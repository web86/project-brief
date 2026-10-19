<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfProjectExporter
{
    public function render(array $document): string
    {
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot(base_path('vendor/dompdf/dompdf/lib/fonts'));
        $options->setFontCache(sys_get_temp_dir());
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('exports.specification', ['document' => $document])->render(), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
