<?php

declare(strict_types=1);

use App\Models\CategorizationRule;
use App\Models\Category;
use App\Models\Detail;
use App\Models\User;
use App\Services\CategorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The cascade decides the category behind every report, every Pareto reading and
 * every sentence the coach speaks. It had no tests at all. These pin the order of
 * its steps and the shape of its failure, not the AI underneath it.
 */
beforeEach(function () {
    $this->service = app(CategorizationService::class);
    $this->user = User::factory()->create();
    $this->comida = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Comida']);
    $this->transporte = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transporte']);
});

it('devuelve la regla exacta del comercio antes que cualquier otra cosa', function () {
    $detail = Detail::factory()->create(['user_id' => $this->user->id, 'entity_clean' => 'bodega el chino']);
    $this->service->setRule($this->user->id, $detail->id, $this->transporte->id);

    // El mensaje nombra "Comida" y la entidad no dice nada de transporte: gana la
    // regla aprendida, que es el paso 1 de la cascada.
    expect($this->service->findCategory($this->user->id, $detail, 'Comida'))->toBe($this->transporte->id);
});

it('no aplica la regla exacta de otro usuario sobre el mismo comercio', function () {
    $otro = User::factory()->create();
    $detail = Detail::factory()->create(['user_id' => $this->user->id, 'entity_clean' => 'bodega el chino']);

    // El otro usuario tiene su propia fila del mismo comercio y su propia
    // categoría. Compartirlas es lo que las claves compuestas de
    // `categorization_rules` ahora rechazan, y con razón: `DetailResolver`
    // nunca le entrega a un usuario el detail de otro, así que la regla
    // cruzada no era sólo ilegal, era inalcanzable.
    $suDetail = Detail::factory()->create(['user_id' => $otro->id, 'entity_clean' => 'bodega el chino']);
    $suCategoria = Category::factory()->create(['user_id' => $otro->id, 'name' => 'Transporte']);

    $this->service->setRule($otro->id, $suDetail->id, $suCategoria->id);

    expect($this->service->findCategory($this->user->id, $detail, null))->not->toBe($suCategoria->id);
});

it('usa el mensaje para elegir una categoria hoja del usuario cuando no hay regla', function () {
    $detail = Detail::factory()->create(['user_id' => $this->user->id, 'entity_clean' => 'bodega el chino']);
    // Nombre inventado a proposito: el UserObserver siembra unas cuarenta categorias
    // por usuario, asi que un nombre comun como "Taxi" resuelve a la sembrada y el
    // test probaria la siembra en vez de la cascada.
    $hija = Category::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Zurumbatico',
        'parent_id' => $this->transporte->id,
    ]);

    expect($this->service->findCategory($this->user->id, $detail, 'Zurumbatico'))->toBe($hija->id);
});

it('devuelve null cuando la cascada se queda sin respuesta', function () {
    $detail = Detail::factory()->create(['user_id' => $this->user->id, 'entity_clean' => 'comercio jamas visto']);

    // null NO es un fallo: es la señal honesta de "no se". La transaccion queda sin
    // categoria y el coach la reporta como algo que no puede vigilar.
    expect($this->service->findCategory($this->user->id, $detail, null))->toBeNull();
});

it('no inventa una categoria de otro usuario cuando no sabe', function () {
    $ajena = User::factory()->create();
    Category::factory()->create(['user_id' => $ajena->id, 'name' => 'Comida']);

    $detail = Detail::factory()->create(['user_id' => $this->user->id, 'entity_clean' => 'comercio jamas visto']);

    $resultado = $this->service->findCategory($this->user->id, $detail, null);

    if ($resultado !== null) {
        expect(Category::find($resultado)->user_id)->toBe($this->user->id);
    } else {
        expect($resultado)->toBeNull();
    }
});

it('resolver por el mensaje NO deja regla aprendida, y por eso vuelve a resolver desde cero', function () {
    $detail = Detail::factory()->create(['user_id' => $this->user->id, 'entity_clean' => 'bodega el chino']);
    $hija = Category::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Zurumbatico',
        'parent_id' => $this->transporte->id,
    ]);

    expect($this->service->findCategory($this->user->id, $detail, 'Zurumbatico'))->toBe($hija->id);

    // Solo las ramas de keyword-sobre-entidad y de vector aprenden. Las dos que
    // resuelven por el MENSAJE devuelven directo, sin grabar nada. La primera version
    // de este test derivaba lo esperado del mismo estado que medía, asi que no podia
    // fallar; esto afirma el hecho.
    expect(CategorizationRule::where('user_id', $this->user->id)->where('detail_id', $detail->id)->exists())
        ->toBeFalse();

    // Y sin el mensaje, no queda nada que la sostenga.
    expect($this->service->findCategory($this->user->id, $detail, null))->toBeNull();
});
