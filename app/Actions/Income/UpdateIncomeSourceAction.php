<?php

declare(strict_types=1);

namespace App\Actions\Income;

use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryContract;

final class UpdateIncomeSourceAction
{
    public function __construct(
        private readonly IncomeSourceRepositoryContract $sources,
    ) {}

    /**
     * @param  array{name: string, kind: string}  $data
     */
    public function execute(IncomeSource $source, array $data): IncomeSource
    {
        return $this->sources->update($source, [
            'name' => $data['name'],
            'kind' => $data['kind'],
        ]);
    }
}
