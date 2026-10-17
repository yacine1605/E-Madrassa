<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Support\CurrentSchool;
use App\Support\Formats;
use App\Support\Ics;
use App\Support\Locales;
use App\Support\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-PLN-04: the planning as others read it. A week's timetable of a group, a teacher or a room
 * in PDF, the open days in columns (RTL in Arabic); the sessions of a stretch of days as an
 * iCalendar file in Algiers' time (AC1); a teacher's own sessions. Cancelled sessions appear as
 * such. What the reader may not see the database keeps from them (SQL 062).
 */
final class TimetableController
{
    private const SESSIONS = "SELECT c.id, c.starts_at, c.ends_at, c.status, c.updated_at, school.local_day(c.starts_at)::text AS day,
        to_char(c.starts_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') AS start_time,
        CASE WHEN school.local_day(c.ends_at) > school.local_day(c.starts_at) THEN '24:00' ELSE to_char(c.ends_at AT TIME ZONE 'Africa/Algiers', 'HH24:MI') END AS end_time,
        g.code AS group_code, sj.code AS subject_code, sj.labels AS subject_labels, r.code AS room_code,
        st.last_name_lat, st.first_name_lat, st.last_name_ar, st.first_name_ar
        FROM school.class_session c
        LEFT JOIN school.study_group g ON g.tenant_id = c.tenant_id AND g.id = c.group_id
        LEFT JOIN school.subject sj ON sj.tenant_id = c.tenant_id AND sj.id = c.subject_id
        LEFT JOIN school.room r ON r.tenant_id = c.tenant_id AND r.id = c.room_id
        LEFT JOIN school.staff st ON st.tenant_id = c.tenant_id AND st.id = c.teacher_id";
    private const MAX_DAYS = 92;

    public function __construct(private CurrentSchool $school, private Pdf $pdf) {}

    /** AC1: an iCalendar file of the sessions from one day until another (92 at most), of a group, a teacher, a room, or one's own (`mine`). */
    public function ics(Request $request)
    {
        $data = $this->filters($request) + $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= self::MAX_DAYS) throw Refusal::of('calendar_range_too_long');
        [$where, $bindings, $name] = $this->scope($data);
        // The cancelled ones too, so that an agenda that imported them drops them.
        $rows = $this->sessions($where, $bindings, $data['from'], $data['to'], true);
        $events = array_map(fn (object $row) => ['id' => $row->id, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at, 'updated_at' => $row->updated_at,
            'summary' => implode(' · ', array_filter([$this->subject($row), $row->group_code])), 'location' => $row->room_code,
            'description' => $this->teacher($row), 'cancelled' => $row->status === 'cancelled'], $rows);
        return response(Ics::calendar($name, $events), 200, ['Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="planning.ics"', 'Cache-Control' => 'private, no-store']);
    }

    /** A week's timetable in PDF: the week the given day falls in, as the school starts its weeks. */
    public function pdf(Request $request)
    {
        $data = $this->filters($request) + $request->validate(['week' => 'required|date_format:Y-m-d']);
        [$where, $bindings, $name] = $this->scope($data);
        $first = (int) json_decode(DB::selectOne("SELECT school.setting_value('calendar.first_day_of_week') AS v")->v ?? '0');
        $start = Carbon::parse($data['week']);
        while ($start->dayOfWeek !== $first) $start = $start->subDay();
        $end = $start->copy()->addDays(6);
        $rows = $this->sessions($where, $bindings, $start->toDateString(), $end->toDateString(), true);
        $open = json_decode(DB::selectOne("SELECT school.setting_value('calendar.open_days') AS v")->v, true) ?? [];
        $locale = app()->getLocale();
        $days = [];
        for ($day = $start->copy(); $day <= $end; $day = $day->addDay()) {
            $key = $day->toDateString();
            // The week's open days, and any other day a session falls on (an exceptional opening).
            if (in_array($day->dayOfWeek, $open, true) || collect($rows)->contains('day', $key)) {
                $days[] = ['key' => $key, 'name' => __('admin.days')[$day->dayOfWeek], 'date' => Formats::date($key)];
            }
        }
        $grid = [];
        foreach ($rows as $row) {
            $grid[$row->start_time.'–'.$row->end_time][$row->day][] = ['subject' => $this->subject($row), 'room' => $row->room_code,
                'who' => isset($data['group_id']) ? $this->teacher($row) : $row->group_code, 'cancelled' => $row->status === 'cancelled'];
        }
        ksort($grid);
        $title = __('planning.timetable');
        $pdf = $this->pdf->render('timetable', ['subject' => $name, 'week' => Formats::date($start).' – '.Formats::date($end), 'days' => $days,
            'rows' => array_map(fn ($hours, $cells) => ['hours' => $hours, 'cells' => $cells], array_keys($grid), $grid)], $locale,
            $start->format('Y').'-S'.$start->format('W'), $title, 'A4-L');
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="planning.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    /** A teacher's own sessions from one day until another (62 at most): those they teach, as the database finds them theirs. */
    public function mine(Request $request)
    {
        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) >= 62) throw Refusal::of('session_range_too_long');
        $rows = $this->sessions('school.is_own_staff(c.teacher_id)', [], $data['from'], $data['to'], true);
        return ['data' => array_map(fn (object $row) => [...((array) $row), 'subject_labels' => json_decode((string) $row->subject_labels, true)], $rows)];
    }

    private function filters(Request $request): array
    {
        return $request->validate(['group_id' => 'nullable|uuid', 'teacher_id' => 'nullable|uuid', 'room_id' => 'nullable|uuid', 'mine' => 'boolean']);
    }

    /** One of a group, a teacher, a room or one's own; and its name for the document. */
    private function scope(array $data): array
    {
        if (!empty($data['mine'])) return ['school.is_own_staff(c.teacher_id)', [], __('planning.my_timetable')];
        foreach (['group_id' => 'study_group', 'teacher_id' => 'staff', 'room_id' => 'room'] as $column => $table) {
            if (!isset($data[$column])) continue;
            $row = DB::selectOne("SELECT * FROM school.$table WHERE tenant_id = ? AND id = ?", [$this->school->id(), $data[$column]]);
            abort_unless($row, 404);
            $name = match ($table) {
                'staff' => trim($row->last_name_lat.' '.$row->first_name_lat) ?: trim($row->last_name_ar.' '.$row->first_name_ar),
                default => implode(' · ', array_filter([$row->code, Locales::label(json_decode($row->labels, true))])),
            };
            return ["c.$column = ?", [$data[$column]], $name];
        }
        throw Refusal::of('timetable_scope_required', 422);
    }

    private function sessions(string $where, array $bindings, string $from, string $to, bool $cancelled = false): array
    {
        return DB::select(self::SESSIONS." WHERE c.tenant_id = ? AND $where AND c.starts_at >= school.local_at(?::date, '00:00')
            AND c.starts_at < school.local_at(?::date + 1, '00:00')".($cancelled ? '' : " AND c.status <> 'cancelled'").' ORDER BY c.starts_at, g.code',
            [$this->school->id(), ...$bindings, $from, $to]);
    }

    private function subject(object $row): string
    {
        return implode(' · ', array_filter([$row->subject_code, Locales::label(json_decode((string) $row->subject_labels, true) ?? [])]));
    }

    private function teacher(object $row): ?string
    {
        $latin = trim(($row->last_name_lat ?? '').' '.($row->first_name_lat ?? ''));
        $arabic = trim(($row->last_name_ar ?? '').' '.($row->first_name_ar ?? ''));
        return (app()->getLocale() === 'ar' ? ($arabic ?: $latin) : ($latin ?: $arabic)) ?: null;
    }
}
