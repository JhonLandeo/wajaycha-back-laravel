<?php

declare(strict_types=1);

use App\DTOs\Shopping\PantryStock;
use App\Enums\Unit;
use App\Services\Shopping\PantryPriority;
use Carbon\CarbonImmutable;

it('ordena el vencimiento explícito, la vida útil estimada y los productos sin fecha', function () {
    $today = CarbonImmutable::parse('2026-11-09 08:00:00', 'America/Lima');
    $pantry = [
        new PantryStock(10, Unit::Kg, 1.0, CarbonImmutable::parse('2026-11-20'), 'Sin urgencia'),
        new PantryStock(11, Unit::Kg, 1.0, null, 'Estimado', CarbonImmutable::parse('2026-11-08'), 2),
        new PantryStock(12, Unit::Kg, 1.0, CarbonImmutable::parse('2026-11-11'), 'Explicito'),
        new PantryStock(13, Unit::Kg, 1.0, null, 'Sin fecha'),
    ];

    $service = new PantryPriority;

    expect($service->orderedProductIds($pantry, $today))->toBe([11, 12, 10, 13])
        ->and($service->daysLeftByProduct($pantry, $today))->toMatchArray([
            10 => 11,
            11 => 1,
            12 => 2,
            13 => null,
        ]);
});

it('descarta lotes vencidos y conserva el más urgente por producto', function () {
    $today = CarbonImmutable::parse('2026-11-09 08:00:00', 'America/Lima');
    $pantry = [
        new PantryStock(20, Unit::Kg, 1.0, CarbonImmutable::parse('2026-11-08'), 'Vencido'),
        new PantryStock(21, Unit::Kg, 1.0, CarbonImmutable::parse('2026-11-18'), 'Lote largo'),
        new PantryStock(21, Unit::Kg, 1.0, CarbonImmutable::parse('2026-11-10'), 'Lote corto'),
    ];

    $days = (new PantryPriority)->daysLeftByProduct($pantry, $today);

    expect($days)->not->toHaveKey(20)
        ->and($days[21])->toBe(1);
});
