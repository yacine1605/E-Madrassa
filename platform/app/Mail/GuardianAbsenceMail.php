<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * FR-PRE-01: a minor's absences told to their guardian, in the school's language and the other one
 * (Arabic and French): the pupil's first name, the absences and their window, the school to call;
 * nothing more (BR-19). The pupil's first name and the window's days are given by language.
 */
final class GuardianAbsenceMail extends Mailable
{
    public function __construct(public array $locales, public array $pupil, public int $absences, public array $since, public array $until,
        public string $school, public ?string $phone) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: implode(' · ', array_map(fn (string $locale) => __('mail.guardian.subject', ['pupil' => $this->pupil[$locale]], $locale),
            $this->locales)).' — '.$this->school);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.guardian-absence');
    }
}
