<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Models\Detail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\TransactionAnalyzer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RegisterWhatsAppTransactionAction
{
    public function __construct(
        protected TransactionAnalyzer $analyzer,
        protected CategorizationService $categorizationService
    ) {}

    public function execute(User $user, ParsedReceiptDTO $dto): Transaction
    {
        $isExpense = $dto->type === 'expense';
        $descriptionRaw = $isExpense ? ($dto->destination ?? '') : ($dto->origin ?? '');

        if (empty(trim($descriptionRaw)) || strtolower($descriptionRaw) === 'usuario') {
            $descriptionRaw = "Desconocido WhatsApp";
        }

        // A. Analizamos para obtener la entidad limpia
        $features = $this->analyzer->analyze($descriptionRaw);
        $cleanEntity = $features['entity'];

        // B. Buscamos el detalle INTELIGENTEMENTE con Trigramas
        $detail = $this->findExistingDetail($user->id, $cleanEntity);

        // C. Si no existe, lo creamos
        if (!$detail) {
            $detail = Detail::create([
                'user_id' => $user->id,
                'description' => $descriptionRaw,
                'operation_type' => $features['type'] ?? 'unknown',
                'entity_clean' => $cleanEntity
            ]);
            Log::info("🆕 WhatsApp Action: Nuevo Detalle creado: {$descriptionRaw} (Clean: {$cleanEntity})");
        } else {
            if (empty($detail->entity_clean)) {
                $detail->update(['entity_clean' => $cleanEntity]);
            }
        }

        // D. Categorizamos usando el servicio
        $categoryId = $this->categorizationService->findCategory(
            $user->id,
            $detail,
            $dto->message ?? ''
        );

        // E. Parseamos la fecha
        $dateOp = $dto->dateOperation
            ? Carbon::parse($dto->dateOperation)->format('Y-m-d H:i:s')
            : Carbon::now()->format('Y-m-d H:i:s');

        // F. Guardar transacción final
        $transaction = Transaction::create([
            'user_id' => $user->id,
            'detail_id' => $detail->id,
            'category_id' => $categoryId,
            'amount' => $dto->amount,
            'type_transaction' => $dto->type,
            'date_operation' => $dateOp,
            'message' => $dto->message,
            'is_manual' => true,
        ]);

        Log::info("✅ WhatsApp Action: Transacción registrada (S/ {$dto->amount} " . ($isExpense ? "a" : "de") . " {$descriptionRaw}) -> Cat ID: {$categoryId}");

        return $transaction;
    }

    /**
     * Busca un detalle existente usando Trigramas sobre la entidad limpia.
     */
    private function findExistingDetail(int $userId, string $cleanEntity): ?Detail
    {
        $threshold = 0.6;

        return Detail::where('user_id', $userId)
            ->where(function ($query) use ($cleanEntity, $threshold) {
                $query->where('entity_clean', $cleanEntity)
                    ->orWhereRaw('similarity(entity_clean, ?) > ?', [$cleanEntity, $threshold]);
            })
            ->orderByRaw('similarity(entity_clean, ?) DESC', [$cleanEntity])
            ->first();
    }
}
