<?php

declare(strict_types=1);

namespace App\Actions\Imports;

use Illuminate\Http\UploadedFile;

/**
 * A statement file that has already been written to storage.
 *
 * It exists so the action never types `UploadedFile`. That class belongs to HTTP, and
 * a use case that requires one cannot be driven from a console command, a queued job
 * or a test without inventing a request first — the same rule the architecture suite
 * already enforces for `App\Services`.
 *
 * Writing the file is deliberately not the action's job. Storage is not transactional,
 * so a write that happened inside `DB::transaction()` would survive a rollback anyway.
 * Keeping it outside makes that visible rather than implied.
 */
final readonly class UploadedStatement
{
    public function __construct(
        public string $originalName,
        public string $extension,
        public string $storedPath,
        public string $mime,
        public int $size,
    ) {}

    /**
     * Store the upload and describe what was stored.
     *
     * Note the orphan this leaves: if the transaction that follows fails, the file
     * stays on disk with no row pointing at it. That was already true before this
     * change and is left alone — removing it needs a compensating delete and a test
     * for the failure path, which is its own change.
     */
    public static function store(UploadedFile $file, string $folder = 'files/yape'): self
    {
        return new self(
            originalName: $file->getClientOriginalName(),
            extension: $file->getClientOriginalExtension(),
            mime: (string) $file->getMimeType(),
            storedPath: $file->store($folder),
            size: (int) $file->getSize(),
        );
    }
}
