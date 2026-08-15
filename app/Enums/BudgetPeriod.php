<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * La unidad natural del presupuesto de una categoria, que no es una preferencia
 * de visualizacion sino dos aritmeticas distintas.
 *
 * Un RITMO se gasta de forma continua: el mes es su unidad, `spent × diasDelMes
 * / dia` responde algo real, y pasarse del monto es un hecho del mes. Comida,
 * transporte, delivery.
 *
 * Un SOBRE es un monto anual que se consume a saltos: salud, seguros,
 * mantenimiento del auto, matriculas. Proyectarlo linealmente no significa nada
 * — una sola consulta medica extrapolada a doce meses es ruido con forma de
 * advertencia — y compararlo contra un doceavo del monto produce un "ya pasaste
 * el presupuesto" sobre un presupuesto que el usuario no paso.
 *
 * Hasta que esta distincion existio, `PaceEvaluator` la intentaba adivinar con
 * `isLumpy`, que mira la FORMA del gasto de un mes ("¿domino una sola compra?").
 * Esa pregunta detecta una forma despues del hecho; no puede recuperar el HECHO
 * de que la categoria estaba presupuestada para el año. La categoria siempre lo
 * supo: lo que faltaba era donde decirlo.
 *
 * Vive en App\Enums y no como constantes del modelo `Category` a proposito. Los
 * DTOs de coaching necesitan este valor y `BoundariesTest` prohibe que App\DTOs
 * importe App\Models — un DTO que carga un modelo Eloquent deja de ser un objeto
 * de transferencia y pasa a ser un handle de base de datos con mejor nombre.
 */
enum BudgetPeriod: string
{
    /** Ritmo: el mes es la unidad y proyectar el cierre tiene sentido. */
    case MONTHLY = 'monthly';

    /** Sobre: monto anual que se consume, nunca se proyecta. */
    case YEARLY = 'yearly';

    /**
     * Los valores como strings, para las reglas de validacion y para cualquier
     * comparacion contra la columna cruda.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $period): string => $period->value, self::cases());
    }

    /**
     * Interpreta la columna, cayendo en MONTHLY ante cualquier valor que no
     * reconozca.
     *
     * `budget_period` no tiene CHECK en la base (la migracion explica por que:
     * `categories.type` tampoco lo tiene y las reglas viven en el FormRequest).
     * Sin este fallback, una fila escrita fuera de la aplicacion haria explotar
     * el barrido nocturno entero por una categoria. MONTHLY es la caida segura
     * porque es lo que significaba cada fila antes de que la columna existiera.
     */
    public static function fromColumn(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::MONTHLY;
    }
}
