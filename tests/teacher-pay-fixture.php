<?php
// The reused teacher fixture checks the disposable project before any write.
if (($argv[1] ?? '') !== 'setup') { require __DIR__.'/teacher-portal-fixture.php'; exit; }
ob_start(); require __DIR__.'/teacher-portal-fixture.php'; $data = json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$admin = App\Models\User::create(['name'=>'Payroll administrator','email'=>'pay-admin@example.test','password'=>'Integration-pay-123456']);
$permissions = ['hr.read','hr.write','payroll.read','payroll.write','finance.read','finance.write','catalog.read','catalog.write'];
Illuminate\Support\Facades\DB::table('school.memberships')->insert(['tenant_id'=>$data['tenant'],'user_id'=>$admin->id,'permissions'=>json_encode($permissions)]);
$data['admin'] = ['email'=>$admin->email,'password'=>'Integration-pay-123456'];
foreach ($data['teachers'] as $index => &$teacher) $teacher['staff'] = Illuminate\Support\Facades\DB::table('school.staff')->where('code','TEACHER-'.($index+1))->value('id');
echo json_encode($data);
