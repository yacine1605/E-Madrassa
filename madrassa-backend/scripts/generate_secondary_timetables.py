"""Generate two 1AS timetables and a standalone HTML file (standard library only).

Run: python -B madrassa-backend/scripts/generate_secondary_timetables.py
Source: ministerial-annex hours and coefficients supplied by the user.
The printed (x+y) splits are included in those hours, not additional TD periods.
"""
from __future__ import annotations

from collections import Counter
from html import escape
import json
import math
from pathlib import Path
import random
import time

# Editable grid, annealing parameters and preference weights.
SEED = 2026
DAYS = ("Dimanche", "Lundi", "Mardi", "Mercredi", "Jeudi")
MORNING = (("08:00", "09:00"), ("09:00", "10:00"), ("10:00", "11:00"), ("11:00", "12:00"))
AFTERNOON = (("13:00", "14:00"), ("14:00", "15:00"), ("15:00", "16:00"), ("16:00", "17:00"))
AFTERNOON_DAYS = (0, 1, 3, 4)
LUNCH = ("12:00", "13:00")
ITERATIONS = 120_000
RESTARTS = 4
START_TEMPERATURE = 25.0
END_TEMPERATURE = 0.02
CORE_AFTERNOON_COST = (2, 4, 7, 11)
ARABIC_MATHS_AFTERNOON_COST = (2, 4, 9, 14)
ARABIC_MATHS_LAST_MORNING_COST = 2
LIGHT_MORNING_COST = 3
FREE_COST = (14, 10, 5, 0)
SECOND_FREE_SAME_DAY_COST = 8
HARD_PENALTY = 300
EXTRA_DOUBLE_DAY_PENALTY = 80
OUTPUT = Path(__file__).resolve().parent.parent / "docs" / "emplois-du-temps-1as-2026-2027.html"

# (hours, coefficient, annex display); None means not taught.
SOURCE = {
    "AR":    {"arts": (6, 5, "6 (5+1)"), "st": (4, 3, "4")},
    "EN":    {"arts": (4, 3, "4"),       "st": (3, 2, "3")},
    "FR":    {"arts": (4, 3, "4"),       "st": (3, 2, "3")},
    "ISLAM": {"arts": (2, 2, "2"),       "st": (2, 2, "2")},
    "HG":    {"arts": (4, 3, "4"),       "st": (2, 2, "2")},
    "MATH":  {"arts": (3, 2, "3"),       "st": (6, 5, "6")},
    "PH":    {"arts": (2, 2, "2 (1+1)"), "st": (4, 4, "4 (2+2)")},
    "SVT":   {"arts": (2, 2, "2 (1+1)"), "st": (4, 4, "4 (2+2)")},
    "TECH":  {"arts": None,              "st": (2, 2, "2 (1+1)")},
    "INFO":  {"arts": (2, 2, "2 (1+1)"), "st": (2, 2, "2 (1+1)")},
    "PE":    {"arts": (2, 1, "2"),       "st": (2, 1, "2")},
    "ART":   {"arts": (2, 1, "2"),       "st": (1, 1, "1")},
}
EXPECTED_TOTALS = {"arts": (33, 26), "st": (35, 30)}
TRUNKS = {"arts": "Tronc commun Lettres", "st": "Tronc commun Sciences et Technologie"}
CORE = {"AR", "EN", "FR", "HG", "MATH", "PH", "SVT"}
ROOMS = {"PE": "Gymnase", "INFO": "Salle informatique"}
NAMES = {
    "AR": "Langue arabe et littérature", "EN": "Anglais", "FR": "Français",
    "ISLAM": "Sciences islamiques", "HG": "Histoire et géographie", "MATH": "Mathématiques",
    "PH": "Sciences physiques", "SVT": "Sciences naturelles (SVT)", "TECH": "Technologie",
    "INFO": "Informatique", "PE": "EPS", "ART": "Éducation artistique", "FREE": "Heure libre",
}
SHORT_NAMES = {**NAMES, "AR": "Arabe", "ISLAM": "Sc. islamiques", "HG": "Histoire / géo",
               "MATH": "Maths", "PH": "Sc. physiques", "SVT": "SVT", "ART": "Éd. artistique"}
HALF = len(MORNING)
PERIODS = MORNING + AFTERNOON
SLOTS = tuple((day, period) for day in range(len(DAYS))
              for period in range(len(PERIODS)) if period < HALF or day in AFTERNOON_DAYS)
DAY_INDICES = tuple(tuple(i for i, (day, _) in enumerate(SLOTS) if day == d) for d in range(len(DAYS)))


def expected_counts(trunk):
    result = Counter({subject: rows[trunk][0] for subject, rows in SOURCE.items() if rows[trunk]})
    result["FREE"] = len(SLOTS) - sum(result.values())
    return result


def preflight():
    if HALF != 4 or len(AFTERNOON) != 4:
        raise ValueError("The preference weights require four periods per half-day.")
    for trunk, totals in EXPECTED_TOTALS.items():
        actual = tuple(sum(rows[trunk][i] for rows in SOURCE.values() if rows[trunk]) for i in (0, 1))
        if actual != totals:
            raise ValueError(f"Annex total mismatch for {trunk}: {actual} != {totals}")
        if expected_counts(trunk)["FREE"] < 0:
            raise ValueError("Not enough periods in the grid.")


def hard_errors(assignment, trunk, previous=None):
    """Independent full validation: no calls to the search's energy functions."""
    errors = []
    if len(assignment) != len(SLOTS):
        return ["Incorrect number of assigned periods."]
    if Counter(assignment) != expected_counts(trunk):
        errors.append("Subject counts or FREE count do not match the annex.")
    double_days = Counter()
    for day in range(len(DAYS)):
        occupied = [(period, assignment[i]) for i, (d, period) in enumerate(SLOTS) if d == day]
        for subject in set(token for _, token in occupied) - {"FREE"}:
            positions = [p for p, token in occupied if token == subject]
            if len(positions) <= 1:
                continue
            source = SOURCE.get(subject, {}).get(trunk)
            if source is None or source[0] != 6:
                errors.append(f"{DAYS[day]}: repeated subject {subject}.")
            elif len(positions) != 2 or positions[1] != positions[0] + 1 or positions[0] // HALF != positions[1] // HALF:
                errors.append(f"{DAYS[day]}: invalid double period for {subject}.")
            else:
                double_days[subject] += 1
        for period, token in occupied:
            if token == "FREE":
                if period < HALF:
                    errors.append(f"{DAYS[day]}: morning FREE period.")
                if any(p > period and p // HALF == period // HALF and other != "FREE" for p, other in occupied):
                    errors.append(f"{DAYS[day]}: a class follows a FREE period in the same half-day.")
    for subject, number in double_days.items():
        if number > SOURCE[subject][trunk][0] - len(DAYS):
            errors.append(f"Too many double days for {subject}.")
    if previous is not None:
        if len(previous) != len(SLOTS):
            errors.append("Invalid reference timetable for shared rooms.")
        else:
            for i, token in enumerate(assignment):
                if token in ROOMS and previous[i] == token:
                    day, period = SLOTS[i]
                    errors.append(f"{ROOMS[token]} booked twice on {DAYS[day]} at {PERIODS[period][0]}.")
    return errors


def hard_ok(assignment, trunk, previous=None):
    return not hard_errors(assignment, trunk, previous)


def unary_cost(subject, period):
    if subject == "FREE":
        return HARD_PENALTY if period < HALF else FREE_COST[period - HALF]
    if subject in CORE:
        if period >= HALF:
            return (ARABIC_MATHS_AFTERNOON_COST if subject in {"AR", "MATH"} else CORE_AFTERNOON_COST)[period - HALF]
        return ARABIC_MATHS_LAST_MORNING_COST if subject in {"AR", "MATH"} and period == HALF - 1 else 0
    return LIGHT_MORNING_COST if period < HALF else 0


def day_energy(assignment, day, double_subject, unary):
    positions = {}
    free = []
    total = 0
    for i in DAY_INDICES[day]:
        token = assignment[i]
        period = SLOTS[i][1]
        total += unary[i][token]
        if token == "FREE":
            free.append(period)
        else:
            positions.setdefault(token, []).append(period)
    doubles = 0
    for token, periods in positions.items():
        if len(periods) > 1:
            if token != double_subject:
                total += HARD_PENALTY * (len(periods) - 1)
            elif len(periods) > 2:
                total += HARD_PENALTY * (len(periods) - 2)
            elif periods[1] != periods[0] + 1 or periods[0] // HALF != periods[1] // HALF:
                total += HARD_PENALTY
            if token == double_subject:
                doubles += 1
    # Every non-free period after a free period in the same half-day is a violation.
    for period in free:
        total += HARD_PENALTY * sum(p > period and p // HALF == period // HALF
                                    for periods in positions.values() for p in periods)
    total += SECOND_FREE_SAME_DAY_COST * max(0, len(free) - 1)
    return total, doubles


def anneal(trunk, previous=None):
    """Four random starts, swap-only simulated annealing; preserve the multiset."""
    counts = expected_counts(trunk)
    tokens = list(counts.elements())
    double_subject = next(subject for subject, rows in SOURCE.items() if rows[trunk] and rows[trunk][0] == 6)
    allowed_double_days = counts[double_subject] - len(DAYS)
    unary = [{token: unary_cost(token, period)
              + (HARD_PENALTY if previous is not None and token in ROOMS and previous[i] == token else 0)
              for token in counts} for i, (_, period) in enumerate(SLOTS)]
    cooling = (END_TEMPERATURE / START_TEMPERATURE) ** (1 / (ITERATIONS - 1))
    best = None
    best_score = math.inf
    runs = []
    for restart in range(RESTARTS):
        assignment = tokens.copy()
        random.shuffle(assignment)
        day_scores = [day_energy(assignment, d, double_subject, unary) for d in range(len(DAYS))]
        double_count = sum(doubles for _, doubles in day_scores)
        score = sum(value for value, _ in day_scores) + EXTRA_DOUBLE_DAY_PENALTY * max(0, double_count - allowed_double_days)
        run_best, run_score = assignment.copy(), score
        temperature = START_TEMPERATURE
        for _ in range(ITERATIONS):
            i = random.randrange(len(SLOTS))
            j = random.randrange(len(SLOTS))
            if assignment[i] != assignment[j]:
                d1, d2 = SLOTS[i][0], SLOTS[j][0]
                changed = (d1,) if d1 == d2 else (d1, d2)
                assignment[i], assignment[j] = assignment[j], assignment[i]
                updated = [day_energy(assignment, d, double_subject, unary) for d in changed]
                new_doubles = double_count + sum(new[1] - day_scores[d][1] for d, new in zip(changed, updated))
                delta = sum(new[0] - day_scores[d][0] for d, new in zip(changed, updated))
                delta += EXTRA_DOUBLE_DAY_PENALTY * (max(0, new_doubles - allowed_double_days) - max(0, double_count - allowed_double_days))
                if delta <= 0 or random.random() < math.exp(-delta / temperature):
                    score += delta
                    double_count = new_doubles
                    for d, new in zip(changed, updated):
                        day_scores[d] = new
                    if score < run_score:
                        run_best, run_score = assignment.copy(), score
                else:
                    assignment[i], assignment[j] = assignment[j], assignment[i]
            temperature *= cooling
        valid = hard_ok(run_best, trunk, previous)
        runs.append({"restart": restart + 1, "score": run_score, "hard_ok": valid})
        print(f"{trunk}: restart {restart + 1}/{RESTARTS}, score={run_score}, hard_ok={valid}", flush=True)
        if valid and run_score < best_score:
            best, best_score = run_best, run_score
    if best is None:
        raise RuntimeError(f"No valid timetable found for {trunk}; increase ITERATIONS/RESTARTS.")
    assert hard_ok(best, trunk, previous), hard_errors(best, trunk, previous)
    return best, {"score": best_score, "restarts": runs}


STYLE = """
:root{font-family:Segoe UI,Arial,sans-serif;color:#203247;background:#edf2f6;font-synthesis:none}
*{box-sizing:border-box}body{margin:0}main{max-width:1500px;margin:auto;padding:30px}
h1,h2,h3,p{margin-top:0}.intro{background:#19374e;color:#fff;border-radius:16px;padding:28px;margin-bottom:24px}
.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:2px;font-weight:700;margin-bottom:8px;color:#567089}
.intro .eyebrow{color:#bcd9e9}h1{font-size:30px;margin-bottom:12px;letter-spacing:-.5px}.intro p{line-height:1.6;max-width:1000px;color:#dfebf4}
nav{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}nav a,button{padding:10px 14px;border-radius:7px;font:inherit;font-size:13px;font-weight:600;text-decoration:none;background:white;color:#19374e;border:0;cursor:pointer}
.sheet{background:white;border:1px solid #d5e0e9;border-radius:14px;padding:24px;margin-bottom:24px;scroll-margin-top:20px}
.sheet-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:16px}.sheet h2{font-size:25px;letter-spacing:-.5px;margin:0}
.stats{text-align:right;white-space:nowrap;font-size:12px;line-height:1.7;color:#537084}.stats strong{font-size:23px;color:#203247}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;table-layout:fixed}.grid{min-width:960px}
.grid th,.grid td{border:1px solid #d5e0e9;padding:10px 11px;vertical-align:top}.grid thead th{background:#23465f;color:#fff;font-size:14px;padding:12px}
.grid .time{width:112px;font-size:12px;white-space:nowrap;text-align:left;background:#f0f4f7}.grid thead .time{background:#23465f;color:white}
.title{font-size:14px;font-weight:600;line-height:1.3}.detail{font-size:11px;color:#587084;margin-top:4px;line-height:1.35}
.ar{background:#fff2d8}.language{background:#eaf2fe}.math{background:#eee9fc}.science{background:#e5f4f1}.hg{background:#fff0e8}
.light{background:#f8f7f2}.pe{background:#e2f1e5}.info{background:#e2f2f9}.free,.off{background:#f1f4f7;color:#66798a}
.double .title{font-weight:750}.double .detail{color:#654e29}.grid .lunch th,.grid .lunch td{background:#f0f4f7;font-size:11px;padding:6px}.grid .lunch td{text-align:center}
.legend{display:flex;gap:8px;flex-wrap:wrap;font-size:11px;margin-top:12px}.legend span{padding:5px 8px;border-radius:5px}
.note{font-size:12px;line-height:1.5;margin:10px 0}.reference{min-width:860px;margin-top:14px}.reference caption{text-align:left;font-size:12px;font-weight:700;padding-bottom:7px}
.reference th,.reference td{border:1px solid #dae4ec;padding:6px 4px;text-align:center;font-size:10px}.reference th{background:#f0f4f7}.reference .row-label{width:90px;text-align:left}
.footer{font-size:11px;line-height:1.5;color:#5b7285;margin-top:10px}.validation{font-size:12px;color:#496676;margin:12px 0 0}
@media(max-width:700px){main{padding:12px}.intro,.sheet{padding:16px}h1{font-size:25px}.sheet h2{font-size:20px}.stats strong{font-size:19px}}
@media print{
@page{size:A4 landscape;margin:8mm}*{print-color-adjust:exact;-webkit-print-color-adjust:exact}
:root{background:#fff}main{padding:0;max-width:none}.intro{display:none}.sheet{border:0;border-radius:0;padding:0;margin:0;break-after:page;break-inside:avoid}.sheet:last-of-type{break-after:auto}
.sheet-head{margin-bottom:9px}.sheet h2{font-size:19px}.eyebrow{font-size:8px;margin-bottom:4px}.stats{font-size:9px}.stats strong{font-size:17px}
.table-wrap{overflow:visible}.grid,.reference{min-width:0}.grid .time{width:80px;font-size:9px}.grid th,.grid td{padding:7px}.grid thead th{font-size:10px;padding:7px}
.title{font-size:10px}.detail{font-size:8px;margin-top:2px}.grid .lunch th,.grid .lunch td{font-size:9px;padding:4px}
.legend{font-size:8px;margin-top:8px;gap:5px}.legend span{padding:3px 6px}.note{font-size:9px;line-height:1.35;margin:7px 0}
.reference{margin-top:8px}.reference caption{font-size:9px;padding-bottom:4px}.reference th,.reference td{font-size:8px;padding:4px 2px}.reference .row-label{width:67px}
.footer,.validation{font-size:8px;margin-top:7px;line-height:1.35}
}
"""


def render_cell(assignment, day, period):
    index = SLOTS.index((day, period))
    subject = assignment[index]
    css = {"AR": "ar", "EN": "language", "FR": "language", "MATH": "math", "PH": "science",
           "SVT": "science", "HG": "hg", "PE": "pe", "INFO": "info", "FREE": "free"}.get(subject, "light")
    positions = [SLOTS[i][1] for i in DAY_INDICES[day] if assignment[i] == subject]
    details = []
    if subject != "FREE" and len(positions) == 2:
        css += " double"
        details.append(f"Séance double · {positions.index(period) + 1}/2")
    if subject in ROOMS:
        details.append(ROOMS[subject])
    if subject == "FREE":
        details.append("Fin des cours pour la journée")
    return f'<td class="{css}" data-subject="{subject}"><div class="title">{escape(NAMES[subject])}</div>' + ''.join('<div class="detail">' + escape(d) + '</div>' for d in details) + '</td>'


def render_sheet(trunk, assignment):
    hours, coefficients = EXPECTED_TOTALS[trunk]
    free = len(SLOTS) - hours
    html = f'<section class="sheet" id="{trunk}"><header class="sheet-head"><div><div class="eyebrow">1re année secondaire · 2026 / 2027</div><h2>{TRUNKS[trunk]}</h2></div><div class="stats"><strong>{hours} h / semaine</strong><br>Coefficients : {coefficients} · {free} h libre{("s" if free > 1 else "")}</div></header>'
    html += '<div class="table-wrap"><table class="grid"><thead><tr><th class="time" scope="col">Horaire</th>' + ''.join('<th scope="col">' + day + '</th>' for day in DAYS) + '</tr></thead><tbody>'
    for period, (start, end) in enumerate(PERIODS):
        if period == HALF:
            html += f'<tr class="lunch"><th class="time" scope="row">{LUNCH[0]}–{LUNCH[1]}</th><td colspan="{len(DAYS)}">Pause déjeuner · mardi : fin des cours à 12:00</td></tr>'
        html += f'<tr><th class="time" scope="row">{start}–{end}</th>'
        for day in range(len(DAYS)):
            if period >= HALF and day not in AFTERNOON_DAYS:
                if period == HALF:
                    html += f'<td class="off" rowspan="{len(AFTERNOON)}"><div class="title">Après-midi libre</div></td>'
            else:
                html += render_cell(assignment, day, period)
        html += '</tr>'
    html += '</tbody></table></div><div class="legend"><span class="ar">Arabe</span><span class="language">Langues étrangères</span><span class="math">Mathématiques</span><span class="science">Sciences</span><span class="pe">EPS · gymnase</span><span class="info">Salle informatique</span><span class="free">Heure libre</span></div>'
    double = "d’arabe" if trunk == "arts" else "de mathématiques"
    html += f'<p class="note">Chaque case représente 1 heure. La séance double {double} occupe deux heures consécutives dans la même demi-journée. Les autres matières apparaissent au maximum une fois par jour.</p>'
    html += '<div class="table-wrap"><table class="reference"><caption>Volumes et coefficients de l’annexe fournie</caption><thead><tr><th class="row-label" scope="col">Matière</th>'
    html += ''.join('<th scope="col">' + escape(SHORT_NAMES[subject]) + '</th>' for subject in SOURCE) + '</tr></thead><tbody>'
    for title, index in (("Heures", 2), ("Coefficient", 1)):
        html += '<tr><th class="row-label" scope="row">' + title + '</th>'
        html += ''.join('<td>' + (escape(str(rows[trunk][index])) if rows[trunk] else '—') + '</td>' for rows in SOURCE.values()) + '</tr>'
    html += '</tbody></table></div><p class="footer">Les décompositions (5+1), (1+1) et (2+2) sont comprises dans les volumes affichés et réparties en périodes d’une heure. Amazigh : 3 h, coefficient (2), hors des totaux de l’annexe et non ajouté à cette grille.'
    if trunk == "arts":
        html += ' La technologie n’est pas enseignée dans ce tronc commun.'
    html += '</p><p class="validation">Volumes et coefficients vérifiés · Heures libres uniquement en fin d’après-midi · Aucun conflit entre ces deux classes au gymnase ou en salle informatique.</p></section>'
    return html


def render(schedules, diagnostics):
    assert hard_ok(schedules["arts"], "arts"), hard_errors(schedules["arts"], "arts")
    assert hard_ok(schedules["st"], "st", schedules["arts"]), hard_errors(schedules["st"], "st", schedules["arts"])
    html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>1AS · Emplois du temps 2026–2027</title><style>' + STYLE + '</style></head><body><main>'
    html += '<header class="intro"><div class="eyebrow">Enseignement secondaire · 2026 / 2027</div><h1>Deux emplois du temps de 1AS</h1><p>Troncs communs Lettres et Sciences et Technologie. Du dimanche au jeudi, de 08:00 à 12:00 et de 13:00 à 17:00 ; mardi après-midi libre. Les heures libres sont placées en fin de journée.</p><p class="note">Un gymnase et une salle informatique partagés entre les deux classes. Les enseignants ne sont pas affectés dans ces grilles.</p><nav aria-label="Choisir le tronc commun"><a href="#arts">Lettres · 33 h</a><a href="#st">Sciences et Technologie · 35 h</a><button type="button" onclick="window.print()">Imprimer</button></nav></header>'
    html += ''.join(render_sheet(trunk, schedules[trunk]) for trunk in TRUNKS)
    # Machine-readable data makes the delivered HTML independently auditable.
    data = {"seed": SEED, "days": DAYS, "periods": PERIODS, "slots": SLOTS, "schedules": schedules, "search": diagnostics}
    html += '<script type="application/json" id="timetable-data">' + json.dumps(data, ensure_ascii=False).replace('<', '\\u003c') + '</script>'
    return html + '</main></body></html>'


def main():
    preflight()
    random.seed(SEED)
    started = time.monotonic()
    schedules, diagnostics = {}, {}
    schedules["arts"], diagnostics["arts"] = anneal("arts")
    schedules["st"], diagnostics["st"] = anneal("st", schedules["arts"])
    OUTPUT.write_text(render(schedules, diagnostics), encoding="utf-8")
    print(f"Validated both timetables; generated {OUTPUT} in {time.monotonic() - started:.1f}s", flush=True)


if __name__ == "__main__":
    main()
