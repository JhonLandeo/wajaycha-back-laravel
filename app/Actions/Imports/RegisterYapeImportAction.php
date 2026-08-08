<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Jobs\ProcessExcelImport;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Registers an uploaded Yape statement and queues it for processing.
 *
 * The transaction lives here rather than in the controller. Opening one is a decision
 * about where a consistency boundary falls — which rows must appear together or not
 * at all — and ADR-0005 reserves decisions for the layer that owns the rules. A
 * controller that calls `beginTransaction` has stopped orchestrating.
 */
class RegisterYapeImportAction
{
    /**
     * Whichever rows the seeders insert first.
     *
     * These moved here with the use case rather than being repaired, and they are
     * fragile in a specific way: they hold only while the sequence agrees with them.
     * Resolving Yape and BCP by code instead of id would be sturdier, but it is a
     * different question — it can return null, which is a behaviour this change has no
     * test for and no mandate to introduce.
     */
    private const FINANCIAL_BCP_ID = 1;

    private const PAYMENT_SERVICE_YAPE_ID = 1;

    public function execute(UploadedStatement $statement, int $userId): Import
    {
        return DB::transaction(function () use ($statement, $userId): Import {
            $import = new Import;
            $import->name = $statement->originalName;
            $import->extension = $statement->extension;
            $import->path = $statement->storedPath;
            $import->mime = $statement->mime;
            $import->size = $statement->size;
            $import->user_id = $userId;
            $import->payment_service_id = self::PAYMENT_SERVICE_YAPE_ID;
            $import->financial_entity_id = self::FINANCIAL_BCP_ID;
            $import->save();

            // `afterCommit` is what keeps the worker from reading a row that is not
            // there yet. Inside `DB::transaction()` it is not optional decoration.
            ProcessExcelImport::dispatch($import->id, $userId, $statement->storedPath)
                ->afterCommit();

            return $import;
        });
    }
}
