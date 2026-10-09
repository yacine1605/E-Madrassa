<?php
namespace App\Http\Controllers;
use App\School\Services\SchoolService;
use App\School\Services\StudentLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentLifecycleController
{
    public function __construct(private SchoolService $school, private StudentLifecycle $lifecycle) {}
    private function access(): void { foreach(['students.read','enrollments.read','catalog.read'] as $permission) $this->school->authorize($permission); }
    private function render(string $view, array $data)
    {
        $data['schoolName'] = DB::table('school.institutions')->where('id',$this->school->tenant())->value('name');
        $data['tenant'] = $this->school->tenant();
        return response()->view('lifecycle.'.$view,$data)->header('Cache-Control','private, no-store');
    }
    private function offerings()
    {
        return DB::table('school.offerings as o')->where('o.tenant_id',$this->school->tenant())
            ->join('school.periods as p',fn($j)=>$j->on('p.id','=','o.period_id')->on('p.tenant_id','=','o.tenant_id'))
            ->join('school.levels as l',fn($j)=>$j->on('l.id','=','o.level_id')->on('l.tenant_id','=','o.tenant_id'));
    }
    private function classes()
    {
        return $this->offerings()->join('school.cohorts as c',fn($j)=>$j->on('c.offering_id','=','o.id')->on('c.tenant_id','=','o.tenant_id'));
    }
    public function admissions(Request $request)
    {
        $this->access();
        $filters = $request->validate(['q'=>'nullable|string|max:100','status'=>'nullable|in:submitted,waiting,accepted,rejected,withdrawn,enrolled','offering'=>'nullable|uuid']);
        $offers = $this->offerings()->where('o.active',true)->orderBy('p.starts_on')->orderBy('l.name')->get(['o.id','o.name','p.name as year','l.name as level']);
        $query = $this->school->query('admissions')->orderByDesc('created_at')->orderByDesc('id');
        if(!empty($filters['status'])) $query->where('status',$filters['status']);
        if(!empty($filters['offering'])) $query->where('offering_id',$filters['offering']);
        if(!empty($filters['q'])) {
            $term = '%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$filters['q']).'%';
            $query->where(function($q) use($term) {
                $q->where('first_name','ilike',$term)->orWhere('last_name','ilike',$term)->orWhere('matricule','ilike',$term)
                    ->orWhereIn('student_id',$this->school->query('students')->where(fn($s)=>$s->where('first_name','ilike',$term)->orWhere('last_name','ilike',$term)->orWhere('matricule','ilike',$term))->select('id'));
            });
        }
        $applications = $query->paginate(25)->withQueryString();
        $existing = $this->school->query('students')->whereIn('id',$applications->pluck('student_id')->filter())->get()->keyBy('id');
        $key = (string)Str::uuid();
        $selectedStudent = !empty($request->query('student')) ? $this->school->row('students',(string)$request->query('student')) : null;
        return $this->render('admissions',compact('offers','applications','existing','filters','key','selectedStudent'));
    }
    public function apply(Request $request)
    {
        $this->access();
        $data = $request->validate(['student_id'=>'nullable|uuid','offering_id'=>'required|uuid',
            'matricule'=>'nullable|required_without:student_id|string|max:40','first_name'=>'nullable|required_without:student_id|string|max:100',
            'last_name'=>'nullable|required_without:student_id|string|max:100','birth_date'=>'nullable|date_format:Y-m-d|before_or_equal:today',
            'phone'=>'nullable|string|max:30','email'=>'nullable|email|max:200','guardian_name'=>'nullable|string|max:150',
            'guardian_phone'=>'nullable|string|max:30','previous_school'=>'nullable|string|max:200','idempotency_key'=>'required|uuid']);
        $row = $this->lifecycle->apply($data);
        return redirect()->route('lifecycle.application',['tenant'=>$this->school->tenant(),'application'=>$row->id])->with('success','Candidature enregistrée.');
    }
    public function application(Request $request, string $tenant, string $application)
    {
        $this->access(); $application = $this->school->row('admissions',$application);
        $student = $application->student_id ? $this->school->row('students',$application->student_id) : null;
        $offering = $this->offerings()->where('o.id',$application->offering_id)->first(['o.*','p.name as year','l.name as level']);
        $classes = $this->school->query('cohorts')->where('offering_id',$application->offering_id)->orderBy('name')->get();
        $counts = $this->school->query('enrollments')->whereIn('cohort_id',$classes->pluck('id'))->where('status','<>','cancelled')->selectRaw('cohort_id,count(*) as total')->groupBy('cohort_id')->pluck('total','cohort_id');
        return $this->render('application',compact('application','student','offering','classes','counts'));
    }
    public function review(Request $request, string $tenant, string $application)
    {
        $this->access(); $data = $request->validate(['status'=>'required|in:waiting,accepted,rejected,withdrawn','reason'=>'required|string|min:3|max:1000',
            'documents_complete'=>'sometimes|boolean','version'=>'required|string|size:64']);
        $data['documents_complete'] = (bool)($data['documents_complete'] ?? false);
        $this->lifecycle->review($application,$data);
        return redirect()->route('lifecycle.application',['tenant'=>$tenant,'application'=>$application])->with('success','Décision enregistrée.');
    }
    public function admit(Request $request, string $tenant, string $application)
    {
        $this->access(); $data = $request->validate(['cohort_id'=>'required|uuid','due_on'=>'required|date_format:Y-m-d']);
        $row = $this->lifecycle->admit($application,$data);
        return redirect()->route('lifecycle.history',['tenant'=>$tenant,'student'=>$row->student_id])->with('success','Élève inscrit. La facture a été créée pour cette inscription.');
    }
    public function progression(Request $request)
    {
        $this->access(); $data = $request->validate(['cohort'=>'nullable|uuid']);
        $classes = $this->classes()->orderBy('p.starts_on')->orderBy('c.name')->get(['c.id','c.name','c.offering_id','o.level_id','p.id as period_id','p.name as year','p.starts_on','p.ends_on','l.name as level','o.active','o.price_minor']);
        $class = isset($data['cohort']) ? $classes->firstWhere('id',$data['cohort']) : $classes->first();
        if(isset($data['cohort'])) abort_unless($class,404);
        $targets = $class ? $classes->filter(fn($c)=>$c->active && $c->starts_on > $class->ends_on) : collect();
        $students = $class ? DB::table('school.enrollments as en')->where('en.tenant_id',$this->school->tenant())->where('en.cohort_id',$class->id)->where('en.status','validated')
            ->join('school.students as s',fn($j)=>$j->on('s.id','=','en.student_id')->on('s.tenant_id','=','en.tenant_id'))
            ->orderBy('s.last_name')->orderBy('s.first_name')->orderBy('en.id')->select('en.id','en.student_id','s.matricule','s.first_name','s.last_name')->paginate(25)->withQueryString() : null;
        $decisions = $students ? $this->school->query('annual_progressions')->whereIn('source_enrollment_id',$students->pluck('id'))->get()->keyBy('source_enrollment_id') : collect();
        return $this->render('progression',compact('classes','class','targets','students','decisions'));
    }
    public function progress(Request $request)
    {
        $this->access(); $data = $request->validate(['source_enrollment_ids'=>'required|array|min:1|max:100','source_enrollment_ids.*'=>'required|uuid|distinct',
            'decision'=>'required|in:promoted,repeated,left,completed','reason'=>'required|string|min:3|max:1000',
            'target_cohort_id'=>'nullable|required_if:decision,promoted,repeated|uuid',
            'due_on'=>'nullable|required_if:decision,promoted,repeated|date_format:Y-m-d']);
        if(!in_array($data['decision'],['promoted','repeated'],true)) { $data['target_cohort_id']=null; $data['due_on']=null; }
        foreach($data['source_enrollment_ids'] as $source) $this->lifecycle->progress(['source_enrollment_id'=>$source,
            'decision'=>$data['decision'],'reason'=>$data['reason'],'target_cohort_id'=>$data['target_cohort_id'] ?? null,'due_on'=>$data['due_on'] ?? null]);
        return redirect()->back()->with('success',count($data['source_enrollment_ids']).' décision(s) enregistrée(s). Les dossiers des années précédentes sont conservés.');
    }
    public function history(Request $request, string $tenant, string $student)
    {
        $this->access(); $student = $this->school->row('students',$student);
        $enrollments = $this->classes()->join('school.enrollments as en',fn($j)=>$j->on('en.cohort_id','=','c.id')->on('en.tenant_id','=','c.tenant_id'))
            ->where('en.student_id',$student->id)->orderByDesc('p.starts_on')->orderByDesc('en.created_at')
            ->get(['en.id','en.status','en.price_minor','en.cohort_id','c.name as classroom','l.name as level','p.name as year','p.id as period_id','o.level_id']);
        $decisions = $this->school->query('annual_progressions')->whereIn('source_enrollment_id',$enrollments->pluck('id'))->get()->keyBy('source_enrollment_id');
        return $this->render('history',compact('student','enrollments','decisions'));
    }
}
