<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Settings\SettingsService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Branded PDF invoice/receipt for any charge. Rendered from our records;
 * a copy is stored when attached to an email.
 */
final class InvoicePdf
{
    public function __construct(private readonly SettingsService $settings) {}

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('customer', 'contract', 'payments');
        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'customer' => $invoice->customer,
            'company' => $this->settings->group('company'),
            'payment' => $invoice->payments->whereIn('status', ['succeeded', 'refunded', 'disputed'])->sortByDesc('id')->first(),
            'paid' => $invoice->status === InvoiceStatus::Paid,
            'logo' => is_file(public_path('brand/logo.png')) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('brand/logo.png'))) : null,
            'fontDir' => resource_path('fonts'),
        ])->render();

        $cache = storage_path('app/dompdf');
        if (! is_dir($cache)) {
            mkdir($cache, 0775, true);
        }
        $options = new Options;
        $options->setChroot([resource_path('fonts')]);
        $options->setFontDir($cache);
        $options->setFontCache($cache);
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** Stores the PDF on the private disk and returns an attachment for EmailSender. */
    public function attachment(Invoice $invoice): array
    {
        $path = "invoices/{$invoice->customer->uuid}/{$invoice->number}.pdf";
        Storage::disk('local')->put($path, $this->render($invoice));

        return ['path' => $path, 'name' => ($invoice->status === InvoiceStatus::Paid ? 'Receipt' : 'Invoice')."-{$invoice->number}.pdf"];
    }

    public function filename(Invoice $invoice): string
    {
        return ($invoice->status === InvoiceStatus::Paid ? 'RightAlly-Receipt-' : 'RightAlly-Invoice-').$invoice->number.'.pdf';
    }
}
