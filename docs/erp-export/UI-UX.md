# ERP Journal Export - UI/UX Plan

## 1. Design goals

- Make the next action obvious for Accounting.
- Keep one Payment Slip equal to one export throughout the interface.
- Show enough journal detail to catch mapping errors without reproducing a 59-column spreadsheet in the browser.
- Request only data that the system cannot derive.
- Keep optional fields visually optional and avoid unnecessary warnings.
- Use existing Filament components, colors, typography, dark mode, and navigation patterns.

The UI should feel like a focused export queue, not another Payment Slip editor.

## 2. New page inventory

### ERP Export

- Suggested navigation label: `ERP Export`
- Suggested URL: `/admin/erp-exports`
- Navigation group: `Payment`
- Suggested icon: `Heroicon::OutlinedArrowDownTray`
- Access: `checker` role only
- Page type: auto-discovered Filament v5 custom page with a table

No new resource or CRUD page is required because the primary records remain Payment Slips and the feature is an operation over them.

## 3. Page structure

```text
ERP Export
Generate one ERP journal file from one approved payment slip.

[ Ready to export ] [ Export history ]                 [Search]

┌──────────────┬──────────┬──────────┬────────┬────────────┬──────────────┐
│ Slip number  │ Supplier │ Type     │ Amount │ Approved   │ Action       │
├──────────────┼──────────┼──────────┼────────┼────────────┼──────────────┤
│ PS-...       │ ...      │ Export   │ Rp ... │ 04 Sep ... │ Prepare file │
└──────────────┴──────────┴──────────┴────────┴────────────┴──────────────┘
```

### Ready to export tab

Query only `status = approved` and no existing `erpExportItem`.

Recommended columns:

- Slip Number: searchable, links to the existing Payment Slip view.
- Supplier: searchable.
- Type: Import/Export badge.
- Invoice count.
- Amount: IDR formatting.
- Approved at.
- Action: `Prepare file`.

Default sort: newest approval first.

Do not use row checkboxes or bulk actions.

### Export history tab

Show exported Payment Slips and existing export metadata:

- Slip Number.
- Supplier.
- Type.
- Amount.
- Exported by.
- Exported at.
- Action: `Download again` when the private file exists.

History is useful because `erp_export_batches` and `erp_export_items` already exist. It does not require a schema change.

## 4. Prepare-file interaction

Use a wide Filament action modal or slide-over opened by the row action. A separate multi-step wizard would add friction because the user is exporting one already-approved record and normally has only one optional input.

Suggested title:

```text
Prepare ERP file - {slip_number}
```

### Section A - Payment Slip summary

Read-only compact summary:

- Supplier and supplier code.
- Import/Export type and resolved Cost Center.
- Invoice count.
- Total expense.
- PPN.
- PPh.
- Net payable.

This section confirms the selected record and should not repeat the entire Payment Slip form.

### Section B - Optional invoice information

Show one compact row/card per invoice:

```text
Invoice E832772363                  04 Aug 2026
VAT Invoice No.  [____________________________]
                  Optional. Fill only when a tax invoice number is available.
```

Input behavior:

- Label: `VAT Invoice No.`
- Helper text: `Optional. Fill only when a tax invoice number is available.`
- Text input, not numeric input, so leading zeroes remain intact.
- Trim surrounding whitespace.
- Apply a generous maximum length only; do not impose an unconfirmed number pattern.
- Leave empty by default.
- Do not show an error or warning when empty.
- Do not prefill it with supplier Tax No., NPWP, or commercial Invoice Number.
- State exists only for the current export request and is not saved to the database.

Do not expose every blank ERP column as a form field. Those fields remain blank in the generated workbook until a reliable business source exists.

### Section C - Journal preview

Show a compact, scrollable table containing the fields a reviewer needs to understand the journal:

| Preview column | Purpose |
| --- | --- |
| Invoice | Groups rows visually |
| Row type | Expense, PPN, PPh, Supplier |
| Account type | Ledger/Supplier |
| Account | Critical mapping check |
| Description | Traceability to source item |
| Cost Center | Import/Export check |
| Debit | Balance review |
| Credit | Balance review |
| VAT Invoice No. | Confirms optional input placement |

Do not display all 59 ERP columns in the primary preview. They create horizontal noise while most are intentionally blank. Add a short note that the downloaded workbook retains the complete ERP template.

Footer summary:

```text
Total debit       Rp 2.205.903
Total credit      Rp 2.205.903
Difference        Rp         0
```

When balanced, use a restrained success icon/text. Do not use a large celebratory banner.

### Modal actions

- Secondary: `Cancel`
- Optional secondary: `View Payment Slip` (opens existing view, preferably in a new tab only if consistent with the app)
- Primary: `Export XLSX`

The primary action remains disabled or fails with inline errors when structural validation fails.

## 5. Error and empty states

### No ready slips

```text
No payment slips are ready to export.
Approved payment slips will appear here.
```

Do not offer a create button from this page.

### Missing Account

Show the error next to the affected preview row and summarize it above the action footer:

```text
Account is missing for invoice E832772363, item "Terminal Handling".
Assign a COA during Accounting review before exporting.
```

Provide a link to the existing Payment Slip view/edit flow when the user is authorized.

### Unbalanced journal

```text
The file cannot be exported because debit and credit differ by Rp {difference}.
```

Show the three totals. Do not provide an override button in version 1.

### Generation failure

```text
The ERP file could not be generated. No export was recorded. Please try again.
```

Log technical detail server-side; do not expose stack traces.

### Duplicate export

If another request exports the same slip first, show:

```text
This payment slip has already been exported.
```

Offer `Download again` when the stored file is available.

## 6. COA selection adjustment

The current Payment Slip form presents COA at Invoice level, while the database and target journal support COA per Invoice Item.

For Accounting review:

- Add a searchable COA select to every Invoice Item row.
- Keep it hidden from Maker.
- Enable it for Checker only during the submitted/verification stage, matching existing permissions.
- Show code and name together, for example `43011011 - Terminal Handling`.
- Preserve the Invoice-level COA as a legacy fallback; do not emphasize it for new records.
- If helpful for repetitive invoices, an explicit `Apply this COA to all items` convenience action may be added later, but it is not required for version 1.

The item row should remain readable. Recommended desktop order:

```text
Item description | Quantity | Unit price | COA
```

On narrow screens, fields stack vertically with the item description first.

## 7. Visual hierarchy

- Reuse the portal's Plus Jakarta Sans font and Filament theme.
- Use the existing amber primary color for the main export action.
- Use badges for Import/Export and status; do not invent another color system.
- Right-align monetary values and use tabular number spacing where available.
- Keep labels literal and concise.
- Use normal body-size text for tables; avoid oversized KPI cards.
- Keep gridlines/borders light and reserve strong emphasis for blocking validation.
- Preserve dark-mode contrast.

## 8. Accessibility and interaction

- Every action needs a visible text label; do not use icon-only export controls.
- Maintain keyboard focus inside the modal/slide-over.
- Associate helper/error text with the VAT Invoice Number input.
- Announce export success/failure through Filament notifications.
- Keep touch targets large enough for tablet use.
- Do not rely on color alone for validation state.
- Show a loading state and prevent a double-submit while generating the workbook.

## 9. Why this pattern

- A table is the natural UI for selecting one record from an approved queue.
- A row action expresses the one-slip/one-file rule directly.
- A modal or slide-over keeps the user in context while collecting a small amount of per-invoice export data that is persisted when export succeeds.
- A compact preview exposes the financially important fields without forcing the user to inspect 59 columns.
- Optional values stay optional and do not pollute the Payment Slip database.

## 10. UI references

Use Filament v5 native patterns rather than introducing shadcn/ui or a separate component library:

- Filament v5 table and record actions: https://filamentphp.com/docs/5.x/tables/actions
- Filament v5 action forms and modal content: https://filamentphp.com/docs/5.x/actions/modals
- Filament v5 custom pages: https://filamentphp.com/docs/5.x/getting-started#custom-pages
- Filament v5 navigation: https://filamentphp.com/docs/5.x/navigation/overview
- Filament v5 page security: https://filamentphp.com/docs/5.x/advanced/security

The official action-modal pattern directly supports a form inside a table row action, and Filament custom pages provide the appropriate container for this focused workflow.
