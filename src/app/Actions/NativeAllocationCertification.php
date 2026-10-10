<?php

namespace App\Actions;

use App\Models\NativeResultVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Validation\ValidationException;

class NativeAllocationCertification
{
    public function __construct(private NativeAllocationRun $allocation) {}

    public const BLOCKER = 'BLOCKED: cần chính sách phối hợp Native được phê duyệt đúng hạn và kết quả DA đã tái kiểm chứng; đồng điểm và tương đương rule versions phải được giải quyết.';

    /**
     * Mandatory certification boundary. No config flag, administrator confirmation,
     * or claimed algorithm name substitutes for a verified allocation implementation.
     *
     * @return list<string>
     */
    public function blockers(NativeResultVersion $version): array
    {
        if ($version->algorithm_version !== NativeDeferredAcceptance::ALGORITHM) {
            return [self::BLOCKER];
        }
        try {
            $expected = $this->allocation->preview($version->admission_round_id);
        } catch (ValidationException|ModelNotFoundException|MultipleRecordsFoundException $exception) {
            return [self::BLOCKER, $exception->getMessage()];
        }
        if ($version->policy_reference !== $expected['policy_reference']) {
            return [self::BLOCKER];
        }
        $actual = $version->entries()->orderBy('application_id')->get()->map(fn ($entry) => ['application_id' => $entry->application_id, 'binding_id' => $entry->wish_method_binding_id, 'decision' => $entry->decision, 'reason' => $entry->reason])->all();
        $decisions = collect($expected['decisions'])->sortBy('application_id')->values()->all();

        return $actual === $decisions ? [] : [self::BLOCKER, 'Kết quả không khớp phép phân bổ DA tái tính.'];
    }
}
