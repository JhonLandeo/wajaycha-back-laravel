<?php

declare(strict_types=1);

use App\Services\Prices\Sources\SmalotPdfPageReader;

/**
 * The real reader against a real (generated, tiny) PDF: the adapters are tested
 * with a fake reader because PDFs are never committed, so this is the one place
 * that proves the smalot seam works and filters pages by pattern.
 */
it('returns only the pages whose text matches the pattern, in page order', function () {
    $pdf = new FPDF;
    foreach (['Portada del boletin', 'CUADRO N 19: PRECIOS PROMEDIO MENSUAL', 'Otro cuadro', 'CUADRO N 19 (continuacion)'] as $text) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, $text);
    }

    $path = tempnam(sys_get_temp_dir(), 'pdfreader');
    file_put_contents($path, $pdf->Output('S'));

    try {
        $pages = (new SmalotPdfPageReader)->pagesMatching($path, '/CUADRO\s*N\S{0,2}\s*19\b/u');
    } finally {
        unlink($path);
    }

    expect($pages)->toHaveCount(2)
        ->and($pages[0])->toContain('PRECIOS PROMEDIO MENSUAL')
        ->and($pages[1])->toContain('continuacion');
});
