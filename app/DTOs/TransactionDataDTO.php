<?php
namespace App\DTOs;

 class TransactionDataDTO
{
    public function __construct(
        public readonly float $amount,
        public readonly string $date_operation,
        public readonly string $type_transaction,
        public readonly string $description
    ) {
       
    }
}
