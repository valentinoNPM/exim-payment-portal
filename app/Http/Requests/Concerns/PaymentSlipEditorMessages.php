<?php

namespace App\Http\Requests\Concerns;

trait PaymentSlipEditorMessages
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'numeric' => ':attribute harus berupa angka.',
            'integer' => 'Pilihan :attribute tidak valid.',
            'exists' => 'Pilihan :attribute tidak tersedia atau tidak diizinkan.',
            'in' => 'Pilihan :attribute tidak valid.',
            'date' => ':attribute harus berupa tanggal yang valid.',
            'string' => ':attribute harus berupa teks.',
            'array' => 'Format :attribute tidak valid.',
            'boolean' => 'Pilihan :attribute tidak valid.',
            'max' => ':attribute melebihi batas :max.',
            'invoices.*.document_pdf.max' => ':attribute maksimal 100 MB.',
            'min' => ':attribute minimal berisi :min data.',
            'file' => ':attribute harus berupa file.',
            'mimetypes' => ':attribute harus berupa PDF.',
            'invoices.*.buyer_id.required' => 'Buyer pada invoice #:position wajib dipilih.',
            'invoices.*.invoice_number.required' => 'Nomor invoice #:position wajib diisi.',
            'invoices.*.items.*.unit_id.required' => 'Satuan pada invoice #:position, item #:second-position wajib dipilih.',
            'invoices.*.items.*.item_name.required' => 'Nama item pada invoice #:position, item #:second-position wajib diisi.',
            'form_complete.required' => 'Form tidak terkirim utuh karena terlalu besar. Muat ulang halaman dan coba lagi; hubungi admin jika berulang.',
            'form_complete.in' => 'Form tidak terkirim utuh. Muat ulang halaman dan coba lagi.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'Supplier', 'customer_id' => 'Customer', 'buyer_id' => 'Buyer',
            'currency' => 'Mata uang', 'transaction_type' => 'Tipe transaksi',
            'transaction_description' => 'Keterangan transaksi', 'invoice_receipt_number' => 'Nomor tanda terima',
            'invoices' => 'Invoice', 'invoices.*.invoice_date' => 'Tanggal invoice',
            'invoices.*.buyer_id' => 'Buyer invoice', 'invoices.*.document_pdf' => 'Dokumen invoice',
            'invoices.*.items' => 'Item invoice', 'invoices.*.items.*.quantity' => 'Quantity',
            'invoices.*.items.*.unit_price_amount' => 'Harga item', 'invoices.*.items.*.unit_id' => 'Satuan',
        ];
    }
}
