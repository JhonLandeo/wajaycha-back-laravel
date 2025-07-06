<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialReportController extends Controller
{
    private $transactions;
    public function __construct()
    {
        $this->transactions = DB::table('transactions as t')
            ->select('t.id', 't.amount', 't.date_operation', 't.type_transaction', 's.name as subcat_name', 'd.name as detail_name', 't.sub_category_id')
            ->join('sub_categories as s', 's.id', '=', 't.sub_category_id')
            ->join('details as d', 'd.id', '=', 't.detail_id')
            ->get();
    }

    public function getBalance()
    {
        $totalIncome = $this->transactions->where('type_transaction', 'income')->sum('amount');
        $totalExpense = $this->transactions->where('type_transaction', 'expense')->sum('amount');
        $balance = $totalIncome - $totalExpense;
        return $balance;
    }

    public function totalExpensesByCategory($month)
    {
        return $this->transactions->groupBy('subcat_name')->map(function ($item) use ($month) {
            return $item->where('type_transaction', 'expense')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount') - $item->where('type_transaction', 'income')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount');
        });
    }

    public function totalIncomeByCategory($month)
    {
        return $this->transactions->groupBy('subcat_name')->map(function ($item) use ($month) {
            return $item->where('type_transaction', 'income')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount') - $item->where('type_transaction', 'expense')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount');
        });
    }

    public function summaryMonthlyReport($month)
    {
        return $this->transactions->groupBy(function ($item) {
            $date = new DateTime($item->date_operation);
            $year = $date->format('y');
            $month = $date->format('m');
            return $year . '-' . $month;
        })->map(function ($group) {
            $expenses = $group->where('type_transaction', 'expense')->sum('amount');
            $incomes = $group->where('type_transaction', 'income')->sum('amount');
            return (object) [
                'expenses' => $expenses,
                'incomes' => $incomes
            ];
        });
    }

    public function topThreeExpenses($month)
    {
        return $this->totalExpensesByCategory($month)
            ->sortByDesc(function ($item) {
                return $item;
            })->take(3);
    }

    public function topThreeIncomes($month)
    {
        return $this->totalIncomeByCategory($month)
            ->sortByDesc(function ($item) {
                return $item;
            })->take(3);
    }

    public function getBalanceDay($day)
    {
        return $this->transactions->groupBy(function ($item) {
            return $item->date_operation;
        })->map(function ($sub) {
            $expenses = $sub->where('type_transaction', 'expense')->sum('amount');
            $incomes = $sub->where('type_transaction', 'income')->sum('amount');
            $balance = $incomes - $expenses;
            return $balance;
        });
    }

    public function getExpenseByCategory($category)
    {
        return $this->transactions->where('type_transaction', 'expense')
            ->where('sub_category_id', $category)
            ->sum('amount');
    }

    public function getExpenseWeekendAndWeekdayByCategory($category)
    {
        [$foodExpenseWeekend, $foodExpenseWorkday] = $this->getExpenseByCategory($category)->reduceSpread(function ($foodExpenseWeekend, $foodExpenseWorkday, $item) {
            $date = Carbon::parse($item->date_operation);
            $dayOfWeek = $date->dayOfWeek();
            if ($dayOfWeek != 0 && $dayOfWeek != 6) {
                $foodExpenseWorkday += $item->amount;
            } else {
                $foodExpenseWeekend += $item->amount;
            }
            return [$foodExpenseWeekend, $foodExpenseWorkday];
        }, 0, 0);
        return [
            'weekend' => $foodExpenseWeekend,
            'workday' => $foodExpenseWorkday
        ];
    }

    public function getGrowthRate()
    {
        return $this->transactions->groupBy(function ($item) {
            $date = new DateTime($item->date_operation);
            $year = $date->format('y');
            $month = $date->format('m');
            return $year . '-' . $month;
        })->map(function ($sub) {
            return $sub->where('type_transaction', 'expense')->sum('amount');
        })->sliding(2)->map(function ($data) {
            $actual = $data->last() == 0 ? 1 : $data->first();
            $anterior = $data->first() == 0 ? 1 : $data->last();
            return (object) [
                'gastos_mes_actual' => $actual,
                'gastos_mes_anterior' => $anterior,
                'tasa_crecimiento' => (($actual - $anterior) / $anterior) * 100
            ];
        });
    }

    public function getOutlierTransactions()
    {
        return $this->transactions->where('type_transaction', 'expense')
            ->groupBy('sub_category_id')
            ->map(function ($group, $index) {
                $promedio = $group->avg('amount');
                $total = $group->count();
                $varianza =  $group->reduce(function ($carry, $item) use ($promedio) {
                    $result = $carry + ($item->amount - $promedio) ** 2;
                    return $result;
                });

                $desviacion = ($total > 1) ? sqrt($varianza / ($total - 1)) : 0;
                $limite = $desviacion * 2 + $promedio;
                return $group->filter(function ($tran) use ($limite) {
                    return $tran->amount > $limite;
                });
            });
    }

    public function budgetDeviation($month)
    {
        $monthlyBudget = DB::table('sub_categories')->get();
        return $this->totalExpensesByCategory($month)->map(function ($item, $sub) use ($monthlyBudget) {
            $monthly_budget = $monthlyBudget->where('name', $sub)->value('monthly_budget');
            $variance =  $item - $monthly_budget;
            return (object) [
                'subcategory' => $sub,
                'budgeted' => $monthly_budget,
                'real' => $item,
                'variance' => $variance,
                'status' => $variance <= 0 ? 'Dentro del presupuesto' : 'Excedido'
            ];
        });
    }

    public function concurrentTransactions()
    {
        $transactionsWithDetail = DB::table('transactions as t')
            ->selectRaw("
                CASE 
                    WHEN t.yape_id IS NULL THEN d.name
                    ELSE IF(ty.type_transaction = 'expense', ty.destination, ty.origin) 
                END AS detail_name,
                t.amount,
                t.id,
                t.date_operation,
                t.type_transaction
            ")
            ->leftJoin('transaction_yapes as ty', 'ty.id', '=', 't.yape_id')
            ->leftJoin('details as d', 'd.id', '=', 't.detail_id')
            ->get();



        $transaccionesConcurrentes = $transactionsWithDetail
            ->where('type_transaction', 'expense')
            ->groupBy(function ($item) use ($transactionsWithDetail) {
                $percentage = 85;
                $filter = $transactionsWithDetail->filter(function ($data) use ($item, $percentage) {
                    similar_text($data->detail_name, $item->detail_name, $percentage);
                    return $percentage > 80;
                });
                return $filter;
            })->map(function ($item, $key) {
                $first = collect(json_decode($key))->first();
                return [$first->detail_name => $item];
            })->collapse()
            ->filter(function ($item) {
                $arraySum = $item->pluck('amount');
                $median = $arraySum->median();
                $variacion = $median * 0.25;
                $limiteSuperior = $median + $variacion;
                $limiteInferior = $median - $variacion;
                $filtrar = $item->filter(function ($data, $index) use ($limiteInferior, $limiteSuperior, $item) {
                    return $data->amount > $limiteInferior && $data->amount < $limiteSuperior;
                })->count();
                $totalCount = $item->count();
                $coherencia = $filtrar / $totalCount;
                if ($filtrar >= 2 && $coherencia >= 0.65) {
                    $item->map(function ($data) use ($median) {
                        $data->monto_promedio = $median;
                    });
                    return $item;
                } else {
                    return false;
                }
            })
            ->map(function ($item) {
                $hasConcurrentPair = $item->sliding(2)->filter(function ($pair) {
                    $fechaA = Carbon::parse($pair->first()->date_operation);
                    $fechaB = Carbon::parse($pair->last()->date_operation);

                    $diff = $fechaA->diffInDays($fechaB);

                    return $diff >= 25 && $diff <= 35;
                })->values();
                if ($hasConcurrentPair->count() > 1) {
                    $primeros =  $hasConcurrentPair->flatMap(function ($item, $index) use ($hasConcurrentPair) {
                        $first = collect([]);
                        $first[] = $item->first();
                        if ($hasConcurrentPair->count() - 1 == $index) {
                            $first->push($item->last());
                        }
                        return $first;
                    });
                    return $primeros;
                }
                return false;
            })
            ->filter()
            ->map(function ($item) {
                $primer = $item->first();
                $descripcion_detectada = $primer->detail_name;
                $monto_promedio = $primer->monto_promedio;
                return [
                    'descripcion_detectada' => $descripcion_detectada,
                    'monto_promedio' => $monto_promedio,
                    'transacciones_encontradas' => $item->map(function ($item) {
                        return (object) [
                            'fecha' => $item->date_operation,
                            'monto' => $item->amount
                        ];
                    })
                ];
            });

        return $transaccionesConcurrentes;
    }
}
