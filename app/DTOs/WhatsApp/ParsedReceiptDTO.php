<?php

declare(strict_types=1);

namespace App\DTOs\WhatsApp;

final readonly class ParsedReceiptDTO
{
    public function __construct(
        public bool $isValid,
        public ?float $amount,
        public ?string $destination,
        public ?string $origin,
        public ?string $dateOperation,
        public ?string $type,
        public ?string $message
    ) {}

    /**
     * @param array{
     *   is_valid_receipt?: bool,
     *   is_valid_transaction?: bool,
     *   amount?: float|int|string,
     *   destination?: string,
     *   origin?: string,
     *   date_operation?: string,
     *   type_transaction?: string,
     *   message?: string
     * } $data
     */
    public static function fromGemini(array $data): self
    {
        return new self(
            isValid: $data['is_valid_receipt'] ?? $data['is_valid_transaction'] ?? false,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            destination: $data['destination'] ?? null,
            origin: $data['origin'] ?? null,
            dateOperation: $data['date_operation'] ?? null,
            type: $data['type_transaction'] ?? null,
            message: $data['message'] ?? null
        );
    }
}
