<?php
require '/var/www/html/app/School/Services/ReportCardCalculator.php';
use App\School\Services\ReportCardCalculator as C;
function check($actual,$expected,$label) {
    if (is_float($expected) ? abs($actual-$expected)>0.000001 : $actual !== $expected) throw new RuntimeException($label.': '.var_export($actual,true));
}
$rule = ['continu'=>1,'devoir'=>1,'examen'=>4];
$grade = fn($kind,$score,$max=20,$coefficient=1,$status='marked')=>compact('kind','score','max','coefficient','status')+['max_score'=>$max];
$marks = [$grade('continu',14),$grade('devoir',16),$grade('examen',18)];
check(C::subject($rule,$marks,20)['average'],17.0,'Weighted components');
check(C::subject($rule,$marks,10)['average'],8.5,'Primary scale');
check(C::subject($rule,[$grade('continu',0),$grade('devoir',0),$grade('examen',0)],20)['average'],0.0,'Zero is graded');
check(C::subject($rule,[$grade('continu',14),$grade('devoir',null,20,1,'absent'),$grade('examen',18)],20)['average'],null,'Absence blocks average');
check(C::subject($rule,[$grade('continu',14),$grade('devoir',16)],20)['average'],null,'Missing exam blocks average');
check(C::subject($rule,[...$marks,$grade(null,20)],20)['average'],null,'Untyped evaluation blocks average');
$devoirOnly = ['continu'=>0,'devoir'=>1,'examen'=>0];
check(C::subject($devoirOnly,[$grade('devoir',10,10,1),$grade('devoir',10,20,3)],20)['average'],12.5,'Assessment normalization and coefficients');
$exempt = C::subject($devoirOnly,[$grade('devoir',null,20,1,'exempt')],20);
check($exempt['exempt'],true,'Exemption retained');
check(C::overall([['average'=>15.0,'coefficient'=>2,'qualitative'=>false,'exempt'=>false],['average'=>12.0,'coefficient'=>1,'qualitative'=>false,'exempt'=>false]]),14.0,'Subject coefficients');
check(C::overall([['average'=>null,'coefficient'=>1,'qualitative'=>false,'exempt'=>false]]),null,'Incomplete overall');
check(C::overall([['average'=>null,'coefficient'=>1,'qualitative'=>true,'exempt'=>false]]),null,'Qualitative only');
echo "PASS report card calculation: weights, scale, normalization, zero, absence, missing marks, untyped evaluations, exemptions, overall.\n";
