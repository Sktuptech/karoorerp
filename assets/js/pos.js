import { ApiError } from './api.js';
import { clamp, debounce, emit, finiteNumber, formatMoney, parseJson, roundMoney, setBusy } from './modules/utils.js';

function productFromRecord(record) {
    const id = Number.parseInt(String(record.id ?? record.product_id), 10);
    if (!Number.isInteger(id) || id < 1) {
        return null;
    }
    const stockValue = record.stock_quantity ?? record.quantity_available ?? record.stock;
    return {
        id,
        name: String(record.name ?? record.product_name ?? 'Product'),
        sku: String(record.sku ?? ''),
        barcode: String(record.barcode ?? ''),
        category: String(record.category_name ?? record.category ?? ''),
        imageUrl: String(record.image_url ?? record.imageUrl ?? record.image ?? ''),
        price: Math.max(0, finiteNumber(record.selling_price ?? record.unit_price ?? record.price)),
        taxRate: Math.max(0, finiteNumber(record.tax_rate ?? record.taxRate)),
        taxInclusive: record.tax_inclusive === true || record.taxInclusive === true || Number(record.tax_inclusive) === 1,
        stock: stockValue === undefined || stockValue === null ? null : Math.max(0, finiteNumber(stockValue)),
        quantityStep: Math.max(0.0001, finiteNumber(record.quantityStep, Number(record.allows_decimal) === 1 ? 0.01 : 1)),
    };
}

function element(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== '') {
        node.textContent = text;
    }
    return node;
}

function actionButton(action, productId, label, icon, className = 'btn btn-sm btn-outline-secondary') {
    const button = element('button', className);
    button.type = 'button';
    button.dataset.posAction = action;
    button.dataset.productId = String(productId);
    button.setAttribute('aria-label', label);
    const iconNode = element('i', icon);
    iconNode.setAttribute('aria-hidden', 'true');
    button.append(iconNode);
    return button;
}

export class PosEngine {
    constructor(root, { api, notify, confirmAction }) {
        this.root = root;
        this.api = api;
        this.notify = notify;
        this.confirmAction = confirmAction;
        this.productsEndpoint = root.dataset.productsEndpoint || 'pos/products';
        this.checkoutEndpoint = root.dataset.checkoutEndpoint || 'pos/sales';
        this.currency = root.dataset.currency || document.querySelector('meta[name="app-currency"]')?.content || 'ETB';
        this.storageKey = root.dataset.storageKey || 'karoor-pos-cart';
        this.cart = new Map();
        this.products = new Map();
        this.searchController = null;
        this.searchInput = root.querySelector('[data-pos-search]');
        this.categoryInput = root.querySelector('[data-pos-category]');
        this.warehouseInput = root.querySelector('[data-pos-warehouse]');
        this.customerInput = root.querySelector('[data-pos-customer]');
        this.paymentInput = root.querySelector('[data-pos-payment-method]');
        this.tenderedInput = root.querySelector('[data-pos-tendered]');
        this.discountInput = root.querySelector('[data-pos-order-discount]');
        this.productsContainer = root.querySelector('[data-pos-products]');
        this.cartContainer = root.querySelector('[data-pos-cart]');
        this.checkoutButton = root.querySelector('[data-pos-checkout]');
        this.restore();
        this.bind();
        this.renderCart();
        if (this.productsContainer) {
            this.loadProducts();
        }
    }

    bind() {
        this.root.addEventListener('click', (event) => {
            const productButton = event.target.closest('[data-pos-product]');
            if (productButton && this.root.contains(productButton)) {
                const embedded = parseJson(productButton.dataset.product);
                const product = embedded ? productFromRecord(embedded) : this.products.get(Number(productButton.dataset.productId));
                if (product) {
                    this.add(product);
                }
                return;
            }
            const action = event.target.closest('[data-pos-action]');
            if (!action || !this.root.contains(action)) {
                return;
            }
            const productId = Number.parseInt(action.dataset.productId || '', 10);
            switch (action.dataset.posAction) {
                case 'increment':
                    this.changeQuantity(productId, this.cart.get(productId)?.product.quantityStep || 1);
                    break;
                case 'decrement':
                    this.changeQuantity(productId, -(this.cart.get(productId)?.product.quantityStep || 1));
                    break;
                case 'remove':
                    this.remove(productId);
                    break;
                case 'clear':
                    this.clearWithConfirmation();
                    break;
                case 'checkout':
                    this.checkout(action);
                    break;
            }
        });

        if (this.checkoutButton && !this.checkoutButton.hasAttribute('data-pos-action')) {
            this.checkoutButton.addEventListener('click', () => this.checkout(this.checkoutButton));
        }
        const clearButton = this.root.querySelector('[data-pos-clear]');
        if (clearButton && !clearButton.hasAttribute('data-pos-action')) {
            clearButton.addEventListener('click', () => this.clearWithConfirmation());
        }

        this.root.addEventListener('change', (event) => {
            const quantity = event.target.closest('[data-pos-quantity]');
            if (quantity) {
                this.setQuantity(Number(quantity.dataset.productId), finiteNumber(quantity.value));
            }
            const lineDiscount = event.target.closest('[data-pos-line-discount]');
            if (lineDiscount) {
                this.setLineDiscount(Number(lineDiscount.dataset.productId), finiteNumber(lineDiscount.value));
            }
        });

        const search = debounce(() => this.loadProducts(), 280);
        this.searchInput?.addEventListener('input', search);
        this.searchInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.loadProducts({ barcodeIntent: true });
            }
        });
        this.categoryInput?.addEventListener('change', () => this.loadProducts());
        this.warehouseInput?.addEventListener('change', () => {
            this.cart.clear();
            this.persist();
            this.renderCart();
            this.loadProducts();
            this.notify('Cart cleared after changing the warehouse.', 'info');
        });
        [this.discountInput, this.tenderedInput].forEach((input) => {
            input?.addEventListener('input', () => this.renderTotals());
        });
        this.paymentInput?.addEventListener('change', () => this.renderTotals());
    }

    async loadProducts({ barcodeIntent = false } = {}) {
        if (!this.productsContainer || !this.productsEndpoint) {
            return;
        }
        this.searchController?.abort();
        const controller = new AbortController();
        this.searchController = controller;
        this.renderProductState('loading', 'Loading products…');
        try {
            const payload = await this.api.get(this.productsEndpoint, {
                search: this.searchInput?.value.trim() || '',
                category_id: this.categoryInput?.value || '',
                warehouse_id: this.warehouseInput?.value || '',
                per_page: 40,
            }, { signal: controller.signal });
            const records = Array.isArray(payload.data) ? payload.data : payload.data?.items || payload.data?.products || [];
            const products = records.map(productFromRecord).filter(Boolean);
            this.products = new Map(products.map((product) => [product.id, product]));
            this.renderProducts(products);

            const scanned = this.searchInput?.value.trim() || '';
            if (barcodeIntent && scanned) {
                const exact = products.find((product) => product.barcode === scanned || product.sku === scanned);
                if (exact) {
                    this.add(exact);
                    this.searchInput.value = '';
                    this.loadProducts();
                }
            }
        } catch (error) {
            if (controller.signal.aborted) {
                return;
            }
            const message = error instanceof ApiError ? error.message : 'Unable to load products.';
            this.renderProductState('error', message);
            this.notify(message, 'danger');
        }
    }

    renderProductState(state, message) {
        if (!this.productsContainer) {
            return;
        }
        const wrapper = element('div', state === 'loading' ? 'loading-state' : 'error-state');
        const icon = element('i', state === 'loading' ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-triangle-exclamation');
        icon.setAttribute('aria-hidden', 'true');
        const copy = element('span', '', message);
        wrapper.append(icon, copy);
        this.productsContainer.replaceChildren(wrapper);
    }

    renderProducts(products) {
        if (!this.productsContainer) {
            return;
        }
        if (products.length === 0) {
            const empty = element('div', 'empty-state');
            const iconWrap = element('span', 'empty-state-icon');
            const icon = element('i', 'fa-solid fa-magnifying-glass');
            icon.setAttribute('aria-hidden', 'true');
            iconWrap.append(icon);
            empty.append(iconWrap, element('h3', '', 'No products found'), element('p', '', 'Try another name, SKU, barcode, or category.'));
            this.productsContainer.replaceChildren(empty);
            return;
        }
        const fragment = document.createDocumentFragment();
        products.forEach((product) => {
            const button = element('button', 'pos-product-card');
            button.type = 'button';
            button.dataset.posProduct = 'true';
            button.dataset.productId = String(product.id);
            button.disabled = product.stock !== null && product.stock <= 0;

            const media = element('span', 'pos-product-media');
            if (product.imageUrl && /^(?:\/|https?:\/\/)/.test(product.imageUrl)) {
                const image = document.createElement('img');
                image.src = product.imageUrl;
                image.alt = '';
                image.loading = 'lazy';
                media.append(image);
            } else {
                const icon = element('i', 'fa-solid fa-box');
                icon.setAttribute('aria-hidden', 'true');
                media.append(icon);
            }
            const content = element('span', 'pos-product-copy');
            content.append(
                element('strong', '', product.name),
                element('small', '', product.sku || product.barcode || product.category),
                element('b', '', formatMoney(product.price, this.currency)),
            );
            if (product.stock !== null) {
                content.append(element('em', product.stock <= 0 ? 'text-danger' : '', `${product.stock} in stock`));
            }
            button.append(media, content);
            fragment.append(button);
        });
        this.productsContainer.replaceChildren(fragment);
    }

    add(product) {
        const existing = this.cart.get(product.id);
        const quantity = roundMoney((existing?.quantity || 0) + product.quantityStep);
        if (product.stock !== null && quantity > product.stock) {
            this.notify(`Only ${product.stock} ${product.name} available.`, 'warning');
            return;
        }
        this.cart.set(product.id, {
            product,
            quantity,
            discount: existing?.discount || 0,
        });
        this.afterCartChange();
        this.notify(`${product.name} added to cart.`, 'success');
    }

    setQuantity(productId, quantity) {
        const item = this.cart.get(productId);
        if (!item) {
            return;
        }
        if (quantity <= 0) {
            this.remove(productId);
            return;
        }
        const maximum = item.product.stock ?? Number.MAX_SAFE_INTEGER;
        const next = clamp(roundMoney(quantity), item.product.quantityStep, maximum);
        if (quantity > maximum) {
            this.notify(`Only ${maximum} ${item.product.name} available.`, 'warning');
        }
        item.quantity = next;
        item.discount = clamp(item.discount, 0, item.product.price * next);
        this.afterCartChange();
    }

    changeQuantity(productId, change) {
        const item = this.cart.get(productId);
        if (item) {
            this.setQuantity(productId, item.quantity + change);
        }
    }

    setLineDiscount(productId, discount) {
        const item = this.cart.get(productId);
        if (!item) {
            return;
        }
        item.discount = clamp(roundMoney(discount), 0, item.product.price * item.quantity);
        this.afterCartChange();
    }

    remove(productId) {
        if (this.cart.delete(productId)) {
            this.afterCartChange();
        }
    }

    async clearWithConfirmation() {
        if (this.cart.size === 0) {
            return;
        }
        const confirmed = await this.confirmAction('Remove every item from the current cart?', {
            title: 'Clear cart',
            confirmLabel: 'Clear cart',
            danger: true,
        });
        if (confirmed) {
            this.cart.clear();
            this.afterCartChange();
        }
    }

    afterCartChange() {
        this.persist();
        this.renderCart();
        emit(this.root, 'karoor:pos-cart-change', { cart: this.cart, totals: this.totals() });
    }

    lineValues(item) {
        const gross = roundMoney(item.product.price * item.quantity);
        const lineDiscount = clamp(roundMoney(item.discount), 0, gross);
        const discounted = roundMoney(gross - lineDiscount);
        const rate = item.product.taxRate / 100;
        const tax = item.product.taxInclusive
            ? roundMoney(discounted - discounted / (1 + rate))
            : roundMoney(discounted * rate);
        const net = item.product.taxInclusive ? roundMoney(discounted - tax) : discounted;
        return { gross, lineDiscount, net, tax, total: roundMoney(net + tax) };
    }

    totals() {
        const lines = [...this.cart.values()].map((item) => ({ item, ...this.lineValues(item) }));
        const gross = roundMoney(lines.reduce((sum, line) => sum + line.gross, 0));
        const lineDiscount = roundMoney(lines.reduce((sum, line) => sum + line.lineDiscount, 0));
        const net = roundMoney(lines.reduce((sum, line) => sum + line.net, 0));
        const unadjustedTax = roundMoney(lines.reduce((sum, line) => sum + line.tax, 0));
        const discountable = roundMoney(Math.max(0, gross - lineDiscount));
        const orderValue = Math.max(0, finiteNumber(this.discountInput?.value));
        const orderDiscount = this.discountInput?.dataset.discountType === 'percent'
            ? roundMoney(discountable * clamp(orderValue, 0, 100) / 100)
            : clamp(roundMoney(orderValue), 0, discountable);
        const ratio = discountable > 0 ? (discountable - orderDiscount) / discountable : 0;
        const tax = roundMoney(unadjustedTax * ratio);
        const total = roundMoney((net + unadjustedTax) * ratio);
        const paymentMethod = this.paymentInput?.value || 'CASH';
        const enteredAmount = Math.max(0, finiteNumber(this.tenderedInput?.value));
        const tendered = ['CASH', 'CREDIT'].includes(paymentMethod) ? enteredAmount : total;
        const paid = paymentMethod === 'CREDIT' ? clamp(tendered, 0, total) : Math.min(tendered, total);
        return {
            gross,
            net,
            lineDiscount,
            orderDiscount,
            discountRatio: ratio,
            discount: roundMoney(lineDiscount + orderDiscount),
            tax,
            total,
            tendered: roundMoney(tendered),
            paid: roundMoney(paid),
            due: roundMoney(Math.max(0, total - paid)),
            change: paymentMethod === 'CASH' ? roundMoney(Math.max(0, tendered - total)) : 0,
            lines,
        };
    }

    renderCart() {
        if (!this.cartContainer) {
            this.renderTotals();
            return;
        }
        if (this.cart.size === 0) {
            const empty = element('div', 'empty-state');
            empty.append(element('h3', '', 'Your cart is empty'), element('p', '', 'Search or scan a product to begin a sale.'));
            if (this.cartContainer instanceof HTMLTableSectionElement) {
                const row = element('tr');
                const cell = element('td');
                cell.colSpan = 6;
                cell.append(empty);
                row.append(cell);
                this.cartContainer.replaceChildren(row);
            } else {
                this.cartContainer.replaceChildren(empty);
            }
            this.renderTotals();
            return;
        }

        const fragment = document.createDocumentFragment();
        this.cart.forEach((item) => {
            const values = this.lineValues(item);
            if (this.cartContainer instanceof HTMLTableSectionElement) {
                fragment.append(this.cartTableRow(item, values));
            } else {
                fragment.append(this.cartCard(item, values));
            }
        });
        this.cartContainer.replaceChildren(fragment);
        this.renderTotals();
    }

    quantityControl(item) {
        const wrap = element('span', 'pos-quantity-control');
        const decrement = actionButton('decrement', item.product.id, `Decrease ${item.product.name}`, 'fa-solid fa-minus');
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'form-control form-control-sm';
        input.dataset.posQuantity = 'true';
        input.dataset.productId = String(item.product.id);
        input.value = String(item.quantity);
        input.min = String(item.product.quantityStep);
        input.step = String(item.product.quantityStep);
        if (item.product.stock !== null) {
            input.max = String(item.product.stock);
        }
        input.setAttribute('aria-label', `${item.product.name} quantity`);
        const increment = actionButton('increment', item.product.id, `Increase ${item.product.name}`, 'fa-solid fa-plus');
        wrap.append(decrement, input, increment);
        return wrap;
    }

    discountControl(item, maximum) {
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'form-control form-control-sm pos-line-discount';
        input.dataset.posLineDiscount = 'true';
        input.dataset.productId = String(item.product.id);
        input.value = String(item.discount);
        input.min = '0';
        input.max = String(maximum);
        input.step = '0.01';
        input.setAttribute('aria-label', `${item.product.name} discount amount`);
        return input;
    }

    cartTableRow(item, values) {
        const row = element('tr');
        const product = element('td');
        product.dataset.label = 'Product';
        product.append(element('strong', '', item.product.name), element('small', 'd-block text-secondary', item.product.sku));
        const price = element('td', '', formatMoney(item.product.price, this.currency));
        price.dataset.label = 'Price';
        const quantity = element('td');
        quantity.dataset.label = 'Quantity';
        quantity.append(this.quantityControl(item));
        const discount = element('td');
        discount.dataset.label = 'Discount';
        discount.append(this.discountControl(item, values.gross));
        const total = element('td', '', formatMoney(values.total, this.currency));
        total.dataset.label = 'Total';
        const actions = element('td');
        actions.dataset.label = 'Actions';
        actions.append(actionButton('remove', item.product.id, `Remove ${item.product.name}`, 'fa-solid fa-trash', 'btn btn-sm btn-outline-danger'));
        row.append(product, price, quantity, discount, total, actions);
        return row;
    }

    cartCard(item, values) {
        const card = element('article', 'pos-cart-item');
        const heading = element('div', 'pos-cart-item-heading');
        const copy = element('span');
        copy.append(element('strong', '', item.product.name), element('small', '', item.product.sku || item.product.barcode));
        heading.append(copy, actionButton('remove', item.product.id, `Remove ${item.product.name}`, 'fa-solid fa-xmark'));
        const controls = element('div', 'pos-cart-item-controls');
        controls.append(this.quantityControl(item), this.discountControl(item, values.gross), element('strong', '', formatMoney(values.total, this.currency)));
        card.append(heading, controls);
        return card;
    }

    renderTotals() {
        const totals = this.totals();
        const values = {
            subtotal: totals.gross,
            discount: totals.discount,
            tax: totals.tax,
            total: totals.total,
            paid: totals.paid,
            due: totals.due,
            change: totals.change,
            count: this.cart.size,
            quantity: [...this.cart.values()].reduce((sum, item) => sum + item.quantity, 0),
        };
        Object.entries(values).forEach(([name, value]) => {
            this.root.querySelectorAll(`[data-pos-${name}]`).forEach((target) => {
                target.textContent = ['count', 'quantity'].includes(name) ? String(value) : formatMoney(value, this.currency);
            });
        });
        if (this.checkoutButton) {
            this.checkoutButton.disabled = this.cart.size === 0;
        }
    }

    payload() {
        const totals = this.totals();
        return {
            customer_id: this.customerInput?.value || null,
            warehouse_id: this.warehouseInput?.value || null,
            payment_method: this.paymentInput?.value || 'CASH',
            subtotal: totals.gross,
            order_discount_amount: totals.orderDiscount,
            tax_amount: totals.tax,
            total_amount: totals.total,
            tendered_amount: totals.tendered,
            paid_amount: totals.paid,
            due_amount: totals.due,
            items: totals.lines.map((line) => ({
                product_id: line.item.product.id,
                quantity: line.item.quantity,
                unit_price: line.item.product.price,
                discount_amount: line.lineDiscount,
                tax_rate: line.item.product.taxRate,
                tax_amount: roundMoney(line.tax * totals.discountRatio),
                line_total: roundMoney((line.net + line.tax) * totals.discountRatio),
            })),
        };
    }

    async checkout(button = this.checkoutButton) {
        const totals = this.totals();
        if (this.cart.size === 0) {
            this.notify('Add at least one product before taking payment.', 'warning');
            return;
        }
        if (this.warehouseInput && !this.warehouseInput.value) {
            this.notify('Select a warehouse before completing the sale.', 'warning');
            this.warehouseInput.focus();
            return;
        }
        if ((this.paymentInput?.value || 'CASH') === 'CASH' && totals.tendered < totals.total) {
            this.notify('The tendered amount is less than the sale total.', 'warning');
            this.tenderedInput?.focus();
            return;
        }
        if (!this.checkoutEndpoint) {
            this.notify('The checkout endpoint is not configured.', 'danger');
            return;
        }

        setBusy(button, true, 'Completing…');
        this.root.setAttribute('aria-busy', 'true');
        try {
            const payload = await this.api.post(this.checkoutEndpoint, this.payload());
            this.cart.clear();
            this.persist();
            this.renderCart();
            this.notify(payload.message || 'Sale completed successfully.', 'success');
            emit(this.root, 'karoor:pos-complete', { payload });
            const printUrl = payload.data?.receipt_url;
            if (typeof printUrl === 'string' && printUrl.startsWith('/') && !printUrl.startsWith('//')) {
                window.open(printUrl, '_blank', 'noopener,noreferrer');
            }
        } catch (error) {
            const message = error instanceof ApiError ? error.message : 'Unable to complete the sale.';
            this.notify(message, 'danger');
            emit(this.root, 'karoor:pos-error', { error });
        } finally {
            this.root.removeAttribute('aria-busy');
            setBusy(button, false);
        }
    }

    persist() {
        try {
            const records = [...this.cart.values()].map(({ product, quantity, discount }) => ({ product, quantity, discount }));
            if (records.length === 0) {
                sessionStorage.removeItem(this.storageKey);
            } else {
                sessionStorage.setItem(this.storageKey, JSON.stringify(records));
            }
        } catch {
            sessionStorage.removeItem(this.storageKey);
        }
    }

    restore() {
        try {
            const records = parseJson(sessionStorage.getItem(this.storageKey), []);
            if (!Array.isArray(records)) {
                return;
            }
            records.forEach((record) => {
                const product = productFromRecord(record.product || {});
                const quantity = finiteNumber(record.quantity);
                if (product && quantity > 0 && (product.stock === null || quantity <= product.stock)) {
                    this.cart.set(product.id, {
                        product,
                        quantity,
                        discount: clamp(finiteNumber(record.discount), 0, product.price * quantity),
                    });
                }
            });
        } catch {
            sessionStorage.removeItem(this.storageKey);
        }
    }
}

export function initPos(context) {
    return [...document.querySelectorAll('[data-pos]')].map((root) => new PosEngine(root, context));
}
