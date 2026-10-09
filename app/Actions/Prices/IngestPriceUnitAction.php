<?php

declare(strict_types=1);

namespace App\Actions\Prices;

use App\Enums\PriceRunStatus;
use App\Exceptions\Prices\UnknownPriceSource;
use App\Models\PriceIngestionRun;
use App\Repositories\Contracts\PriceRepositoryContract;
use App\Services\Prices\PriceSourceRegistry;
use App\Services\Prices\Sources\PriceFetchUnit;
use App\Services\Prices\Sources\PriceSourceLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingests one unit of work of one source and writes exactly one run-log row for
 * it (spec "Ingestion run log").
 *
 * The kill switch is checked here, at the moment of execution, and this is what
 * `IngestPriceUnit::handle()` calls: a job queued while the source was on and
 * executed after it went off finds the flag down, writes a `skipped` row and
 * makes no request. A failure is a `failed` row plus a report to Sentry and
 * nothing else — it never raises to the caller, never touches the observations
 * already stored, and never stops the other units of the same run.
 */
final class IngestPriceUnitAction
{
    private const ERROR_LIMIT = 500;

    public function __construct(
        private readonly PriceRepositoryContract $repository,
        private readonly PriceSourceRegistry $registry,
        private readonly PriceSourceLocator $sources,
    ) {}

    /**
     * @throws UnknownPriceSource before writing anything
     */
    public function execute(string $source, string $unitKey, CarbonImmutable $asOf): PriceIngestionRun
    {
        if (! $this->registry->knows($source)) {
            throw UnknownPriceSource::named($source);
        }

        if (! $this->registry->isEnabled($source)) {
            return $this->repository->recordRun($source, $unitKey, PriceRunStatus::Skipped, details: ['reason' => 'source_disabled']);
        }

        $run = $this->repository->startRun($source, $unitKey);

        try {
            $result = $this->sources->for($source)->fetch(new PriceFetchUnit($unitKey), $asOf);

            if ($result->skipped) {
                return $this->repository->finishRun($run, PriceRunStatus::Skipped, details: $result->details);
            }

            foreach ($result->drafts as $draft) {
                $this->repository->upsertObservation($draft);
            }

            return $this->repository->finishRun(
                $run,
                $result->partial ? PriceRunStatus::Partial : PriceRunStatus::Success,
                rowsWritten: count($result->drafts),
                rowsRejected: count($result->rejections),
                details: $result->details === [] ? null : $result->details,
            );
        } catch (Throwable $e) {
            report($e);

            return $this->repository->finishRun(
                $run,
                PriceRunStatus::Failed,
                error: Str::limit($e->getMessage(), self::ERROR_LIMIT),
            );
        }
    }
}
