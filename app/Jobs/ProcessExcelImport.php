<?php

namespace App\Jobs;

use App\Imports\TransactionYapeImport;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ProcessExcelImport implements ShouldQueue
{
      use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $userId;
    protected string $filePath;
    protected int $importId;
    public function __construct(int $importId, int $userId, string $filePath)
    {
        $this->importId = $importId;
        $this->userId = $userId;
        $this->filePath = $filePath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Import::where('id', $this->importId)->update(['status' => 'processing']);
        try {
            Excel::import(new TransactionYapeImport($this->userId), $this->filePath);
            Import::where('id', $this->importId)->update(['status' => 'completed']);
        } catch (\Throwable $th) {
            Log::error('Error processing Excel import for user ' . $this->userId . ': ' . $th->getMessage());
            Import::where('id', $this->importId)->update([
                'status' => 'failed',
                'error_message' => $th->getMessage()
            ]);
        }
    }
}
