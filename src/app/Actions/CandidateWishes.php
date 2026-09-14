<?php

namespace App\Actions;

use App\Enums\WishStatus;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\Major;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CandidateWishes
{
    /** @param array<string, mixed> $form */
    public function add(int $applicationId, array $form): void
    {
        $validated = Validator::make(['form' => $form], [
            'form' => ['required', 'array:admission_program_id'],
            'form.admission_program_id' => ['required', 'integer', 'min:1'],
        ])->validate();
        try {
            DB::transaction(function () use ($applicationId, $validated): void {
                $application = CandidateApplications::lockApplication(CandidateApplications::lockProfile(), $applicationId);
                Gate::authorize('create', [AdmissionWish::class, $application]);
                CandidateApplications::requireEditable($application);
                Gate::authorize('browseForApplication', [AdmissionProgram::class, $application]);
                $wishes = $application->wishes()->orderBy('id')->lockForUpdate()->get();
                $programs = self::lockPrograms($application, [(int) $validated['form']['admission_program_id']]);
                $round = $application->admissionRound()->lockForUpdate()->firstOrFail();
                self::lockProgramParents($programs);
                CandidateApplications::requireOpenRound($round);
                $program = $programs->first();
                if ($program === null || self::unavailableReason($program, $round) !== null) {
                    throw ValidationException::withMessages(['form.admission_program_id' => __('The selected program is unavailable for this round.')]);
                }
                if ($wishes->contains('admission_program_id', $program->getKey())) {
                    throw ValidationException::withMessages(['form.admission_program_id' => __('This program is already in your wish list.')]);
                }
                if ($wishes->count() >= 65535) {
                    throw ValidationException::withMessages(['wishes' => __('The wish priority storage limit has been reached.')]);
                }
                $this->applyOrder($wishes, self::orderedIds($wishes));
                $wish = $application->wishes()->make([
                    'admission_program_id' => $program->getKey(), 'priority' => $wishes->count() + 1,
                    'status' => WishStatus::Pending, 'calculated_score' => null,
                ]);
                CandidateApplications::requireOpenRound($round);
                if (! $wish->save()) {
                    throw ValidationException::withMessages(['wishes' => __('The wish could not be saved.')]);
                }
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $message = (string) ($exception->errorInfo[2] ?? '');
            if (! str_contains($message, 'admission_wishes_application_id_') && ! str_contains($message, 'admission_wishes.application_id,')) {
                throw $exception;
            }
            throw ValidationException::withMessages(['wishes' => __('The wish list changed or this program was already selected. Reload and try again.')]);
        }
    }

    /** @param list<int> $expectedOrder */
    public function delete(int $applicationId, int $wishId, array $expectedOrder): void
    {
        try {
            DB::transaction(function () use ($applicationId, $wishId, $expectedOrder): void {
                $application = CandidateApplications::lockApplication(CandidateApplications::lockProfile(), $applicationId);
                CandidateApplications::requireEditable($application);
                $wishes = $application->wishes()->orderBy('id')->lockForUpdate()->get();
                $wish = $wishes->firstWhere('id', $wishId);
                abort_if($wish === null, 404);
                Gate::authorize('delete', $wish);
                abort_if($wish->result()->lockForUpdate()->first() !== null, 403);
                $round = $application->admissionRound()->lockForUpdate()->firstOrFail();
                CandidateApplications::requireOpenRound($round);
                $this->assertCurrentOrder($wishes, $expectedOrder);
                $remaining = $wishes->reject(fn (AdmissionWish $item): bool => $item->is($wish))->values();
                $order = self::orderedIds($remaining);
                $this->authorizeOrder($remaining, $order);
                if (! $wish->delete()) {
                    throw ValidationException::withMessages(['deletion' => __('The wish could not be deleted.')]);
                }
                $this->applyOrder($remaining, $order);
                CandidateApplications::requireOpenRound($round);
            }, 3);
        } catch (QueryException $exception) {
            $error = $exception->errorInfo;
            $foreignKeyFailure = match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => ($error[0] ?? null) === '23000' && ($error[1] ?? null) === 1451,
                'sqlite' => ($error[0] ?? null) === '23000' && in_array($error[1] ?? null, [19, 787, 1811], true)
                    && ($error[2] ?? null) === 'FOREIGN KEY constraint failed',
                default => false,
            };
            $prefix = 'delete from '.DB::connection()->getQueryGrammar()->wrapTable((new AdmissionWish)->getTable()).' ';
            if (! $foreignKeyFailure || ! str_starts_with($exception->getSql(), $prefix)) {
                throw $exception;
            }
            throw ValidationException::withMessages(['deletion' => __('A wish with an admission result cannot be deleted.')]);
        }
    }

    /** @param array<mixed> $desiredOrder
     * @param  list<int>  $expectedOrder
     */
    public function reorder(int $applicationId, array $desiredOrder, array $expectedOrder): void
    {
        $validated = Validator::make(['order' => $desiredOrder], [
            'order' => ['present', 'array', 'list', 'max:65535'],
            'order.*' => ['required', 'integer', 'min:1', 'distinct'],
        ])->validate();
        $order = array_values(array_map(fn ($id): int => (int) $id, $validated['order']));
        if (count(array_unique($order)) !== count($order)) {
            throw ValidationException::withMessages(['order' => __('Each wish must appear exactly once.')]);
        }
        DB::transaction(function () use ($applicationId, $order, $expectedOrder): void {
            $application = CandidateApplications::lockApplication(CandidateApplications::lockProfile(), $applicationId);
            CandidateApplications::requireEditable($application);
            $wishes = $application->wishes()->orderBy('id')->lockForUpdate()->get();
            $round = $application->admissionRound()->lockForUpdate()->firstOrFail();
            CandidateApplications::requireOpenRound($round);
            $this->assertCurrentOrder($wishes, $expectedOrder);
            $ids = $wishes->modelKeys();
            $requested = $order;
            sort($ids);
            sort($requested);
            if ($ids !== $requested) {
                throw ValidationException::withMessages(['order' => __('Include every wish from this application exactly once. Reload and try again.')]);
            }
            $this->applyOrder($wishes, $order);
            CandidateApplications::requireOpenRound($round);
        }, 3);
    }

    /** @param Collection<int, AdmissionWish> $wishes
     * @return list<int>
     */
    public static function orderedIds(Collection $wishes): array
    {
        return array_values($wishes->sortBy('priority')->map(fn (AdmissionWish $wish): int => $wish->getKey())->all());
    }

    /** @param Collection<int, AdmissionWish> $wishes
     * @param  list<int>  $expectedOrder
     */
    private function assertCurrentOrder(Collection $wishes, array $expectedOrder): void
    {
        if (self::orderedIds($wishes) !== $expectedOrder) {
            throw ValidationException::withMessages(['order' => __('The wish list changed since this page was loaded. Reload the wishes and try again.')]);
        }
    }

    /** @param Collection<int, AdmissionWish> $wishes
     * @param  list<int>  $order
     */
    private function authorizeOrder(Collection $wishes, array $order): void
    {
        $positions = array_flip($order);
        foreach ($wishes as $wish) {
            if ($wish->getAttribute('priority') !== $positions[$wish->getKey()] + 1) {
                Gate::authorize('update', $wish);
                if ($wish->result()->lockForUpdate()->first() !== null) {
                    throw ValidationException::withMessages(['order' => __('Wishes with admission results cannot change priority.')]);
                }
            }
        }
    }

    /**
     * A free slot breaks permutation cycles without unsigned overflow or a
     * transient duplicate priority. Rows are never removed and reinserted.
     *
     * @param  Collection<int, AdmissionWish>  $wishes
     * @param  list<int>  $order
     */
    private function applyOrder(Collection $wishes, array $order): void
    {
        if (count($order) > 65535) {
            throw ValidationException::withMessages(['order' => __('The wish priority storage limit has been reached.')]);
        }
        $this->authorizeOrder($wishes, $order);
        $byId = $wishes->keyBy('id');
        $occupied = [];
        foreach ($wishes as $wish) {
            $occupied[(int) $wish->getAttribute('priority')] = (int) $wish->getKey();
        }
        foreach ($order as $index => $id) {
            $wish = $byId->get($id);
            abort_if($wish === null, 404);
            $target = $index + 1;
            if ($wish->getAttribute('priority') === $target) {
                continue;
            }
            if (isset($occupied[$target])) {
                $free = 0;
                while (isset($occupied[$free]) && $free <= 65535) {
                    $free++;
                }
                if ($free > 65535) {
                    throw ValidationException::withMessages(['order' => __('No safe temporary priority is available.')]);
                }
                $occupant = $byId->get($occupied[$target]);
                abort_if($occupant === null, 404);
                $this->moveTo($occupant, $free, $occupied);
            }
            $this->moveTo($wish, $target, $occupied);
        }
    }

    /** @param array<int, int> $occupied
     * @param-out array<int, int> $occupied
     */
    private function moveTo(AdmissionWish $wish, int $priority, array &$occupied): void
    {
        $old = $wish->getAttribute('priority');
        $wish->setAttribute('priority', $priority);
        if (! $wish->save()) {
            throw ValidationException::withMessages(['order' => __('The wish order could not be saved.')]);
        }
        unset($occupied[$old]);
        $occupied[$priority] = (int) $wish->getKey();
    }

    /** @param list<int> $ids
     * @return Collection<int, AdmissionProgram>
     */
    public static function lockPrograms(Application $application, array $ids): Collection
    {
        return $application->admissionRound()->firstOrFail()->programs()
            ->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /** @param Collection<int, AdmissionProgram> $programs */
    public static function lockProgramParents(Collection $programs): void
    {
        $majors = Major::query()->whereKey($programs->pluck('major_id')->unique()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $methods = AdmissionMethod::query()->whereKey($programs->pluck('admission_method_id')->unique()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($programs as $program) {
            $program->setRelation('major', $majors->get($program->getAttribute('major_id')));
            $program->setRelation('admissionMethod', $methods->get($program->getAttribute('admission_method_id')));
        }
    }

    public static function unavailableReason(AdmissionProgram $program, AdmissionRound $round): ?string
    {
        $reason = match (true) {
            (int) $program->getAttribute('admission_round_id') !== (int) $round->getKey() => 'Different admission round',
            $program->getAttribute('status') !== 'active' => 'Program inactive',
            ! $program->major?->getAttribute('is_active') => 'Major inactive',
            ! $program->admissionMethod?->getAttribute('is_active') => 'Admission method inactive',
            $program->getAttribute('quota') <= 0 => 'No configured capacity',
            ! CandidateApplications::roundIsOpen($round) => 'Round outside its open application window',
            default => null,
        };

        if ($reason === null) {
            return null;
        }
        $translated = __($reason);

        return is_string($translated) ? $translated : $reason;
    }
}
