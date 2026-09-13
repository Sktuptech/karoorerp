import { ApiError } from './api.js';
import { deepValue, emit, serializeForm } from './modules/utils.js';

const palette = ['#3b82f6', '#10b981', '#f59e0b', '#a855f7', '#ef4444', '#06b6d4', '#f97316', '#84cc16'];

function themeColors() {
    const light = document.documentElement.dataset.bsTheme === 'light';
    return light
        ? { text: '#64748b', border: '#dbe3ef', tooltip: '#ffffff', tooltipText: '#0f172a', card: '#ffffff', grid: 'rgba(100, 116, 139, 0.13)' }
        : { text: '#94a3b8', border: '#1e293b', tooltip: '#0b1120', tooltipText: '#f8fafc', card: '#131d31', grid: 'rgba(148, 163, 184, 0.09)' };
}

function colorWithAlpha(color, alpha) {
    if (!/^#[0-9a-f]{6}$/i.test(color)) {
        return color;
    }
    const red = Number.parseInt(color.slice(1, 3), 16);
    const green = Number.parseInt(color.slice(3, 5), 16);
    const blue = Number.parseInt(color.slice(5, 7), 16);
    return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
}

function normalizeDatasets(type, datasets) {
    const theme = themeColors();
    return datasets.map((dataset, index) => {
        const color = dataset.borderColor || palette[index % palette.length];
        if (['doughnut', 'pie', 'polarArea'].includes(type)) {
            return {
                ...dataset,
                backgroundColor: dataset.backgroundColor || dataset.data.map((_, itemIndex) => palette[itemIndex % palette.length]),
                borderColor: dataset.borderColor || theme.card,
                borderWidth: dataset.borderWidth ?? 3,
                hoverOffset: dataset.hoverOffset ?? 5,
            };
        }
        return {
            ...dataset,
            borderColor: color,
            backgroundColor: dataset.backgroundColor || colorWithAlpha(color, type === 'line' ? 0.14 : 0.72),
            borderWidth: dataset.borderWidth ?? 2,
            pointRadius: dataset.pointRadius ?? (type === 'line' ? 2 : 0),
            pointHoverRadius: dataset.pointHoverRadius ?? 5,
            tension: dataset.tension ?? (type === 'line' ? 0.35 : 0),
            fill: dataset.fill ?? false,
        };
    });
}

function chartOptions(type, horizontal = false) {
    const radial = ['doughnut', 'pie', 'polarArea'].includes(type);
    const theme = themeColors();
    return {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: horizontal ? 'y' : 'x',
        animation: { duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 450 },
        interaction: { mode: radial ? 'nearest' : 'index', intersect: radial },
        plugins: {
            legend: {
                display: true,
                position: radial ? 'bottom' : 'top',
                align: 'end',
                labels: { color: theme.text, boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 16 },
            },
            tooltip: {
                backgroundColor: theme.tooltip,
                titleColor: theme.tooltipText,
                bodyColor: theme.text,
                borderColor: theme.border,
                borderWidth: 1,
                padding: 12,
                cornerRadius: 9,
                displayColors: true,
            },
        },
        scales: radial ? undefined : {
            x: {
                grid: { display: horizontal, color: theme.grid },
                ticks: { color: theme.text, maxRotation: 0, autoSkipPadding: 16 },
                border: { color: theme.border },
                stacked: false,
            },
            y: {
                beginAtZero: true,
                grid: { color: theme.grid },
                ticks: { color: theme.text, precision: 0 },
                border: { color: theme.border },
                stacked: false,
            },
        },
    };
}

function createState(kind, title, message) {
    const state = document.createElement('div');
    state.className = `${kind}-state chart-state`;
    state.dataset.chartState = kind;
    const iconWrap = document.createElement('span');
    iconWrap.className = kind === 'error' ? 'error-state-icon' : kind === 'empty' ? 'empty-state-icon' : 'skeleton';
    if (kind === 'loading') {
        iconWrap.style.width = '100%';
        iconWrap.style.height = '220px';
    } else {
        const icon = document.createElement('i');
        icon.className = kind === 'error' ? 'fa-solid fa-chart-line' : 'fa-regular fa-chart-bar';
        icon.setAttribute('aria-hidden', 'true');
        iconWrap.append(icon);
    }
    state.append(iconWrap);
    if (kind !== 'loading') {
        const heading = document.createElement('h3');
        heading.textContent = title;
        const copy = document.createElement('p');
        copy.textContent = message;
        state.append(heading, copy);
    }
    return state;
}

export class DynamicChart {
    constructor(canvas, { api, notify }) {
        this.canvas = canvas;
        this.api = api;
        this.notify = notify;
        this.endpoint = canvas.dataset.chartEndpoint || '';
        this.type = canvas.dataset.chartType || 'line';
        this.dataPath = canvas.dataset.chartData || 'data';
        this.chart = null;
        this.controller = null;
        this.container = canvas.closest('.chart-wrap') || canvas.parentElement;
        this.filter = canvas.dataset.chartFilter
            ? document.querySelector(canvas.dataset.chartFilter)
            : null;
        this.bind();
    }

    bind() {
        document.addEventListener('karoor:theme-change', () => {
            if (this.chart) {
                this.load();
            }
        });
        if (this.filter instanceof HTMLFormElement) {
            this.filter.addEventListener('submit', (event) => {
                event.preventDefault();
                this.load();
            });
            this.filter.addEventListener('change', () => {
                if (this.filter.dataset.autoSubmit !== 'false') {
                    this.load();
                }
            });
        }
        const chartId = this.canvas.id;
        if (chartId) {
            document.querySelectorAll(`[data-chart-refresh="${CSS.escape(chartId)}"]`).forEach((button) => {
                button.addEventListener('click', () => this.load());
            });
            document.querySelectorAll(`[data-chart-period][data-chart-target="${CSS.escape(chartId)}"]`).forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll(`[data-chart-period][data-chart-target="${CSS.escape(chartId)}"]`)
                        .forEach((item) => {
                            item.classList.toggle('active', item === button);
                        });
                    this.load({ period: button.dataset.chartPeriod });
                });
            });
        }
    }

    query(extra = {}) {
        const filters = this.filter instanceof HTMLFormElement ? serializeForm(this.filter) : {};
        return { ...filters, ...extra };
    }

    setState(kind, title = '', message = '') {
        this.container?.querySelectorAll('[data-chart-state]').forEach((state) => {
            state.remove();
        });
        this.canvas.hidden = kind !== 'ready';
        if (kind !== 'ready') {
            this.container?.append(createState(kind, title, message));
        }
    }

    async load(extraQuery = {}) {
        if (!this.endpoint) {
            return;
        }
        if (typeof window.Chart !== 'function') {
            this.setState('error', 'Chart unavailable', 'The chart library could not be loaded.');
            return;
        }
        this.controller?.abort();
        const controller = new AbortController();
        this.controller = controller;
        this.setState('loading');
        this.container?.setAttribute('aria-busy', 'true');
        try {
            const payload = await this.api.get(this.endpoint, this.query(extraQuery), { signal: controller.signal });
            const source = deepValue(payload, this.dataPath, {});
            const labels = Array.isArray(source?.labels) ? source.labels : [];
            const datasets = Array.isArray(source?.datasets) ? source.datasets : [];
            const hasValues = labels.length > 0 && datasets.some((dataset) => Array.isArray(dataset.data) && dataset.data.length > 0);
            if (!hasValues) {
                this.chart?.destroy();
                this.chart = null;
                this.setState('empty', 'No chart data', 'There are no results for the selected period.');
                return;
            }
            this.setState('ready');
            this.chart?.destroy();
            this.chart = new window.Chart(this.canvas.getContext('2d'), {
                type: this.type,
                data: { labels, datasets: normalizeDatasets(this.type, datasets) },
                options: {
                    ...chartOptions(this.type, this.canvas.dataset.chartHorizontal === 'true'),
                    ...(source.options && typeof source.options === 'object' ? source.options : {}),
                },
            });
            emit(this.canvas, 'karoor:chart-loaded', { payload, chart: this.chart });
        } catch (error) {
            if (controller.signal.aborted) {
                return;
            }
            const message = error instanceof ApiError ? error.message : 'Unable to load chart data.';
            this.setState('error', 'Unable to load chart', message);
            this.notify(message, 'danger');
            emit(this.canvas, 'karoor:chart-error', { error });
        } finally {
            this.container?.removeAttribute('aria-busy');
        }
    }
}

export function initCharts(context) {
    return [...document.querySelectorAll('canvas[data-chart-endpoint]')].map((canvas) => {
        const instance = new DynamicChart(canvas, context);
        instance.load();
        return instance;
    });
}
