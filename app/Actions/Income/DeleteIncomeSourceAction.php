<?php

declare(strict_types=1);

namespace App\Actions\Income;

use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryContract;

final class DeleteIncomeSourceAction
{
    public function __construct(
        private readonly IncomeSourceRepositoryContract $sources,
    ) {}

    public function execute(IncomeSource $source): void
    {
        $this->sources->delete($source);
    }
}
