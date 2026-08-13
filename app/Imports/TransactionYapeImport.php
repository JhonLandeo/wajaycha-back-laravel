<?php

namespace App\Imports;

use App\Enums\SourceType;
use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Services\DetailResolver;
use App\Services\Reconciliation\DuplicateCandidateDetector;
use App\Services\TransactionAnalyzer;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

HeadingRowFormatter::default('none');

class TransactionYapeImport implements ToModel, WithHeadingRow
{
    protected int $userId;

    protected TransactionAnalyzer $transactionAnalyzer;

    protected CategorizationService $categorizationService;

    protected DetailResolver $detailResolver;

    protected DuplicateCandidateDetector $duplicateDetector;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->transactionAnalyzer = app(TransactionAnalyzer::class);
        $this->categorizationService = app(CategorizationService::class);
        $this->detailResolver = app(DetailResolver::class);
        $this->duplicateDetector = app(DuplicateCandidateDetector::class);
    }

    public function headingRow(): int
    {
        return 5;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function model(array $row)
    {
        // Validaciones básicas
        if (empty($row['Fecha de operación']) || empty($row['Origen']) || empty($row['Destino']) || empty($row['Monto']) || empty($row['Tipo de Transacción'])) {
            return null;
        }

        // Parseo de Fechas
        $dateString = $row['Fecha de operación'];
        if (! Carbon::hasFormat($dateString, 'd/m/Y H:i:s')) {
            // `date_operation` es NOT NULL. Dejar pasar la fila con la fecha en
            // null hacía que el insert abortara con SQLSTATE 23502 y se cayera
            // el import completo, no solo la fila defectuosa. Una fecha que no
            // se puede parsear deja la fila inutilizable igual que un campo
            // vacío, así que se descarta con el mismo criterio.
            return null;
        }

        $dateOperation = Carbon::createFromFormat('d/m/Y H:i:s', $dateString)->format('Y-m-d H:i:s');

        // Determinamos quién es la contraparte
        $isExpense = $row['Tipo de Transacción'] == 'PAGASTE';
        $descriptionRaw = $isExpense ? $row['Destino'] : $row['Origen'];
        $typeTransaction = $isExpense ? 'expense' : 'income';
        $messageRaw = $row['Mensaje'];

        // 1. Analizamos para obtener la entidad limpia
        $features = $this->transactionAnalyzer->analyze($descriptionRaw);
        $cleanEntity = $features['entity'];

        // 2. Resolvemos el detalle contra Entity Resolution
        //
        // Resolver ANTES de buscar duplicados, aunque cueste una consulta de más en
        // la fila repetida. El control comparaba `details.description` contra el
        // texto crudo del Excel, y esos dos valores no son el mismo: Entity
        // Resolution empareja por similitud, asi que un "Brayan Rojas O." del
        // archivo termina colgado del Detail "Yape Brayan Roj". La busqueda pedia
        // un Detail llamado como el texto crudo, no lo encontraba, e insertaba de
        // nuevo. Comparar por `detail_id` compara contra lo que realmente se
        // guardo.
        $detail = $this->detailResolver->resolveOrCreate(
            $this->userId,
            $descriptionRaw,
            $cleanEntity,
            $features['type']
        );

        if ($this->alreadyImported($detail->id, $messageRaw, (float) $row['Monto'], $dateOperation)) {
            return null;
        }

        // IMPORTANTE: Pasamos el mensaje como tercer argumento
        $categoryId = $this->categorizationService->findCategory(
            $this->userId,
            $detail,
            $messageRaw
        );

        $transaction = Transaction::create([
            'message' => $messageRaw,
            'amount' => (float) $row['Monto'],
            'date_operation' => $dateOperation,
            'type_transaction' => $typeTransaction,
            'user_id' => $this->userId,
            'detail_id' => $detail->id,
            'category_id' => $categoryId,
            'financial_entity_id' => 1,
            'payment_service_id' => 1,
            'source_type' => SourceType::IMPORT_APP->value,
            'is_manual' => false,
        ]);

        // El control de duplicados de arriba solo mira filas `import_app`, y esa
        // restriccion es correcta: compara `message` y la descripcion literal, textos
        // que solo coinciden entre dos exportaciones de Yape. Un movimiento que ya
        // entro por una foto de Telegram trae la descripcion que leyo Gemini, jamas
        // igual a la que Yape registro, asi que ninguna comparacion de texto lo
        // encuentra. Ese cruce se decide por monto, tipo y fecha, y no aqui.
        $this->duplicateDetector->inspect($transaction);

        return $transaction;
    }

    /**
     * Whether this exact movement already came in through a previous import.
     *
     * Guards against re-importing a file that overlaps one already loaded, which
     * is the normal way a person uses this: export the last three months, import,
     * export again next month.
     *
     * The message is compared through `coalesce`, not with `=`. The Yape export
     * leaves that cell empty on plenty of movements and has written the emptiness
     * two different ways across versions of the file: NULL in the older ones, an
     * empty string in the newer. Plain equality fails on both halves of that —
     * `NULL = ''` is not false but NULL, and so is `NULL = NULL`, so two identical
     * blank messages never recognised each other either. For any movement without
     * a note this check silently did nothing, which accounts for 43 of the 142
     * duplicate pairs measured in production.
     *
     * `IS NOT DISTINCT FROM` alone would only close the NULL-against-NULL half:
     * a NULL and an empty string genuinely are distinct values, and here they are
     * the same fact written by two versions of one exporter. Normalising both
     * sides is what makes them meet.
     *
     * A message that says something still discriminates. Two payments of the same
     * amount to the same merchant carrying different notes remain two payments.
     *
     * The tolerance stays at 60 seconds: the same movement re-exported can carry
     * truncated seconds in one file and full seconds in another.
     */
    private function alreadyImported(int $detailId, ?string $message, float $amount, string $dateOperation): bool
    {
        $tolerance = 60;

        return Transaction::query()
            ->where('user_id', $this->userId)
            ->where('source_type', SourceType::IMPORT_APP->value)
            ->where('detail_id', $detailId)
            ->where('amount', $amount)
            ->whereRaw("coalesce(message, '') = coalesce(?, '')", [$message])
            ->whereBetween('date_operation', [
                Carbon::parse($dateOperation)->subSeconds($tolerance),
                Carbon::parse($dateOperation)->addSeconds($tolerance),
            ])
            ->exists();
    }
}
