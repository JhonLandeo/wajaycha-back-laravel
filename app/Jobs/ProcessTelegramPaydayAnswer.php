<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\PaydayPlan\AnswerPaydayCycleAction;
use App\Actions\PaydayPlan\PaydayArrivedAction;
use App\DTOs\PaydayPlan\PaydayMessage;
use App\Services\Capture\ChannelIdentityResolver;
use App\Services\Capture\TelegramChannel;
use App\Services\PaydayPlan\PaydayCallbackParser;
use App\Services\PaydayPlan\PaydayPlanComposer;
use App\Support\Redact;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers a `p:` button of the payday plan: Ya separé, Este mes no, Todavía no
 * cobré, the skip reasons and "Cobré hoy".
 *
 * It is not part of {@see ProcessTelegramMenu}: the menu's unknown-action
 * fallback would swallow an answer, and an answer needs authorising against the
 * user's own cycles. The chat is resolved to a user first; the callback never
 * names a user, so a button forwarded or forged with someone else's cycle id
 * reaches `AnswerPaydayCycleAction`, which refuses it.
 *
 * `$data` is the raw string rather than a parsed value so that malformed input
 * survives the queue payload and gets a polite reply instead of crashing the job.
 */
class ProcessTelegramPaydayAnswer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $chatId,
        private readonly ?string $callbackQueryId = null,
        private readonly ?string $data = null,
    ) {}

    public function handle(
        TelegramChannel $telegram,
        ChannelIdentityResolver $identities,
        PaydayCallbackParser $parser,
        AnswerPaydayCycleAction $answers,
        PaydayArrivedAction $arrived,
        PaydayPlanComposer $composer,
    ): void {
        // Closing the callback comes first, whatever happens next: it is what stops
        // the spinner on the user's button.
        if ($this->callbackQueryId !== null) {
            $telegram->answerCallback($this->callbackQueryId);
        }

        $user = $identities->resolve($telegram->key(), $this->chatId);

        if (! $user) {
            Log::warning('❌ Payday: botón del chat '.Redact::id($this->chatId).' sin vincular.');
            $telegram->reply($this->chatId, $composer->notLinked());

            return;
        }

        $callback = $parser->parse($this->data);
        $now = CarbonImmutable::now();

        if ($callback === null) {
            $telegram->reply($this->chatId, $composer->stale());

            return;
        }

        if ($callback->isPaid()) {
            // Sends its own reply.
            $arrived->execute($user->id, $this->chatId, $now);

            return;
        }

        $this->say($telegram, $answers->execute($user->id, $callback, $now));
    }

    private function say(TelegramChannel $telegram, PaydayMessage $message): void
    {
        if ($message->keyboard === []) {
            $telegram->reply($this->chatId, $message->text);

            return;
        }

        $telegram->replyWithKeyboard($this->chatId, $message->text, $message->keyboard);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('❌ Job ProcessTelegramPaydayAnswer falló inesperadamente: '.Redact::secrets($exception->getMessage()));

        try {
            app(TelegramChannel::class)->reply($this->chatId, '❌ No pude responderte ahora mismo. Intentá de nuevo en un momento.');
        } catch (Throwable $e) {
            Log::error('❌ No se pudo avisar del fallo al remitente: '.Redact::secrets($e->getMessage()));
        }
    }
}
