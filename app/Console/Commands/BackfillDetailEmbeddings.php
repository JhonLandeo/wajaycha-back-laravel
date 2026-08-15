<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Detail;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fills `details.embedding` for the rows that never got one.
 *
 * `EmbeddingService` asked `gemini-embedding-001` for a vector without naming a
 * size and received its default 3072, while the column is `vector(768)`. Every
 * insert failed with `expected 768 dimensions, not 3072`, so the backlog is not
 * a few stragglers — it is every detail ever created. Fixing the service only
 * helps the rows written after it; this command is what repairs the history.
 *
 * Deliberately synchronous rather than dispatching `GenerateEmbeddingForDetail`
 * per row. This is a paid, one-shot repair against an external API, and the
 * queue would hide exactly what the operator needs to see: how many calls are
 * left, how many failed, and how fast they are going. Failures here are counted
 * and reported, not buried in `failed_jobs`.
 *
 * Safe to stop and re-run: the query selects only rows still missing an
 * embedding, so a second run resumes where the first left off. Use `--limit` to
 * work in measured batches and `--dry-run` to see the size of the bill first.
 */
class BackfillDetailEmbeddings extends Command
{
    protected $signature = 'embeddings:backfill
        {--limit= : Máximo de details a procesar en esta corrida}
        {--chunk=100 : Cuántas filas leer por lote de base de datos}
        {--sleep=200 : Milisegundos de espera entre llamadas a Gemini}
        {--retries=2 : Reintentos por detail antes de darlo por fallido}
        {--dry-run : Cuenta cuántas llamadas haría, sin llamar a Gemini}';

    protected $description = 'Genera el embedding faltante de cada detail, por lotes y con control de ritmo';

    public function handle(EmbeddingService $embeddings): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunk = max(1, (int) $this->option('chunk'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $retries = max(0, (int) $this->option('retries'));
        $isDryRun = (bool) $this->option('dry-run');

        if ($limit !== null && $limit < 1) {
            $this->error('--limit debe ser mayor a cero.');

            return self::FAILURE;
        }

        $pending = Detail::query()->whereNull('embedding')->count();
        $planned = $limit === null ? $pending : min($limit, $pending);

        $this->info("Details sin embedding: {$pending}.");

        if ($pending === 0) {
            $this->info('No hay nada que rellenar.');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            // The number of calls is the whole point of the dry run: price it
            // against the Gemini plan in use before committing to a full pass.
            $this->info("[dry-run] Se harían {$planned} llamadas a Gemini, una por detail.");
            $this->info("[dry-run] A {$sleepMs}ms entre llamadas, el piso de duración es de ".
                $this->humanDuration((int) round($planned * $sleepMs / 1000)).'.');

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        $skipped = 0;
        $processed = 0;

        $bar = $this->output->createProgressBar($planned);
        $bar->start();

        // chunkById and not chunk: this loop clears the very condition the query
        // filters on, so offset paging would step over unprocessed rows.
        Detail::query()
            ->whereNull('embedding')
            ->orderBy('id')
            ->chunkById($chunk, function ($details) use (
                $embeddings, $bar, $limit, $sleepMs, $retries,
                &$done, &$failed, &$skipped, &$processed
            ): bool {
                foreach ($details as $detail) {
                    if ($limit !== null && $processed >= $limit) {
                        return false;
                    }

                    $processed++;
                    $bar->advance();

                    $description = trim((string) $detail->description);

                    if ($description === '') {
                        $skipped++;

                        continue;
                    }

                    $vector = $this->generateWithRetries($embeddings, $description, $retries, $sleepMs);

                    if ($vector === null) {
                        $failed++;

                        continue;
                    }

                    $detail->update(['embedding' => $vector]);
                    $done++;

                    $this->rest($sleepMs);
                }

                return true;
            });

        $bar->finish();
        $this->newLine();

        $summary = "Backfill de embeddings: {$processed} details procesados, {$done} escritos, ".
            "{$failed} fallidos, {$skipped} sin descripción. Quedan ".
            Detail::query()->whereNull('embedding')->count().' sin embedding.';

        $this->info($summary);
        Log::info($summary);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `EmbeddingService::generate()` returns null for both a transport failure
     * and an unusable vector, so the two cannot be told apart here. Retrying a
     * bounded number of times covers the transient case without spinning on a
     * description Gemini will never embed.
     *
     * @return array<float>|null
     */
    private function generateWithRetries(
        EmbeddingService $embeddings,
        string $description,
        int $retries,
        int $sleepMs,
    ): ?array {
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $vector = $embeddings->generate($description);

            if ($vector !== null) {
                return $vector;
            }

            if ($attempt < $retries) {
                // Linear backoff on top of the configured pace. A rate limit is
                // the likeliest reason for a null, and retrying at the same
                // cadence that caused it just burns the remaining quota.
                $this->rest($sleepMs * ($attempt + 2));
            }
        }

        return null;
    }

    private function rest(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return "{$minutes}min";
        }

        return intdiv($minutes, 60).'h '.($minutes % 60).'min';
    }
}
