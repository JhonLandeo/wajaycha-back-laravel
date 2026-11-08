<?php

declare(strict_types=1);

namespace App\Actions\Income;

use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CloseIncomeSourceAction
{
    public function __construct(
        private readonly IncomeSourceRepositoryContract $sources,
    ) {}

    /**
     * Cierra la version abierta en el ultimo dia del mes indicado.
     *
     * @param  array{effective_to: string}  $data
     */
    public function execute(IncomeSource $source, array $data): IncomeSource
    {
        return DB::transaction(function () use ($source, $data): IncomeSource {
            $open = $this->sources->openVersion($source);

            if ($open === null) {
                throw ValidationException::withMessages([
                    'effective_to' => 'La fuente ya esta cerrada.',
                ]);
            }

            $closeAt = CreateIncomeSourceAction::endOfMonth($data['effective_to']);

            if ($closeAt < CarbonImmutable::parse((string) $open->effective_from)->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_to' => 'El cierre no puede ser anterior al inicio de la version vigente.',
                ]);
            }

            $this->sources->closeOpenVersion($source, $closeAt);

            return $source->load('versions');
        });
    }
}
