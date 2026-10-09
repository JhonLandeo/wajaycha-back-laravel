<?php

declare(strict_types=1);

namespace App\Actions\Prices;

use App\DTOs\Prices\PurgeResult;
use App\Enums\PriceRunStatus;
use App\Exceptions\Prices\UnknownPriceSource;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\PriceSourceRegistry;

/**
 * Takedown for one source (spec "Purge command for takedown"; ADR-0010's
 * 24-48 hour commitment): delete every observation it ever produced, count
 * them, and leave one `purged` row in the run log.
 *
 * It orchestrates and decides nothing beyond "is this a real source": the
 * deletion is the repository's, the enabled check the registry's. Idempotent —
 * a second run deletes zero and still succeeds. The run log is never touched
 * (it holds no third-party prices), and the row written here records how many
 * rows went, never what they said.
 */
final class PurgePriceSourceAction
{
    public function __construct(
        private readonly PriceRepositoryContract $repository,
        private readonly PriceSourceRegistry $registry,
    ) {}

    /**
     * @throws UnknownPriceSource before deleting anything
     */
    public function execute(string $source): PurgeResult
    {
        if (! $this->registry->knows($source)) {
            throw UnknownPriceSource::named($source);
        }

        $deleted = $this->repository->deleteBySource($source);

        $this->repository->recordRun($source, 'all', PriceRunStatus::Purged, details: ['deleted' => $deleted]);

        return new PurgeResult($source, $deleted, $this->registry->isEnabled($source));
    }
}
