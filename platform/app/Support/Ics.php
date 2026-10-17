<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * FR-PLN-04 AC1: sessions as an iCalendar file (RFC 5545) in Algiers' time: each event's start
 * and end written with the zone `Africa/Algiers` and that zone described, so that an agenda
 * shows the school's hours wherever it is opened. Lines end with CRLF and are folded at 75
 * octets; texts are escaped.
 */
final class Ics
{
    /** @param list<array{id: string, starts_at: string, ends_at: string, summary: string, location: ?string, description: ?string, cancelled: bool, updated_at: string}> $events */
    public static function calendar(string $name, array $events): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Madrassa//Planning//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text($name), 'X-WR-TIMEZONE:Africa/Algiers',
            // Algeria keeps UTC+1 all year, without summer time.
            'BEGIN:VTIMEZONE', 'TZID:Africa/Algiers', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0100', 'TZNAME:CET',
            'END:STANDARD', 'END:VTIMEZONE'];
        foreach ($events as $event) {
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:'.$event['id'].'@madrassa',
                'DTSTAMP:'.Carbon::parse($event['updated_at'])->utc()->format('Ymd\THis\Z'),
                'DTSTART;TZID=Africa/Algiers:'.self::local($event['starts_at']), 'DTEND;TZID=Africa/Algiers:'.self::local($event['ends_at']),
                'SUMMARY:'.self::text($event['summary']), ...($event['location'] ? ['LOCATION:'.self::text($event['location'])] : []),
                ...($event['description'] ? ['DESCRIPTION:'.self::text($event['description'])] : []),
                'STATUS:'.($event['cancelled'] ? 'CANCELLED' : 'CONFIRMED'), 'END:VEVENT'];
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    private static function local(string $at): string
    {
        return Carbon::parse($at)->timezone('Africa/Algiers')->format('Ymd\THis');
    }

    private static function text(string $value): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $value);
    }

    /** At most 75 octets a line, a continuation starting with a space; never inside a character. */
    private static function fold(string $line): string
    {
        $out = '';
        $length = 0;
        foreach (mb_str_split($line) as $char) {
            $size = strlen($char);
            if ($length + $size > 75) { $out .= "\r\n "; $length = 1; }
            $out .= $char;
            $length += $size;
        }
        return $out;
    }
}
