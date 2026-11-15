<?php

declare(strict_types=1);

namespace App\Enums;

enum RecipeOrigin: string
{
    case Catalog = 'catalog';
    case User = 'user';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Catalog => 'Catálogo',
            self::User => 'Propia',
            self::Ai => 'Propuesta IA',
        };
    }
}
