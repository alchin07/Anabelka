(function () {
    'use strict';

    if (window.AnabelkaMaskEditor) return;

    const limits = {actions: 64, points: 2048, perStroke: 512, bytes: 1024 * 1024, zoom: 12};
    let active = null;
    let generation = 0;
    const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));
    const clone = value => JSON.parse(JSON.stringify(value));
    const signature = actions => JSON.stringify(actions);
    const refusalLabels = {
        invalid_alpha_area: 'Неправильна площа моделі в масці.',
        source_component_loss: 'У масці бракує частини моделі.',
        source_local_material_loss: 'У масці бракує матеріалу моделі.',
        immutable_material_loss: 'Маска втрачає матеріал моделі.',
        source_thin_material_loss: 'Тонкі деталі моделі потрібно зберегти.',
        source_background_retained: 'На позначеній ділянці залишився фон.',
        source_evidence_unavailable: 'Не вдалося перевірити оригінал. Фото не змінено.',
        unsafe_background_annotation: 'Позначка фону зачіпає модель.',
        unsupported_foreground_annotation: 'Ця позначка моделі виходить за підтверджений матеріал. Зменште її або позначте фон.',
        annotation_unsure: 'Неясна позначка не змінює маску. Уточніть її як фон або модель.'
    };
    const gateLabels = {component: 'Частини моделі', local: 'Матеріал', thin: 'Тонкі деталі', background: 'Фон'};
    const icons = {
        close: '<path d="m6 6 12 12M18 6 6 18"/>',
        previous: '<path d="m15 5-7 7 7 7"/>', next: '<path d="m9 5 7 7-7 7"/>',
        minus: '<path d="M5 12h14"/>', plus: '<path d="M5 12h14M12 5v14"/>',
        fit: '<path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/>',
        undo: '<path d="m8 4-5 5 5 5M4 9h9a7 7 0 0 1 0 14"/>',
        redo: '<path d="m16 4 5 5-5 5M20 9h-9a7 7 0 0 0 0 14"/>',
        reset: '<path d="M4 11a8 8 0 1 1 2 7M4 4v7h7"/>',
        help: '<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4m0 3h.01"/>',
        save: '<path d="m4 16 10-10 4 4-10 10H4zm10-10 2-2 4 4-2 2"/>',
        erase: '<path d="m3 14 9-9 8 8-7 7H9zm6 6-6-6m10 6h8"/>',
        pan: '<path d="M12 3v18M3 12h18m-12-6 3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3m12-6 3 3-3 3"/>'
    };
    function icon(name) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + icons[name] + '</svg>';
    }
    function button(attribute, label, iconName, visibleLabel) {
        return '<button type="button" ' + attribute + ' aria-label="' + label + '" title="' + label + '">' + (iconName ? icon(iconName) : '') + '<span>' + (visibleLabel || label) + '</span></button>';
    }
    function notify(type, message) {
        if (window.AnabelkaNotify && typeof window.AnabelkaNotify.show === 'function') window.AnabelkaNotify.show(type, message);
    }
    function sameOriginImage(value) {
        const url = new URL(String(value || ''), window.location.href);
        if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) throw new Error('Недоступне зображення маски.');
        return url.href;
    }
    function safeConfig(config) {
        const width = Number(config.width), height = Number(config.height);
        if (!Number.isSafeInteger(width) || !Number.isSafeInteger(height) || width < 1 || height < 1 || width * height > 20000000) throw new Error('Неправильний розмір зображення.');
        return Object.assign({}, config, {width, height, sourceUrl: sameOriginImage(config.sourceUrl), alphaUrl: sameOriginImage(config.alphaUrl), regions: Array.isArray(config.regions) ? config.regions.slice(0, 100) : []});
    }

    class Editor {
        constructor(config, callbacks) {
            this.config = safeConfig(config);
            this.callbacks = callbacks || {};
            this.id = ++generation;
            this.actions = clone(Array.isArray(config.actions) ? config.actions : []);
            this.hasEdited = false;
            this.undo = this.actions.map((_, index) => clone(this.actions.slice(0, index)));
            this.redo = [];
            this.reviewedActions = clone(this.actions);
            this.reviewedSignature = signature(this.actions);
            this.actionResults = clone(config.actionResults || config.action_results || []);
            this.retryResult = false;
            this.editingIndex = null;
            this.kind = 'background';
            this.tool = 'point';
            this.radius = 24;
            this.hardness = .65;
            this.zoom = 1;
            this.offset = {x: 0, y: 0};
            this.pointers = new Map();
            this.gesture = null;
            this.provisional = null;
            this.cursor = null;
            this.region = 0;
            this.initialRegionCount = Number.isSafeInteger(config.initialRegionCount) && config.initialRegionCount >= 0 ? config.initialRegionCount : this.config.regions.length;
            this.remainingRegionCount = Number.isSafeInteger(config.remainingRegionCount) && config.remainingRegionCount >= 0 ? config.remainingRegionCount : this.config.regions.length;
            this.busy = false;
            this.ready = false;
            this.auxiliary = null;
            this.loads = new Set();
            this.listeners = [];
            this.inert = [];
            this.frame = 0;
            this.focusBefore = document.activeElement;
            this.mount();
        }
        listen(target, name, callback, options) {
            target.addEventListener(name, callback, options);
            this.listeners.push(() => target.removeEventListener(name, callback, options));
        }
        current() { return active === this && this.id === generation; }
        mount() {
            const node = document.createElement('section');
            node.className = 'mask-editor';
            node.setAttribute('role', 'dialog');
            node.setAttribute('aria-modal', 'true');
            node.setAttribute('aria-labelledby', 'mask-editor-title');
            node.dataset.ready = 'false';
            node.innerHTML = '<header class="mask-editor-header">' + button('data-mask-close', 'Закрити маску', 'close') + '<h2 id="mask-editor-title">Розумна маска</h2><div class="mask-editor-zoom">' + button('data-mask-zoom-out', 'Зменшити', 'minus') + button('data-mask-zoom-in', 'Збільшити', 'plus') + button('data-mask-fit', 'Умістити фото', 'fit') + '</div></header>'
                + '<div class="mask-editor-stage" data-mask-stage data-zoom="1" tabindex="0" aria-label="Фото з піксельною маскою. Два пальці — масштаб і рух."><canvas class="mask-editor-canvas" aria-hidden="true"></canvas><canvas class="mask-editor-magnifier" width="160" height="160" hidden aria-hidden="true"></canvas><div class="mask-editor-loading" role="status">Завантаження маски…</div></div>'
                + '<nav class="mask-editor-regions" aria-label="Перевірка ділянок">' + button('data-mask-previous', 'Попередня ділянка', 'previous') + '<div class="mask-editor-region-summary" aria-live="polite"><span data-mask-count></span><small><span data-mask-initial-count></span> · <span data-mask-remaining-count></span></small></div>' + button('data-mask-next', 'Наступна ділянка', 'next') + button('data-mask-conflicts-open class="mask-editor-conflict-count"', 'Відхилені та неясні позначки', null, '<span data-mask-accepted-count hidden></span><span data-mask-rejected-count></span><small data-mask-unresolved-count></small>') + button('data-mask-help', 'Пензель і підказка', 'help') + '</nav>'
                + '<footer class="mask-editor-footer"><div class="mask-editor-modes" role="group" aria-label="Позначити точку">' + button('data-mask-kind="background"', 'Тут фон', null, 'Фон') + button('data-mask-kind="foreground"', 'Тут модель', null, 'Модель') + button('data-mask-kind="unsure"', 'Тут неясно', null, 'Неясно') + '</div><div class="mask-editor-tools" role="group" aria-label="Інструменти">' + button('data-mask-tool="brush" data-mask-brush="foreground"', 'Пензель зберегти модель', 'save', 'Зберегти') + button('data-mask-tool="brush" data-mask-brush="background"', 'Пензель видалити фон', 'erase', 'Видалити') + button('data-mask-tool="pan"', 'Рух без малювання', 'pan', 'Рух') + button('data-mask-undo', 'Скасувати штрих', 'undo') + button('data-mask-redo', 'Повторити штрих', 'redo') + button('data-mask-reset', 'Скинути всі штрихи', 'reset') + '</div><p class="mask-editor-status" data-mask-status role="status" aria-live="polite">Оберіть «Фон», «Модель» або «Неясно» й торкніться фото.</p><div class="mask-editor-actions">' + button('data-mask-cancel', 'Скасувати редагування', null, 'Скасувати') + button('data-mask-validate class="mask-editor-primary"', 'Перевірити і порівняти', null, 'Перевірити і порівняти') + '</div></footer>';
            this.node = node;
            this.stage = node.querySelector('[data-mask-stage]');
            this.canvas = node.querySelector('.mask-editor-canvas');
            this.context = this.canvas.getContext('2d');
            this.magnifier = node.querySelector('.mask-editor-magnifier');
            [...document.body.children].forEach(child => {
                if (child.tagName === 'SCRIPT' || child.id === 'site-message') return;
                this.inert.push({node: child, inert: child.inert, aria: child.getAttribute('aria-hidden')});
                child.inert = true;
                child.setAttribute('aria-hidden', 'true');
            });
            this.bodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            document.body.append(node);
            this.listen(node, 'click', event => this.click(event));
            this.listen(this.stage, 'pointerdown', event => this.pointerDown(event));
            this.listen(this.stage, 'pointermove', event => this.pointerMove(event));
            this.listen(this.stage, 'pointerup', event => this.pointerUp(event));
            this.listen(this.stage, 'pointercancel', event => this.pointerCancel(event));
            this.listen(this.stage, 'lostpointercapture', event => this.pointerCancel(event));
            this.listen(this.stage, 'wheel', event => {
                event.preventDefault();
                if (!this.ready || this.busy || this.auxiliary) return;
                this.setZoom(this.zoom * (event.deltaY < 0 ? 1.2 : 1 / 1.2), {x: event.clientX, y: event.clientY});
            }, {passive: false});
            this.listen(document, 'keydown', event => this.key(event), true);
            this.observer = new ResizeObserver(() => this.resize());
            this.observer.observe(this.stage);
            this.listen(window, 'pagehide', () => this.destroy({discarded: true, reason: 'navigation'}));
            this.update();
            node.querySelector('[data-mask-close]').focus({preventScroll: true});
        }
        async loadImage(url) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                const load = {img, reject};
                this.loads.add(load);
                img.onload = () => { this.loads.delete(load); img.onload = img.onerror = null; resolve(img); };
                img.onerror = () => { this.loads.delete(load); img.onload = img.onerror = null; reject(new Error('Не вдалося завантажити маску.')); };
                img.src = url;
            });
        }
        async load() {
            const serial = (this.loadSerial || 0) + 1;
            this.loadSerial = serial;
            this.ready = false;
            this.node.dataset.ready = 'false';
            this.node.querySelector('.mask-editor-loading').hidden = false;
            this.update();
            try {
                const alphaRequest = this.loadImage(this.config.alphaUrl);
                const [source, alpha, raw] = await Promise.all([this.source ? Promise.resolve(this.source) : this.loadImage(this.config.sourceUrl), alphaRequest,
                    this.baselineAlpha ? Promise.resolve(null) : this.config.rawAlphaUrl ? this.loadImage(this.config.rawAlphaUrl) : alphaRequest]);
                if (!this.current() || serial !== this.loadSerial) return;
                if (source.naturalWidth !== this.config.width || source.naturalHeight !== this.config.height || alpha.naturalWidth !== this.config.width || alpha.naturalHeight !== this.config.height) throw new Error('Розміри фото та маски не збігаються.');
                this.source = source;
                if (raw && (raw.naturalWidth !== this.config.width || raw.naturalHeight !== this.config.height)) throw new Error('Розміри початкової маски не збігаються.');
                if (this.reviewedAlpha && this.reviewedAlpha !== this.baselineAlpha) this.reviewedAlpha.width = this.reviewedAlpha.height = 0;
                this.reviewedAlpha = this.tint(alpha);
                if (!this.baselineAlpha) this.baselineAlpha = raw === alpha ? this.reviewedAlpha : this.tint(raw);
                this.restoreBaseline();
                this.ready = true;
                this.node.dataset.ready = 'true';
                this.node.querySelector('.mask-editor-loading').hidden = true;
                if (serial === 1 && Array.isArray(this.config.reasons) && this.config.reasons.length) {
                    const reasons = [...new Set(this.config.reasons.map(reason => refusalLabels[reason]).filter(Boolean))].slice(0, 2);
                    this.status((this.actions.length ? 'Маска потребує уточнення. ' : 'Оберіть «Фон», «Модель» або «Неясно» й торкніться фото. ') + (reasons[0] || 'Перевірте позначені ділянки.'));
                }
                this.resize();
                this.update();
            } catch (error) {
                if (!this.current() || serial !== this.loadSerial) return;
                this.status(error.message, 'error');
                this.node.querySelector('.mask-editor-loading').textContent = 'Маска недоступна. Закрийте редактор і повторіть.';
            }
        }
        tint(image) {
            const canvas = document.createElement('canvas');
            canvas.width = this.config.width; canvas.height = this.config.height;
            const ctx = canvas.getContext('2d', {willReadFrequently: true});
            ctx.drawImage(image, 0, 0);
            const data = ctx.getImageData(0, 0, canvas.width, canvas.height);
            for (let index = 0; index < data.data.length; index += 4) {
                const opacity = Math.round((255 - data.data[index]) * .48);
                data.data[index] = 138; data.data[index + 1] = 43; data.data[index + 2] = 226; data.data[index + 3] = opacity;
            }
            ctx.putImageData(data, 0, 0);
            return canvas;
        }
        resize() {
            if (!this.current()) return;
            const rect = this.stage.getBoundingClientRect();
            const ratio = Math.min(window.devicePixelRatio || 1, 2);
            if (this.viewWidth === rect.width && this.viewHeight === rect.height && this.ratio === ratio) {this.render(); return;}
            const previous = this.viewWidth ? this.transform() : null;
            const center = previous ? {x: (this.viewWidth / 2 - previous.x) / previous.scale, y: (this.viewHeight / 2 - previous.y) / previous.scale} : null;
            if (this.pointers.size) this.cancelGesture();
            this.viewWidth = Math.max(1, rect.width);
            this.viewHeight = Math.max(1, rect.height);
            this.fit = Math.min(this.viewWidth / this.config.width, this.viewHeight / this.config.height);
            this.canvas.width = Math.round(this.viewWidth * ratio);
            this.canvas.height = Math.round(this.viewHeight * ratio);
            this.ratio = ratio;
            if (center) {
                const scale = this.fit * this.zoom;
                this.offset.x = (this.config.width / 2 - center.x) * scale;
                this.offset.y = (this.config.height / 2 - center.y) * scale;
            }
            this.boundOffset();
            this.render();
        }
        transform() {
            const scale = this.fit * this.zoom;
            return {scale, x: (this.viewWidth - this.config.width * scale) / 2 + this.offset.x, y: (this.viewHeight - this.config.height * scale) / 2 + this.offset.y};
        }
        point(clientX, clientY, unrestricted) {
            const rect = this.stage.getBoundingClientRect(), transform = this.transform();
            const x = (clientX - rect.left - transform.x) / transform.scale;
            const y = (clientY - rect.top - transform.y) / transform.scale;
            if (!unrestricted && (x < 0 || y < 0 || x >= this.config.width || y >= this.config.height)) return null;
            return [Math.round(clamp(x, 0, this.config.width - 1)), Math.round(clamp(y, 0, this.config.height - 1))];
        }
        boundOffset() {
            const scale = this.fit * this.zoom;
            const maxX = Math.max(0, (this.config.width * scale - this.viewWidth) / 2);
            const maxY = Math.max(0, (this.config.height * scale - this.viewHeight) / 2);
            this.offset.x = clamp(this.offset.x, -maxX, maxX);
            this.offset.y = clamp(this.offset.y, -maxY, maxY);
        }
        setZoom(value, anchor) {
            if (!this.ready) return;
            this.cancelGesture();
            const rect = this.stage.getBoundingClientRect();
            const client = anchor || {x: rect.left + rect.width / 2, y: rect.top + rect.height / 2};
            const transform = this.transform();
            const sourceX = (client.x - rect.left - transform.x) / transform.scale;
            const sourceY = (client.y - rect.top - transform.y) / transform.scale;
            this.zoom = clamp(value, 1, limits.zoom);
            const scale = this.fit * this.zoom;
            this.offset.x = client.x - rect.left - (this.viewWidth - this.config.width * scale) / 2 - sourceX * scale;
            this.offset.y = client.y - rect.top - (this.viewHeight - this.config.height * scale) / 2 - sourceY * scale;
            this.boundOffset();
            this.render();
        }
        paintAction(ctx, action, outcome, scale) {
            const points = action.points;
            const rejected = outcome && outcome.status === 'rejected';
            const color = rejected ? '196,49,68' : action.kind === 'foreground' ? '54,152,101' : action.kind === 'unsure' ? '238,165,29' : '138,43,226';
            const radius = action.radius;
            const hardness = action.hardness == null ? 1 : action.hardness;
            const dot = (x, y) => {
                ctx.beginPath();
                if (hardness < 1) {
                    const gradient = ctx.createRadialGradient(x, y, radius * hardness, x, y, radius);
                    gradient.addColorStop(0, 'rgba(' + color + ',.55)');
                    gradient.addColorStop(1, 'rgba(' + color + ',0)');
                    ctx.fillStyle = gradient;
                } else ctx.fillStyle = 'rgba(' + color + ',.55)';
                ctx.arc(x, y, radius, 0, Math.PI * 2);
                ctx.fill();
                if (rejected) {
                    ctx.save(); ctx.setLineDash([5 / scale, 3 / scale]); ctx.lineWidth = 1.5 / scale;
                    ctx.strokeStyle = '#c43144'; ctx.stroke(); ctx.restore();
                }
            };
            for (let i = 0; i < points.length; i++) {
                const point = points[i];
                if (i) {
                    const previous = points[i - 1], distance = Math.hypot(point[0] - previous[0], point[1] - previous[1]);
                    const steps = Math.min(500, Math.ceil(distance / Math.max(1, radius / 2)));
                    for (let step = 1; step < steps; step++) dot(previous[0] + (point[0] - previous[0]) * step / steps, previous[1] + (point[1] - previous[1]) * step / steps);
                }
                dot(point[0], point[1]);
            }
        }
        drawScene(ctx, transform, showCursor) {
            if (!this.ready || !this.source) return;
            ctx.save();
            ctx.translate(transform.x, transform.y);
            ctx.scale(transform.scale, transform.scale);
            ctx.drawImage(this.source, 0, 0);
            ctx.imageSmoothingEnabled = false;
            ctx.drawImage(this.alpha, 0, 0);
            ctx.imageSmoothingEnabled = true;
            this.config.regions.forEach((region, index) => {
                const polygon = region.polygon;
                if (!Array.isArray(polygon) || polygon.length < 3) return;
                ctx.beginPath();
                polygon.forEach((point, i) => {
                    if (!Array.isArray(point) || !Number.isFinite(point[0]) || !Number.isFinite(point[1])) return;
                    if (!i) ctx.moveTo(point[0], point[1]); else ctx.lineTo(point[0], point[1]);
                });
                ctx.closePath();
                ctx.lineWidth = (index === this.region ? 2.5 : 1.4) / transform.scale;
                ctx.strokeStyle = index === this.region ? '#eda51d' : '#f4eaff';
                ctx.fillStyle = 'rgba(238,165,29,.09)';
                ctx.fill(); ctx.stroke();
            });
            this.actions.forEach((action, index) => {
                const outcome = this.outcome(index);
                if (this.alpha !== this.reviewedAlpha || signature(this.actions) !== this.reviewedSignature || !outcome || outcome.status !== 'accepted') this.paintAction(ctx, action, outcome, transform.scale);
            });
            if (this.provisional) this.paintAction(ctx, this.provisional);
            if (showCursor && this.cursor && this.tool !== 'pan') {
                ctx.beginPath();
                ctx.arc(this.cursor[0], this.cursor[1], this.tool === 'point' ? 10 : this.radius, 0, Math.PI * 2);
                ctx.lineWidth = 2 / transform.scale;
                ctx.strokeStyle = '#fff'; ctx.stroke();
                ctx.lineWidth = 1 / transform.scale;
                ctx.strokeStyle = '#20202a'; ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(this.cursor[0] - 6 / transform.scale, this.cursor[1]); ctx.lineTo(this.cursor[0] + 6 / transform.scale, this.cursor[1]);
                ctx.moveTo(this.cursor[0], this.cursor[1] - 6 / transform.scale); ctx.lineTo(this.cursor[0], this.cursor[1] + 6 / transform.scale); ctx.stroke();
            }
            ctx.restore();
        }
        render() {
            if (!this.current() || this.frame) return;
            this.frame = window.requestAnimationFrame(() => {
                this.frame = 0;
                if (!this.current() || !this.context || !this.viewWidth) return;
                const ctx = this.context;
                ctx.setTransform(this.ratio, 0, 0, this.ratio, 0, 0);
                ctx.clearRect(0, 0, this.viewWidth, this.viewHeight);
                this.drawScene(ctx, this.transform(), true);
                this.stage.dataset.zoom = String(Math.round(this.zoom * 100) / 100);
                if (this.cursor && this.provisional && this.pointers.size === 1) this.drawMagnifier();
                else this.magnifier.hidden = true;
            });
        }
        drawMagnifier() {
            const ctx = this.magnifier.getContext('2d');
            const scale = Math.max(this.fit * this.zoom * 2, .7);
            ctx.clearRect(0, 0, 160, 160);
            this.drawScene(ctx, {scale, x: 80 - this.cursor[0] * scale, y: 80 - this.cursor[1] * scale}, true);
            const last = [...this.pointers.values()][0];
            const rect = this.stage.getBoundingClientRect();
            this.magnifier.style.left = clamp(last.x - rect.left + (last.x - rect.left < this.viewWidth / 2 ? 30 : -130), 6, Math.max(6, this.viewWidth - 106)) + 'px';
            this.magnifier.style.top = clamp(last.y - rect.top - 120, 6, Math.max(6, this.viewHeight - 106)) + 'px';
            this.magnifier.hidden = false;
        }
        pointerDown(event) {
            if (!this.ready || this.busy || this.auxiliary || (event.pointerType === 'mouse' && event.button !== 0)) return;
            const rect = this.stage.getBoundingClientRect();
            if (rect.width !== this.viewWidth || rect.height !== this.viewHeight) this.resize();
            event.preventDefault();
            this.pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
            try { this.stage.setPointerCapture(event.pointerId); } catch (_) {}
            if (this.pointers.size > 1) {
                this.provisional = null;
                const points = [...this.pointers.values()].slice(0, 2);
                const center = {x: (points[0].x + points[1].x) / 2, y: (points[0].y + points[1].y) / 2};
                const rect = this.stage.getBoundingClientRect(), transform = this.transform();
                const source = {x: (center.x - rect.left - transform.x) / transform.scale, y: (center.y - rect.top - transform.y) / transform.scale};
                this.gesture = {type: 'pinch', distance: Math.max(1, Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y)), zoom: this.zoom, center, source, blocked: true};
                this.render(); return;
            }
            this.gesture = {type: this.tool === 'pan' ? 'pan' : 'draw', start: {x: event.clientX, y: event.clientY}, offset: Object.assign({}, this.offset), moved: false};
            const point = this.point(event.clientX, event.clientY);
            this.cursor = point;
            if (point && this.tool !== 'pan') this.provisional = {kind: this.kind, tool: this.tool, radius: this.tool === 'point' ? 10 : this.radius, hardness: this.tool === 'point' ? 1 : this.hardness, points: [point]};
            this.render();
        }
        pointerMove(event) {
            if (!this.pointers.has(event.pointerId)) {
                if (event.pointerType === 'mouse' && this.ready && !this.auxiliary) {this.cursor = this.point(event.clientX, event.clientY); this.render();}
                return;
            }
            event.preventDefault();
            this.pointers.set(event.pointerId, {x: event.clientX, y: event.clientY});
            const gesture = this.gesture;
            if (!gesture) return;
            if (gesture.type === 'pinch') {
                if (this.pointers.size < 2) return;
                const points = [...this.pointers.values()].slice(0, 2);
                const center = {x: (points[0].x + points[1].x) / 2, y: (points[0].y + points[1].y) / 2};
                const distance = Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y);
                this.zoom = clamp(gesture.zoom * distance / gesture.distance, 1, limits.zoom);
                const rect = this.stage.getBoundingClientRect(), scale = this.fit * this.zoom;
                this.offset.x = center.x - rect.left - (this.viewWidth - this.config.width * scale) / 2 - gesture.source.x * scale;
                this.offset.y = center.y - rect.top - (this.viewHeight - this.config.height * scale) / 2 - gesture.source.y * scale;
                this.boundOffset(); this.render(); return;
            }
            const moved = Math.hypot(event.clientX - gesture.start.x, event.clientY - gesture.start.y);
            if (moved > 6) gesture.moved = true;
            if (gesture.type === 'pan' || (this.tool === 'point' && gesture.moved)) {
                this.provisional = null;
                this.offset.x = gesture.offset.x + event.clientX - gesture.start.x;
                this.offset.y = gesture.offset.y + event.clientY - gesture.start.y;
                this.boundOffset(); this.render(); return;
            }
            this.cursor = this.point(event.clientX, event.clientY, true);
            if (this.provisional && this.tool === 'brush' && this.provisional.points.length < limits.perStroke) {
                const previous = this.provisional.points[this.provisional.points.length - 1];
                if (Math.hypot(this.cursor[0] - previous[0], this.cursor[1] - previous[1]) >= Math.max(1, this.radius / 4)) this.provisional.points.push(this.cursor);
            }
            this.render();
        }
        pointerUp(event) {
            if (!this.pointers.has(event.pointerId)) return;
            event.preventDefault();
            const gesture = this.gesture;
            this.pointers.delete(event.pointerId);
            if (gesture && gesture.type !== 'pinch' && this.provisional && (this.tool !== 'point' || !gesture.moved)) this.add(this.provisional);
            this.provisional = null;
            if (!this.pointers.size) {this.gesture = null; this.cursor = null;}
            this.render();
        }
        pointerCancel(event) {
            if (!this.pointers.has(event.pointerId)) return;
            this.pointers.delete(event.pointerId);
            this.provisional = null;
            if (!this.pointers.size) { this.gesture = null; this.cursor = null; }
            this.render();
        }
        cancelGesture() {
            const ids = [...this.pointers.keys()]; this.pointers.clear();
            this.provisional = this.gesture = this.cursor = null;
            ids.forEach(id => {try {this.stage.releasePointerCapture(id);} catch (_) {}});
            this.render();
        }
        add(action) {
            const actions = clone(this.actions);
            if (this.editingIndex !== null) actions[this.editingIndex] = clone(action);
            else actions.push(clone(action));
            if (actions.length > limits.actions || actions.reduce((sum, item) => sum + item.points.length, 0) > limits.points || new Blob([JSON.stringify(actions)]).size > limits.bytes) {
                this.status('Забагато позначок. Скасуйте зайві штрихи або почніть знову.', 'warning'); return;
            }
            this.changeActions(actions);
        }
        changeActions(actions) {
            this.undo.push(clone(this.actions));
            if (this.undo.length > limits.actions) this.undo.shift();
            this.actions = clone(actions); this.redo = []; this.editingIndex = null;
            this.hasEdited = true; this.restoreBaseline(); this.describeState(); this.update(); this.render();
        }
        outcome(index) {
            const key = signature(this.actions[index]);
            const occurrence = this.actions.slice(0, index).filter(action => signature(action) === key).length;
            const matches = this.reviewedActions.map((action, reviewedIndex) => signature(action) === key ? reviewedIndex : -1).filter(index => index >= 0);
            const reviewedIndex = matches[occurrence];
            return this.actionResults.find(result => result.index === reviewedIndex) || null;
        }
        canValidate() {
            return !this.busy && this.ready && typeof this.callbacks.onValidate === 'function'
                && (signature(this.actions) !== this.reviewedSignature || this.retryResult);
        }
        updateConflicts() {
            const container = this.node.querySelector('[data-mask-conflicts]');
            if (!container) return;
            container.replaceChildren();
            const pending = signature(this.actions) !== this.reviewedSignature;
            this.actions.forEach((action, index) => {
                const outcome = this.outcome(index);
                const conflict = outcome && ['rejected', 'unresolved'].includes(outcome.status);
                const row = document.createElement('div'); row.dataset.maskActionResult = String(index);
                row.dataset.maskActionStatus = outcome ? outcome.status : 'pending';
                if (conflict) row.dataset.maskConflict = String(index);
                const text = document.createElement('p');
                const reasons = outcome ? (outcome.reasons || []).map(reason => refusalLabels[reason]).filter(Boolean) : [];
                const label = !outcome ? 'ще не перевірено' : outcome.status === 'accepted' ? (pending ? 'раніше прийнята' : 'прийнята') : outcome.status === 'unresolved' ? 'неясна' : 'відхилена';
                text.textContent = 'Позначка ' + (index + 1) + ' — ' + label + '. '
                    + (conflict ? reasons.join(' ') || 'Позначка не підтверджена. Зменште її або змініть тип.' : '')
                    + (pending && outcome ? ' Це результат попередньої перевірки; змінені позначки ще не перевірено.' : '');
                row.append(text);
                const controls = document.createElement('div');
                controls.innerHTML = button('data-mask-focus-action="' + index + '"', 'Показати позначку ' + (index + 1), null, 'Показати')
                    + (conflict ? button('data-mask-replace-action="' + index + '"', 'Виправити позначку ' + (index + 1), null, 'Виправити')
                    + button('data-mask-delete-action="' + index + '"', 'Видалити позначку ' + (index + 1), null, 'Видалити') : '');
                if (!conflict) controls.className = 'mask-editor-action-readonly';
                controls.querySelectorAll('button').forEach(button => {button.disabled = this.busy || !this.ready;});
                row.append(controls); container.append(row);
            });
            if (!container.children.length) {
                const text = document.createElement('p'); text.textContent = 'Відхилених і неясних позначок немає. Зміни можна перевірити.'; container.append(text);
            }
        }
        focusRegion(direction) {
            const regions = this.config.regions;
            if (!regions.length) return;
            this.region = clamp(this.region + direction, 0, regions.length - 1);
            this.focusBox(regions[this.region].bbox);
            const reason = refusalLabels[regions[this.region].reason];
            this.status('Ділянка ' + (this.region + 1) + ' із ' + regions.length + '. ' + (reason || 'Збільште фото й позначайте тільки потрібні пікселі.')
                + (this.region === regions.length - 1 ? ' Це остання поточна ділянка.' : ''));
            this.update();
        }
        focusBox(bbox) {
            this.cancelGesture();
            if (Array.isArray(bbox) && bbox.length === 4 && bbox.every(Number.isFinite) && bbox[2] > 0 && bbox[3] > 0) {
                this.zoom = clamp(Math.min(this.viewWidth * .65 / bbox[2], this.viewHeight * .65 / bbox[3]) / this.fit, 1, limits.zoom);
                const scale = this.fit * this.zoom;
                this.offset.x = (this.config.width / 2 - bbox[0] - bbox[2] / 2) * scale;
                this.offset.y = (this.config.height / 2 - bbox[1] - bbox[3] / 2) * scale;
                this.boundOffset(); this.render();
            }
        }
        focusAction(index) {
            const action = this.actions[index]; if (!action) return;
            const xs = action.points.map(point => point[0]), ys = action.points.map(point => point[1]);
            const left = Math.max(0, Math.min(...xs) - action.radius), top = Math.max(0, Math.min(...ys) - action.radius);
            const right = Math.min(this.config.width, Math.max(...xs) + action.radius), bottom = Math.min(this.config.height, Math.max(...ys) + action.radius);
            this.focusBox([left, top, Math.max(1, right - left), Math.max(1, bottom - top)]);
            this.stage.focus({preventScroll: true});
        }
        click(event) {
            const control = event.target.closest('button');
            if (!control || !this.node.contains(control)) return;
            if (control.disabled) return;
            this.cancelGesture();
            if (control.hasAttribute('data-mask-close') || control.hasAttribute('data-mask-cancel')) {this.requestClose(); return;}
            if (control.hasAttribute('data-mask-keep')) {this.closeAuxiliary(); return;}
            if (control.hasAttribute('data-mask-discard')) {this.destroy({discarded: true, reason: 'cancel'}); return;}
            if (control.hasAttribute('data-mask-helper-close') || control.hasAttribute('data-mask-conflicts-close')) {this.closeAuxiliary(); return;}
            if (control.hasAttribute('data-mask-confirm-reset')) {this.changeActions([]); this.closeAuxiliary(); this.status('Позначки скинуто. Перевірте початкову маску перед порівнянням.'); return;}
            if (this.busy) return;
            if (control.hasAttribute('data-mask-delete-action')) {
                const index = Number(control.dataset.maskDeleteAction); if (Number.isSafeInteger(index) && this.actions[index]) this.changeActions(this.actions.filter((_, position) => position !== index));
                if (this.auxiliary) {
                    const next = this.auxiliary.querySelector('[data-mask-conflict="' + index + '"] button') || this.auxiliary.querySelector('[data-mask-conflict] button') || this.auxiliary.querySelector('[data-mask-conflicts-close]');
                    if (next) next.focus({preventScroll: true});
                }
                return;
            }
            if (control.hasAttribute('data-mask-focus-action')) {
                const index = Number(control.dataset.maskFocusAction), outcome = this.outcome(index), pending = signature(this.actions) !== this.reviewedSignature;
                this.closeAuxiliary(); this.focusAction(index);
                this.status('Позначка ' + (index + 1) + ': ' + (!outcome ? 'ще не перевірено.' : outcome.status === 'accepted'
                    ? pending ? 'було прийнято під час попередньої перевірки; поточні зміни ще не перевірено.' : 'прийнято. Продовжіть перевірку інших ділянок маски.'
                    : outcome.status === 'unresolved' ? 'неясна точка лише позначає місце й не змінює маску.' : 'відхилено. Зменште позначку або змініть її тип.'));
                return;
            }
            if (control.hasAttribute('data-mask-replace-action')) {
                const index = Number(control.dataset.maskReplaceAction), action = this.actions[index];
                if (!action) return;
                this.closeAuxiliary(); this.focusAction(index);
                this.editingIndex = index; this.kind = action.kind; this.tool = action.tool; this.radius = action.radius; this.hardness = action.hardness;
                this.toolHint(); this.update(); return;
            }
            if (this.auxiliary) return;
            if (control.dataset.maskKind) {this.kind = control.dataset.maskKind; this.tool = 'point'; this.cursor = null; this.toolHint(); this.update(); this.render();}
            else if (control.dataset.maskTool) {this.tool = control.dataset.maskTool; if (control.dataset.maskBrush) this.kind = control.dataset.maskBrush; this.cursor = null; this.toolHint(); this.update(); this.render();}
            else if (control.hasAttribute('data-mask-undo')) {if (this.undo.length) {this.redo.push(clone(this.actions)); this.actions = this.undo.pop(); this.hasEdited = true;} this.editingIndex = null; this.restoreBaseline(); this.describeState(); this.update(); this.render();}
            else if (control.hasAttribute('data-mask-redo')) {if (this.redo.length) {this.undo.push(clone(this.actions)); this.actions = this.redo.pop(); this.hasEdited = true;} this.editingIndex = null; this.restoreBaseline(); this.describeState(); this.update(); this.render();}
            else if (control.hasAttribute('data-mask-reset')) {if (this.actions.length || this.redo.length) this.showAuxiliary('reset');}
            else if (control.hasAttribute('data-mask-previous')) this.focusRegion(-1);
            else if (control.hasAttribute('data-mask-next')) this.focusRegion(1);
            else if (control.hasAttribute('data-mask-zoom-in')) this.setZoom(this.zoom * 1.35);
            else if (control.hasAttribute('data-mask-zoom-out')) this.setZoom(this.zoom / 1.35);
            else if (control.hasAttribute('data-mask-fit')) {this.zoom = 1; this.offset = {x: 0, y: 0}; this.render();}
            else if (control.hasAttribute('data-mask-help')) this.showAuxiliary('help');
            else if (control.hasAttribute('data-mask-conflicts-open')) this.showAuxiliary('conflicts');
            else if (control.hasAttribute('data-mask-validate')) this.validate();
        }
        update() {
            this.node.querySelectorAll('[data-mask-kind]').forEach(button => button.setAttribute('aria-pressed', String(this.tool === 'point' && button.dataset.maskKind === this.kind)));
            this.node.querySelectorAll('[data-mask-tool]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.maskTool === this.tool && (!button.dataset.maskBrush || button.dataset.maskBrush === this.kind))));
            this.node.querySelector('[data-mask-count]').textContent = this.config.regions.length
                ? (this.remainingRegionCount > this.config.regions.length ? 'Показано: ' : '') + (this.region + 1) + ' із ' + this.config.regions.length + (this.remainingRegionCount > this.config.regions.length ? '' : ' ділянок')
                : 'Ділянок немає';
            this.node.querySelector('[data-mask-initial-count]').textContent = 'Спочатку: ' + this.initialRegionCount;
            this.node.querySelector('[data-mask-remaining-count]').textContent = 'Залишилось: ' + this.remainingRegionCount;
            const outcomes = this.actions.map((_, index) => this.outcome(index));
            const accepted = outcomes.filter(result => result && result.status === 'accepted').length, rejected = outcomes.filter(result => result && result.status === 'rejected').length, unresolved = outcomes.filter(result => result && result.status === 'unresolved').length;
            const acceptedCount = this.node.querySelector('[data-mask-accepted-count]');
            acceptedCount.textContent = (signature(this.actions) === this.reviewedSignature ? 'Прийнято: ' : 'Було прийнято: ') + accepted;
            acceptedCount.hidden = !this.actionResults.length;
            this.node.querySelector('[data-mask-rejected-count]').textContent = 'Відхилено: ' + rejected;
            const unsure = this.node.querySelector('[data-mask-unresolved-count]'); unsure.textContent = 'Неясно: ' + unresolved; unsure.hidden = !unresolved;
            const conflicts = this.node.querySelector('[data-mask-conflicts-open]'); conflicts.disabled = this.busy || !this.ready || !this.actionResults.length;
            conflicts.setAttribute('aria-label', acceptedCount.textContent + '. Відхилено позначок: ' + rejected + '. Неясних: ' + unresolved + '. Відкрити результати позначок.');
            this.node.querySelector('[data-mask-undo]').disabled = this.busy || !this.ready || !this.undo.length;
            this.node.querySelector('[data-mask-redo]').disabled = this.busy || !this.ready || !this.redo.length;
            this.node.querySelector('[data-mask-reset]').disabled = this.busy || !this.ready || (!this.actions.length && !this.redo.length);
            this.node.querySelector('[data-mask-validate]').disabled = !this.canValidate();
            this.node.querySelector('[data-mask-previous]').disabled = this.busy || !this.ready || this.region <= 0;
            this.node.querySelector('[data-mask-next]').disabled = this.busy || !this.ready || this.region >= this.config.regions.length - 1;
            this.node.setAttribute('aria-busy', String(this.busy));
            this.node.dataset.tool = this.tool;
            this.updateConflicts();
        }
        status(message, type) {
            this.node.querySelector('[data-mask-status]').textContent = message;
            if (type) notify(type, message);
        }
        toolHint() {
            const hints = {background: 'Фон: торкніться чистого фону на фото. Біля волосся або тканини зменште радіус.', foreground: 'Модель: торкніться матеріалу на фото біля збереженого краю. Білі прогалини не домальовуються.', unsure: 'Неясно: торкніться фото, щоб позначити сумнівне місце. Точка не змінює маску; уточніть її як фон або модель.'};
            this.status((this.editingIndex === null ? '' : 'Замініть позначку ' + (this.editingIndex + 1) + ' новим штрихом. ') + (this.tool === 'pan' ? 'Рух не малює. Два пальці змінюють масштаб і положення фото.' : hints[this.kind]));
        }
        describeState() {
            this.status(signature(this.actions) !== this.reviewedSignature ? 'Локальні зміни ще не перевірено. Натисніть «Перевірити і порівняти» після уточнення позначок.'
                : this.retryResult ? 'Маску підтверджено. Повторіть підготовку порівняння.'
                : 'Ці позначки вже перевірено. Виправте відхилені або неясні позначки чи уточніть поточні ділянки.');
        }
        showAuxiliary(kind) {
            if (this.auxiliary) return;
            this.cancelGesture();
            this.auxiliaryFocus = document.activeElement;
            const overlay = document.createElement('div');
            overlay.className = 'mask-editor-aux';
            overlay.setAttribute('role', kind === 'help' || kind === 'conflicts' ? 'dialog' : 'alertdialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-labelledby', 'mask-editor-aux-title');
            if (kind === 'help') {
                overlay.innerHTML = '<div class="mask-editor-aux-card"><h3 id="mask-editor-aux-title">Пензель і рух</h3><p>Точка змінює лише локальні пікселі. Два пальці — масштаб і рух; інструмент «Рух» не малює. Жовті контури показують місця для перевірки, не обов’язково фон: там може бути матеріал або тонкі деталі моделі.</p><p>Прийняті позначки збережено; незалежні ділянки маски можуть усе ще блокувати порівняння. Якщо локальне уточнення не проходить захист матеріалу, скасуйте попередній перегляд і створіть нову пробу. Не кожну проблему оригіналу можна безпечно виправити позначками; незмінені позначки повторно не перевіряються.</p><label>Радіус пензля <output data-mask-radius-value>' + this.radius + ' px</output><input data-mask-radius type="range" min="1" max="80" step="1" value="' + this.radius + '"></label><label>Твердість <output data-mask-hardness-value>' + Math.round(this.hardness * 100) + '%</output><input data-mask-hardness type="range" min="25" max="100" step="5" value="' + Math.round(this.hardness * 100) + '"></label>' + button('data-mask-helper-close class="mask-editor-primary"', 'Продовжити редагування', null, 'Готово') + '</div>';
            } else if (kind === 'conflicts') {
                overlay.innerHTML = '<div class="mask-editor-aux-card mask-editor-conflict-card"><h3 id="mask-editor-aux-title">Результати позначок</h3><p>Прийняті позначки збережено, але інші ділянки маски ще можуть потребувати перевірки. «Показати» збільшує місце; «Виправити» замінює лише обрану відхилену або неясну позначку.</p><div class="mask-editor-conflicts" data-mask-conflicts></div>' + button('data-mask-conflicts-close class="mask-editor-primary"', 'Повернутися до маски', null, 'Повернутися до маски') + '</div>';
            } else {
                overlay.innerHTML = '<div class="mask-editor-aux-card"><h3 id="mask-editor-aux-title">' + (kind === 'reset' ? 'Скинути позначки?' : 'Скасувати редагування?') + '</h3><p>' + (kind === 'reset' ? 'Усі ваші локальні позначки буде видалено. Початкова маска залишиться.' : 'Ваші локальні позначки буде втрачено. Поточне фото залишиться без змін.') + '</p><div class="mask-editor-aux-actions">' + button('data-mask-keep', 'Продовжити редагування', null, 'Продовжити') + button(kind === 'reset' ? 'data-mask-confirm-reset class="mask-editor-primary"' : 'data-mask-discard class="mask-editor-primary"', kind === 'reset' ? 'Скинути позначки' : 'Скасувати зміни', null, kind === 'reset' ? 'Скинути' : 'Скасувати зміни') + '</div></div>';
            }
            if (kind === 'help') {
                const gates = document.createElement('ul');
                gates.className = 'mask-editor-gates';
                gates.setAttribute('aria-label', 'Перевірка захисту маски');
                Object.entries(gateLabels).forEach(([name, label]) => {
                    const item = document.createElement('li');
                    item.dataset.maskGate = name;
                    const gate = this.config.gates && this.config.gates[name];
                    item.textContent = label + ': ' + (!gate || typeof gate.accepted !== 'boolean' ? 'перевіряється' : gate.accepted ? 'збережено' : 'потребує уточнення');
                    gates.append(item);
                });
                overlay.querySelector('p').after(gates);
                const reasons = Array.isArray(this.config.reasons) ? [...new Set(this.config.reasons.map(reason => refusalLabels[reason]).filter(Boolean))] : [];
                if (reasons.length) {
                    const explanation = document.createElement('p'); explanation.textContent = 'Причини перевірки: ' + reasons.join(' '); gates.after(explanation);
                }
            }
            [...this.node.children].forEach(child => {child.inert = true;});
            this.node.append(overlay); this.auxiliary = overlay;
            if (kind === 'conflicts') this.updateConflicts();
            this.listen(overlay, 'input', event => {
                if (event.target.hasAttribute('data-mask-radius')) {this.radius = clamp(Number(event.target.value), 1, 80); overlay.querySelector('[data-mask-radius-value]').textContent = this.radius + ' px';}
                if (event.target.hasAttribute('data-mask-hardness')) {this.hardness = clamp(Number(event.target.value) / 100, .25, 1); overlay.querySelector('[data-mask-hardness-value]').textContent = Math.round(this.hardness * 100) + '%';}
            });
            if (typeof this.callbacks.onAuxiliary === 'function') this.callbacks.onAuxiliary(true);
            overlay.querySelector('button').focus({preventScroll: true});
        }
        closeAuxiliary() {
            if (!this.auxiliary) return false;
            this.auxiliary.remove(); this.auxiliary = null;
            [...this.node.children].forEach(child => {child.inert = false;});
            if (typeof this.callbacks.onAuxiliary === 'function') this.callbacks.onAuxiliary(false);
            if (this.auxiliaryFocus && this.auxiliaryFocus.isConnected) this.auxiliaryFocus.focus({preventScroll: true});
            return true;
        }
        requestClose() {
            if (this.auxiliary) {this.closeAuxiliary(); return false;}
            if (this.actions.length || signature(this.actions) !== this.reviewedSignature || this.busy) {this.showAuxiliary('discard'); return false;}
            this.destroy({discarded: true, reason: 'cancel'}); return true;
        }
        restoreBaseline() {
            if (!this.baselineAlpha) return;
            this.alpha = signature(this.actions) === this.reviewedSignature && this.reviewedAlpha ? this.reviewedAlpha : this.baselineAlpha;
        }
        key(event) {
            if (!this.current()) return;
            if (event.key === 'Escape') {event.preventDefault(); event.stopImmediatePropagation(); this.requestClose(); return;}
            if (event.key === 'Tab') {
                const root = this.auxiliary || this.node;
                const nodes = [...root.querySelectorAll('button:not(:disabled),input:not(:disabled),[tabindex="0"]')].filter(node => node.getClientRects().length && !node.closest('[inert]'));
                const first = nodes[0], last = nodes[nodes.length - 1];
                if (event.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) {event.preventDefault(); last?.focus();}
                else if (!event.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) {event.preventDefault(); first?.focus();}
                event.stopImmediatePropagation();
            }
        }
        async validate() {
            if (!this.canValidate()) return;
            const controller = new AbortController();
            this.controller = controller; this.busy = true; this.provisional = null; this.update();
            this.status('Перевірка пікселів і безпеки маски…');
            const payload = {imageId: this.config.imageId, previewId: this.config.previewId, revision: this.config.revision, width: this.config.width, height: this.config.height, actions: clone(this.actions), retryResult: this.retryResult && signature(this.actions) === this.reviewedSignature};
            try {
                const result = await this.callbacks.onValidate(payload, {signal: controller.signal});
                if (!this.current() || controller.signal.aborted) return;
                if (!result || result.success !== true) throw new Error(result && result.message || 'Маску не підтверджено. Уточніть ділянки й повторіть.');
                if (result.state === 'ready' && typeof result.preview_id === 'string' && result.preview_id && typeof result.preview_url === 'string' && result.preview_url && result.processing && result.processing.status === 'ready') {
                    sameOriginImage(result.preview_url);
                    if (typeof this.callbacks.onCompare !== 'function') throw new Error('Порівняння недоступне. Фото не змінено.');
                    const compare = this.callbacks.onCompare;
                    const context = {config: clone(this.config), actions: clone(this.actions)};
                    this.destroy({discarded: false, reason: 'compare'});
                    compare(result, context);
                    return;
                }
                if (result.state !== 'needs_review') throw new Error('Маску не підтверджено. Фото не змінено.');
                const review = result.review || {};
                this.reviewedActions = clone(Array.isArray(review.actions) ? review.actions : payload.actions);
                this.reviewedSignature = signature(this.reviewedActions);
                this.actionResults = clone(Array.isArray(review.action_results) ? review.action_results : []);
                this.retryResult = review.retry_result === true && review.status === 'accepted';
                if (Number.isSafeInteger(Number(review.revision))) this.config.revision = Number(review.revision);
                if (Array.isArray(review.regions)) {this.config.regions = review.regions.slice(0, 100); this.region = 0;}
                this.remainingRegionCount = Number.isSafeInteger(review.remaining_region_count) && review.remaining_region_count >= 0 ? review.remaining_region_count : this.config.regions.length;
                if (review.gates && typeof review.gates === 'object') this.config.gates = review.gates;
                if (Array.isArray(review.reasons)) this.config.reasons = review.reasons;
                const reasons = Array.isArray(review.reasons) ? [...new Set(review.reasons.map(reason => refusalLabels[reason]).filter(Boolean))].slice(0, 2) : [];
                this.status(review.resynced ? 'Версію маски оновлено. Ваші локальні позначки збережено; перевірте зміни перед повторною перевіркою.'
                    : this.retryResult ? 'Маску підтверджено. Повторіть підготовку порівняння.'
                    : 'Маска потребує уточнення. ' + (reasons.length ? reasons.join(' ') : 'Виправте відхилені позначки або додайте уточнення.'), 'warning');
                const alpha = review.alphaUrl || review.alpha_url;
                const source = review.sourceUrl || review.source_url;
                if (source) this.config.sourceUrl = sameOriginImage(source);
                const raw = review.rawAlphaUrl || review.raw_alpha_url;
                if (raw) this.config.rawAlphaUrl = sameOriginImage(raw);
                if (alpha) {this.config.alphaUrl = sameOriginImage(alpha); this.load();}
                this.render();
            } catch (error) {
                if (this.current() && !controller.signal.aborted) this.status(error.message || 'Перевірка не вдалася. Спробуйте ще раз.', 'error');
            } finally {
                if (this.current() && this.controller === controller) {this.controller = null; this.busy = false; this.update();}
            }
        }
        destroy(options) {
            if (!this.current()) return;
            const callbacks = this.callbacks;
            this.closeAuxiliary();
            active = null; ++generation;
            if (this.controller) this.controller.abort();
            this.loads.forEach(load => {load.img.onload = load.img.onerror = null; load.img.src = ''; load.reject(new DOMException('Editor closed', 'AbortError'));});
            this.loads.clear();
            this.listeners.forEach(remove => remove()); this.listeners = [];
            this.observer.disconnect();
            if (this.frame) window.cancelAnimationFrame(this.frame);
            this.pointers.clear(); this.provisional = this.gesture = this.cursor = null;
            this.node.remove();
            this.canvas.width = this.canvas.height = this.magnifier.width = this.magnifier.height = 0;
            if (this.reviewedAlpha) this.reviewedAlpha.width = this.reviewedAlpha.height = 0;
            if (this.baselineAlpha) this.baselineAlpha.width = this.baselineAlpha.height = 0;
            this.source = this.alpha = this.baselineAlpha = this.reviewedAlpha = null;
            this.inert.forEach(item => {if (!item.node.isConnected) return; item.node.inert = item.inert; if (item.aria === null) item.node.removeAttribute('aria-hidden'); else item.node.setAttribute('aria-hidden', item.aria);});
            document.body.style.overflow = this.bodyOverflow;
            if (this.focusBefore && this.focusBefore.isConnected) this.focusBefore.focus({preventScroll: true});
            if (typeof callbacks.onClose === 'function') callbacks.onClose(Object.assign({discarded: true, reason: 'close'}, options || {}));
        }
    }

    window.AnabelkaMaskEditor = {
        open: function (config, callbacks) {
            if (active) active.destroy({discarded: true, reason: 'replace'});
            try {const editor = new Editor(config || {}, callbacks); active = editor; editor.resize(); editor.load(); return true;}
            catch (error) {notify('error', error.message || 'Редактор маски недоступний.'); return false;}
        },
        close: function (options) {if (active) active.destroy(options);},
        requestClose: function (options) {return active ? active.requestClose(options) : true;},
        closeAuxiliary: function () {return active ? active.closeAuxiliary() : false;},
        handleBack: function () {return active ? (active.auxiliary ? active.closeAuxiliary() : active.requestClose()) : false;},
        hasAuxiliary: function () {return Boolean(active && active.auxiliary);},
        isOpen: function () {return Boolean(active);},
        getActions: function () {return active ? clone(active.actions) : [];},
        isDirty: function () {return Boolean(active && (active.actions.length || signature(active.actions) !== active.reviewedSignature));}
    };
}());
