<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use App\Enums\ImportStatus;
use App\Jobs\ProcessPdfImport;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Registers an uploaded bank statement and queues it for parsing.
 *
 * The sibling of `RegisterYapeImportAction`, and deliberately shaped the same way: the
 * transaction lives here, not in the controller, because deciding where a consistency
 * boundary falls is not orchestration.
 */
class RegisterStatementImportAction
{
    public function execute(
        UploadedStatement $statement,
        int $userId,
        int $financialEntityId,
        ?string $password,
    ): Import {
        return DB::transaction(function () use ($statement, $userId, $financialEntityId, $password): Import {
            $import = new Import;
            $import->name = $statement->originalName;
            $import->extension = $statement->extension;
            $import->path = $statement->storedPath;
            $import->mime = $statement->mime;
            $import->size = $statement->size;
            $import->user_id = $userId;
            $import->financial_entity_id = $financialEntityId;
            $import->status = ImportStatus::PENDING;
            $import->save();

            // `afterCommit` for the reason `RegisterYapeImportAction` documents: every
            // connection in config/queue.php carries `after_commit => false`, so a Redis
            // worker can take the job before this transaction commits, and the job's
            // first act is an update matched on the row that is not there yet.
            ProcessPdfImport::dispatch(
                $import->id,
                $userId,
                $statement->storedPath,
                $financialEntityId,
                $this->statementYear($statement->originalName),
                $this->statementMonth($statement->originalName),
                $password,
            )->afterCommit();

            return $import;
        });
    }

    /**
     * The year the statement covers, read from characters 7-10 of its filename.
     *
     * Carried over unchanged and worth naming rather than leaving inline: it is a
     * positional read of a filename the user chose, so a statement named anything else
     * yields a nonsense year and the parsed movements land in the wrong one. Replacing
     * it means deciding what to do when the year cannot be determined, which is a
     * behaviour this change has no test for.
     */
    private function statementYear(string $originalName): int
    {
        return (int) substr($originalName, 6, 4);
    }

    /**
     * The month the statement was issued, read from characters 5-6 of its
     * filename — `EECC` `06` `2023` `_06703564.pdf`.
     *
     * Positional like `statementYear()` and just as fragile, but the parser needs
     * it: a statement covers the month before the one it is issued in, so the
     * issue month is the only thing that says whether a December movement in a
     * January statement belongs to the previous year. See
     * `StatementLineParser::readDate()`.
     */
    private function statementMonth(string $originalName): int
    {
        return (int) substr($originalName, 4, 2);
    }
}
