<?php

namespace App\Http\Controllers;

use App\Imports\TransactionYapeImport;
use App\Jobs\ProcessExcelImport;
use App\Models\TransactionYape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;


class TransactionYapeController extends Controller
{
    const FINANCIAL_BCP_ID = 1;
    const PAYMENT_SERVICE_YAPE_ID = 1;
    public function import(Request $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $userId = Auth::id();
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $size = $file->getSize();
            $folder = 'files/yape';
            $storedPath = $file->store($folder);
            $mime = Storage::mimeType($file);

            DB::beginTransaction();
            $import = DB::table('imports')->insert([
                'name' => $originalName,
                'extension' => $extension,
                'path' => $storedPath,
                'mime' => $mime,
                'url' => null,
                'size' => $size,
                'user_id' => $userId,
                'financial_id' => self::FINANCIAL_BCP_ID,
                'financial_entity_id' => self::FINANCIAL_BCP_ID,
                'created_at' => now()
            ]);
            ProcessExcelImport::dispatch($import, $userId, $storedPath);
            DB::commit();
            return response()->json(['status' => 'ok']);
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}
