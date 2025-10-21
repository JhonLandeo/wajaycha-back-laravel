<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FinancialReportController extends Controller
{
    private $transactions;
    public function __construct()
    {
        $this->transactions = DB::table('transactions as t')
            ->select('t.id', 't.amount', 't.date_operation', 't.type_transaction', 's.name as cat_name', 'd.name as detail_name', 't.category_id')
            ->join('categories as s', 's.id', '=', 't.category_id')
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
        return $this->transactions->groupBy('cat_name')->map(function ($item) use ($month) {
            return $item->where('type_transaction', 'expense')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount') - $item->where('type_transaction', 'income')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount');
        });
    }

    public function totalIncomeByCategory($month)
    {
        return $this->transactions->groupBy('cat_name')->map(function ($item) use ($month) {
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
            ->where('category_id', $category)
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
            ->groupBy('category_id')
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
        $monthlyBudget = DB::table('categories')->get();
        return $this->totalExpensesByCategory($month)->map(function ($item, $sub) use ($monthlyBudget) {
            $monthly_budget = $monthlyBudget->where('name', $sub)->value('monthly_budget');
            $variance =  $item - $monthly_budget;
            return (object) [
                'category' => $sub,
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

    public function cashFlowWeekly()
    {
        $weeklyCashFlow = $this->transactions->groupBy(function ($item) {
            return Carbon::parse($item->date_operation)->startOfWeek();
        })->map(function ($group, $week) {
            $ingreso = $group->where('type_transaction', 'income')->sum('amount');
            $egreso = $group->where('type_transaction', 'expense')->sum('amount');
            $numberOfWeek = Carbon::parse($week)->weekOfYear();
            $endWeekDay = Carbon::parse($week)->endOfWeek();
            return (object) [
                'semana_del_año' => $numberOfWeek,
                'ingresos' => $ingreso,
                'gastos' => $egreso,
                'flujo_neto' => $ingreso - $egreso,
                'rango_fechas' => Carbon::parse($week)->format('Y-m-d') . ' a ' . $endWeekDay->format('Y-m-d')
            ];
        })->flatten()->sortBy('semana_del_año');

        return $weeklyCashFlow;
    }

    public function spendingVelocity()
    {
        return $this->transactions->groupBy(function ($item) {
            return Carbon::parse($item->date_operation)->monthOfYear();
        })->map(function ($group, $month) {
            $totalExpense = round($group->where('type_transaction', 'expense')->sum('amount'), 2);
            $daysOfPeriod = Carbon::create(2025, $month, 1)->daysInMonth();
            $velocity = round($totalExpense / $daysOfPeriod, 2);
            $monthName = Carbon::create(null, $month, 1)->monthName;
            return (object) [
                'periodo' => $monthName . ' 2025',
                'gasto_total' => $totalExpense,
                'dias_en_periodo' => $daysOfPeriod,
                'velocidad_de_gasto' => $velocity,
                'diagnostico' => 'En promedio, gastaste ' . $velocity . ' por día durante el mes de ' . $monthName
            ];
        })->flatten();
    }

    public function getLargestExpenseInDays($days)
    {
        $transaction =  $this->transactions->where('type_transaction', 'expense')->filter(function ($item) use ($days) {
            $subtractDays = now()->subDay($days);
            return $item->date_operation > $subtractDays;
        })->sortByDesc('amount')->first();

        return (object) [
            'periodo_analizado' => 'Ultimos ' . $days . ' dias',
            'transaction' => $transaction
        ];
    }

    public function drillDown($category)
    {
        $data = $this->transactions
            ->where('type_transaction', 'expense')
            ->where('cat_name', $category)
            ->groupBy('detail_name')
            ->map(function ($group, $detailName) {
                return (object) [
                    'descripcion' => $detailName,
                    'numero_transacciones' => $group->count(),
                    'monto_total' => $group->sum('amount'),
                ];
            })
            ->sortByDesc('monto_total')
            ->values();

        return (object) [
            'categoria_analizada' => $category,
            'desglose_por_descripcion' => $data
        ];
    }

    public function correlationByCategory($category)
    {

        $base = $this->transactions
            ->groupBy('cat_name')
            ->map(function ($group, $key) {
                $month = $group->groupBy(function ($item) {
                    return Carbon::parse($item->date_operation)->monthName;
                });
                return $month;
            })->map(function ($sub, $key) {
                return $sub->map(function ($data) {
                    $expense = $data->where('type_transaction', 'expense')->sum('amount');
                    $income = $data->where('type_transaction', 'income')->sum('amount');
                    return $expense - $income;
                });
            });

        $categormainCategoryy = $base->get($category);
        $diferentCategory = $base->filter(function ($value, $key) use ($category) {
            return $key !== $category;
        });

        return $diferentCategory->map(function ($item, $key) use ($categormainCategoryy) {

            return $categormainCategoryy->flatMap(function ($sub, $subkey) use ($item) {
                return [$subkey => [$sub, $item->get($subkey)]];
            })->values();
        })->map(function ($item) {
            $r = 0;
            $n = $item->count();
            $sum_x = $item->reduce(function ($carry, $item) {
                return $carry + $item[0];
            }, 0);
            $sum_y = $item->reduce(function ($carry, $item) {
                return $carry + $item[1];
            }, 0);
            $sum_x2 = $item->map(function ($item) {
                return $item[0] * $item[0];
            })->sum();
            $sum_y2 = $item->map(function ($item) {
                return $item[1] * $item[1];
            })->sum();
            $sum_xy =  $item->map(function ($item) {
                return $item[0] * $item[1];
            })->sum();
            $numerador = ($n * $sum_xy - $sum_x * $sum_y);
            $denominador = sqrt(($n * $sum_x2 - pow($sum_x, 2)) * ($n * $sum_y2 - pow($sum_y, 2)));
            if ($numerador != 0 && $denominador != 0) {
                $r = $numerador / $denominador;
            }
            return (object) [
                'data_points_used' => $n,
                'correlation_coefficient' => $r
            ];
        })->map(function ($item, $key) use ($category) {
            $correlation_coefficient = $item->correlation_coefficient;
            $strength = '';
            if ($correlation_coefficient >= 0.9) {
                $strength = 'Muy fuerte';
            } elseif ($correlation_coefficient >= 0.7) {
                $strength = 'Fuerte';
            } elseif ($correlation_coefficient >= 0.4) {
                $strength = 'Moderada';
            } elseif ($correlation_coefficient >= 0.1) {
                $strength = 'Débil';
            } else {
                $strength = 'Nula o despreciable';
            }
            $varY = $key;
            $varX = $category;
            $direction = $correlation_coefficient > 0 ? 'Positiva' : 'Negativa';
            $interpretation = "Se ha encontrado una $strength correlación $direction entre $varX y $varY. ";

            if ($direction === 'Positiva') {
                $interpretation .= "Esto sugiere que cuando aumentas tus gastos en $varX, también aumentan los de $varY.";
            } else {
                $interpretation .= "Esto indica que cuando aumentas tus gastos en $varX, los de $varY tienden a disminuir.";
            }
            return (object) [
                'analysis_type' => "Correlación de Gastos Mensuales",
                'variables' => (object)[
                    'x' => $varX,
                    'y' => $varY
                ],
                'correlation_coefficient' => $correlation_coefficient,
                'strength' => $strength,
                'direction' => $direction,
                'interpretation' => $interpretation,
                'data_points_used' => $item->data_points_used
            ];
        })->values();
    }

    public function getQuantityByAmountRange()
    {
        $ranges = [
            '0 - $10' => [0, 10],
            '$10.01 - $25' => [10.01, 25],
            '$25.01 - $50' => [25.01, 50],
            '$50.01 - $100' => [50.01, 100],
            '$100+' => [100.01, INF],
        ];

        $amountCounts = [];
        foreach ($ranges as $label => $range) {
            $amountCounts[$label] = 0;
        }

        foreach ($this->transactions->where('type_transaction', 'expense') as $transaction) {
            foreach ($ranges as $label => [$min, $max]) {
                if ($transaction->amount >= $min && $transaction->amount <= $max) {
                    $amountCounts[$label]++;
                    break;
                }
            }
        }

        return $amountCounts;
    }
    public function getVolatileCategory()
    {
        return $this->transactions->groupBy('cat_name')
            ->map(function ($group) {
                $month = $group->groupBy(function ($item) {
                    return Carbon::parse($item->date_operation)->monthName;
                });
                return $month;
            })->map(function ($sub) {
                return $sub->map(function ($data) {
                    $expense = $data->where('type_transaction', 'expense')->sum('amount');
                    $income = $data->where('type_transaction', 'income')->sum('amount');
                    return $expense - $income;
                })->values();
            })->map(function ($item, $key) {
                $promedio = $item->avg();
                $coeficienteVariacion = 0;
                if($promedio > 0){
                    $total = $item->count();
                    $varianza =  $item->reduce(function ($carry, $subItem) use ($promedio) {
                        $result = $carry + ($subItem - $promedio) ** 2;
                        return $result;
                    });
                    $desviacion = ($total > 1) ? sqrt($varianza / ($total - 1)) : 0;
                    $coeficienteVariacion = $desviacion / $promedio;
                }

                $diagnostic = '';

                if ($coeficienteVariacion <= -1.5) {
                    $diagnostic = 'Muy estable';
                } elseif ($coeficienteVariacion > -1.5 && $coeficienteVariacion <= -0.5) {
                    $diagnostic = 'Estable';
                } elseif ($coeficienteVariacion > -0.5 && $coeficienteVariacion <= 0.5) {
                    $diagnostic = 'Normal';
                } elseif ($coeficienteVariacion > 0.5 && $coeficienteVariacion <= 1.5) {
                    $diagnostic = 'Volatil';
                } else {
                    $diagnostic = 'Muy Volátil';
                }
                return (object) [
                    'categoria' => $key,
                    'volatilidad' => $coeficienteVariacion,
                    'diagnostico' => $diagnostic
                ];
            })->values();
    }

    public function getParetoCategory(){
        $totalExpenseByCategory = $this->transactions->where('type_transaction', 'expense')
        ->groupBy('cat_name')
        ->map(function ($group) {
            $expense = $group->where('type_transaction', 'expense')->sum('amount');
            $income = $group->where('type_transaction', 'income')->sum('amount');
            return $expense - $income;
        })->sortDesc();
        $total = $totalExpenseByCategory->values()->sum();
        $percentages = $totalExpenseByCategory->map(function ($item) use ($total) {
            return (object) [
                'percentage' => $item * 100 / $total,
                'amount' => $item
            ];
        });
        $pareto = collect();
        $acc = 0;
        foreach ($percentages as $key => $value) {
            $acc += $value->percentage;
            $pareto[] = (object) [
                'acumulado_porc' => $acc,
                'category' => $key,
                'gasto' => $value->amount
            ];
            if($acc >= 80) break;
        }
        $accPercentage = $pareto->last()->acumulado_porc;
        $quantity = $pareto->count();
        return (object) [
            'principio_pareto' => $pareto,
            'conclusion' => "El $accPercentage% de todos tus gastos provienen de solo $quantity categorias",
        ];
    }

}
