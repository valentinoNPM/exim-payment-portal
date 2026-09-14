<?php

namespace App\Services;

class InvoiceExtractionValidator
{
    /** Validate document facts without changing application tax calculations. */
    public function review(array $invoice, ?string $sourceText): array
    {
        $warnings = $invoice['warnings'] ?? [];
        $sum = array_sum(array_column($invoice['items'], 'line_total'));
        $subtotal = $invoice['printed_subtotal'];
        $tax = $invoice['printed_tax'];
        $due = $invoice['printed_amount_due'];
        if ($due === null) {
            $warnings[] = 'Total tagihan PDF belum ditemukan; periksa nominal akhir pada dokumen.';
        }

        if ($invoice['currency'] !== 'IDR') {
            $warnings[] = 'Mata uang bukan IDR atau belum diketahui; jangan gunakan nominal ini sebagai Rupiah tanpa pemeriksaan.';
        }
        if ($subtotal === null) {
            $warnings[] = 'Subtotal dokumen belum ditemukan; jumlah item belum dapat direkonsiliasi.';
        } elseif (abs($sum - $subtotal) > 1.0) {
            $warnings[] = 'Jumlah item tidak cocok dengan subtotal dokumen (selisih '.number_format(abs($sum - $subtotal), 2, ',', '.').').';
        }
        if ($subtotal !== null && $tax !== null && $due !== null && abs($subtotal + $tax - $due) > 1.0) {
            $warnings[] = 'Subtotal + pajak berbeda dari total tagihan; periksa diskon/potongan atau pajak yang sudah termasuk.';
        }

        foreach (['invoice_number', 'invoice_date', 'printed_subtotal', 'printed_tax', 'printed_amount_due'] as $field) {
            if ($invoice[$field] === null) {
                continue;
            }
            $evidence = $invoice['evidence'][$field] ?? null;
            $quote = is_array($evidence) ? ($evidence['quote'] ?? null) : null;
            $page = is_array($evidence) ? ($evidence['page'] ?? null) : null;
            $quote = is_string($quote) ? mb_substr($quote, 0, 1000) : null;
            $page = is_int($page) && $page > 0 ? $page : null;
            $verified = false;
            if (is_string($quote) && trim($quote) !== '' && is_int($page) && $page > 0 && $sourceText !== null) {
                $pages = preg_split('/\[PAGE (\d+)\]\n/', $sourceText, -1, PREG_SPLIT_DELIM_CAPTURE);
                $pageText = null;
                for ($index = 1; $index < count($pages); $index += 2) {
                    if ((int) $pages[$index] === $page) {
                        $pageText = $pages[$index + 1];
                        break;
                    }
                }
                $normalize = static fn (string $text): string => preg_replace('/\s+/u', ' ', trim($text));
                $verified = $pageText !== null && str_contains($normalize($pageText), $normalize($quote));
                if (str_starts_with($field, 'printed_')) {
                    $verified = $verified && $this->containsAmount($quote, $invoice[$field]);
                }
                if ($field === 'invoice_date') {
                    $date = new \DateTimeImmutable($invoice['invoice_date']);
                    $formats = ['Y-m-d', 'd/m/Y', 'd/m/y', 'd-m-Y', 'd.m.Y', 'd-M-Y', 'F j, Y', 'j F Y'];
                    $dateFound = false;
                    foreach ($formats as $format) {
                        $dateFound = $dateFound || stripos($quote, $date->format($format)) !== false;
                    }
                    $verified = $verified && $dateFound;
                }
                if ($field === 'invoice_number') {
                    $verified = $verified && str_contains($quote, $invoice['invoice_number']);
                    if (preg_match('/\b(?:tax|npwp|vat)\b/i', $quote)) {
                        $warnings[] = 'Bukti nomor invoice juga memuat label pajak; periksa nomor pada PDF.';
                    }
                }
            }
            $invoice['evidence'][$field] = ['page' => $page, 'quote' => $quote, 'quote_verified' => $verified];
            if (! $verified) {
                $warnings[] = 'Bukti sumber '.$field.' belum dapat diverifikasi; periksa halaman PDF.';
            }
        }

        $invoice['computed_line_total'] = $sum;
        $invoice['warnings'] = array_values(array_unique($warnings));
        $invoice['review_required'] = $invoice['warnings'] !== [];
        $invoice['schema_version'] = 3;

        return $invoice;
    }

    private function containsAmount(string $quote, float $amount): bool
    {
        preg_match_all('/(?<![\d.,])\d+(?:[.,]\d+)*(?![\d.,])/', $quote, $matches);
        foreach ($matches[0] as $number) {
            // Support Indonesian and international printed separators. This
            // checks the cited value, not the semantic correctness of its label.
            $variants = [str_replace(',', '', $number), str_replace(',', '.', str_replace('.', '', $number))];
            foreach ($variants as $variant) {
                if (is_numeric($variant) && abs((float) $variant - $amount) < 0.005) {
                    return true;
                }
            }
        }

        return false;
    }
}
