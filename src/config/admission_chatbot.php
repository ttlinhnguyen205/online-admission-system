<?php

return [
    'enabled' => env('ADMISSION_CHATBOT_ENABLED', false),
    'provider' => env('ADMISSION_CHATBOT_PROVIDER', 'gemini'),
    'connect_timeout' => 3,
    'timeout' => 15,
    'max_response_bytes' => 65536,
    'max_output_tokens' => 2048,
    'max_question_characters' => 2000,
    'max_context_bytes' => 16000,
    'max_facts' => 20,
    'max_catalog_records' => 200,
    'per_minute' => 5,
    'per_day' => 50,
    'per_ip_minute' => 30,
    'global_per_day' => 1000,
    'history_turns' => 10,
    'history_bytes' => 32768,
    'history_minutes' => 120,
    'history_absolute_hours' => 24,

    /*
     * Deny additional disclosure by default. Keys: "major:ID:description",
     * "method:ID:method", "program:ID:program", "program:ID:tuition",
     * or "major:ID:tuition" (explicitly labelled default fee).
     * Entries require approved => true and fingerprint from
     * BuildAdmissionCounselingContext::fingerprint($model, $field, $metadata).
     * Tuition also requires verified currency and period (semester/year/credit/course).
     * Fingerprints bind the record identity, all content and tuition metadata.
     * Never approve demo values as official fees. Reapprove after any record change.
     */
    'publications' => [],
];
