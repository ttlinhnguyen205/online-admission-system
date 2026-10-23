<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\User;
use App\Notifications\StaffApplicationSubmitted;

class NotifyStaffOfApplicationSubmission
{
    public function handle(Application $application): void
    {
        User::query()
            ->where('role', UserRole::Staff)
            ->where('status', UserStatus::Active)
            ->whereNotNull('email_verified_at')
            ->eachById(function (User $staff) use ($application): void {
                $staff->notify(new StaffApplicationSubmitted($application->id, $application->application_code));
            });
    }
}
