<?php

declare(strict_types=1);

namespace App\Actions\Income;

use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AddIncomeSourceVersionAction
{
    public function __construct(
        private readonly IncomeSourceRepositoryContract $sources,
    ) {}

    /**
     * Agrega una version y cierra la anterior el dia previo al nuevo inicio.
     *
     * Las versiones son consecutivas: no se puede agregar una version anterior o
     * solapada con otra. El mes es la unidad; la UI manda "YYYY-MM".
     *
     * @param  array{amount: numeric-string|float|int, effective_from: string}  $data
     */
    public function execute(IncomeSource $source, array $data): IncomeSource
    {
        return DB::transaction(function () use ($source, $data): IncomeSource {
            $newFrom = CreateIncomeSourceAction::startOfMonth($data['effective_from']);
            $latest = $this->sources->latestVersion($source);

            if ($latest !== null && $newFrom <= CarbonImmutable::parse((string) $latest->effective_from)->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'La nueva vigencia debe ser posterior a la ultima version.',
                ]);
            }

            if ($latest !== null && $latest->effective_to !== null && $newFrom <= CarbonImmutable::parse((string) $latest->effective_to)->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_from' => 'La nueva vigencia se solapa con una version ya cerrada.',
                ]);
            }

            if ($latest !== null && $latest->effective_to === null) {
                $this->sources->closeOpenVersion($source, CarbonImmutable::parse($newFrom)->subDay()->toDateString());
            }

            $this->sources->addVersion($source, [
                'amount' => number_format((float) $data['amount'], 2, '.', ''),
                'effective_from' => $newFrom,
                'effective_to' => null,
            ]);

            return $source->load('versions');
        });
    }
}
