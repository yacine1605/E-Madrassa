<?php

namespace App\Support;

use App\Mail\NotificationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The email outbox's worker (ARC-05, FR-NOT-01): claims due emails across schools, sends each
 * one, reports it. A failure never reaches the operation that notified; after five attempts
 * the email stays as a failure the school can see and send again.
 */
final class Outbox
{
    private const LEASE_SECONDS = 120;

    /** @return array{sent: int, failed: int} */
    public function work(int $limit = 50): array
    {
        $done = ['sent' => 0, 'failed' => 0];
        $labels = collect(DB::select('SELECT code, labels FROM platform.notification_event'))
            ->mapWithKeys(fn ($event) => [$event->code => json_decode($event->labels, true)]);
        foreach (DB::select('SELECT id, email, name, event, school, locale FROM platform.claim_outbox(?, ?)', [$limit, self::LEASE_SECONDS]) as $email) {
            $title = $labels[$email->event][$email->locale] ?? $labels[$email->event]['fr'] ?? $labels[$email->event]['ar'];
            try {
                Mail::to($email->email, $email->name)->send(new NotificationMail($email->name, $title, $email->school));
                DB::select('SELECT platform.finish_outbox(?, NULL)', [$email->id]);
                $done['sent']++;
            } catch (Throwable $e) {
                // The reason is kept for the school, without the address or the content (NFR-SEC-06).
                DB::select('SELECT platform.finish_outbox(?, ?)', [$email->id, class_basename($e).': '.mb_substr($e->getMessage(), 0, 300)]);
                $done['failed']++;
            }
        }
        return $done;
    }
}
