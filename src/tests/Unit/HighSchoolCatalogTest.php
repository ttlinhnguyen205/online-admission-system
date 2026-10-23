<?php

test('reviewed school catalog covers the existing provinces with unique source codes and names', function () {
    $catalog = json_decode(file_get_contents(__DIR__.'/../../database/data/high_schools_2026.json'), true, flags: JSON_THROW_ON_ERROR);
    $expectedCodes = ['01', '04', '08', '11', '12', '14', '15', '19', '20', '22', '24', '25', '31', '33', '37', '38', '40', '42', '44', '46', '48', '51', '52', '56', '66', '68', '75', '79', '80', '82', '86', '91', '92', '96'];
    $codes = [];
    $names = [];
    $counts = [];
    foreach ($catalog['schools'] as $row) {
        expect($row['province_code'])->toBeIn($expectedCodes);
        expect($row['code'])->toMatch('/\A[0-9]{3}\z/');
        expect($row['source_record'])->toBeGreaterThanOrEqual(8);
        expect(mb_strlen($row['name']))->toBeLessThanOrEqual(255);
        $codes[] = $row['province_code'].':'.$row['code'];
        $names[] = $row['province_code'].':'.preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($row['name']));
        $counts[$row['province_code']] = ($counts[$row['province_code']] ?? 0) + 1;
    }
    expect($catalog['schools'])->toHaveCount(2784);
    expect(array_unique($codes))->toHaveCount(2784);
    expect(array_unique($names))->toHaveCount(2784);
    expect($counts)->toHaveCount(34);
    foreach ($catalog['metadata']['coverage'] as $coverage) {
        expect($counts[$coverage['province_code']])->toBe($coverage['school_count']);
    }
    expect($catalog['withheld'])->toHaveCount(920);
    expect($catalog['metadata']['source_record_count'])->toBe(4922);
    expect($catalog['metadata']['outside_thpt_scope_count'])->toBe(1218);
});

test('conflicting and historical source records cannot enter the seedable catalog', function () {
    $catalog = json_decode(file_get_contents(__DIR__.'/../../database/data/high_schools_2026.json'), true, flags: JSON_THROW_ON_ERROR);
    $accepted = array_column($catalog['schools'], 'source_record');
    foreach ($catalog['withheld'] as $row) {
        expect($row['source_record'])->not->toBeIn($accepted);
        expect($row['reason'])->toBeIn(['historical_or_priority_area_record_needs_review', 'province_address_conflict', 'same_name_multiple_codes_needs_review']);
    }
    $conflicts = array_filter($catalog['withheld'], fn (array $row): bool => $row['reason'] === 'province_address_conflict');
    expect($conflicts)->toHaveCount(5);
});
