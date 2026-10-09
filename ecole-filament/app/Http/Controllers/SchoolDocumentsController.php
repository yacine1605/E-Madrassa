<?php
namespace App\Http\Controllers;

use App\School\Services\ReportCardArchive;
use App\School\Services\ReportCardPresets;
use App\School\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Pagination\LengthAwarePaginator;

class SchoolDocumentsController
{
    public function __construct(private SchoolService $school, private ReportCardArchive $archives) {}
    private function render(string $view, array $data = [])
    {
        // Render while TenantContext's RLS transaction is still active.
        $data['school'] ??= DB::table('school.institutions')->where('id', $this->school->tenant())->firstOrFail();
        return response()->view('documents.'.$view, $data)->header('Cache-Control', 'private, no-store');
    }
    private function classes()
    {
        return DB::table('school.cohorts as c')->where('c.tenant_id',$this->school->tenant())
            ->join('school.offerings as o', fn($j) => $j->on('o.id','=','c.offering_id')->on('o.tenant_id','=','c.tenant_id'))
            ->join('school.levels as l', fn($j) => $j->on('l.id','=','o.level_id')->on('l.tenant_id','=','o.tenant_id'))
            ->join('school.programs as pr', fn($j) => $j->on('pr.id','=','l.program_id')->on('pr.tenant_id','=','l.tenant_id'))
            ->join('school.periods as p', fn($j) => $j->on('p.id','=','o.period_id')->on('p.tenant_id','=','o.tenant_id'));
    }
    public function index(Request $request)
    {
        $this->school->authorize('pedagogy.read');
        $data = $request->validate(['cohort'=>'nullable|uuid']);
        $classes = $this->classes()->orderBy('c.name')->get(['c.id','c.name','o.level_id','o.period_id','p.name as year','l.name as level',
            'l.code as level_code','pr.code as program_code','pr.kind as program_kind','p.starts_on as year_start','p.ends_on as year_end']);
        $class = isset($data['cohort']) ? $classes->firstWhere('id',$data['cohort']) : $classes->first();
        if (isset($data['cohort'])) abort_unless($class,404);
        $subjects = $class ? DB::table('school.curriculum as cu')->where('cu.tenant_id',$this->school->tenant())
            ->join('school.subjects as s',fn($j)=>$j->on('s.id','=','cu.subject_id')->on('s.tenant_id','=','cu.tenant_id'))
            ->where('cu.level_id',$class->level_id)->orderBy('s.name')->get(['s.id','s.code','s.name','cu.coefficient']) : collect();
        $preset = $class ? ReportCardPresets::forClass($class) : null;
        $hasBothArts = $subjects->contains('code','MOY-ART') && $subjects->contains('code','MOY-MUSIC');
        $terms = $class ? $this->school->query('report_card_terms')->where('level_id',$class->level_id)->where('period_id',$class->period_id)->orderBy('trimester')->get() : collect();
        $students = $class && in_array('students.read',$request->attributes->get('school.permissions'),true)
            ? $this->roster($class->id)->paginate(25)->withQueryString() : null;
        $archiveRows = $class ? $this->school->query('report_card_archives')->where('cohort_id',$class->id)->where('period_id',$class->period_id)
            ->get(['id','kind','term_id','status','validated_at','closed_at']) : collect();
        return $this->render('index',compact('classes','class','subjects','terms','students','preset','hasBothArts','archiveRows'));
    }
    public function configure(Request $request)
    {
        $this->school->authorize('pedagogy.write');
        $data = $request->validate([
            'cohort'=>'required|uuid','trimester'=>'required|integer|between:1,3',
            'starts_on'=>'required|date_format:Y-m-d','ends_on'=>'required|date_format:Y-m-d|after_or_equal:starts_on',
            'scale'=>'required|integer|in:10,20','reference'=>'required|string|min:3|max:1000',
            'rules'=>'required|array|min:1|max:100','rules.*'=>'required|array:coefficient,continu,devoir,examen,qualitative',
            'rules.*.coefficient'=>'required|numeric|between:0.01,100','rules.*.continu'=>'required|numeric|between:0,100',
            'rules.*.devoir'=>'required|numeric|between:0,100','rules.*.examen'=>'required|numeric|between:0,100',
            'rules.*.qualitative'=>'sometimes|boolean',
        ]);
        $class = $this->classes()->where('c.id',$data['cohort'])->first(['o.level_id','o.period_id','p.starts_on as year_start','p.ends_on as year_end',
            'l.code as level_code','pr.code as program_code','pr.kind as program_kind']);
        abort_unless($class,404);
        $ids = $this->school->query('curriculum')->where('level_id',$class->level_id)->pluck('subject_id')->all();
        $submitted = array_keys($data['rules']); sort($ids); sort($submitted);
        if ($ids !== $submitted || $data['starts_on'] < $class->year_start || $data['ends_on'] > $class->year_end)
            throw ValidationException::withMessages(['rules'=>'Vérifiez les matières et les dates de l’année scolaire.']);
        foreach ($data['rules'] as &$rule) {
            $rule['qualitative'] = (bool)($rule['qualitative'] ?? false);
            if (!$rule['qualitative'] && $rule['continu']+$rule['devoir']+$rule['examen'] <= 0)
                throw ValidationException::withMessages(['rules'=>'Chaque matière chiffrée doit avoir au moins une composante de calcul.']);
        }
        unset($rule);
        if (ReportCardPresets::forClass($class)) {
            $arts = $this->school->query('subjects')->whereIn('id',$ids)->whereIn('code',['MOY-ART','MOY-MUSIC'])->pluck('id');
            if ($arts->filter(fn($id) => !$data['rules'][$id]['qualitative'])->count() > 1)
                throw ValidationException::withMessages(['rules'=>'Arts plastiques ou musique : une seule de ces matières peut participer à la moyenne. Cochez « Hors moyenne / qualitative » pour l’autre.']);
        }
        $overlap = $this->school->query('report_card_terms')->where('level_id',$class->level_id)->where('period_id',$class->period_id)
            ->where('trimester','<>',$data['trimester'])->where('starts_on','<=',$data['ends_on'])->where('ends_on','>=',$data['starts_on'])->exists();
        if ($overlap) throw ValidationException::withMessages(['starts_on'=>'Les dates des trimestres ne doivent pas se chevaucher.']);
        $configured = $this->school->insert('report_card_terms',[
            'level_id'=>$class->level_id,'period_id'=>$class->period_id,'trimester'=>$data['trimester'],
            'starts_on'=>$data['starts_on'],'ends_on'=>$data['ends_on'],'scale'=>$data['scale'],
            'reference'=>$data['reference'],'rules'=>json_encode($data['rules'],JSON_THROW_ON_ERROR),'created_by'=>$request->user()->id,
        ]);
        $this->school->event('report_card.term_configured',$configured);
        return redirect()->route('documents.index',['tenant'=>$this->school->tenant(),'cohort'=>$data['cohort']])->with('success','Trimestre configuré pour ce niveau et cette année scolaire.');
    }
    private function roster(string $cohort)
    {
        return DB::table('school.enrollments as en')->where('en.tenant_id',$this->school->tenant())->where('en.cohort_id',$cohort)->where('en.status','validated')
            ->join('school.students as s',fn($j)=>$j->on('s.id','=','en.student_id')->on('s.tenant_id','=','en.tenant_id'))
            ->orderBy('s.last_name')->orderBy('s.first_name')->orderBy('en.id')
            ->select('en.id','s.first_name','s.last_name','s.matricule','s.birth_date');
    }
    public function bulletins(Request $request, string $tenant, string $term, string $cohort)
    {
        return $this->preview($request,'term',$term,$cohort);
    }
    public function annual(Request $request, string $tenant, string $cohort)
    {
        return $this->preview($request,'annual',null,$cohort);
    }
    private function paginate(Request $request, array $payload): LengthAwarePaginator
    {
        $data = $request->validate(['enrollment'=>'nullable|uuid','page'=>'nullable|integer|min:1','download'=>'sometimes|boolean']);
        $cards = collect($payload['cards']);
        if (isset($data['enrollment'])) {
            $cards = $cards->filter(fn($card)=>$card['student']['id'] === $data['enrollment'])->values();
            abort_unless($cards->count(),404);
        }
        $page = (int)($data['page'] ?? 1);
        return new LengthAwarePaginator($cards->forPage($page,50)->values(),$cards->count(),50,$page,
            ['path'=>$request->url(),'query'=>$request->query()]);
    }
    private function preview(Request $request, string $kind, ?string $termId, string $cohort)
    {
        $this->school->authorize('pedagogy.read'); $this->school->authorize('students.read');
        $archive = $this->archives->find($kind,$termId,$cohort);
        if ($archive?->status === 'closed') return $this->frozen($request,$archive);
        $payload = $archive?->status === 'validated' ? json_decode($archive->payload,true,512,JSON_THROW_ON_ERROR) : $this->archives->snapshot($kind,$termId,$cohort);
        $version = ReportCardArchive::digest($payload); $status = $archive?->status ?? 'draft';
        $students = $this->paginate($request,$payload); $cards = $students->items();
        $class = (object)$payload['class']; $school = (object)$payload['school']; $scale = $payload['scale'];
        $term = isset($payload['term']) ? (object)$payload['term'] : null;
        return $this->render($kind === 'term' ? 'bulletins' : 'annual',compact('class','school','scale','term','kind','cards','students','version','status','archive','payload'));
    }
    public function archive(Request $request, string $tenant, string $archive)
    {
        $this->school->authorize('pedagogy.read'); $this->school->authorize('students.read');
        $row = $this->school->row('report_card_archives',$archive);
        abort_unless($row->status === 'closed',404);
        return $this->frozen($request,$row);
    }
    private function frozen(Request $request, object $archive)
    {
        $payload = json_decode($archive->payload,true,512,JSON_THROW_ON_ERROR);
        $rendered = json_decode($archive->rendered,true,512,JSON_THROW_ON_ERROR);
        $students = $this->paginate($request,$payload);
        $renderedCards = array_map(fn($card)=>$rendered['cards'][$card['student']['id']],$students->items());
        $school = (object)$payload['school']; $styles = $rendered['styles']; $download = $request->boolean('download');
        $response = $this->render('archive',compact('archive','students','renderedCards','school','styles','download'));
        if ($download) $response->header('Content-Disposition','attachment; filename="bulletins-'.$archive->id.'.html"');
        return $response;
    }
    public function validateTerm(Request $request, string $tenant, string $term, string $cohort) { return $this->workflow($request,'term',$term,$cohort,'validate'); }
    public function closeTerm(Request $request, string $tenant, string $term, string $cohort) { return $this->workflow($request,'term',$term,$cohort,'close'); }
    public function draftTerm(Request $request, string $tenant, string $term, string $cohort) { return $this->workflow($request,'term',$term,$cohort,'draft'); }
    public function validateAnnual(Request $request, string $tenant, string $cohort) { return $this->workflow($request,'annual',null,$cohort,'validate'); }
    public function closeAnnual(Request $request, string $tenant, string $cohort) { return $this->workflow($request,'annual',null,$cohort,'close'); }
    public function draftAnnual(Request $request, string $tenant, string $cohort) { return $this->workflow($request,'annual',null,$cohort,'draft'); }
    private function workflow(Request $request, string $kind, ?string $term, string $cohort, string $action)
    {
        $this->school->authorize('pedagogy.read'); $this->school->authorize('students.read'); $this->school->authorize('pedagogy.write');
        $data = $request->validate(['version'=>'required|string|regex:/^[0-9a-f]{64}$/']);
        if ($action === 'validate') $this->archives->validate($kind,$term,$cohort,$data['version']);
        elseif ($action === 'draft') $this->archives->draft($kind,$term,$cohort,$data['version']);
        else $this->archives->close($kind,$term,$cohort,$data['version'],function($payload,$id,$closedAt) {
            $cards = []; $school = (object)$payload['school']; $class = (object)$payload['class']; $scale = $payload['scale'];
            $term = isset($payload['term']) ? (object)$payload['term'] : null; $archiveId = $id;
            foreach ($payload['cards'] as $card) $cards[$card['student']['id']] = view('documents.partials.'.$payload['kind'].'-card',
                compact('school','class','scale','term','card','payload','archiveId','closedAt'))->render();
            return ['styles'=>file_get_contents(public_path('css/documents.css')),'cards'=>$cards];
        });
        return redirect()->route($kind === 'term' ? 'documents.bulletins' : 'documents.annual',
            array_filter(['tenant'=>$this->school->tenant(),'term'=>$term,'cohort'=>$cohort]))
            ->with('success',match($action) {'validate'=>'Bulletins validés. Vérifiez la copie avant clôture.','draft'=>'Lot revenu au brouillon.','close'=>'Bulletins clôturés et archivés définitivement.'});
    }
    public function observations(Request $request, string $tenant, string $term, string $cohort)
    {
        $this->school->authorize('pedagogy.write'); $this->school->authorize('students.read');
        abort_unless(Str::isUuid($cohort),404);
        $term = $this->school->row('report_card_terms',$term);
        $this->archives->lock('term',$term->id,$cohort);
        $archive = $this->archives->find('term',$term->id,$cohort);
        if ($archive && $archive->status !== 'draft') throw ValidationException::withMessages(['observations'=>'Ce lot est validé ou clôturé. Les observations sont verrouillées.']);
        abort_unless($this->classes()->where('c.id',$cohort)->where('o.level_id',$term->level_id)->where('o.period_id',$term->period_id)->exists(),404);
        $data = $request->validate(['enrollment'=>'required|uuid','observations'=>'required|array|max:100','observations.*'=>'nullable|string|max:1000']);
        abort_unless($this->roster($cohort)->where('en.id',$data['enrollment'])->exists(),404);
        $rules = json_decode($term->rules,true,512,JSON_THROW_ON_ERROR);
        foreach ($data['observations'] as $subject => $observation) abort_unless(isset($rules[$subject]),422);
        foreach ($data['observations'] as $subject => $observation) {
            $key = ['tenant_id'=>$tenant,'term_id'=>$term->id,'enrollment_id'=>$data['enrollment'],'subject_id'=>$subject];
            // Serialize concurrent observation writes before insert/update.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 3))',[$tenant.':'.$term->id.':'.$data['enrollment'].':'.$subject]);
            $old = $this->school->query('report_card_observations')->where('term_id',$term->id)->where('enrollment_id',$data['enrollment'])->where('subject_id',$subject)->first();
            if ($old) {
                $this->school->query('report_card_observations')->where('id',$old->id)->update(['observation'=>$observation ?? '']);
                $row = $this->school->row('report_card_observations',$old->id);
            } else $row = $this->school->insert('report_card_observations',[...$key,'observation'=>$observation ?? '']);
            $this->school->event('report_card.observation_saved',$row);
        }
        return redirect()->route('documents.bulletins',['tenant'=>$tenant,'term'=>$term->id,'cohort'=>$cohort,'enrollment'=>$data['enrollment']])->with('success','Observations enregistrées.');
    }
}
