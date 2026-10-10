<?php
namespace App\Http\Controllers;

use App\School\Services\SchoolService;
use App\School\Services\TeacherPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffPayrollController
{
    public function __construct(private SchoolService $school) {}
    private function access(): void
    {
        foreach(['hr.read','payroll.read','finance.read'] as $permission) $this->school->authorize($permission);
    }
    private function teacher(string $id): object
    {
        $this->access(); $staff = $this->school->row('staff',$id);
        abort_unless($staff->is_teacher,404); return $staff;
    }
    public function index(Request $request)
    {
        $this->access();
        $month = TeacherPayController::month($request);
        $data = $request->validate(['staff'=>'nullable|uuid']);
        $teachers = $this->school->query('staff')->where('is_teacher',true)->orderBy('name')->get();
        $staff = isset($data['staff']) ? $teachers->firstWhere('id',$data['staff']) : $teachers->first();
        if(isset($data['staff'])) abort_unless($staff,404);
        $ledger = $staff ? TeacherPay::summary($this->school->tenant(),$staff->id,$month.'-01') : null;
        $schoolName = DB::table('school.institutions')->where('id',$this->school->tenant())->value('name');
        $key = (string)Str::uuid();
        return response()->view('payroll.index',compact('teachers','staff','ledger','month','schoolName','key'))->header('Cache-Control','private, no-store');
    }
    private function back(string $staff, string $month)
    {
        return redirect()->route('staff-pay.index',['tenant'=>$this->school->tenant(),'staff'=>$staff,'month'=>$month]);
    }
    public static function minor(string $value): int
    {
        [$whole,$decimal] = array_pad(explode('.',$value,2),2,'');
        return (int)$whole*100+(int)str_pad($decimal,2,'0');
    }
    public function contract(Request $request)
    {
        // The contract carries the pay rate, which only payroll may set (FR-STF-04).
        $this->school->authorize('hr.write'); $this->school->authorize('payroll.write');
        $data = $request->validate(['staff_id'=>'required|uuid','starts_on'=>'required|date_format:Y-m-d',
            'ends_on'=>'nullable|date_format:Y-m-d|after_or_equal:starts_on','basis'=>'required|in:monthly,hourly',
            'rate'=>'required|numeric|between:0.01,10000000|regex:/^\d{1,8}(\.\d{1,2})?$/']);
        $this->teacher($data['staff_id']);
        $rate = self::minor((string)$data['rate']); unset($data['rate']);
        $contract = $this->school->insert('contracts',[...$data,'rate_minor'=>$rate]);
        $this->school->event('contract.created',$contract);
        return $this->back($data['staff_id'],substr($data['starts_on'],0,7))->with('success','Contrat de rémunération enregistré.');
    }
    public function declare(Request $request)
    {
        $this->school->authorize('hr.write');
        $data = $request->validate(TeacherPayController::hourRules()+['staff_id'=>'required|uuid']);
        $staff = $this->teacher($data['staff_id']);
        $row = TeacherPay::declare($this->school->tenant(),$staff->id,$data);
        $this->school->event('work_log.declared',$row);
        return $this->back($staff->id,substr($data['worked_on'],0,7))->with('success','Heures enregistrées, en attente de validation.');
    }
    public function review(Request $request, string $tenant, string $log)
    {
        $this->access(); $this->school->authorize('payroll.write');
        $data = $request->validate(['decision'=>'required|in:validate,reject','reason'=>'required|string|min:3|max:1000','version'=>'required|string|size:64']);
        $row = $this->school->row('work_logs',$log);
        $contract = $this->school->row('contracts',$row->contract_id); $this->teacher($contract->staff_id);
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 2))',[$tenant.':'.$contract->staff_id.':'.substr($row->worked_on,0,7).'-01']);
        $row = $this->school->query('work_logs')->where('id',$row->id)->lockForUpdate()->first();
        if (!hash_equals(TeacherPay::version($row),$data['version']) || $row->validated_at || $row->rejected_at)
            throw ValidationException::withMessages(['version'=>'Cette déclaration a déjà été examinée ou modifiée. Rechargez la page.']);
        if ($this->school->query('payroll_runs')->where('staff_id',$contract->staff_id)->where('month',substr($row->worked_on,0,7).'-01')->exists())
            throw ValidationException::withMessages(['decision'=>'La rémunération de ce mois est déjà calculée.']);
        // Segregation of duties (BR-08); the database enforces it as well.
        if ($data['decision']==='validate' && (string)$row->entered_by === (string)$request->user()->id)
            throw ValidationException::withMessages(['decision'=>'Vous avez saisi ces heures : une autre personne doit les valider.']);
        $this->school->query('work_logs')->where('id',$row->id)->update([
            'validated_at'=>$data['decision']==='validate' ? now() : null,
            'rejected_at'=>$data['decision']==='reject' ? now() : null,'review_reason'=>$data['reason'],
            'reviewed_by'=>$request->user()->id]);
        $this->school->event('work_log.'.$data['decision'],$this->school->row('work_logs',$row->id));
        return $this->back($contract->staff_id,substr($row->worked_on,0,7))->with('success',$data['decision']==='validate' ? 'Heures validées.' : 'Heures refusées. L’enseignant peut corriger sa déclaration.');
    }
    public function calculate(Request $request)
    {
        $data = $request->validate(['staff_id'=>'required|uuid','month'=>'required|date_format:Y-m']);
        $this->teacher($data['staff_id']);
        try { $this->school->payroll(['staff_id'=>$data['staff_id'],'month'=>$data['month'].'-01']); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if($e->getStatusCode()!==422) throw $e;
            throw ValidationException::withMessages(['month'=>$e->getMessage()]);
        }
        return $this->back($data['staff_id'],$data['month'])->with('success','Rémunération calculée en brouillon. Les heures du mois sont figées ; une autre personne doit valider le calcul avant tout versement.');
    }
    public function validateRun(Request $request, string $tenant, string $run)
    {
        $row = $this->school->row('payroll_runs',$run); $this->teacher($row->staff_id);
        $this->decide(fn() => $this->school->validatePayroll($row->id));
        return $this->back($row->staff_id,substr($row->month,0,7))->with('success','Rémunération validée. Le versement peut être enregistré.');
    }
    public function discardRun(Request $request, string $tenant, string $run)
    {
        $row = $this->school->row('payroll_runs',$run); $this->teacher($row->staff_id);
        $this->decide(fn() => $this->school->discardPayroll($row->id));
        return $this->back($row->staff_id,substr($row->month,0,7))->with('success','Brouillon supprimé. Les heures du mois peuvent être corrigées puis recalculées.');
    }
    /** Shows a refused decision on the form instead of an error page. */
    private function decide(\Closure $action): void
    {
        try { $action(); }
        catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            if($e->getStatusCode()!==409) throw $e;
            throw ValidationException::withMessages(['run'=>$e->getMessage()]);
        }
    }
    public function pay(Request $request, string $tenant, string $run)
    {
        $this->school->authorize('finance.write');
        $data = $request->validate(['amount'=>'required|numeric|between:0.01,1000000000|regex:/^\d{1,10}(\.\d{1,2})?$/',
            'paid_on'=>'required|date_format:Y-m-d|before_or_equal:today','method'=>'required|in:cash,bank,ccp,card',
            'reference'=>'nullable|string|max:200','idempotency_key'=>'required|uuid']);
        $run = $this->school->row('payroll_runs',$run); $this->teacher($run->staff_id);
        $expense = $this->school->query('expenses')->where('payroll_id',$run->id)->first();
        abort_unless($expense,422,'Aucun montant à verser pour cette rémunération.');
        $amount = self::minor((string)$data['amount']); unset($data['amount']);
        $this->school->financial('disbursements',[...$data,'expense_id'=>$expense->id,'amount_minor'=>$amount]);
        return $this->back($run->staff_id,substr($run->month,0,7))->with('success','Versement enregistré. Le solde a été mis à jour.');
    }
    public function receipt(Request $request, string $tenant, string $payment)
    {
        $this->access(); $payment = $this->school->row('disbursements',$payment);
        $expense = $this->school->row('expenses',$payment->expense_id);
        abort_unless($expense->payroll_id,404);
        $run = $this->school->row('payroll_runs',$expense->payroll_id); $staff = $this->teacher($run->staff_id);
        $schoolName = DB::table('school.institutions')->where('id',$tenant)->value('name');
        return response()->view('payroll.receipt',compact('payment','run','staff','schoolName'))->header('Cache-Control','private, no-store');
    }
}
