import { ApiError } from '../api.js';
import { emit, serializeForm, setBusy } from './utils.js';

function clearFieldErrors(form) {
    form.querySelectorAll('.is-invalid').forEach((field) => {
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        const describedBy = (field.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter((id) => id && !id.startsWith('error-'));
        if (describedBy.length > 0) {
            field.setAttribute('aria-describedby', describedBy.join(' '));
        } else {
            field.removeAttribute('aria-describedby');
        }
    });
    form.querySelectorAll('[data-generated-error]').forEach((message) => {
        message.remove();
    });
}

function firstMessage(value) {
    if (Array.isArray(value)) {
        return value.find((item) => typeof item === 'string') || '';
    }
    return typeof value === 'string' ? value : '';
}

function showFieldErrors(form, errors) {
    if (!errors || typeof errors !== 'object') {
        return;
    }
    let firstInvalid = null;
    Object.entries(errors).forEach(([name, messages]) => {
        const field = form.elements.namedItem(name);
        const input = field instanceof RadioNodeList ? field[0] : field;
        if (!(input instanceof HTMLElement)) {
            return;
        }
        const message = firstMessage(messages);
        if (!message) {
            return;
        }
        const errorId = `error-${form.id || 'form'}-${name.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
        input.classList.add('is-invalid');
        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), errorId].filter(Boolean).join(' '));

        const feedback = document.createElement('div');
        feedback.id = errorId;
        feedback.className = 'invalid-feedback d-block';
        feedback.dataset.generatedError = 'true';
        feedback.textContent = message;
        const wrapper = input.closest('[data-field], .form-field, .mb-3') || input.parentElement;
        wrapper?.append(feedback);
        firstInvalid ||= input;
    });
    firstInvalid?.focus({ preventScroll: true });
    firstInvalid?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function nativeValidationErrors(form) {
    const invalid = [...form.elements].filter(
        (field) => field instanceof HTMLElement && 'checkValidity' in field && !field.checkValidity(),
    );
    invalid.forEach((field) => {
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
    });
    invalid[0]?.focus();
    return invalid.length > 0;
}

function requestForForm(form) {
    const method = (form.dataset.method || form.getAttribute('method') || 'POST').toUpperCase();
    const endpoint = form.dataset.endpoint || form.getAttribute('action') || window.location.pathname;
    const data = serializeForm(form);
    if (method === 'GET') {
        return { endpoint, options: { method, query: data } };
    }
    if (form.dataset.encoding === 'multipart') {
        return { endpoint, options: { method, body: new FormData(form) } };
    }
    return { endpoint, options: { method, json: data } };
}

export function initApiForms({ api, notify, confirmAction }) {
    document.addEventListener('input', (event) => {
        if (!(event.target instanceof HTMLElement) || !event.target.closest('form[data-api-form]')) {
            return;
        }
        const field = event.target;
        const errorIds = (field.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter((id) => id.startsWith('error-'));
        errorIds.forEach((id) => {
            document.getElementById(id)?.remove();
        });
        const remainingIds = (field.getAttribute('aria-describedby') || '')
            .split(/\s+/)
            .filter((id) => id && !id.startsWith('error-'));
        field.classList.remove('is-invalid');
        field.removeAttribute('aria-invalid');
        if (remainingIds.length > 0) {
            field.setAttribute('aria-describedby', remainingIds.join(' '));
        } else {
            field.removeAttribute('aria-describedby');
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form?.matches('[data-api-form]')) {
            return;
        }
        event.preventDefault();
        if (form.dataset.submitting === 'true') {
            return;
        }

        clearFieldErrors(form);
        if (nativeValidationErrors(form)) {
            notify('Please correct the highlighted fields.', 'warning');
            return;
        }

        const confirmation = form.dataset.confirm;
        if (confirmation && !(await confirmAction(confirmation, {
            title: form.dataset.confirmTitle || 'Confirm action',
            confirmLabel: form.dataset.confirmLabel || 'Continue',
            danger: form.dataset.confirmDanger === 'true',
        }))) {
            return;
        }

        const submitter = event.submitter instanceof HTMLElement
            ? event.submitter
            : form.querySelector('[type="submit"]');
        const { endpoint, options } = requestForForm(form);
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        setBusy(submitter, true, form.dataset.loadingLabel || 'Saving…');

        try {
            const payload = await api.request(endpoint, options);
            notify(payload.message || form.dataset.successMessage || 'Saved successfully.', 'success');
            emit(form, 'karoor:form-success', { payload });
            if (form.dataset.resetOnSuccess === 'true') {
                form.reset();
            }
            const redirect = payload.meta?.redirect || payload.data?.redirect || form.dataset.successRedirect;
            if (typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//')) {
                window.location.assign(redirect);
            }
        } catch (error) {
            if (error instanceof ApiError) {
                showFieldErrors(form, error.errors);
                notify(error.message, error.status === 422 ? 'warning' : 'danger');
            } else {
                notify('Unable to submit the form.', 'danger');
            }
            emit(form, 'karoor:form-error', { error });
        } finally {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
            setBusy(submitter, false);
        }
    });
}

export { clearFieldErrors, showFieldErrors };
