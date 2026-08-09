<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Capture\RegisterCapturedTransactionAction;
use App\DTOs\Coaching\CoachingScope;
use App\Models\User;
use App\Services\AI\GeminiTextService;
use App\Services\AI\GeminiVisionService;
use App\Services\Capture\CaptureChannel;
use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\ChannelLinkTokenRedeemer;
use App\Services\Coaching\FinancialCoachingService;
use App\Support\Redact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one Telegram message into a Transaction, or into the reply that explains
 * why it did not become one.
 *
 * Everything below the transport is the shared pipeline: the same Gemini services,
 * the same ParsedReceiptDTO, the same register action WhatsApp uses.
 */
class ProcessTelegramCapture implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Long enough to survive its own outbound budget.
     *
     * Without this the job inherited Horizon's 60-second supervisor default
     * (`config/horizon.php`), which no one had ever compared against what this
     * job actually does. The photo path makes four outbound calls — `getFile`,
     * the download, Gemini, then the reply — and the coach can add a fifth.
     * Summed from `config/http.php` through
     * {@see \App\Support\OutboundHttp::worstCaseSecondsFor()} that is roughly
     * 145 seconds of worst case, against 60 available and a supervisor set to
     * `tries: 1`, so a job killed mid-retry is never replayed and the sender
     * receives nothing — worse than the failure the retries were added to
     * survive.
     *
     * The number is not tuned by feel: `OutboundHttpBudgetTest` derives the sum
     * from config and fails if it ever exceeds this value, so raising a timeout
     * or a retry in `config/http.php` cannot silently outgrow the job again.
     */
    public int $timeout = 180;

    private const LINK_PREFIX = '/start ';

    /**
     * @param  string|null  $mediaReference  The already-chosen file id, if this was a photo.
     */
    public function __construct(
        private readonly string $chatId,
        private readonly ?string $text = null,
        private readonly ?string $mediaReference = null,
    ) {}

    public function handle(
        CaptureChannelRegistry $channels,
        ChannelIdentityResolver $identities,
        ChannelLinkTokenRedeemer $redeemer,
        GeminiVisionService $vision,
        GeminiTextService $textService,
        RegisterCapturedTransactionAction $registerAction,
    ): void {
        $channel = $channels->for('telegram');

        // 1. VINCULACION: /start <token> llega antes que cualquier identidad, porque
        //    es justamente lo que la crea.
        if ($this->isLinkAttempt()) {
            $this->link($channel, $redeemer);

            return;
        }

        // 2. IDENTIFICAR AL REMITENTE POR SU IDENTIDAD DE CANAL
        $user = $identities->resolve($channel->key(), $this->chatId);

        if (! $user) {
            // Un chat id de Telegram es un identificador de persona: sirve para
            // escribirle. Se registra su seudonimo, que alcanza para contar
            // cuantos remitentes sin vincular hay y para seguir a uno entre
            // varias lineas.
            Log::warning('❌ Telegram: chat '.Redact::id($this->chatId).' sin vincular.');
            $channel->reply($this->chatId, '❌ Tu cuenta de Telegram no está vinculada. Abre la app y genera tu enlace de vinculación.');

            return;
        }

        // 3. PARSEAR: foto o texto, mismo destino
        $parsed = $this->mediaReference !== null
            ? $this->parsePhoto($channel, $vision)
            : $textService->parseText((string) $this->text);

        if (! $parsed) {
            $channel->reply($this->chatId, '❌ No pude leer ese envío. Intenta de nuevo o contacta con soporte si el problema persiste.');

            return;
        }

        if (! $parsed->isValid) {
            $channel->reply($this->chatId, '❌ Eso no parece un movimiento con monto. Envíame un comprobante o dime cuánto gastaste.');

            return;
        }

        // 4. REGISTRAR (ORQUESTACION)
        $transaction = $registerAction->execute($user, $parsed);

        // 5. CONFIRMAR
        $isExpense = $parsed->type === 'expense';
        $description = $isExpense ? $parsed->destination : $parsed->origin;
        $channel->reply(
            $this->chatId,
            '✅ Registrado: S/ '.number_format($parsed->amount, 2)
                .($isExpense ? ' pagado a ' : ' recibido de ').$description.'.'
        );

        // 6. COACHEAR (opcional, jamas debe romper una captura ya confirmada;
        //    design.md §5.3). Un gasto sin categoria no puede acusar a nadie,
        //    y un ingreso no tiene presupuesto que rebasar.
        if ($isExpense && $transaction->category_id !== null) {
            $this->coach($user, (int) $transaction->category_id, (float) $parsed->amount);
        }
    }

    /**
     * Guarded per design.md §5.3: the Transaction is already persisted and the
     * ✅ confirmation already sent, so a coaching failure must never fail this
     * job — retrying it would re-run the capture and duplicate the transaction,
     * the same reasoning already documented on `reply()`. Caught, logged, and
     * swallowed here, never rethrown.
     *
     * Resolved via the container rather than method-injected, matching this
     * class's own `failed()` (`app(CaptureChannelRegistry::class)`) — the
     * established pattern for a best-effort side call this class does not
     * want to be forced through by `handle()`'s normal call signature.
     */
    private function coach(User $user, int $categoryId, float $amount): void
    {
        try {
            app(FinancialCoachingService::class)->speak($user, CoachingScope::forCategory($categoryId, $amount));
        } catch (Throwable $e) {
            Log::error('⚠️ Coaching falló tras una captura ya registrada: '.$e->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('❌ Job ProcessTelegramCapture falló inesperadamente: '.$exception->getMessage());

        try {
            app(CaptureChannelRegistry::class)
                ->for('telegram')
                ->reply($this->chatId, '❌ Ocurrió un error inesperado al procesar tu envío. Intenta de nuevo o contacta con soporte.');
        } catch (Throwable $e) {
            Log::error('❌ No se pudo avisar del fallo al remitente: '.$e->getMessage());
        }
    }

    private function isLinkAttempt(): bool
    {
        return $this->text !== null && str_starts_with($this->text, self::LINK_PREFIX);
    }

    private function link(CaptureChannel $channel, ChannelLinkTokenRedeemer $redeemer): void
    {
        $token = trim(substr((string) $this->text, strlen(self::LINK_PREFIX)));

        if ($redeemer->redeem($channel->key(), $this->chatId, $token)) {
            $channel->reply($this->chatId, '✅ Cuenta vinculada. Ya puedes enviarme tus comprobantes.');

            return;
        }

        // Una sola respuesta para todo rechazo. Distinguir "vencido" de "inexistente"
        // le diria a un curioso que tokens son reales.
        $channel->reply($this->chatId, '❌ Ese enlace no es válido o ya fue usado. Genera uno nuevo desde la app.');
    }

    private function parsePhoto(CaptureChannel $channel, GeminiVisionService $vision): mixed
    {
        $media = $channel->fetchMedia((string) $this->mediaReference);

        return $media ? $vision->parseReceipt($media->bytes, $media->mimeType) : null;
    }
}
