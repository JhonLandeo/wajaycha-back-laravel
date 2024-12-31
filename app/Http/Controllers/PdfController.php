<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdfRequest;
use App\Models\Details;
use App\Models\Expense;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Smalot\PdfParser\Parser;
use thiagoalessio\TesseractOCR\TesseractOCR;
use setasign\Fpdi\Fpdi;

class PdfController extends Controller
{
    public function __construct()
    {
        App::setLocale('es');
        Carbon::setLocale('es');
    }
    public function extractData(PdfRequest $request)
    {

        
        // Obtener el archivo PDF desde la solicitud
        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        $year = (int)substr($originalName, 6, 4);
        $filePath = $file->getPathname();

        // Desencriptar el archivo PDF si está encriptado
        if ($this->isEncrypted($filePath)) {
            $filePath = $this->decryptPdf($filePath);
        }

        // Intentar leer el archivo como un PDF basado en texto
        $text = $this->extractTextFromPdf($filePath);

        // Si no se ha extraído texto, hacer OCR con Tesseract
        if (empty($text)) {
            // Si el PDF no contiene texto, procesarlo con OCR
            $text = (new TesseractOCR($filePath))->run();
        }

        // Procesar el texto extraído
        $lines = explode("\n", $text);

        $transactions = [];
        $details = [];

        foreach ($lines as $line) {
            if (preg_match('/(\d{2}[A-Z]{3})\s+(\d{2}[A-Z]{3})/', $line, $matches)) {
                $line_subtracted = explode(" ", substr($line, 0, -1));

                $description = '';
                for ($i = 2; $i < count($line_subtracted); $i++) {
                    if (trim($line_subtracted[$i]) === '') {
                        break;
                    }
                    $description .= $line_subtracted[$i] . ' ';
                }
                $description = trim($description);

                $income = $expense = null;

                foreach ($line_subtracted as $index => $item) {
                    $itemCleaned = $item;
                    if (strpos($itemCleaned, '.') !== false) {
                        if ($index === count($line_subtracted) - 1) {
                            $income = $itemCleaned;
                        } else {
                            $expense = $itemCleaned;
                        }
                    }
                }

                $income = $income ?? 0;
                $expense = $expense ?? 0;

                $day = substr($line_subtracted[1], 0, 2);
                $month = substr($line_subtracted[1], 2);
                $monthFormat = $month == 'SET' ? 'SEP' : $month;
                $dayMonth = $day . $monthFormat;
                $transactions[] = [
                    'amount' => $income == 0 ? floatval(str_replace(',', '', $expense)) : floatval(str_replace(',', '', $income)),
                    'date_operation' => Carbon::createFromLocaleFormat('dM', 'es', $dayMonth)->setYear($year),
                    'type_transaction' => $income == 0 ? 'expense' : 'income',
                    'name' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $details[] = [
                    'name' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'user_id' => $request->user_id,
                ];
            }
        }

        try {
            DB::beginTransaction();
            foreach ($details as $item) {
                $details = Details::firstOrCreate(['name' => $item['name'], 'user_id' => $item['user_id']]);
            }

            foreach ($transactions as &$item) {
                $item['detail_id'] = Details::where('name', $item['name'])->first()->id;
                unset($item['name']);
            }

            Transaction::upsert(
                $transactions,
                ['detail_id'],
                ['amount', 'date_operation']
            );
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }




        return response()->json($transactions);
    }

    /**
     * Extrae el texto de un archivo PDF, manejando PDFs encriptados.
     *
     * @param string $filePath
     * @return string
     */
    private function extractTextFromPdf($filePath)
    {
        $parser = new Parser();
        $text = '';

        try {
            $pdf = $parser->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Exception $e) {
            $text = '';
        }

        return $text;
    }

    /**
     * Verifica si un PDF está encriptado.
     *
     * @param string $filePath
     * @return bool
     */
    private function isEncrypted($filePath)
    {
        // Usamos FPDI para intentar abrir el archivo PDF
        $pdf = new Fpdi();

        try {
            $pdf->setSourceFile($filePath);
            return false;
        } catch (\setasign\Fpdi\PdfParser\PDFParserException $e) {

            return true; // El archivo está encriptado
        }
    }

    /**
     * Desencripta un archivo PDF usando qpdf.
     *
     * @param string $filePath
     * @return string $decryptedFilePath
     */
    private function decryptPdf($filePath)
    {
        $decryptedFilePath = storage_path('app/uploads/decrypted_' . basename($filePath));

        // Contraseña del archivo PDF
        $password = '76288003'; // Cambiar por la contraseña del PDF

        // Comando para usar qpdf y desencriptar el archivo
        $command = sprintf(
            'qpdf --decrypt --password=%s %s %s',
            $password, // Aquí no se usa escapeshellarg() para la contraseña
            escapeshellarg($filePath),
            escapeshellarg($decryptedFilePath)
        );


        exec($command, $output, $returnVar);


        if ($returnVar !== 0) {
            Log::error('QPDF Output: ' . implode("\n", $output));
            Log::error('QPDF Return Code: ' . $returnVar);
            throw new \Exception('No se pudo desencriptar el archivo PDF con qpdf.');
        }

        return $decryptedFilePath;
    }
}
