# Private primary/preschool timetable

User scope: two classes per level, except one 5AP; ten generalist teachers,
three French, two English, one Spanish, one sports. Sunday through Thursday,
08:30–12:00 and 13:00–16:00; Tuesday 08:30–12:30 with the afternoon free. Spanish one hour in every
class. French and English start in preschool.

The user's primary subject volumes, in integer minutes, are in
`config/primary_weekly_minutes.json`. The detailed grade 4 subjects total 22.5h
(not the supplied total of 25.5h); grade 5 totals 23.25h. Private-language
supplements are separate from these base volumes. All fractional hours are exact.
The weekly schedule is in `scripts/private_timetable.json`.
Recess is a **zero-minute marker at 10:30** on every class and teacher timetable.
It reserves no time: lessons resume at 10:30. The full morning runs from 08:30
to 12:00, or 12:30 on Tuesday. The weekly programme totals 30 hours (1,800 minutes), excluding lunch.
Workshop time is now assigned to language support in both primary and preschool:
exactly 2 hours of English per class, with all remaining workshop time in French.
These are additional to the base language hours. The timetable retains the label
**Atelier / soutien**, using blue for English and amber for French. Each workshop
is immediately before or after a course in the same language, with the same teacher.

| Level | English support | French support |
| --- | --- | --- |
| Preschool, 1AP, 2AP | 2h | 1h |
| 3AP | 2h | 3h |
| 4AP | 2h | 4h30 |
| 5AP | 2h | 3h45 |

Base subject and language volumes remain exact; `curriculum.hours` includes the
additional support in the corresponding language total. Plan sessions distinguish
`activity: course` and `activity: support`, and lesson metadata preserves both volumes.
The model includes daily Arabic and mathematics in primary and supervised preschool quiet
activities. Ten generalists cover eleven classes through specialist rotations
and subject-based sharing of 4AP-B. Fifteen existing teacher identities are used;
Spanish and sports have explicit provisional names. No existing staff accounts
are deleted or disabled. Eleven logical classroom records require mapping to
the school's actual premises.

`generate_private_timetable.py` uses installed NumPy/SciPy to solve class and
teacher exclusivity, exact weekly minutes, and daily subject limits. Sessions
last 30, 45 or 60 minutes and start on a 15-minute grid. Its output is
checked in, so importing requires no Python dependencies. The existing middle
school session on 28 September is preserved. `render_private_timetable.py`
produces the printable HTML review with class and teacher timetables.

`import_private_timetable.php` uses the current local Laravel backend. It:

- preserves cohort identities, existing enrollments and unrelated sessions;
- corrects the six offerings named 2026/2027 that were linked to 2025/2026;
- adds five missing classes, curriculum links, qualifications and assignments;
- writes decimal weekly hours to `curriculum.hours` without rounding;
- preserves course/support identity in `sessions.activity` and removes obsolete
  generic workshop curriculum links and unused class assignments;
- imports planned dated sessions inside the 21/09/2026–08/06/2027 period;
- skips Friday/Saturday, Tuesday afternoons and supplied inclusive closures;
- backs up affected tables privately before committing and saves an import manifest;
- takes locks and rolls back the entire import on any conflict;
- rejects a differing existing session and skips an identical one on rerun.

Default execution is a dry run. `--catalog-only --apply` commits just classes,
staff, rooms, curriculum hours and teacher assignments, with no dated sessions.
An annual commit **requires** a supplied calendar:

```json
{"confirmed": true, "closures": [{"start": "YYYY-MM-DD", "end": "YYYY-MM-DD", "name": "School closure"}]}
```

Supply school holidays and holiday/festival closures. Actual 2026/2027 dates
remain pending: searches did not locate a reliable official calendar applicable
to this school. Do not substitute the 2025/2026 or French-school calendar.

```powershell
docker-compose cp madrassa-backend/scripts/import_private_timetable.php app:/tmp/import_private_timetable.php
docker-compose cp madrassa-backend/scripts/private_timetable.json app:/tmp/private_timetable.json
docker-compose cp <confirmed-calendar.json> app:/tmp/private-calendar.json
docker-compose exec -T app php /tmp/import_private_timetable.php --calendar=/tmp/private-calendar.json
docker-compose exec -T app php /tmp/import_private_timetable.php --calendar=/tmp/private-calendar.json --apply
```

Validation completed: weekly 394 sessions, including 48 language support sessions,
11 classes, exactly 17 teachers,
exact fractional subject hours, zero-minute recess markers and no teacher/class collisions.
`tests/weekly-timetable.php` verifies stored fractional hours, private supplements,
sync idempotence, two English support hours in every class, same-language adjacency,
continuous morning coverage through 12:30 on Tuesday and rejection of nonzero
recess durations, collisions, lunch sessions and rounded volumes.
`tests/fixtures/timetable-calendar.json` is a synthetic closure test, not a school calendar.

Applied locally: subject catalogue with weekly-volume entries, five missing
classes, two explicitly provisional specialist records, eleven logical rooms,
qualifications and subject assignments. A repeat catalogue import reports no changes.
Existing 10 enrollments and the single unrelated session remain intact. The latest
weekly plan is exported to `emplois-du-temps-2026-2027.html`; dated annual sessions
remain pending the school's holiday dates.
