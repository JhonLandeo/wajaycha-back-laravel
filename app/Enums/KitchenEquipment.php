<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Equipamiento que una receta necesita y que el usuario declara tener
 * (ADR-0022). El sugeridor descarta las recetas que piden algo que falta.
 */
enum KitchenEquipment: string
{
    case Stove = 'stove';
    case Oven = 'oven';
    case Blender = 'blender';
    case PressureCooker = 'pressure_cooker';
    case Microwave = 'microwave';
    case NoCook = 'no_cook';

    public function label(): string
    {
        return match ($this) {
            self::Stove => 'Cocina',
            self::Oven => 'Horno',
            self::Blender => 'Licuadora',
            self::PressureCooker => 'Olla a presión',
            self::Microwave => 'Microondas',
            self::NoCook => 'Sin cocción',
        };
    }

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(fn (self $equipment): string => $equipment->value, self::cases());
    }
}
