<?php

namespace App\Support;

use App\Mail\GuardianAbsenceMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * FR-PRE-01: the worker of the messages to guardians (SQL 074), as the email outbox's: claims the
 * messages due across schools, sends each one, reports it. A failure never reaches the roll call
 * that raised the alert; after five attempts the message stays as a failure the school sees.
 */
final class GuardianMessages
{
    private const LEASE_SECONDS = 120;

    /** @return array{sent: int, failed: int} */
    public function work(int $limit = 50): array
    {
        $done = ['sent' => 0, 'failed' => 0];
        foreach (DB::select('SELECT * FROM platform.claim_guardian_messages(?, ?, ?)', ['email', $limit, self::LEASE_SECONDS]) as $message) {
            try {
                Mail::to($message->email)->send($this->mail($message));
                DB::select('SELECT platform.finish_guardian_message(?, NULL)', [$message->id]);
                $done['sent']++;
            } catch (Throwable $e) {
                // The reason is kept for the school, without the address or the content (NFR-SEC-06).
                DB::select('SELECT platform.finish_guardian_message(?, ?)', [$message->id, class_basename($e).': '.mb_substr($e->getMessage(), 0, 300)]);
                $done['failed']++;
            }
        }
        return $done;
    }

    /** The school's language first, then the other. */
    private function mail(object $message): GuardianAbsenceMail
    {
        $locales = $message->locale === 'ar' ? ['ar', 'fr'] : ['fr', 'ar'];
        $names = ['fr' => $message->first_name_lat ?: $message->first_name_ar, 'ar' => $message->first_name_ar ?: $message->first_name_lat];
        $since = $until = [];
        foreach ($locales as $locale) {
            $since[$locale] = Formats::date($message->from_day, $locale);
            $until[$locale] = Formats::date($message->to_day, $locale);
        }
        return new GuardianAbsenceMail($locales, $names, (int) $message->absences, $since, $until, $message->school, $message->school_phone);
    }
}
