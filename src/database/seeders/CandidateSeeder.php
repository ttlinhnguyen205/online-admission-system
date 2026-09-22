<?php

namespace Database\Seeders;

use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\CandidateProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class CandidateSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => 'candidate@example.com'],
            [
                'name' => 'Demo Candidate',
                'password' => 'password',
                'email_verified_at' => now(),
                'role' => UserRole::Candidate,
                'status' => UserStatus::Active,
            ],
        );

        CandidateProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'candidate_code' => 'CANDIDATE-DEMO-001',
                'date_of_birth' => '2007-05-15',
                'gender' => 'male',
                'ethnicity' => 'Kinh',
                'religion' => 'Không',
                'citizen_id' => '001207000001',
                'citizen_id_issued_date' => '2022-05-15',
                'citizen_id_issued_place' => 'Cục Cảnh sát quản lý hành chính về trật tự xã hội',
                'phone' => '0900000001',
                'address' => 'Hà Nội',
                'high_school_name' => 'Trường THPT Demo',
                'graduation_year' => 2026,
                'profile_status' => ProfileStatus::Complete,
            ],
        );
    }
}
