<?php

declare(strict_types=1);

namespace App\Services\Prices\Parsers;

use App\DTOs\Prices\GmmlBulletin;
use App\DTOs\Prices\GmmlRow;
use App\Exceptions\Prices\PriceSourceFormatChanged;
use Carbon\CarbonImmutable;

/**
 * MIDAGRI's "Boletin diario" of the Gran Mercado Mayorista de Lima, as smalot
 * hands it over. A row reads
 *
 *     Aji Rocoto <tab> 73 65 115 73Cajon 18.0076.2583.7579.82
 *
 * that is: product, four columns of incoming mass (tonnes, or ":" for none),
 * the unit of measure, its equivalent in kg, and three price columns whose
 * header is "Ayer | Hoy | Ultimos 7 dias". The price columns are glued whenever
 * a price has no leading space, so numbers are split after every two-decimal
 * group. The bulletin's own day is "Hoy" (the second price), and its date comes
 * from the header line, not from the file name.
 *
 * Pure: text in, a bulletin out.
 */
final class GmmlBulletinParser
{
    private const MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'setiembre' => 9, 'septiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
    ];

    private const MASS = '(?:\d+(?:[.,]\d+)?|:)';

    public function parse(string $text): GmmlBulletin
    {
        $rows = [];

        $pattern = '/^(?<label>.+?)\s*(?<m1>'.self::MASS.')\s+(?<m2>'.self::MASS.')\s+(?<m3>'.self::MASS.')\s+(?<m4>'.self::MASS.')'
            .'(?<unit>[A-Za-zÁÉÍÓÚÑáéíóúñ]+(?: [A-Za-zÁÉÍÓÚÑáéíóúñ]+)*)\s*(?<nums>\d[\d. ]*)$/u';

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match($pattern, rtrim($line), $m) !== 1) {
                continue;
            }

            $numbers = preg_split('/\s+/', trim((string) preg_replace('/(\d+\.\d{2})(?=\d)/', '$1 ', $m['nums']))) ?: [];

            if (count($numbers) !== 4) {
                continue;
            }

            $rows[] = new GmmlRow(
                label: trim((string) preg_replace('/\s+\d+\/\s*$/u', '', trim($m['label']))),
                unit: $m['unit'],
                equivalentKg: $numbers[0],
                priceYesterday: $numbers[1],
                priceToday: $numbers[2],
                priceWeek: $numbers[3],
            );
        }

        return new GmmlBulletin($this->date($text), $rows);
    }

    private function date(string $text): CarbonImmutable
    {
        $months = implode('|', array_keys(self::MONTHS));

        if (preg_match('/\b(\d{1,2})\s+de\s+('.$months.')\s+de\s+(\d{4})\b/iu', $text, $m) !== 1) {
            throw PriceSourceFormatChanged::missing('gmml', 'the bulletin date');
        }

        return CarbonImmutable::create((int) $m[3], self::MONTHS[strtolower($m[2])], (int) $m[1], 0, 0, 0, 'America/Lima');
    }
}
