<?php

use App\Actions\AdmissionQuotaManagement;
use App\Enums\UserRole;
use App\Livewire\Admin\AdmissionPrograms;
use App\Livewire\Admin\CandidateMajorOfferings as OfferingPage;
use App\Livewire\Admin\OfferingQuotas;
use App\Models\ActivityLog;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionQuotaVersion;
use App\Models\AdmissionResult;
use App\Models\AdmissionWish;
use App\Models\CandidateMajorOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
});

/** @return array{CandidateMajorOffering, AdmissionProgram, AdmissionProgram} */
function quotaCatalogFixture(): array
{
    $offering = CandidateMajorOffering::factory()->create();
    $first = $offering->admissionProgram;
    $second = AdmissionProgram::factory()->create(['admission_round_id' => $offering->admission_round_id, 'major_id' => $offering->major_id]);

    return [$offering, $first, $second];
}

/** @return array{total_quota: int, reason: string, limits: list<array{program_id: int, quota: mixed}>} */
function quotaDraftForm(int $total, array $limits): array
{
    return ['total_quota' => $total, 'reason' => 'Điều chỉnh chỉ tiêu đợt tuyển sinh',
        'limits' => collect($limits)->map(fn ($quota, $id) => ['program_id' => (int) $id, 'quota' => $quota])->values()->all()];
}

test('one offering lists multiple methods without changing the legacy program or wishes', function () {
    [$offering,$first,$second] = quotaCatalogFixture();
    $wish = AdmissionWish::factory()->for($first, 'admissionProgram')->create();
    expect($offering->programs()->count())->toBe(2);
    Livewire::test(OfferingPage::class)->assertSee($first->admissionMethod->name)->assertSee($second->admissionMethod->name)
        ->call('manageQuota', $offering->id)->assertSee('Chưa có phiên bản chỉ tiêu');
    expect($offering->fresh()->admission_program_id)->toBe($first->id);
    expect($wish->fresh()->admission_program_id)->toBe($first->id);
});

test('duplicate round major method remains rejected by the catalog', function () {
    [$offering,$first] = quotaCatalogFixture();
    Livewire::test(AdmissionPrograms::class)->call('create')->set('form.admission_round_id', $offering->admission_round_id)
        ->set('form.major_id', $offering->major_id)->set('form.admission_method_id', $first->admission_method_id)
        ->call('save')->assertHasErrors('form.admission_method_id');
});

test('quota approval preserves remainder and zero caps without updating legacy quotas', function () {
    [$offering,$first,$second] = quotaCatalogFixture();
    $quotas = app(AdmissionQuotaManagement::class);
    $version = $quotas->createDraft($offering->id, 'Cấu hình đợt mới');
    expect($version->limits()->whereNull('quota')->count())->toBe(2);
    $quotas->updateDraft($version->id, quotaDraftForm(100, [$first->id => 90, $second->id => 0]));
    $quotas->approve($version->id);
    expect($version->fresh()->status)->toBe('approved');
    expect($version->limits()->sum('quota'))->toBe(90);
    expect($first->fresh()->quota)->toBe(100);
    expect($version->fresh()->content_hash)->toHaveLength(64);
    expect(ActivityLog::query()->where('action', 'admission_quota.approved')->count())->toBe(1);
});

test('negative total or method quota and excessive sum are rejected', function (int $total, int $cap) {
    [$offering,$first] = quotaCatalogFixture();
    $quotas = app(AdmissionQuotaManagement::class);
    $version = $quotas->createDraft($offering->id, 'Tạo bản nháp');
    expect(fn () => $quotas->updateDraft($version->id, quotaDraftForm($total, [$first->id => $cap])))->toThrow(ValidationException::class);
    expect($version->fresh()->total_quota)->toBe(0);
})->with([[-1, 0], [10, -1], [10, 11]]);

test('missing and empty method quotas cannot be approved', function (mixed $cap) {
    [$offering,$first,$second] = quotaCatalogFixture();
    $quotas = app(AdmissionQuotaManagement::class);
    $version = $quotas->createDraft($offering->id, 'Tạo bản nháp');
    $quotas->updateDraft($version->id, quotaDraftForm(10, [$first->id => 5, $second->id => $cap]));
    expect(fn () => $quotas->approve($version->id))->toThrow(ValidationException::class);
    expect($version->fresh()->status)->toBe('draft');
})->with([null, '']);

test('quota rejects foreign programs and duplicate rows', function () {
    [$offering,$first] = quotaCatalogFixture();
    $foreign = AdmissionProgram::factory()->create();
    $quotas = app(AdmissionQuotaManagement::class);
    $version = $quotas->createDraft($offering->id, 'Tạo bản nháp');
    expect(fn () => $quotas->updateDraft($version->id, quotaDraftForm(10, [$foreign->id => 1])))->toThrow(ValidationException::class);
    $form = quotaDraftForm(10, [$first->id => 1]);
    $form['limits'][] = $form['limits'][0];
    expect(fn () => $quotas->updateDraft($version->id, $form))->toThrow(ValidationException::class);
});

test('composite database foreign keys reject a program from another offering', function () {
    [$offering] = quotaCatalogFixture();
    $foreign = AdmissionProgram::factory()->create();
    $version = app(AdmissionQuotaManagement::class)->createDraft($offering->id, 'Tạo bản nháp');
    expect(fn () => DB::table('admission_quota_method_limits')->insert([
        'admission_quota_version_id' => $version->id, 'admission_program_id' => $foreign->id,
        'admission_round_id' => $offering->admission_round_id, 'major_id' => $offering->major_id, 'quota' => 1,
    ]))->toThrow(QueryException::class);
});

test('approved payload and child caps are immutable and retirement preserves history', function () {
    [$offering,$first,$second] = quotaCatalogFixture();
    $quotas = app(AdmissionQuotaManagement::class);
    $version = $quotas->createDraft($offering->id, 'Tạo bản nháp');
    $quotas->updateDraft($version->id, quotaDraftForm(0, [$first->id => 0, $second->id => 0]));
    $quotas->approve($version->id);
    $hash = $version->fresh()->content_hash;
    expect(fn () => $quotas->updateDraft($version->id, quotaDraftForm(1, [$first->id => 1, $second->id => 0])))->toThrow(ValidationException::class);
    expect(fn () => $version->fresh()->update(['total_quota' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $version->limits()->first()->update(['quota' => 1]))->toThrow(ValidationException::class);
    $quotas->retire($version->id);
    expect($version->fresh()->status)->toBe('retired')->and($version->fresh()->content_hash)->toBe($hash);
    expect($version->limits()->count())->toBe(2);
    $new = $quotas->createDraft($offering->id, 'Điều chỉnh sau phê duyệt');
    expect($new->version)->toBe(2)->and($new->previous_version_id)->toBe($version->id);
    expect($version->fresh()->status)->toBe('retired');
});

test('staff candidate inactive and unverified admins cannot approve', function (string $role, string $status, bool $verified) {
    [$offering] = quotaCatalogFixture();
    $version = app(AdmissionQuotaManagement::class)->createDraft($offering->id, 'Tạo bản nháp');
    $this->actingAs(User::factory()->create(['role' => $role, 'status' => $status, 'email_verified_at' => $verified ? now() : null]));
    expect(fn () => app(AdmissionQuotaManagement::class)->approve($version->id))->toThrow(AuthorizationException::class);
    expect($version->fresh()->status)->toBe('draft');
})->with([['staff', 'active', true], ['candidate', 'active', true], ['admin', 'inactive', true], ['admin', 'active', false]]);

test('catalog changes from another admin session stale a saved quota draft', function () {
    [$offering,$first,$second] = quotaCatalogFixture();
    $quotas = app(AdmissionQuotaManagement::class);
    $version = $quotas->createDraft($offering->id, 'Tạo bản nháp');
    $quotas->updateDraft($version->id, quotaDraftForm(10, [$first->id => 5, $second->id => 5]));
    $another = AdmissionMethod::factory()->create();
    Livewire::test(AdmissionPrograms::class)->call('create')->set('form.admission_round_id', $offering->admission_round_id)
        ->set('form.major_id', $offering->major_id)->set('form.admission_method_id', $another->id)->call('save')->assertHasNoErrors();
    expect(fn () => $quotas->approve($version->id))->toThrow(ValidationException::class);
    expect($version->fresh()->status)->toBe('draft');
});

test('catalog writes and quota approval acquire the round serialization lock first', function () {
    [$offering,$first,$second] = quotaCatalogFixture();
    $quotas = app(AdmissionQuotaManagement::class);
    $version = $quotas->createDraft($offering->id, 'Tạo bản nháp');
    $quotas->updateDraft($version->id, quotaDraftForm(10, [$first->id => 5, $second->id => 5]));
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $quotas->approve($version->id);
    $selects = array_values(array_filter($queries, fn ($sql) => str_starts_with($sql, 'select')));
    expect($selects[1] ?? $selects[0])->toContain('admission_rounds');
});

test('admin quota UI supports create save approve history and retirement', function () {
    [$offering,$first,$second] = quotaCatalogFixture();
    $page = Livewire::test(OfferingQuotas::class, ['offeringId' => $offering->id])
        ->set('quotaForm.reason', 'Cấu hình chỉ tiêu')->call('createDraft')->assertHasNoErrors()
        ->set('quotaForm.total_quota', 100)->set('quotaForm.limits.0.quota', 50)->set('quotaForm.limits.1.quota', 30)
        ->call('saveDraft')->assertHasNoErrors()->assertSee('80')->assertSee('20')
        ->call('approve')->assertHasNoErrors()->assertSee('Đã phê duyệt')->call('retire')->assertHasNoErrors()->assertSee('Đã ngừng sử dụng');
    expect(AdmissionQuotaVersion::query()->sole()->status)->toBe('retired');
});

test('quota operations never change published admission results', function () {
    [$offering,$first,$second] = quotaCatalogFixture();
    $wish = AdmissionWish::factory()->for($first, 'admissionProgram')->create();
    $result = AdmissionResult::factory()->for($wish, 'admissionWish')->create(['decision' => 'admitted', 'published_at' => now(), 'confirmed_at' => now()]);
    $before = $result->fresh()->getAttributes();
    $quotas = app(AdmissionQuotaManagement::class);
    $version = $quotas->createDraft($offering->id, 'Quản lý chỉ tiêu riêng');
    $quotas->updateDraft($version->id, quotaDraftForm(10, [$first->id => 5, $second->id => 5]));
    $quotas->approve($version->id);
    expect($result->fresh()->getAttributes())->toBe($before);
});
