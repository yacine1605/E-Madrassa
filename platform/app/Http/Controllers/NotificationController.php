<?php

namespace App\Http\Controllers;

use App\Support\CurrentSchool;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Notifications (FR-NOT-01): the bell, the person's email preferences, and, for whoever
 * manages the school's settings, the emails that could not be sent, to send them again.
 */
final class NotificationController
{
    public function __construct(private CurrentSchool $school) {}

    /** The person's own notifications (AC2), newest first, with their event's labels. */
    public function index(Request $request)
    {
        $request->validate(Paging::RULES);
        $sql = 'SELECT n.id, n.event, e.labels, n.data, n.read_at, n.created_at FROM school.notification n
            JOIN platform.notification_event e ON e.code = n.event'.($request->boolean('unread') ? ' WHERE n.read_at IS NULL' : '');
        $unread = DB::selectOne('SELECT count(*) AS n FROM school.notification WHERE read_at IS NULL')->n;
        return Paging::select($request, $sql, [], 'n.created_at DESC',
            fn ($row) => [...(array) $row, 'labels' => json_decode($row->labels, true), 'data' => json_decode($row->data, true)], whole: 100)
            + ['unread' => (int) $unread];
    }

    public function read(string $notification)
    {
        abort_unless(Str::isUuid($notification), 404);
        abort_unless(DB::update('UPDATE school.notification SET read_at = coalesce(read_at, now()) WHERE id = ?', [$notification]), 404);
        return response()->noContent();
    }

    public function readAll()
    {
        DB::update('UPDATE school.notification SET read_at = now() WHERE read_at IS NULL');
        return response()->noContent();
    }

    public function preferences()
    {
        return ['data' => array_map(fn ($row) => [...(array) $row, 'labels' => json_decode($row->labels, true)],
            DB::select('SELECT e.code AS event, e.labels, coalesce(p.email, e.email_default) AS email FROM platform.notification_event e
            LEFT JOIN school.notification_preference p ON p.event = e.code ORDER BY e.code'))];
    }

    /** `email`: for each event code, whether it also comes by email. */
    public function updatePreferences(Request $request)
    {
        $data = $request->validate(['email' => 'required|array', 'email.*' => 'boolean']);
        foreach ($data['email'] as $event => $email) {
            DB::insert('INSERT INTO school.notification_preference (tenant_id, user_id, event, email)
                SELECT ?, ?, code, ? FROM platform.notification_event WHERE code = ?
                ON CONFLICT (tenant_id, user_id, event) DO UPDATE SET email = EXCLUDED.email',
                [$this->school->id(), auth()->id(), $email, $event]);
        }
        return $this->preferences();
    }

    /** FR-NOT-01 AC1: emails that failed five times, with the reason; the notification itself was delivered in the application. */
    public function failures(Request $request)
    {
        $request->validate(Paging::RULES);
        return Paging::select($request, "SELECT o.id, o.event, o.user_id, u.name AS recipient, o.attempts, o.last_error, o.created_at
            FROM school.outbox o JOIN public.users u ON u.id = o.user_id WHERE o.status = 'failed'", [], 'o.created_at DESC');
    }

    public function retry(string $delivery)
    {
        abort_unless(Str::isUuid($delivery), 404);
        DB::select('SELECT school.retry_delivery(?)', [$delivery]);
        return response()->noContent();
    }
}
