import api from './api.js';
import { initCharts } from './charts.js';
import { initPos } from './pos.js';
import { initApiForms } from './modules/forms.js';
import { initApiTables } from './modules/tables.js';
import { initPages } from './modules/pages.js';

const toastIcons = {
    success: 'fa-circle-check',
    danger: 'fa-circle-exclamation',
    warning: 'fa-triangle-exclamation',
    info: 'fa-circle-info',
};

export function notify(message, tone = 'info', options = {}) {
    const container = document.getElementById('toastContainer');
    if (!container || !message) {
        return null;
    }
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
    toast.setAttribute('aria-live', tone === 'danger' ? 'assertive' : 'polite');
    toast.setAttribute('aria-atomic', 'true');

    const body = document.createElement('div');
    body.className = 'toast-body d-flex align-items-start gap-2';
    const icon = document.createElement('i');
    icon.className = `fa-solid ${toastIcons[tone] || toastIcons.info} text-${tone === 'danger' ? 'danger' : tone}`;
    icon.setAttribute('aria-hidden', 'true');
    const copy = document.createElement('span');
    copy.className = 'flex-grow-1';
    copy.textContent = String(message);
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white';
    close.setAttribute('data-bs-dismiss', 'toast');
    close.setAttribute('aria-label', 'Close notification');
    body.append(icon, copy, close);
    toast.append(body);
    container.append(toast);

    if (window.bootstrap?.Toast) {
        const instance = new window.bootstrap.Toast(toast, { delay: options.delay || (tone === 'danger' ? 7000 : 4500) });
        toast.addEventListener('hidden.bs.toast', () => toast.remove(), { once: true });
        instance.show();
    } else {
        close.addEventListener('click', () => toast.remove());
        window.setTimeout(() => toast.remove(), options.delay || 5000);
        toast.classList.add('show');
    }
    return toast;
}

function confirmationElement() {
    let modal = document.getElementById('applicationConfirmModal');
    if (modal) {
        return modal;
    }
    modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'applicationConfirmModal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-labelledby', 'applicationConfirmTitle');
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-6" id="applicationConfirmTitle"></h2>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"><p class="mb-0" data-confirm-message></p></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" data-confirm-accept></button>
                </div>
            </div>
        </div>`;
    document.body.append(modal);
    return modal;
}

export function confirmAction(message, { title = 'Confirm action', confirmLabel = 'Continue', danger = false } = {}) {
    if (!window.bootstrap?.Modal) {
        return Promise.resolve(window.confirm(message));
    }
    const modalElement = confirmationElement();
    const titleElement = modalElement.querySelector('#applicationConfirmTitle');
    const messageElement = modalElement.querySelector('[data-confirm-message]');
    const accept = modalElement.querySelector('[data-confirm-accept]');
    titleElement.textContent = title;
    messageElement.textContent = message;
    accept.textContent = confirmLabel;
    accept.className = `btn ${danger ? 'btn-danger' : 'btn-primary'}`;

    return new Promise((resolve) => {
        const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement, { backdrop: 'static' });
        let accepted = false;
        const approve = () => {
            accepted = true;
            modal.hide();
        };
        const finish = () => {
            accept.removeEventListener('click', approve);
            resolve(accepted);
        };
        accept.addEventListener('click', approve, { once: true });
        modalElement.addEventListener('hidden.bs.modal', finish, { once: true });
        modal.show();
    });
}

function initTheme() {
    const controls = [...document.querySelectorAll('[data-theme-toggle]')];
    if (controls.length === 0) {
        return;
    }
    const sync = () => {
        const current = window.KaroorTheme?.current?.() || document.documentElement.dataset.bsTheme || 'dark';
        const next = current === 'light' ? 'dark' : 'light';
        controls.forEach((control) => {
            control.setAttribute('aria-label', `Switch to ${next} mode`);
            control.setAttribute('title', `Switch to ${next} mode`);
            const icon = control.querySelector('[data-theme-icon]');
            if (icon) {
                icon.className = `fa-solid ${current === 'light' ? 'fa-moon' : 'fa-sun'}`;
            }
        });
    };
    controls.forEach((control) => {
        control.addEventListener('click', () => {
            window.KaroorTheme?.toggle?.();
            sync();
        });
    });
    document.addEventListener('karoor:theme-change', sync);
    sync();
}

function initSidebar() {
    const toggle = document.getElementById('desktopSidebarToggle');
    if (!(toggle instanceof HTMLInputElement)) {
        return;
    }
    try {
        toggle.checked = localStorage.getItem('karoor-sidebar-collapsed') === 'true';
    } catch {
        toggle.checked = false;
    }
    const update = () => {
        document.querySelector(`label[for="${CSS.escape(toggle.id)}"]`)?.setAttribute('aria-expanded', String(!toggle.checked));
        try {
            localStorage.setItem('karoor-sidebar-collapsed', String(toggle.checked));
        } catch {
            return;
        }
    };
    toggle.addEventListener('change', update);
    update();
}

function initKeyboardNavigation() {
    document.addEventListener('keydown', (event) => {
        const target = event.target;
        const editing = target instanceof HTMLInputElement
            || target instanceof HTMLTextAreaElement
            || target instanceof HTMLSelectElement
            || target?.isContentEditable;
        if (event.key === '/' && !editing && !event.ctrlKey && !event.metaKey && !event.altKey) {
            const search = document.getElementById('globalSearch') || document.querySelector('[data-pos-search]');
            if (search instanceof HTMLElement) {
                event.preventDefault();
                search.focus();
            }
        }
    });
}

function initNativeConfirmations() {
    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form?.matches('[data-confirm]:not([data-api-form])') || form.dataset.confirmed === 'true') {
            return;
        }
        event.preventDefault();
        const submitter = event.submitter || undefined;
        if (await confirmAction(form.dataset.confirm, {
            title: form.dataset.confirmTitle || 'Confirm action',
            confirmLabel: form.dataset.confirmLabel || 'Continue',
            danger: form.dataset.confirmDanger === 'true',
        })) {
            form.dataset.confirmed = 'true';
            try {
                form.requestSubmit(submitter);
            } finally {
                delete form.dataset.confirmed;
            }
        }
    });
}

function initAutomaticForms() {
    document.addEventListener('change', (event) => {
        const control = event.target instanceof HTMLElement ? event.target.closest('[data-auto-submit]') : null;
        const form = control?.closest('form');
        if (form) {
            form.requestSubmit();
        }
    });
}

function initSessionEvents() {
    let redirecting = false;
    document.addEventListener('karoor:unauthorized', () => {
        if (redirecting) {
            return;
        }
        redirecting = true;
        const loginUrl = document.querySelector('meta[name="app-login-url"]')?.content || '/login';
        const next = `${window.location.pathname}${window.location.search}`;
        const url = new URL(loginUrl, window.location.origin);
        url.searchParams.set('next', next);
        notify('Your session has expired. Please sign in again.', 'warning');
        window.setTimeout(() => window.location.assign(url), 500);
    });
    document.addEventListener('karoor:csrf-expired', () => {
        notify('Your security token expired. Refresh the page before trying again.', 'warning', { delay: 8000 });
    });
    window.addEventListener('offline', () => notify('You are offline. Unsaved work may not be submitted.', 'warning'));
    window.addEventListener('online', () => notify('Connection restored.', 'success'));
}

function start() {
    document.documentElement.classList.add('js');
    initTheme();
    initSidebar();
    initKeyboardNavigation();
    initNativeConfirmations();
    initAutomaticForms();
    initSessionEvents();
    const context = { api, notify, confirmAction };
    initApiForms(context);
    const tables = initApiTables(context);
    const charts = initCharts(context);
    const pos = initPos(context);
    initPages({ ...context, tables, charts, pos });
    window.Karoor = Object.freeze({ api, notify, confirmAction, tables, charts, pos });
    document.dispatchEvent(new CustomEvent('karoor:ready', { detail: window.Karoor }));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
