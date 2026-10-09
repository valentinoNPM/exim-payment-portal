<x-filament-panels::page>
    @php
        $selectedAdditionTax = old('addition_tax_id', $additionTaxId);
        $selectedDeductionTax = old('deduction_tax_id', $deductionTaxId);
        $selectedPic = old('pic_user_id', $record?->pic_user_id ?? auth()->id());
        $selectedSupplier = old('supplier_id', $record?->supplier_id);
        $selectedWarehouse = old('warehouse_id', $record?->warehouse_id);
        $selectedCurrency = old('currency', $record?->currency ?? \App\Models\PurchaseOrder::CURRENCY_IDR);
        $selectedStatus = old('status', $record?->status ?? \App\Models\PurchaseOrder::STATUS_NEW);
        $poDate = old('po_date', $record?->po_date?->format('Y-m-d') ?? today()->format('Y-m-d'));
        $deliveryDate = old('delivery_date', $record?->delivery_date?->format('Y-m-d') ?? $poDate);
        $blankZero = static fn ($value) => is_numeric($value) && (float) $value === 0.0 ? '' : $value;
        $shippingAmountInput = $blankZero(old('shipping_amount', $record?->shipping_amount));
        $discountAmountInput = $blankZero(old('discount_amount', $record?->discount_amount));
        $selectedSupplierRecord = $suppliers->firstWhere('id', (int) $selectedSupplier);
        $selectedPicRecord = $users->firstWhere('id', (int) $selectedPic);
        $selectedWarehouseRecord = $warehouses->firstWhere('id', (int) $selectedWarehouse);
        $headerPickerOptions = [
            'supplier' => $suppliers->map(fn ($supplier) => [
                'id' => (string) $supplier->id,
                'label' => $supplier->name,
                'meta' => $supplier->code,
            ])->values(),
            'pic' => $users->map(fn ($user) => [
                'id' => (string) $user->id,
                'label' => $user->name,
                'meta' => '',
            ])->values(),
            'warehouse' => $warehouses->map(fn ($warehouse) => [
                'id' => (string) $warehouse->id,
                'label' => $warehouse->name,
                'meta' => $warehouse->source_code,
            ])->values(),
        ];
    @endphp

    <form
        method="POST"
        action="{{ $isEdit ? route('purchase-orders.editor.update', $record) : route('purchase-orders.editor.store') }}"
        class="po-editor"
        data-po-editor
        data-item-search-url="{{ route('purchase-orders.editor.items') }}"
        data-number-preview-url="{{ route('purchase-orders.editor.number-preview') }}"
        data-is-edit="{{ $isEdit ? '1' : '0' }}"
        novalidate
        wire:ignore
    >
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        @if ($errors->any())
            <div class="po-editor__alert" role="alert">
                <strong>Purchase Order belum dapat disimpan.</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="po-editor__section" aria-labelledby="po-data-heading">
            <header class="po-editor__section-header">
                <div>
                    <h2 id="po-data-heading">Data PO</h2>
                    <p>Data utama dan informasi pengiriman PO.</p>
                </div>
            </header>

            <div class="po-editor__header-grid">
                <div class="po-editor__field">
                    <label for="po-date">Tanggal PO <span>*</span></label>
                    @if ($isEdit)
                        <input type="hidden" name="po_date" value="{{ $poDate }}">
                        <input id="po-date" class="po-editor__input" type="date" value="{{ $poDate }}" disabled>
                    @else
                        <input id="po-date" class="po-editor__input" type="date" name="po_date" value="{{ $poDate }}" required data-po-date>
                    @endif
                </div>

                <div class="po-editor__field">
                    <label for="supplier-picker">Vendor <span>*</span></label>
                    <input type="hidden" name="supplier_id" value="{{ $selectedSupplier }}" data-header-picker-value="supplier">
                    <button id="supplier-picker" type="button" class="po-editor__picker-button" data-open-header-picker="supplier" aria-haspopup="dialog">
                        <span data-header-picker-label="supplier">{{ $selectedSupplierRecord?->name ?: 'Pilih vendor' }}</span>
                        <span class="po-editor__picker-icon" aria-hidden="true">+</span>
                    </button>
                </div>

                <div class="po-editor__field">
                    <label for="pic-picker">PIC <span>*</span></label>
                    <input type="hidden" name="pic_user_id" value="{{ $selectedPic }}" data-header-picker-value="pic">
                    <button id="pic-picker" type="button" class="po-editor__picker-button" data-open-header-picker="pic" aria-haspopup="dialog">
                        <span data-header-picker-label="pic">{{ $selectedPicRecord?->name ?: 'Pilih PIC' }}</span>
                        <span class="po-editor__picker-icon" aria-hidden="true">+</span>
                    </button>
                </div>

                <div class="po-editor__field">
                    <label for="po-number">Nomor PO</label>
                    <input id="po-number" class="po-editor__input" value="{{ $draftNumber }}" readonly data-draft-number>
                    @unless ($isEdit)<small>Nomor masih berupa draf dan baru dipesan saat PO disimpan.</small>@endunless
                </div>

                <div class="po-editor__field">
                    <label for="warehouse-picker">Gudang</label>
                    <input type="hidden" name="warehouse_id" value="{{ $selectedWarehouse }}" data-header-picker-value="warehouse">
                    <button id="warehouse-picker" type="button" class="po-editor__picker-button" data-open-header-picker="warehouse" aria-haspopup="dialog">
                        <span data-header-picker-label="warehouse">
                            {{ $selectedWarehouseRecord ? $selectedWarehouseRecord->name.' ('.$selectedWarehouseRecord->source_code.')' : 'Pilih gudang' }}
                        </span>
                        <span class="po-editor__picker-icon" aria-hidden="true">+</span>
                    </button>
                </div>

                <div class="po-editor__field">
                    <label for="addition-tax">PPN (penambahan)</label>
                    <select id="addition-tax" class="po-editor__select" name="addition_tax_id" data-addition-tax>
                        <option value="" data-rate="0">Tanpa PPN</option>
                        @foreach ($additionTaxes as $tax)
                            <option value="{{ $tax->id }}" data-rate="{{ $tax->rate }}" @selected((string) $selectedAdditionTax === (string) $tax->id)>
                                {{ $tax->name }} ({{ rtrim(rtrim(number_format((float) $tax->rate, 4, '.', ''), '0'), '.') }}%)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="po-editor__field">
                    <label for="currency">Mata Uang <span>*</span></label>
                    <select id="currency" class="po-editor__select" name="currency" required data-currency>
                        @foreach (\App\Models\PurchaseOrder::CURRENCY_LABELS as $code => $label)
                            <option value="{{ $code }}" @selected($selectedCurrency === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="po-editor__field">
                    <label for="delivery-date">Tanggal Pengiriman</label>
                    <input id="delivery-date" class="po-editor__input" type="date" name="delivery_date" value="{{ $deliveryDate }}" data-delivery-date>
                </div>

                <div class="po-editor__field">
                    <label for="shipping-amount">Biaya Kirim</label>
                    <div class="po-editor__money-input">
                        <span data-currency-prefix>Rp</span>
                        <input id="shipping-amount" type="number" name="shipping_amount" min="0" step="0.01" value="{{ $shippingAmountInput }}" data-shipping>
                    </div>
                </div>

                <div class="po-editor__field">
                    <label for="deduction-tax">PPh (potongan)</label>
                    <select id="deduction-tax" class="po-editor__select" name="deduction_tax_id" data-deduction-tax>
                        <option value="" data-rate="0">Tanpa PPh</option>
                        @foreach ($deductionTaxes as $tax)
                            <option value="{{ $tax->id }}" data-rate="{{ $tax->rate }}" @selected((string) $selectedDeductionTax === (string) $tax->id)>
                                {{ $tax->name }} ({{ rtrim(rtrim(number_format((float) $tax->rate, 4, '.', ''), '0'), '.') }}%)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="po-editor__field">
                    <label for="status">Status <span>*</span></label>
                    <select id="status" class="po-editor__select" name="status" required>
                        @foreach (\App\Models\PurchaseOrder::STATUS_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected($selectedStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="po-editor__field">
                    <label for="discount-amount">Diskon</label>
                    <div class="po-editor__money-input">
                        <span data-currency-prefix>Rp</span>
                        <input id="discount-amount" type="number" name="discount_amount" min="0" step="0.01" value="{{ $discountAmountInput }}" data-discount>
                    </div>
                </div>

                @if ($isEdit && filled($record?->source_code))
                    <div class="po-editor__field po-editor__field--wide">
                        <label>Kunci ECOUNT</label>
                        <input class="po-editor__input" value="{{ $record->source_code }}" readonly>
                    </div>
                @endif

            </div>
        </section>

        <section class="po-editor__section" aria-labelledby="po-items-heading">
            <header class="po-editor__section-header">
                <div>
                    <h2 id="po-items-heading">Item PO</h2>
                    <p>Perubahan baris dihitung di browser dan disimpan sekaligus.</p>
                </div>
                <button type="button" class="po-editor__add-button" data-add-row>Tambah Item</button>
            </header>

            <div class="po-editor__table-wrap">
                <table class="po-editor__table">
                    <thead>
                        <tr>
                            <th aria-label="Urutan"></th>
                            <th>Nama Barang <span>*</span></th>
                            <th>Spesifikasi</th>
                            <th>Keterangan</th>
                            <th>Kuantitas <span>*</span></th>
                            <th>Satuan</th>
                            <th>Harga <span>*</span></th>
                            <th>Subtotal</th>
                            <th>Pajak</th>
                            <th aria-label="Hapus"></th>
                        </tr>
                    </thead>
                    <tbody data-items-body>
                        @foreach ($initialItems as $index => $row)
                            @include('filament.resources.purchase-orders.pages.partials.item-row', ['row' => $row, 'index' => $index])
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="po-editor__section po-editor__summary-section" aria-labelledby="po-summary-heading">
            <header class="po-editor__section-header">
                <div><h2 id="po-summary-heading">Ringkasan</h2></div>
            </header>
            <dl class="po-editor__summary">
                <div><dt>Subtotal</dt><dd data-summary-subtotal>Rp 0</dd></div>
                <div><dt>PPN</dt><dd data-summary-addition>Rp 0</dd></div>
                <div><dt>PPh</dt><dd data-summary-deduction>Rp 0</dd></div>
                <div><dt>Diskon</dt><dd data-summary-discount>Rp 0</dd></div>
                <div><dt>Biaya Kirim</dt><dd data-summary-shipping>Rp 0</dd></div>
                <div class="po-editor__summary-total"><dt>Total PO</dt><dd data-summary-total>Rp 0</dd></div>
            </dl>
        </section>

        <div class="po-editor__actions">
            <button type="submit" class="po-editor__primary-button">{{ $isEdit ? 'Simpan Perubahan' : 'Simpan PO' }}</button>
            <a href="{{ \App\Filament\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('index') }}" class="po-editor__secondary-button">Batal</a>
        </div>

        <template data-item-row-template>
            @include('filament.resources.purchase-orders.pages.partials.item-row', [
                'row' => ['quantity' => 1, 'unit_price_amount' => null],
                'index' => '__INDEX__',
            ])
        </template>

        <script type="application/json" data-header-picker-options>{!! json_encode($headerPickerOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

        <dialog class="po-editor__dialog po-editor__dialog--relation" data-header-picker-dialog aria-labelledby="header-picker-title">
            <div class="po-editor__dialog-panel">
                <header class="po-editor__dialog-header">
                    <div>
                        <h2 id="header-picker-title" data-header-picker-title>Pilih Data</h2>
                        <p data-header-picker-description>Cari dan pilih data yang dipakai.</p>
                    </div>
                    <button type="button" class="po-editor__dialog-close" data-close-dialog aria-label="Tutup">×</button>
                </header>
                <div class="po-editor__dialog-search">
                    <input class="po-editor__input" type="search" placeholder="Cari..." data-header-picker-search autocomplete="off">
                </div>
                <div class="po-editor__relation-list" data-header-picker-list></div>
                <p class="po-editor__empty" data-header-picker-empty hidden>Data tidak ditemukan.</p>
                <footer class="po-editor__dialog-footer">
                    <span data-header-picker-count></span>
                </footer>
            </div>
        </dialog>

        <dialog class="po-editor__dialog" data-item-dialog aria-labelledby="item-dialog-title">
            <div class="po-editor__dialog-panel">
                <header class="po-editor__dialog-header">
                    <div>
                        <h2 id="item-dialog-title">Pilih Barang</h2>
                        <p>Cari berdasarkan kode atau nama barang.</p>
                    </div>
                    <button type="button" class="po-editor__dialog-close" data-close-dialog aria-label="Tutup">×</button>
                </header>
                <div class="po-editor__dialog-search">
                    <input class="po-editor__input" type="search" placeholder="Cari barang..." data-item-search autocomplete="off">
                </div>
                <div class="po-editor__dialog-table-wrap">
                    <table class="po-editor__dialog-table">
                        <thead><tr><th>Kode</th><th>Nama Barang</th><th>Satuan</th><th></th></tr></thead>
                        <tbody data-item-results></tbody>
                    </table>
                    <p class="po-editor__empty" data-item-empty hidden>Barang tidak ditemukan.</p>
                </div>
                <footer class="po-editor__dialog-footer">
                    <span data-item-page-label></span>
                    <div>
                        <button type="button" class="po-editor__secondary-button" data-item-prev>Sebelumnya</button>
                        <button type="button" class="po-editor__secondary-button" data-item-next>Berikutnya</button>
                    </div>
                </footer>
            </div>
        </dialog>

        <dialog class="po-editor__dialog po-editor__dialog--unit" data-unit-dialog aria-labelledby="unit-dialog-title">
            <div class="po-editor__dialog-panel">
                <header class="po-editor__dialog-header">
                    <div>
                        <h2 id="unit-dialog-title">Pilih Satuan</h2>
                        <p>Pilih satuan yang dipakai pada baris aktif.</p>
                    </div>
                    <button type="button" class="po-editor__dialog-close" data-close-dialog aria-label="Tutup">×</button>
                </header>
                <div class="po-editor__dialog-search">
                    <input class="po-editor__input" type="search" placeholder="Cari satuan..." data-unit-search autocomplete="off">
                </div>
                <div class="po-editor__unit-list" data-unit-list>
                    @foreach ($units as $unit)
                        <button type="button" data-unit-choice data-unit-id="{{ $unit->id }}" data-unit-name="{{ $unit->name }}">
                            <strong>{{ $unit->name }}</strong>
                        </button>
                    @endforeach
                </div>
                <p class="po-editor__empty" data-unit-empty hidden>Satuan tidak ditemukan.</p>
            </div>
        </dialog>
    </form>

    <script src="{{ asset('js/purchase-order-editor.js') }}?v={{ filemtime(public_path('js/purchase-order-editor.js')) }}" defer></script>
</x-filament-panels::page>
