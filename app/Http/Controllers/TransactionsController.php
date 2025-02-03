<?php

namespace App\Http\Controllers;

use App\Exports\TransactionsExport;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class TransactionsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $year = $request->input('year', null);
        $month = $request->input('month', null);
        $type = $request->input('type', null);
        $amount = $request->input('amount', null);
        $search = $request->input('search', null);
        $subCategory = $request->input('sub_category', null);
        $userId = $request->input('user_id', null);

        $subQuery = "
            (SELECT JSON_ARRAYAGG(JSON_OBJECT(
                'date_operation', yape_trans.date_operation,
                'amount', yape_trans.amount,
                'origin', yape_trans.origin,
                'destination', yape_trans.destination,
                'type_transaction', yape_trans.type_transaction,
                'message', yape_trans.message
            ))
            FROM transaction_yapes yape_trans
            WHERE yape_trans.amount = transactions.amount
              AND yape_trans.user_id = $userId
              AND DATE(yape_trans.date_operation) = DATE(transactions.date_operation)
            ) AS relation
        ";

        $subQueryFrequency = "(
            SELECT
                COUNT(*)
            FROM
                transactions t2
            WHERE
                t2.detail_id = transactions.detail_id
            GROUP BY
                t2.detail_id ) as frequency";

        $query = Transaction::with(['detail.user'])
            ->selectRaw("transactions.*, $subQuery")
            ->selectRaw("transactions.*, $subQueryFrequency")
            ->join('details as d', 'transactions.detail_id', '=',  'd.id');

        if ($year) {
            $query->whereYear('date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('date_operation', $month);
        }

        if ($type) {
            $query->where('type_transaction', $type);
        }
        if ($amount && $amount != 0.00) {
            $query->where('amount', $amount);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereLike('d.name', "%$search%")
                    ->orWhereRaw("EXISTS (
                      SELECT 1
                      FROM transaction_yapes yape_trans
                      WHERE yape_trans.amount = transactions.amount
                        AND DATE(yape_trans.date_operation) = DATE(transactions.date_operation)
                        AND yape_trans.destination LIKE ?)", ["%$search%"]);
            });
        }

        if ($subCategory && $subCategory == 'without_sub_category') {
            $query->whereNull('transactions.sub_category_id');
        } elseif ($subCategory) {
            $query->where('transactions.sub_category_id', $subCategory);
        }
        $query->where('transactions.user_id', $userId);
        $data = $query->orderBy('transactions.date_operation', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($data);
    }


    public function getSummaryBySubCategory(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = $request->input('page', 1);
        $year = $request->input('year', null);
        $month = $request->input('month', null);
        $type = $request->input('type', null);
        $userId = $request->input('user_id', null);

        $query = DB::table('transactions as t')
            ->leftJoin('details as d', 'd.id', '=', 't.detail_id')
            ->leftJoin('sub_categories as sc', 'sc.id', '=', 't.sub_category_id')
            ->select(
                DB::raw('COALESCE(sc.name, "Sin categorizar") as name'),
                DB::raw('COUNT(*) as quantity'),
                DB::raw(" SUM(CASE 
                    WHEN t.type_transaction = 'expense' THEN t.amount 
                    WHEN t.type_transaction = 'income' THEN -t.amount 
                    ELSE 0 
                END) as total")
            );

        if ($year) {
            $query->whereYear('t.date_operation', $year);
        }

        if ($month) {
            $query->whereMonth('t.date_operation', $month);
        }

        if ($type) {
            $query->where('t.type_transaction', $type);
        }

        if ($userId) {
            $query->where('t.user_id', $userId);
        }

        $results = $query->groupBy('sc.name')
            ->orderBy(DB::raw('total'), 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Devolvemos la respuesta en formato JSON
        return response()->json($results);
    }

    public function exportTransaction()
    {

        $data = array(
            0 =>
            array(
                'detail_week_mat_id' => 123,
                'type_material_id' => 10,
                'type_material' => 'Guía de clases',
                'description' => 'Falta preguntas para el tipo de material: Guía de clases.',
                'number_validate' => 4,
                'fl_exam' => false,
                'data' =>
                array(
                    0 =>
                    array(
                        'course_id' => 2,
                        'code_course' => '02',
                        'course' => 'ÁLGEBRA',
                        'total' => 1,
                        'topics' =>
                        array(
                            0 =>
                            array(
                                'topic_id' => 55,
                                'code_topic' => '28',
                                'topic' => 'Inecuaciones II',
                                'total' => 1,
                                'subtopics' =>
                                array(
                                    0 =>
                                    array(
                                        'subtopic_id' => 421,
                                        'code_subtopic' => '02',
                                        'subtopic' => 'Resolución de la inecuación fraccionaria (Teorema)',
                                        'total' => 1,
                                    ),
                                ),
                            ),
                        ),
                        'levels' =>
                        array(
                            0 =>
                            array(
                                'level_id' => 47,
                                'level_description' => 'NIVEL 5',
                                'level_name' => '5',
                                'total' => 1,
                            ),
                        ),
                    ),
                    1 =>
                    array(
                        'course_id' => 3,
                        'code_course' => '03',
                        'course' => 'GEOMETRÍA',
                        'total' => 7,
                        'topics' =>
                        array(
                            0 =>
                            array(
                                'topic_id' => 91,
                                'code_topic' => '19',
                                'topic' => 'ÁREAS DE REGIONES TRIANGULARES',
                                'total' => 7,
                                'subtopics' =>
                                array(
                                    0 =>
                                    array(
                                        'subtopic_id' => 723,
                                        'code_subtopic' => '03',
                                        'subtopic' => 'Fórmula trigonométrica',
                                        'total' => 2,
                                    ),
                                    1 =>
                                    array(
                                        'subtopic_id' => 724,
                                        'code_subtopic' => '04',
                                        'subtopic' => 'Fórmula de Herón',
                                        'total' => 2,
                                    ),
                                    2 =>
                                    array(
                                        'subtopic_id' => 725,
                                        'code_subtopic' => '05',
                                        'subtopic' => 'Fórmula del inradio',
                                        'total' => 1,
                                    ),
                                    3 =>
                                    array(
                                        'subtopic_id' => 730,
                                        'code_subtopic' => '10',
                                        'subtopic' => 'Razón entre áreas de regiones de igual altura',
                                        'total' => 1,
                                    ),
                                    4 =>
                                    array(
                                        'subtopic_id' => 732,
                                        'code_subtopic' => '12',
                                        'subtopic' => 'Razón entre áreas de regiones triangulares semejantes',
                                        'total' => 1,
                                    ),
                                ),
                            ),
                        ),
                        'levels' =>
                        array(
                            0 =>
                            array(
                                'level_id' => 45,
                                'level_description' => 'NIVEL 3',
                                'level_name' => '3',
                                'total' => 2,
                            ),
                            1 =>
                            array(
                                'level_id' => 46,
                                'level_description' => 'NIVEL 4',
                                'level_name' => '4',
                                'total' => 3,
                            ),
                            2 =>
                            array(
                                'level_id' => 47,
                                'level_description' => 'NIVEL 5',
                                'level_name' => '5',
                                'total' => 2,
                            ),
                        ),
                    ),
                ),
                
            ),
            1 =>
            array(
                'detail_week_mat_id' => 124,
                'type_material_id' => 11,
                'type_material' => 'Homework',
                'description' => 'Falta preguntas para el tipo de material: Homework.',
                'number_validate' => 4,
                'fl_exam' => false,
                'data' =>
                array(
                    0 =>
                    array(
                        'course_id' => 1,
                        'code_course' => '01',
                        'course' => 'ARITMÉTICA',
                        'total' => 2,
                        'topics' =>
                        array(
                            0 =>
                            array(
                                'topic_id' => 19,
                                'code_topic' => '19',
                                'topic' => 'MULTIPLICACIÓN',
                                'total' => 1,
                                'subtopics' =>
                                array(
                                    0 =>
                                    array(
                                        'subtopic_id' => 137,
                                        'code_subtopic' => '03',
                                        'subtopic' => 'MULTIPLICACIÓN CON PRODUCTOS PARCIALES',
                                        'total' => 1,
                                    ),
                                ),
                            ),
                            1 =>
                            array(
                                'topic_id' => 21,
                                'code_topic' => '21',
                                'topic' => 'DIVISIBILIDAD',
                                'total' => 1,
                                'subtopics' =>
                                array(
                                    0 =>
                                    array(
                                        'subtopic_id' => 149,
                                        'code_subtopic' => '05',
                                        'subtopic' => 'ECUACIONES DIOFÁNTICAS',
                                        'total' => 1,
                                    ),
                                ),
                            ),
                        ),
                        'levels' =>
                        array(
                            0 =>
                            array(
                                'level_id' => 44,
                                'level_description' => 'NIVEL 2',
                                'level_name' => '2',
                                'total' => 1,
                            ),
                            1 =>
                            array(
                                'level_id' => 47,
                                'level_description' => 'NIVEL 5',
                                'level_name' => '5',
                                'total' => 1,
                            ),
                        ),
                    ),
                    1 =>
                    array(
                        'course_id' => 2,
                        'code_course' => '02',
                        'course' => 'ÁLGEBRA',
                        'total' => 1,
                        'topics' =>
                        array(
                            0 =>
                            array(
                                'topic_id' => 55,
                                'code_topic' => '28',
                                'topic' => 'Inecuaciones II',
                                'total' => 1,
                                'subtopics' =>
                                array(
                                    0 =>
                                    array(
                                        'subtopic_id' => 421,
                                        'code_subtopic' => '02',
                                        'subtopic' => 'Resolución de la inecuación fraccionaria (Teorema)',
                                        'total' => 1,
                                    ),
                                ),
                            ),
                        ),
                        'levels' =>
                        array(
                            0 =>
                            array(
                                'level_id' => 47,
                                'level_description' => 'NIVEL 5',
                                'level_name' => '5',
                                'total' => 1,
                            ),
                        ),
                    ),
                ),
            ),
        );
        $flattenedData = collect($data)->flatMap(function ($material) {
            $materialName = $material['type_material'];
        
            return collect($material['data'])->flatMap(function ($course) use ($materialName) {
                $courseName = $course['course'];
        
                return collect($course['topics'])->flatMap(function ($topic) use ($materialName, $courseName) {
                    $topicName = $topic['topic'];
        
                    return collect($topic['subtopics'])->map(function ($subtopic) use ($materialName, $courseName, $topicName) {
                        return [
                            'material' => $materialName,
                            'course' => $courseName,
                            'topic' => $topicName,
                            'subtopic' => $subtopic['subtopic'],
                            'total' => $subtopic['total'],
                        ];
                    });
                });
            });
        });
        Log::info($flattenedData->toArray());
        $convert = collect($flattenedData);
        $fileName = 'report_' . now()->format('Y_m_d_His') . '.xlsx';

        // Guardar el archivo en el almacenamiento local
        Excel::store(new TransactionsExport($convert), $fileName, 'local'); // Puedes cambiar 'local' por otro disco configurado
    
        // Opcional: Guardar en un subdirectorio (por ejemplo, "reports")
        // Excel::store(new ReportExport($data), "reports/{$fileName}", 'local');
    
        // Devolver el archivo como descarga
        return Excel::download(new TransactionsExport($convert), $fileName);
    }





    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        return Transaction::create($request->all());
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Transaction $transaction)
    {
        return $transaction->update($request->all());
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Transaction $transaction)
    {
        return $transaction->delete();
    }
}
