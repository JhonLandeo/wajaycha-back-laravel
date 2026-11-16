<?php

declare(strict_types=1);

namespace App\DTOs\Shopping;

/**
 * Valores nutricionales aproximados de una receta o de una entrada del menú.
 *
 * `missing` lista los productos sin datos completos. Un valor con faltantes no
 * es cero: es incompleto, y la UI debe decirlo en vez de presentarlo como una
 * medición exacta (ADR-0031).
 */
final class Nutrition
{
    /**
     * @param  array<int, string>  $missing
     */
    public function __construct(
        public readonly float $kcal,
        public readonly float $proteinG,
        public readonly float $carbsG,
        public readonly float $fatG,
        public readonly array $missing = [],
    ) {}

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0);
    }

    public function add(self $other): self
    {
        return new self(
            $this->kcal + $other->kcal,
            $this->proteinG + $other->proteinG,
            $this->carbsG + $other->carbsG,
            $this->fatG + $other->fatG,
            array_values(array_unique([...$this->missing, ...$other->missing])),
        );
    }

    public function scale(float $factor): self
    {
        return new self(
            $this->kcal * $factor,
            $this->proteinG * $factor,
            $this->carbsG * $factor,
            $this->fatG * $factor,
            $this->missing,
        );
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /**
     * @return array{calories_kcal: int, protein_g: float, carbs_g: float, fat_g: float, is_complete: bool, missing: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'calories_kcal' => (int) round($this->kcal),
            'protein_g' => round($this->proteinG, 1),
            'carbs_g' => round($this->carbsG, 1),
            'fat_g' => round($this->fatG, 1),
            'is_complete' => $this->isComplete(),
            'missing' => $this->missing,
        ];
    }
}
