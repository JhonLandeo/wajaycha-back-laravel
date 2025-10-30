<?php

namespace App\Imports;

use App\Models\Detail;
use App\Models\TransactionYape;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

use function PHPUnit\Framework\isEmpty;

HeadingRowFormatter::default('none');
class TransactionYapeImport implements ToModel, WithHeadingRow
{
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
     */
    public function model(array $row)
    {
       if(empty($row['Fecha de operación']) || empty($row['Origen']) || empty($row['Destino']) || empty($row['Monto']) || empty($row['Tipo de Transacción'])){
            return;
       }

        $dateOperation = null;

        if (!empty($row['Fecha de operación'])) {
            $dateString = $row['Fecha de operación'];
            if (Carbon::hasFormat($dateString, 'd/m/Y H:i:s')) {
                $dateOperation = Carbon::createFromFormat('d/m/Y H:i:s', $dateString)->format('Y-m-d H:i:s');
            } elseif (Carbon::hasFormat($dateString, 'd/m/Y')) {
                $dateOperation = Carbon::createFromFormat('d/m/Y', $dateString)->format('Y-m-d') . ' 00:00:00';
            }
        }

        $userId = Auth::id();
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
            ->where('user_id', $userId)
            ->first();

        if ($yapeRecord) {
            return;
        } else {
            $typeTransaction = $row['Tipo de Transacción'] == 'PAGASTE' ? 'expense' : 'income';
            $description = $typeTransaction == 'expense' ? $row['Destino'] : $row['Origen'];
            $detailId = Detail::where('description', $description)->first()->id ?? null;
            if(is_null( $detailId)){
                $newDetail = Detail::create([
                    'description' => $description,
                    'user_id' => $userId,
                ]);
                $detailId = $newDetail->id;
            }
            return  TransactionYape::create([
                'message' => $row['Mensaje'],
                'origin' => $row['Origen'],
                'destination' => $row['Destino'],
                'amount' => (float) $row['Monto'],
                'date_operation' => $dateOperation,
                'type_transaction' => $typeTransaction,
                'user_id' => $userId,
                'detail_id' => $detailId,
            ]);
        }
    }


    /**
     * Especificar la fila donde comienzan los encabezados
     *
     * @return int
     */
    // public function headingRow(): int
    // {
    //     return 3; // Empezar a leer encabezados desde la fila 5
    // }

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    // public function model(array $row)
    // {
    //     // Convertir Fecha Operación
    //     $fechaOperacion = Date::excelToDateTimeObject($row['Fecha Operación'])->format('Y-m-d');

    //     // Convertir Hora Operación
    //     $horaOperacion = gmdate('H:i:s', floor($row['Hora Operación'] * 86400)); // Fracción del día a HH:MM:SS

    //     Log::info($row);

    //     return new TransactionYape([
    //         'message' => null,
    //         'origin' => $row['NOMBRE Origen'],
    //         'destination' => $row['NOMBRE Destino'],
    //         'amount' => (float) $row['Monto'],
    //         'date_operation' => $fechaOperacion . ' ' . $horaOperacion,
    //         'type_transaction' => $row['Tipo de Operación'] == 'YAPEASTE' ? 'expense' : 'income',
    //     ]);
    // }
}
