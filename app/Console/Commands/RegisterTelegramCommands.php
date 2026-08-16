<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BotCommand;
use App\Services\Capture\TelegramChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Tells Telegram which commands the bot understands.
 *
 * Deliberately NOT scheduled. This is deploy-time setup that belongs beside
 * setting the webhook: the list lives on Telegram's side, survives restarts, and
 * only needs republishing when {@see BotCommand} changes. Running it on a
 * schedule would spend a request a day to send Telegram a list it already has.
 *
 * It exists as a command rather than a curl line in a runbook for one reason: the
 * payload is derived from the enum the bot actually dispatches on, so the list
 * Telegram advertises cannot drift from the list the bot answers. A runbook can.
 *
 * The Command orchestrates and holds no string of its own beyond its own output
 * (`.agents/rules/01-laravel-core.md`).
 */
class RegisterTelegramCommands extends Command
{
    protected $signature = 'app:register-telegram-commands {--dry-run : Print the list without publishing it}';

    protected $description = 'Publica en Telegram la lista de comandos del bot, para que el cliente los muestre';

    public function handle(TelegramChannel $telegram): int
    {
        $commands = BotCommand::registration();

        foreach ($commands as $command) {
            $this->line("/{$command['command']} — {$command['description']}");
        }

        if ((bool) $this->option('dry-run')) {
            $this->info('[dry-run] '.count($commands).' comandos, nada publicado.');

            return self::SUCCESS;
        }

        if ((string) config('services.telegram.bot_token') === '') {
            // Sin token la URL queda como `https://api.telegram.org/bot/setMyCommands`
            // y Telegram contesta 404, que en los logs se lee como un problema de la
            // lista. Se dice lo que realmente pasa, que ademas es lo unico que el
            // operador puede arreglar.
            $this->error('TELEGRAM_BOT_TOKEN no está configurado: no hay a qué bot publicarle los comandos.');

            return self::FAILURE;
        }

        if (! $telegram->registerCommands($commands)) {
            // El detalle ya quedo en el log, saneado. Aca solo importa el codigo de
            // salida: un deploy que sigue adelante creyendo que publico la lista deja
            // el menu invisible, que es indistinguible de no haber corrido nada.
            $this->error('Telegram no aceptó la lista de comandos. Revisa el log.');

            return self::FAILURE;
        }

        $summary = 'Comandos publicados en Telegram: '.count($commands).'.';

        $this->info($summary);
        Log::info($summary);

        return self::SUCCESS;
    }
}
