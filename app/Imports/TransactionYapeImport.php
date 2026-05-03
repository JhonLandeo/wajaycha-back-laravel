<?php

namespace App\Imports;

use App\Models\Detail;
use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Services\TransactionAnalyzer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

HeadingRowFormatter::default('none');

class TransactionYapeImport implements ToModel, WithHeadingRow
{
    protected int $userId;
    protected TransactionAnalyzer $transactionAnalyzer;
    protected CategorizationService $categorizationService;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->transactionAnalyzer = app(TransactionAnalyzer::class);
        $this->categorizationService = app(CategorizationService::class);
    }

    public function headingRow(): int
    {
        return 5;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function model(array $row)
    {
        // Validaciones básicas
        if (empty($row['Fecha de operación']) || empty($row['Origen']) || empty($row['Destino']) || empty($row['Monto']) || empty($row['Tipo de Transacción'])) {
            return null;
        }

        // Parseo de Fechas
        $dateOperation = null;
        $dateString = $row['Fecha de operación'];
        if (Carbon::hasFormat($dateString, 'd/m/Y H:i:s')) {
            $dateOperation = Carbon::createFromFormat('d/m/Y H:i:s', $dateString)->format('Y-m-d H:i:s');
        }

        // Lógica de Duplicados (Transacción)
        $toleranceInSeconds = 60;
        $startDate = Carbon::parse($dateOperation)->subSeconds($toleranceInSeconds);
        $endDate = Carbon::parse($dateOperation)->addSeconds($toleranceInSeconds);

        // Determinamos quién es la contraparte
        $isExpense = $row['Tipo de Transacción'] == 'PAGASTE';
        $descriptionRaw = $isExpense ? $row['Destino'] : $row['Origen'];

        // Verificamos si la transacción YA existe
        $yapeRecord = Transaction::query()
            ->from('transactions as ty')
            ->join('details as d', 'ty.detail_id', '=', 'd.id')
            ->where('message', $row['Mensaje'])
            ->where('d.description', $descriptionRaw)
            ->where('amount', (float) $row['Monto'])
            ->whereBetween('date_operation', [$startDate, $endDate])
            ->where('ty.user_id', $this->userId)
            ->where('ty.source_type', 'import_app')
            ->first();

        if ($yapeRecord) {
            return null;
        }

        $typeTransaction = $isExpense ? 'expense' : 'income';

        // 1. Analizamos para obtener la entidad limpia
        $features = $this->transactionAnalyzer->analyze($descriptionRaw);
        $cleanEntity = $features['entity'];

        // 2. Buscamos el detalle INTELIGENTEMENTE
        $detail = $this->findExistingDetail($cleanEntity);
        Log::info("🔍 Buscando Detalle para Entidad Limpia: {$cleanEntity}. " . ($detail ? "Encontrado ID: {$detail->id}" : "No encontrado."));

        // 3. Si no existe ni parecido, creamos uno nuevo
        if (!$detail) {
            $detail = Detail::create([
                'user_id' => $this->userId,
                'description' => $descriptionRaw,
                'operation_type' => $features['type'],
                'entity_clean' => $cleanEntity
            ]);
            Log::info("🆕 Nuevo Detalle creado: {$descriptionRaw} (Clean: {$cleanEntity})");
        } else {
            if (empty($detail->entity_clean)) {
                $detail->update(['entity_clean' => $cleanEntity]);
            }
        }

        // 4. Categorizamos (Pasando el Mensaje)
        $messageRaw = $row['Mensaje'];

        // IMPORTANTE: Pasamos el mensaje como tercer argumento
        $categoryId = $this->categorizationService->findCategory(
            $this->userId,
            $detail,
            $messageRaw
        );

        return Transaction::create([
            'message' => $messageRaw,
            'amount' => (float) $row['Monto'],
            'date_operation' => $dateOperation,
            'type_transaction' => $typeTransaction,
            'user_id' => $this->userId,
            'detail_id' => $detail->id,
            'category_id' => $categoryId,
            'financial_entity_id' => 1,
            'payment_service_id' => 1,
            'source_type' => 'import_app',
            'is_manual' => false,
        ]);
    }

    /**
     * Busca un detalle existente usando Trigramas sobre la entidad limpia
     */
    private function findExistingDetail(string $cleanEntity): ?Detail
    {
        // Umbral de similitud (ajusta según pruebas, 0.6 suele ser seguro)
        $threshold = 0.6;

        return Detail::where('user_id', $this->userId)
            ->where(function ($query) use ($cleanEntity, $threshold) {
                $query->where('entity_clean', $cleanEntity)
                    ->orWhereRaw('similarity(entity_clean, ?) > ?', [$cleanEntity, $threshold]);
            })
            ->orderByRaw('similarity(entity_clean, ?) DESC', [$cleanEntity])
            ->first();
    }
}
