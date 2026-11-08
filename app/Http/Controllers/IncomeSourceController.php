<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Income\AddIncomeSourceVersionAction;
use App\Actions\Income\CloseIncomeSourceAction;
use App\Actions\Income\CreateIncomeSourceAction;
use App\Actions\Income\DeleteIncomeSourceAction;
use App\Actions\Income\UpdateIncomeSourceAction;
use App\Http\Requests\Income\CloseIncomeSourceRequest;
use App\Http\Requests\Income\StoreIncomeSourceRequest;
use App\Http\Requests\Income\StoreIncomeSourceVersionRequest;
use App\Http\Requests\Income\UpdateIncomeSourceRequest;
use App\Models\IncomeSource;
use App\Repositories\Contracts\IncomeSourceRepositoryContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD de las fuentes de ingreso planificado del usuario autenticado.
 *
 * El usuario siempre sale de `Auth::id()`; ninguna ruta acepta un user_id. Una
 * fuente de otro usuario responde 404, igual que una inexistente.
 */
final class IncomeSourceController extends Controller
{
    public function __construct(
        private readonly IncomeSourceRepositoryContract $sources,
        private readonly CreateIncomeSourceAction $create,
        private readonly UpdateIncomeSourceAction $update,
        private readonly AddIncomeSourceVersionAction $addVersion,
        private readonly CloseIncomeSourceAction $close,
        private readonly DeleteIncomeSourceAction $delete,
    ) {}

    public function index(): JsonResponse
    {
        $sources = $this->sources->allForUser((int) Auth::id())
            ->map(fn (IncomeSource $source): array => $this->present($source))
            ->all();

        return response()->json(['data' => $sources]);
    }

    public function store(StoreIncomeSourceRequest $request): JsonResponse
    {
        $source = $this->create->execute((int) Auth::id(), $request->validated());

        return response()->json(['data' => $this->present($source)], 201);
    }

    public function update(UpdateIncomeSourceRequest $request, IncomeSource $incomeSource): JsonResponse
    {
        $this->ensureOwned($incomeSource);

        $source = $this->update->execute($incomeSource, $request->validated());

        return response()->json(['data' => $this->present($source->load('versions'))]);
    }

    public function storeVersion(
        StoreIncomeSourceVersionRequest $request,
        IncomeSource $incomeSource
    ): JsonResponse {
        $this->ensureOwned($incomeSource);

        $source = $this->addVersion->execute($incomeSource, $request->validated());

        return response()->json(['data' => $this->present($source)]);
    }

    public function close(
        CloseIncomeSourceRequest $request,
        IncomeSource $incomeSource
    ): JsonResponse {
        $this->ensureOwned($incomeSource);

        $source = $this->close->execute($incomeSource, $request->validated());

        return response()->json(['data' => $this->present($source)]);
    }

    public function destroy(IncomeSource $incomeSource): JsonResponse
    {
        $this->ensureOwned($incomeSource);

        $this->delete->execute($incomeSource);

        return response()->json(['status' => 'deleted']);
    }

    private function ensureOwned(IncomeSource $source): void
    {
        if ((int) $source->user_id !== (int) Auth::id()) {
            abort(404);
        }
    }

    /**
     * @return array<int, array{id: int, amount: string, effective_from: string, effective_to: string|null}>
     */
    private function versions(IncomeSource $source): array
    {
        $versions = [];

        foreach ($source->versions as $version) {
            $versions[] = [
                'id' => (int) $version->id,
                'amount' => (string) $version->amount,
                'effective_from' => \Carbon\CarbonImmutable::parse((string) $version->effective_from)->toDateString(),
                'effective_to' => $version->effective_to !== null
                    ? \Carbon\CarbonImmutable::parse((string) $version->effective_to)->toDateString()
                    : null,
            ];
        }

        return $versions;
    }

    /**
     * @return array{
     *   id: int,
     *   name: string,
     *   kind: string,
     *   kind_label: string,
     *   versions: array<int, array{id: int, amount: string, effective_from: string, effective_to: string|null}>
     * }
     */
    private function present(IncomeSource $source): array
    {
        return [
            'id' => (int) $source->id,
            'name' => (string) $source->name,
            'kind' => $source->kind->value,
            'kind_label' => $source->kind->label(),
            'versions' => $this->versions($source),
        ];
    }
}
