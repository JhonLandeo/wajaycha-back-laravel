<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un turno del chat que ajusta el menú semanal (ADR-0020).
 *
 * `role` es `user` o `assistant`. `payload` guarda las operaciones
 * estructuradas que la IA propuso; nada se aplica hasta que el usuario las
 * confirma.
 *
 * @property int $id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $week_start
 * @property string $role
 * @property string $content
 * @property array<string, mixed>|null $payload
 * @property-read User $user
 */
class MenuChatMessage extends Model
{
    /** @use HasFactory<\Database\Factories\MenuChatMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'week_start',
        'role',
        'content',
        'payload',
    ];

    protected $casts = [
        'week_start' => 'date',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
