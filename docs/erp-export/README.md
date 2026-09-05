# ERP Journal Export - Implementation Brief

Status: ready for implementation  
Audience: coding agents and maintainers  
Target stack: PHP 8.3+, Laravel 13.26.1, Livewire 4.4.1, Filament 5.7.6

## 1. Objective

Add an output-only feature that converts one approved Payment Slip into one Microsoft Excel file matching the validated ERP journal template.

This feature reuses the current transactional data, with one approved schema change to make item-level COA authoritative and persist VAT Invoice Numbers. It is not an ERP API integration, a redesign of Payment Slips, or a general-purpose journal builder.

## 2. Final product decisions

1. One Payment Slip produces one export file.
2. Export is initiated from a new admin page, not from the Payment Slip creation form.
3. The ready-to-export list contains Payment Slips with status `approved`. In the UI this status means **Verified**: Accounting verification is the final in-system decision, and GM does not use this system. Verification sets `verified_at`, `approved_at`, and `approved_by` to the Checker and makes the slip ready for export. The legacy `pending_approval` value remains only for database compatibility and is not part of the active workflow.
4. Only the `checker` role may access the page and export files.
5. Every Invoice Item produces its own expense journal row. Do not aggregate rows in the first version.
6. PPN and PPh values must use the final stored Payment Slip/Invoice values. Do not calculate or round tax again in the exporter.
7. Additional ERP fields are optional. Empty optional fields do not block export.
8. `VAT Invoice No.` is optional and persisted per Invoice in `invoices.vat_invoice_number`.
9. Account and balance failures are structural errors and must block export.
10. The generated workbook must match the canonical template at `docs/vmc 5 -expeditor-.xlsx`: sheet names, two header rows, column order A:BG, date representation, and data types.
11. Do not add, remove, or upgrade Laravel, Livewire, Filament, or spreadsheet packages.
12. Use a migration to move legacy Invoice COA values to empty Invoice Items, remove Invoice-level COA fields, and add `invoices.vat_invoice_number`. Do not add a separate VAT table.

## 3. Scope

### Included

- New ERP Export page for Accounting.
- Approved Payment Slip queue.
- One-row action to prepare and export one Payment Slip.
- Optional VAT Invoice Number input per invoice.
- Compact journal preview before download.
- Server-side structural validation.
- XLSX generation from the validated ERP template.
- Existing export history records, status transition, and audit event.
- COA selection and validation exclusively at Invoice Item level.

### Deferred

- Bulk selection or one file containing multiple Payment Slips.
- Aggregating multiple items into one account row.
- Invoice without tax as a separately optimized flow.
- Multiple suppliers in one Payment Slip.
- Multiple currencies.
- Negative values, reversals, credit notes, or correction journals.
- ERP API or webhook integration.
- User-configurable ERP formats.

The deferred scenarios must not receive speculative logic in the first implementation.

## 4. Database policy

One schema migration is required. It preserves legacy account data by copying Invoice COA values into Invoice Items that do not yet have a COA before removing the Invoice-level fields.

| Output need | Existing source |
| --- | --- |
| Import or Export | `payment_slips.transaction_type` |
| Slip identifier | `payment_slips.slip_number` |
| Supplier account and FD_5 | `suppliers.code` |
| Supplier name | `suppliers.name` |
| Buyer | existing `buyer` relation |
| Invoice number and date | `invoices.invoice_number`, `invoices.invoice_date` |
| Expense amount | `invoice_items.subtotal_amount` |
| Expense description | `invoice_items.item_name` plus existing invoice context |
| Expense COA | `invoice_items.coa_id` / snapshot |
| PPN/PPh amounts | stored Invoice/Payment Slip tax totals |
| Export record | existing `erp_export_batches` and `erp_export_items` |
| Export audit | existing `payment_slip_audits` |
| Optional VAT Invoice No. | `invoices.vat_invoice_number` |

### COA compatibility rule

For new and corrected data, Accounting selects COA per Invoice Item. The database already supports `invoice_items.coa_id`, `coa_code_snapshot`, and `coa_name_snapshot`.

The exporter resolves expense COA in this order:

1. Invoice Item snapshot code.
2. Active Invoice Item COA relation code.
3. If both are empty, block verification/export and identify the invoice and item.

Invoice-level COA fields and UI are removed after their legacy values are copied to empty Invoice Items by the migration.

When an Invoice Item COA is selected, populate its snapshot fields using the same principle already applied to Invoice snapshots. This is a model/application change, not a migration.

## 5. Cost center and fixed mappings

Use application configuration for stable ERP mappings. Do not create a master table in this version.

```php
return [
    'company_dimension' => 'I0500000',
    'currency' => 'IDR',
    'posting_profile' => 'AP_OTP',
    'sales_tax_code' => 'i2',
    'accounts' => [
        'ppn' => '11990501',
        'pph_23' => '21020401',
    ],
    'cost_centers' => [
        'import' => 'I05_190000',
        'export' => 'I05_902020',
    ],
];
```

The Cost Center is derived directly from `payment_slips.transaction_type`. No new user choice or Payment Slip column is needed.

## 6. Journal construction

Create journal rows independently for every invoice within the selected Payment Slip.

### Expense rows

For every Invoice Item:

- Account type: `ledger`
- Account: resolved item COA
- FD_1: Cost Center derived from Import/Export
- FD_2: `I0500000`
- Transaction text: deterministic text based on item name, invoice number, supplier, and available buyer
- Debit: stored item subtotal
- Credit: blank
- Currency: `IDR`
- Offset Account type: `Ledger`

Do not combine items that use the same Account.

### PPN row

Create one PPN row per invoice only when the stored PPN amount is greater than zero:

- Account type: `ledger`
- Account: `11990501`
- Debit: stored PPN amount
- Credit: blank
- Currency: `IDR`
- SalesTaxCode: `i2`
- Offset Account type: `Ledger`

Do not recalculate PPN.

### PPh row

Create one PPh row per invoice only when the stored PPh amount is greater than zero:

- Account type: `ledger`
- Account: `21020401`
- Debit: blank
- Credit: stored PPh amount
- Currency: `IDR`
- Offset Account type: `Ledger`

Do not recalculate or round PPh.

### Supplier row

Create one supplier row per invoice:

- Account type: `Supplier`
- Account: `supplier.code`
- FD_5: `supplier.code`
- Transaction text: deterministic AP description
- Debit: blank
- Credit: stored invoice net payable (`subtotal + PPN - PPh`)
- Currency: `IDR`
- Posting Profile: `AP_OTP`
- Offset Account type: `Ledger`
- Invoice: invoice number
- Document date: invoice date
- VAT Invoice No.: optional persisted value from `invoices.vat_invoice_number`; blank when not supplied

Do not use the supplier NPWP/Tax No. or commercial Invoice Number as a substitute for VAT Invoice No.

### Balance invariant

For every invoice and for the complete file:

```text
Debit  = expense subtotal + PPN
Credit = PPh + supplier net payable
```

Compare monetary values at the stored currency precision. Do not silently alter one row to force a balance.

## 7. ERP workbook contract

Use `docs/vmc 5 -expeditor-.xlsx` as the canonical template. Prefer copying and populating the template over reconstructing its workbook formatting.

Preserve all three sheets and their order:

1. `LedgerJournalTrans`
2. `Costcenter`
3. `Sub`

On `LedgerJournalTrans`:

- Preserve rows 1-2 exactly as the two-level header.
- Write data beginning at row 3.
- Preserve the complete A:BG column order (59 columns).
- Leave unsupported/unused optional columns blank rather than deleting them.
- Write dates as actual spreadsheet dates using the template's date format.
- Write amounts as numeric cells, never formatted text.
- Keep identifier fields such as supplier code, invoice number, and VAT Invoice Number as text so leading zeroes are preserved.
- Use `Seq = 1` for all rows in a one-Payment-Slip export unless the canonical template proves otherwise during implementation verification.

The important populated columns for version 1 are:

| Column | Header | Source/rule |
| --- | --- | --- |
| A | Seq | `1` |
| B | Date | invoice date |
| C | Account type | row rule |
| D | Account | COA, tax account, or supplier code |
| E | FD_1 | transaction cost center on expense rows |
| F | FD_2 | `I0500000` on expense rows |
| I | FD_5 | supplier code on supplier rows |
| O | Transaction text | generated description |
| P | Debit | debit rows only |
| Q | Credit | credit rows only |
| R | Currency | `IDR` |
| T | Posting Profile | `AP_OTP` on supplier rows |
| U | SalesTaxCode | `i2` on PPN rows |
| X | Offset Account type | `Ledger` |
| AL | Invoice | invoice number on supplier rows |
| AN | Document date | invoice date on supplier rows |
| AV | VAT inv. No. | optional user input on supplier rows |

All other columns remain present and blank unless a reliable existing source is explicitly added later.

Suggested filename:

```text
{slip_number}-ERP.xlsx
```

Sanitize characters that are invalid in filenames without changing the visible slip number inside the application.

## 8. Validation behavior

### Blocking errors

- Payment Slip is not `approved`.
- Payment Slip was already exported and the operation is not an explicit re-download of the stored file.
- Supplier or supplier code is missing.
- An Invoice Item cannot resolve an Account using the compatibility rule.
- An invoice has no items/expense value required to construct its journal.
- A stored monetary value is invalid.
- Debit and credit are not balanced per invoice or for the complete file.
- Workbook generation cannot preserve the template contract.

Show a specific error that identifies the affected invoice/item. Do not return a generic server error.

### Non-blocking empty values

- VAT Invoice No.
- VAT Symbol
- Payment date
- Terms of payment
- Bank/reference fields
- Other unused ERP template dimensions

Do not show a warning solely because VAT Invoice No. is blank.

## 9. Status, history, and transaction safety

Generate and register the export atomically where practical:

1. Re-check eligibility and uniqueness on the server.
2. Build and validate journal rows.
3. Generate the XLSX.
4. Store the file using the existing private storage convention.
5. Create one `erp_export_batches` record and one `erp_export_items` record for the selected slip. A batch contains exactly one item in this feature.
   Set both existing batch period fields (`approval_date_from` and `approval_date_to`) to the selected slip's approval date.
6. Set Payment Slip status to `exported`.
7. Add a `payment_slip_audits` event such as `erp_exported`.
8. Return the download.

If any database write fails, do not leave the slip marked as exported. Remove an incomplete generated file when safe.

The existing unique constraint on `erp_export_items.payment_slip_id` remains the guard against duplicate first-time exports.

## 10. Authorization

- Page access: `checker` only.
- Query scope: server-side `approved` slips for the Ready tab and exported slips related through `erpExportItem` for History.
- Export action: authorize again server-side; hiding a button is not sufficient.
- File download/re-download: private storage and authenticated authorization.

## 11. Suggested implementation structure

Names may be adjusted to match nearby conventions, but responsibilities should remain separated.

```text
app/
  Actions/
    ExportPaymentSlipToErp.php
  Data/
    ErpJournalRow.php
  Filament/
    Pages/
      ErpExports.php
  Services/
    Erp/
      ErpJournalBuilder.php
      ErpJournalValidator.php
      ErpWorkbookWriter.php
config/
  erp-export.php
resources/
  templates/
    erp/
      ledger-journal-trans.xlsx
  views/
    filament/
      pages/
        erp-exports.blade.php
tests/
  Feature/
    Filament/
      ErpExportsPageTest.php
    ErpExportWorkflowTest.php
  Unit/
    Erp/
      ErpJournalBuilderTest.php
      ErpJournalValidatorTest.php
      ErpWorkbookWriterTest.php
```

Copy the canonical workbook into the runtime template location only if needed. Keep one authoritative runtime template and document its provenance.

## 12. Definition of done

- Accounting can open the new page and see approved, not-yet-exported slips.
- A row action prepares exactly one slip.
- Optional VAT Invoice Number can be entered per invoice and skipped.
- Preview rows reconcile with the selected Payment Slip.
- Every Invoice Item becomes a separate expense row.
- COA resolution uses item first and Invoice fallback for old data.
- Generated XLSX retains the required sheets, headers, A:BG order, types, and formatting.
- PPN/PPh use stored values without recalculation.
- Debit equals credit per invoice and per workbook.
- Successful export records history, audit, and changes status to `exported`.
- Failed export does not create partial status/history.
- No migration or dependency version change is introduced.
- Relevant tests pass, followed by `vendor/bin/pint --dirty` and `php artisan test`.

## 13. References

- Canonical ERP workbook: `docs/vmc 5 -expeditor-.xlsx`
- Current product requirements: `docs/prd.md`
- Current data model: `docs/database-erd.md`
- Filament v5 custom pages: https://filamentphp.com/docs/5.x/getting-started#custom-pages
- Filament v5 navigation: https://filamentphp.com/docs/5.x/navigation/overview
- Filament v5 table actions: https://filamentphp.com/docs/5.x/tables/actions
- Filament v5 action modals: https://filamentphp.com/docs/5.x/actions/modals
- Filament v5 security: https://filamentphp.com/docs/5.x/advanced/security
