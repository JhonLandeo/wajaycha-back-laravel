<?php

declare(strict_types=1);

namespace App\DTOs\Transactions;

use App\Http\Requests\Transaction\SetTransactionSplitsRequest;

final readonly class SetTransactionSplitsDTO
{
    /** @param  TransactionSplitLineDTO[]  $splits */
    public function __construct(
        public int $transactionId,
        public int $userId,
        public array $splits,
    ) {}

    public static function fromRequest(SetTransactionSplitsRequest $request, int $userId, int $transactionId): self
    {
        /** @var array<int, array{category_id: int|string, amount: int|float|string}> $lines */
        $lines = $request->validated('splits');

        return new self(
            transactionId: $transactionId,
            userId: $userId,
            splits: array_map(
                static fn (array $line): TransactionSplitLineDTO => TransactionSplitLineDTO::fromArray($line),
                $lines,
            ),
        );
    }
}
