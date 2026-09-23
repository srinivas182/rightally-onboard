<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders a signed agreement to PDF (US Letter) with Dompdf.
 * Fonts are bundled in resources/fonts so the PDF looks the same on any server.
 */
final class ContractPdf
{
    public function __construct(private readonly ContractRenderer $renderer) {}

    public function render(Contract $contract): string
    {
        $html = view('pdf.contract', [
            'contract' => $contract,
            'customer' => $contract->customer,
            'body' => $contract->rendered_html ?: $this->renderer->body($contract),
            'companySignature' => $this->renderer->companySignatureDataUri($contract),
            'clientSignature' => $this->renderer->clientSignatureDataUri($contract),
            'logo' => $this->logoDataUri(),
            'fontDir' => resource_path('fonts'),
        ])->render();

        $fontCache = storage_path('app/dompdf');
        if (! is_dir($fontCache)) {
            mkdir($fontCache, 0775, true);
        }

        $options = new Options;
        $options->setChroot([resource_path('fonts'), public_path('brand')]);
        $options->setFontDir($fontCache);
        $options->setFontCache($fontCache);
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('SourceSerif');
        $options->setDpi(96);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $footer = $contract->company_legal_name.($contract->company_dba ? ' d/b/a '.$contract->company_dba : '').'   ·   '.$contract->number;
        $canvas->page_text(54, 760, $footer, $font, 7.5, [0.37, 0.42, 0.51]);
        $canvas->page_text(510, 760, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.37, 0.42, 0.51]);

        return (string) $pdf->output();
    }

    private function logoDataUri(): ?string
    {
        $path = public_path('brand/logo.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }
}
