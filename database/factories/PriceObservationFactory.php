<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceBasis;
use App\Enums\PriceKind;
use App\Models\PriceObservation;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceObservation>
 */
class PriceObservationFactory extends Factory
{
    protected $model = PriceObservation::class;

    /**
     * A fresh Plaza Vea retail observation for a kilogram product.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = now('America/Lima')->toDateString();

        return [
            'product_id' => Product::factory(),
            'source' => 'plazavea',
            'price_kind' => PriceKind::Retail,
            'unit' => 'kg',
            'unit_price' => '8.9000',
            'reference_price' => null,
            'price_min' => null,
            'price_max' => null,
            'sample_size' => 3,
            'basis' => PriceBasis::Measured,
            'period_start' => $day,
            'period_end' => $day,
            'observed_at' => now(),
            'source_ref' => null,
            'is_quarantined' => false,
        ];
    }

    public function wholesale(): static
    {
        return $this->state(fn (): array => [
            'source' => 'emmsa',
            'price_kind' => PriceKind::Wholesale,
        ]);
    }

    public function quarantined(): static
    {
        return $this->state(fn (): array => [
            'source' => 'inei',
            'is_quarantined' => true,
        ]);
    }
}
