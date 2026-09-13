import { deepValue } from './modules/utils.js';

export class ApiError extends Error {
    constructor(message, { status = 0, errors = {}, meta = {}, response = null, cause = null } = {}) {
        super(message, cause ? { cause } : undefined);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
        this.meta = meta;
        this.response = response;
    }
}

export class ApiClient {
    constructor({ baseUrl, csrfToken, timeout = 20_000 } = {}) {
        this.baseUrl = (baseUrl || document.querySelector('meta[name="api-base-url"]')?.content || '/api/v1').replace(/\/$/, '');
        this.csrfToken = csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
        this.timeout = timeout;
    }

    buildUrl(path, query = {}) {
        const requestedPath = String(path || '');
        const url = /^[a-z][a-z\d+.-]*:/i.test(requestedPath)
            ? new URL(requestedPath)
            : requestedPath.startsWith('/')
                ? new URL(requestedPath, window.location.origin)
                : new URL(`${this.baseUrl}/${requestedPath.replace(/^\/+/, '')}`, window.location.origin);
        if (url.origin !== window.location.origin) {
            throw new ApiError('Cross-origin API requests are not allowed.');
        }
        Object.entries(query).forEach(([key, value]) => {
            if (value === undefined || value === null || value === '') {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach((item) => {
                    url.searchParams.append(key, String(item));
                });
            } else {
                url.searchParams.set(key, String(value));
            }
        });
        return url;
    }

    async request(path, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('X-Requested-With', 'XMLHttpRequest');

        let body = options.body;
        if (options.json !== undefined) {
            body = JSON.stringify(options.json);
            headers.set('Content-Type', 'application/json');
        } else if (body && !(body instanceof FormData) && !(body instanceof URLSearchParams) && typeof body === 'object') {
            body = JSON.stringify(body);
            headers.set('Content-Type', 'application/json');
        }

        if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && this.csrfToken) {
            headers.set('X-CSRF-Token', this.csrfToken);
        }

        const controller = new AbortController();
        const timeoutId = window.setTimeout(() => controller.abort('timeout'), options.timeout || this.timeout);
        const externalSignal = options.signal;
        const abortFromExternal = () => controller.abort(externalSignal.reason);
        if (externalSignal) {
            if (externalSignal.aborted) {
                abortFromExternal();
            } else {
                externalSignal.addEventListener('abort', abortFromExternal, { once: true });
            }
        }

        try {
            const response = await fetch(this.buildUrl(path, options.query), {
                method,
                headers,
                body: ['GET', 'HEAD'].includes(method) ? undefined : body,
                credentials: 'same-origin',
                cache: options.cache || 'no-store',
                signal: controller.signal,
            });
            const payload = await this.parseResponse(response);

            if (!response.ok || payload.success !== true) {
                const error = new ApiError(payload.message || `Request failed with status ${response.status}.`, {
                    status: response.status,
                    errors: payload.errors || {},
                    meta: payload.meta || {},
                    response: payload,
                });
                this.emitFailure(error);
                throw error;
            }
            return payload;
        } catch (error) {
            if (error instanceof ApiError) {
                throw error;
            }
            const aborted = controller.signal.aborted;
            throw new ApiError(
                aborted ? 'The request took too long or was cancelled.' : 'Unable to reach the server. Check your connection and try again.',
                { status: 0, cause: error },
            );
        } finally {
            window.clearTimeout(timeoutId);
            externalSignal?.removeEventListener('abort', abortFromExternal);
        }
    }

    async parseResponse(response) {
        if (response.status === 204) {
            return { success: true, message: '', data: null, errors: {}, meta: {} };
        }
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            throw new ApiError('The server returned an invalid response.', { status: response.status });
        }
        try {
            const payload = await response.json();
            if (!payload || typeof payload !== 'object' || typeof payload.success !== 'boolean') {
                throw new TypeError('Invalid API envelope');
            }
            return payload;
        } catch (error) {
            if (error instanceof ApiError) {
                throw error;
            }
            throw new ApiError('The server returned malformed JSON.', { status: response.status, cause: error });
        }
    }

    emitFailure(error) {
        const eventName = error.status === 401
            ? 'karoor:unauthorized'
            : error.status === 419
                ? 'karoor:csrf-expired'
                : 'karoor:api-error';
        document.dispatchEvent(new CustomEvent(eventName, { detail: { error } }));
    }

    get(path, query = {}, options = {}) {
        return this.request(path, { ...options, method: 'GET', query });
    }

    post(path, data = {}, options = {}) {
        return this.request(path, { ...options, method: 'POST', json: data });
    }

    put(path, data = {}, options = {}) {
        return this.request(path, { ...options, method: 'PUT', json: data });
    }

    patch(path, data = {}, options = {}) {
        return this.request(path, { ...options, method: 'PATCH', json: data });
    }

    delete(path, data = {}, options = {}) {
        return this.request(path, { ...options, method: 'DELETE', json: data });
    }

    value(payload, path = 'data', fallback = undefined) {
        return deepValue(payload, path, fallback);
    }
}

export const api = new ApiClient();

export default api;
