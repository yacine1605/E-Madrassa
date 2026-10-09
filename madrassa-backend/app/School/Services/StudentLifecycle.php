<?php
namespace App\School\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class StudentLifecycle
{
    public function __construct(private SchoolService $school) {}
    public static function admissionVersion(object $row): string
    {
        return hash_hmac('sha256',json_encode([$row->id,$row->status,$row->documents_complete,$row->review_reason,$row->enrollment_id]),config('app.key'));
    }
    public function apply(array $data): object
    {
        $this->school->authorize('enrollments.write');
        $this->school->authorize('students.write');
        $offering = $this->school->row('offerings',$data['offering_id']);
        abort_unless($offering->active,422,'L’offre de formation est inactive.');
        if(!empty($data['student_id'])) $this->school->row('students',$data['student_id']);
        ksort($data); $hash = hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 4))',[$this->school->tenant().':admission:'.$data['idempotency_key']]);
        $old = $this->school->query('admissions')->where('idempotency_key',$data['idempotency_key'])->first();
        if($old) {
            if($old->request_hash !== $hash) throw new ConflictHttpException('Cette clé de candidature correspond à un autre dossier.');
            return $old;
        }
        $row = $this->school->insert('admissions',[...$data,'request_hash'=>$hash,'created_by'=>request()->user()->id]);
        $this->school->event('admission.submitted',$row); return $row;
    }
    public function review(string $id, array $data): object
    {
        $this->school->authorize('enrollments.write');
        $this->school->row('admissions',$id);
        $row = $this->school->query('admissions')->where('id',$id)->lockForUpdate()->first();
        if(!hash_equals(self::admissionVersion($row),$data['version'])) throw ValidationException::withMessages(['version'=>'Le dossier a changé. Rechargez la page.']);
        if(!in_array($row->status,['submitted','waiting','accepted'],true)) throw ValidationException::withMessages(['status'=>'Ce dossier est clos.']);
        if($data['status']==='accepted' && !$data['documents_complete']) throw ValidationException::withMessages(['documents_complete'=>'Confirmez que le dossier est complet avant acceptation.']);
        $this->school->query('admissions')->where('id',$id)->update(['status'=>$data['status'],'review_reason'=>$data['reason'],'documents_complete'=>$data['documents_complete']]);
        $row = $this->school->row('admissions',$id); $this->school->event('admission.reviewed',$row); return $row;
    }
    public function admit(string $id, array $data): object
    {
        $this->school->authorize('enrollments.write'); $this->school->authorize('students.write');
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))',[$this->school->tenant()]);
        $this->school->row('admissions',$id);
        $row = $this->school->query('admissions')->where('id',$id)->lockForUpdate()->first();
        if($row->enrollment_id) {
            $enrollment = $this->school->row('enrollments',$row->enrollment_id);
            $invoice = $this->school->query('invoices')->where('enrollment_id',$enrollment->id)->first();
            if($enrollment->cohort_id !== $data['cohort_id'] || $invoice->due_on !== $data['due_on']) throw new ConflictHttpException('Ce dossier a déjà été inscrit avec une autre classe ou échéance.');
            return $enrollment;
        }
        if($row->status !== 'accepted' || !$row->documents_complete) throw ValidationException::withMessages(['status'=>'Le dossier doit être complet et accepté avant inscription.']);
        $cohort = $this->school->row('cohorts',$data['cohort_id']);
        if($cohort->offering_id !== $row->offering_id) throw ValidationException::withMessages(['cohort_id'=>'Choisissez une classe de l’offre demandée.']);
        $studentId = $row->student_id;
        if(!$studentId) {
            if($this->school->query('students')->where('matricule',$row->matricule)->exists())
                throw ValidationException::withMessages(['matricule'=>'Ce matricule existe déjà. Utilisez une candidature liée à l’élève existant.']);
            $student = $this->school->insert('students',['matricule'=>$row->matricule,'first_name'=>$row->first_name,'last_name'=>$row->last_name,
                'birth_date'=>$row->birth_date,'phone'=>$row->phone,'email'=>$row->email]);
            $studentId = $student->id;
            if($row->guardian_name) {
                $guardian = $this->school->insert('guardians',['name'=>$row->guardian_name,'phone'=>$row->guardian_phone]);
                $this->school->insert('student_guardians',['student_id'=>$studentId,'guardian_id'=>$guardian->id,'relationship'=>'Responsable légal','can_collect'=>false]);
            }
        }
        $enrollment = $this->school->enroll(['student_id'=>$studentId,'offering_id'=>$row->offering_id,'cohort_id'=>$cohort->id,
            'due_on'=>$data['due_on'],'idempotency_key'=>$row->id]);
        $this->school->query('admissions')->where('id',$id)->update(['student_id'=>$studentId,'enrollment_id'=>$enrollment->id,'status'=>'enrolled']);
        $this->school->event('admission.enrolled',$this->school->row('admissions',$id)); return $enrollment;
    }
    public function progress(array $data): object
    {
        $this->school->authorize('enrollments.write'); $this->school->authorize('pedagogy.write');
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))',[$this->school->tenant()]);
        $source = $this->school->row('enrollments',$data['source_enrollment_id']);
        $old = $this->school->query('annual_progressions')->where('source_enrollment_id',$source->id)->first();
        if($old) {
            foreach(['decision','reason','target_cohort_id','due_on'] as $key) if((string)($old->$key ?? '') !== (string)($data[$key] ?? ''))
                throw new ConflictHttpException('Une décision annuelle différente est déjà enregistrée pour cette inscription.');
            return $old;
        }
        if($source->status !== 'validated') throw ValidationException::withMessages(['source_enrollment_id'=>'Sélectionnez une inscription validée.']);
        $previous = $this->school->row('offerings',$source->offering_id);
        $previousYear = $this->school->row('periods',$previous->period_id);
        $newId = null;
        if(in_array($data['decision'],['promoted','repeated'],true)) {
            $target = $this->school->row('cohorts',$data['target_cohort_id']);
            $offering = $this->school->row('offerings',$target->offering_id);
            $year = $this->school->row('periods',$offering->period_id);
            if($year->starts_on <= $previousYear->ends_on) throw ValidationException::withMessages(['target_cohort_id'=>'La classe cible doit appartenir à une année scolaire postérieure.']);
            if(($data['decision']==='repeated') !== ($previous->level_id === $offering->level_id))
                throw ValidationException::withMessages(['decision'=>'Un redoublement conserve le niveau ; un passage change de niveau.']);
            $new = $this->school->enroll(['student_id'=>$source->student_id,'offering_id'=>$offering->id,'cohort_id'=>$target->id,
                'due_on'=>$data['due_on'],'idempotency_key'=>$source->id]);
            $newId = $new->id;
        }
        $row = $this->school->insert('annual_progressions',[...$data,'new_enrollment_id'=>$newId,'decided_by'=>request()->user()->id]);
        $this->school->event('student.annual_progression',$row); return $row;
    }
}
