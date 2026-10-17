<?php

declare(strict_types=1);

namespace App\DTOs\PaydayPlan;

/**
 * The plan as it stood when the cycle was claimed. Every message, retry and
 * re-send of that cycle uses this, never the live plan (PD-8).
 *
 * Money stays a decimal string end to end; the percentage is applied on integer
 * cents, so no float rounding can touch what the user is told they committed to.
 */
final readonly class PaydayPlanSnapshot
{
    private function __construct(
        public int $paydayDay,
        public string $declaredSalary,
        public int $savingsPercent,
        public string $savingsAmount,
    ) {}

    public static function fromDeclaration(int $paydayDay, string $declaredSalary, int $savingsPercent): self
    {
        $salaryCents = self::toCents($declaredSalary);
        $savingsCents = intdiv($salaryCents * $savingsPercent + 50, 100);

        return new self(
            $paydayDay,
            self::fromCents($salaryCents),
            $savingsPercent,
            self::fromCents($savingsCents),
        );
    }

    /**
     * @param  array{payday_day: int, declared_salary: string, savings_percent: int, savings_amount: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['payday_day'],
            (string) $data['declared_salary'],
            (int) $data['savings_percent'],
            (string) $data['savings_amount'],
        );
    }

    /** @return array{payday_day: int, declared_salary: string, savings_percent: int, savings_amount: string} */
    public function toArray(): array
    {
        return [
            'payday_day' => $this->paydayDay,
            'declared_salary' => $this->declaredSalary,
            'savings_percent' => $this->savingsPercent,
            'savings_amount' => $this->savingsAmount,
        ];
    }

    private static function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        return ((int) $whole) * 100 + (int) $fraction;
    }

    private static function fromCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
