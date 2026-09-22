<?php

return [
    'temporary_file_upload' => [
        'disk' => 'candidate-private',
        'rules' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        'middleware' => ['auth', 'active', 'verified', 'throttle:20,1'],
        'preview_mimes' => ['jpg', 'jpeg', 'png'],
        'directory' => 'livewire-tmp',
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
