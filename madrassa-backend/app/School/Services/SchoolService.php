<?php
namespace App\School\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SchoolService
{
    public function authorize(string $permission): void
    {
        abort_unless(in_array($permission, request()->attributes->get('school.permissions', []), true), 403);
    }

    public function tenant(): string { return request()->attributes->get('school.tenant'); }

    public function query(string $table): \Illuminate\Database\Query\Builder
    {
        return DB::table('school.'.$table)->where('tenant_id', $this->tenant());
    }

    public function row(string $table, string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        return $this->query($table)->where('id', $id)->firstOrFail();
    }

    public function insert(string $table, array $data): object
    {
        $id = (string) Str::uuid();
        DB::table('school.'.$table)->insert([...$data, 'id' => $id, 'tenant_id' => $this->tenant()]);
        return $this->row($table, $id);
    }

    public function event(string $topic, object $row): void
    {
        // No network side effect in the request transaction.
        $this->insert('outbox', ['topic' => $topic, 'payload' => json_encode(['id' => $row->id], JSON_THROW_ON_ERROR)]);
    }

    private function replay(string $table, array $data): ?object
    {
        $key = $data['idempotency_key'];
        // Serialize duplicate requests before looking up their result.
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 1))', [$this->tenant().':'.$table.':'.$key]);
        $old = $this->query($table)->where('idempotency_key', $key)->first();
        if ($old) {
            foreach ($data as $field => $value) {
                if ((string) ($old->$field ?? '') !== (string) $value) {
                    throw new ConflictHttpException('Clé de répétition déjà utilisée avec un contenu différent.');
                }
            }
        }
        return $old;
    }

    public function enroll(array $data): object
    {
        $this->authorize('enrollments.write');
        // Use the same lock as the enrollment trigger before checking duplicates/capacity.
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))',[$this->tenant()]);
        // due_on participates in replay through the associated invoice.
        $due = $data['due_on']; unset($data['due_on']);
        if ($old = $this->replay('enrollments', $data)) {
            $invoice = $this->query('invoices')->where('enrollment_id', $old->id)->firstOrFail();
            if ($invoice->due_on !== $due) throw new ConflictHttpException('Échéance différente pour la même clé.');
            return $old;
        }
        $offering = $this->row('offerings', $data['offering_id']);
        if ($this->query('enrollments')->where('student_id',$data['student_id'])->where('offering_id',$data['offering_id'])->where('status','<>','cancelled')->exists())
            throw new ConflictHttpException('Cet élève possède déjà une inscription pour cette offre et cette année.');
        $row = $this->insert('enrollments', [...$data, 'price_minor' => $offering->price_minor]);
        $this->insert('invoices', ['enrollment_id' => $row->id, 'number' => 'INV-'.$row->id,
            'amount_minor' => $row->price_minor, 'due_on' => $due]);
        $this->event('enrollment.created', $row);
        return $row;
    }

    public function financial(string $table, array $data): object
    {
        $this->authorize('finance.write');
        if ($old = $this->replay($table, $data)) return $old;
        $row = $this->insert($table, $data);
        $this->event($table.'.created', $row);
        return $row;
    }

    public function recordResult(string $table, array $data): object
    {
        $this->authorize('pedagogy.write');
        $foreign = $table === 'attendance' ? 'session_id' : 'assessment_id';
        $existing = $this->query($table)->where($foreign, $data[$foreign])
            ->where('enrollment_id', $data['enrollment_id'])->lockForUpdate()->first();
        if (!$existing) return $this->insert($table, $data);
        $this->query($table)->where('id', $existing->id)->update($data);
        return $this->row($table, $existing->id);
    }

    public function payroll(array $data): object
    {
        $this->authorize('payroll.write');
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 2))', [$this->tenant().':'.$data['staff_id'].':'.$data['month']]);
        $old = $this->query('payroll_runs')->where('staff_id', $data['staff_id'])->where('month', $data['month'])->first();
        if ($old) return $old;
        $start = \Carbon\CarbonImmutable::parse($data['month']);
        $end = $start->endOfMonth()->toDateString();
        $contracts = $this->query('contracts')->where('staff_id', $data['staff_id'])
            ->where('starts_on', '<=', $end)->where(fn($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $data['month']))->get();
        // Explicitly reject mid-month changes instead of inventing a proration rule.
        abort_unless($contracts->count() === 1, 422, 'Un contrat unique doit couvrir le mois.');
        $c = $contracts->first();
        abort_unless($c->starts_on <= $data['month'] && (!$c->ends_on || $c->ends_on >= $end), 422, 'Proratisation à traiter avant validation.');
        $pending = $this->query('work_logs')->where('contract_id',$c->id)->whereBetween('worked_on',[$data['month'],$end])
            ->whereNull('validated_at')->whereNull('rejected_at')->exists();
        abort_if($pending,422,'Validez ou refusez toutes les heures déclarées avant de calculer la rémunération du mois.');
        $minutes = (int) $this->query('work_logs')->where('contract_id', $c->id)->whereNotNull('validated_at')
            ->whereBetween('worked_on', [$data['month'], $end])->sum('minutes');
        abort_if($c->basis === 'hourly' && $minutes === 0,422,'Aucune heure validée pour cette rémunération horaire.');
        $amount = $c->basis === 'monthly' ? (int) $c->rate_minor : intdiv((int) $c->rate_minor * $minutes + 30, 60);
        $row = $this->insert('payroll_runs', [...$data, 'amount_minor' => $amount, 'validated_at' => now(),
            'calculation' => json_encode(['contract_id' => $c->id, 'basis' => $c->basis, 'rate_minor' => $c->rate_minor, 'minutes' => $minutes, 'rounding' => 'half_up_centime'], JSON_THROW_ON_ERROR)]);
        if ($amount > 0) $this->insert('expenses', ['label' => 'Rémunération '.$data['month'], 'amount_minor' => $amount, 'incurred_on' => $end, 'payroll_id' => $row->id]);
        $this->event('payroll.validated', $row);
        return $row;
    }
}
