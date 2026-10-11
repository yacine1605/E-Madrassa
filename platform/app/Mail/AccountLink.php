<?php

namespace App\Mail;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Invitation (link valid 72 h) or password recovery (valid 1 h). Queued, its link is encrypted in the queue (NFR-SEC-06). */
final class AccountLink extends Mailable implements ShouldBeEncrypted
{
    public function __construct(public string $purpose, public string $name, public string $url, public ?string $school = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->purpose === 'activation'
            ? ($this->school ? __('mail.activation.subject_school', ['school' => $this->school]) : __('mail.activation.subject'))
            : __('mail.recovery.subject'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.account-link');
    }
}
