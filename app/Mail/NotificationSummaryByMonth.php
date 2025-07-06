<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationSummaryByMonth extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $budgetDeviation;

    /**
     * Create a new message instance.
     */
    public function __construct($budgetDeviation)
    {
        $this->budgetDeviation = $budgetDeviation;
    }


    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Notification Summary By Month',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.summary_month',
            with: [
                'budgetDeviation' => $this->budgetDeviation
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
