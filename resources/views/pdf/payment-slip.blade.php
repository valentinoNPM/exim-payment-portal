<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Slip - {{ $slip->slip_number }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            padding: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        th, td {
            border: 1px solid #5b2d8e;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }

        /* Header */
        .header-table td {
            border: none;
            padding: 5px 8px;
        }
        .logo-box {
            width: 60%;
            vertical-align: top;
        }
        .company-name {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        .company-address {
            font-size: 8px;
            color: #555;
            line-height: 1.3;
        }
        .title-box {
            width: 40%;
            font-size: 16px;
            font-weight: bold;
            color: #5b2d8e;
            text-align: right;
            vertical-align: middle;
        }

        /* Approval Grid */
        .approval-grid th {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-size: 8px;
            font-weight: bold;
            text-align: center;
            padding: 3px 4px;
        }
        .approval-grid td {
            text-align: center;
            height: 22px;
            vertical-align: middle;
            font-size: 8px;
        }
        .approval-grid .label-cell {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            width: 18%;
        }

        /* Data Section */
        .data-table td {
            border: none;
            padding: 2px 6px;
            font-size: 10px;
        }
        .data-label {
            font-weight: bold;
            width: 18%;
            color: #333;
        }
        .data-separator {
            width: 2%;
            text-align: center;
        }
        .data-value {
            width: 30%;
        }

        /* Detail Table */
        .detail-table {
            margin-top: 8px;
        }
        .detail-table th {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            font-size: 9px;
            text-align: center;
            padding: 5px 4px;
        }
        .detail-table td {
            padding: 4px 6px;
            font-size: 9px;
        }
        .detail-table .amount-cell {
            text-align: right;
            white-space: nowrap;
        }
        .summary-label {
            text-align: right;
            font-weight: bold;
            color: #5b2d8e;
            padding-right: 10px;
        }
        .summary-value {
            text-align: right;
            font-weight: bold;
        }

        /* Payment Box */
        .payment-table {
            margin-top: 10px;
        }
        .payment-table th {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            font-size: 8px;
            text-align: center;
            padding: 4px;
        }
        .payment-table td {
            height: 30px;
            text-align: center;
            vertical-align: middle;
            font-size: 8px;
        }
        .payment-label {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
        }

        /* Footer Box */
        .footer-table {
            margin-top: 8px;
        }
        .footer-table th {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            font-size: 9px;
            text-align: center;
            padding: 5px;
        }
        .footer-table td {
            height: 25px;
            text-align: center;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header: Company Info + Title -->
        <table class="header-table">
            <tr>
                <td class="logo-box">
                    @if(file_exists(public_path('images/logo.svg')))
                        <img src="{{ public_path('images/logo.svg') }}" style="height: 28px; width: auto; margin-bottom: 3px;" /><br/>
                    @endif
                    <span class="company-name">PT. HANSOLL INDO JAVA</span><br/>
                    <span class="company-address">
                        Dukuh Ngemplak, RT.006/RW.002, Dusun III, Randusari<br/>
                        Randusari, Kec. Teras, Kabupaten Boyolali, Jawa Tengah 57372, Jawa Tengah - Indonesia<br/>
                        Telp.: (0276) 3280401
                    </span>
                </td>
                <td class="title-box">
                    PAYMENT SLIP
                </td>
            </tr>
        </table>

        <!-- Approval Grid -->
        <table class="approval-grid" style="margin-bottom: 8px;">
            <tr>
                <td rowspan="2" class="label-cell">APPROVAL</td>
                <th>INCHARGE</th>
                <th>PASSED</th>
                <th>PASSED</th>
                <th>PASSED</th>
                <th>DIRECTOR</th>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
        </table>

        <!-- Data Section -->
        @php
            $invoiceNumbers = $slip->invoices->pluck('invoice_number')->filter()->implode(', ');
            $department = $slip->creator?->division?->name ?? strtoupper($slip->transaction_type);
        @endphp
        <table class="data-table" style="margin-bottom: 8px;">
            <tr>
                <td class="data-label">Payment Slip Number</td>
                <td class="data-separator">:</td>
                <td class="data-value">{{ $slip->slip_number }}</td>
                
            </tr>
            <tr>
                <td class="data-label">Date</td>
                <td class="data-separator">:</td>
                <td class="data-value">{{ $slip->created_at->format('Y-m-d') }}</td>
                <td class="data-label">No. Invoice</td>
                <td class="data-separator">:</td>
                <td class="data-value">{{ $invoiceNumbers }}</td>
            </tr>
            <tr>
                <td class="data-label">Department</td>
                <td class="data-separator">:</td>
                <td class="data-value">{{ $department }} ({{ strtoupper($slip->transaction_type) }})</td>
                <td colspan="3"></td>
            </tr>
            <tr>
                <td class="data-label">Name</td>
                <td class="data-separator">:</td>
                <td class="data-value">{{ $slip->creator?->name ?? '-' }}</td>
                <td colspan="3"></td>
            </tr>
            <tr>
                <td class="data-label">Supplier</td>
                <td class="data-separator">:</td>
                <td class="data-value">{{ $slip->supplier?->name ?? '-' }}</td>
                <td colspan="3"></td>
            </tr>
            <tr>
                <td class="data-label">Account Name</td>
                <td class="data-separator">:</td>
                <td class="data-value">
                    @php
                        $coaNames = $slip->invoices->map(fn($inv) => $inv->coa_name_snapshot)->filter()->unique()->implode(', ');
                    @endphp
                    {{ $coaNames ?: '-' }}
                </td>
                <td colspan="3"></td>
            </tr>
            <tr>
                <td class="data-label">Transaction</td>
                <td class="data-separator">:</td>
                <td class="data-value" colspan="4">CHARGE {{ strtoupper($slip->transaction_type) }}</td>
            </tr>
        </table>

        <!-- Detail Table -->
        <table class="detail-table">
            <thead>
                <tr>
                    <th style="width: 45%;">Detail</th>
                    <th style="width: 14%;">Amount</th>
                    <th style="width: 14%;">PPN</th>
                    <th style="width: 14%;">PPH</th>
                    <th style="width: 13%;">Amount Dibayar</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $totalSubtotal = 0;
                    $totalPpn = 0;
                    $totalPph = 0;
                    $totalGrand = 0;
                @endphp
                @foreach($slip->invoices as $idx => $invoice)
                @php
                    $invSubtotal = (float) $invoice->subtotal_amount;
                    $invPpn = (float) $invoice->tax_addition_amount;
                    $invPph = (float) $invoice->tax_deduction_amount;
                    $invGrand = (float) $invoice->grand_total_amount;
                    $totalSubtotal += $invSubtotal;
                    $totalPpn += $invPpn;
                    $totalPph += $invPph;
                    $totalGrand += $invGrand;

                    // Build description: REF [invoice_number] - [item names]
                    $itemNames = $invoice->items->pluck('item_name')->implode(', ');
                    $description = "REF {$invoice->invoice_number}";
                    if ($itemNames) {
                        $description .= " - {$itemNames}";
                    }
                @endphp
                <tr>
                    <td>{{ $idx + 1 }}. {{ $description }}</td>
                    <td class="amount-cell">Rp {{ number_format($invSubtotal, 0, ',', '.') }}</td>
                    <td class="amount-cell">{{ $invPpn > 0 ? 'Rp ' . number_format($invPpn, 0, ',', '.') : '-' }}</td>
                    <td class="amount-cell">{{ $invPph > 0 ? 'Rp ' . number_format($invPph, 0, ',', '.') : '-' }}</td>
                    <td class="amount-cell">Rp {{ number_format($invGrand, 0, ',', '.') }}</td>
                </tr>
                @endforeach

                <!-- Empty spacer rows if few invoices -->
                @for($i = count($slip->invoices); $i < 3; $i++)
                <tr>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
                @endfor
            </tbody>

            <!-- Summary Rows -->
            <tfoot>
                <tr>
                    <td class="summary-label" colspan="4">SUB TOTAL</td>
                    <td class="summary-value">Rp {{ number_format($totalSubtotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="4">
                        PPN
                        @php
                            $ppnLabel = $slip->invoices->first()?->ppnTax?->name;
                        @endphp
                        {{ $ppnLabel ? "({$ppnLabel})" : '(0%)' }}
                    </td>
                    <td class="summary-value">Rp {{ number_format($totalPpn, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="4">
                        PPH
                        @php
                            $pphLabel = $slip->invoices->first()?->pphTax?->name;
                        @endphp
                        {{ $pphLabel ? "({$pphLabel})" : '(0%)' }}
                    </td>
                    <td class="summary-value">Rp {{ number_format($totalPph, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="4" style="font-size: 11px;">GRAND TOTAL</td>
                    <td class="summary-value" style="font-size: 11px;">Rp {{ number_format($totalGrand, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>

        <!-- Payment Signature Box -->
        <table class="payment-table">
            <tr>
                <td rowspan="2" class="payment-label" style="width: 15%;">PAYMENT</td>
                <th style="width: 21%;">INCHARGE</th>
                <th style="width: 21%;">ACC MANAGER</th>
                <th style="width: 22%;">GENERAL MANAGER</th>
                <th style="width: 21%;">PASSED</th>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
        </table>

        <!-- Footer: Tanggal Bayar & Received/Confirmed -->
        <table class="footer-table">
            <tr>
                <th style="width: 50%;">Tanggal Bayar</th>
                <th style="width: 50%;">Received / Confirmed</th>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
        </table>
    </div>
</body>
</html>
