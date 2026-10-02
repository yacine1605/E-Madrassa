<?php
// Local one-time import, transactional and idempotent. Default: dry run.
// php import_private_timetable.php [--apply] [--catalog-only] [--calendar=/path/calendar.json]
// Calendar: {"confirmed":true,"closures":[{"start":"2026-12-18","end":"2027-01-02","name":"Hiver"}]}
// Dates of closures are inclusive. No annual commit without a confirmed calendar.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

if (!app()->environment('local') || !config('school.backend')) throw new RuntimeException('Local school backend required.');
$apply = in_array('--apply', $argv, true);
$catalogOnly = in_array('--catalog-only', $argv, true);
$calendarPath = null;
foreach ($argv as $arg) if (str_starts_with($arg, '--calendar=')) $calendarPath = substr($arg, 11);
$calendar = $calendarPath ? json_decode(file_get_contents($calendarPath), true, 512, JSON_THROW_ON_ERROR) : ['confirmed'=>false,'closures'=>[]];
if ($apply && !$catalogOnly && ($calendar['confirmed'] ?? false) !== true) throw new RuntimeException('Provide confirmed school closure dates before committing the annual schedule.');
foreach ($calendar['closures'] as $closure) {
    foreach (['start','end'] as $key) {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $closure[$key], 'Africa/Algiers');
        if (!$date || $date->format('Y-m-d') !== $closure[$key]) throw new RuntimeException('Invalid closure date.');
    }
    if ($closure['end'] < $closure['start']) throw new RuntimeException('Reversed closure range.');
}
$plan = json_decode(file_get_contents(__DIR__.'/private_timetable.json'), true, 512, JSON_THROW_ON_ERROR);
App\School\Services\WeeklyTimetable::validate($plan);
$teachersUsed=array_fill_keys(array_column($plan['sessions'],'teacher'),true);
DB::beginTransaction();
try {
    $tenant = App\School\Services\DeploymentSchool::id();
    if (!$tenant) throw new RuntimeException('School missing.');
    DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))', [$tenant]);
    DB::statement('LOCK TABLE school.sessions,school.cohorts,school.offerings,school.teacher_assignments,school.teacher_qualifications,school.curriculum,school.staff,school.rooms IN SHARE ROW EXCLUSIVE MODE');
    $q = fn($table) => DB::table('school.'.$table)->where('tenant_id',$tenant);
    $actor = $q('memberships')->where('active',true)->get()->first(fn($m)=>
        !array_diff(['catalog.write','pedagogy.write','hr.write'],json_decode($m->permissions,true)));
    if (!$actor) throw new RuntimeException('School administrator missing.');
    DB::select("select set_config('app.user_id',?,true),set_config('app.tenant_id',?,true)",[(string)$actor->user_id,$tenant]);
    $period = $q('periods')->where('starts_on','2026-09-21')->where('ends_on','2027-06-08')->sole();
    $backup = [];
    foreach (['offerings','cohorts','staff','rooms','subjects','curriculum','teacher_assignments','teacher_qualifications','sessions'] as $table) $backup[$table]=$q($table)->get();
    if ($apply) {
        $backupPath='timetable-backups/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        if (!Storage::disk('local')->put($backupPath,json_encode(['tenant'=>$tenant,'tables'=>$backup,'calendar'=>$calendar],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))) throw new RuntimeException('Backup failed.');
        echo 'Backup: '.Storage::disk('local')->path($backupPath)."\n";
    }
    $counts=[];
    $ensure = function ($table,$key,$values=[]) use ($q,$tenant,&$counts) {
        $old=$q($table)->where($key)->first();
        if ($old) return $old;
        $id=(string)Str::uuid();
        DB::table('school.'.$table)->insert(['id'=>$id,'tenant_id'=>$tenant,...$key,...$values]);
        $counts[$table]=($counts[$table]??0)+1;
        return $q($table)->where('id',$id)->sole();
    };
    $newSubjects=array_intersect_key(config('algerian_curriculum.subjects'),array_fill_keys(array_column($plan['lessons'],'subject'),true));
    foreach ($newSubjects as $code=>$name) $ensure('subjects',['code'=>$code],['name'=>$name]);
    foreach (['T-PLAN-ES-01'=>'Enseignant espagnol — nom à renseigner','T-PLAN-SPORT-01'=>'Enseignant sport — nom à renseigner'] as $code=>$name) {
        $ensure('staff',['code'=>$code],['name'=>$name,'is_teacher'=>true,'active'=>true]);
    }
    $teachers=$q('staff')->whereIn('code',array_keys($teachersUsed))->where('active',true)->where('is_teacher',true)->get()->keyBy('code');
    if ($teachers->count()!==17) throw new RuntimeException('Missing active teacher from the selected team.');
    $subjects=$q('subjects')->get()->keyBy('code');
    $classes=[]; $rooms=[]; $levels=[];
    foreach (['PRE','1AP','2AP','3AP','4AP','5AP'] as $levelCode) {
        $offering=DB::table('school.offerings as o')->join('school.levels as l','l.id','=','o.level_id')
            ->join('school.programs as p','p.id','=','l.program_id')->where('o.tenant_id',$tenant)->where('o.active',true)
            ->where('p.name',$levelCode==='PRE'?'Préscolaire':'Primaire')->where('l.code',$levelCode==='PRE'?'1':$levelCode)
            ->where('o.name','like','%2026/2027%')->select('o.*','l.name as level_name')->sole();
        // Correct the demonstrably mismatched year while preserving offering/cohort/enrollment IDs.
        if ($offering->period_id!==$period->id) $q('offerings')->where('id',$offering->id)->update(['period_id'=>$period->id]);
        $oldClasses=$q('cohorts')->where('offering_id',$offering->id)->orderBy('name')->get();
        $expected=$levelCode==='5AP'?1:2;
        if ($oldClasses->count()>$expected) throw new RuntimeException('Unexpected extra classes.');
        foreach (array_slice(['A','B'],0,$expected) as $index=>$letter) {
            $code=$levelCode.'-'.$letter;
            $name=($levelCode==='PRE'?'Préscolaire':$levelCode).' — Classe '.$letter;
            $class=$oldClasses->first(fn($c)=>$c->name===$name);
            if (!$class && $index===0 && $oldClasses->count()===1) {
                $class=$oldClasses->first();
                $q('cohorts')->where('id',$class->id)->update(['name'=>$name]);
            }
            $class ??= $ensure('cohorts',['offering_id'=>$offering->id,'name'=>$name],['capacity'=>25]);
            $classes[$code]=$class;
            $levels[$code]=$offering->level_id;
            $rooms[$code]=$ensure('rooms',['campus_id'=>$offering->campus_id,'name'=>'Salle '.$code],['capacity'=>$class->capacity]);
            if ($rooms[$code]->capacity<$class->capacity) throw new RuntimeException('Room too small.');
        }
    }
    foreach ($plan['lessons'] as $l) {
        $class=$classes[$l['class_code']]; $level=$levels[$l['class_code']];
        $subject=$subjects[$l['subject']]->id; $teacher=$teachers[$l['teacher']]->id;
        $curriculum=$ensure('curriculum',['level_id'=>$level,'subject_id'=>$subject],['hours'=>$l['minutes']/60]);
        if ($curriculum->hours===null || abs((float)$curriculum->hours-$l['minutes']/60)>.0001) {
            $q('curriculum')->where('id',$curriculum->id)->update(['hours'=>$l['minutes']/60]);
            $counts['weekly_hours_updated']=($counts['weekly_hours_updated']??0)+1;
        }
        $ensure('teacher_qualifications',['level_id'=>$level,'subject_id'=>$subject,'teacher_id'=>$teacher]);
        $assignment=$ensure('teacher_assignments',['cohort_id'=>$class->id,'subject_id'=>$subject],['teacher_id'=>$teacher]);
        if ($assignment->teacher_id!==$teacher) {
            $q('teacher_assignments')->where('id',$assignment->id)->update(['teacher_id'=>$teacher]);
            $counts['assignments_updated']=($counts['assignments_updated']??0)+1;
        }
    }
    // Generic workshops have been redistributed to their language teachers.
    // Keep historical assignments when they still grant access to past work.
    foreach ($classes as $code=>$class) {
        $legacy=$subjects[str_starts_with($code,'PRE')?'PRE-ATELIER':'PRI-ATELIER'] ?? null;
        if (!$legacy) continue;
        $removed=$q('curriculum')->where('level_id',$levels[$code])->where('subject_id',$legacy->id)->delete();
        if ($removed) $counts['workshop_links_removed']=($counts['workshop_links_removed']??0)+$removed;
        $hasHistory=false;
        foreach (['sessions','assessments'] as $table) {
            if ($q($table)->where('cohort_id',$class->id)->where('subject_id',$legacy->id)->exists()) $hasHistory=true;
        }
        if (!$hasHistory) {
            $removed=$q('teacher_assignments')->where('cohort_id',$class->id)->where('subject_id',$legacy->id)->delete();
            if ($removed) $counts['workshop_assignments_removed']=($counts['workshop_assignments_removed']??0)+$removed;
        }
    }
    $created=0; $existing=0; $days=0; $skipped=0;
    $start=CarbonImmutable::parse($period->starts_on,'Africa/Algiers');
    $end=CarbonImmutable::parse($period->ends_on,'Africa/Algiers');
    $manifest=[];
    for ($date=$start;!$catalogOnly && $date<=$end;$date=$date->addDay()) {
        $day=$date->dayOfWeek;
        if ($day>4) continue;
        $closed=false;
        foreach ($calendar['closures'] as $closure) if ($date->toDateString()>=$closure['start'] && $date->toDateString()<=$closure['end']) $closed=true;
        if ($closed) { $skipped++; continue; }
        $days++;
        foreach ($plan['sessions'] as $s) {
            if ($s['day']!==$day) continue;
            $data=['cohort_id'=>$classes[$s['class_code']]->id,'subject_id'=>$subjects[$s['subject']]->id,
                   'teacher_id'=>$teachers[$s['teacher']]->id,'room_id'=>$rooms[$s['class_code']]->id,
                   'activity'=>$s['activity'],
                   'starts_at'=>$date->setTimeFromTimeString($s['start'])->toIso8601String(),
                   'ends_at'=>$date->setTimeFromTimeString($s['end'])->toIso8601String()];
            App\School\Services\Curriculum::validateSubject($tenant,$data['cohort_id'],$data['subject_id']);
            App\School\Services\TeacherEligibility::validate($tenant,$data);
            $old=$q('sessions')->where('cohort_id',$data['cohort_id'])->where('starts_at',$data['starts_at'])->first();
            if ($old) {
                foreach (['subject_id','teacher_id','room_id','activity'] as $key) if ($old->$key!==$data[$key]) throw new RuntimeException('Existing session differs; no overwrite allowed.');
                if ($old->status==='cancelled' || !CarbonImmutable::parse($old->ends_at)->equalTo(CarbonImmutable::parse($data['ends_at']))) throw new RuntimeException('Existing session duration/status differs.');
                $existing++; $id=$old->id;
            } else {
                $id=(string)Str::uuid();
                DB::table('school.sessions')->insert(['id'=>$id,'tenant_id'=>$tenant,...$data,'status'=>'planned']);
                $created++;
            }
            $manifest[]=$id;
        }
    }
    $summary=['apply'=>$apply,'catalog_only'=>$catalogOnly,'calendar_confirmed'=>$calendar['confirmed'],'from'=>$period->starts_on,'to'=>$period->ends_on,
        'classes'=>count($classes),'teachers'=>$teachers->count(),'weekly_sessions'=>count($plan['sessions']),'school_days'=>$days,
        'closed_weekdays'=>$skipped,'sessions_created'=>$created,'sessions_existing'=>$existing,'catalog_changes'=>$counts];
    if ($apply) {
        $path='timetable-backups/import-'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        if (!Storage::disk('local')->put($path,json_encode(['summary'=>$summary,'session_ids'=>$manifest,'calendar'=>$calendar,'weekly_plan'=>$plan],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))) throw new RuntimeException('Manifest write failed.');
        DB::commit();
    } else DB::rollBack();
    echo json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
} catch (Throwable $error) {
    DB::rollBack(); fwrite(STDERR,$error->getMessage()."\n"); exit(1);
}
