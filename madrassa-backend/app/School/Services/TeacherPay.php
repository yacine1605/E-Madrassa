<?php
namespace App\School\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Controllers authorize the staff member before calling this scoped ledger. */
final class TeacherPay
{
    public static function query(string $tenant, string $table)
    {
        return DB::table('school.'.$table)->where('tenant_id',$tenant);
    }
    public static function summary(string $tenant, string $staff, string $month): array
    {
        $end = CarbonImmutable::parse($month)->endOfMonth()->toDateString();
        $contracts = self::query($tenant,'contracts')->where('staff_id',$staff)->orderByDesc('starts_on')->get();
        $logs = self::query($tenant,'work_logs')->whereIn('contract_id',$contracts->pluck('id'))
            ->whereBetween('worked_on',[$month,$end])->orderByDesc('worked_on')->get();
        $run = self::query($tenant,'payroll_runs')->where('staff_id',$staff)->where('month',$month)->first();
        $expense = $run ? self::query($tenant,'expenses')->where('payroll_id',$run->id)->first() : null;
        $payments = $expense ? self::query($tenant,'disbursements')->where('expense_id',$expense->id)->orderByDesc('paid_on')->orderBy('id')->get() : collect();
        $paid = (int)$payments->sum('amount_minor');
        $validated = (int)$logs->whereNotNull('validated_at')->sum('minutes');
        $pending = (int)$logs->whereNull('validated_at')->whereNull('rejected_at')->sum('minutes');
        $covering = $contracts->filter(fn($c)=>$c->starts_on <= $month && (!$c->ends_on || $c->ends_on >= $end));
        $contract = $covering->count() === 1 ? $covering->first() : null;
        $estimate = $contract ? ($contract->basis === 'monthly' ? (int)$contract->rate_minor : intdiv((int)$contract->rate_minor*$validated+30,60)) : null;
        return compact('contracts','logs','run','expense','payments','paid','validated','pending','contract','estimate')+
            ['balance'=>$run ? (int)$run->amount_minor-$paid : null,'calculation'=>$run ? json_decode($run->calculation,true) : null];
    }
    public static function duration(int $minutes): string { return intdiv($minutes,60).' h '.str_pad((string)($minutes%60),2,'0',STR_PAD_LEFT).' min'; }
    public static function money(?int $minor): string { return $minor === null ? '—' : number_format($minor/100,2,',',' ').' DA'; }
    public static function version(object $log): string
    {
        return hash_hmac('sha256',json_encode([$log->id,$log->minutes,$log->validated_at,$log->rejected_at,$log->review_reason]),config('app.key'));
    }
    public static function declare(string $tenant, string $staff, array $data): object
    {
        $contract = self::query($tenant,'contracts')->where('staff_id',$staff)->where('id',$data['contract_id'])->first();
        abort_unless($contract,404);
        if ($data['worked_on'] < $contract->starts_on || ($contract->ends_on && $data['worked_on'] > $contract->ends_on))
            throw ValidationException::withMessages(['worked_on'=>'Cette date est en dehors du contrat.']);
        $month = substr($data['worked_on'],0,7).'-01';
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 2))',[$tenant.':'.$staff.':'.$month]);
        if (self::query($tenant,'payroll_runs')->where('staff_id',$staff)->where('month',$month)->exists())
            throw ValidationException::withMessages(['worked_on'=>'La rémunération de ce mois est déjà calculée ; les heures sont figées.']);
        $old = self::query($tenant,'work_logs')->where('contract_id',$contract->id)->where('worked_on',$data['worked_on'])->lockForUpdate()->first();
        if ($old) {
            if ($old->validated_at) throw ValidationException::withMessages(['worked_on'=>'Ces heures ont déjà été validées.']);
            if (!isset($data['version']) || !hash_equals(self::version($old),$data['version']))
                throw ValidationException::withMessages(['worked_on'=>'Une déclaration existe ou a changé. Rechargez la page et utilisez Modifier.']);
            self::query($tenant,'work_logs')->where('id',$old->id)->update(['minutes'=>$data['minutes'],'rejected_at'=>null,'review_reason'=>null]);
            $id = $old->id;
        } else {
            if (!empty($data['version'])) throw ValidationException::withMessages(['worked_on'=>'Déclaration introuvable. Rechargez la page.']);
            $id = (string)Str::uuid();
            DB::table('school.work_logs')->insert(['id'=>$id,'tenant_id'=>$tenant,'contract_id'=>$contract->id,'worked_on'=>$data['worked_on'],'minutes'=>$data['minutes']]);
        }
        return self::query($tenant,'work_logs')->where('id',$id)->first();
    }
}
