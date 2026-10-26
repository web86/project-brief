<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

class PdfProjectExporter
{
    public function render(array $document): string
    {
        $cjk = ExportFonts::needsCjk($document);
        $temporary = null;
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([base_path('vendor/dompdf/dompdf/lib/fonts'), resource_path('fonts')]);
        $options->setFontCache(sys_get_temp_dir());
        if ($cjk) {
            $temporary = sys_get_temp_dir().'/projectbrief-cjk-'.bin2hex(random_bytes(16));
            if (! mkdir($temporary, 0700)) {
                throw new RuntimeException('Cannot create temporary font cache.');
            }
            $options->setFontDir($temporary);
            $options->setFontCache($temporary);
        }
        try {
            $pdf = new Dompdf($options);
            if ($cjk) {
                $families = ExportFonts::pdfFamilies($document);
                foreach ($families as $family => $faces) {
                    $pdf->getFontMetrics()->setFontFamily($family, $faces);
                }
            }
            $pdf->setPaper('A4');
            $pdf->loadHtml(view('exports.specification', ['document' => $document, 'cjk' => $cjk, 'cjkFontFamilies' => array_keys($families ?? [])])->render(), 'UTF-8');
            $pdf->render();

            return $pdf->output();
        } finally {
            unset($pdf);
            gc_collect_cycles();
            if ($temporary) {
                foreach (glob($temporary.'/*') as $file) {
                    unlink($file);
                }
                rmdir($temporary);
            }
        }
    }
}
