<?php

declare(strict_types=1);

namespace App\Actions\Capture;

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Enums\SourceType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorizationService;
use App\Services\DetailResolver;
use App\Services\Reconciliation\DuplicateCandidateDetector;
use App\Services\TransactionAnalyzer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RegisterCapturedTransactionAction
{
    public function __construct(
        protected TransactionAnalyzer $analyzer,
        protected CategorizationService $categorizationService,
        protected DetailResolver $detailResolver,
        protected DuplicateCandidateDetector $duplicates
    ) {}

    public function execute(User $user, ParsedReceiptDTO $dto): Transaction
    {
        $isExpense = $dto->type === 'expense';
        $descriptionRaw = $isExpense ? ($dto->destination ?? '') : ($dto->origin ?? '');

        if (empty(trim($descriptionRaw)) || strtolower($descriptionRaw) === 'usuario') {
            // Este texto quedo inexacto cuando se extrajo el puerto de captura: la
            // accion ya no es de WhatsApp y hoy la usan los dos canales. Aun asi NO se
            // cambia, y la razon esta medida: similarity('desconocido whatsapp',
            // 'desconocido') da 0.571 contra el umbral 0.6 de DetailResolver, asi que
            // renombrarlo PARTE el agrupamiento historico en dos Detail distintos y
            // deja huerfana cualquier regla aprendida sobre el viejo. Corregirlo bien
            // exige migrar los datos, y eso es un cambio propio, no una limpieza.
            $descriptionRaw = 'Desconocido WhatsApp';
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
        //
        // Cuando Gemini no leyo una fecha del comprobante, lo que se guarda es la
        // hora de la captura: cuando el usuario mando la foto, no cuando se movio
        // la plata. Se marca, porque la conciliacion decide por cercania temporal
        // y sobre un relleno esa cercania no significa nada.
        $hasOperationDate = (bool) $dto->dateOperation;

        $dateOp = $hasOperationDate
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
            'is_date_estimated' => ! $hasOperationDate,
            'message' => $dto->message,
            'is_manual' => true,
            // Sin esto la fila cae al default de la columna, `manual`, que es lo que
            // escribe la interfaz cuando alguien tipea un movimiento a mano. Una foto
            // de comprobante y algo tipeado no son la misma procedencia, y mientras
            // compartieron etiqueta nada aguas abajo pudo distinguirlas — incluido el
            // control de duplicados del import, que filtra por `source_type`.
            'source_type' => SourceType::CAPTURE->value,
        ]);

        // El mismo pago llega despues por la exportacion de Yape o por el estado de
        // cuenta. Se anota la sospecha; confirmarla es del usuario.
        $this->duplicates->inspect($transaction);

        // Esta linea escribia el monto y el nombre del comercio. Entre las dos
        // cosas reconstruian el movimiento entero en texto plano, y con
        // `breadcrumbs.logs` prendido en Sentry se iban del servidor. Los ids
        // responden las mismas preguntas operativas sin decir en que gasto nadie.
        Log::info("✅ Captura registrada: transacción {$transaction->id} del usuario {$user->id}"
            ." -> detail {$detail->id}, categoría ".($categoryId ?? 'sin asignar').'.');

        return $transaction;
    }
}
