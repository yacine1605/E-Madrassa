<?php
namespace Tests;
use App\Models\User;
use App\Support\DemoStore as D;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
class FrontendTest extends TestCase {
 use RefreshDatabase;
 public function test_all_pages_render():void {
  $this->actingAs(User::create(['name'=>'Recette','email'=>'qa@example.test','password'=>'test-password-long']));
  foreach(array_keys(D::modules()) as $m)$this->get('/admin/'.$m)->assertOk()->assertSee('Madrassa');
 }
 public function test_validated_payment_is_immutable():void {$r=D::find('payments','1');$r['amount']=1;$this->expectException(ValidationException::class);D::save('payments',$r,'1');}
 public function test_teacher_collision_is_rejected():void {$r=D::find('schedule','1');unset($r['id']);$r['group']='2';$r['room']='2';$this->expectException(ValidationException::class);D::save('schedule',$r);}
 public function test_partial_payment_does_not_reactivate_enrollment():void {
  $r=D::find('enrollments','2');$r['status']='Suspendue';D::save('enrollments',$r,'2');$before=D::balance($r);
  D::save('payments',['enrollment'=>'2','amount'=>10000,'date'=>'2026-09-25','method'=>'Espèces','reference'=>'TEST','status'=>'Validé']);
  $this->assertSame($before-10000,D::balance($r));$this->assertSame('Suspendue',D::find('enrollments','2')['status']);
 }
 public function test_missing_and_zero_grade_are_distinct():void {$r=D::find('grades','4');$this->assertNull($r['grade']);$r['grade']=0;D::save('grades',$r,'4');$this->assertSame(0,D::find('grades','4')['grade']);}
 public function test_cycle_level_agreement():void {$r=D::find('students','1');$r['level']='3AS';$this->expectException(ValidationException::class);D::save('students',$r,'1');}
 public function test_native_action_creates_student():void {
  $u=User::create(['name'=>'Recette','email'=>'actions@example.test','password'=>'test-password-long']);
  \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
  \Livewire\Livewire::actingAs($u)->test(\App\Filament\Pages\Students::class)->mountAction('edit')
   ->set('mountedActions.0.data',['name'=>'Élève Recette','name_ar'=>'','cycle'=>'Primaire','level'=>'1AP','birth'=>'2020-05-01','guardian'=>'Responsable Recette','phone'=>'0550000000','address'=>'Batna','status'=>'Actif'])->callMountedAction()->assertHasNoActionErrors();
  $this->assertSame('Élève Recette',collect(D::all('students'))->last()['name']);
 }
}
