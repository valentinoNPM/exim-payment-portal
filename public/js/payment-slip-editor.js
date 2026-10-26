(() => {
    const root = document.querySelector('[data-payment-slip-editor]');
    if (!root) return;
    const general = root.dataset.type === 'general';

    const form = root.querySelector('form');
    const invoiceContainer = root.querySelector('[data-ps-invoices]');
    const invoiceTemplate = root.querySelector('[data-ps-invoice-template]');
    const itemTemplate = root.querySelector('[data-ps-item-template]');
    const previewFrame = root.querySelector('[data-ps-preview-frame]');
    const previewEmpty = root.querySelector('[data-ps-preview-empty]');
    const masterDialog = document.querySelector('[data-ps-master-dialog]');
    const masterForm = masterDialog?.querySelector('[data-ps-master-form]');
    let masterKind = null;
    let masterTarget = null;
    let nextKey = 0;
    let temporaryPreviewUrl = null;
    const picker = document.querySelector('[data-ps-picker]');
    const pickerQuery = picker?.querySelector('[data-ps-picker-query]');
    const pickerResults = picker?.querySelector('[data-ps-picker-results]');
    let pickerTarget = null;
    const selectButtons = new WeakMap();
    const optionLists = new WeakMap();
    let fieldSequence = 0;
    let extracting = false;
    const toolbar = root.querySelector('.ps-editor__toolbar');
    const toolbarObserver = new ResizeObserver(() => {
        root.style.setProperty('--ps-tools-height', `${toolbar.offsetHeight}px`);
    });
    toolbarObserver.observe(toolbar);
    root.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const panel = root.querySelector('#ps-extraction-panel');
        if (panel && !panel.hidden && panel.contains(event.target)) {
            panel.hidden = true;
            const trigger = root.querySelector('[aria-controls="ps-extraction-panel"]');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.focus();
        }
    });

    function enhanceFields(container) {
        container.querySelectorAll('.ps-editor__field').forEach(field => {
            const input = field.querySelector('input, select');
            const label = field.querySelector('label');
            if (input && label) {
                input.id ||= `ps-field-${++fieldSequence}`;
                label.htmlFor = input.id;
            }
        });
        container.querySelectorAll('select:not([data-ps-currency]):not([data-ps-tax-mode]):not([data-ps-type])').forEach(select => {
            if (selectButtons.has(select)) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ps-editor__select-trigger';
            button.setAttribute('aria-haspopup', 'dialog');
            select.after(button);
            select.hidden = true;
            selectButtons.set(select, button);
            optionLists.set(select, [...select.options]);
            setSelectValue(select, select.value);
            const label = select.closest('.ps-editor__field')?.querySelector('label');
            button.id = `${select.id}-picker`;
            if (label) label.htmlFor = button.id;
            button.addEventListener('click', () => {
                pickerTarget = select;
                picker.querySelector('h2').textContent = `Pilih ${label?.textContent.replace('*', '').trim() || 'data'}`;
                pickerQuery.value = '';
                renderOptions();
                picker.showModal();
                pickerQuery.focus();
            });
            syncSelect(select);
        });
    }

    function syncSelect(select) {
        const button = selectButtons.get(select);
        if (!button) return;
        button.textContent = select.selectedOptions[0]?.textContent || 'Pilih…';
        button.classList.toggle('ps-editor__select-trigger--empty', !select.value);
        button.disabled = select.disabled;
    }

    function setSelectValue(select, value) {
        const options = optionLists.get(select);
        if (!options) { select.value = String(value); return; }
        const selected = options.find(option => option.value === String(value));
        const empty = options.find(option => option.value === '');
        // Keep only the submitted selection in the DOM, not a copy of every master row per item.
        select.replaceChildren(...[empty, selected].filter((option, index, array) => option && array.indexOf(option) === index).map(option => option.cloneNode(true)));
        select.value = String(value);
        syncSelect(select);
    }

    function renderOptions() {
        const query = pickerQuery.value.trim().toLocaleLowerCase();
        const matches = optionLists.get(pickerTarget).filter(option => !option.disabled && option.textContent.toLocaleLowerCase().includes(query));
        pickerResults.replaceChildren();
        matches.slice(0, 60).forEach(option => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = option.textContent;
            button.className = 'ps-editor__picker-option';
            button.setAttribute('aria-pressed', String(option.value === pickerTarget.value));
            button.addEventListener('click', () => {
                setSelectValue(pickerTarget, option.value);
                pickerTarget.dispatchEvent(new Event('change', { bubbles: true }));
                picker.close();
            });
            pickerResults.append(button);
        });
        picker.querySelector('[data-ps-picker-status]').textContent = matches.length
            ? `${Math.min(matches.length, 60)} dari ${matches.length} pilihan${matches.length > 60 ? ' — persempit pencarian untuk hasil lainnya.' : '.'}`
            : 'Tidak ada pilihan yang cocok.';
    }
    pickerQuery?.addEventListener('input', renderOptions);
    picker?.querySelector('[data-ps-picker-close]').addEventListener('click', () => picker.close());
    picker?.addEventListener('close', () => selectButtons.get(pickerTarget)?.focus());
    picker?.addEventListener('keydown', event => {
        const buttons = [...pickerResults.querySelectorAll('button')];
        const index = buttons.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            buttons[(index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length]?.focus();
        } else if (event.key === 'Enter' && event.target === pickerQuery) {
            event.preventDefault(); buttons[0]?.click();
        }
    });

    const key = () => `new${++nextKey}`;
    const invoices = () => [...invoiceContainer.querySelectorAll('[data-ps-invoice]')];
    const items = invoice => [...invoice.querySelectorAll('[data-ps-item]')];
    const currency = () => root.querySelector('[data-ps-currency]')?.value || root.dataset.currency || 'IDR';
    const money = value => {
        const code = currency();
        const formatted = new Intl.NumberFormat(code === 'IDR' ? 'id-ID' : 'en-US', {
            minimumFractionDigits: code === 'IDR' ? 0 : 2,
            maximumFractionDigits: code === 'IDR' ? 0 : 2,
        }).format(value || 0);
        return `${code === 'IDR' ? 'Rp' : code} ${formatted}`;
    };
    const numeric = field => Number.parseFloat(field?.value || '0') || 0;
    const selectedRate = field => Number.parseFloat(field?.selectedOptions[0]?.dataset.rate || '0') || 0;
    const roundTo = (value, factor) => Math.sign(value) * Math.round((Math.abs(value) + Number.EPSILON) * factor) / factor;
    const roundAmount = value => roundTo(value, currency() === 'IDR' ? 1 : 100);

    function updateLabels() {
        invoices().forEach((invoice, invoiceIndex) => {
            invoice.querySelector('[data-ps-invoice-number]').textContent = invoiceIndex + 1;
            invoice.querySelector('[data-ps-add-item-invoice-number]').textContent = invoiceIndex + 1;
            items(invoice).forEach((item, itemIndex) => {
                item.querySelector('[data-ps-item-number]').textContent = `Item ${itemIndex + 1}`;
                item.querySelector('[data-ps-remove-item]').disabled = items(invoice).length === 1;
            });
            invoice.querySelector('[data-ps-remove-invoice]').disabled = invoices().length === 1;
        });
    }

    function syncMode(invoice) {
        const legacy = invoice.querySelector('[data-ps-tax-mode]')?.value === 'invoice_legacy';
        invoice.querySelector('[data-ps-invoice-taxes]').hidden = !legacy;
        invoice.querySelectorAll('[data-ps-invoice-tax]').forEach(select => { select.disabled = !legacy; });
        invoice.querySelectorAll('[data-ps-item-tax]').forEach(field => { field.hidden = legacy; });
        invoice.querySelectorAll('[data-ps-item-tax-select]').forEach(select => { select.disabled = legacy; });
        invoice.querySelectorAll('select').forEach(syncSelect);
    }

    function recalculate() {
        const type = root.querySelector('[data-ps-type]')?.value || root.dataset.type;
        let slipTotal = 0;
        const currentInvoices = invoices();
        const overview = root.querySelector('[data-ps-overview]');
        const overviewTotal = overview.querySelector('[data-ps-overview-total]');
        const overviewRows = [...overview.querySelectorAll('[data-ps-overview-invoice]')];
        while (overviewRows.length > currentInvoices.length) overviewRows.pop().remove();
        currentInvoices.forEach((invoice, index) => {
            let overviewRow = overviewRows[index];
            if (!overviewRow) {
                overviewRow = document.createElement('div');
                overviewRow.className = 'payment-slip-detail__overview-invoice';
                overviewRow.setAttribute('data-ps-overview-invoice', '');
                const label = document.createElement('dt');
                const title = document.createElement('span');
                title.dataset.psOverviewTitle = '';
                const details = document.createElement('span');
                details.className = 'ps-editor__overview-details';
                details.dataset.psOverviewDetails = '';
                label.append(title, details);
                const amount = document.createElement('dd');
                amount.className = 'payment-slip-detail__money';
                overviewRow.append(label, amount);
                overview.insertBefore(overviewRow, overviewTotal);
            }
            overviewRow.querySelector('[data-ps-overview-title]').textContent = `Invoice ${index + 1}`;
            const invoiceNumber = invoice.querySelector('[name$="[invoice_number]"]')?.value.trim();
            const buyer = invoice.querySelector('[data-ps-master-select="buyer"]') || root.querySelector('#ps-buyer');
            const buyerName = buyer?.value ? buyer.selectedOptions[0]?.textContent.trim() : 'Belum dipilih';
            overviewRow.querySelector('[data-ps-overview-details]').textContent = `No. Invoice: ${invoiceNumber || 'Belum diisi'}${buyer ? `\nBuyer: ${buyerName}` : ''}`;
            syncMode(invoice);
            const legacy = invoice.querySelector('[data-ps-tax-mode]')?.value === 'invoice_legacy';
            let subtotal = 0;
            let addition = 0;
            let deduction = 0;
            items(invoice).forEach(item => {
                const rawLine = numeric(item.querySelector('[data-ps-quantity]')) * numeric(item.querySelector('[data-ps-price]'));
                const line = root.querySelector('[data-ps-currency]') ? roundAmount(rawLine) : roundTo(rawLine, 100);
                subtotal += line;
                const ppn = item.querySelector('[data-ps-item-tax-select="addition"]');
                const pph = item.querySelector('[data-ps-item-tax-select="deduction"]');
                const itemAddition = legacy ? 0 : ppn.value ? roundAmount(line * selectedRate(ppn) / 100) : Number(item.dataset.psAddition || 0);
                const itemDeduction = legacy ? 0 : pph.value ? roundAmount(line * selectedRate(pph) / 100) : Number(item.dataset.psDeduction || 0);
                addition += itemAddition;
                deduction += itemDeduction;
                item.querySelector('[data-ps-item-addition]').textContent = `PPN Nominal: ${money(itemAddition)}`;
                item.querySelector('[data-ps-item-deduction]').textContent = `PPh Nominal: ${money(itemDeduction)}`;
                item.querySelector('[data-ps-item-total]').textContent = money(line + itemAddition - itemDeduction);
            });
            subtotal = Math.round((subtotal + Number.EPSILON) * 100) / 100;
            if (legacy) {
                addition = roundAmount(subtotal * selectedRate(invoice.querySelector('[data-ps-invoice-tax="addition"]')) / 100);
                deduction = roundAmount(subtotal * selectedRate(invoice.querySelector('[data-ps-invoice-tax="deduction"]')) / 100);
            }
            const discount = general ? roundAmount(Number(invoice.querySelector('[data-ps-adjustment="discount_amount"]')?.value || 0)) : 0;
            const shipping = general ? roundAmount(Number(invoice.querySelector('[data-ps-adjustment="shipping_amount"]')?.value || 0)) : 0;
            const total = (legacy ? roundAmount(subtotal) : subtotal) + addition - deduction - discount + shipping;
            invoice.querySelector('[data-ps-invoice-subtotal]').textContent = money(subtotal);
            invoice.querySelector('[data-ps-invoice-addition]').textContent = money(addition);
            invoice.querySelector('[data-ps-invoice-deduction]').textContent = money(deduction);
            invoice.querySelector('[data-ps-invoice-total]').textContent = money(total);
            overviewRow.querySelector('dd').textContent = money(total);
            slipTotal += total;
        });
        root.querySelector('[data-ps-total]').textContent = money(slipTotal);
    }

    function addInvoice(focus = true) {
        const invoiceKey = key();
        invoiceContainer.insertAdjacentHTML('beforeend', invoiceTemplate.innerHTML.replaceAll('__INVOICE__', invoiceKey));
        const invoice = invoiceContainer.lastElementChild;
        enhanceFields(invoice);
        invoice.querySelectorAll('[data-ps-tax-mode]').forEach(select => { select.dataset.previous = select.value; });
        updateLabels();
        recalculate();
        if (focus) {
            invoice.querySelector('input[name$="[invoice_number]"]')?.focus();
            invoice.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        return invoice;
    }

    function addItem(invoice, focus = true) {
        const itemKey = key();
        const invoiceKey = invoice.dataset.psInvoiceKey;
        invoice.querySelector('[data-ps-items]').insertAdjacentHTML('beforeend', itemTemplate.innerHTML.replaceAll('__INVOICE__', invoiceKey).replaceAll('__ITEM__', itemKey));
        enhanceFields(items(invoice).at(-1));
        updateLabels();
        recalculate();
        const newItem = items(invoice).at(-1);
        if (focus) {
            newItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            newItem.querySelector('input[name$="[item_name]"]')?.focus({ preventScroll: true });
        }
        return newItem;
    }

    function showPreview(url) {
        if (temporaryPreviewUrl && temporaryPreviewUrl !== url) URL.revokeObjectURL(temporaryPreviewUrl);
        temporaryPreviewUrl = url?.startsWith('blob:') ? url : null;
        previewFrame.src = url;
        previewFrame.hidden = !url;
        previewEmpty.hidden = !!url;
        setView(url ? 'split' : 'form');
    }

    function setView(mode) {
        root.dataset.view = mode;
        form.hidden = mode === 'pdf';
        root.querySelector('.ps-editor__viewer').hidden = mode === 'form';
        root.querySelector('[data-ps-divider]').hidden = mode !== 'split';
        root.classList.toggle('ps-editor__workspace--preview', mode === 'split');
        root.querySelectorAll('[data-ps-view]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.psView === mode)));
    }

    const divider = root.querySelector('[data-ps-divider]');
    function resizeSplit(value) {
        const percent = Math.max(35, Math.min(65, value));
        root.style.setProperty('--ps-form-share', `${percent}fr`);
        root.style.setProperty('--ps-form-percent', `${percent}%`);
        root.style.setProperty('--ps-pdf-share', `${100 - percent}fr`);
        divider.setAttribute('aria-valuenow', String(Math.round(percent)));
    }
    divider.addEventListener('pointerdown', event => {
        divider.setPointerCapture(event.pointerId);
        root.classList.add('ps-editor__resizing');
    });
    divider.addEventListener('pointermove', event => {
        if (!divider.hasPointerCapture(event.pointerId)) return;
        const bounds = root.getBoundingClientRect();
        resizeSplit((event.clientX - bounds.left) / bounds.width * 100);
    });
    divider.addEventListener('lostpointercapture', () => root.classList.remove('ps-editor__resizing'));
    divider.addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        resizeSplit(event.key === 'Home' ? 35 : event.key === 'End' ? 65 : Number(divider.getAttribute('aria-valuenow')) + (event.key === 'ArrowLeft' ? -2 : 2));
    });

    function renderFiles() {
        const input = root.querySelector('[data-ps-extract-files]');
        const list = root.querySelector('[data-ps-extract-list]');
        list.replaceChildren();
        [...input.files].forEach((file, index) => {
            const entry = document.createElement('li');
            const name = document.createElement('span');
            name.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(1)} MB)`;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ps-editor__button ps-editor__button--remove';
            remove.textContent = 'Hapus';
            remove.setAttribute('aria-label', `Hapus file ${file.name}`);
            remove.disabled = extracting;
            remove.addEventListener('click', () => {
                if (extracting) return;
                const selected = new DataTransfer();
                [...input.files].forEach((candidate, offset) => { if (offset !== index) selected.items.add(candidate); });
                input.files = selected.files;
                renderFiles();
            });
            entry.append(name, remove);
            list.append(entry);
        });
        list.hidden = !input.files.length;
    }

    function uploadForExtraction(url, body, progress, report) {
        return new Promise((resolve, reject) => {
            const request = new XMLHttpRequest();
            request.open('POST', url);
            request.setRequestHeader('X-CSRF-TOKEN', root.dataset.csrf);
            request.setRequestHeader('Accept', 'application/json');
            request.upload.onprogress = event => {
                if (event.lengthComputable) {
                    progress.value = Math.round(event.loaded / event.total * 100);
                    report(`Mengunggah PDF: ${progress.value}%`, 'processing');
                }
            };
            request.upload.onload = () => {
                progress.removeAttribute('value');
                report('PDF terunggah. Gemini sedang membaca invoice… Jangan muat ulang halaman.', 'processing');
            };
            request.onerror = () => reject(new Error('Koneksi terputus. Isian invoice tetap tersedia; periksa koneksi sebelum mencoba lagi.'));
            request.onload = () => {
                if (request.status === 419 || request.responseURL.includes('/login')) {
                    reject(new Error('Sesi berakhir. Login di tab lain, lalu coba lagi. Isian belum disimpan.')); return;
                }
                try {
                    const result = JSON.parse(request.responseText);
                    if (request.status < 200 || request.status >= 300) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Ekstraksi gagal.');
                    resolve(result);
                } catch (failure) {
                    reject(failure instanceof SyntaxError ? new Error('Respons ekstraksi tidak dapat dibaca. Isian sebelumnya tetap tersedia.') : failure);
                }
            };
            request.send(body);
        });
    }

    function setField(container, suffix, value) {
        const field = container.querySelector(`[name$="[${suffix}]"]`);
        if (field && value !== null && value !== undefined) {
            if (field.tagName === 'SELECT') setSelectValue(field, value);
            else field.value = String(value);
        }
    }

    async function extractInvoices(button) {
        if (extracting) return;
        const area = button.closest('[data-ps-extract-url]');
        const files = area.querySelector('[data-ps-extract-files]').files;
        const status = area.querySelector('[data-ps-extract-status]');
        const progress = area.querySelector('[data-ps-extract-progress]');
        const report = (message, state) => {
            status.textContent = message;
            status.dataset.state = state;
            root.querySelector('[data-ps-toolbar-status]').textContent = state === 'processing' ? message : state === 'error' ? 'Ekstraksi gagal — buka panel PDF.' : state === 'warning' ? 'Sebagian file gagal — buka panel PDF.' : 'Invoice ditambahkan.';
        };
        if (!files.length) { report('Pilih minimal satu file PDF.', 'error'); area.querySelector('[data-ps-extract-files]').focus(); return; }
        if ([...files].some(file => !file.name.toLowerCase().endsWith('.pdf') || file.size > 102400 * 1024)) {
            report('Gunakan file PDF dengan ukuran maksimal 100 MB per file.', 'error'); return;
        }
        const body = new FormData();
        [...files].forEach(file => body.append('raw_pdf_files[]', file));
        body.append('supplier_id', root.querySelector('#ps-supplier')?.value || '');
        if (root.dataset.recordId) body.append('payment_slip_id', root.dataset.recordId);
        button.disabled = true;
        const buttonLabel = button.textContent;
        button.textContent = 'Mengekstrak…';
        extracting = true;
        renderFiles();
        progress.hidden = false;
        progress.value = 0;
        area.setAttribute('aria-busy', 'true');
        area.querySelector('[data-ps-extract-files]').disabled = true;
        report(`Memproses ${files.length} PDF. Tunggu hingga selesai; jangan tutup atau muat ulang halaman.`, 'processing');
        try {
            const extractUrl = area.dataset.psExtractUrl.replace(/\/(import|export)$/, `/${root.querySelector('[data-ps-type]')?.value || root.dataset.type}`);
            const result = await uploadForExtraction(extractUrl, body, progress, report);
            if (!Array.isArray(result.invoices) || !result.invoices.length) throw new Error('Tidak ada invoice yang berhasil diekstrak.');
            const first = invoices()[0];
            const untouched = first && !first.querySelector('input[name$="[invoice_number]"]').value
                && !first.querySelector('[data-ps-document-id]').value
                && !first.querySelector('[data-ps-file]').files.length
                && [...first.querySelectorAll('select')].every(select => !select.value || select.matches('[data-ps-tax-mode]'))
                && [...first.querySelectorAll('[data-ps-item] input')].every(input => input.matches('[data-ps-quantity]') ? input.value === '1' : !input.value);
            if (invoices().length === 1 && untouched) first.remove();
            result.invoices.forEach(data => {
                const invoice = addInvoice(false);
                setField(invoice, 'invoice_number', data.invoice_number);
                setField(invoice, 'invoice_date', data.invoice_date);
                setField(invoice, 'tax_calculation_mode', data.tax_calculation_mode || 'itemized');
                setField(invoice, 'document_file_id', data.document_file_id);
                ['buyer_id', 'vat_invoice_number', 'ppn_tax_id', 'pph_tax_id'].forEach(field => setField(invoice, field, data[field]));
                invoice.querySelector('[data-ps-tax-mode]').dataset.previous = invoice.querySelector('[data-ps-tax-mode]').value;
                invoice.querySelector('[data-ps-preview]').dataset.psPreview = data.document_url || '';
                invoice.querySelector('[data-ps-document-name]').textContent = data.document_name ? `File saat ini: ${data.document_name}` : '';
                const rows = Array.isArray(data.items) ? data.items : Object.values(data.items || {});
                rows.forEach((itemData, index) => {
                    const item = index === 0 ? items(invoice)[0] : addItem(invoice, false);
                    ['item_name', 'quantity', 'unit_price_amount', 'source_supplier_name', 'vat_invoice_number', 'ppn_tax_id', 'pph_tax_id', 'tax_addition_amount', 'tax_deduction_amount'].forEach(field => setField(item, field, itemData[field]));
                });
            });
            updateLabels(); recalculate();
            report((result.message || `${result.invoices.length} invoice berhasil diekstrak.`) + (result.failed?.length ? ` File gagal: ${result.failed.map(file => file.original_name || 'PDF tanpa nama').join(', ')}. Pilih ulang hanya file yang gagal untuk mencoba lagi.` : ' Gunakan Preview PDF untuk memeriksa hasil. Item yang belum ada dapat ditambahkan melalui Tambah Item.'), result.failed?.length ? 'warning' : 'success');
            area.querySelector('[data-ps-extract-files]').value = '';
            area.querySelector('[data-ps-extract-list]').replaceChildren();
            area.querySelector('[data-ps-extract-list]').hidden = true;
        } catch (failure) {
            report(failure.message, 'error');
        } finally {
            button.disabled = false; extracting = false;
            button.textContent = buttonLabel;
            progress.hidden = true;
            renderFiles();
            area.removeAttribute('aria-busy');
            area.querySelector('[data-ps-extract-files]').disabled = false;
        }
    }

    root.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.matches('[data-ps-view]')) setView(button.dataset.psView);
        if (button.matches('[data-ps-toggle-extract]')) {
            const panel = root.querySelector('#ps-extraction-panel');
            panel.hidden = !panel.hidden;
            const trigger = root.querySelector('[aria-controls="ps-extraction-panel"]');
            trigger.setAttribute('aria-expanded', String(!panel.hidden));
            if (panel.hidden) trigger.focus();
        }
        if (button.matches('[data-ps-add-invoice]')) { if (form.hidden) setView('form'); addInvoice(); }
        if (button.matches('[data-ps-add-item]')) addItem(button.closest('[data-ps-invoice]'));
        if (button.matches('[data-ps-remove-invoice]') && invoices().length > 1 && window.confirm('Hapus invoice ini dari draft?')) {
            button.closest('[data-ps-invoice]').remove(); updateLabels(); recalculate();
        }
        if (button.matches('[data-ps-remove-item]')) {
            const invoice = button.closest('[data-ps-invoice]');
            if (items(invoice).length > 1) { button.closest('[data-ps-item]').remove(); updateLabels(); recalculate(); }
        }
        if (button.matches('[data-ps-preview]')) {
            const file = button.closest('[data-ps-invoice]').querySelector('[data-ps-file]')?.files[0];
            if (file) showPreview(URL.createObjectURL(file));
            else if (button.dataset.psPreview) showPreview(button.dataset.psPreview);
            else window.alert('Belum ada PDF invoice untuk ditampilkan.');
        }
        if (button.matches('[data-ps-close-preview]')) showPreview('');
        if (button.matches('[data-ps-extract]')) extractInvoices(button);
        if (button.matches('[data-ps-submit]')) {
            root.querySelector('[data-ps-submit-value]').value = button.dataset.psSubmit === 'submit' ? '1' : '0';
            root.querySelector('[data-ps-create-another]').value = button.dataset.psSubmit === 'another' ? '1' : '0';
        }
        if (button.matches('[data-ps-create-master]') && masterDialog) {
            masterKind = button.dataset.psCreateMaster;
            masterTarget = button.closest('[data-ps-field], .ps-editor__field')?.querySelector(`[data-ps-master-select="${masterKind}"]`);
            masterForm.reset();
            masterDialog.querySelector('[data-ps-master-title]').textContent = `Buat ${masterKind.charAt(0).toUpperCase()}${masterKind.slice(1)}`;
            masterForm.elements.address.closest('label').hidden = masterKind === 'unit';
            masterDialog.querySelector('[data-ps-master-error]').hidden = true;
            masterDialog.showModal();
            masterForm.elements.code.focus();
        }
    });

    root.addEventListener('change', event => {
        if (event.target.tagName === 'SELECT') syncSelect(event.target);
        if (event.target.matches('[data-ps-item-tax-select]')) {
            const item = event.target.closest('[data-ps-item]');
            item.dataset[event.target.dataset.psItemTaxSelect === 'addition' ? 'psAddition' : 'psDeduction'] = '0';
        }
        if (event.target.matches('[data-ps-extract-files]')) {
            renderFiles();
        }
        const modeSelect = event.target.closest('[data-ps-tax-mode]');
        if (modeSelect) {
            const invoice = modeSelect.closest('[data-ps-invoice]');
            const previous = modeSelect.dataset.previous || 'itemized';
            const type = root.querySelector('[data-ps-type]')?.value || root.dataset.type;
            const details = [...invoice.querySelectorAll('[name$="[vat_invoice_number]"]')].some(field => field.closest('[data-ps-item]') && field.value.trim())
                || (type === 'import' && [...invoice.querySelectorAll('[name$="[source_supplier_name]"]')].some(field => field.value.trim()));
            if (modeSelect.value === 'invoice_legacy' && details) {
                modeSelect.value = previous;
                window.alert('Invoice dengan supplier pendukung atau VAT per item harus memakai pajak per item agar detail ERP tidak hilang.');
                recalculate(); return;
            }
            const hasTax = modeSelect.value === 'invoice_legacy'
                ? [...invoice.querySelectorAll('[data-ps-item-tax-select]')].some(select => select.value)
                : [...invoice.querySelectorAll('[data-ps-invoice-tax]')].some(select => select.value);
            if (hasTax && !window.confirm('Pajak pada mode sebelumnya akan dihapus. Lanjutkan?')) {
                modeSelect.value = previous; recalculate(); return;
            }
            if (modeSelect.value === 'invoice_legacy') invoice.querySelectorAll('[data-ps-item-tax-select]').forEach(select => { select.value = ''; });
            else invoice.querySelectorAll('[data-ps-invoice-tax]').forEach(select => { select.value = ''; });
            items(invoice).forEach(item => { item.dataset.psAddition = '0'; item.dataset.psDeduction = '0'; });
            modeSelect.dataset.previous = modeSelect.value;
        }
        recalculate();
    });
    root.addEventListener('input', event => {
        if (event.target.matches('[data-ps-quantity], [data-ps-price], [name$="[invoice_number]"]')) recalculate();
    });
    form.addEventListener('submit', event => {
        if (extracting) {
            event.preventDefault(); window.alert('Tunggu proses ekstraksi selesai sebelum menyimpan.'); return;
        }
        if (invoices().length === 0 || invoices().some(invoice => items(invoice).length === 0)) {
            event.preventDefault(); window.alert('Minimal satu invoice dan satu item diperlukan.');
        }
    });

    masterDialog?.addEventListener('click', event => {
        if (event.target.closest('[data-ps-master-close]')) masterDialog.close();
    });
    masterForm?.addEventListener('submit', async event => {
        event.preventDefault();
        if (!masterForm.reportValidity()) return;
        const submit = masterForm.querySelector('[type="submit"]');
        const error = masterDialog.querySelector('[data-ps-master-error]');
        submit.disabled = true;
        error.hidden = true;
        try {
            const response = await fetch(root.dataset.masterUrl.replace('__KIND__', masterKind), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': root.dataset.csrf, Accept: 'application/json' },
                body: new FormData(masterForm),
            });
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Data gagal disimpan.');
            root.querySelectorAll(`[data-ps-master-select="${masterKind}"]`).forEach(select => {
                const option = new Option(result.label, result.id);
                if (optionLists.has(select)) optionLists.get(select).push(option);
                else select.add(option);
            });
            [invoiceTemplate, itemTemplate].forEach(template => {
                template.content.querySelectorAll(`[data-ps-master-select="${masterKind}"]`).forEach(select => select.add(new Option(result.label, result.id)));
            });
            if (masterTarget) setSelectValue(masterTarget, result.id);
            root.querySelectorAll('select').forEach(syncSelect);
            recalculate();
            masterDialog.close();
        } catch (failure) {
            error.textContent = failure.message;
            error.hidden = false;
        } finally { submit.disabled = false; }
    });

    root.querySelectorAll('[data-ps-tax-mode]').forEach(select => { select.dataset.previous = select.value; });
    enhanceFields(form);
    updateLabels();
    recalculate();
})();
