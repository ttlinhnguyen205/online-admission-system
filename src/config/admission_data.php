<?php

return [
    'exam_types' => [
        'thpt' => ['label' => 'THPT', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
        'dgnl' => ['label' => 'ĐGNL', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
        'dgtd' => ['label' => 'ĐGTD', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
        'vsat' => ['label' => 'V-SAT', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
        'spt' => ['label' => 'SPT', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
    ],
    'certificate_types' => [
        'ielts' => ['label' => 'IELTS', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
        'sat' => ['label' => 'SAT', 'score' => ['min' => null, 'max' => null, 'decimal_places' => 3]],
    ],
    'subjects' => [
        'MATH' => 'Toán',
        'LITERATURE' => 'Ngữ văn',
        'ENG' => 'Tiếng Anh',
        'PHYSICS' => 'Vật lý',
        'CHEMISTRY' => 'Hóa học',
    ],
];
