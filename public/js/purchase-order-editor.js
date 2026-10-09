(() => {
    if (window.__purchaseOrderEditorBootAll) {
        window.__purchaseOrderEditorBootAll();

        return;
    }

    const boot = (root) => {
        if (!root || root.dataset.initialized === 'true') {
            return;
        }

        root.dataset.initialized = 'true';

        const body = root.querySelector('[data-items-body]');
        const template = root.querySelector('[data-item-row-template]');
        const itemDialog = root.querySelector('[data-item-dialog]');
        const unitDialog = root.querySelector('[data-unit-dialog]');
        const headerPickerDialog = root.querySelector('[data-header-picker-dialog]');
        const headerPickerSearch = root.querySelector('[data-header-picker-search]');
        const headerPickerList = root.querySelector('[data-header-picker-list]');
        const headerPickerEmpty = root.querySelector('[data-header-picker-empty]');
        const headerPickerCount = root.querySelector('[data-header-picker-count]');
        const headerPickerTitle = root.querySelector('[data-header-picker-title]');
        const headerPickerDescription = root.querySelector('[data-header-picker-description]');
        const headerPickerData = JSON.parse(root.querySelector('[data-header-picker-options]')?.textContent || '{}');
        const itemSearch = root.querySelector('[data-item-search]');
        const itemResults = root.querySelector('[data-item-results]');
        const itemEmpty = root.querySelector('[data-item-empty]');
        const itemPageLabel = root.querySelector('[data-item-page-label]');
        const itemPrev = root.querySelector('[data-item-prev]');
        const itemNext = root.querySelector('[data-item-next]');
        const unitSearch = root.querySelector('[data-unit-search]');
        const currency = root.querySelector('[data-currency]');
        const additionTax = root.querySelector('[data-addition-tax]');
        const deductionTax = root.querySelector('[data-deduction-tax]');
        const discount = root.querySelector('[data-discount]');
        const shipping = root.querySelector('[data-shipping]');
        const poDate = root.querySelector('[data-po-date]');
        const deliveryDate = root.querySelector('[data-delivery-date]');
        let activeRow = null;
        let activeHeaderPicker = null;
        let currentItemPage = 1;
        let lastItemPage = 1;
        let itemRequest = null;
        let searchTimer = null;
        let dirty = false;

        const rows = () => Array.from(body.querySelectorAll('[data-po-item-row]'));

        const parseNumber = (value) => {
            const parsed = Number.parseFloat(String(value ?? '').replace(',', '.'));

            return Number.isFinite(parsed) ? parsed : 0;
        };

        const rate = (select) => parseNumber(select?.selectedOptions?.[0]?.dataset?.rate);

        const formatMoney = (amount) => {
            const code = currency?.value || 'IDR';
            const locale = code === 'IDR' ? 'id-ID' : 'en-US';
            const decimals = code === 'IDR' ? 0 : 2;

            return `${code === 'IDR' ? 'Rp' : code} ${new Intl.NumberFormat(locale, {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            }).format(amount)}`;
        };

        const updateCurrencyPrefixes = () => {
            const prefix = currency?.value === 'IDR' ? 'Rp' : (currency?.value || 'IDR');

            root.querySelectorAll('[data-currency-prefix]').forEach((element) => {
                element.textContent = prefix;
            });
        };

        const calculate = () => {
            const additionRate = rate(additionTax);
            const deductionRate = rate(deductionTax);
            let subtotal = 0;

            rows().forEach((row) => {
                const quantity = parseNumber(row.querySelector('[data-field="quantity"]')?.value);
                const unitPrice = parseNumber(row.querySelector('[data-field="unit_price_amount"]')?.value);
                const lineSubtotal = quantity * unitPrice;
                const lineTax = lineSubtotal * ((additionRate - deductionRate) / 100);
                subtotal += lineSubtotal;
                row.querySelector('[data-line-subtotal]').textContent = formatMoney(lineSubtotal);
                row.querySelector('[data-line-tax]').textContent = formatMoney(lineTax);
            });

            const addition = subtotal * (additionRate / 100);
            const deduction = subtotal * (deductionRate / 100);
            const discountAmount = parseNumber(discount?.value);
            const shippingAmount = parseNumber(shipping?.value);
            const total = subtotal + addition - deduction - discountAmount + shippingAmount;

            root.querySelector('[data-summary-subtotal]').textContent = formatMoney(subtotal);
            root.querySelector('[data-summary-addition]').textContent = formatMoney(addition);
            root.querySelector('[data-summary-deduction]').textContent = formatMoney(deduction);
            root.querySelector('[data-summary-discount]').textContent = formatMoney(discountAmount);
            root.querySelector('[data-summary-shipping]').textContent = formatMoney(shippingAmount);
            root.querySelector('[data-summary-total]').textContent = formatMoney(total);
            updateCurrencyPrefixes();
        };

        const reindex = () => {
            rows().forEach((row, index) => {
                row.querySelectorAll('[name]').forEach((field) => {
                    field.name = field.name.replace(/items\[[^\]]+\]/, `items[${index}]`);
                });
            });
        };

        const markDirty = () => {
            dirty = true;
        };

        const openDialog = (dialog) => {
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
            }
        };

        const closeDialog = (dialog) => {
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        };

        const headerPickerConfig = {
            supplier: {
                title: 'Pilih Vendor',
                description: 'Cari berdasarkan kode atau nama vendor.',
                placeholder: 'Cari vendor...',
                emptyLabel: 'Pilih vendor',
            },
            pic: {
                title: 'Pilih PIC',
                description: 'Cari berdasarkan nama pengguna.',
                placeholder: 'Cari PIC...',
                emptyLabel: 'Pilih PIC',
            },
            warehouse: {
                title: 'Pilih Gudang',
                description: 'Cari berdasarkan kode atau nama gudang.',
                placeholder: 'Cari gudang...',
                emptyLabel: 'Pilih gudang',
                allowEmpty: true,
            },
        };

        const headerPickerSelectionLabel = (type, choice) => {
            if (!choice?.id) {
                return headerPickerConfig[type].emptyLabel;
            }

            return type === 'warehouse' && choice.meta
                ? `${choice.label} (${choice.meta})`
                : choice.label;
        };

        const chooseHeaderValue = (type, choice) => {
            root.querySelector(`[data-header-picker-value="${type}"]`).value = choice.id;
            root.querySelector(`[data-header-picker-label="${type}"]`).textContent = headerPickerSelectionLabel(type, choice);
            markDirty();
            closeDialog(headerPickerDialog);
        };

        const renderHeaderPicker = () => {
            const config = headerPickerConfig[activeHeaderPicker];
            const query = headerPickerSearch.value.trim().toLocaleLowerCase('id');
            const choices = [...(headerPickerData[activeHeaderPicker] || [])];

            if (config.allowEmpty) {
                choices.unshift({ id: '', label: 'Tanpa gudang', meta: 'Kosongkan pilihan' });
            }

            const filtered = choices.filter((choice) => `${choice.label} ${choice.meta || ''}`
                .toLocaleLowerCase('id')
                .includes(query));

            headerPickerList.replaceChildren();
            headerPickerEmpty.hidden = filtered.length > 0;
            headerPickerCount.textContent = `${filtered.length} pilihan`;

            filtered.forEach((choice) => {
                const button = document.createElement('button');
                const label = document.createElement('strong');
                const meta = document.createElement('span');

                button.type = 'button';
                button.dataset.headerPickerChoice = '';
                label.textContent = choice.label;
                meta.textContent = choice.meta || '';
                button.append(label, meta);
                button.addEventListener('click', () => chooseHeaderValue(activeHeaderPicker, choice));
                headerPickerList.append(button);
            });
        };

        const openHeaderPicker = (type) => {
            const config = headerPickerConfig[type];

            if (!config) {
                return;
            }

            activeHeaderPicker = type;
            headerPickerTitle.textContent = config.title;
            headerPickerDescription.textContent = config.description;
            headerPickerSearch.placeholder = config.placeholder;
            headerPickerSearch.value = '';
            renderHeaderPicker();
            openDialog(headerPickerDialog);
            window.setTimeout(() => headerPickerSearch.focus(), 0);
        };

        const renderItems = (payload) => {
            itemResults.replaceChildren();
            itemEmpty.hidden = payload.data.length > 0;
            currentItemPage = payload.meta.current_page;
            lastItemPage = payload.meta.last_page;
            itemPageLabel.textContent = `Halaman ${currentItemPage} dari ${lastItemPage} · ${payload.meta.total} barang`;
            itemPrev.disabled = currentItemPage <= 1;
            itemNext.disabled = currentItemPage >= lastItemPage;

            payload.data.forEach((item) => {
                const row = document.createElement('tr');
                const code = document.createElement('td');
                const name = document.createElement('td');
                const unit = document.createElement('td');
                const action = document.createElement('td');
                const choose = document.createElement('button');

                code.textContent = item.code || '—';
                name.textContent = item.name;
                unit.textContent = item.unit_name || '—';
                choose.type = 'button';
                choose.className = 'po-editor__choose-button';
                choose.textContent = 'Pilih';
                choose.addEventListener('click', () => {
                    if (!activeRow) {
                        return;
                    }

                    activeRow.querySelector('[data-field="item_id"]').value = item.id;
                    activeRow.querySelector('[data-item-label]').textContent = `${item.code || ''}${item.code ? ' - ' : ''}${item.name}`;
                    activeRow.querySelector('[data-field="specification"]').value = item.specification || '';
                    activeRow.querySelector('[data-field="unit_id"]').value = item.unit_id || '';
                    activeRow.querySelector('[data-unit-label]').textContent = item.unit_name || 'Pilih satuan';
                    markDirty();
                    closeDialog(itemDialog);
                    calculate();
                });
                action.append(choose);
                row.append(code, name, unit, action);
                itemResults.append(row);
            });
        };

        const loadItems = async (page = 1) => {
            itemRequest?.abort();
            itemRequest = new AbortController();
            const url = new URL(root.dataset.itemSearchUrl, window.location.origin);
            url.searchParams.set('q', itemSearch.value.trim());
            url.searchParams.set('page', String(page));
            itemResults.innerHTML = '<tr><td colspan="4">Memuat barang...</td></tr>';

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: itemRequest.signal,
                });

                if (!response.ok) {
                    throw new Error('Item request failed');
                }

                renderItems(await response.json());
            } catch (error) {
                if (error.name !== 'AbortError') {
                    itemResults.innerHTML = '<tr><td colspan="4">Daftar barang gagal dimuat. Silakan coba lagi.</td></tr>';
                }
            }
        };

        root.addEventListener('click', (event) => {
            const itemButton = event.target.closest('[data-open-item-picker]');
            const unitButton = event.target.closest('[data-open-unit-picker]');
            const removeButton = event.target.closest('[data-remove-row]');
            const moveButton = event.target.closest('[data-move]');
            const headerPickerButton = event.target.closest('[data-open-header-picker]');

            if (headerPickerButton) {
                openHeaderPicker(headerPickerButton.dataset.openHeaderPicker);
            }

            if (itemButton) {
                activeRow = itemButton.closest('[data-po-item-row]');
                itemSearch.value = '';
                openDialog(itemDialog);
                loadItems(1);
                window.setTimeout(() => itemSearch.focus(), 0);
            }

            if (unitButton) {
                activeRow = unitButton.closest('[data-po-item-row]');
                unitSearch.value = '';
                root.querySelectorAll('[data-unit-choice]').forEach((choice) => {
                    choice.hidden = false;
                });
                openDialog(unitDialog);
                window.setTimeout(() => unitSearch.focus(), 0);
            }

            if (removeButton) {
                const row = removeButton.closest('[data-po-item-row]');
                if (rows().length === 1) {
                    row.querySelectorAll('input').forEach((input) => {
                        if (input.dataset.field === 'quantity') input.value = '1';
                        else if (input.dataset.field === 'unit_price_amount') input.value = '';
                        else input.value = '';
                    });
                    row.querySelector('[data-item-label]').textContent = 'Pilih barang';
                    row.querySelector('[data-unit-label]').textContent = 'Pilih satuan';
                } else {
                    row.remove();
                    reindex();
                }
                markDirty();
                calculate();
            }

            if (moveButton) {
                const row = moveButton.closest('[data-po-item-row]');
                if (moveButton.dataset.move === 'up' && row.previousElementSibling) {
                    body.insertBefore(row, row.previousElementSibling);
                }
                if (moveButton.dataset.move === 'down' && row.nextElementSibling) {
                    body.insertBefore(row.nextElementSibling, row);
                }
                reindex();
                markDirty();
            }

            if (event.target.closest('[data-add-row]')) {
                const fragment = template.content.cloneNode(true);
                body.append(fragment);
                reindex();
                markDirty();
                calculate();
            }

            const closeButton = event.target.closest('[data-close-dialog]');
            if (closeButton) {
                closeDialog(closeButton.closest('dialog'));
            }

            const unitChoice = event.target.closest('[data-unit-choice]');
            if (unitChoice && activeRow) {
                activeRow.querySelector('[data-field="unit_id"]').value = unitChoice.dataset.unitId;
                activeRow.querySelector('[data-unit-label]').textContent = unitChoice.dataset.unitName;
                markDirty();
                closeDialog(unitDialog);
            }
        });

        root.addEventListener('input', (event) => {
            if (event.target.matches('[data-field="quantity"], [data-field="unit_price_amount"], [data-discount], [data-shipping]')) {
                calculate();
            }

            if (event.target.matches('input, textarea, select')) {
                markDirty();
            }
        });

        root.addEventListener('change', (event) => {
            if (event.target.matches('[data-currency], [data-addition-tax], [data-deduction-tax]')) {
                calculate();
            }
            markDirty();
        });

        itemSearch.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(() => loadItems(1), 250);
        });
        itemPrev.addEventListener('click', () => loadItems(Math.max(1, currentItemPage - 1)));
        itemNext.addEventListener('click', () => loadItems(Math.min(lastItemPage, currentItemPage + 1)));

        unitSearch.addEventListener('input', () => {
            const query = unitSearch.value.trim().toLocaleLowerCase('id');
            let visible = 0;

            root.querySelectorAll('[data-unit-choice]').forEach((choice) => {
                const matches = choice.textContent.toLocaleLowerCase('id').includes(query);
                choice.hidden = !matches;
                if (matches) visible += 1;
            });
            root.querySelector('[data-unit-empty]').hidden = visible > 0;
        });

        headerPickerSearch.addEventListener('input', renderHeaderPicker);

        poDate?.addEventListener('change', async () => {
            if (!deliveryDate.value) {
                deliveryDate.value = poDate.value;
            }

            const url = new URL(root.dataset.numberPreviewUrl, window.location.origin);
            url.searchParams.set('date', poDate.value);

            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (response.ok) {
                    const preview = await response.json();
                    root.querySelector('[data-draft-number]').value = preview.po_number;
                }
            } catch (_) {
                // Nomor final tetap dihitung ulang oleh server saat simpan.
            }
        });

        root.addEventListener('submit', () => {
            dirty = false;
            reindex();
        });
        const warnBeforeUnload = (event) => {
            if (!dirty) return;
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warnBeforeUnload);
        document.addEventListener('livewire:navigating', () => {
            window.removeEventListener('beforeunload', warnBeforeUnload);
            itemRequest?.abort();
        }, { once: true });

        reindex();
        calculate();
    };

    const bootAll = () => document.querySelectorAll('[data-po-editor]').forEach(boot);
    window.__purchaseOrderEditorBootAll = bootAll;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootAll, { once: true });
    } else {
        bootAll();
    }
    document.addEventListener('livewire:navigated', bootAll);
})();
