<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NotificationSummaryByDay extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    public object $summary;

    public function __construct(object $summary)
    {
        $this->summary = $summary;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Resumen Diario Wajaycha');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.summary_day', with: ['summary' => $this->summary]);
    }
}
