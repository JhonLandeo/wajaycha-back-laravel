<?php

namespace App\Http\Controllers;

use App\DTOs\BudgetDeviationDTO;
use App\DTOs\VolatilityReportDTO;
use App\Enums\VolatilityDiagnostic;
use App\Models\Category;
use App\Models\Transaction;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection as EloquentCollection; // <-- Importante

class FinancialReportController extends Controller
{

    /**
     * @var Collection<int, \App\Models\Transaction>
     */
    protected Collection $transactions;
    public function __construct()
    {
        // $this->transactions = DB::table('transactions as t')
        //     ->select('t.id', 't.amount', 't.date_operation', 't.type_transaction', 's.name as cat_name', 'd.description as detail_name', 't.category_id')
        //     ->join('categories as s', 's.id', '=', 't.category_id')
        //     ->join('details as d', 'd.id', '=', 't.detail_id')
        //     ->get();
    }

    public function getBalance(): float
    {
        $totalIncome = $this->transactions->where('type_transaction', 'income')->sum('amount');
        $totalExpense = $this->transactions->where('type_transaction', 'expense')->sum('amount');
        $balance = $totalIncome - $totalExpense;
        return $balance;
    }

    /**
     * @return Collection<string, float|int>
     */
    public function totalExpensesByCategory(int $month): Collection
    {
        return $this->transactions->groupBy('cat_name')->map(function ($item) use ($month) {
            return $item->where('type_transaction', 'expense')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount') - $item->where('type_transaction', 'income')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount');
        });
    }

    public function totalIncomeByCategory(int $month): Collection
    {
        return $this->transactions->groupBy('cat_name')->map(function ($item) use ($month) {
            return $item->where('type_transaction', 'income')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount') - $item->where('type_transaction', 'expense')
                ->filter(fn($sub) => Carbon::parse($sub->date_operation)->month == $month)
                ->sum('amount');
        });
    }

    public function summaryMonthlyReport(int $month): Collection
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

    public function topThreeExpenses(int $month): Collection
    {
        return $this->totalExpensesByCategory($month)
            ->sortByDesc(function ($item) {
                return $item;
            })->take(3);
    }

    public function topThreeIncomes(int $month): Collection
    {
        return $this->totalIncomeByCategory($month)
            ->sortByDesc(function ($item) {
                return $item;
            })->take(3);
    }

    public function getBalanceDay(int $day): Collection
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

    public function getExpenseByCategory(int $category): mixed
    {
        return $this->transactions->where('type_transaction', 'expense')
            ->where('category_id', $category)
            ->sum('amount');
    }

    /** 
     * @return array<string, mixed>
     */
    public function getExpenseWeekendAndWeekdayByCategory(int $category): array
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

    public function getGrowthRate(): Collection
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

    /**
     * @return Collection<int, Collection<int, Transaction>>
     */
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

    /**
     * @return Collection<string, mixed>
     */
    public function budgetDeviation(int $month): Collection
    {
        $monthlyBudget = Category::all();
        return $this->totalExpensesByCategory($month)->map(function ($item, $sub) use ($monthlyBudget) {
            /** @var Collection<int, \App\Models\Category> $monthlyBudget */
            $monthly_budget = (float) $monthlyBudget->where('name', $sub)->value('monthly_budget');
            $variance =  $item - $monthly_budget;
            return new BudgetDeviationDTO(
                category: $sub,
                budgeted: $monthly_budget,
                real: $item,
                variance: $variance,
                status: $variance <= 0 ? 'Dentro del presupuesto' : 'Excedido'
            );
        });
    }

    /**
     * @return Collection
     */
    public function concurrentTransactions(): Collection
    {
        // --- 1. OBTENER DATOS ---
        /** @var EloquentCollection<int, Transaction> $transactionsWithDetail */
        $transactionsWithDetail = Transaction::query()
            // ... (tu selectRaw y joins) ...
            ->get();

        // --- 2. AGRUPAR POR SIMILITUD ---

        // --- ¡¡¡AQUÍ ESTÁ LA CORRECCIÓN!!! ---
        // Inicializamos la colección diciéndole a Larastan el "tipo" de elementos que contendrá,
        // aunque esté vacía.
        /** @var Collection<int, EloquentCollection<int, Transaction>> $groups */
        $groups = collect([]); // <--- En lugar de 'new Collection()'

        $minSimilarity = 80;

        foreach ($transactionsWithDetail->where('type_transaction', 'expense') as $transaction) {
            /** @var Transaction $transaction */
            $foundGroup = false;

            // Ahora Larastan sabe que $groups contiene colecciones, por lo que $group es una colección
            foreach ($groups as $group) {
                // Ya no necesitamos el @var incorrecto aquí
                /** @var EloquentCollection<int, Transaction> $group */
                $representative = $group->first();
                if ($representative === null) continue; // Grupo vacío, saltar

                similar_text($transaction->detail_name, $representative->detail_name, $percentage);

                if ($percentage >= $minSimilarity) {
                    $group->push($transaction);
                    $foundGroup = true;
                    break;
                }
            }

            if (!$foundGroup) {
                // Usamos 'EloquentCollection' para ser consistentes
                $groups->push(new EloquentCollection([$transaction]));
            }
        }

        // --- 3. FILTRAR GRUPOS COHERENTES ---
        // Ya no necesitamos el @var para $coherentGroups, Larastan puede inferirlo.
        $coherentGroups = $groups->filter(function (EloquentCollection $group) {
            // Reemplazamos el @var por un type-hint en el parámetro (más limpio)

            $arraySum = $group->pluck('amount');
            $median = $arraySum->median();
            if ($median === 0 || $median === null) return false;

            $variacion = $median * 0.25;
            $limiteSuperior = $median + $variacion;
            $limiteInferior = $median - $variacion;

            $filteredCount = $group->filter(function (Transaction $data) use ($limiteInferior, $limiteSuperior) {
                return $data->amount > $limiteInferior && $data->amount < $limiteSuperior;
            })->count();

            $totalCount = $group->count();
            if ($totalCount === 0) return false; // Evitar división por cero

            $coherencia = $filteredCount / $totalCount;

            if ($filteredCount >= 2 && $coherencia >= 0.65) {
                $group->each(function (Transaction $data) use ($median) {
                    $data->monto_promedio = (float) $median;
                });
                return true;
            }
            return false;
        });

        // --- 4. ENCONTRAR PARES CONCURRENTES ---
        // Larastan ya sabe el tipo de $coherentGroups, así que puede inferir $group.
        $concurrentGroups = $coherentGroups->map(function (EloquentCollection $group) {

            $sortedGroup = $group->sortBy('date_operation');

            $concurrentPairs = $sortedGroup->sliding(2)->filter(function (EloquentCollection $pair) {
                /** @var Transaction $first */
                $first = $pair->first();
                /** @var Transaction $last */
                $last = $pair->last();

                $fechaA = Carbon::parse($first->date_operation);
                $fechaB = Carbon::parse($last->date_operation);
                $diff = $fechaA->diffInDays($fechaB);
                return $diff >= 25 && $diff <= 35;
            })->values();

            if ($concurrentPairs->count() > 1) {
                $transactions = $concurrentPairs->flatMap(function (EloquentCollection $pair, $index) use ($concurrentPairs) {
                    /** @var Transaction|null $first */
                    $first = $pair->first();
                    /** @var Transaction|null $last */
                    $last = $pair->last();

                    $items = collect([]);
                    if ($first) $items[] = $first;
                    if ($concurrentPairs->count() - 1 == $index && $last) {
                        $items->push($last);
                    }
                    return $items;
                });
                return $transactions->unique('id');
            }
            return false;
        })->filter();

        // --- 5. FORMATEAR SALIDA ---
        return $concurrentGroups->map(function (EloquentCollection $item) {
            /** @var Transaction|null $primer */
            $primer = $item->first();
            if ($primer === null) return null; // Seguridad

            $descripcion_detectada = $primer->detail_name;
            $monto_promedio = $primer->monto_promedio;

            return [
                'descripcion_detectada' => $descripcion_detectada,
                'monto_promedio' => $monto_promedio,
                'transacciones_encontradas' => $item->map(function (Transaction $tx) {
                    return (object) [
                        'fecha' => $tx->date_operation,
                        'monto' => $tx->amount
                    ];
                })
            ];
        })->filter(); // Filtra cualquier 'null' que se haya colado
    }

    public function cashFlowWeekly(): Collection
    {
        $weeklyCashFlow = $this->transactions->groupBy(function ($item) {
            return Carbon::parse($item->date_operation)->startOfWeek()->format('Y-m-d');
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

    // public function spendingVelocity(): Collection
    // {
    //     return $this->transactions->groupBy(function ($item) {
    //         return Carbon::parse($item->date_operation)->monthOfYear();
    //     })->map(function ($group, $month) {
    //         $totalExpense = round($group->where('type_transaction', 'expense')->sum('amount'), 2);
    //         $daysOfPeriod = Carbon::create(2025, $month, 1)->daysInMonth();
    //         $velocity = round($totalExpense / $daysOfPeriod, 2);
    //         $monthName = Carbon::create(null, $month, 1)->monthName;
    //         return (object) [
    //             'periodo' => $monthName . ' 2025',
    //             'gasto_total' => $totalExpense,
    //             'dias_en_periodo' => $daysOfPeriod,
    //             'velocidad_de_gasto' => $velocity,
    //             'diagnostico' => 'En promedio, gastaste ' . $velocity . ' por día durante el mes de ' . $monthName
    //         ];
    //     })->flatten();
    // }

    public function getLargestExpenseInDays(int $days): object
    {
        $transaction =  $this->transactions->where('type_transaction', 'expense')->filter(function ($item) {
            $subtractDays = now()->subDay();
            return $item->date_operation > $subtractDays;
        })->sortByDesc('amount')->first();

        return (object) [
            'periodo_analizado' => 'Ultimos ' . $days . ' dias',
            'transaction' => $transaction
        ];
    }

    public function drillDown(string $category): object
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

    // public function correlationByCategory(string $category): Collection
    // {

    //     $base = $this->transactions
    //         ->groupBy('cat_name')
    //         ->map(function ($group) {
    //             $month = $group->groupBy(function ($item) {
    //                 return Carbon::parse($item->date_operation)->monthName;
    //             });
    //             return $month;
    //         })->map(function ($sub) {
    //             return $sub->map(function ($data) {
    //                 $expense = $data->where('type_transaction', 'expense')->sum('amount');
    //                 $income = $data->where('type_transaction', 'income')->sum('amount');
    //                 return $expense - $income;
    //             });
    //         });

    //     $categormainCategoryy = $base->get($category);
    //     $diferentCategory = $base->filter(function ($value, $key) use ($category) {
    //         return $key !== $category;
    //     });

    //     return $diferentCategory->map(function ($item) use ($categormainCategoryy) {
    //         return $categormainCategoryy->flatMap(function ($sub, $subkey) use ($item) {
    //             return [$subkey => [$sub, $item->get($subkey)]];
    //         })->values();
    //     })->map(function ($item) {
    //         $r = 0;
    //         $n = $item->count();
    //         $sum_x = $item->reduce(function ($carry, $item) {
    //             return $carry + $item[0];
    //         }, 0);
    //         $sum_y = $item->reduce(function ($carry, $item) {
    //             return $carry + $item[1];
    //         }, 0);
    //         $sum_x2 = $item->map(function ($item) {
    //             return $item[0] * $item[0];
    //         })->sum();
    //         $sum_y2 = $item->map(function ($item) {
    //             return $item[1] * $item[1];
    //         })->sum();
    //         $sum_xy =  $item->map(function ($item) {
    //             return $item[0] * $item[1];
    //         })->sum();
    //         $numerador = ($n * $sum_xy - $sum_x * $sum_y);
    //         $denominador = sqrt(($n * $sum_x2 - pow($sum_x, 2)) * ($n * $sum_y2 - pow($sum_y, 2)));
    //         if ($numerador != 0 && $denominador != 0) {
    //             $r = $numerador / $denominador;
    //         }
    //         return (object) [
    //             'data_points_used' => $n,
    //             'correlation_coefficient' => $r
    //         ];
    //     })->map(function ($item, $key) use ($category) {
    //         $correlation_coefficient = $item->correlation_coefficient;
    //         $strength = '';
    //         if ($correlation_coefficient >= 0.9) {
    //             $strength = 'Muy fuerte';
    //         } elseif ($correlation_coefficient >= 0.7) {
    //             $strength = 'Fuerte';
    //         } elseif ($correlation_coefficient >= 0.4) {
    //             $strength = 'Moderada';
    //         } elseif ($correlation_coefficient >= 0.1) {
    //             $strength = 'Débil';
    //         } else {
    //             $strength = 'Nula o despreciable';
    //         }
    //         $varY = $key;
    //         $varX = $category;
    //         $direction = $correlation_coefficient > 0 ? 'Positiva' : 'Negativa';
    //         $interpretation = "Se ha encontrado una $strength correlación $direction entre $varX y $varY. ";

    //         if ($direction === 'Positiva') {
    //             $interpretation .= "Esto sugiere que cuando aumentas tus gastos en $varX, también aumentan los de $varY.";
    //         } else {
    //             $interpretation .= "Esto indica que cuando aumentas tus gastos en $varX, los de $varY tienden a disminuir.";
    //         }
    //         return (object) [
    //             'analysis_type' => "Correlación de Gastos Mensuales",
    //             'variables' => (object)[
    //                 'x' => $varX,
    //                 'y' => $varY
    //             ],
    //             'correlation_coefficient' => $correlation_coefficient,
    //             'strength' => $strength,
    //             'direction' => $direction,
    //             'interpretation' => $interpretation,
    //             'data_points_used' => $item->data_points_used
    //         ];
    //     })->values();
    // }

    /**
     * @return array<string, mixed>
     */
    public function getQuantityByAmountRange(): array
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

    /**
     * @return Collection<int, \App\DTOs\VolatilityReportDTO>
     */
    public function getVolatileCategory(): Collection
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
                if ($promedio > 0) {
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
                    $diagnostic = VolatilityDiagnostic::VeryStable;
                } elseif ($coeficienteVariacion > -1.5 && $coeficienteVariacion <= -0.5) {
                    $diagnostic = VolatilityDiagnostic::Stable;
                } elseif ($coeficienteVariacion > -0.5 && $coeficienteVariacion <= 0.5) {
                    $diagnostic = VolatilityDiagnostic::Normal;
                } elseif ($coeficienteVariacion > 0.5 && $coeficienteVariacion <= 1.5) {
                    $diagnostic = VolatilityDiagnostic::Volatile;
                } else {
                    $diagnostic = VolatilityDiagnostic::VeryVolatile;
                }
                return new VolatilityReportDTO(
                    categoria: $key,
                    volatilidad: $coeficienteVariacion,
                    diagnostico: $diagnostic
                );
            })->values();
    }

    public function getParetoCategory(): object
    {
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
            if ($acc >= 80) break;
        }
        $accPercentage = $pareto->last()->acumulado_porc;
        $quantity = $pareto->count();
        return (object) [
            'principio_pareto' => $pareto,
            'conclusion' => "El $accPercentage% de todos tus gastos provienen de solo $quantity categorias",
        ];
    }
}
