<?php

declare(strict_types=1);

namespace App\Actions\Capture;

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\DetailResolver;
use App\Services\TransactionAnalyzer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RegisterCapturedTransactionAction
{
    public function __construct(
        protected TransactionAnalyzer $analyzer,
        protected CategorizationService $categorizationService,
        protected DetailResolver $detailResolver
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

        // B. Resolvemos el detalle contra Entity Resolution
        $detail = $this->detailResolver->resolveOrCreate(
            $user->id,
            $descriptionRaw,
            $cleanEntity,
            $features['type'] ?? 'unknown'
        );

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
}
