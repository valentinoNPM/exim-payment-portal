<tr class="po-editor__item-row" data-po-item-row>
    <td class="po-editor__move-cell">
        <input type="hidden" name="items[{{ $index }}][id]" value="{{ $row['id'] ?? '' }}" data-field="id">
        <input type="hidden" name="items[{{ $index }}][item_id]" value="{{ $row['item_id'] ?? '' }}" data-field="item_id">
        <button type="button" class="po-editor__icon-button" data-move="up" aria-label="Pindahkan item ke atas">↑</button>
        <button type="button" class="po-editor__icon-button" data-move="down" aria-label="Pindahkan item ke bawah">↓</button>
    </td>
    <td>
        <button type="button" class="po-editor__picker-button" data-open-item-picker>
            <span data-item-label>{{ filled($row['item_name'] ?? null) ? trim(($row['item_code'] ?? '').' - '.$row['item_name'], ' -') : 'Pilih barang' }}</span>
            <span class="po-editor__picker-icon" aria-hidden="true">+</span>
        </button>
    </td>
    <td>
        <input
            class="po-editor__input"
            name="items[{{ $index }}][specification]"
            value="{{ $row['specification'] ?? '' }}"
            maxlength="500"
            data-field="specification"
            aria-label="Spesifikasi item"
        >
    </td>
    <td>
        <input
            class="po-editor__input po-editor__number-input"
            type="number"
            name="items[{{ $index }}][quantity]"
            value="{{ $row['quantity'] ?? 1 }}"
            min="0"
            step="1"
            required
            data-field="quantity"
            aria-label="Kuantitas item"
        >
    </td>
    <td>
        <input type="hidden" name="items[{{ $index }}][unit_id]" value="{{ $row['unit_id'] ?? '' }}" data-field="unit_id">
        <button type="button" class="po-editor__unit-button" data-open-unit-picker>
            <span data-unit-label>{{ $row['unit_name'] ?? 'Pilih satuan' }}</span>
            <span aria-hidden="true">⌄</span>
        </button>
    </td>
    <td>
        <div class="po-editor__money-input">
            <span data-currency-prefix>Rp</span>
            <input
                type="number"
                name="items[{{ $index }}][unit_price_amount]"
                value="{{ $row['unit_price_amount'] ?? 0 }}"
                step="0.01"
                required
                data-field="unit_price_amount"
                aria-label="Harga item"
            >
        </div>
    </td>
    <td class="po-editor__numeric" data-line-subtotal>Rp 0</td>
    <td class="po-editor__numeric" data-line-tax>Rp 0</td>
    <td class="po-editor__delete-cell">
        <button type="button" class="po-editor__delete-button" data-remove-row aria-label="Hapus item">×</button>
    </td>
</tr>
