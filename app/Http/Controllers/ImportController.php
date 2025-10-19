<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdfRequest;
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

        $query = DB::table('imports as i')
            ->select('i.*', 'fe.name as financial_name')
            ->leftJoin('financial_entities as fe', 'financial_id', '=', 'fe.id')
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
            $extension = $file->getClientOriginalExtension();
            $size = $file->getSize();
            $financialCode = DB::table('financial_entities')
                ->where('id', $request->financial)
                ->value('code');
            $folder = 'files/' . $financialCode;
            $storedPath = $file->store($folder);
            $mime = Storage::mimeType($file);
            $userId = Auth::id();

            DB::beginTransaction();
            DB::table('imports')->insert([
                'name' => $originalName,
                'extension' => $extension,
                'path' => $storedPath,
                'mime' => $mime,
                'url' => null,
                'size' => $size,
                'user_id' => $userId,
                'financial_id' => $request->financial,
                'created_at' => now()
            ]);


            $this->pdfController->extractData($request);
            DB::commit();

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error("Error al importar archivo: " . $th->getMessage());
            throw $th;
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
        $import = DB::table('imports')->find($id); 
        return Storage::download($import->path, $import->name);
    }
}
