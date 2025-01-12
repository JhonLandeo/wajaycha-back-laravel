<?php

namespace App\Imports;

use App\Models\Expense;
use App\Models\TransactionYape;
use Carbon\Carbon;
use \PhpOffice\PhpSpreadsheet\Shared\Date;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

HeadingRowFormatter::default('none');
class TransactionYapeImport implements ToModel, WithHeadingRow
{
    // /**
    //  * Especificar la fila donde comienzan los encabezados
    //  *
    //  * @return int
    //  */
    // public function headingRow(): int
    // {
    //     return 5; // Empezar a leer encabezados desde la fila 5
    // }

    // /**
    //  * @param array $row
    //  *
    //  * @return \Illuminate\Database\Eloquent\Model|null
    //  */
    // public function model(array $row)
    // {
    // //    Log::info($row);
    //     $dateOperation = null;
    //     LOg::info($row['Fecha de operación']);

    //     if (!empty($row['Fecha de operación'])) {
    //         $dateString = $row['Fecha de operación'];

    //         // Intentar diferentes formatos de fecha
    //         if (Carbon::hasFormat($dateString, 'd/m/Y H:i:s')) {
    //             $dateOperation = Carbon::createFromFormat('d/m/Y H:i:s', $dateString)->format('Y-m-d H:i:s');
    //         } elseif (Carbon::hasFormat($dateString, 'd/m/Y')) {
    //             $dateOperation = Carbon::createFromFormat('d/m/Y', $dateString)->format('Y-m-d') . ' 00:00:00';
    //         }
    //     }
    //     return new TransactionYape([
    //         'message' => $row['Mensaje'],
    //         'origin' => $row['Origen'],
    //         'destination' => $row['Destino'],
    //         'amount' => (float) $row['Monto'],
    //         'date_operation' => $dateOperation,
    //         'type_transaction' => $row['Tipo de Transacción'] == 'PAGASTE' ? 'expense' : 'income',
    //     ]);
    // }





    /**
     * Especificar la fila donde comienzan los encabezados
     *
     * @return int
     */
    public function headingRow(): int
    {
        return 3; // Empezar a leer encabezados desde la fila 5
    }

    /**
     * @param array $row
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // Convertir Fecha Operación
        $fechaOperacion = Date::excelToDateTimeObject($row['Fecha Operación'])->format('Y-m-d');
    
        // Convertir Hora Operación
        $horaOperacion = gmdate('H:i:s', floor($row['Hora Operación'] * 86400)); // Fracción del día a HH:MM:SS
    
        Log::info($row);
    
        return new TransactionYape([
            'message' => null,
            'origin' => $row['NOMBRE Origen'],
            'destination' => $row['NOMBRE Destino'],
            'amount' => (float) $row['Monto'],
            'date_operation' => $fechaOperacion . ' ' . $horaOperacion,
            'type_transaction' => $row['Tipo de Operación'] == 'YAPEASTE' ? 'expense' : 'income',
        ]);
    }
}
