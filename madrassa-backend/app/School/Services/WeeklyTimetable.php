<?php
namespace App\School\Services;

/** Validate duration, coverage and resource conflicts independently of the solver. */
final class WeeklyTimetable
{
    public static function validate(array $plan): void
    {
        $classes=['PRE-A','PRE-B','1AP-A','1AP-B','2AP-A','2AP-B','3AP-A','3AP-B','4AP-A','4AP-B','5AP-A'];
        if (($plan['schema_version']??null)!==2 || ($plan['classes']??null)!==$classes) throw new \RuntimeException('Unexpected weekly plan version/classes.');
        if (($plan['weekly_minutes']??null)!==config('algerian_curriculum.weekly_reference.school_week_minutes')) {
            throw new \RuntimeException('Weekly programme total differs from the configured volume.');
        }
        if (($plan['recess']['time']??null)!=='10:30' || ($plan['recess']['minutes']??null)!==0) throw new \RuntimeException('Recess is a zero-minute marker, not a reserved time slot.');
        $minutes = function ($time) {
            if (!is_string($time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)) throw new \RuntimeException('Invalid time.');
            return (int)substr($time,0,2)*60+(int)substr($time,3,2);
        };
        $occupied=[]; $totals=[]; $supportTotals=[]; $teachers=[]; $assignments=[];
        foreach ($plan['sessions'] as $s) {
            if (!in_array($s['class_code'],$classes,true) || !in_array($s['day'],[0,1,2,3,4],true)) throw new \RuntimeException('Unknown class/day.');
            $start=$minutes($s['start']); $end=$minutes($s['end']);
            $valid=($start>=510 && $end<=($s['day']===2?750:720)) || ($s['day']!==2 && $start>=780 && $end<=960);
            if (!$valid || $start%15 || $end%15 || !in_array($end-$start,[30,45,60],true) || $end-$start!==$s['minutes']) throw new \RuntimeException('Invalid school time/duration.');
            for ($time=$start;$time<$end;$time+=15) {
                foreach (['class_code','teacher'] as $kind) {
                    $key="$kind:{$s[$kind]}:{$s['day']}:$time";
                    if (isset($occupied[$key])) throw new \RuntimeException('Weekly timetable collision.');
                    $occupied[$key]=true;
                }
            }
            $key=$s['class_code'].':'.$s['subject'];
            if (isset($assignments[$key]) && $assignments[$key]!==$s['teacher']) throw new \RuntimeException('Inconsistent subject teacher.');
            $assignments[$key]=$s['teacher'];
            $totals[$key]=($totals[$key]??0)+$s['minutes'];
            if (!in_array($s['activity']??null,['course','support'],true)) throw new \RuntimeException('Unknown session activity.');
            if ($s['activity']==='support') {
                if (!in_array($s['subject'],['PRI-FR','PRI-EN'],true)) throw new \RuntimeException('Support must belong to French or English.');
                $supportTotals[$key]=($supportTotals[$key]??0)+$s['minutes'];
                $adjacent=false;
                foreach ($plan['sessions'] as $course) {
                    if (($course['activity']??null)==='course' && $course['class_code']===$s['class_code'] && $course['subject']===$s['subject']
                        && $course['teacher']===$s['teacher'] && $course['day']===$s['day']
                        && ($course['end']===$s['start'] || $course['start']===$s['end'])) { $adjacent=true; break; }
                }
                if (!$adjacent) throw new \RuntimeException('Support must be immediately before or after its language course.');
            }
            $teachers[$s['teacher']]=true;
        }
        if (count($teachers)!==17) throw new \RuntimeException('Expected exactly 17 teachers.');
        $lessonKeys=[];
        foreach ($plan['lessons'] as $l) {
            $key=$l['class_code'].':'.$l['subject'];
            if (isset($lessonKeys[$key]) || ($totals[$key]??null)!==$l['minutes'] || ($assignments[$key]??null)!==$l['teacher'] || $l['hours']!=$l['minutes']/60) throw new \RuntimeException('Lesson metadata mismatch.');
            if (($l['support_minutes']??null)!==($supportTotals[$key]??0) || ($l['course_minutes']??null)!==$l['minutes']-($supportTotals[$key]??0)) throw new \RuntimeException('Course/support metadata mismatch.');
            $lessonKeys[$key]=true;
        }
        if (count($lessonKeys)!==count($totals)) throw new \RuntimeException('Missing lesson metadata.');
        $expectedKeys=[];
        foreach ($classes as $class) {
            $preschool=str_starts_with($class,'PRE');
            $expected=WeeklyTeachingPlan::minutes($preschool?'PRESCOLAIRE':'PRIMAIRE',$preschool?'1':$class[0]);
            $expectedSupport=WeeklyTeachingPlan::supportMinutes($preschool?'PRESCOLAIRE':'PRIMAIRE',$preschool?'1':$class[0]);
            if (array_sum($expected)!==$plan['weekly_minutes']) throw new \RuntimeException('Weekly programme total differs from subject volumes.');
            foreach ($expected as $subject=>$duration) {
                $key=$class.':'.$subject;
                $expectedKeys[$key]=true;
                if (($totals[$key]??null)!==$duration) throw new \RuntimeException('Subject weekly volume mismatch: '.$key);
                if (($supportTotals[$key]??0)!==($expectedSupport[$subject]??0)) throw new \RuntimeException('Language support volume mismatch: '.$key);
            }
            foreach (range(0,4) as $day) {
                $windows=[[510,$day===2?750:720]];
                if ($day!==2) $windows[]=[780,960];
                foreach ($windows as [$first,$last]) for ($time=$first;$time<$last;$time+=15) {
                    if (!isset($occupied["class_code:$class:$day:$time"])) throw new \RuntimeException('Unsupervised class time.');
                }
            }
        }
        if (array_diff_key($totals,$expectedKeys)) throw new \RuntimeException('Unexpected subject in timetable.');
    }
}
