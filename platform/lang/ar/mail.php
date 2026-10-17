<?php

// Emails: the account's links (FR-IAM-01, FR-IAM-02) and notifications (FR-NOT-01).
return [
    'greeting' => 'مرحبًا :name،',
    'activation' => [
        'subject' => 'فعّل حسابك',
        'subject_school' => 'فعّل حسابك — :school',
        'opened' => 'فُتح لك حساب.',
        'opened_school' => 'فتحت لك مؤسسة :school حسابًا.',
        'action' => 'لتفعيله، افتح هذا الرابط خلال 72 ساعة:',
    ],
    'recovery' => [
        'subject' => 'إعادة تعيين كلمة المرور',
        'asked' => 'طُلبت إعادة تعيين كلمة مرورك.',
        'action' => 'لاختيار كلمة مرور جديدة، افتح هذا الرابط خلال ساعة:',
    ],
    'once' => 'هذا الرابط صالح لمرة واحدة. إن لم تكن صاحب هذا الطلب، فتجاهل هذه الرسالة.',
    'notification' => [
        'subject' => ':title — :school',
        'line' => ':title (:school).',
        'details' => 'سجّل الدخول للاطلاع على التفاصيل.',
        'why' => 'تصلك هذه الرسالة وفق تفضيلات الإشعارات الخاصة بك، ويمكنك تعديلها في التطبيق.',
    ],
    // FR-PRE-01: a minor's absences told to their guardian (SQL 074).
    'guardian' => [
        'subject' => 'غيابات :pupil',
        'greeting' => 'السلام عليكم،',
        'absences' => 'سُجّل غياب :pupil عن :absences حصص دون تبرير بين :from و:to.',
        'contact' => 'يُرجى الاتصال بالمؤسسة :school.',
        'contact_phone' => 'يُرجى الاتصال بالمؤسسة :school على الرقم :phone.',
        'automatic' => 'هذه رسالة آلية، يُرجى عدم الرد عليها.',
    ],
];
