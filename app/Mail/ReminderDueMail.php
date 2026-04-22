<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;

class ReminderDueMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public array $applicationIds) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Applications due for follow-up',
            to: [$this->user->email],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reminder-due',
            with: ['applications' => \App\Models\Application::with('canonicalJob:id,title,company_name')->whereIn('id', $this->applicationIds)->get()],
        );
    }
}
