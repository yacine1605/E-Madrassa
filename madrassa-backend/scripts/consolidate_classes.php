<?php
// One-time local consolidation. Dry run by default; --apply commits after backup.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (!app()->environment('local')) throw new RuntimeException('Local environment required.');
$apply = in_array('--apply', $argv, true);
DB::beginTransaction();
try {
    $tenant = App\School\Services\DeploymentSchool::id();
    if (!$tenant) throw new RuntimeException('Missing school.');
    DB::select("select pg_advisory_xact_lock(hashtextextended(?,0))", [$tenant]);
    DB::statement('LOCK TABLE school.offerings, school.cohorts, school.enrollments, school.sessions, school.assessments, school.teacher_assignments IN SHARE ROW EXCLUSIVE MODE');
    $actor = DB::table('school.memberships')->where('tenant_id',$tenant)->where('active',true)->get()
        ->first(fn($m)=>in_array('catalog.write',json_decode($m->permissions,true),true));
    if (!$actor) throw new RuntimeException('Missing catalogue administrator.');
    DB::select("select set_config('app.user_id',?,true), set_config('app.tenant_id',?,true)",[(string)$actor->user_id,$tenant]);
    $query = fn($table)=>DB::table('school.'.$table)->where('tenant_id',$tenant);
    $tables=['offerings','cohorts','enrollments','sessions','assessments','teacher_assignments','teacher_qualifications'];
    $backup=[];
    foreach ($tables as $table) $backup[$table]=$query($table)->get();
    $plan=[];
    foreach ($backup['offerings']->where('active',true) as $offering) {
        $classes=$backup['cohorts']->where('offering_id',$offering->id)->sortBy('name')->values();
        // Preserve the identity of any enrolled class (enrollment identities are immutable).
        $enrolled=$classes->filter(fn($c)=>$backup['enrollments']->contains('cohort_id',$c->id));
        if ($enrolled->count()>1) throw new RuntimeException('Multiple enrolled classes require a separate transfer workflow.');
        $keep=$enrolled->first() ?? $classes->first();
        $level=$query('levels')->where('id',$offering->level_id)->first();
        if (!$level) throw new RuntimeException('Missing level.');
        $plan[]=['offering'=>$offering->id,'keep'=>$keep?->id,'remove'=>$classes->where('id','<>',$keep?->id)->pluck('id')->all(),
            'name'=>$level->name.' — Classe unique','capacity'=>$keep?->capacity ?? 25];
    }
    if ($apply) {
        $path='class-backups/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        if (!Storage::disk('local')->put($path,json_encode(['school'=>$tenant,'data'=>$backup,'plan'=>$plan],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Backup failed.');
        }
        echo 'Backup: '.Storage::disk('local')->path($path)."\n";
    }
    $removed=0; $discarded=0;
    foreach ($plan as $item) {
        $id=$item['keep'];
        if (!$id) {
            $id=(string)Str::uuid();
            DB::table('school.cohorts')->insert(['id'=>$id,'tenant_id'=>$tenant,'offering_id'=>$item['offering'],'name'=>$item['name'],'capacity'=>$item['capacity']]);
        }
        foreach ($item['remove'] as $old) {
            foreach ($query('teacher_assignments')->where('cohort_id',$old)->get() as $assignment) {
                if ($query('teacher_assignments')->where('cohort_id',$id)->where('subject_id',$assignment->subject_id)->exists()) {
                    $discarded += $query('teacher_assignments')->where('id',$assignment->id)->delete();
                } else $query('teacher_assignments')->where('id',$assignment->id)->update(['cohort_id'=>$id]);
            }
            foreach (['sessions','assessments'] as $table) $query($table)->where('cohort_id',$old)->update(['cohort_id'=>$id]);
            $removed += $query('cohorts')->where('id',$old)->delete();
        }
        $query('cohorts')->where('id',$id)->update(['name'=>$item['name']]);
    }
    foreach ($backup['offerings']->where('active',true) as $offering) {
        if ($query('cohorts')->where('offering_id',$offering->id)->count()!==1) throw new RuntimeException('Expected exactly one class per offering.');
    }
    foreach (['enrollments','sessions','assessments','teacher_qualifications'] as $table) {
        if ($query($table)->count()!==$backup[$table]->count()) throw new RuntimeException('Related data count changed: '.$table);
    }
    echo json_encode(['apply'=>$apply,'formations'=>count($plan),'classes_before'=>$backup['cohorts']->count(),'classes_after'=>$query('cohorts')->count(),
        'removed'=>$removed,'duplicate_assignments_removed'=>$discarded,'assignments_after'=>$query('teacher_assignments')->count(),
        'enrollments_preserved'=>$query('enrollments')->count(),'sessions_preserved'=>$query('sessions')->count(),'assessments_preserved'=>$query('assessments')->count()],JSON_UNESCAPED_UNICODE)."\n";
    if ($apply) DB::commit(); else DB::rollBack();
} catch (Throwable $error) { DB::rollBack(); fwrite(STDERR,$error->getMessage()."\n"); exit(1); }
