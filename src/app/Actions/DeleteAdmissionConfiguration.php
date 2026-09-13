<?php

namespace App\Actions;

use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\Major;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteAdmissionConfiguration
{
    public function __invoke(Model $record): void
    {
        abort_unless(in_array($record::class, [AdmissionRound::class, Major::class, AdmissionMethod::class, AdmissionProgram::class], true), 404);
        $record->getConnection()->transaction(function () use ($record): void {
            $current = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $current);
            if (Gate::denies('delete', $current)) {
                throw ValidationException::withMessages(['deletion' => 'This record is in use by admission records and cannot be deleted.']);
            }
            Gate::authorize('delete', $current);
            try {
                /** @throws QueryException */
                $deleted = $current->delete();
                if ($deleted !== true) {
                    throw ValidationException::withMessages(['deletion' => 'This record could not be deleted.']);
                }
            } catch (QueryException $exception) {
                $error = $exception->errorInfo;
                $connection = $current->getConnection();
                $deletePrefix = 'delete from '.$connection->getQueryGrammar()->wrapTable($current->getTable()).' ';
                $foreignKeyFailure = match ($connection->getDriverName()) {
                    'mysql', 'mariadb' => ($error[0] ?? null) === '23000' && ($error[1] ?? null) === 1451,
                    'sqlite' => ($error[0] ?? null) === '23000'
                        && in_array($error[1] ?? null, [19, 787, 1811], true)
                        && ($error[2] ?? null) === 'FOREIGN KEY constraint failed',
                    default => false,
                };
                if (! str_starts_with($exception->getSql(), $deletePrefix) || ! $foreignKeyFailure) {
                    throw $exception;
                }
                throw ValidationException::withMessages(['deletion' => 'This record is in use by admission records and cannot be deleted.']);
            }
        });
    }
}
