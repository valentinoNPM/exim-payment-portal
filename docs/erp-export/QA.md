# ERP Export — implementation and QA

Verified on 5 September 2026. Baseline: 13 tests / 31 assertions. Final suite: 31 tests / 183 assertions.

## Implemented behavior

- Checker-only, automatically discovered `/admin/erp-exports` page under Payment.
- Ready contains only approved slips without an export item. Search, Import/Export filter, approval-date range, and sorting are available; there are no bulk actions or selection checkboxes.
- Prepare file displays the slip summary, optional per-invoice VAT text inputs, and a nine-column journal preview. VAT values are persisted on their Invoice when export succeeds and are not copied into audit payloads.
- Each item creates an expense row using only its item snapshot or item COA relation. Stored PPN, PPh, and invoice net payable are used without recalculation. Integer minor units validate both invoice and whole-file balances.
- Item COA selection is limited to Checker during submitted status. Snapshot code/name come from the selected master record. Maker does not receive snapshot names in form state. Saving COA does not recalculate stored taxes.
- Verify on Edit, View, and Table consistently transitions directly to the database status `approved` (displayed as Verified), validates account resolution, records the Checker and timestamps, and writes an audit. Verified/exported slips cannot be edited through the resource.
- Export locks the slip, revalidates eligibility, writes and reopens the private file, then creates one existing batch and one item, updates status, and records `erp_exported` in one transaction. Exceptions remove the generated file and roll back database writes. The existing unique index protects duplicate exports.
- Successful export downloads the file and switches to History. History uses actual export time for ordering. Download again reads the existing private file through an authenticated Checker-only route and creates no records.

## Workbook mapping

The runtime template remains `docs/vmc 5 -expeditor-.xlsx`; it is not copied into a second authoritative template.

| Row | Account | Debit | Credit | Other fields |
| --- | --- | --- | --- | --- |
| Expense, one per item | Resolved COA | Stored item subtotal | Empty | FD_1 from transaction type; FD_2 I0500000 |
| PPN, when positive | 11990501 | Stored PPN | Empty | SalesTaxCode i2 |
| PPh, when positive | 21020401 | Empty | Stored PPh | |
| Supplier, one per invoice | Supplier code | Empty | Stored net payable | FD_5 supplier code; AP_OTP; invoice/date; optional VAT |

All rows have Seq 1, IDR currency, and Offset Account type Ledger. B contains the invoice date. Data begins at row 3. A:BG, both header rows, and LedgerJournalTrans / Costcenter / Sub sheet order remain intact. Unsupported columns stay blank. Identifier cells are explicit strings; dates are spreadsheet serial dates and amounts are numeric.

## Checks performed

- `php vendor/bin/pint --dirty`, followed by `php vendor/bin/pint --dirty --test`: passed.
- `php artisan test`: 31 tests passed, 183 assertions.
- ERP-specific tests cover account fallback order, per-item snapshots, Maker restrictions, stale-model status protection, actual verification-form saving without tax changes, import/export mapping, exact stored taxes, optional VAT, live preview, modal download, eligibility, duplicate rejection, writer/audit failure rollback, history, private downloads, missing files/templates, invalid money, empty items, and imbalance.
- `php artisan route:list --path=erp`: both page and download routes are registered.
- Browser QA used only a separate temporary SQLite database: Ready, Prepare, reactive VAT, export loading/disabled submit controls, automatic History transition, empty Ready state, and Download again were exercised.
- Light/dark mode and desktop/tablet layouts were inspected. The journal table scrolls horizontally on tablet; modal controls remain visible.
- After downloading an existing fixture again, counts remained 2 batches, 2 items, and 2 export audits for the two exported fixture slips.
- `git diff --check`: clean. No migration, `.env`, or dependency changes. No application database migration or seeding was run.

## Verified example

`storage/app/private/erp-examples/PS-FIXTURE-002-ERP.xlsx`

The example contains two invoices, four separate expense rows, two PPN rows, two PPh rows, and two Supplier rows. Both invoice journals balance; total debit and credit are each IDR 666.01. Supplier code `000123`, invoice numbers `000045` / `000046`, and VAT number `000099` retain leading zeroes. The second VAT input is blank.

The file was reopened with PhpSpreadsheet and independently with openpyxl. The latter emitted zero warnings. ZIP integrity passed and all 15 XML/relationship parts parsed successfully. Header text, typography, alignment, numeric/date types, optional blank cells, supporting sheet values, and absence of leftover example transactions were checked. Workbook ranges were also rendered for visual inspection.

PhpSpreadsheet normalizes empty header strings to blank cells and serializes some theme colors as their RGB equivalents. The independent renderer sometimes displays numeric-looking string identifiers without leading zeroes; raw cell values/types were verified with both PhpSpreadsheet and openpyxl and are correct.

## Verification limits

- Microsoft Excel desktop and an import into the target ERP were not run in this session. The successful parser/ZIP/XML checks do not claim a native Excel or ERP acceptance test.
- Transaction tests used isolated SQLite. MySQL concurrent-load testing was not performed; production protection uses a row lock and the existing unique export-item constraint.
- Existing approved slips without an approval timestamp are blocked explicitly because the required batch approval-period fields cannot be derived reliably. No date is invented and no legacy record is changed automatically.
- Amounts exceeding 13 integer digits or containing more than two decimal places are rejected to avoid silent precision loss in numeric XLSX cells.
- A process kill/power loss between filesystem and database operations is outside exception rollback; as with any separate filesystem/database transaction, an unreferenced private file could remain. Ordinary writer/database failures are covered by cleanup tests.

## File inventory

New files:

- `app/Actions/ExportPaymentSlipToErp.php`
- `app/Actions/VerifyPaymentSlip.php`
- `app/Data/ErpJournalRow.php`
- `app/Filament/Pages/ErpExports.php`
- `app/Http/Controllers/DownloadErpExportController.php`
- `app/Services/Erp/AccountResolver.php`
- `app/Services/Erp/ErpJournalBuilder.php`
- `app/Services/Erp/ErpJournalValidator.php`
- `app/Services/Erp/ErpWorkbookWriter.php`
- `config/erp-export.php`
- `resources/views/filament/pages/erp-exports.blade.php`
- `resources/views/filament/erp/summary.blade.php`
- `resources/views/filament/erp/preview.blade.php`
- `tests/Fixtures/ErpPaymentSlip.php`
- `tests/Feature/ErpExportTest.php`
- `docs/erp-export/QA.md`

Updated files:

- `app/Models/InvoiceItem.php`
- `app/Filament/Resources/PaymentSlips/PaymentSlipResource.php`
- `app/Filament/Resources/PaymentSlips/Schemas/PaymentSlipForm.php`
- `app/Filament/Resources/PaymentSlips/Pages/EditPaymentSlip.php`
- `app/Filament/Resources/PaymentSlips/Pages/ViewPaymentSlip.php`
- `routes/web.php`

Temporary browser-server scripts, SQLite data, screenshots, renderer scripts/junction, roundtrip workbook, and superseded example were removed after verification. The verified example above is retained privately as the deliverable; test fixture source and automated tests are retained in the repository.
