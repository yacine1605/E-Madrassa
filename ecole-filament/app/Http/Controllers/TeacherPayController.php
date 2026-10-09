<?php
namespace App\Http\Controllers;

use App\School\Services\TeacherPay;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherPayController
{
    public static function month(Request $request): string
    {
        return $request->validate(['month'=>'nullable|date_format:Y-m|regex:/^\d{4}-\d{2}$/'])['month'] ?? now('Africa/Algiers')->format('Y-m');
    }
    public function index(Request $request)
    {
        $teacher = $request->attributes->get('teacher');
        $month = self::month($request);
        $ledger = TeacherPay::summary($teacher->tenant_id,$teacher->id,$month.'-01');
        return response()->view('teacher.pay',compact('ledger','month'))->header('Cache-Control','private, no-store');
    }
    public static function hourRules(): array
    {
        return ['contract_id'=>'required|uuid','worked_on'=>'required|date_format:Y-m-d|before_or_equal:today',
            'minutes'=>'required|integer|between:1,1440','version'=>'nullable|string|size:64'];
    }
    public function declare(Request $request)
    {
        $teacher = $request->attributes->get('teacher');
        $data = $request->validate(self::hourRules());
        TeacherPay::declare($teacher->tenant_id,$teacher->id,$data);
        return redirect()->route('teacher.pay',['month'=>substr($data['worked_on'],0,7)])->with('success','Heures déclarées. En attente de validation par la direction.');
    }
    public function receipt(Request $request, string $payment)
    {
        $teacher = $request->attributes->get('teacher');
        abort_unless(\Illuminate\Support\Str::isUuid($payment),404);
        $payment = TeacherPay::query($teacher->tenant_id,'disbursements')->where('id',$payment)->first();
        abort_unless($payment,404);
        $expense = TeacherPay::query($teacher->tenant_id,'expenses')->where('id',$payment->expense_id)->first();
        $run = $expense?->payroll_id ? TeacherPay::query($teacher->tenant_id,'payroll_runs')->where('id',$expense->payroll_id)->where('staff_id',$teacher->id)->first() : null;
        abort_unless($run,404);
        $schoolName = DB::table('school.institutions')->where('id',$teacher->tenant_id)->value('name');
        return response()->view('payroll.receipt',['payment'=>$payment,'run'=>$run,'staff'=>$teacher,'schoolName'=>$schoolName])->header('Cache-Control','private, no-store');
    }
}
