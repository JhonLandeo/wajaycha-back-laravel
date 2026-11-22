<?php

declare(strict_types=1);

namespace App\DTOs\Transactions;

/**
 * Una línea del reparto: categoría y monto.
 *
 * El monto se normaliza a dos decimales al entrar; los cálculos de la suma se
 * hacen en centavos para no comparar flotantes.
 */
final readonly class TransactionSplitLineDTO
{
    public function __construct(
        public int $categoryId,
        public string $amount,
    ) {}

    /** @param array{category_id: int|string, amount: int|float|string} $line */
    public static function fromArray(array $line): self
    {
        return new self(
            categoryId: (int) $line['category_id'],
            amount: number_format((float) $line['amount'], 2, '.', ''),
        );
    }

    public function amountInCents(): int
    {
        return (int) round((float) $this->amount * 100);
    }
}
