<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdfRequest;
use App\Jobs\ProcessPdfImport;
use App\Jobs\ProcessYapeImport;
use App\Models\FinancialEntity;
use App\Models\Import;
use App\Models\PaymentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{

    public function __construct() {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $userId = Auth::id();

        $query = Import::select([
            'id',
            'name',
            'financial_entity_id',
            'payment_service_id',
            'status',
            'extension',
            'created_at',
        ])
            ->with([
                'financialEntity:id,name',
                'paymentService:id,name',
            ])
            ->where('user_id', $userId);

        $data = $query->paginate($perPage, ['*'], 'page', $page);

        $data->getCollection()->transform(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'financial_entity' => $item->financialEntity?->name,
                'payment_service' => $item->paymentService?->name,
                'url' => Storage::url('files/' . $item->name),
                'created_at' => Carbon::parse($item->created_at)->format('Y-m-d H:i:s'),
                'status' => $item->status,
                'extension' => $item->extension
            ];
        });

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

            $import = new Import();
            $import->name = $originalName;
            $import->extension = $file->getClientOriginalExtension();
            $import->path =  $storedPath;
            $import->mime = $file->getMimeType();
            $import->size = $file->getSize();
            $import->user_id = $userId;
            $import->financial_entity_id = $accountId;
            $import->status = 'pending';
            $import->save();

            ProcessPdfImport::dispatch(
                $import->id,
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

    public function storeYape(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|mimes:pdf']);
        $user = Auth::user();
        $file = $request->file('file');
        $userName = $user->name;
        $path = $file->store('yape_imports', 'private');

        ProcessYapeImport::dispatch($user, $path, $userName);

        return response()->json(['message' => 'Tu reporte de Yape está siendo procesado.'], 202);
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

    public function download(int $id): StreamedResponse
    {
        $import = Import::find($id);
        return Storage::download($import->path, $import->name);
    }
}
