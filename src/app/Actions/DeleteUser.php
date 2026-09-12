<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    public function __invoke(User $user): void
    {
        $user->getConnection()->transaction(function () use ($user): void {
            $account = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $authorization = Gate::forUser($account)->inspect('delete', $account);

            if ($authorization->denied()) {
                throw ValidationException::withMessages([
                    'accountDeletion' => $authorization->message() ?? 'Your account is not eligible for deletion.',
                ]);
            }

            try {
                /** @throws QueryException */
                $deleted = $account->delete();

                if ($deleted !== true) {
                    throw ValidationException::withMessages([
                        'accountDeletion' => 'Your account could not be deleted.',
                    ]);
                }
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyDeletionFailure($exception, $account)) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'accountDeletion' => 'Your account cannot be deleted because it has protected admission records or authored announcements.',
                ]);
            }
        });
    }

    private function isForeignKeyDeletionFailure(QueryException $exception, User $account): bool
    {
        $connection = $account->getConnection();
        $deletePrefix = 'delete from '.$connection->getQueryGrammar()->wrapTable($account->getTable()).' ';

        if (! str_starts_with($exception->getSql(), $deletePrefix)) {
            return false;
        }

        $error = $exception->errorInfo;

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => ($error[0] ?? null) === '23000' && ($error[1] ?? null) === 1451,
            'sqlite' => ($error[0] ?? null) === '23000'
                && in_array($error[1] ?? null, [19, 787, 1811], true)
                && ($error[2] ?? null) === 'FOREIGN KEY constraint failed',
            default => false,
        };
    }
}
