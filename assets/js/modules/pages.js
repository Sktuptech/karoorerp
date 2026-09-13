function tableController(tables, element) {
    return tables.find((instance) => instance.element === element);
}

function productsFromScript(id) {
    const node = document.getElementById(id);
    if (!node) return [];
    try {
        const products = JSON.parse(node.textContent || '[]');
        return Array.isArray(products) ? products : [];
    } catch {
        return [];
    }
}

function createNumberCell(value, step = '0.01') {
    const cell = document.createElement('td');
    const input = document.createElement('input');
    input.className = 'form-control';
    input.type = 'number';
    input.min = '0';
    input.step = step;
    input.value = value;
    input.required = true;
    cell.append(input);
    return [cell, input];
}

function addDocumentLine(lines, products, priceField) {
    const row = document.createElement('tr');
    const productCell = document.createElement('td');
    const select = document.createElement('select');
    select.className = 'form-select';
    select.required = true;
    const prompt = document.createElement('option');
    prompt.value = '';
    prompt.textContent = 'Select product';
    select.append(prompt);
    products.forEach((product) => {
        const option = document.createElement('option');
        option.value = String(product.id);
        option.textContent = `${product.name} (${product.sku})`;
        option.dataset.price = String(product[priceField] || 0);
        select.append(option);
    });
    productCell.append(select);
    const [quantityCell, quantity] = createNumberCell('1', '0.0001');
    quantity.min = '0.0001';
    const [priceCell, price] = createNumberCell('0');
    const [discountCell] = createNumberCell('0');
    select.addEventListener('change', () => {
        price.value = select.selectedOptions[0]?.dataset.price || '0';
    });
    const removeCell = document.createElement('td');
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-sm btn-outline-danger';
    remove.setAttribute('aria-label', 'Remove line');
    const icon = document.createElement('i');
    icon.className = 'fa-solid fa-xmark';
    icon.setAttribute('aria-hidden', 'true');
    remove.append(icon);
    remove.addEventListener('click', () => {
        if (lines.children.length > 1) row.remove();
    });
    removeCell.append(remove);
    row.append(productCell, quantityCell, priceCell, discountCell, removeCell);
    lines.append(row);
}

function initDashboard({ api, notify }) {
    const root = document.querySelector('[data-dashboard-kpis]');
    if (!root) return;
    const currency = root.dataset.currency || 'ETB';
    const money = new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 });
    const number = new Intl.NumberFormat();
    api.get('dashboard').then((payload) => {
        Object.entries(payload.data?.kpis || {}).forEach(([key, metric]) => {
            const card = root.querySelector(`[data-kpi="${CSS.escape(key)}"]`);
            if (!card) return;
            const formatter = card.dataset.kpiMoney === 'true' ? money : number;
            card.querySelector('[data-kpi-value]').textContent = formatter.format(Number(metric.value || 0));
            const trend = card.querySelector('[data-kpi-trend]');
            if (metric.comparison_available && metric.change_percent !== null) {
                const change = Number(metric.change_percent);
                trend.textContent = `${change > 0 ? '+' : ''}${change.toFixed(1)}% vs previous day`;
                trend.classList.add(change > 0 ? 'trend-up' : change < 0 ? 'trend-down' : 'trend-neutral');
            } else {
                trend.textContent = 'Current balance';
                trend.classList.add('trend-neutral');
            }
            card.removeAttribute('aria-busy');
        });
        const actions = document.querySelector('[data-dashboard-actions]');
        if (!actions) return;
        actions.replaceChildren();
        (payload.data?.quick_actions || []).forEach((item) => {
            const link = document.createElement('a');
            link.className = 'btn btn-outline-primary';
            link.href = item.url;
            link.textContent = item.label;
            actions.append(link);
        });
        if (!actions.childElementCount) actions.textContent = 'No quick actions are available for your role.';
    }).catch((error) => {
        root.querySelectorAll('[data-kpi]').forEach((card) => card.removeAttribute('aria-busy'));
        notify(error.message || 'Unable to load dashboard totals.', 'danger');
    });
}

function initSales({ api, notify, confirmAction, tables }) {
    const table = document.getElementById('salesTable');
    if (!table) return;
    const reload = () => tableController(tables, table)?.load();
    table.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.field !== 'id') return;
        event.preventDefault();
        const { cell, record } = event.detail;
        const group = document.createElement('div');
        group.className = 'btn-group btn-group-sm';
        const action = (label, endpoint, tone = 'outline-secondary') => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-${tone}`;
            button.textContent = label;
            button.addEventListener('click', async () => {
                if (!await confirmAction(`${label} invoice ${record.invoice_number}?`, { danger: label !== 'Complete' })) return;
                try {
                    await api.post(endpoint, label === 'Complete' ? { payment_method: 'CASH', paid_amount: 0 } : {});
                    notify(`Invoice action completed.`, 'success');
                    reload();
                } catch (error) {
                    notify(error.message, 'danger');
                }
            });
            group.append(button);
        };
        if (record.status === 'DRAFT') {
            action('Complete', `sales/${record.id}/complete`, 'outline-success');
            action('Cancel', `sales/${record.id}/cancel`, 'outline-danger');
        }
        if (record.status === 'COMPLETED') action('Refund', `sales/${record.id}/refund`, 'outline-danger');
        const print = document.createElement('a');
        print.className = 'btn btn-outline-secondary';
        print.href = `${table.dataset.printUrl}?id=${encodeURIComponent(record.id)}`;
        print.textContent = 'Print';
        group.append(print);
        cell.append(group);
    });

    const form = document.getElementById('saleCreateForm');
    const lines = document.getElementById('saleLines');
    if (!form || !lines) return;
    const products = productsFromScript('saleProductData');
    const addLine = () => addDocumentLine(lines, products, 'selling_price');
    document.getElementById('addSaleLine')?.addEventListener('click', addLine);
    addLine();
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.customer_id ||= null;
        payload.account_id ||= null;
        payload.items = [...lines.rows].map((row) => ({
            product_id: row.cells[0].querySelector('select').value,
            quantity: row.cells[1].querySelector('input').value,
            unit_price: row.cells[2].querySelector('input').value,
            discount_amount: row.cells[3].querySelector('input').value,
        }));
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            const result = await api.post('sales', payload);
            notify(result.message || 'Sale saved.', 'success');
            window.bootstrap.Modal.getInstance(document.getElementById('newSaleModal'))?.hide();
            form.reset();
            lines.replaceChildren();
            addLine();
            reload();
        } catch (error) {
            notify(error.message || 'Unable to save the sale.', 'danger');
        } finally {
            submit.disabled = false;
        }
    });
}

function initPurchases({ api, notify, confirmAction, tables }) {
    const table = document.getElementById('purchasesTable');
    if (!table) return;
    const reload = () => tableController(tables, table)?.load();
    table.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.field !== 'id') return;
        event.preventDefault();
        const { cell, record } = event.detail;
        const group = document.createElement('div');
        group.className = 'btn-group btn-group-sm';
        const action = (label, endpoint, tone) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-outline-${tone}`;
            button.textContent = label;
            button.addEventListener('click', async () => {
                if (!await confirmAction(`${label} purchase ${record.purchase_number}?`, { danger: label === 'Cancel' })) return;
                try {
                    await api.post(endpoint, {});
                    notify('Purchase action completed.', 'success');
                    reload();
                } catch (error) {
                    notify(error.message, 'danger');
                }
            });
            group.append(button);
        };
        if (['DRAFT', 'ORDERED'].includes(record.status)) action('Receive', `purchases/${record.id}/receive`, 'success');
        if (!['RECEIVED', 'CANCELLED'].includes(record.status)) action('Cancel', `purchases/${record.id}/cancel`, 'danger');
        cell.append(group);
    });

    const form = document.getElementById('purchaseCreateForm');
    const lines = document.getElementById('purchaseLines');
    if (!form || !lines) return;
    const products = productsFromScript('purchaseProductData');
    const addLine = () => addDocumentLine(lines, products, 'purchase_price');
    document.getElementById('addPurchaseLine')?.addEventListener('click', addLine);
    addLine();
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const payload = Object.fromEntries(new FormData(form).entries());
        payload.account_id ||= null;
        payload.items = [...lines.rows].map((row) => ({
            product_id: row.cells[0].querySelector('select').value,
            quantity: row.cells[1].querySelector('input').value,
            unit_cost: row.cells[2].querySelector('input').value,
            discount_amount: row.cells[3].querySelector('input').value,
        }));
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            const result = await api.post('purchases', payload);
            notify(result.message || 'Purchase saved.', 'success');
            window.bootstrap.Modal.getInstance(document.getElementById('newPurchaseModal'))?.hide();
            form.reset();
            lines.replaceChildren();
            addLine();
            reload();
        } catch (error) {
            notify(error.message || 'Unable to save the purchase.', 'danger');
        } finally {
            submit.disabled = false;
        }
    });
}

function initInventory({ api, notify, confirmAction, tables }) {
    const table = document.getElementById('inventoryTable');
    if (!table) return;
    const reload = () => tableController(tables, table)?.load();
    table.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.format !== 'actions') return;
        event.preventDefault();
        const { cell, record } = event.detail;
        let label = '';
        let path = '';
        if ('adjustment_number' in record && record.status === 'DRAFT') {
            label = 'Post';
            path = `inventory/adjustments/${record.id}/post`;
        }
        if ('transfer_number' in record && record.status === 'PENDING') {
            label = 'Approve';
            path = `inventory/transfers/${record.id}/approve`;
        } else if ('transfer_number' in record && ['APPROVED', 'IN_TRANSIT'].includes(record.status)) {
            label = 'Complete';
            path = `inventory/transfers/${record.id}/complete`;
        }
        if (!path) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-primary';
        button.textContent = label;
        button.addEventListener('click', async () => {
            if (!await confirmAction(`${label} ${record.adjustment_number || record.transfer_number}?`)) return;
            try {
                await api.post(path, {});
                notify(`${label} completed.`, 'success');
                reload();
            } catch (error) {
                notify(error.message, 'danger');
            }
        });
        cell.append(button);
    });
    const form = document.getElementById('inventoryMovementForm');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const data = Object.fromEntries(new FormData(form).entries());
        const product = document.getElementById('movementProduct');
        const item = { product_id: product.value, quantity: document.getElementById('movementQuantity').value };
        if (form.dataset.mode === 'adjustments') {
            data.status = 'POSTED';
            item.unit_cost = product.selectedOptions[0]?.dataset.cost || 0;
        }
        data.items = [item];
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            await api.post(`inventory/${form.dataset.mode}`, data);
            notify('Inventory transaction saved.', 'success');
            window.bootstrap.Modal.getInstance(document.getElementById('inventoryCreateModal'))?.hide();
            form.reset();
            reload();
        } catch (error) {
            notify(error.message || 'Unable to save inventory transaction.', 'danger');
        } finally {
            submit.disabled = false;
        }
    });
}

function initContacts({ api, notify }) {
    const table = document.getElementById('partyTable');
    if (!table) return;
    const money = new Intl.NumberFormat(undefined, { style: 'currency', currency: table.dataset.currency || 'ETB', maximumFractionDigits: 2 });
    table.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.field !== 'id') return;
        event.preventDefault();
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-primary';
        button.textContent = 'View ledger';
        button.addEventListener('click', async () => {
            try {
                const payload = await api.get(`${table.dataset.partyType}/${event.detail.record.id}`);
                const party = payload.data;
                document.getElementById('partyDetailTitle').textContent = party.name;
                document.querySelector('[data-party-contact]').textContent = [party.phone, party.email].filter(Boolean).join(' · ');
                document.querySelector('[data-party-balance]').textContent = money.format(Number(party.balance || 0));
                document.querySelector('[data-party-address]').textContent = [party.address, party.city].filter(Boolean).join(', ') || 'No address recorded';
                const body = document.querySelector('[data-party-ledger]');
                body.replaceChildren();
                (party.ledger || []).forEach((entry) => {
                    const row = document.createElement('tr');
                    [new Date(entry.transaction_date).toLocaleString(), String(entry.transaction_type).replaceAll('_', ' '), entry.description || '', money.format(Number(entry.debit || 0)), money.format(Number(entry.credit || 0))].forEach((value) => {
                        const cell = document.createElement('td');
                        cell.textContent = value;
                        row.append(cell);
                    });
                    body.append(row);
                });
                if (!body.children.length) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 5;
                    cell.className = 'text-center text-secondary py-4';
                    cell.textContent = 'No ledger entries recorded.';
                    row.append(cell);
                    body.append(row);
                }
                window.bootstrap.Modal.getOrCreateInstance(document.getElementById('partyDetailModal')).show();
            } catch (error) {
                notify(error.message || 'Unable to load party details.', 'danger');
            }
        });
        event.detail.cell.append(button);
    });
}

function makeButton(label, tone, handler) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `btn btn-sm btn-outline-${tone}`;
    button.textContent = label;
    button.addEventListener('click', handler);
    return button;
}

function initFinance({ api, notify, confirmAction, tables }) {
    const table = document.getElementById('financeTable');
    if (table) {
        const reload = () => tableController(tables, table)?.load();
        table.addEventListener('karoor:table-cell', (event) => {
            if (event.detail.column.format !== 'actions') return;
            event.preventDefault();
            const { record, cell } = event.detail;
            const group = document.createElement('div');
            group.className = 'btn-group btn-group-sm';
            const action = (label, path, tone, danger = false) => group.append(makeButton(label, tone, async () => {
                if (!await confirmAction(`${label} journal ${record.entry_number}?`, { danger })) return;
                try { await api.post(path, {}); notify(`Journal ${label.toLowerCase()}ed.`, 'success'); reload(); }
                catch (error) { notify(error.message, 'danger'); }
            }));
            if (record.status === 'DRAFT') action('Post', `finance/journals/${record.id}/post`, 'success');
            if (record.status === 'POSTED') action('Reverse', `finance/journals/${record.id}/reverse`, 'danger', true);
            cell.append(group);
        });
    }
    const form = document.getElementById('journalCreateForm');
    const body = document.getElementById('journalLines');
    if (!form || !body) return;
    const accounts = productsFromScript('journalAccountData');
    const addLine = () => {
        const row = document.createElement('tr');
        const accountCell = document.createElement('td');
        const select = document.createElement('select'); select.className = 'form-select'; select.required = true;
        select.append(new Option('Select account', ''));
        accounts.forEach((account) => select.append(new Option(`${account.code} · ${account.name}`, account.id)));
        accountCell.append(select);
        const descriptionCell = document.createElement('td');
        const description = document.createElement('input'); description.className = 'form-control'; description.maxLength = 255; descriptionCell.append(description);
        const [debitCell, debit] = createNumberCell('0');
        const [creditCell, credit] = createNumberCell('0');
        debit.required = false; credit.required = false;
        const removeCell = document.createElement('td');
        removeCell.append(makeButton('Remove', 'danger', () => { if (body.rows.length > 2) row.remove(); }));
        row.append(accountCell, descriptionCell, debitCell, creditCell, removeCell); body.append(row);
    };
    document.getElementById('addJournalLine')?.addEventListener('click', addLine); addLine(); addLine();
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (!form.reportValidity()) return;
        const data = Object.fromEntries(new FormData(form).entries());
        data.status = document.getElementById('postJournal')?.checked ? 'POSTED' : 'DRAFT'; delete data.post_now;
        data.lines = [...body.rows].map((row) => ({ chart_account_id: row.cells[0].querySelector('select').value, description: row.cells[1].querySelector('input').value, debit: row.cells[2].querySelector('input').value, credit: row.cells[3].querySelector('input').value }));
        const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
        try { await api.post('finance/journals', data); notify('Journal entry created.', 'success'); window.location.reload(); }
        catch (error) { notify(error.message, 'danger'); }
        finally { submit.disabled = false; }
    });
}

function initExpenses({ api, notify, confirmAction, tables }) {
    const table = document.getElementById('expenseTable'); if (!table) return;
    table.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.format !== 'actions') return; event.preventDefault();
        event.detail.cell.append(makeButton('Archive', 'danger', async () => {
            if (!await confirmAction(`Reverse and archive ${event.detail.record.expense_number}?`, { danger: true, confirmLabel: 'Archive expense' })) return;
            try { await api.delete(`expenses/${event.detail.record.id}`); notify('Expense reversed and archived.', 'success'); tableController(tables, table)?.load(); }
            catch (error) { notify(error.message, 'danger'); }
        }));
    });
}

function initHrm({ api, notify, confirmAction, tables }) {
    const table = document.getElementById('hrTable'); if (!table) return;
    const payslipOnly = table.dataset.payslipOnly === 'true';
    const reload = () => tableController(tables, table)?.load();
    table.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.format !== 'actions') return; event.preventDefault();
        const { record, cell } = event.detail; const group = document.createElement('div'); group.className = 'btn-group btn-group-sm';
        if ('leave_type' in record && record.status === 'PENDING') {
            ['approve', 'reject'].forEach((action) => group.append(makeButton(action[0].toUpperCase() + action.slice(1), action === 'approve' ? 'success' : 'danger', async () => {
                if (!await confirmAction(`${action} this leave request?`, { danger: action === 'reject' })) return;
                try { await api.post(`hrm/leave/${record.id}/${action}`, {}); notify('Leave request updated.', 'success'); reload(); } catch (error) { notify(error.message, 'danger'); }
            })));
        }
        if ('payroll_month' in record) {
            if (!payslipOnly && record.status === 'DRAFT') group.append(makeButton('Approve', 'success', async () => {
                if (!await confirmAction(`Approve payroll for ${record.employee_name}?`)) return;
                try { await api.post(`hrm/payroll/${record.id}/approve`, {}); notify('Payroll approved.', 'success'); reload(); } catch (error) { notify(error.message, 'danger'); }
            }));
            if (!payslipOnly && record.status === 'APPROVED') group.append(makeButton('Pay', 'primary', () => {
                const modal = document.getElementById('payrollPayModal'); modal.querySelector('[name="payroll_id"]').value = record.id; window.bootstrap.Modal.getOrCreateInstance(modal).show();
            }));
            const print = document.createElement('a'); print.className = 'btn btn-outline-secondary'; print.textContent = 'Payslip'; print.href = `${table.dataset.printUrl}?id=${encodeURIComponent(record.id)}`; group.append(print);
        }
        cell.append(group);
    });
    const payForm = document.getElementById('payrollPayForm');
    payForm?.addEventListener('submit', async (event) => {
        event.preventDefault(); if (!payForm.reportValidity()) return; const data = Object.fromEntries(new FormData(payForm).entries()); const id = data.payroll_id; delete data.payroll_id;
        const submit = payForm.querySelector('[type="submit"]'); submit.disabled = true;
        try { await api.post(`hrm/payroll/${id}/pay`, data); notify('Payroll paid and posted.', 'success'); window.bootstrap.Modal.getInstance(document.getElementById('payrollPayModal'))?.hide(); reload(); }
        catch (error) { notify(error.message, 'danger'); } finally { submit.disabled = false; }
    });
}

function initReports() {
    const table = document.getElementById('reportTable'); const filters = document.getElementById('reportFilters'); const exportLink = document.querySelector('[data-report-export]');
    const updateExport = () => {
        if (!table || !exportLink) return;
        const base = document.querySelector('meta[name="api-base-url"]')?.content || '/api/v1';
        const url = new URL(`${base}/${table.dataset.endpoint}`, window.location.origin);
        if (filters) new FormData(filters).forEach((value, key) => { if (value !== '') url.searchParams.set(key, value); });
        url.searchParams.set('format', 'csv'); exportLink.href = url.toString();
    };
    filters?.addEventListener('submit', () => window.setTimeout(updateExport)); filters?.addEventListener('reset', () => window.setTimeout(updateExport)); updateExport();
    document.querySelectorAll('[data-print-trigger]').forEach((button) => button.addEventListener('click', () => window.print()));
}

function initRecycleBin({ api, notify, confirmAction, tables }) {
    const table = document.getElementById('recycleTable'); if (!table) return;
    table.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.format !== 'actions') return; event.preventDefault(); const { record, cell } = event.detail; const group = document.createElement('div'); group.className = 'btn-group btn-group-sm';
        const run = (action, tone) => group.append(makeButton(action === 'restore' ? 'Restore' : 'Delete permanently', tone, async () => {
            if (!await confirmAction(`${action === 'restore' ? 'Restore' : 'Permanently delete'} ${record.record_name}?`, { danger: action === 'purge', confirmLabel: action === 'restore' ? 'Restore' : 'Delete permanently' })) return;
            try { await api.post(`system/recycle-bin/${record.id}/${action}`, { record_type: record.record_type }); notify(action === 'restore' ? 'Record restored.' : 'Record permanently deleted.', 'success'); tableController(tables, table)?.load(); } catch (error) { notify(error.message, 'danger'); }
        }));
        run('restore', 'success'); run('purge', 'danger'); cell.append(group);
    });
}

function initSettings({ api, notify, confirmAction, tables }) {
    const bind = (id, key, transform) => document.getElementById(id)?.addEventListener('submit', async (event) => {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return; const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
        try { await api.patch('system/settings', { [key]: transform(form) }); notify('Settings updated.', 'success'); }
        catch (error) { notify(error.message, 'danger'); } finally { submit.disabled = false; }
    });
    bind('companySettingsForm', 'company', (form) => Object.fromEntries(new FormData(form).entries()));
    bind('applicationSettingsForm', 'settings', (form) => Object.fromEntries(new FormData(form).entries()));
    bind('numberingSettingsForm', 'sequences', (form) => [...form.querySelectorAll('[data-sequence-row]')].map((row) => ({ id: row.dataset.id, prefix: row.querySelector('[name="prefix"]').value, padding: row.querySelector('[name="padding"]').value, reset_period: row.querySelector('[name="reset_period"]').value })));

    const usersTable = document.getElementById('usersTable');
    const userForm = document.getElementById('systemUserForm');
    const userModalElement = document.getElementById('userModal');
    const openUserModal = (record = null) => {
        if (!userForm || !userModalElement) return;
        userForm.reset();
        userForm.elements.user_id.value = record?.id || '';
        userForm.elements.full_name.value = record?.full_name || '';
        userForm.elements.username.value = record?.username || '';
        userForm.elements.email.value = record?.email || '';
        userForm.elements.phone.value = record?.phone || '';
        userForm.elements.status.value = record?.status || 'ACTIVE';
        userForm.elements.locale.value = record?.locale || 'en';
        userForm.elements.branch_id.value = record?.branch_id || '';
        userForm.elements.default_warehouse_id.value = record?.default_warehouse_id || '';
        userForm.elements.force_password_change.checked = record ? Number(record.force_password_change) === 1 : true;
        userForm.elements.password.required = !record;
        const selectedRoles = new Set((record?.roles || []).map((role) => String(role.id)));
        userForm.querySelectorAll('[name="role_ids"]').forEach((checkbox) => { checkbox.checked = selectedRoles.has(checkbox.value); });
        userForm.querySelector('[data-role-error]')?.classList.add('d-none');
        userModalElement.querySelector('.modal-title').textContent = record ? 'Edit user' : 'New user';
        userForm.querySelector('[type="submit"]').textContent = record ? 'Save changes' : 'Create user';
        window.bootstrap.Modal.getOrCreateInstance(userModalElement).show();
    };
    document.querySelector('[data-new-user]')?.addEventListener('click', () => openUserModal());
    usersTable?.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.format !== 'actions') return;
        event.preventDefault();
        const { record, cell } = event.detail;
        const group = document.createElement('div'); group.className = 'btn-group btn-group-sm';
        group.append(makeButton('Edit', 'primary', async () => {
            try { const payload = await api.get(`system/users/${record.id}`); openUserModal(payload.data); }
            catch (error) { notify(error.message, 'danger'); }
        }));
        if (String(record.id) !== usersTable.dataset.currentUserId) group.append(makeButton('Archive', 'danger', async () => {
            if (!await confirmAction(`Move ${record.full_name} to the recycle bin?`, { danger: true, confirmLabel: 'Archive user' })) return;
            try { await api.delete(`system/users/${record.id}`); notify('User moved to the recycle bin.', 'success'); tableController(tables, usersTable)?.load(); }
            catch (error) { notify(error.message, 'danger'); }
        }));
        cell.append(group);
    });
    userForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const roleIds = [...userForm.querySelectorAll('[name="role_ids"]:checked')].map((input) => Number(input.value));
        const roleError = userForm.querySelector('[data-role-error]');
        roleError?.classList.toggle('d-none', roleIds.length > 0);
        if (!userForm.reportValidity() || roleIds.length === 0) return;
        const data = Object.fromEntries(new FormData(userForm).entries());
        const id = data.user_id; delete data.user_id; delete data.role_ids;
        data.role_ids = roleIds;
        data.force_password_change = userForm.elements.force_password_change.checked;
        data.branch_id ||= null; data.default_warehouse_id ||= null;
        if (!data.password) delete data.password;
        const submit = userForm.querySelector('[type="submit"]'); submit.disabled = true;
        try {
            const result = id ? await api.put(`system/users/${id}`, data) : await api.post('system/users', data);
            notify(result.message, 'success'); window.bootstrap.Modal.getInstance(userModalElement)?.hide(); tableController(tables, usersTable)?.load();
        } catch (error) { notify(error.message, 'danger'); }
        finally { submit.disabled = false; }
    });

    const rolesTable = document.getElementById('rolesTable');
    const roleForm = document.getElementById('systemRoleForm');
    const roleModalElement = document.getElementById('roleModal');
    const openRoleModal = (record = null) => {
        if (!roleForm || !roleModalElement) return;
        roleForm.reset();
        roleForm.elements.role_id.value = record?.id || '';
        roleForm.elements.name.value = record?.name || '';
        roleForm.elements.description.value = record?.description || '';
        roleForm.elements.is_active.checked = record ? Number(record.is_active) === 1 : true;
        const selected = new Set((record?.permission_ids || []).map(String));
        roleForm.querySelectorAll('[name="permission_ids"]').forEach((checkbox) => { checkbox.checked = selected.has(checkbox.value); });
        roleModalElement.querySelector('.modal-title').textContent = record ? 'Edit role' : 'New role';
        roleForm.querySelector('[type="submit"]').textContent = record ? 'Save changes' : 'Create role';
        window.bootstrap.Modal.getOrCreateInstance(roleModalElement).show();
    };
    document.querySelector('[data-new-role]')?.addEventListener('click', () => openRoleModal());
    rolesTable?.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.format !== 'actions') return;
        event.preventDefault();
        const { record, cell } = event.detail;
        if (Number(record.is_system) === 1) {
            const badge = document.createElement('span'); badge.className = 'status-badge status-info'; badge.textContent = 'Protected'; cell.append(badge); return;
        }
        cell.append(makeButton('Edit', 'primary', async () => {
            try { const payload = await api.get(`system/roles/${record.id}`); openRoleModal(payload.data); }
            catch (error) { notify(error.message, 'danger'); }
        }));
    });
    document.querySelector('[data-toggle-permissions]')?.addEventListener('click', (event) => {
        const checkboxes = [...roleForm.querySelectorAll('[name="permission_ids"]')];
        const select = checkboxes.some((checkbox) => !checkbox.checked);
        checkboxes.forEach((checkbox) => { checkbox.checked = select; });
        event.currentTarget.textContent = select ? 'Clear all' : 'Select all';
    });
    roleForm?.addEventListener('submit', async (event) => {
        event.preventDefault(); if (!roleForm.reportValidity()) return;
        const data = Object.fromEntries(new FormData(roleForm).entries());
        const id = data.role_id; delete data.role_id; delete data.permission_ids;
        data.permission_ids = [...roleForm.querySelectorAll('[name="permission_ids"]:checked')].map((input) => Number(input.value));
        data.is_active = roleForm.elements.is_active.checked;
        const submit = roleForm.querySelector('[type="submit"]'); submit.disabled = true;
        try {
            const result = id ? await api.put(`system/roles/${id}`, data) : await api.post('system/roles', data);
            notify(result.message, 'success'); window.bootstrap.Modal.getInstance(roleModalElement)?.hide(); tableController(tables, rolesTable)?.load();
        } catch (error) { notify(error.message, 'danger'); }
        finally { submit.disabled = false; }
    });

    const backupTable = document.getElementById('backupTable');
    document.querySelector('[data-backup-create]')?.addEventListener('click', async (event) => {
        event.currentTarget.disabled = true; try { await api.post('system/backups', {}); notify('Backup created.', 'success'); tableController(tables, backupTable)?.load(); } catch (error) { notify(error.message, 'danger'); } finally { event.currentTarget.disabled = false; }
    });
    backupTable?.addEventListener('karoor:table-cell', (event) => {
        if (event.detail.column.format !== 'actions') return; event.preventDefault(); const { record, cell } = event.detail; const group = document.createElement('div'); group.className = 'btn-group btn-group-sm';
        if (record.status === 'COMPLETED') { const link = document.createElement('a'); link.className = 'btn btn-outline-primary'; link.textContent = 'Download'; link.href = `${backupTable.dataset.downloadBase}/${record.id}/download`; group.append(link); }
        group.append(makeButton('Delete', 'danger', async () => { if (!await confirmAction(`Delete backup ${record.filename}?`, { danger: true })) return; try { await api.delete(`system/backups/${record.id}`); notify('Backup deleted.', 'success'); tableController(tables, backupTable)?.load(); } catch (error) { notify(error.message, 'danger'); } })); cell.append(group);
    });
}

export function initPages(context) {
    initDashboard(context);
    initSales(context);
    initPurchases(context);
    initInventory(context);
    initContacts(context);
    initFinance(context);
    initExpenses(context);
    initHrm(context);
    initReports(context);
    initRecycleBin(context);
    initSettings(context);
}
