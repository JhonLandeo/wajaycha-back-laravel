<?php

declare(strict_types=1);

namespace App\DTOs\Transactions;

use Illuminate\Http\Request;

final readonly class TransactionFilterDTO
{
    public function __construct(
        public int $userId,
        public int $perPage,
        public int $page,
        public ?int $year,
        public ?int $month,
        public ?string $type,
        public ?float $amount,
        public ?string $search,
        public ?string $category,
        public bool $recurring,
        public bool $weekend,
        public bool $workday
    ) {}

    public static function fromRequest(Request $request, int $userId): self
    {
        return new self(
            userId: $userId,
            perPage: (int) $request->input('per_page', 10),
            page: (int) $request->input('page', 1),
            year: $request->filled('year') ? (int) $request->input('year') : null,
            month: $request->filled('month') ? (int) $request->input('month') : null,
            type: $request->input('type'),
            amount: $request->filled('amount') ? (float) $request->input('amount') : null,
            search: $request->input('search'),
            category: $request->input('category') ? (string) $request->input('category') : null,
            recurring: filter_var($request->input('recurring', false), FILTER_VALIDATE_BOOLEAN),
            weekend: filter_var($request->input('weekend', false), FILTER_VALIDATE_BOOLEAN),
            workday: filter_var($request->input('workday', false), FILTER_VALIDATE_BOOLEAN)
        );
    }
}
