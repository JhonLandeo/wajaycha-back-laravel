<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Detail;
use Illuminate\Support\Facades\Log;

/**
 * Owning service for the Entity Resolution subdomain: given a clean entity name,
 * resolve it to an existing Detail or create one.
 *
 * Callers orchestrate; the decision of what counts as "the same merchant" lives here
 * and nowhere else.
 */
class DetailResolver
{
    /**
     * Trigram similarity above which two clean entities are considered the same merchant.
     *
     * This is the effective value both former call sites hardcoded. It is not tuned here:
     * changing it changes matching for every ingestion path at once.
     */
    public const THRESHOLD_TRIGRAM = 0.6;

    /**
     * Resolve the clean entity to a Detail owned by the user, creating one if nothing
     * similar enough exists.
     */
    public function resolveOrCreate(
        int $userId,
        string $descriptionRaw,
        string $cleanEntity,
        ?string $operationType
    ): Detail {
        $detail = $this->findExisting($userId, $cleanEntity);

        Log::info("🔍 Buscando Detalle para Entidad Limpia: {$cleanEntity}. ".($detail ? "Encontrado ID: {$detail->id}" : 'No encontrado.'));

        if (! $detail) {
            $detail = Detail::create([
                'user_id' => $userId,
                'description' => $descriptionRaw,
                'operation_type' => $operationType,
                'entity_clean' => $cleanEntity,
            ]);

            Log::info("🆕 Nuevo Detalle creado: {$descriptionRaw} (Clean: {$cleanEntity})");

            return $detail;
        }

        if (empty($detail->entity_clean)) {
            $detail->update(['entity_clean' => $cleanEntity]);
        }

        return $detail;
    }

    /**
     * Find the closest Detail owned by the user, by exact clean entity or trigram
     * similarity above the threshold. Returns the most similar candidate.
     */
    public function findExisting(int $userId, string $cleanEntity): ?Detail
    {
        return Detail::where('user_id', $userId)
            ->where(function ($query) use ($cleanEntity) {
                $query->where('entity_clean', $cleanEntity)
                    ->orWhereRaw('similarity(entity_clean, ?) > ?', [$cleanEntity, self::THRESHOLD_TRIGRAM]);
            })
            ->orderByRaw('similarity(entity_clean, ?) DESC', [$cleanEntity])
            ->first();
    }
}
