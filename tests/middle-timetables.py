"""Independent regression checks for the middle-school timetable request."""
import copy
from collections import Counter
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('middle', ROOT/'madrassa-backend/scripts/generate_middle_timetables.py')
middle = importlib.util.module_from_spec(spec)
spec.loader.exec_module(middle)


class MiddleTimetables(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.plan = middle.generate()

    def test_all_classes_exact_loads_and_shared_rooms(self):
        middle.validate(self.plan)
        self.assertFalse(self.plan['independent_variants'])
        self.assertEqual([t['weekly_hours'] for t in self.plan['timetables']], [28,28,28,28,27,27,28,28])
        resource_slots = Counter()
        for table in self.plan['timetables']:
            for s in table['sessions']:
                if s['room']:
                    resource_slots[s['room'],s['day'],s['start']] += 1
            pe = [s for s in table['sessions'] if s['subject']=='PE']
            self.assertEqual({s['block'] for s in pe}, {'morning','afternoon'})
            self.assertEqual(len({s['day'] for s in pe}),2)
        self.assertTrue(all(count==1 for count in resource_slots.values()))
        self.assertEqual(len(resource_slots),24)  # 16 gym periods + 8 lab periods.

    def test_seed_is_reproducible_and_grids_are_distinct(self):
        self.assertEqual(self.plan,middle.generate())
        signatures={tuple((s['day'],s['start'],s['subject'],s['kind']) for s in t['sessions']) for t in self.plan['timetables']}
        self.assertEqual(len(signatures),8)

    def test_original_eight_class_gym_capacity_is_impossible(self):
        with self.assertRaises(middle.InfeasibleGrid):
            middle.generate(morning_pe=False)
        alternative=middle.generate(gym_rooms=('Gym 1','Gym 2'),morning_pe=False)
        middle.validate(alternative)
        self.assertTrue(all(s['block']=='afternoon' for t in alternative['timetables'] for s in t['sessions'] if s['subject']=='PE'))

    def test_half_group_td_averages_and_m4_mirroring(self):
        for table in self.plan['timetables']:
            for group in ('G1','G2'):
                average=Counter()
                for week in ('A','B'):
                    weekly=Counter(s['groups'][week][group] for s in table['sessions'] if s['kind']=='td')
                    average.update(weekly)
                    if table['level']==4:
                        self.assertEqual(weekly['AR'],1)
                        self.assertEqual(weekly['MATH'],1)
                self.assertEqual(average['FR'],1)
                self.assertEqual(average['EN'],1)
                self.assertEqual(average['AR'],2 if table['level']==4 else 1)
                self.assertEqual(average['MATH'],2 if table['level']==4 else 1)

    def test_invalid_source_load_and_td_swap_are_rejected(self):
        bad=copy.deepcopy(self.plan)
        bad['timetables'][0]['sessions'].pop()
        with self.assertRaisesRegex(ValueError,'Missing or overlapping'):
            middle.validate(bad)
        bad=copy.deepcopy(self.plan)
        td=next(s for s in bad['timetables'][0]['sessions'] if s['subject']=='TD_FE')
        td['groups']['B']=td['groups']['A'].copy()
        with self.assertRaisesRegex(ValueError,'Incorrect TD swap'):
            middle.validate(bad)

    def test_m3_free_period_and_science_practicals(self):
        for table in self.plan['timetables']:
            free=[(s['day'],s['start'],s['end']) for s in table['sessions'] if s['kind']=='free']
            self.assertEqual(free,[(4,'11:00','12:00')] if table['level']==3 else [])
            practical=next(s for s in table['sessions'] if s['subject']=='NS' and s['kind']=='practical')
            self.assertEqual(practical['block'],'afternoon')
            for subject in ('NS','PH'):
                sessions=[s for s in table['sessions'] if s['subject']==subject]
                self.assertEqual({s['kind'] for s in sessions},{'course','practical'})
                self.assertEqual(len({s['day'] for s in sessions}),2)


if __name__=='__main__':
    unittest.main(verbosity=2)
