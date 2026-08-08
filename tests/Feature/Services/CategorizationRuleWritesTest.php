<?php

declare(strict_types=1);

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Detail;
use App\Models\User;
use App\Services\CategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(CategorizationService::class);
    $this->user = User::factory()->create();
    $this->detail = Detail::factory()->create(['user_id' => $this->user->id]);
    $this->comida = Category::factory()->create(['user_id' => $this->user->id]);
    $this->transporte = Category::factory()->create(['user_id' => $this->user->id]);
});

it('recuerda una regla inferida cuando el comercio no tenia ninguna', function () {
    $this->service->rememberInferredRule($this->user->id, $this->detail->id, $this->comida->id);

    expect(CategorizationRule::sole()->category_id)->toBe($this->comida->id);
});

it('una inferencia nunca pisa una regla que ya existe', function () {
    $this->service->setRule($this->user->id, $this->detail->id, $this->comida->id);

    // Esta es la razon por la que el arreglo NO es cambiar todo a updateOrCreate:
    // la cascada infiere desde un match vectorial flojo, y esa adivinanza no puede
    // sobrescribir lo que el usuario ya decidio.
    $this->service->rememberInferredRule($this->user->id, $this->detail->id, $this->transporte->id);

    expect(CategorizationRule::sole()->category_id)->toBe($this->comida->id);
});

it('una correccion explicita SI cambia la regla existente', function () {
    $this->service->setRule($this->user->id, $this->detail->id, $this->comida->id);

    // El bug que este archivo existe para cerrar: con firstOrCreate esto era un
    // no-op silencioso, asi que corregir la categoria desde la app no cambiaba nada
    // y el comercio seguia cayendo mal para siempre.
    $this->service->setRule($this->user->id, $this->detail->id, $this->transporte->id);

    expect(CategorizationRule::count())->toBe(1)
        ->and(CategorizationRule::sole()->category_id)->toBe($this->transporte->id);
});

it('crea la regla cuando la correccion llega sin regla previa', function () {
    $this->service->setRule($this->user->id, $this->detail->id, $this->transporte->id);

    expect(CategorizationRule::sole()->category_id)->toBe($this->transporte->id);
});

it('mantiene una regla por usuario y comercio, no una por correccion', function () {
    $this->service->setRule($this->user->id, $this->detail->id, $this->comida->id);
    $this->service->setRule($this->user->id, $this->detail->id, $this->transporte->id);
    $this->service->setRule($this->user->id, $this->detail->id, $this->comida->id);

    expect(CategorizationRule::count())->toBe(1);
});

it('no toca la regla de otro usuario sobre el mismo comercio', function () {
    $otro = User::factory()->create();
    $suCategoria = Category::factory()->create(['user_id' => $otro->id]);

    $this->service->setRule($otro->id, $this->detail->id, $suCategoria->id);
    $this->service->setRule($this->user->id, $this->detail->id, $this->comida->id);

    expect(CategorizationRule::where('user_id', $otro->id)->sole()->category_id)->toBe($suCategoria->id)
        ->and(CategorizationRule::where('user_id', $this->user->id)->sole()->category_id)->toBe($this->comida->id);
});
