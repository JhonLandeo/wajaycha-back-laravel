<?php

declare(strict_types=1);

namespace App\Services\Prices\Normalization;

/**
 * Pulls the pack sizes out of a retail product name ("Arroz Extra Bolsa 750g",
 * "Huevos Bandeja 30un", "Leche Lata 390g Paquete 6un").
 *
 * This is where grams become kilograms and millilitres become litres; the
 * {@see PriceNormalizer} then only ever sees kg, l and units. A name can carry
 * several sizes, so every one is returned in order of appearance and the caller
 * picks the one that fits the product it prices. A bare "x kg" or "x und." with
 * no number carries no size — that is a price per unit of measure, which the
 * catalogue marks with `measurementUnit`, not the name.
 *
 * Pure: one string in, a list of values out.
 */
final class PackSizeParser
{
    private const NUMBER = '(?<![\d.,])(\d+(?:[.,]\d+)?)';

    private const UNITS = 'kilos?|kg|gramos?|grs|gr|g|ml|litros?|lts|lt|l|unidades|unidad|unid|und|un|u';

    private const SCALE = 6;

    /**
     * @return list<Measure>
     */
    public function measures(string $name): array
    {
        preg_match_all('/'.self::NUMBER.'\s*('.self::UNITS.')\b/iu', $name, $matches, PREG_SET_ORDER);

        $measures = [];

        foreach ($matches as $match) {
            $quantity = str_replace(',', '.', $match[1]);

            $measures[] = match (strtolower($match[2])) {
                'kg', 'kilo', 'kilos' => new Measure($this->trim($quantity), Dimension::Mass),
                'g', 'gr', 'grs', 'gramo', 'gramos' => new Measure($this->trim(bcdiv($quantity, '1000', self::SCALE)), Dimension::Mass),
                'ml' => new Measure($this->trim(bcdiv($quantity, '1000', self::SCALE)), Dimension::Volume),
                'l', 'lt', 'lts', 'litro', 'litros' => new Measure($this->trim($quantity), Dimension::Volume),
                default => new Measure($this->trim($quantity), Dimension::Count),
            };
        }

        return $measures;
    }

    /** "0.750000" to "0.75", "1.0" to "1", "30" stays "30". */
    private function trim(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') : $quantity;
    }
}
