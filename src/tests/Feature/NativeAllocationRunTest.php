<?php

use App\Actions\NativeAllocationPolicyManagement;
use App\Actions\NativeAllocationRun;
use App\Actions\NativeDeferredAcceptance;
use App\Actions\NativeResultWorkflow;
use App\Livewire\Admin\NativeResults;
use App\Models\ActivityLog;
use App\Models\NativeResultEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Fixtures/native-allocation.php';

test('real native DA integrates sealed result version approval publication and candidate visibility', function () {
    $fixture = allocationFixture();
    $snapshot = $fixture['application']->submissionSnapshots()->sole();
    $hash = $snapshot->content_hash;
    $run = app(NativeAllocationRun::class);
    $results = app(NativeResultWorkflow::class);
    $count = DB::table('native_result_versions')->count();

    $preview = $run->preview($fixture['round']->id);
    expect(DB::table('native_result_versions')->count())->toBe($count);
    $version = $run->run($fixture['round']->id, $results);
    expect($run->run($fixture['round']->id, $results)->id)->toBe($version->id);
    expect($version->status)->toBe('draft');
    expect($version->entries()->sole()->wish_method_binding_id)->toBe($snapshot->entries()->sole()->bindings()->where('admission_method_id', $fixture['thpt']->admission_method_id)->sole()->id);
    expect($results->check($version->id))->toBe([]);
    $owner = $fixture['application']->candidateProfile->user;
    expect(NativeResultEntry::query()->visibleToCandidate($owner)->count())->toBe(0);
    $results->approve($version->id, $version->content_hash);
    expect($version->fresh()->status)->toBe('approved');
    expect($fixture['round']->fresh()->getRawOriginal('status'))->toBe('closed');
    $results->publish($version->id, $version->content_hash);
    expect($version->fresh()->status)->toBe('published');
    expect(NativeResultEntry::query()->visibleToCandidate($owner)->count())->toBe(1);
    expect(NativeResultEntry::query()->visibleToCandidate(User::factory()->create())->count())->toBe(0);
    expect($snapshot->fresh()->content_hash)->toBe($hash);
    expect($snapshot->entries()->sole()->bindings()->count())->toBe(2);
    test()->assertDatabaseCount('admission_results', 0);
    test()->assertDatabaseCount('admission_wishes', 0);
    expect(ActivityLog::query()->where('action', 'native_results.draft_created')->count())->toBe(1);
});

test('policy is immutable and cannot be approved late or with incomplete method precedence', function () {
    $fixture = allocationFixture();
    $management = app(NativeAllocationPolicyManagement::class);
    expect(fn () => $fixture['policy']->update(['payload' => []]))->toThrow(ValidationException::class);
    $payload = $fixture['payload'];
    $payload['method_priority'] = [$fixture['thpt']->admission_method_id];
    expect(fn () => $management->createDraft($fixture['round']->id, $payload))->toThrow(ValidationException::class);
    $payload = $fixture['payload'];
    $payload['method_priority'] = array_reverse($payload['method_priority']);
    $new = $management->createDraft($fixture['round']->id, $payload);
    expect(fn () => $management->approve($new->id, $new->content_hash))->toThrow(ValidationException::class);
    expect($new->fresh()->status)->toBe('draft');
});

test('claimed engine name never certifies manually altered decisions', function () {
    $fixture = allocationFixture();
    $run = app(NativeAllocationRun::class);
    $preview = $run->preview($fixture['round']->id);
    $preview['decisions'][0]['binding_id'] = null;
    $preview['decisions'][0]['decision'] = 'not_admitted';
    $results = app(NativeResultWorkflow::class);
    $version = $results->createDraft($fixture['round']->id, $preview['decisions'], NativeDeferredAcceptance::ALGORITHM, $preview['policy_reference'], 'Spoofed fixture');

    expect($results->check($version->id))->not->toBeEmpty();
    expect(fn () => $results->approve($version->id, $version->content_hash))->toThrow(ValidationException::class);
});

test('missing approval stale scoring and unauthorized actors cannot allocate', function () {
    $fixture = allocationFixture();
    $run = app(NativeAllocationRun::class);
    DB::table('native_allocation_policies')->where('id', $fixture['policy']->id)->update(['approved_by' => null]);
    expect(fn () => $run->preview($fixture['round']->id))->toThrow(ValidationException::class);
    DB::table('native_allocation_policies')->where('id', $fixture['policy']->id)->update(['approved_by' => $fixture['admin']->id]);
    DB::table('native_method_evaluations')->update(['input_fingerprint' => str_repeat('0', 64)]);
    expect(fn () => $run->preview($fixture['round']->id))->toThrow(ValidationException::class);
    test()->actingAs($fixture['application']->candidateProfile->user);
    expect(fn () => $run->preview($fixture['round']->id))->toThrow(HttpException::class);
});

test('native allocation UI requires confirmation and creates only a draft version', function () {
    $fixture = allocationFixture();

    Livewire::test(NativeResults::class, ['roundId' => $fixture['round']->id])->call('allocate')->assertHasErrors(['confirmed']);
    test()->assertDatabaseCount('native_result_versions', 0);
    Livewire::test(NativeResults::class, ['roundId' => $fixture['round']->id])->set('confirmed', true)->call('allocate')->assertHasNoErrors()->assertSet('confirmed', false);
    test()->assertDatabaseHas('native_result_versions', ['status' => 'draft']);
    test()->assertDatabaseCount('admission_results', 0);
});

test('reusing policy configuration creates no duplicate version or approval audit', function () {
    $fixture = allocationFixture();

    expect(app(NativeAllocationPolicyManagement::class)->createDraft($fixture['round']->id, $fixture['payload'])->id)->toBe($fixture['policy']->id);
    test()->assertDatabaseCount('native_allocation_policies', 1);
    expect(ActivityLog::query()->where('action', 'native_allocation.policy_approved')->count())->toBe(1);
});
