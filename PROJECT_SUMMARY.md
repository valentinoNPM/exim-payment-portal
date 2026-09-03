# Exim Payment Portal - AI Agent Context & Project Summary

> **ATTENTION AI AGENTS:** This document serves as the master context file for the Exim Payment Portal project. Read this carefully before suggesting changes, writing code, or evaluating the architecture. It contains the technical stack, core business logic, architectural patterns, and strict coding rules you must follow.

## 1. Project Overview
**Exim Payment Portal** is a web-based system designed to manage and document payment slips for **Export** and **Import** transactions. It facilitates a workflow from document creation to approval, involving roles such as `maker` and `checker`.

## 2. Technology Stack & Environment
- **Backend:** PHP 8.3+, Laravel 13.26.1 (or compatible Laravel 11.x depending on environment).
- **Frontend/Admin Panel:** Filament v5.7.6, Livewire 4.4.1.
- **PDF Generation:** `barryvdh/laravel-dompdf`.
- **AI Integration:** Google Gemini API for OCR/Invoice data extraction (`GeminiInvoiceExtractor`).

## 3. Strict Development Rules (CRITICAL)
When contributing to this project, you **MUST** adhere to the following rules (also defined in `AGENTS.md`):
1. **Filament v5 Only:** You must use Filament v5 APIs. Do NOT use Filament v3/v4 namespaces (`Filament\Forms\Form`, `Filament\Tables\Actions\*`, `->actions()`, `->bulkActions()`). 
2. **Resource Structure:** Follow the strict separation of concerns for resources:
   - Form schemas must be in `app/Filament/Resources/<Plural>/Schemas/<Singular>Form.php`.
   - Tables must be in `app/Filament/Resources/<Plural>/Tables/<Plural>Table.php`.
3. **Reactive Forms:** Use `Filament\Schemas\Components\Utilities\Get` and `Set` for live updates in forms, not the old form closures.
4. **Auto-Discovery:** Do not manually register resources/pages/widgets in `AdminPanelProvider.php`. They are auto-discovered.

## 4. Core Business Logic & Features

### A. Payment Slips (`PaymentSlip` Model)
- **Transaction Types:** `import` and `export`.
- **Relations:** Belongs to `Supplier`, `Buyer`, and `Creator` (User). Has Many `Invoices`.
- **Forms (`PaymentSlipForm.php`):** 
  - Both `supplier_id` and `buyer_id` are strictly required for all transaction types and must have placeholders (`Pilih Supplier`, `Pilih Buyer`).
  - Utilizes `Repeater` for `Invoices`.
- **Status Workflow:** `draft` -> `submitted` -> `pending_approval` -> `approved` -> `exported`.

### B. Invoices (`Invoice` Model) & Taxes
- **Relations:** Belongs to `PaymentSlip`, `DocumentFile` (PDF), `ChartOfAccount` (COA), `Tax` (PPN/PPh). Has Many `InvoiceItem`.
- **Live Calculations:** The form automatically computes:
  - `tax_addition_amount` (PPN) based on selected Tax rate.
  - `tax_deduction_amount` (PPh) based on selected Tax rate.
  - `grand_total_amount` = `subtotal_amount` + `tax_addition_amount` - `tax_deduction_amount`.
  
### C. AI Invoice Extraction (`GeminiInvoiceExtractor`)
- Users upload a raw PDF. 
- A custom action sends the file(s) to Gemini AI to extract `invoice_number`, `invoice_date`, and line items (`item_name`, `qty`, `original_price`).
- The extracted data auto-populates the Livewire repeater in the Filament form.
- Contains a fallback to manual upload if the AI extraction fails.

### D. PDF Document Generation (`GeneratePaymentSlipPdf.php`)
- **Action:** Generates an A4 portrait PDF for printing.
- **Eager Loading:** Must eager load `supplier`, `buyer`, `invoices.documentFile`, `invoices.items`, `invoices.ppnTax`, `invoices.pphTax`, `creator.division`.
- **View (`resources/views/pdf/payment-slip.blade.php`):** Renders company header (PT. HANSOLL INDO JAVA), transaction details (Supplier & Buyer shown explicitly), a table of invoices with subtotals/taxes/grand totals, and approval/payment signature grids.

## 5. Key File Locations
- **PDF Action:** `app/Actions/GeneratePaymentSlipPdf.php`
- **PDF View:** `resources/views/pdf/payment-slip.blade.php`
- **Payment Slip Resource:** `app/Filament/Resources/PaymentSlips/PaymentSlipResource.php`
- **Payment Slip Form Schema:** `app/Filament/Resources/PaymentSlips/Schemas/PaymentSlipForm.php`
- **Payment Slip Table:** `app/Filament/Resources/PaymentSlips/Tables/PaymentSlipsTable.php`

## 6. Current State & Recent Updates
- The system correctly enforces that `Buyer` input is required for both Export and Import transactions.
- Placeholders for Supplier and Buyer dropdowns are correctly set to `Pilih Supplier` and `Pilih Buyer`.
- The PDF rendering engine successfully includes the `Buyer` information directly below the `Supplier` in the generated document.

---
*End of Context.* When asked to implement a new feature, refer to the structure of `PaymentSlipForm.php` as a baseline for how Filament v5 schemas are constructed in this project.
