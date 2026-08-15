<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Imports\RegisterStatementImportAction;
use App\Actions\Imports\UploadedStatement;
use App\Http\Requests\Import\UpdateImportRequest;
use App\Http\Requests\PdfRequest;
use App\Models\Import;
use App\Repositories\Contracts\ImportRepositoryContract;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Statement uploads and the record of them. Orchestrates; decides nothing.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly ImportRepositoryContract $imports,
        private readonly RegisterStatementImportAction $registerImport,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->imports->paginateForUser(
            (int) Auth::id(),
            (int) $request->input('per_page', 10),
            (int) $request->input('page', 1),
        );

        return response()->json($page->through(fn (Import $item): array => [
            'id' => $item->id,
            'name' => $item->name,
            'financial_entity' => $item->financialEntity?->name,
            'payment_service' => $item->paymentService?->name,
            'url' => Storage::url('files/'.$item->name),
            'created_at' => Carbon::parse($item->created_at)->format('Y-m-d H:i:s'),
            'status' => $item->status->value,
            'status_label' => $item->status->label(),
            'extension' => $item->extension,
        ]));
    }

    public function store(PdfRequest $request): JsonResponse
    {
        try {
            /** @var \Illuminate\Http\UploadedFile $file */
            $file = $request->file('file');
            $financialEntityId = (int) $request->input('financial');

            $code = $this->imports->financialEntityCode($financialEntityId);

            $this->registerImport->execute(
                UploadedStatement::store($file, 'files/'.$code),
                (int) Auth::id(),
                $financialEntityId,
                $request->input('password'),
            );

            return response()->json([
                'status' => 'ok',
                'message' => 'Tu archivo ha sido recibido y está siendo procesado.',
            ]);
        } catch (\Throwable $th) {
            Log::error('Error al despachar importación: '.$th->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo recibir el archivo.',
            ], 500);
        }
    }

    public function update(UpdateImportRequest $request, int $id): JsonResponse
    {
        $import = $this->imports->findOwned($id, (int) Auth::id());

        if (! $import) {
            return response()->json(['message' => 'Import no encontrado'], 404);
        }

        return response()->json($this->imports->update($import, $request->validated()));
    }

    public function destroy(int $id): JsonResponse
    {
        $import = $this->imports->findOwned($id, (int) Auth::id());

        if (! $import) {
            return response()->json(['message' => 'Import no encontrado'], 404);
        }

        return response()->json($this->imports->delete($import));
    }

    public function getBank(): JsonResponse
    {
        return response()->json($this->imports->financialEntities());
    }

    public function getService(): JsonResponse
    {
        return response()->json($this->imports->paymentServices());
    }

    /**
     * Stream the file the user originally uploaded.
     *
     * This returns the raw bank statement or Yape export, not a derived summary, so the
     * ownership scope here is the difference between a private document and a public one.
     */
    public function download(int $id): StreamedResponse|JsonResponse
    {
        $import = $this->imports->findOwned($id, (int) Auth::id());

        if (! $import) {
            return response()->json(['message' => 'Import no encontrado'], 404);
        }

        if (! Storage::exists($import->path)) {
            // The row survives its file: a cleanup, a failed upload or a disk migration
            // leaves the record pointing at nothing. Report it instead of throwing a 500.
            return response()->json(['message' => 'El archivo del import ya no está disponible'], 410);
        }

        return Storage::download($import->path, $import->name);
    }
}
