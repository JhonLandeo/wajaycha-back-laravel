<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Imports\RegisterYapeImportAction;
use App\Actions\Imports\UploadedStatement;
use App\Http\Requests\TransactionYape\ImportYapeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Yape statement uploads. Orchestrates; decides nothing.
 */
class TransactionYapeController extends Controller
{
    public function __construct(
        private readonly RegisterYapeImportAction $registerImport,
    ) {}

    public function import(ImportYapeRequest $request): JsonResponse
    {
        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        $this->registerImport->execute(
            UploadedStatement::store($file),
            (int) Auth::id(),
        );

        return response()->json(['status' => 'ok']);
    }
}
