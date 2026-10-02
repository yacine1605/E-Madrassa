<?php
namespace App\School\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AlgerianCurriculum
{
    // Caller owns the transaction and tenant/actor context. Idempotent.
    public function sync(string $tenant): array
    {
        $stats = ['subjects_created'=>0,'subjects_renamed'=>0,'links_added'=>0,'links_removed'=>0,'classes_configured'=>0,'weekly_hours_updated'=>0];
        $q = fn ($table) => DB::table('school.'.$table)->where('tenant_id', $tenant);
        $insert = function ($table, $data) use ($tenant) {
            $id = (string) Str::uuid();
            DB::table('school.'.$table)->insert(['id'=>$id,'tenant_id'=>$tenant,...$data]);
            return $id;
        };
        $programs = $q('programs')->where('kind','school')->get();
        $secondary = $programs->first(fn ($p) => strtoupper($p->code) === 'SECONDAIRE');
        if ($secondary) {
            foreach (config('algerian_curriculum.tracks') as $code => $track) {
                $level = $q('levels')->where('program_id',$secondary->id)->where('code',$code)->first();
                if (!$level) $level = (object)['id'=>$insert('levels',['program_id'=>$secondary->id,'code'=>$code,'name'=>$track['name'],'position'=>1])];
                foreach ($q('cohorts')->where('name',$track['legacy_class'])->get() as $class) {
                    $old = $q('offerings')->where('id',$class->offering_id)->first();
                    $oldLevel = $q('levels')->where('id',$old->level_id)->first();
                    if ($oldLevel->program_id !== $secondary->id || !in_array($oldLevel->code,['1','1AS'],true)) continue;
                    $period = $q('periods')->where('id',$old->period_id)->first();
                    if ($q('enrollments')->where('cohort_id',$class->id)->exists()) throw new \RuntimeException('La classe '.$class->name.' contient des inscriptions : rattachement automatique refusé.');
                    $offer = $q('offerings')->where('level_id',$level->id)->where('period_id',$old->period_id)->where('campus_id',$old->campus_id)->first();
                    $offerId = $offer?->id ?? $insert('offerings',['level_id'=>$level->id,'period_id'=>$old->period_id,'campus_id'=>$old->campus_id,
                        'name'=>$track['name'].' — '.$period->name,'price_minor'=>$old->price_minor,'active'=>$old->active]);
                    $q('cohorts')->where('id',$class->id)->update(['offering_id'=>$offerId,'name'=>$track['name']]);
                    if (!$q('cohorts')->where('offering_id',$old->id)->exists()) $q('offerings')->where('id',$old->id)->update(['active'=>false]);
                    $stats['classes_configured']++;
                }
            }
        }
        $subjectIds = [];
        foreach (config('algerian_curriculum.subjects') as $code => $name) {
            $subject = $q('subjects')->where('code',$code)->first();
            if (!$subject) { $subjectIds[$code] = $insert('subjects',['code'=>$code,'name'=>$name]); $stats['subjects_created']++; }
            else {
                $subjectIds[$code] = $subject->id;
                if ($subject->name !== $name) { $q('subjects')->where('id',$subject->id)->update(['name'=>$name]); $stats['subjects_renamed']++; }
            }
        }
        foreach ($programs as $program) {
            $plans = config('algerian_curriculum.plans')[strtoupper($program->code)] ?? [];
            foreach ($q('levels')->where('program_id',$program->id)->get() as $level) {
                $key = preg_replace('/^(\d)(AP|AM)$/', '$1', strtoupper($level->code));
                if (!isset($plans[$key])) continue;
                $ids = array_map(fn ($code) => $subjectIds[$code], $plans[$key]);
                $weeklyMinutes = WeeklyTeachingPlan::minutes(strtoupper($program->code), $key);
                // Remove membership only; retain subjects and all historical records.
                $stats['links_removed'] += $q('curriculum')->where('level_id',$level->id)->whereNotIn('subject_id',$ids)->delete();
                foreach ($ids as $subject) {
                    if (!$q('curriculum')->where('level_id',$level->id)->where('subject_id',$subject)->exists()) {
                        $insert('curriculum',['level_id'=>$level->id,'subject_id'=>$subject,'coefficient'=>1]);
                        $stats['links_added']++;
                    }
                }
                foreach ($weeklyMinutes as $code => $minutes) {
                    $row = $q('curriculum')->where('level_id',$level->id)->where('subject_id',$subjectIds[$code]);
                    $current = $row->first();
                    if ($current->hours === null || abs((float)$current->hours - $minutes / 60) > 0.0001) {
                        $row->update(['hours'=>$minutes / 60]);
                        $stats['weekly_hours_updated']++;
                    }
                }
            }
        }
        return $stats;
    }
}
