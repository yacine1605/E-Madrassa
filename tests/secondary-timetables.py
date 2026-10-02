"""Independent audits and negative tests for the two delivered 1AS grids."""
from collections import Counter
from contextlib import redirect_stdout
from html.parser import HTMLParser
import importlib.util
import io
import json
from pathlib import Path
import random
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("secondary", ROOT / "madrassa-backend/scripts/generate_secondary_timetables.py")
model = importlib.util.module_from_spec(spec)
spec.loader.exec_module(model)


class EmbeddedData(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.active = False
        self.parts = []
        self.feed(html)
        self.data = json.loads(''.join(self.parts))

    def handle_starttag(self, tag, attrs):
        if tag == "script" and dict(attrs).get("id") == "timetable-data":
            self.active = True

    def handle_endtag(self, tag):
        if tag == "script":
            self.active = False

    def handle_data(self, data):
        if self.active:
            self.parts.append(data)


class SecondaryTimetablesTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = EmbeddedData(model.OUTPUT.read_text(encoding="utf-8")).data
        cls.schedules = cls.data["schedules"]

    def test_annex_totals_and_grid(self):
        model.preflight()
        self.assertEqual(len(model.SLOTS), 36)
        self.assertEqual([len(indices) for indices in model.DAY_INDICES], [8, 8, 4, 8, 8])
        for trunk, hours, coef, free in (("arts", 33, 26, 3), ("st", 35, 30, 1)):
            self.assertEqual(sum(row[trunk][0] for row in model.SOURCE.values() if row[trunk]), hours)
            self.assertEqual(sum(row[trunk][1] for row in model.SOURCE.values() if row[trunk]), coef)
            self.assertEqual(Counter(self.schedules[trunk])["FREE"], free)
            self.assertEqual(Counter(self.schedules[trunk]), model.expected_counts(trunk))
        self.assertNotIn("TECH", self.schedules["arts"])

    def test_all_hard_rules_and_single_double_day(self):
        self.assertTrue(model.hard_ok(self.schedules["arts"], "arts"))
        self.assertTrue(model.hard_ok(self.schedules["st"], "st", self.schedules["arts"]))
        for trunk, subject in (("arts", "AR"), ("st", "MATH")):
            per_day = [sum(self.schedules[trunk][i] == subject for i in indices) for indices in model.DAY_INDICES]
            self.assertEqual(sorted(per_day), [1, 1, 1, 1, 2])

    def test_rejects_missing_period_unknown_subject_and_wrong_count(self):
        valid = self.schedules["arts"]
        self.assertFalse(model.hard_ok(valid[:-1], "arts"))
        changed = valid.copy()
        changed[0] = "UNKNOWN"
        self.assertFalse(model.hard_ok(changed, "arts"))
        changed[0] = "FREE"
        self.assertFalse(model.hard_ok(changed, "arts"))

    def test_rejects_morning_and_internal_free_periods(self):
        valid = self.schedules["arts"]
        free_index = valid.index("FREE")
        changed = valid.copy()
        changed[0], changed[free_index] = changed[free_index], changed[0]
        self.assertTrue(any("morning FREE" in error for error in model.hard_errors(changed, "arts")))
        changed = valid.copy()
        day = model.SLOTS[free_index][0]
        afternoon_start = model.SLOTS.index((day, 4))
        changed[afternoon_start], changed[free_index] = changed[free_index], changed[afternoon_start]
        self.assertTrue(any("class follows" in error for error in model.hard_errors(changed, "arts")))

    def test_rejects_both_shared_room_collisions(self):
        for subject in ("PE", "INFO"):
            target = self.schedules["arts"].index(subject)
            changed = self.schedules["st"].copy()
            origin = changed.index(subject)
            changed[target], changed[origin] = changed[origin], changed[target]
            self.assertEqual(Counter(changed), Counter(self.schedules["st"]))
            self.assertTrue(any("booked twice" in error for error in model.hard_errors(changed, "st", self.schedules["arts"])))

    def test_rejects_nonconsecutive_and_cross_lunch_double_periods(self):
        for requested in ((0, 2), (3, 4)):
            changed = self.schedules["arts"].copy()
            double_day = next(d for d in range(len(model.DAYS)) if sum(changed[i] == "AR" for i in model.DAY_INDICES[d]) == 2)
            day = double_day if double_day in model.AFTERNOON_DAYS else model.AFTERNOON_DAYS[0]
            if day != double_day:
                origin = next(i for i in model.DAY_INDICES[double_day] if changed[i] == "AR")
                target = next(i for i in model.DAY_INDICES[day] if changed[i] != "AR")
                changed[target], changed[origin] = changed[origin], changed[target]
            destinations = {model.SLOTS.index((day, p)) for p in requested}
            for target in sorted(destinations):
                if changed[target] != "AR":
                    origin = next(i for i in model.DAY_INDICES[day] if changed[i] == "AR" and i not in destinations)
                    changed[target], changed[origin] = changed[origin], changed[target]
            self.assertEqual(Counter(changed), Counter(self.schedules["arts"]))
            self.assertTrue(any("invalid double period for AR" in error for error in model.hard_errors(changed, "arts")))

    def test_annealing_is_reproducible(self):
        random.seed(model.SEED)
        with redirect_stdout(io.StringIO()):
            arts, arts_search = model.anneal("arts")
            st, st_search = model.anneal("st", arts)
        self.assertEqual(arts, self.schedules["arts"])
        self.assertEqual(st, self.schedules["st"])
        self.assertEqual({"arts": arts_search, "st": st_search}, self.data["search"])


if __name__ == "__main__":
    unittest.main()
