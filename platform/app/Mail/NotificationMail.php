<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The email of a notification: its title and the school's name, nothing more (BR-19). The detail stays in the application. */
final class NotificationMail extends Mailable
{
    public function __construct(public string $name, public string $title, public string $school) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.notification.subject', ['title' => $this->title, 'school' => $this->school]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.notification');
    }
}
