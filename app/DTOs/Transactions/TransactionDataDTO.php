<?php

declare(strict_types=1);

namespace App\DTOs\Transactions;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;

final class TransactionDataDTO
{
    public function __construct(
        public readonly float $amount,
        public readonly string $date_operation,
        public readonly string $type_transaction,
        public readonly int $user_id,
        public readonly ?int $category_id = null,
        public readonly ?int $detail_id = null,
        public readonly ?string $detail_description = null,
        public readonly bool $is_manual = true,
        public readonly ?int $transaction_id = null,
        public readonly bool $is_frequent = false,
        public readonly ?string $source_type = null,
        public readonly ?int $tag_id = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function fromStoreRequest(StoreTransactionRequest $request, int $userId): self
    {
        return new self(
            amount: (float) $request->validated('amount'),
            date_operation: (string) $request->validated('date_operation'),
            type_transaction: (string) $request->validated('type_transaction'),
            user_id: $userId,
            category_id: $request->validated('category_id') ? (int) $request->validated('category_id') : null,
            detail_id: $request->validated('detail_id') ? (int) $request->validated('detail_id') : null,
            detail_description: (string) $request->validated('detail_description'),
            is_manual: true
        );
    }

    public static function fromUpdateRequest(UpdateTransactionRequest $request, int $userId, int $transactionId): self
    {
        return new self(
            amount: (float) ($request->validated('amount') ?? 0),
            date_operation: (string) ($request->validated('date_operation') ?? ''),
            type_transaction: (string) ($request->validated('type_transaction') ?? ''),
            user_id: $userId,
            category_id: $request->validated('category_id') ? (int) $request->validated('category_id') : null,
            detail_id: $request->validated('detail_id') ? (int) $request->validated('detail_id') : null,
            detail_description: (string) $request->validated('detail_description'),
            is_manual: true,
            transaction_id: $transactionId,
            is_frequent: (bool) $request->input('is_frequent', false),
            source_type: (string) $request->input('source_type'),
            tag_id: $request->input('tag_id') ? (int) $request->input('tag_id') : null,
            reason: (string) $request->input('reason'),
        );
    }
}
