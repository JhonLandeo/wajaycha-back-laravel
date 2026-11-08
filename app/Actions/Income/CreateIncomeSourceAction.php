<?php

declare(strict_types=1);

namespace App\Actions\Income;

use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CreateIncomeSourceAction
{
    public function __construct(
        private readonly IncomeSourceRepositoryContract $sources,
    ) {}

    /**
     * @param  array{name: string, kind: string, amount: numeric-string|float|int, effective_from: string}  $data
     */
    public function execute(int $userId, array $data): IncomeSource
    {
        return DB::transaction(function () use ($userId, $data): IncomeSource {
            $source = $this->sources->create([
                'user_id' => $userId,
                'name' => $data['name'],
                'kind' => $data['kind'],
            ]);

            $this->sources->addVersion($source, [
                'amount' => number_format((float) $data['amount'], 2, '.', ''),
                'effective_from' => self::startOfMonth($data['effective_from']),
                'effective_to' => null,
            ]);

            return $source->load('versions');
        });
    }

    /** "2026-11" -> "2026-11-01". */
    public static function startOfMonth(string $month): string
    {
        return CarbonImmutable::createFromFormat('Y-m', $month)
            ->startOfMonth()
            ->toDateString();
    }

    /** "2026-11" -> "2026-11-30". */
    public static function endOfMonth(string $month): string
    {
        return CarbonImmutable::createFromFormat('Y-m', $month)
            ->endOfMonth()
            ->toDateString();
    }
}
