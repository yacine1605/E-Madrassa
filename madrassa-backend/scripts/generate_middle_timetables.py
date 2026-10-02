"""Eight middle-school timetables from the user-supplied July 2026 annex data.

Standard library only. PE room matching, randomized morning DFS, then afternoon
shuffle/retry. Resource feasibility is checked before generating any timetable.
"""
from __future__ import annotations

import argparse
from collections import Counter
from fractions import Fraction
from itertools import combinations
import json
from pathlib import Path
import random

# School grid and resources: change these constants to adapt the generator.
SEED = 2026
DAYS = ("Dimanche", "Lundi", "Mardi", "Mercredi", "Jeudi")
MORNING = (("08:00", "09:00"), ("09:00", "10:00"), ("10:00", "11:00"), ("11:00", "12:00"))
AFTERNOON = (("13:00", "14:00"), ("14:00", "15:00"))
AFTERNOON_DAYS = (0, 1, 3, 4)
LUNCH = ("12:00", "13:00")
LEVELS = (1, 2, 3, 4)
SECTIONS = ("A", "B")
GYM_ROOMS = ("Gymnase",)
COMPUTER_ROOMS = ("Salle informatique",)
INDEPENDENT_VARIANTS = False
ALLOW_MORNING_PE = True  # User-approved: swap one PE hour with the natural-science practical.
FREE_M3 = (4, 3)  # Thursday, fourth morning period.
MAX_AFTERNOON_ATTEMPTS = 10000
OUTPUT_ROOT = Path(__file__).resolve().parent

# (lecture hours, weekly-average TD hours, coefficient), M1 through M4.
SOURCE = {
    "AR": ((5, .5, 2), (5, .5, 3), (4, .5, 3), (4, 1, 5)),
    "FR": ((2, .5, 1), (2, .5, 2), (2, .5, 2), (2, .5, 2)),
    "EN": ((3, .5, 1), (3, .5, 3), (3, .5, 3), (3, .5, 3)),
    "MATH": ((4, .5, 2), (4, .5, 3), (4, .5, 3), (4, 1, 4)),
    "NS": ((2, 0, 1), (2, 0, 2), (2, 0, 2), (2, 0, 2)),
    "PH": ((2, 0, 1), (2, 0, 2), (2, 0, 2), (2, 0, 2)),
    "HIST": ((1, 0, None),) * 4,
    "GEO": ((1, 0, None),) * 4,
    "ISLAM": ((1, 0, 1),) * 4,
    "CIVIC": ((1, 0, 1),) * 4,
    "ART": ((1, 0, 1),) * 4,
    "PE": ((2, 0, 1),) * 4,
    "CS": ((1, 0, 1),) * 4,
}
HISTORY_GEOGRAPHY_COEFFICIENT = (2, 2, 2, 3)
EXPECTED_LOAD = {1: 28, 2: 28, 3: 27, 4: 28}
LIGHT = {"PE", "ART", "CIVIC", "ISLAM", "HIST", "GEO", "CS"}
NAMES = {
    "AR": "Arabe", "FR": "Français", "EN": "Anglais", "MATH": "Mathématiques",
    "NS": "Sciences naturelles", "PH": "Physique et technologie", "HIST": "Histoire",
    "GEO": "Géographie", "ISLAM": "Éducation islamique", "CIVIC": "Éducation civique",
    "ART": "Arts / musique", "PE": "EPS", "CS": "Informatique", "FREE": "Heure libre",
    "TD_AM": "TD Arabe ↔ Maths", "TD_AM_1": "TD Arabe ↔ Maths · 1",
    "TD_AM_2": "TD Arabe ↔ Maths · 2", "TD_FE": "TD Français ↔ Anglais",
}


class InfeasibleGrid(ValueError):
    pass


def classes():
    return [(level, section) for level in LEVELS for section in SECTIONS]


def class_code(item):
    return f"{item[0]}AM-{item[1]}"


def resource_batch(item, independent):
    return item[1] if independent else "all"


def afternoon_slots():
    return [(day, period) for day in AFTERNOON_DAYS for period in range(len(AFTERNOON))]


def preflight(gym_rooms, computer_rooms, independent, morning_pe=False):
    if not gym_rooms or not computer_rooms:
        raise InfeasibleGrid("Un espace sportif et une salle informatique au minimum sont nécessaires.")
    if len(set(gym_rooms)) != len(gym_rooms) or len(set(computer_rooms)) != len(computer_rooms):
        raise InfeasibleGrid("Chaque salle doit avoir un nom distinct.")
    batch_size = len(LEVELS) if independent else len(classes())
    sports_needed = batch_size * (1 if morning_pe else 2)
    sports_capacity = len(afternoon_slots()) * len(gym_rooms)
    if sports_needed > sports_capacity:
        raise InfeasibleGrid(
            f"{batch_size} classes exigent {sports_needed} créneaux d’EPS ; "
            f"{len(gym_rooms)} gymnase(s) ne permettent que {sports_capacity} créneaux. "
            "Choisir des espaces sportifs supplémentaires ou des variantes indépendantes."
        )
    if batch_size > len(afternoon_slots()) * len(computer_rooms):
        raise InfeasibleGrid("Capacité de la salle informatique insuffisante.")
    for level in LEVELS:
        lectures = sum(row[level - 1][0] for row in SOURCE.values())
        td = 3 if level == 4 else 2
        if lectures + td != EXPECTED_LOAD[level]:
            raise InfeasibleGrid(f"Volume de la {level}AM incohérent avec les données sources.")


def assign_pe(items, rooms, rng, morning_pe=False):
    """Match each class's two PE periods to distinct afternoons and free rooms."""
    positions = [("afternoon", day, period, room) for day, period in afternoon_slots() for room in rooms]
    if morning_pe:
        positions += [("morning", day, period, room) for day in range(len(DAYS)) for period in range(len(MORNING)) for room in rooms]
    used = set()
    assigned = {}

    def choices(item):
        available = [slot for slot in positions if slot not in used]
        if item[0] == 3:
            available = [s for s in available if not (s[0] == "morning" and s[1:3] == FREE_M3)]
        options = [pair for pair in combinations(available, 2) if pair[0][1] != pair[1][1]
                   and (not morning_pe or pair[0][0] != pair[1][0])]
        rng.shuffle(options)
        return options

    order = list(items)
    rng.shuffle(order)

    def dfs(index):
        if index == len(order):
            return True
        item = order[index]
        for pair in choices(item):
            assigned[item] = pair
            used.update(pair)
            if dfs(index + 1):
                return True
            used.difference_update(pair)
        assigned.pop(item, None)
        return False

    if not dfs(0):
        raise InfeasibleGrid("Impossible d’affecter l’EPS sur des jours distincts.")
    return assigned


def lecture_subject(token):
    return token.split(":")[0]


def morning_counts(level, morning_pe=False):
    counts = Counter({subject: rows[level - 1][0] for subject, rows in SOURCE.items() if subject not in LIGHT})
    # Preserve the one-hour lecture / one-hour practical split in both sciences.
    for subject in ("NS", "PH"):
        del counts[subject]
        counts[f"{subject}:course"] = 1
        counts[f"{subject}:practical"] = 1
    if morning_pe:
        del counts["NS:practical"]
    counts.update({"TD_AM_1": 1, "TD_AM_2": 1, "TD_FE": 1} if level == 4 else {"TD_AM": 1, "TD_FE": 1})
    return counts


def assign_morning(level, rng, fixed_pe=None, afternoon=None):
    remaining = morning_counts(level, fixed_pe is not None)
    grid = {FREE_M3: "FREE"} if level == 3 else {}
    if fixed_pe is not None:
        grid[fixed_pe] = "PE"
    slots = [(day, period) for day in range(len(DAYS)) for period in range(len(MORNING))]
    used_subjects = [set() for _ in DAYS]
    for (day, _), (subject, _) in (afternoon or {}).items():
        used_subjects[day].add(lecture_subject(subject))
    if fixed_pe is not None:
        used_subjects[fixed_pe[0]].add("PE")
    td_days = set()

    def allowed(token, slot):
        day, period = slot
        if token.startswith("TD_"):
            return day not in td_days
        subject = lecture_subject(token)
        return subject not in used_subjects[day] and not (subject in ("AR", "MATH") and period == 3)

    def dfs():
        empty = [slot for slot in slots if slot not in grid]
        if not empty:
            return not any(remaining.values())
        # Most-constrained slot first; randomize ties and candidate subjects.
        rng.shuffle(empty)
        domains = [(slot, [token for token, count in remaining.items() if count and allowed(token, slot)]) for slot in empty]
        if any(not options for _, options in domains):
            return False
        # Each lecture subject, or TD of either pair, may use each day only once.
        needs = Counter()
        available_days = {}
        for token, count in remaining.items():
            if not count:
                continue
            group = "TD" if token.startswith("TD_") else lecture_subject(token)
            needs[group] += count
            available_days.setdefault(group, set()).update(slot[0] for slot in empty if allowed(token, slot))
        if any(count > len(available_days[group]) for group, count in needs.items()):
            return False
        slot, options = min(domains, key=lambda entry: len(entry[1]))
        rng.shuffle(options)
        options.sort(key=lambda token: -remaining[token])
        day = slot[0]
        for token in options:
            is_td = token.startswith("TD_")
            target = td_days if is_td else used_subjects[day]
            key = day if is_td else lecture_subject(token)
            target.add(key)
            grid[slot] = token
            remaining[token] -= 1
            if dfs():
                return True
            remaining[token] += 1
            del grid[slot]
            target.remove(key)
        return False

    if not dfs():
        raise InfeasibleGrid(f"Matinées impossibles pour la {level}AM.")
    return grid


def assign_afternoons(items, pe, computer_rooms, rng, morning_pe=False):
    slots = afternoon_slots()
    others = ["ART", "CIVIC", "ISLAM", "HIST", "GEO", "CS"]
    if morning_pe:
        others.append("NS:practical")
    for _ in range(MAX_AFTERNOON_ATTEMPTS):
        labs = set()
        grids = {}
        order = list(items)
        rng.shuffle(order)
        for item in order:
            grid = {(day, period): ("PE", room) for block, day, period, room in pe[item] if block == "afternoon"}
            available = [slot for slot in slots if slot not in grid]
            for _ in range(200):
                shuffled = others[:]
                rng.shuffle(shuffled)
                proposed = dict(zip(available, shuffled))
                history = next(slot for slot, subject in proposed.items() if subject == "HIST")
                geography = next(slot for slot, subject in proposed.items() if subject == "GEO")
                lab_slot = next(slot for slot, subject in proposed.items() if subject == "CS")
                rooms = [room for room in computer_rooms if (*lab_slot, room) not in labs]
                if history[0] == geography[0] or not rooms:
                    continue
                lab = rng.choice(rooms)
                labs.add((*lab_slot, lab))
                grid.update({slot: (subject, lab if subject == "CS" else None) for slot, subject in proposed.items()})
                grids[item] = grid
                break
            else:
                break
        else:
            return grids
    raise InfeasibleGrid("Les affectations d’après-midi dépassent la limite d’essais.")


def td_groups(token):
    if token in ("TD_AM_1", "TD_AM_2"):
        pair = ("AR", "MATH") if token == "TD_AM_1" else ("MATH", "AR")
        return {week: dict(zip(("G1", "G2"), pair)) for week in ("A", "B")}
    pair = ("AR", "MATH") if token == "TD_AM" else ("FR", "EN")
    return {"A": dict(zip(("G1", "G2"), pair)), "B": dict(zip(("G1", "G2"), reversed(pair)))}


def make_session(day, period, token, afternoon=False, room=None):
    times = AFTERNOON if afternoon else MORNING
    is_td = token.startswith("TD_")
    subject = token if is_td or token == "FREE" else lecture_subject(token)
    kind = "td" if is_td else "free" if token == "FREE" else "practical" if token.endswith(":practical") else "course"
    result = dict(day=day, period=period, block="afternoon" if afternoon else "morning",
                  start=times[period][0], end=times[period][1], subject=subject, kind=kind, room=room)
    if is_td:
        result["groups"] = td_groups(token)
    return result


def generate(gym_rooms=GYM_ROOMS, independent=INDEPENDENT_VARIANTS, computer_rooms=COMPUTER_ROOMS, morning_pe=ALLOW_MORNING_PE):
    preflight(gym_rooms, computer_rooms, independent, morning_pe)
    random.seed(SEED)
    rng = random.Random(SEED)
    batch_ids = SECTIONS if independent else ("all",)
    afternoons = {}
    pe_assignments = {}
    for batch in batch_ids:
        items = [item for item in classes() if resource_batch(item, independent) == batch]
        pe = assign_pe(items, gym_rooms, rng, morning_pe)
        pe_assignments.update(pe)
        afternoons.update(assign_afternoons(items, pe, computer_rooms, rng, morning_pe))
    timetables = []
    for item in classes():
        fixed_pe = next(((day, period) for block, day, period, room in pe_assignments[item] if block == "morning"), None)
        morning = assign_morning(item[0], rng, fixed_pe, afternoons[item])
        pe_room = next((room for block, day, period, room in pe_assignments[item] if block == "morning"), None)
        sessions = [make_session(day, period, token, room=pe_room if token == "PE" else None) for (day, period), token in morning.items()]
        sessions += [make_session(day, period, subject, True, room) for (day, period), (subject, room) in afternoons[item].items()]
        sessions.sort(key=lambda session: (session["day"], session["start"]))
        timetables.append(dict(id=class_code(item), level=item[0], section=item[1],
                               resource_batch=resource_batch(item, independent), weekly_hours=EXPECTED_LOAD[item[0]], sessions=sessions))
    plan = dict(schema_version=1, seed=SEED, source="Données fournies : annexe à la décision ministérielle du 27 juillet 2026",
                independent_variants=independent, morning_pe=morning_pe, gym_rooms=list(gym_rooms), computer_rooms=list(computer_rooms),
                days=list(DAYS), morning=list(MORNING), afternoon=list(AFTERNOON), afternoon_days=list(AFTERNOON_DAYS),
                lunch=list(LUNCH), source_subjects=SOURCE, history_geography_coefficients=HISTORY_GEOGRAPHY_COEFFICIENT,
                timetables=timetables)
    validate(plan)
    return plan


def validate(plan):
    """Audit counts, daily rules, rooms and each half-group's fortnightly TD load."""
    def check(condition, message):
        if not condition:
            raise ValueError(message)
    check({table["id"] for table in plan["timetables"]} == {class_code(item) for item in classes()}, "Expected eight distinct classes")
    check(len(plan["timetables"]) == 8, "Expected exactly eight timetables")
    used_rooms = set()
    signatures = set()
    expected_slots = {("morning", d, p) for d in range(5) for p in range(4)} | {("afternoon", d, p) for d, p in afternoon_slots()}
    for table in plan["timetables"]:
        level = table["level"]
        check(level in LEVELS and table["section"] in SECTIONS and table["id"] == class_code((level, table["section"])), "Incorrect class/level identity")
        check(table["resource_batch"] == resource_batch((level, table["section"]), plan["independent_variants"]), "Incorrect resource sharing scope")
        sessions = table["sessions"]
        slots = [(s["block"], s["day"], s["period"]) for s in sessions]
        check(len(slots) == len(set(slots)) == 28 and set(slots) == expected_slots, "Missing or overlapping period")
        signature = tuple((s["day"], s["start"], s["subject"], s["kind"]) for s in sessions)
        check(signature not in signatures, "Copied timetable")
        signatures.add(signature)
        lectures = Counter(s["subject"] for s in sessions if s["kind"] in ("course", "practical"))
        check(lectures == Counter({subject: rows[level-1][0] for subject, rows in SOURCE.items()}), "Incorrect lecture counts")
        check(sum(s["kind"] != "free" for s in sessions) == table["weekly_hours"] == EXPECTED_LOAD[level], "Incorrect student load")
        tds = Counter(s["subject"] for s in sessions if s["kind"] == "td")
        expected_td = Counter({"TD_AM_1": 1, "TD_AM_2": 1, "TD_FE": 1} if level == 4 else {"TD_AM": 1, "TD_FE": 1})
        check(tds == expected_td, "Incorrect TD pairs")
        free = [(s["day"], s["period"]) for s in sessions if s["kind"] == "free"]
        check(free == ([FREE_M3] if level == 3 else []), "Incorrect free period")
        for subject in ("NS", "PH"):
            check(Counter(s["kind"] for s in sessions if s["subject"] == subject) == {"course": 1, "practical": 1}, "Science lecture/practical split")
        for day in range(5):
            daily = [s for s in sessions if s["day"] == day]
            counts = Counter(s["subject"] for s in daily if s["kind"] in ("course", "practical"))
            check(all(count == 1 for count in counts.values()), "Repeated subject on the same day")
            check(not (counts["HIST"] and counts["GEO"]), "History and geography on the same day")
            check(sum(s["kind"] == "td" for s in daily) <= 1, "More than one TD on a day")
        for s in sessions:
            times = AFTERNOON if s["block"] == "afternoon" else MORNING
            check((s["start"], s["end"]) == times[s["period"]], "Incorrect hour")
            swapped = plan["morning_pe"] and (s["subject"] == "PE" or (s["subject"] == "NS" and s["kind"] == "practical"))
            if not swapped:
                check((s["subject"] in LIGHT) == (s["block"] == "afternoon"), "Incorrect morning/afternoon subject")
            check(not (s["subject"] in ("AR", "MATH") and s["period"] == 3), "Arabic/maths in fourth morning period")
            if s["subject"] in ("PE", "CS"):
                allowed_rooms = plan["gym_rooms"] if s["subject"] == "PE" else plan["computer_rooms"]
                check(s["room"] in allowed_rooms, "Missing shared-room assignment")
                key = (table["resource_batch"], s["day"], s["start"], s["room"])
                check(key not in used_rooms, "Shared room conflict")
                used_rooms.add(key)
            if s["kind"] == "td":
                check(s.get("groups") == td_groups(s["subject"]), "Incorrect TD swap")
        if plan["morning_pe"]:
            check(Counter(s["block"] for s in sessions if s["subject"] == "PE") == {"morning": 1, "afternoon": 1}, "Expected one morning and one afternoon PE period")
            check(all(s["block"] == "afternoon" for s in sessions if s["subject"] == "NS" and s["kind"] == "practical"), "Science practical must replace afternoon PE")
        for group in ("G1", "G2"):
            totals = Counter(s["groups"][week][group] for s in sessions if s["kind"] == "td" for week in ("A", "B"))
            for subject in ("AR", "MATH", "FR", "EN"):
                check(Fraction(totals[subject], 2) == Fraction(str(SOURCE[subject][level-1][1])), "Incorrect half-group TD average")
            if level == 4:
                for week in ("A", "B"):
                    weekly = Counter(s["groups"][week][group] for s in sessions if s["kind"] == "td")
                    check(weekly["AR"] == weekly["MATH"] == 1, "M4 mirrored TD must teach each subject weekly")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--gym-rooms", type=int, default=len(GYM_ROOMS))
    parser.add_argument("--independent-variants", action="store_true", default=INDEPENDENT_VARIANTS)
    parser.add_argument("--morning-pe", action=argparse.BooleanOptionalAction, default=ALLOW_MORNING_PE)
    parser.add_argument("--check-only", action="store_true")
    args = parser.parse_args()
    rooms = GYM_ROOMS if args.gym_rooms == len(GYM_ROOMS) else tuple(f"Gymnase {i+1}" for i in range(args.gym_rooms))
    plan = generate(rooms, args.independent_variants, morning_pe=args.morning_pe)
    if not args.check_only:
        output = OUTPUT_ROOT / "middle_timetables_2026_2027.json"
        output.write_text(json.dumps(plan, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(output)
    print(json.dumps({"validated": 8, "seed": SEED, "gym_rooms": list(rooms),
                      "independent_variants": args.independent_variants, "weekly_hours": EXPECTED_LOAD}))


if __name__ == "__main__":
    main()
