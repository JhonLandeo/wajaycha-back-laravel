<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdfRequest;
use App\Jobs\ProcessPdfImport;
use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{

    public function __construct(private PdfController $pdfController) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $userId = Auth::id();
        $query = Import::with(['financialEntity', 'paymentService'])
            ->where('user_id', $userId);

        $data = $query->paginate($perPage, ['*'], 'page', $page);

        foreach ($data->items() as $item) {
            $item->url = Storage::url('files/' . $item->name);
        }

        return response()->json($data);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PdfRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $financialCode = FinancialEntity::where('id', $request->financial)
                ->value('code');

            $folder = 'files/' . $financialCode;
            $storedPath = $file->store($folder);
            $userId = Auth::id();
            $year = (int)substr($originalName, 6, 4);
            $accountId = $request->financial;

            $import = Import::create([
                'name' => $originalName,
                'extension' => $file->getClientOriginalExtension(),
                'path' => $storedPath,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'user_id' => $userId,
                'financial_entity_id' => $accountId,
                'status' => 'pending'
            ]);

            ProcessPdfImport::dispatch(
                $import,
                $userId,
                $storedPath,
                $accountId,
                $year,
                $request->password
            );

            return response()->json([
                'status' => 'ok',
                'message' => 'Tu archivo ha sido recibido y está siendo procesado.'
            ]);
        } catch (\Throwable $th) {
            Log::error("Error al despachar importación: " . $th->getMessage());
            return response()->json(['status' => 'error', 'message' => 'No se pudo recibir el archivo.'], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Import $import): JsonResponse
    {
        $data = $import->update($request->all());
        return response()->json($data);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Import $import): JsonResponse
    {
        $data = $import->delete();
        return response()->json($data);
    }


    public function getBank(): JsonResponse
    {
        $data = FinancialEntity::get();
        return response()->json($data);
    }

    public function getService(): JsonResponse
    {
        $data = PaymentService::get();
        return response()->json($data);
    }

    public function download($id)
    {
        $import = Import::find($id);
        return Storage::download($import->path, $import->name);
    }
}
