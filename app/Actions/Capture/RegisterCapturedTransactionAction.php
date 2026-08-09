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

        // Esta linea escribia el monto y el nombre del comercio. Entre las dos
        // cosas reconstruian el movimiento entero en texto plano, y con
        // `breadcrumbs.logs` prendido en Sentry se iban del servidor. Los ids
        // responden las mismas preguntas operativas sin decir en que gasto nadie.
        Log::info("✅ Captura registrada: transacción {$transaction->id} del usuario {$user->id}"
            ." -> detail {$detail->id}, categoría ".($categoryId ?? 'sin asignar').'.');

        return $transaction;
    }
}
