<?php
return [
    'labels'=>[
        'teacher_qualifications'=>'Matières et niveaux des enseignants',
        'students'=>'Élèves','guardians'=>'Responsables légaux','student_guardians'=>'Liens élève / responsable',
        'programs'=>'Programmes','levels'=>'Niveaux','periods'=>'Périodes','offerings'=>'Offres de formation',
        'cohorts'=>'Groupes','teacher_assignments'=>'Affectations enseignants','subjects'=>'Matières','curriculum'=>'Programme pédagogique','campuses'=>'Campus','rooms'=>'Salles',
        'enrollments'=>'Inscriptions','invoices'=>'Factures','payments'=>'Paiements','refunds'=>'Remboursements',
        'invoice_balances'=>'Soldes des factures','sessions'=>'Séances','attendance'=>'Présences','grades'=>'Notes',
        'assessments'=>'Évaluations','staff'=>'Personnel et enseignants','contracts'=>'Contrats','work_logs'=>'Heures travaillées',
        'payroll_runs'=>'Rémunérations','expenses'=>'Dépenses','disbursements'=>'Décaissements','lesson_versions'=>'Leçons',
        'announcements'=>'Annonces','audit_events'=>'Journal d’audit',
    ],
    'relations'=>[
        'student_id'=>'students','guardian_id'=>'guardians','program_id'=>'programs','level_id'=>'levels',
        'period_id'=>'periods','offering_id'=>'offerings','cohort_id'=>'cohorts','subject_id'=>'subjects',
        'campus_id'=>'campuses','room_id'=>'rooms','teacher_id'=>'staff','staff_id'=>'staff','contract_id'=>'contracts',
        'enrollment_id'=>'enrollments','invoice_id'=>'invoices','payment_id'=>'payments','expense_id'=>'expenses',
        'session_id'=>'sessions','assessment_id'=>'assessments',
    ],
    'operations'=>[
        'enrollments'=>['permission'=>'enrollments.write','path'=>'enrollments','rules'=>[
            'student_id'=>'required|uuid','offering_id'=>'required|uuid','cohort_id'=>'required|uuid','due_on'=>'required|date_format:Y-m-d']],
        'payments'=>['permission'=>'finance.write','path'=>'payments','rules'=>[
            'invoice_id'=>'required|uuid','amount_minor'=>'required|integer|min:1','method'=>'required|in:cash,bank,ccp,card','reference'=>'nullable|string']],
        'refunds'=>['permission'=>'finance.write','path'=>'refunds','rules'=>[
            'payment_id'=>'required|uuid','amount_minor'=>'required|integer|min:1','reason'=>'required|string|min:3']],
        'disbursements'=>['permission'=>'finance.write','path'=>'disbursements','rules'=>[
            'expense_id'=>'required|uuid','amount_minor'=>'required|integer|min:1','paid_on'=>'required|date_format:Y-m-d',
            'method'=>'required|in:cash,bank,ccp,card','reference'=>'nullable|string|max:200']],
        'attendance'=>['permission'=>'pedagogy.write','path'=>'attendance','method'=>'PUT','rules'=>[
            'session_id'=>'required|uuid','enrollment_id'=>'required|uuid','status'=>'required|in:present,absent,late,excused','reason'=>'nullable|string|min:3']],
        'grades'=>['permission'=>'pedagogy.write','path'=>'grades','method'=>'PUT','rules'=>[
            'assessment_id'=>'required|uuid','enrollment_id'=>'required|uuid','status'=>'required|in:marked,absent,exempt,missing','score'=>'nullable|numeric|min:0','reason'=>'nullable|string|min:3']],
        'payroll_runs'=>['permission'=>'payroll.write','path'=>'payroll','rules'=>[
            'staff_id'=>'required|uuid','month'=>'required|date_format:Y-m-d']],
    ],
];
