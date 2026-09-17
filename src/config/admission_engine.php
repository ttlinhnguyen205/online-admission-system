<?php

return [
    'version' => 'phase7-v1',
    'algorithm_version' => 'candidate-proposals-v1',
    'scoring_version' => 'explicit-aliases-round-year-half-up-v1',
    'score_type_aliases' => [
        'thpt' => 'thpt',
        'học bạ' => 'hoc_ba',
        'hoc_ba' => 'hoc_ba',
        'đgnl' => 'dgnl',
        'dgnl' => 'dgnl',
    ],
    'methods' => [
        'DEMO-THPT-A00' => ['type' => 'weighted', 'score_type' => 'thpt', 'subjects' => ['MATH', 'PHYSICS', 'CHEMISTRY']],
        'DEMO-HB-D01' => ['type' => 'weighted', 'score_type' => 'hoc_ba', 'subjects' => ['MATH', 'LITERATURE', 'ENG']],
        'DEMO-DGNL' => ['type' => 'scalar', 'score_type' => 'dgnl'],
        'DEMO-THANG' => ['type' => 'unsupported'],
    ],
];
