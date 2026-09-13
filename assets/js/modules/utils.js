const numberFormatterCache = new Map();

export function debounce(callback, wait = 300) {
    let timer = null;
    const debounced = (...args) => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => callback(...args), wait);
    };
    debounced.cancel = () => window.clearTimeout(timer);
    return debounced;
}

export function clamp(value, minimum, maximum) {
    return Math.min(Math.max(value, minimum), maximum);
}

export function finiteNumber(value, fallback = 0) {
    const number = typeof value === 'number' ? value : Number.parseFloat(String(value));
    return Number.isFinite(number) ? number : fallback;
}

export function positiveInteger(value, fallback = 1) {
    const number = Number.parseInt(String(value), 10);
    return Number.isInteger(number) && number > 0 ? number : fallback;
}

export function roundMoney(value, precision = 4) {
    const factor = 10 ** precision;
    return Math.round((finiteNumber(value) + Number.EPSILON) * factor) / factor;
}

export function formatMoney(value, currency = document.querySelector('meta[name="app-currency"]')?.content || 'ETB') {
    const locale = document.documentElement.lang || 'en';
    const key = `${locale}:${currency}`;
    if (!numberFormatterCache.has(key)) {
        numberFormatterCache.set(key, new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }));
    }
    return numberFormatterCache.get(key).format(finiteNumber(value));
}

export function deepValue(source, path, fallback = undefined) {
    if (!path) {
        return source;
    }
    const value = path.split('.').reduce(
        (current, part) => (current !== null && current !== undefined ? current[part] : undefined),
        source,
    );
    return value === undefined ? fallback : value;
}

export function parseJson(value, fallback = null) {
    if (typeof value !== 'string' || value.trim() === '') {
        return fallback;
    }
    try {
        return JSON.parse(value);
    } catch {
        return fallback;
    }
}

export function setBusy(control, busy, label = 'Working…') {
    if (!(control instanceof HTMLElement)) {
        return;
    }
    if (busy) {
        if (!control.dataset.originalHtml) {
            control.dataset.originalHtml = control.innerHTML;
        }
        control.setAttribute('aria-busy', 'true');
        if ('disabled' in control) {
            control.disabled = true;
        }
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm';
        spinner.setAttribute('aria-hidden', 'true');
        const copy = document.createElement('span');
        copy.textContent = label;
        control.replaceChildren(spinner, copy);
        return;
    }
    control.removeAttribute('aria-busy');
    if ('disabled' in control) {
        control.disabled = false;
    }
    if (control.dataset.originalHtml) {
        control.innerHTML = control.dataset.originalHtml;
        delete control.dataset.originalHtml;
    }
}

export function emit(target, name, detail = {}) {
    target.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
}

export function serializeForm(form) {
    const data = {};
    for (const [key, value] of new FormData(form).entries()) {
        if (Object.hasOwn(data, key)) {
            data[key] = Array.isArray(data[key]) ? [...data[key], value] : [data[key], value];
        } else {
            data[key] = value;
        }
    }
    return data;
}

export function nextFrame() {
    return new Promise((resolve) => window.requestAnimationFrame(resolve));
}
