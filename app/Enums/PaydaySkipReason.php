<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the user skipped saving this month.
 *
 * The one-letter code is what travels in Telegram's 64-byte `callback_data`;
 * the backing value is what is stored. Labels are descriptive, never judgmental.
 */
enum PaydaySkipReason: string
{
    case Unexpected = 'unexpected';
    case Debt = 'debt';
    case Short = 'short';
    case Other = 'other';

    public function code(): string
    {
        return match ($this) {
            self::Unexpected => 'u',
            self::Debt => 'd',
            self::Short => 's',
            self::Other => 'o',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unexpected => 'Gasto imprevisto',
            self::Debt => 'Deuda',
            self::Short => 'No me alcanzó',
            self::Other => 'Otro',
        };
    }

    public static function fromCode(string $code): ?self
    {
        foreach (self::cases() as $reason) {
            if ($reason->code() === $code) {
                return $reason;
            }
        }

        return null;
    }
}
