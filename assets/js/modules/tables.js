import { ApiError } from '../api.js';
import { debounce, deepValue, emit, finiteNumber, formatMoney, positiveInteger } from './utils.js';

function stateRow(columnCount, state, title, message) {
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.colSpan = Math.max(1, columnCount);
    cell.className = `table-state table-state-${state}`;
    const icon = document.createElement('i');
    icon.className = state === 'error'
        ? 'fa-solid fa-triangle-exclamation'
        : state === 'loading'
            ? 'fa-solid fa-spinner fa-spin'
            : 'fa-regular fa-folder-open';
    icon.setAttribute('aria-hidden', 'true');
    const strong = document.createElement('strong');
    strong.textContent = title;
    const span = document.createElement('span');
    span.textContent = message;
    cell.append(icon, strong, span);
    row.append(cell);
    return row;
}

function renderDefaultCell(cell, column, value) {
    if (value === null || value === undefined) {
        cell.textContent = '';
        return;
    }
    if (column.format === 'currency') {
        cell.textContent = formatMoney(value);
        return;
    }
    if (column.format === 'number') {
        cell.textContent = new Intl.NumberFormat().format(finiteNumber(value));
        return;
    }
    if (column.format === 'date' || column.format === 'datetime') {
        const date = new Date(String(value));
        cell.textContent = Number.isNaN(date.getTime())
            ? String(value)
            : new Intl.DateTimeFormat(undefined, column.format === 'datetime'
                ? { dateStyle: 'medium', timeStyle: 'short' }
                : { dateStyle: 'medium' }).format(date);
        return;
    }
    if (column.format === 'boolean') {
        cell.textContent = value === true || Number(value) === 1 ? 'Yes' : 'No';
        return;
    }
    if (column.format === 'status') {
        const status = String(value);
        const normalized = status.toLowerCase();
        const tone = ['active', 'completed', 'approved', 'paid'].includes(normalized)
            ? 'success'
            : ['pending', 'draft', 'partial'].includes(normalized)
                ? 'warning'
                : ['cancelled', 'rejected', 'overdue', 'inactive'].includes(normalized)
                    ? 'danger'
                    : 'info';
        const badge = document.createElement('span');
        badge.className = `status-badge status-${tone}`;
        badge.textContent = status.replaceAll('_', ' ');
        cell.append(badge);
        return;
    }
    cell.textContent = String(value);
}

export class ApiTable {
    constructor(element, { api, notify }) {
        this.element = element;
        element.closest('.table-responsive')?.classList.add('responsive-table', 'stack-mobile');
        this.api = api;
        this.notify = notify;
        this.endpoint = element.dataset.endpoint || '';
        this.body = element.tBodies[0] || element.createTBody();
        this.columns = [...element.querySelectorAll('thead th[data-field]')].map((heading) => ({
            field: heading.dataset.field,
            label: heading.textContent.trim(),
            format: heading.dataset.format || 'text',
            sortable: heading.dataset.sortable === 'true',
            heading,
        }));
        this.page = positiveInteger(element.dataset.page, 1);
        this.perPage = positiveInteger(element.dataset.perPage, 25);
        this.sort = element.dataset.sort || '';
        this.direction = element.dataset.direction === 'desc' ? 'desc' : 'asc';
        this.controller = null;
        this.search = '';
        this.filters = {};
        this.bind();
    }

    bind() {
        this.columns.filter((column) => column.sortable).forEach((column) => {
            column.heading.tabIndex = 0;
            column.heading.setAttribute('role', 'button');
            column.heading.setAttribute('aria-label', `Sort by ${column.label}`);
            const sort = () => this.setSort(column.field);
            column.heading.addEventListener('click', sort);
            column.heading.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sort();
                }
            });
        });

        const tableId = this.element.id;
        if (tableId) {
            const search = document.querySelector(`[data-table-search="${CSS.escape(tableId)}"]`);
            search?.addEventListener('input', debounce(() => {
                this.search = search.value.trim();
                this.page = 1;
                this.load();
            }, 350));

            const filterForm = document.querySelector(`[data-table-filters="${CSS.escape(tableId)}"]`);
            filterForm?.addEventListener('submit', (event) => {
                event.preventDefault();
                this.filters = Object.fromEntries(new FormData(filterForm).entries());
                this.page = 1;
                this.load();
            });
            filterForm?.addEventListener('reset', () => window.setTimeout(() => {
                this.filters = {};
                this.page = 1;
                this.load();
            }));

            document.querySelectorAll(`[data-table-page="${CSS.escape(tableId)}"]`).forEach((button) => {
                button.addEventListener('click', () => {
                    const requested = button.dataset.page;
                    const target = requested === 'next' ? this.page + 1 : requested === 'previous' ? this.page - 1 : positiveInteger(requested, this.page);
                    if (target > 0 && !button.disabled) {
                        this.page = target;
                        this.load();
                    }
                });
            });
        }
    }

    setSort(field) {
        if (this.sort === field) {
            this.direction = this.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.sort = field;
            this.direction = 'asc';
        }
        this.page = 1;
        this.columns.forEach(({ heading, field: columnField }) => {
            heading.setAttribute('aria-sort', columnField === this.sort
                ? (this.direction === 'asc' ? 'ascending' : 'descending')
                : 'none');
        });
        this.load();
    }

    query() {
        return {
            page: this.page,
            per_page: this.perPage,
            search: this.search,
            sort: this.sort,
            direction: this.direction,
            ...this.filters,
        };
    }

    async load() {
        if (!this.endpoint || this.columns.length === 0) {
            return;
        }
        this.controller?.abort();
        const controller = new AbortController();
        this.controller = controller;
        this.setState('loading', 'Loading records', 'Retrieving the latest information…');
        this.element.setAttribute('aria-busy', 'true');
        try {
            const payload = await this.api.get(this.endpoint, this.query(), { signal: controller.signal });
            const rows = Array.isArray(payload.data)
                ? payload.data
                : payload.data?.items || payload.data?.records || [];
            this.render(Array.isArray(rows) ? rows : []);
            this.updatePagination(payload.meta || {});
            emit(this.element, 'karoor:table-loaded', { payload, rows });
        } catch (error) {
            if (controller.signal.aborted) {
                return;
            }
            const message = error instanceof ApiError ? error.message : 'Unable to load records.';
            this.setState('error', 'Unable to load records', message);
            this.notify(message, 'danger');
            emit(this.element, 'karoor:table-error', { error });
        } finally {
            this.element.removeAttribute('aria-busy');
        }
    }

    render(rows) {
        this.body.replaceChildren();
        if (rows.length === 0) {
            this.body.append(stateRow(this.columns.length, 'empty', 'No records found', 'Adjust the filters or add a new record.'));
            return;
        }
        const fragment = document.createDocumentFragment();
        rows.forEach((record) => {
            const row = document.createElement('tr');
            this.columns.forEach((column) => {
                const cell = document.createElement('td');
                cell.dataset.label = column.label;
                const value = deepValue(record, column.field, '');
                const renderEvent = new CustomEvent('karoor:table-cell', {
                    detail: { cell, column, record, value },
                    cancelable: true,
                });
                if (this.element.dispatchEvent(renderEvent)) {
                    renderDefaultCell(cell, column, value);
                }
                row.append(cell);
            });
            fragment.append(row);
        });
        this.body.append(fragment);
    }

    setState(state, title, message) {
        this.body.replaceChildren(stateRow(this.columns.length, state, title, message));
    }

    updatePagination(meta) {
        this.page = positiveInteger(meta.current_page, this.page);
        const lastPage = positiveInteger(meta.last_page, 1);
        const total = Number.isFinite(Number(meta.total)) ? Number(meta.total) : 0;
        if (!this.element.id) {
            return;
        }
        const selector = CSS.escape(this.element.id);
        document.querySelectorAll(`[data-table-total="${selector}"]`).forEach((element) => {
            element.textContent = new Intl.NumberFormat().format(total);
        });
        document.querySelectorAll(`[data-table-current="${selector}"]`).forEach((element) => {
            element.textContent = String(this.page);
        });
        document.querySelectorAll(`[data-table-last="${selector}"]`).forEach((element) => {
            element.textContent = String(lastPage);
        });
        document.querySelectorAll(`[data-table-page="${selector}"]`).forEach((button) => {
            if (button.dataset.page === 'previous') {
                button.disabled = this.page <= 1;
            } else if (button.dataset.page === 'next') {
                button.disabled = this.page >= lastPage;
            }
        });
    }
}

export function initApiTables(context) {
    return [...document.querySelectorAll('table[data-api-table]')].map((table) => {
        const instance = new ApiTable(table, context);
        instance.load();
        return instance;
    });
}
