<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CostAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $admin,
        public float $currentTotalUsd,
        public float $thresholdUsd,
        public float $fixedUsd,
        public float $aiUsd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Infrastructure cost alert: \${$this->currentTotalUsd} this month",
            to: [$this->admin->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.cost-alert',
            with: [
                'admin' => $this->admin,
                'total' => $this->currentTotalUsd,
                'threshold' => $this->thresholdUsd,
                'fixed' => $this->fixedUsd,
                'ai' => $this->aiUsd,
            ],
        );
    }
}
