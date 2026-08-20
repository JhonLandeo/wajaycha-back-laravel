<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\WhatsApp\ParsedReceiptDTO;
use App\Models\ChannelIdentity;
use App\Models\User;

/**
 * Fixtures shared by the capture job tests.
 *
 * They live in a class rather than as global functions in one of the test files:
 * a test file's top-level declarations only run when that file is loaded, so a
 * sibling file depending on them fatals the moment anyone runs it on its own.
 */
final class CaptureFixtures
{
    /** A user reachable at the given WhatsApp account. */
    public static function linkedSender(string $externalId = '51999888777'): User
    {
        $user = User::factory()->create();

        ChannelIdentity::factory()->create([
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => $externalId,
        ]);

        return $user;
    }

    /** A parse result the pipeline accepts: a 25.50 expense at a named merchant. */
    public static function validMovement(): ParsedReceiptDTO
    {
        return new ParsedReceiptDTO(
            isValid: true,
            amount: 25.50,
            destination: 'Bodega El Chino',
            origin: null,
            dateOperation: now()->toIso8601String(),
            type: 'expense',
            message: 'Compra rapida',
        );
    }

    /**
     * A parse result on the OTHER side of the ledger: 800.00 received from a named
     * payer.
     *
     * `origin` carries the counterparty and `destination` is left null, which is
     * the shape both Gemini prompts produce for "me pagaron" / "Te yapeó" — the
     * mirror of {@see validMovement()}. `RegisterCapturedTransactionAction` reads
     * one or the other depending on `type`, so a fixture that filled both would
     * hide a swap.
     */
    public static function receivedMovement(): ParsedReceiptDTO
    {
        return new ParsedReceiptDTO(
            isValid: true,
            amount: 800.00,
            destination: null,
            origin: 'Estudio Contable Vega',
            dateOperation: now()->toIso8601String(),
            type: 'income',
            message: 'Honorarios',
        );
    }

    /** A parse result the pipeline rejects. */
    public static function unparseableMovement(): ParsedReceiptDTO
    {
        return new ParsedReceiptDTO(false, 0.0, null, null, null, 'expense', null);
    }
}
