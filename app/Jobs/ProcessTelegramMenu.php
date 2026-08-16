<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BotMenuAction;
use App\Models\User;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\TelegramChannel;
use App\Services\Coaching\BudgetDigestService;
use App\Support\Redact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers the bot's menu: either showing it, or answering the button that was
 * pressed.
 *
 * The pull half of the conversation, and the counterpart to
 * {@see ProcessTelegramCapture}. The distinction that matters is who started it:
 * the coach and the digest speak unprompted and therefore have to earn the
 * interruption — hence the ledger, the bands, the "never repeat a month". Nothing
 * here is unprompted. The user asked, so repeating an answer is not noise, and
 * silence is not an option: a question that gets no reply reads as a broken bot.
 *
 * `TelegramChannel` is depended on by name rather than through
 * `CaptureChannelRegistry`, because buttons are not part of the capture port. The
 * menu is a Telegram feature until a second channel can express the same thing,
 * and pretending otherwise would put a keyboard behind an interface that cannot
 * carry one.
 */
class ProcessTelegramMenu implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The text command that opens the menu.
     *
     * Public because the controller has to recognise it before dispatching:
     * every other text message is a capture attempt and goes to Gemini, so a
     * command that reached the capture pipeline would be billed as a receipt and
     * answered as an unreadable one.
     */
    public const COMMAND = '/menu';

    /**
     * @param  string|null  $callbackQueryId  present when a button was pressed,
     *                                        absent when the menu was opened by
     *                                        command. Telegram spins an indicator
     *                                        on the button until it is answered.
     * @param  string|null  $action  the raw `callback_data`, deliberately not the
     *                               enum: an unknown value has to survive being
     *                               serialised into the queue payload so it can be
     *                               answered politely rather than crashing the job.
     */
    public function __construct(
        private readonly string $chatId,
        private readonly ?string $callbackQueryId = null,
        private readonly ?string $action = null,
    ) {}

    public function handle(
        TelegramChannel $telegram,
        ChannelIdentityResolver $identities,
        BudgetDigestService $digest,
    ): void {
        // Cerrar el callback va primero y pase lo que pase. Es lo unico que apaga
        // el indicador girando en el cliente, y el usuario lo nota mucho antes que
        // la respuesta — una respuesta perfecta con el boton trabado se lee igual
        // que un bot colgado.
        if ($this->callbackQueryId !== null) {
            $telegram->answerCallback($this->callbackQueryId);
        }

        $user = $identities->resolve($telegram->key(), $this->chatId);

        if (! $user) {
            Log::warning('❌ Telegram: menú pedido por el chat '.Redact::id($this->chatId).' sin vincular.');
            $telegram->reply($this->chatId, '❌ Tu cuenta de Telegram no está vinculada. Abre la app y genera tu enlace de vinculación.');

            return;
        }

        if ($this->action === null) {
            $telegram->replyWithKeyboard($this->chatId, '¿Qué querés saber?', BotMenuAction::keyboard());

            return;
        }

        $action = BotMenuAction::tryFromCallback($this->action);

        if ($action === null) {
            // Un boton vive para siempre en el historial del chat. Este es el
            // usuario tocando una opcion de un mensaje viejo que ya no existe, no
            // un atacante: se le contesta y se le vuelve a ofrecer el menu actual.
            Log::info('ℹ️ Telegram: acción de menú desconocida, se reabre el menú.');
            $telegram->replyWithKeyboard(
                $this->chatId,
                'Esa opción ya no está disponible. Estas son las de ahora:',
                BotMenuAction::keyboard(),
            );

            return;
        }

        $telegram->reply($this->chatId, $this->answer($action, $user, $digest));
    }

    private function answer(BotMenuAction $action, User $user, BudgetDigestService $digest): string
    {
        return match ($action) {
            BotMenuAction::HOW_AM_I_DOING => $this->howAmIDoing($user, $digest),
        };
    }

    /**
     * The standing budget board, or the fact that there is nothing on it.
     *
     * The null branch is the whole difference between push and pull. As a morning
     * message, "nothing is over budget" is wallpaper and the digest stays silent —
     * `BudgetDigestComposer` returns null on purpose. As an answer to a question
     * the user just asked, silence is a bug, and "todo bien" is the actual answer.
     */
    private function howAmIDoing(User $user, BudgetDigestService $digest): string
    {
        $board = $digest->composeOnDemand($user);

        if ($board !== null) {
            return $board;
        }

        if (! (bool) config('coaching.enabled')) {
            // No se dice "vas bien": el subsistema esta apagado, asi que nadie
            // miro. Es la misma distincion que coaching_evaluations existe para
            // registrar — "no cruzaste nada" y "no te mire" no son la misma frase.
            return 'Ahora mismo no puedo revisar tus presupuestos. Probá más tarde.';
        }

        return 'Ningún presupuesto pasado ni en camino a pasarse. Vas bien.';
    }

    public function failed(Throwable $exception): void
    {
        Log::error('❌ Job ProcessTelegramMenu falló inesperadamente: '.Redact::secrets($exception->getMessage()));

        try {
            app(TelegramChannel::class)->reply($this->chatId, '❌ No pude responderte ahora mismo. Intentá de nuevo en un momento.');
        } catch (Throwable $e) {
            Log::error('❌ No se pudo avisar del fallo al remitente: '.Redact::secrets($e->getMessage()));
        }
    }
}
