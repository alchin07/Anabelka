"""Bounded, deterministic alpha review. User labels never grant material deletion."""
from __future__ import annotations

import math
from typing import Any

import cv2
import numpy as np
from PIL import Image


MAX_ACTIONS = 64
MAX_POINTS = 2048
MAX_ACTION_POINTS = 512
MAX_ANNOTATED_RATIO = .02
MAX_SOURCE_EDGE = 8192
MAX_SOURCE_PIXELS = 20_000_000


def _resize(mask: np.ndarray, size: tuple[int, int]) -> np.ndarray:
    return np.asarray(Image.fromarray(mask).resize(size, Image.Resampling.NEAREST))


def regions_from_mask(mask: np.ndarray, reason: str, *, maximum: int = 32) -> list[dict[str, Any]]:
    """Describe connected actual loss pixels, never a coarse validation cell."""
    count, labels, stats, _ = cv2.connectedComponentsWithStats(mask.astype(np.uint8), connectivity=8)
    result = []
    order = sorted(range(1, count), key=lambda i: int(stats[i, cv2.CC_STAT_AREA]), reverse=True)
    for index in order[:maximum]:
        x, y, width, height, area = [int(value) for value in stats[index]]
        local = (labels[y:y + height, x:x + width] == index).astype(np.uint8)
        contours, _ = cv2.findContours(local, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        polygon = []
        if contours:
            contour = max(contours, key=cv2.contourArea)
            contour = cv2.approxPolyDP(contour, .5, True).reshape(-1, 2)
            # Polygon complexity is bounded; bbox/pixels still describe the exact component.
            step = max(1, math.ceil(len(contour) / 128))
            polygon = [[int(px) + x, int(py) + y] for px, py in contour[::step]]
        result.append({'reason': reason, 'bbox': [x, y, width, height],
                       'polygon': polygon, 'pixels': area})
    return result


def validate_alpha(alpha: np.ndarray, raw: np.ndarray, seed: np.ndarray | None) -> dict[str, Any]:
    """Evaluate each required source gate independently, preserving its thresholds."""
    gates = {name: {'accepted': True, 'reasons': []} for name in ('component', 'local', 'thin', 'background')}
    report: dict[str, Any] = {'status': 'accepted', 'reasons': [], 'gates': gates, 'regions': []}

    def fail(gate: str, reason: str, loss: np.ndarray | None = None) -> None:
        gates[gate]['accepted'] = False
        if reason not in gates[gate]['reasons']:
            gates[gate]['reasons'].append(reason)
        if reason not in report['reasons']:
            report['reasons'].append(reason)
        report['status'] = 'needs_review'
        if loss is not None and np.any(loss):
            report['regions'].extend(regions_from_mask(loss, reason))

    if (alpha.dtype != np.uint8 or raw.dtype != np.uint8 or alpha.ndim != 2
            or alpha.shape != raw.shape or max(alpha.shape) > MAX_SOURCE_EDGE
            or alpha.size > MAX_SOURCE_PIXELS):
        raise ValueError('Некоректний розмір маски.')
    ratio = float(np.mean(alpha >= 128))
    report['actual_foreground_ratio'] = round(ratio, 6)
    if not .02 <= ratio <= .90:
        fail('component', 'invalid_alpha_area')
    if seed is None:
        for gate in gates:
            fail(gate, 'source_evidence_unavailable')
        return report
    if seed.ndim != 2 or seed.dtype != np.uint8:
        raise ValueError('Некоректні дані перевірки джерела.')
    size = (seed.shape[1], seed.shape[0])
    opacity = np.asarray(Image.fromarray(alpha).resize(size, Image.Resampling.BILINEAR))
    native_size = (alpha.shape[1], alpha.shape[0])

    def native_loss(mask: np.ndarray, threshold: int) -> np.ndarray:
        return _resize(mask.astype(np.uint8), native_size).astype(bool) & (alpha < threshold)

    source_foreground = seed == cv2.GC_PR_FGD
    core = cv2.erode(source_foreground.astype(np.uint8), np.ones((3, 3), np.uint8))
    count, labels, stats, _ = cv2.connectedComponentsWithStats(core, connectivity=8)
    minimum = max(16, round(seed.size * .001))
    retentions = []
    for index in range(1, count):
        if stats[index, cv2.CC_STAT_AREA] < minimum:
            continue
        component = labels == index
        retention = float(np.mean(opacity[component] >= 128))
        retentions.append(retention)
        if retention < .90:
            fail('component', 'source_component_loss', native_loss(component, 128))
    gates['component']['retention'] = retentions
    if not retentions:
        fail('component', 'source_evidence_unavailable')
    lost = ((core > 0) & (opacity < 128)).astype(np.uint8)
    count, labels, stats, _ = cv2.connectedComponentsWithStats(lost, connectivity=8)
    for index in range(1, count):
        if stats[index, cv2.CC_STAT_AREA] >= minimum:
            fail('local', 'source_local_material_loss', native_loss(labels == index, 128))
    # Independent immutable-alpha guard prevents hand/skin/hair/lace labels from erasing material.
    destructive = (raw >= 64) & (alpha < raw)
    if np.any(destructive):
        fail('local', 'immutable_material_loss', destructive)
    strands = (source_foreground & (cv2.dilate(core, np.ones((3, 3), np.uint8)) == 0)).astype(np.uint8)
    count, labels, stats, _ = cv2.connectedComponentsWithStats(strands, connectivity=8)
    for index in range(1, count):
        if (stats[index, cv2.CC_STAT_AREA] >= minimum
                and float(np.mean(opacity[labels == index] >= 64)) < .90):
            fail('thin', 'source_thin_material_loss', native_loss(labels == index, 64))
    background = cv2.erode((~source_foreground).astype(np.uint8), np.ones((3, 3), np.uint8)).astype(bool)
    samples = seed == cv2.GC_BGD
    retentions = [float(np.mean(opacity[domain] <= 32)) for domain in (background, samples) if np.any(domain)]
    gates['background']['retention'] = retentions
    if not retentions:
        fail('background', 'source_evidence_unavailable')
    elif min(retentions) < .90:
        retained = _resize((background | samples).astype(np.uint8), native_size).astype(bool) & (alpha > 32)
        fail('background', 'source_background_retained', retained)
    return report


def _integer(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def normalize_actions(actions: Any, shape: tuple[int, int]) -> list[dict[str, Any]]:
    """Validate the journal and give equivalent default-valued requests one form."""
    if not isinstance(actions, list) or len(actions) > MAX_ACTIONS:
        raise ValueError('Забагато дій редагування маски.')
    height, width = shape
    if max(shape) > MAX_SOURCE_EDGE or height * width > MAX_SOURCE_PIXELS:
        raise ValueError('Фотографія завелика для редактора маски.')
    total_points = 0
    normalized = []
    for action in actions:
        if not isinstance(action, dict) or set(action) - {'kind', 'tool', 'radius', 'hardness', 'points'}:
            raise ValueError('Некоректна дія редактора маски.')
        kind, tool = action.get('kind'), action.get('tool')
        if kind not in ('foreground', 'background', 'unsure') or tool not in ('brush', 'point'):
            raise ValueError('Некоректний інструмент маски.')
        if kind == 'unsure' and tool != 'point':
            raise ValueError('Невпевнене позначення підтримує тільки точку.')
        radius = action.get('radius')
        if not _integer(radius) or not 1 <= radius <= (12 if tool == 'point' else 80):
            raise ValueError('Некоректний радіус пензля.')
        hardness = action.get('hardness', 1)
        if isinstance(hardness, bool) or not isinstance(hardness, (int, float)) or not math.isfinite(hardness) or not .25 <= hardness <= 1:
            raise ValueError('Некоректна м’якість пензля.')
        points = action.get('points')
        if not isinstance(points, list) or not 1 <= len(points) <= MAX_ACTION_POINTS or (tool == 'point' and len(points) != 1):
            raise ValueError('Некоректні точки пензля.')
        total_points += len(points)
        if total_points > MAX_POINTS:
            raise ValueError('Забагато точок пензля.')
        for point in points:
            if (not isinstance(point, list) or len(point) != 2 or not all(_integer(value) for value in point)
                    or not 0 <= point[0] < width or not 0 <= point[1] < height):
                raise ValueError('Точка знаходиться поза вихідною фотографією.')
        normalized.append({'kind': kind, 'tool': tool, 'radius': radius,
                           'hardness': float(hardness), 'points': [point.copy() for point in points]})
    return normalized


def action_weights(actions: Any, shape: tuple[int, int]):
    height, width = shape
    annotated = np.zeros(shape, dtype=bool)
    for action in normalize_actions(actions, shape):
        kind, radius, hardness, points = (action[name] for name in ('kind', 'radius', 'hardness', 'points'))
        # Distance from an exact native-coordinate path; feather stays inside the circle.
        path = np.ones(shape, dtype=np.uint8)
        for point in points:
            path[point[1], point[0]] = 0
        for first, second in zip(points, points[1:]):
            cv2.line(path, tuple(first), tuple(second), 0, 1)
        distance = cv2.distanceTransform(path, cv2.DIST_L2, cv2.DIST_MASK_PRECISE)
        weights = np.clip((radius - distance) / max(.001, radius * (1 - hardness)), 0, 1)
        if hardness == 1:
            weights = (distance < radius).astype(np.float32)
        annotated |= weights > 0
        if np.count_nonzero(annotated) > max(16, height * width * MAX_ANNOTATED_RATIO):
            raise ValueError('Позначайте невелику локальну ділянку, не всю область фотографії.')
        yield kind, weights


def revise_alpha(raw: np.ndarray, seed: np.ndarray | None, actions: Any,
                 *, source_rgb: np.ndarray | None = None) -> dict[str, Any]:
    actions = normalize_actions(actions, raw.shape)
    alpha = raw.copy()
    native_seed = None if seed is None else _resize(seed, (raw.shape[1], raw.shape[0]))
    # A coarse colour seed alone cannot license repainting a broad supplier-background gap.
    # New foreground is limited to a narrow neighbourhood of immutable material.
    near_material = cv2.distanceTransform((raw < 128).astype(np.uint8),
                                         cv2.DIST_L2, cv2.DIST_MASK_PRECISE) <= 12
    palette = []
    if source_rgb is not None:
        if source_rgb.shape != (*raw.shape, 3) or source_rgb.dtype != np.uint8:
            raise ValueError('Некоректний незмінний RGB оригінал.')
        if seed is not None:
            sampled = np.asarray(Image.fromarray(source_rgb).resize(
                (seed.shape[1], seed.shape[0]), Image.Resampling.BILINEAR))
            samples = seed == cv2.GC_BGD
            for rows, columns in ((slice(0, seed.shape[0] // 2), slice(None)),
                                  (slice(seed.shape[0] // 2, None), slice(None)),
                                  (slice(None), slice(0, seed.shape[1] // 2)),
                                  (slice(None), slice(seed.shape[1] // 2, None))):
                pixels = sampled[rows, columns][samples[rows, columns]]
                if len(pixels) >= 8:
                    palette.append(np.median(pixels, axis=0))
    refusals = []
    action_results = []
    for index, (kind, weights) in enumerate(action_weights(actions, raw.shape)):
        if kind == 'unsure':
            refusals.append('annotation_unsure')
            action_results.append({'index': index, 'status': 'unresolved', 'reasons': ['annotation_unsure']})
            continue
        changed = weights > 0
        if kind == 'background':
            unsafe = changed & ((raw >= 64) | (native_seed != cv2.GC_BGD if native_seed is not None else True))
            reason = 'unsafe_background_annotation'
        else:
            backdrop_like = np.zeros(raw.shape, dtype=bool)
            if source_rgb is not None and palette:
                selected = changed & (raw < 255)
                pixels = source_rgb[selected].astype(np.float32)
                similar = np.zeros(len(pixels), dtype=bool)
                for color in palette:
                    chroma_difference = np.stack((pixels[:, 0] - pixels[:, 1] - color[0] + color[1],
                                                  pixels[:, 1] - pixels[:, 2] - color[1] + color[2]), axis=1)
                    similar |= ((np.max(np.abs(pixels - color), axis=1) <= 24)
                                & (np.linalg.norm(chroma_difference, axis=1) <= 6))
                backdrop_like[selected] = similar
            unsafe = changed & (raw < 255) & (
                (native_seed != cv2.GC_PR_FGD if native_seed is not None else True) | ~near_material
                | (raw < 64) | backdrop_like | (source_rgb is None or not palette))
            reason = 'unsupported_foreground_annotation'
        if np.any(unsafe):
            refusals.append(reason)
            # A whole stroke is rejected. One focus box keeps its accounting
            # bounded even when overlapping strokes hit many separate details.
            conflict = {'reason': reason, 'bbox': list(cv2.boundingRect(unsafe.astype(np.uint8))),
                        'pixels': int(np.count_nonzero(unsafe))}
            action_results.append({'index': index, 'status': 'rejected', 'reasons': [reason],
                                   'conflicts': [conflict]})
            continue
        action_results.append({'index': index, 'status': 'accepted', 'reasons': []})
        target = 255 if kind == 'foreground' else 0
        alpha = np.rint(alpha.astype(np.float32) * (1 - weights) + target * weights).astype(np.uint8)
    validation = validate_alpha(alpha, raw, seed)
    for reason in dict.fromkeys(refusals):
        if reason not in validation['reasons']:
            validation['reasons'].append(reason)
    if refusals:
        validation['status'] = 'needs_review'
    return {'alpha': alpha, 'validation': validation, 'actions': actions, 'action_results': action_results}
