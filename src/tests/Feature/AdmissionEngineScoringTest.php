<?php

use App\Enums\UserRole;
use App\Models\AdmissionResult;
use App\Models\CandidateScore;
use App\Models\User;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AdmissionEngineFixtures.php';

test('approved methods persist weighted sums and scalar passthrough', function (string $code, string $score, string $expected) {
    $program = engineProgram(code: $code);
    $application = engineApplication($program, $score);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    engineRun($program);

    expect($application->wishes()->sole()->calculated_score)->toBe($expected);
    expect(AdmissionResult::query()->sole()->final_score)->toBe($expected);
})->with([
    'THPT sum' => ['DEMO-THPT-A00', '8.250', '24.750'],
    'HB double English' => ['DEMO-HB-D01', '8.125', '32.500'],
    'DGNL above ten' => ['DEMO-DGNL', '987.654', '987.654'],
    'weighted above ten' => ['DEMO-THPT-A00', '12.345', '37.035'],
]);

test('explicit aliases accept only trim and Unicode lowercase normalization', function (string $code, string $alias, string $expected) {
    $program = engineProgram(code: $code);
    $application = engineApplication($program);
    CandidateScore::query()->where('candidate_profile_id', $application->candidate_profile_id)->update(['score_type' => $alias]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    engineRun($program);

    expect(AdmissionResult::query()->sole()->final_score)->toBe($expected);
})->with([
    ['DEMO-THPT-A00', ' THPT ', '24.000'],
    ['DEMO-HB-D01', 'học bạ', '32.000'], ['DEMO-HB-D01', 'hoc_ba', '32.000'],
    ['DEMO-HB-D01', ' HỌC BẠ ', '32.000'], ['DEMO-HB-D01', ' HOC_BA ', '32.000'],
    ['DEMO-DGNL', 'đgnl', '8.000'], ['DEMO-DGNL', 'dgnl', '8.000'], ['DEMO-DGNL', ' ĐGNL ', '8.000'],
]);

test('unknown score types are not transliterated guessed or converted', function (string $alias) {
    $program = engineProgram(code: 'DEMO-HB-D01');
    $application = engineApplication($program);
    CandidateScore::query()->where('candidate_profile_id', $application->candidate_profile_id)->update(['score_type' => $alias]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, 'Missing verified score');

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
})->with(['hoc ba', 'học_bạ', 'hoc-ba', 'học  bạ', 'IELTS', 'SAT', 'high school', 'unknown']);

test('matching scores require verification subject code and the round year', function (array $changes) {
    $program = engineProgram(code: 'DEMO-THPT-A00');
    $application = engineApplication($program);
    CandidateScore::query()->where('candidate_profile_id', $application->candidate_profile_id)->where('subject_code', 'MATH')->update($changes);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, 'Missing verified score');

    $this->assertDatabaseCount('admission_results', 0);
})->with([
    'unverified' => [['verified' => false]], 'old year' => [['exam_year' => 2025]], 'future year' => [['exam_year' => 2027]],
    'name is not code' => [['subject_code' => null, 'subject_name' => 'MATH']],
    'wrong code' => [['subject_code' => 'MATHEMATICS', 'subject_name' => 'MATH']],
]);

test('irrelevant and unverified attempts do not compete with a required verified score', function () {
    $program = engineProgram(code: 'DEMO-THPT-A00');
    $application = engineApplication($program);
    CandidateScore::factory()->create(['candidate_profile_id' => $application->candidate_profile_id, 'score' => '99.000', 'verified' => false]);
    CandidateScore::factory()->create(['candidate_profile_id' => $application->candidate_profile_id, 'score' => '99.000', 'verified' => true, 'exam_year' => 2025]);
    CandidateScore::factory()->create(['candidate_profile_id' => $application->candidate_profile_id, 'score_type' => 'SAT', 'score' => '1500', 'verified' => true]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    engineRun($program);

    expect(AdmissionResult::query()->sole()->final_score)->toBe('24.000');
});

test('all matching attempts including distinct explicit aliases block as ambiguous', function (string $code, string $alias, ?string $subject) {
    $program = engineProgram(code: $code);
    $application = engineApplication($program);
    CandidateScore::factory()->create([
        'candidate_profile_id' => $application->candidate_profile_id, 'score_type' => $alias,
        'subject_code' => $subject, 'score' => '99.000', 'verified' => true,
    ]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, 'Ambiguous verified score attempts');

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
})->with([
    ['DEMO-THPT-A00', 'thpt', 'MATH'], ['DEMO-THPT-A00', ' THPT ', ' math '],
    ['DEMO-HB-D01', 'học bạ', 'MATH'], ['DEMO-HB-D01', 'hoc_ba', 'ENG'],
    ['DEMO-DGNL', 'đgnl', null], ['DEMO-DGNL', 'dgnl', 'ANY'],
]);

test('malformed weighted contracts block rather than become ineligible', function (mixed $configuration) {
    $program = engineProgram(code: 'DEMO-THPT-A00');
    engineApplication($program);
    $program->admissionMethod->update(['score_config' => $configuration]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseHas('admission_wishes', ['status' => 'pending', 'calculated_score' => null]);
})->with([
    'null' => [null], 'scalar JSON' => ['sum'], 'empty' => [[]], 'wrong root' => [['sum' => []]],
    'weights scalar' => [['weights' => 1]], 'missing subject' => [['weights' => ['MATH' => 1, 'PHYSICS' => 1]]],
    'extra subject' => [['weights' => ['MATH' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1, 'ENG' => 1]]],
    'duplicate normalized' => [['weights' => ['MATH' => 1, ' math ' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]],
    'noncanonical subject' => [['weights' => ['math' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]],
    'unsupported operator' => [['weights' => ['MATH' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1], 'bonus' => 1]],
    'zero weight' => [['weights' => ['MATH' => 0, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]],
    'negative' => [['weights' => ['MATH' => -1, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]],
    'over max weight' => [['weights' => ['MATH' => 101, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]],
    'precision' => [['weights' => ['MATH' => '1.0001', 'PHYSICS' => 1, 'CHEMISTRY' => 1]]],
    'boolean' => [['weights' => ['MATH' => true, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]],
]);

test('scalar method requires a verified year input and null configuration', function (array $scoreChanges, mixed $configuration) {
    $program = engineProgram();
    $application = engineApplication($program);
    $application->candidateProfile->scores()->update($scoreChanges);
    $program->admissionMethod->update(['score_config' => $configuration]);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class);

    $this->assertDatabaseCount('admission_results', 0);
})->with([
    [['exam_year' => 2025], null], [['verified' => false], null], [['score_type' => 'SAT'], null],
    [['score_type' => 'dgnl'], ['weights' => ['MATH' => 1]]],
]);

test('unsupported methods never infer execution from names prefixes or arbitrary null config', function (string $code) {
    $program = engineProgram(code: $code);
    engineApplication($program);
    $program->admissionMethod->update(['name' => 'DEMO-DGNL', 'description' => 'Scalar score']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, 'Unsupported calculation contract');

    $this->assertDatabaseCount('admission_results', 0);
})->with(['DEMO-THANG', 'OTHER', 'DEMO-DGNL-NEW']);

test('fractional products are summed before one half up rounding used for threshold rank and persistence', function () {
    $program = engineProgram(code: 'DEMO-THPT-A00', quota: 2);
    $program->admissionMethod->update(['score_config' => ['weights' => ['MATH' => '0.500', 'PHYSICS' => '0.500', 'CHEMISTRY' => '0.500']]]);
    $program->update(['minimum_score' => '0.002']);
    engineApplication($program, '0.001');
    engineApplication($program, '0.001');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    engineRun($program);

    expect(AdmissionResult::query()->pluck('final_score')->all())->toBe(['0.002', '0.002']);
    expect(AdmissionResult::query()->pluck('rank')->all())->toBe([1, 1]);
    $this->assertDatabaseCount('admission_results', 2);
    $this->assertDatabaseMissing('admission_wishes', ['status' => 'ineligible']);
    $this->assertDatabaseHas('admission_wishes', ['calculated_score' => '0.002', 'status' => 'admitted']);
});

test('arithmetic overflow blocks the whole cohort without clipping', function () {
    $program = engineProgram(code: 'DEMO-THPT-A00', quota: 2);
    engineApplication($program, '8.000');
    engineApplication($program, '99999.999');
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    expect(fn () => engineRun($program))->toThrow(ValidationException::class, 'storage capacity');

    $this->assertDatabaseCount('admission_results', 0);
    $this->assertDatabaseCount('activity_logs', 0);
    $this->assertDatabaseMissing('admission_wishes', ['status' => 'admitted']);
});

test('minimum comparisons include equality ignore historical cutoff and add no priority bonus', function (?string $minimum, string $status, ?int $rank) {
    $program = engineProgram();
    $program->update(['minimum_score' => $minimum, 'previous_cutoff_score' => '999.000']);
    $application = engineApplication($program, '10.125');
    $application->candidateProfile->update(['priority_area' => 'KV1', 'priority_object' => '01']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

    engineRun($program);

    $this->assertDatabaseHas('admission_wishes', ['status' => $status, 'calculated_score' => '10.125']);
    expect(AdmissionResult::query()->sole()->rank)->toBe($rank);
    expect(AdmissionResult::query()->sole()->final_score)->toBe('10.125');
    expect(AdmissionResult::query()->sole()->decision->value)->toBe($status === 'admitted' ? 'admitted' : 'not_admitted');
})->with([
    'null' => [null, 'admitted', 1], 'zero' => ['0.000', 'admitted', 1],
    'above' => ['10.124', 'admitted', 1], 'equal' => ['10.125', 'admitted', 1], 'below' => ['10.126', 'ineligible', null],
]);
