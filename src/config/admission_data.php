<?php

return [
    'exam_types' => [
        'thpt' => ['label' => 'THPT', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
        'dgnl' => ['label' => 'ĐGNL', 'score' => ['min' => 0, 'max' => 150, 'decimal_places' => 0]],
        'dgtd' => ['label' => 'ĐGTD', 'score' => ['min' => 0, 'max' => 150, 'decimal_places' => 0]],
        'vsat' => ['label' => 'V-SAT', 'score' => ['min' => 0, 'max' => 150, 'decimal_places' => 0]],
        'spt' => ['label' => 'SPT', 'score' => ['min' => 0, 'max' => 150, 'decimal_places' => 0]],
    ],
    'certificate_types' => [
        'ielts' => ['label' => 'IELTS', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 2]],
        'toeic' => ['label' => 'TOEIC', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 2]],
        'sat' => ['label' => 'SAT', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
    ],
    'subjects' => [
        'MATH' => 'Toán',
        'LITERATURE' => 'Ngữ văn',
        'ENG' => 'Tiếng Anh',
        'PHYSICS' => 'Vật lý',
        'CHEMISTRY' => 'Hóa học',
        'BIOLOGY' => 'Sinh học',
        'HISTORY' => 'Lịch sử',
        'GEOGRAPHY' => 'Địa lý',
        'CIVICS' => 'GDCD',
        'ECONOMIC_LAW' => 'KTPL',
        'INDUSTRIAL_TECH' => 'Công nghệ Công nghiệp',
        'INFORMATICS' => 'Tin học',
        'JAPANESE' => 'Tiếng Nhật',
        'KOREAN' => 'Tiếng Hàn',
        'CHINESE' => 'Tiếng Trung',
        'FRENCH' => 'Tiếng Pháp',
        'RUSSIAN' => 'Tiếng Nga',
    ],
];
