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
    public string $monthName;

    public function __construct($budgetDeviation, $monthName)
    {
        $this->budgetDeviation = $budgetDeviation;
        $this->monthName = ucfirst($monthName);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Reporte Mensual Wajaycha - {$this->monthName}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.summary_month',
            with: [
                'budgetDeviation' => $this->budgetDeviation,
                'monthName' => $this->monthName
            ],
        );
    }
}
