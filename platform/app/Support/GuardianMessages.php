<?php

namespace App\Support;

use App\Mail\GuardianAbsenceMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * FR-PRE-01: the worker of the messages to guardians (SQL 074, 075), as the email outbox's: claims
 * the messages due across schools, by email and by WhatsApp, sends each one, reports it. A failure
 * never reaches the roll call that raised the alert; after five attempts the message stays as a
 * failure the school sees, at once when it can never be sent (a phone WhatsApp cannot take, or no
 * account set up).
 */
final class GuardianMessages
{
    private const LEASE_SECONDS = 120;

    public function __construct(private WhatsApp $whatsapp) {}

    /** @return array{sent: int, failed: int} */
    public function work(int $limit = 50): array
    {
        $done = ['sent' => 0, 'failed' => 0];
        foreach (['email', 'whatsapp'] as $channel) {
            foreach (DB::select('SELECT * FROM platform.claim_guardian_messages(?, ?, ?)', [$channel, $limit, self::LEASE_SECONDS]) as $message) {
                $done[$this->send($channel, $message) ? 'sent' : 'failed']++;
            }
        }
        return $done;
    }

    private function send(string $channel, object $message): bool
    {
        if ($channel === 'whatsapp') {
            $number = WhatsApp::number($message->phone);
            $final = $number === null ? 'guardian_phone_unusable' : (!$this->whatsapp->configured() ? 'whatsapp_not_configured' : null);
            if ($final !== null) {
                DB::select('SELECT platform.fail_guardian_message(?, ?)', [$message->id, $final]);
                return false;
            }
        }
        try {
            if ($channel === 'email') Mail::to($message->email)->send($this->mail($message));
            else $this->whatsapp->send($number, $this->locales($message)[0], $this->parameters($message, $this->locales($message)[0]));
            DB::select('SELECT platform.finish_guardian_message(?, NULL)', [$message->id]);
            return true;
        } catch (Throwable $e) {
            // The reason is kept for the school, without an address, a number or the content (NFR-SEC-06).
            $reason = preg_replace(['/[^\s@<>"]+@[^\s@<>"]+/', '/\+?\d[\d .-]{6,}\d/'], '…', mb_substr($e->getMessage(), 0, 300));
            DB::select('SELECT platform.finish_guardian_message(?, ?)', [$message->id, class_basename($e).': '.$reason]);
            return false;
        }
    }

    /** The school's language first, then the other. */
    private function locales(object $message): array
    {
        return $message->locale === 'ar' ? ['ar', 'fr'] : ['fr', 'ar'];
    }

    private function name(object $message, string $locale): string
    {
        return $locale === 'ar' ? ($message->first_name_ar ?: $message->first_name_lat) : ($message->first_name_lat ?: $message->first_name_ar);
    }

    private function mail(object $message): GuardianAbsenceMail
    {
        $locales = $this->locales($message);
        $names = $since = $until = [];
        foreach ($locales as $locale) {
            $names[$locale] = $this->name($message, $locale);
            $since[$locale] = Formats::date($message->from_day, $locale);
            $until[$locale] = Formats::date($message->to_day, $locale);
        }
        return new GuardianAbsenceMail($locales, $names, (int) $message->absences, $since, $until, $message->school, $message->school_phone);
    }

    /** The template's body, in order: the pupil's first name, the absences, the window, the school and its phone. */
    private function parameters(object $message, string $locale): array
    {
        return [$this->name($message, $locale), (string) $message->absences, Formats::date($message->from_day, $locale), Formats::date($message->to_day, $locale),
            $message->school, $message->school_phone ?: '—'];
    }
}
