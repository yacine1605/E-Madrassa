<?php
// Subject membership for 2026/2027; sources: docs/algerian-curriculum.md.
// Subject durations supplied by the user; private-school supplements kept separate.
$weekly = json_decode(file_get_contents(__DIR__.'/primary_weekly_minutes.json'), true, 512, JSON_THROW_ON_ERROR);
$primaryPlans = [];
foreach ($weekly['primary'] as $grade => $minutes) {
    $minutes += $weekly['private_supplements'][$grade];
    $primaryPlans[$grade] = array_keys($minutes);
}
$preschool = $weekly['preschool'];
if (in_array('PRESCOLAIRE', $weekly['language_support']['cycles'], true)) unset($preschool['PRE-ATELIER']);
$middle = ['MOY-AR','MOY-MATH','MOY-FR','MOY-EN','MOY-ISLAM','MOY-CIV','MOY-SVT','MOY-PHYS','MOY-HG','MOY-SPORT','MOY-ART','MOY-MUSIC','MOY-IT','MOY-AMAZ'];
$secondary = ['SEC-AR','SEC-MATH','SEC-FR','SEC-EN','SEC-ISLAM','SEC-SVT','SEC-PHYS','SEC-HG','SEC-IT','SEC-SPORT','SEC-ART','SEC-AMAZ'];
return [
    'weekly_reference' => $weekly,
    'subjects' => [
        'PRE-AR'=>'اللغة العربية',
        'PRE-MATH'=>'التهيئة للرياضيات', 'PRE-ART'=>'الفنون والمهارات الحركية الدقيقة',
        'PRE-EVEIL'=>'اكتشاف العالم والألعاب التربوية', 'PRE-REPOS'=>'الراحة والأنشطة الهادئة',
        'PRE-ATELIER'=>'ورشات وأنشطة موجهة',
        'PRI-ES'=>'اللغة الإسبانية', 'PRI-ATELIER'=>'ورشات الدعم والأنشطة الموجهة',
        'PRI-AR-LECT'=>'اللغة العربية', 'PRI-MATH'=>'الرياضيات', 'PRI-ISLAM'=>'التربية الإسلامية',
        'PRI-ART'=>'التربية الفنية', 'PRI-SPORT'=>'التربية البدنية والرياضية',
        'PRI-FR'=>'اللغة الفرنسية', 'PRI-EN'=>'اللغة الإنجليزية', 'PRI-CIV'=>'التربية المدنية',
        'PRI-HG'=>'التاريخ والجغرافيا', 'PRI-HIST'=>'التاريخ', 'PRI-SCI-TECH'=>'التربية العلمية والتكنولوجية', 'PRI-AMAZ'=>'اللغة الأمازيغية',
        'MOY-AR'=>'اللغة العربية', 'MOY-MATH'=>'الرياضيات', 'MOY-FR'=>'اللغة الفرنسية', 'MOY-EN'=>'اللغة الإنجليزية',
        'MOY-ISLAM'=>'التربية الإسلامية', 'MOY-CIV'=>'التربية المدنية', 'MOY-SVT'=>'علوم الطبيعة والحياة',
        'MOY-PHYS'=>'العلوم الفيزيائية والتكنولوجيا', 'MOY-HG'=>'التاريخ والجغرافيا',
        'MOY-SPORT'=>'التربية البدنية والرياضية', 'MOY-ART'=>'التربية الفنية التشكيلية',
        'MOY-MUSIC'=>'التربية الموسيقية', 'MOY-IT'=>'الإعلام الآلي', 'MOY-AMAZ'=>'اللغة الأمازيغية',
        'MOY-SCI-TECH'=>'التربية العلمية والتكنولوجية', // Legacy label, excluded from middle school plans.
        'SEC-AR'=>'اللغة العربية وآدابها', 'SEC-MATH'=>'الرياضيات', 'SEC-FR'=>'اللغة الفرنسية', 'SEC-EN'=>'اللغة الإنجليزية',
        'SEC-ISLAM'=>'العلوم الإسلامية', 'SEC-SVT'=>'علوم الطبيعة والحياة', 'SEC-PHYS'=>'العلوم الفيزيائية',
        'SEC-HG'=>'التاريخ والجغرافيا', 'SEC-IT'=>'الإعلام الآلي', 'SEC-TECH'=>'التكنولوجيا',
        'SEC-SPORT'=>'التربية البدنية والرياضية', 'SEC-ART'=>'التربية الفنية', 'SEC-AMAZ'=>'اللغة الأمازيغية',
    ],
    'plans' => [
        'PRESCOLAIRE'=>['1'=>array_keys($preschool)],
        'PRIMAIRE'=>$primaryPlans,
        'MOYEN'=>['1'=>$middle,'2'=>$middle,'3'=>$middle,'4'=>$middle],
        'SECONDAIRE'=>['1AS-ST'=>[...$secondary,'SEC-TECH'],'1AS-L'=>$secondary],
    ],
    'tracks'=>[
        '1AS-ST'=>['name'=>'الأولى ثانوي — جذع مشترك علوم وتكنولوجيا','legacy_class'=>'Secondaire — 1re année — Classe A'],
        '1AS-L'=>['name'=>'الأولى ثانوي — جذع مشترك آداب','legacy_class'=>'Secondaire — 1re année — Classe B'],
    ],
];
