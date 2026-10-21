<section class="ps-editor__section" aria-labelledby="ps-approval-heading">
    <header class="ps-editor__section-heading">
        <div>
            <h2 id="ps-approval-heading">Nama Persetujuan</h2>
            <p>Nama untuk kotak APPROVAL pada PDF. Tanda tangan tetap diisi pada dokumen cetak.</p>
        </div>
    </header>
    <div class="ps-editor__header-grid">
        <div class="ps-editor__field">
            <label for="ps-approval-incharge">Incharge</label>
            <input id="ps-approval-incharge" name="approval_incharge" maxlength="255" value="{{ old('approval_incharge', $record?->approval_incharge ?: ($record?->creator?->name ?? auth()->user()?->name)) }}" placeholder="Nama Incharge">
            <small>Otomatis nama pembuat slip; dapat diganti.</small>
        </div>
        @foreach (['approval_passed_1' => 'Passed 1', 'approval_passed_2' => 'Passed 2', 'approval_passed_3' => 'Passed 3', 'approval_director' => 'Director'] as $field => $label)
            <div class="ps-editor__field">
                <label for="ps-{{ str_replace('_', '-', $field) }}">{{ $label }} (opsional)</label>
                <input id="ps-{{ str_replace('_', '-', $field) }}" name="{{ $field }}" maxlength="255" value="{{ old($field, $record?->{$field}) }}" placeholder="Nama {{ $label }}">
            </div>
        @endforeach
    </div>
</section>
