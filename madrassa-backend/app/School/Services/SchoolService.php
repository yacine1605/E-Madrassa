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
        // A draft: another person validates it before the salary expense exists (BR-08).
        $row = $this->insert('payroll_runs', [...$data, 'amount_minor' => $amount,
            'calculation' => json_encode(['contract_id' => $c->id, 'basis' => $c->basis, 'rate_minor' => $c->rate_minor, 'minutes' => $minutes, 'rounding' => 'half_up_centime'], JSON_THROW_ON_ERROR)]);
        $this->event('payroll.drafted', $row);
        return $row;
    }

    private function lockPayroll(string $id): object
    {
        $this->authorize('payroll.write');
        $this->row('payroll_runs', $id);
        return $this->query('payroll_runs')->where('id', $id)->lockForUpdate()->first();
    }

    public function validatePayroll(string $id): object
    {
        $run = $this->lockPayroll($id);
        if ($run->status === 'validated') return $run;
        abort_if((string) $run->created_by === (string) request()->user()->id, 409,
            'Séparation des tâches : une autre personne que l’auteur du calcul doit valider cette rémunération.');
        $this->query('payroll_runs')->where('id', $id)->update(['status' => 'validated', 'validated_at' => now(), 'validated_by' => request()->user()->id]);
        if ($run->amount_minor > 0) $this->insert('expenses', ['label' => 'Rémunération '.$run->month, 'amount_minor' => $run->amount_minor,
            'incurred_on' => \Carbon\CarbonImmutable::parse($run->month)->endOfMonth()->toDateString(), 'payroll_id' => $run->id]);
        $row = $this->row('payroll_runs', $id);
        $this->event('payroll.validated', $row);
        return $row;
    }

    public function discardPayroll(string $id): void
    {
        $run = $this->lockPayroll($id);
        abort_if($run->status !== 'draft', 409, 'Une rémunération validée est définitive.');
        // Removing the draft unfreezes the month's hours for correction and a new calculation.
        $this->query('payroll_runs')->where('id', $id)->delete();
        $this->insert('outbox', ['topic' => 'payroll.discarded', 'payload' => json_encode(['id' => $id], JSON_THROW_ON_ERROR)]);
    }

    public function reversePayment(string $id, string $reason): object
    {
        $this->authorize('finance.write');
        $this->row('payments', $id);
        // One reversal per payment: a repeated request returns the existing one.
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 5))', [$this->tenant().':'.$id]);
        if ($old = $this->query('payment_reversals')->where('payment_id', $id)->first()) return $old;
        $row = $this->insert('payment_reversals', ['payment_id' => $id, 'reason' => $reason]);
        $this->event('payment.reversed', $row);
        return $row;
    }
}
