<?php

namespace App\Imports;

use App\Models\Detail;
use App\Models\TransactionYape;
use App\Services\CategorizationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;


HeadingRowFormatter::default('none');
class TransactionYapeImport implements ToModel, WithHeadingRow
{
    protected int $userId;

    /**
     * @param int $userId
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }
    /**
     * Especificar la fila donde comienzan los encabezados
     *
     * @return int
     */
    public function headingRow(): int
    {
        return 5; // Empezar a leer encabezados desde la fila 5
    }

    /**
     * @param array{
     *     'Fecha de operación': string,
     *     'Mensaje': string,
     *     'Origen': string,
     *     'Destino': string,
     *     'Monto': string,
     *     'Tipo de Transacción': string
     * } $row
     * @return \App\Models\TransactionYape|void
     */
    public function model(array $row)
    {
        if (empty($row['Fecha de operación']) || empty($row['Origen']) || empty($row['Destino']) || empty($row['Monto']) || empty($row['Tipo de Transacción'])) {
            return;
        }

        $dateOperation = null;

        $dateString = $row['Fecha de operación'];
        if (Carbon::hasFormat($dateString, 'd/m/Y H:i:s')) {
            $dateOperation = Carbon::createFromFormat('d/m/Y H:i:s', $dateString)->format('Y-m-d H:i:s');
        }

        $toleranceInSeconds = 60;
        $startDate = Carbon::parse($dateOperation)->subSeconds($toleranceInSeconds);
        $endDate = Carbon::parse($dateOperation)->addSeconds($toleranceInSeconds);

        $yapeRecord = TransactionYape::where('message', $row['Mensaje'])
            ->where('message', $row['Mensaje'])
            ->where('origin', $row['Origen'])
            ->where('destination', $row['Destino'])
            ->where('amount', (float) $row['Monto'])
            ->whereBetween('date_operation', [$startDate, $endDate])
            ->where('type_transaction', $row['Tipo de Transacción'] == 'PAGASTE' ? 'expense' : 'income')
            ->where('user_id', $this->userId)
            ->first();

        if ($yapeRecord) {
            return;
        } else {
            $typeTransaction = $row['Tipo de Transacción'] == 'PAGASTE' ? 'expense' : 'income';
            $description = $typeTransaction == 'expense' ? $row['Destino'] : $row['Origen'];
            $detail = Detail::firstOrCreate(
                ['user_id' =>  $this->userId, 'description' => $description],
                ['merchant_id' => null]
            );
            $categorizationService = app(CategorizationService::class);
            $categoryId = $categorizationService->findCategory($this->userId, $detail);
            return  TransactionYape::create([
                'message' => $row['Mensaje'],
                'origin' => $row['Origen'],
                'destination' => $row['Destino'],
                'amount' => (float) $row['Monto'],
                'date_operation' => $dateOperation,
                'type_transaction' => $typeTransaction,
                'user_id' => $this->userId,
                'detail_id' => $detail->id,
                'category_id' => $categoryId,
            ]);
        }
    }
}
