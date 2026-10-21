<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment/Income Slip - {{ $slip->slip_number }}</title>
    <style>
        @page {
            margin: 14px 28px 18px;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12px;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            padding: 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        th, td {
            border: 1px solid #003f97;
            padding: 5px 7px;
            text-align: left;
            vertical-align: top;
        }

        /* Header */
        .header-table td {
            border: none;
            padding: 0 4px 2px;
        }
        .logo-box {
            width: 100%;
            vertical-align: top;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        .company-address {
            font-size: 11.5px;
            color: #555;
            line-height: 1.2;
        }
        .document-title {
            font-size: 24px;
            font-weight: bold;
            color: #003f97;
            text-align: center;
            margin: 4px 0 6px;
            letter-spacing: 0.5px;
        }

        /* Approval Grid */
        .approval-grid {
            margin-top: 10px;
        }
        .approval-grid th {
            background-color: #EDF1F3;
            color: #003f97;
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            padding: 3px 4px;
        }
        .approval-grid td {
            text-align: center;
            height: 26px;
            vertical-align: middle;
            font-size: 11px;
        }
        .approval-grid .label-cell {
            background-color: #EDF1F3;
            color: #003f97;
            font-weight: bold;
            width: 15%;
        }
        .approval-grid .signature-space {
            height: 60px;
        }
        .approval-grid .signer-name {
            height: 24px;
            padding: 4px 3px;
            font-size: 12px;
            font-weight: bold;
            line-height: 1.15;
            word-wrap: break-word;
        }

        /* Data Section */
        .data-table td {
            border: none;
            padding: 3px 6px;
            font-size: 13px;
            line-height: 1.25;
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
            background-color: #EDF1F3;
            color: #003f97;
            font-weight: bold;
            font-size: 12px;
            text-align: center;
            padding: 5px 4px;
        }
        .detail-table td {
            padding: 5px 7px;
            font-size: 12px;
            line-height: 1.25;
        }
        .detail-table .amount-cell {
            text-align: right;
            white-space: nowrap;
        }
        .summary-label {
            text-align: right;
            font-weight: bold;
            color: #003f97;
            padding-right: 10px;
        }
        .summary-value {
            text-align: right;
            font-weight: bold;
            white-space: nowrap;
        }
        .detail-table .item-meta {
            font-size: 11px;
            color: #666;
        }
        .detail-table .grand-total {
            font-size: 14px;
        }

        /* Payment Box */
        .payment-table {
            margin-top: 10px;
        }
        .payment-table th {
            background-color: #EDF1F3;
            color: #003f97;
            font-weight: bold;
            font-size: 11px;
            text-align: center;
            padding: 4px;
        }
        .payment-table td {
            height: 34px;
            text-align: center;
            vertical-align: middle;
            font-size: 11px;
        }
        .payment-label {
            background-color: #EDF1F3;
            color: #003f97;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
        }

        /* Footer Box */
        .footer-table {
            margin-top: 8px;
        }
        .footer-table th {
            background-color: #EDF1F3;
            color: #003f97;
            font-weight: bold;
            font-size: 11px;
            text-align: center;
            padding: 5px;
        }
        .footer-table td {
            height: 28px;
            text-align: center;
            vertical-align: middle;
            font-size: 11px;
        }
        .signature-block {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header: Company Info -->
        <table class="header-table">
            <tr>
                <td class="logo-box">
                    @if(file_exists(public_path('images/logo.png')))
                        <img src="{{ public_path('images/logo.png') }}" style="height: 54px; width: auto; margin-bottom: 1px;" /><br/>
                    @else
                        @if(file_exists(public_path('images/logo.svg')))
                            <img src="{{ public_path('images/logo.svg') }}" style="height: 14px; width: auto; margin-bottom: 3px;" /><br/>
                        @endif
                        <span class="company-name">PT. HANSOLL INDO JAVA</span><br/>
                    @endif
                    <span class="company-address">
                        Dukuh Ngemplak, RT.006/RW.002, Dusun III, Randusari<br/>
                        Randusari, Kec. Teras, Kabupaten Boyolali, Jawa Tengah 57372, Jawa Tengah - Indonesia<br/>
                        Telp.: (0276) 3280401
                    </span>
                </td>
            </tr>
        </table>

        <hr style="border: none; border-top: 1.5px solid #003f97; margin: 2px 0 5px;" />

        <!-- Title: Payment/Income Slip -->
        <div class="document-title">
            PAYMENT/INCOME SLIP
        </div>

        @yield('slipContent')

        <div class="signature-block">
        <!-- Approval Grid -->
        <table class="approval-grid">
            <tr>
                <td rowspan="{{ $slip->transaction_type === \App\Models\PaymentSlip::TYPE_GENERAL ? 3 : 2 }}" class="label-cell">APPROVAL</td>
                <th>INCHARGE</th>
                <th>PASSED</th>
                <th>PASSED</th>
                <th>PASSED</th>
                <th>DIRECTOR</th>
            </tr>
            <tr>
                @if ($slip->transaction_type === \App\Models\PaymentSlip::TYPE_GENERAL)
                    <td class="signature-space">&nbsp;</td>
                    <td class="signature-space">&nbsp;</td>
                    <td class="signature-space">&nbsp;</td>
                    <td class="signature-space">&nbsp;</td>
                    <td class="signature-space">&nbsp;</td>
                @else
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                @endif
            </tr>
            @if ($slip->transaction_type === \App\Models\PaymentSlip::TYPE_GENERAL)
                <tr>
                    <td class="signer-name">{{ $slip->approval_incharge ?: ($slip->creator?->name ?: ' ') }}</td>
                    <td class="signer-name">{{ $slip->approval_passed_1 ?: ' ' }}</td>
                    <td class="signer-name">{{ $slip->approval_passed_2 ?: ' ' }}</td>
                    <td class="signer-name">{{ $slip->approval_passed_3 ?: ' ' }}</td>
                    <td class="signer-name">{{ $slip->approval_director ?: ' ' }}</td>
                </tr>
            @endif
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
    </div>
</body>
</html>
