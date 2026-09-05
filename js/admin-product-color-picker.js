(function () {
    'use strict';

    const root = document.getElementById('product-color-picker');

    if (!root) {
        return;
    }

    const picker = {
        window: root.querySelector('.product-color-picker-window'),
        options: root.querySelector('[data-color-picker-options]'),
        presets: root.querySelector('[data-color-picker-presets]'),
        preview: root.querySelector('[data-color-picker-preview]'),
        name: root.querySelector('[data-color-picker-name]'),
        photoButton: root.querySelector('[data-color-picker-photo]'),
        systemButton: root.querySelector('[data-color-picker-system]'),
        systemInput: root.querySelector('[data-color-system-input]'),
        photoPanel: root.querySelector('[data-color-photo-panel]'),
        photoImage: root.querySelector('[data-color-photo-image]'),
        photoMarker: root.querySelector('[data-color-photo-marker]'),
        photoBack: root.querySelector('[data-color-photo-back]'),
        clear: root.querySelector('[data-color-picker-clear]'),
        apply: root.querySelector('[data-color-picker-apply]'),
        close: root.querySelector('.product-color-picker-close')
    };

    if (
        !picker.window
        || !picker.options
        || !picker.presets
        || !picker.preview
        || !picker.name
        || !picker.photoButton
        || !picker.systemButton
        || !picker.systemInput
        || !picker.photoPanel
        || !picker.photoImage
        || !picker.photoMarker
        || !picker.photoBack
        || !picker.clear
        || !picker.apply
    ) {
        return;
    }

    // Visible quick-selection palette. These names are exact and are never guessed.
    const colorPresets = [
        { name: 'Білий', hex: '#ffffff' },
        { name: 'Молочний', hex: '#f5efe3' },
        { name: 'Бежевий', hex: '#d9c2a3' },
        { name: 'Тілесний', hex: '#d5a485' },
        { name: 'Рожевий', hex: '#f2a6b8' },
        { name: 'Червоний', hex: '#d83d4d' },
        { name: 'Бордовий', hex: '#7f233a' },
        { name: 'Коричневий', hex: '#765044' },
        { name: 'Сірий', hex: '#8c8c91' },
        { name: 'Чорний', hex: '#171717' },
        { name: 'Блакитний', hex: '#79b9e1' },
        { name: 'Синій', hex: '#376bc2' },
        { name: 'Зелений', hex: '#438c5d' },
        { name: 'Фіолетовий', hex: '#7d4ab1' },
        { name: 'Жовтий', hex: '#e3c340' },
        { name: 'Помаранчевий', hex: '#e58a36' }
    ];

    // Wider palette used only for automatic recognition from a photo/system picker.
    // It stays separate so the quick-selection grid remains simple on a phone.
    const colorRecognitionPalette = [
        { name: 'Білий', hex: '#ffffff' },
        { name: 'Молочний', hex: '#f5efe3' },
        { name: 'Кремовий', hex: '#eee0c6' },
        { name: 'Бежевий', hex: '#d9c2a3' },
        { name: 'Тілесний', hex: '#d5a485' },
        { name: 'Пудровий', hex: '#d6a6a3' },
        { name: 'Рожевий', hex: '#f2a6b8' },
        { name: 'Темно-рожевий', hex: '#b96f83' },
        { name: 'Червоний', hex: '#d83d4d' },
        { name: 'Бордовий', hex: '#7f233a' },
        { name: 'Тауп', hex: '#9a7f7b' },
        { name: 'Кавовий', hex: '#6f5148' },
        { name: 'Коричневий', hex: '#765044' },
        { name: 'Світло-сірий', hex: '#b8b8bc' },
        { name: 'Сірий', hex: '#8c8c91' },
        { name: 'Темно-сірий', hex: '#626368' },
        { name: 'Графітовий', hex: '#45474d' },
        { name: 'Чорний', hex: '#171717' },
        { name: 'Блакитний', hex: '#79b9e1' },
        { name: 'Синій', hex: '#376bc2' },
        { name: 'Темно-синій', hex: '#29456f' },
        { name: 'Бірюзовий', hex: '#4aa6a4' },
        { name: 'Зелений', hex: '#438c5d' },
        { name: 'Хакі', hex: '#77794f' },
        { name: 'Ліловий', hex: '#a684b5' },
        { name: 'Фіолетовий', hex: '#7d4ab1' },
        { name: 'Жовтий', hex: '#e3c340' },
        { name: 'Помаранчевий', hex: '#e58a36' }
    ];

    let activeGroup = null;
    let lastFocusedElement = null;
    let pickerHasColor = false;
    let photoReturnTimer = null;


    function normalizedHex(value)
    {
        const hex = String(value || '').trim().toLowerCase();

        return /^#[0-9a-f]{6}$/.test(hex)
            ? hex
            : '#b8b0bd';
    }


    function hexToRgb(hex)
    {
        const normalized = normalizedHex(hex).slice(1);

        return {
            red: parseInt(normalized.slice(0, 2), 16),
            green: parseInt(normalized.slice(2, 4), 16),
            blue: parseInt(normalized.slice(4, 6), 16)
        };
    }


    function rgbToHex(red, green, blue)
    {
        return '#'
            + [red, green, blue].map(function (channel) {
                return Math.max(0, Math.min(255, Math.round(channel)))
                    .toString(16)
                    .padStart(2, '0');
            }).join('');
    }


    function rgbToLab(rgb)
    {
        function linearize(channel)
        {
            const value = channel / 255;
            return value <= 0.04045
                ? value / 12.92
                : Math.pow((value + 0.055) / 1.055, 2.4);
        }

        const red = linearize(rgb.red);
        const green = linearize(rgb.green);
        const blue = linearize(rgb.blue);

        const x = (red * 0.4124564 + green * 0.3575761 + blue * 0.1804375) / 0.95047;
        const y = (red * 0.2126729 + green * 0.7151522 + blue * 0.0721750);
        const z = (red * 0.0193339 + green * 0.1191920 + blue * 0.9503041) / 1.08883;

        function labTransform(value)
        {
            return value > 0.008856
                ? Math.cbrt(value)
                : (7.787 * value) + (16 / 116);
        }

        const fx = labTransform(x);
        const fy = labTransform(y);
        const fz = labTransform(z);

        return {
            lightness: (116 * fy) - 16,
            a: 500 * (fx - fy),
            b: 200 * (fy - fz)
        };
    }


    function labDistance(first, second)
    {
        const lightness = first.lightness - second.lightness;
        const a = first.a - second.a;
        const b = first.b - second.b;

        return Math.sqrt(
            (lightness * lightness)
            + (a * a)
            + (b * b)
        );
    }


    function closestColorName(hex)
    {
        const selectedLab = rgbToLab(hexToRgb(hex));
        const neutralChroma = Math.sqrt(
            (selectedLab.a * selectedLab.a)
            + (selectedLab.b * selectedLab.b)
        );

        // Only genuinely neutral shades are forced into the gray family.
        // Warm gray, taupe and powder shades are left for perceptual LAB matching.
        if (neutralChroma <= 5.5) {
            if (selectedLab.lightness >= 96) {
                return 'Білий';
            }

            if (selectedLab.lightness <= 18) {
                return 'Чорний';
            }

            if (selectedLab.lightness <= 42) {
                return 'Графітовий';
            }

            if (selectedLab.lightness <= 63) {
                return 'Темно-сірий';
            }

            if (selectedLab.lightness >= 80) {
                return 'Світло-сірий';
            }

            return 'Сірий';
        }

        if (selectedLab.lightness <= 15 && neutralChroma <= 12) {
            return 'Чорний';
        }

        let closest = colorRecognitionPalette[0];
        let closestDistance = Number.POSITIVE_INFINITY;

        colorRecognitionPalette.forEach(function (preset) {
            const distance = labDistance(
                selectedLab,
                rgbToLab(hexToRgb(preset.hex))
            );

            if (distance < closestDistance) {
                closestDistance = distance;
                closest = preset;
            }
        });

        return closest.name;
    }


    function showMessage(text)
    {
        const message = document.getElementById('site-message');

        if (!message) {
            return;
        }

        message.textContent = text;
        message.classList.add('show');
        clearTimeout(window.adminProductColorMessageTimer);
        window.adminProductColorMessageTimer = window.setTimeout(function () {
            message.classList.remove('show');
        }, 3000);
    }


    function updatePresetSelection(hex)
    {
        const selectedHex = normalizedHex(hex);

        picker.presets.querySelectorAll('[data-color-preset]').forEach(function (button) {
            const selected = button.dataset.colorPreset === selectedHex;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
    }


    function updatePickerPreview()
    {
        const hex = normalizedHex(picker.systemInput.value);
        picker.preview.style.setProperty('--selected-image-color', hex);
        picker.preview.classList.toggle('has-color', pickerHasColor);
        updatePresetSelection(pickerHasColor ? hex : '');
    }


    function setPickerColor(hex, name)
    {
        const normalized = normalizedHex(hex);
        picker.systemInput.value = normalized;
        picker.name.value = String(name || closestColorName(normalized));
        pickerHasColor = true;
        updatePickerPreview();
    }


    function refreshImageColorGroup(group)
    {
        const nameInput = group.querySelector('[data-image-color-name]');
        const hexInput = group.querySelector('[data-image-color-hex]');
        const button = group.querySelector('[data-image-color-open]');
        const dot = group.querySelector('[data-image-color-dot]');
        const label = group.querySelector('[data-image-color-label]');

        if (!nameInput || !hexInput || !button || !dot || !label) {
            return;
        }

        const name = nameInput.value.trim();
        const hasColor = name !== '';
        group.classList.toggle('has-color', hasColor);

        if (hasColor) {
            dot.style.setProperty('--image-color', normalizedHex(hexInput.value));
            label.textContent = name;
            button.setAttribute('aria-label', 'Змінити колір: ' + name);
            return;
        }

        dot.style.removeProperty('--image-color');
        label.textContent = 'Вибрати колір';
        button.setAttribute('aria-label', 'Вибрати колір фотографії');
    }


    function savePickerToGroup(name, hex)
    {
        if (!activeGroup) {
            return;
        }

        const nameInput = activeGroup.querySelector('[data-image-color-name]');
        const hexInput = activeGroup.querySelector('[data-image-color-hex]');

        if (!nameInput || !hexInput) {
            return;
        }

        nameInput.value = String(name || '').trim();
        hexInput.value = normalizedHex(hex);
        refreshImageColorGroup(activeGroup);
    }


    function showColorOptions()
    {
        root.classList.remove('is-photo-mode');
        picker.options.hidden = false;
        picker.photoPanel.hidden = true;
        picker.photoMarker.hidden = true;
    }


    function closePicker(restoreFocus)
    {
        clearTimeout(photoReturnTimer);
        photoReturnTimer = null;
        showColorOptions();
        root.hidden = true;
        document.body.classList.remove('product-color-picker-open');
        activeGroup = null;
        picker.photoImage.removeAttribute('src');

        if (
            restoreFocus !== false
            && lastFocusedElement
            && document.documentElement.contains(lastFocusedElement)
        ) {
            lastFocusedElement.focus();
        }

        lastFocusedElement = null;
    }


    function openPicker(group, trigger)
    {
        const nameInput = group.querySelector('[data-image-color-name]');
        const hexInput = group.querySelector('[data-image-color-hex]');

        if (!nameInput || !hexInput) {
            return;
        }

        activeGroup = group;
        lastFocusedElement = trigger || document.activeElement;
        pickerHasColor = nameInput.value.trim() !== '';
        picker.name.value = nameInput.value;
        picker.systemInput.value = normalizedHex(hexInput.value);
        showColorOptions();
        updatePickerPreview();
        root.hidden = false;
        document.body.classList.add('product-color-picker-open');

        window.setTimeout(function () {
            if (picker.close) {
                picker.close.focus();
            }
        }, 20);
    }


    function selectedSourceImage()
    {
        if (!activeGroup) {
            return null;
        }

        const card = activeGroup.closest(
            '.product-image-manage, .product-upload-preview-item'
        );

        return card ? card.querySelector('img') : null;
    }


    function openPhotoPicker()
    {
        const sourceImage = selectedSourceImage();
        const source = sourceImage
            ? String(sourceImage.currentSrc || sourceImage.src || '')
            : '';

        if (source === '') {
            showMessage('Спочатку додайте фотографію товару.');
            return;
        }

        picker.photoMarker.hidden = true;
        picker.photoImage.src = source;
        picker.options.hidden = true;
        picker.photoPanel.hidden = false;
        root.classList.add('is-photo-mode');
    }


    function median(values)
    {
        values.sort(function (first, second) {
            return first - second;
        });

        return values[Math.floor(values.length / 2)] || 0;
    }


    function samplePhotoColor(event)
    {
        const image = picker.photoImage;

        if (!image.complete || image.naturalWidth <= 0 || image.naturalHeight <= 0) {
            showMessage('Фотографія ще завантажується. Спробуйте ще раз.');
            return;
        }

        const rect = image.getBoundingClientRect();

        if (rect.width <= 0 || rect.height <= 0) {
            return;
        }

        const scaleX = image.naturalWidth / rect.width;
        const scaleY = image.naturalHeight / rect.height;
        const centerX = (event.clientX - rect.left) * scaleX;
        const centerY = (event.clientY - rect.top) * scaleY;
        const sampleWidth = Math.min(
            image.naturalWidth,
            Math.max(4, Math.round(18 * scaleX))
        );
        const sampleHeight = Math.min(
            image.naturalHeight,
            Math.max(4, Math.round(18 * scaleY))
        );
        const sourceX = Math.max(
            0,
            Math.min(image.naturalWidth - sampleWidth, centerX - sampleWidth / 2)
        );
        const sourceY = Math.max(
            0,
            Math.min(image.naturalHeight - sampleHeight, centerY - sampleHeight / 2)
        );
        const canvas = document.createElement('canvas');
        const sampleResolution = 18;
        canvas.width = sampleResolution;
        canvas.height = sampleResolution;
        const context = canvas.getContext('2d', { willReadFrequently: true });

        if (!context) {
            showMessage('Не вдалося визначити колір на цьому телефоні.');
            return;
        }

        let pixels;

        try {
            context.drawImage(
                image,
                sourceX,
                sourceY,
                sampleWidth,
                sampleHeight,
                0,
                0,
                sampleResolution,
                sampleResolution
            );
            pixels = context.getImageData(
                0,
                0,
                sampleResolution,
                sampleResolution
            ).data;
        } catch (error) {
            showMessage('З цього фото не вдалося взяти колір. Оберіть готовий.');
            showColorOptions();
            return;
        }

        const red = [];
        const green = [];
        const blue = [];

        for (let index = 0; index < pixels.length; index += 4) {
            if (pixels[index + 3] < 100) {
                continue;
            }

            red.push(pixels[index]);
            green.push(pixels[index + 1]);
            blue.push(pixels[index + 2]);
        }

        if (red.length === 0) {
            showMessage('Оберіть непрозору ділянку фотографії.');
            return;
        }

        const hex = rgbToHex(median(red), median(green), median(blue));
        setPickerColor(hex, closestColorName(hex));

        const stageRect = image.parentElement.getBoundingClientRect();
        picker.photoMarker.style.left = (event.clientX - stageRect.left) + 'px';
        picker.photoMarker.style.top = (event.clientY - stageRect.top) + 'px';
        picker.photoMarker.style.setProperty('--sampled-color', hex);
        picker.photoMarker.hidden = false;

        clearTimeout(photoReturnTimer);
        photoReturnTimer = window.setTimeout(function () {
            showColorOptions();
            showMessage('Колір узято з фотографії.');
        }, 420);
    }


    function renderPresets()
    {
        const fragment = document.createDocumentFragment();

        colorPresets.forEach(function (preset) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-color-preset';
            button.dataset.colorPreset = preset.hex;
            button.style.setProperty('--preset-color', preset.hex);
            button.setAttribute('aria-label', preset.name);
            button.setAttribute('aria-pressed', 'false');
            button.title = preset.name;
            button.addEventListener('click', function () {
                setPickerColor(preset.hex, preset.name);
            });
            fragment.appendChild(button);
        });

        picker.presets.appendChild(fragment);
    }


    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-image-color-open]');

        if (!trigger) {
            return;
        }

        const group = trigger.closest('.product-image-color-fields');

        if (group) {
            openPicker(group, trigger);
        }
    });

    root.querySelectorAll('[data-color-picker-cancel]').forEach(function (button) {
        button.addEventListener('click', function () {
            closePicker(true);
        });
    });

    picker.apply.addEventListener('click', function () {
        let name = picker.name.value.trim();

        if (name !== '') {
            pickerHasColor = true;
        }

        if (!pickerHasColor) {
            savePickerToGroup('', '#b8b0bd');
            closePicker(true);
            return;
        }

        if (name === '') {
            name = closestColorName(picker.systemInput.value);
        }

        savePickerToGroup(name, picker.systemInput.value);
        closePicker(true);
    });

    picker.clear.addEventListener('click', function () {
        savePickerToGroup('', '#b8b0bd');
        closePicker(true);
    });

    picker.photoButton.addEventListener('click', openPhotoPicker);
    picker.photoBack.addEventListener('click', showColorOptions);
    picker.photoImage.addEventListener('click', samplePhotoColor);
    picker.photoImage.addEventListener('error', function () {
        if (root.hidden) {
            return;
        }

        showMessage('Не вдалося відкрити фотографію для вибору кольору.');
        showColorOptions();
    });

    picker.systemButton.addEventListener('click', function () {
        picker.systemInput.click();
    });

    function acceptSystemColor()
    {
        setPickerColor(
            picker.systemInput.value,
            closestColorName(picker.systemInput.value)
        );
    }

    picker.systemInput.addEventListener('input', acceptSystemColor);
    picker.systemInput.addEventListener('change', acceptSystemColor);

    picker.name.addEventListener('input', function () {
        if (picker.name.value.trim() !== '') {
            pickerHasColor = true;
            updatePickerPreview();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || root.hidden) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        closePicker(true);
    });

    renderPresets();
})();