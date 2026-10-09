<?php
namespace App\School\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportCardArchive
{
    public function __construct(private SchoolService $school) {}

    public static function digest(array $payload): string
    {
        // PostgreSQL JSONB reorders object keys; the signature must survive a round-trip.
        $canonical = function(array $value) use (&$canonical): array {
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$entry) if (is_array($entry)) $entry = $canonical($entry);
            unset($entry);
            return $value;
        };
        return hash('sha256', json_encode($canonical($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function context(string $cohort): array
    {
        $c = $this->school->row('cohorts', $cohort);
        $o = $this->school->row('offerings', $c->offering_id);
        $l = $this->school->row('levels', $o->level_id);
        $p = $this->school->row('periods', $o->period_id);
        $institution = DB::table('school.institutions')->where('id',$this->school->tenant())->firstOrFail();
        return ['school'=>['id'=>$institution->id,'name'=>$institution->name],
            'class'=>['id'=>$c->id,'name'=>$c->name,'level'=>$l->name,'level_id'=>$l->id,'year'=>$p->name,'period_id'=>$p->id]];
    }

    public function find(string $kind, ?string $term, string $cohort): ?object
    {
        $this->school->row('cohorts', $cohort);
        $query = $this->school->query('report_card_archives')->where('kind',$kind)->where('cohort_id',$cohort);
        if ($kind === 'term') { $this->school->row('report_card_terms', $term); $query->where('term_id',$term); }
        else $query->where('period_id',$this->context($cohort)['class']['period_id']);
        return $query->first();
    }

    public function lock(string $kind, ?string $term, string $cohort): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 7))',[$this->school->tenant().':report-card:'.$kind.':'.$term.':'.$cohort]);
    }

    public function snapshot(string $kind, ?string $term, string $cohort): array
    {
        return $kind === 'term' ? $this->termSnapshot($term,$cohort) : $this->annualSnapshot($cohort);
    }

    private function termSnapshot(string $id, string $cohort): array
    {
        $context = $this->context($cohort);
        $term = $this->school->row('report_card_terms',$id);
        abort_unless($term->level_id === $context['class']['level_id'] && $term->period_id === $context['class']['period_id'],404);
        $rules = json_decode($term->rules,true,512,JSON_THROW_ON_ERROR);
        $subjects = $this->school->query('subjects')->whereIn('id',array_keys($rules))->orderBy('id')->get();
        $assessments = $this->school->query('assessments')->where('cohort_id',$cohort)->whereNotNull('published_at')
            ->whereBetween('held_on',[$term->starts_on,$term->ends_on])->orderBy('id')->get();
        $students = DB::table('school.enrollments as en')->where('en.tenant_id',$this->school->tenant())->where('en.cohort_id',$cohort)->where('en.status','validated')
            ->join('school.students as s',fn($j)=>$j->on('s.id','=','en.student_id')->on('s.tenant_id','=','en.tenant_id'))
            ->orderBy('s.last_name')->orderBy('s.first_name')->orderBy('en.id')->get(['en.id','s.first_name','s.last_name','s.matricule','s.birth_date']);
        $grades = $this->school->query('grades')->whereIn('assessment_id',$assessments->pluck('id'))->whereIn('enrollment_id',$students->pluck('id'))
            ->get()->keyBy(fn($g)=>$g->enrollment_id.':'.$g->assessment_id);
        $observations = $this->school->query('report_card_observations')->where('term_id',$id)->whereIn('enrollment_id',$students->pluck('id'))
            ->get()->keyBy(fn($o)=>$o->enrollment_id.':'.$o->subject_id);
        $cards = [];
        foreach ($students as $student) {
            $lines = [];
            foreach ($subjects as $subject) {
                $rule = $rules[$subject->id]; $marks = [];
                foreach ($assessments->where('subject_id',$subject->id) as $assessment) {
                    $grade = $grades->get($student->id.':'.$assessment->id);
                    $marks[] = ['assessment_id'=>$assessment->id,'kind'=>$assessment->kind,'coefficient'=>$assessment->coefficient,
                        'max_score'=>$assessment->max_score,'status'=>$grade?->status ?? 'missing','score'=>$grade?->score];
                }
                $lines[] = ['name'=>$subject->name,'coefficient'=>(float)$rule['coefficient'],'qualitative'=>$rule['qualitative'],
                    'subject_id'=>$subject->id,'observation'=>$observations->get($student->id.':'.$subject->id)?->observation ?? '',
                    'grades'=>$marks,...ReportCardCalculator::subject($rule,$marks,$term->scale)];
            }
            $cards[] = ['student'=>(array)$student,'lines'=>$lines,'average'=>ReportCardCalculator::overall($lines)];
        }
        return [...$context,'schema_version'=>1,'kind'=>'term','scale'=>(int)$term->scale,
            'term'=>['id'=>$term->id,'trimester'=>(int)$term->trimester,'starts_on'=>$term->starts_on,'ends_on'=>$term->ends_on,
                'scale'=>(int)$term->scale,'reference'=>$term->reference,'rules'=>$rules],'cards'=>$cards];
    }

    private function annualSnapshot(string $cohort): array
    {
        $context = $this->context($cohort);
        $archives = $this->school->query('report_card_archives')->where('kind','term')->where('cohort_id',$cohort)
            ->where('period_id',$context['class']['period_id'])->where('status','closed')->get();
        $terms = []; $sources = []; $scales = [];
        foreach ($archives as $archive) {
            $payload = json_decode($archive->payload,true,512,JSON_THROW_ON_ERROR);
            $number = $payload['term']['trimester']; $terms[$number] = $payload;
            $sources[$number] = ['id'=>$archive->id,'trimester'=>$number,'content_hash'=>$archive->content_hash];
            $scales[] = $payload['scale'];
        }
        ksort($terms); ksort($sources);
        if (array_keys($terms) !== [1,2,3]) $this->fail('Clôturez les trois trimestres de cette classe avant de préparer le bulletin annuel.');
        if (count(array_unique($scales)) !== 1) $this->fail('Les trois trimestres doivent utiliser le même barème pour le bulletin annuel.');
        $pupils = [];
        foreach ($terms as $number => $payload) foreach ($payload['cards'] as $card) $pupils[$card['student']['id']][$number] = $card;
        $cards = [];
        foreach ($pupils as $id => $records) {
            $latest = $records[max(array_keys($records))]; $averages = []; $subjects = [];
            foreach ([1,2,3] as $number) {
                $averages[$number] = $records[$number]['average'] ?? null;
                foreach ($records[$number]['lines'] ?? [] as $line) $subjects[$line['subject_id']][$number] = $line;
            }
            $lines = [];
            foreach ($subjects as $subject => $entries) {
                $values = [];
                foreach ([1,2,3] as $number) {
                    $line = $entries[$number] ?? null;
                    $values[$number] = $line && !$line['qualitative'] && !$line['exempt'] ? $line['average'] : null;
                }
                $lines[] = ['subject_id'=>$subject,'name'=>$entries[max(array_keys($entries))]['name'],'terms'=>$values,
                    'average'=>in_array(null,$values,true) ? null : array_sum($values)/3];
            }
            usort($lines,fn($a,$b)=>strcmp($a['subject_id'],$b['subject_id']));
            $cards[] = ['student'=>$latest['student'],'lines'=>$lines,'term_averages'=>$averages,
                'average'=>in_array(null,$averages,true) ? null : array_sum($averages)/3];
        }
        usort($cards,fn($a,$b)=>strcmp($a['student']['last_name'].' '.$a['student']['first_name'].' '.$a['student']['id'],
            $b['student']['last_name'].' '.$b['student']['first_name'].' '.$b['student']['id']));
        return [...$context,'schema_version'=>1,'kind'=>'annual','scale'=>$scales[0],'sources'=>array_values($sources),
            'reference'=>'Moyenne annuelle = (T1 + T2 + T3) / 3, à partir des bulletins clôturés.','cards'=>$cards];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['bulletins'=>$message]);
    }

    private function complete(array $payload): void
    {
        if (!$payload['cards']) $this->fail('Aucun élève à valider dans ce lot.');
        foreach ($payload['cards'] as $card) {
            $name = $card['student']['last_name'].' '.$card['student']['first_name'];
            if ($payload['kind'] === 'annual') {
                if ($card['average'] === null) $this->fail($name.' : trois moyennes trimestrielles chiffrées sont nécessaires.');
            } else {
                if (!$card['lines']) $this->fail($name.' : aucune matière configurée.');
                foreach ($card['lines'] as $line) {
                    if ($line['qualitative'] ? trim($line['observation']) === '' : (!$line['exempt'] && $line['average'] === null))
                        $this->fail($name.' — '.$line['name'].' : complétez les notes, absences ou observations avant validation.');
                }
            }
        }
    }

    public function validate(string $kind, ?string $term, string $cohort, string $version): object
    {
        $this->lock($kind,$term,$cohort);
        $payload = $this->snapshot($kind,$term,$cohort); $hash = self::digest($payload);
        if (!hash_equals($hash,$version)) $this->fail('Les résultats ont changé depuis l’aperçu. Rechargez les bulletins avant de valider.');
        $this->complete($payload);
        $archive = $this->find($kind,$term,$cohort);
        if ($archive && $archive->status !== 'draft') {
            if (hash_equals($archive->content_hash,$hash)) return $archive;
            $this->fail('Ce lot est déjà validé. Revenez au brouillon avant une nouvelle validation.');
        }
        $values = ['status'=>'validated','payload'=>json_encode($payload,JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'content_hash'=>$hash,'validated_by'=>request()->user()->id,'validated_at'=>now()->toIso8601String()];
        if ($archive) {
            $this->school->query('report_card_archives')->where('id',$archive->id)->update($values);
            $archive = $this->school->row('report_card_archives',$archive->id);
        } else $archive = $this->school->insert('report_card_archives',[
            'kind'=>$kind,'term_id'=>$term,'cohort_id'=>$cohort,'period_id'=>$payload['class']['period_id'],
            'created_by'=>request()->user()->id,...$values]);
        $this->school->event('report_card.validated',$archive);
        return $archive;
    }

    public function close(string $kind, ?string $term, string $cohort, string $version, callable $render): object
    {
        $this->lock($kind,$term,$cohort);
        $archive = $this->find($kind,$term,$cohort);
        if (!$archive || $archive->status === 'draft') $this->fail('Validez les bulletins avant de clôturer.');
        if (!hash_equals($archive->content_hash,$version)) $this->fail('Cette validation a changé. Rechargez la page.');
        if ($archive->status === 'closed') return $archive;
        if (!hash_equals($version,self::digest($this->snapshot($kind,$term,$cohort))))
            $this->fail('Les données ont changé depuis la validation. Revenez au brouillon puis validez à nouveau.');
        $payload = json_decode($archive->payload,true,512,JSON_THROW_ON_ERROR);
        $closedAt = now()->toIso8601String();
        $rendered = $render($payload,$archive->id,$closedAt);
        $this->school->query('report_card_archives')->where('id',$archive->id)->update([
            'status'=>'closed','closed_by'=>request()->user()->id,'closed_at'=>$closedAt,'rendered'=>json_encode($rendered,JSON_THROW_ON_ERROR)]);
        $archive = $this->school->row('report_card_archives',$archive->id);
        $this->school->event('report_card.closed',$archive);
        return $archive;
    }

    public function draft(string $kind, ?string $term, string $cohort, string $version): void
    {
        $this->lock($kind,$term,$cohort);
        $archive = $this->find($kind,$term,$cohort);
        if (!$archive || $archive->status !== 'validated') $this->fail('Seul un lot validé et non clôturé peut revenir au brouillon.');
        if (!hash_equals($archive->content_hash,$version)) $this->fail('Cette validation a changé. Rechargez la page.');
        $this->school->query('report_card_archives')->where('id',$archive->id)->update([
            'status'=>'draft','payload'=>null,'content_hash'=>null,'validated_by'=>null,'validated_at'=>null]);
        $this->school->event('report_card.returned_to_draft',$archive);
    }
}
