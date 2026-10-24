<?php

namespace Database\Seeders;

use App\Actions\CandidateFiles;
use App\Models\AdmissionMethod;
use App\Models\AdmissionProgram;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateExamResult;
use App\Models\CandidateMajorOffering;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\CandidateTranscript;
use App\Models\HighSchool;
use App\Models\Major;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class AdmissionTestingSeeder extends Seeder
{
    public const MARKER = 'admission_testing.v1.seeded';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('AdmissionTestingSeeder chỉ dành cho local/testing.');
        }
        DB::transaction(function (): void {
            $marker = DB::table('activity_logs')->where('action', self::MARKER)->first();
            if ($marker !== null) {
                foreach (json_decode($marker->new_values, true, flags: JSON_THROW_ON_ERROR) as $identity) {
                    if (! User::whereKey($identity['user'])->where('email', $identity['email'])->exists()
                        || ! CandidateProfile::whereKey($identity['profile'])->where('user_id', $identity['user'])->where('candidate_code', $identity['code'])->exists()
                        || ! Application::whereKey($identity['application'])->where('candidate_profile_id', $identity['profile'])->exists()) {
                        throw new RuntimeException('Bộ kiểm thử thiếu hoặc thay đổi định danh; không tự ghi đè.');
                    }
                }

                return;
            }
            if (User::where('email', 'like', 'admission-testing-%@example.test')->exists()
                || CandidateProfile::where('candidate_code', 'like', 'AT-V1-%')->orWhere('citizen_id', 'like', 'TEST-AT-%')->exists()
                || Application::where('application_code', 'like', 'AT-V1-%')->exists()
                || AdmissionRound::where('code', 'like', 'AT-V1-%')->exists()) {
                throw new RuntimeException('Định danh kiểm thử đã được sử dụng; không ghi đè.');
            }
            $password = config('admission_testing.password');
            if (! is_string($password) || strlen($password) < 12) {
                throw new RuntimeException('Cần cấu hình ADMISSION_TESTING_PASSWORD ít nhất 12 ký tự.');
            }
            $schools = HighSchool::with('province')->orderBy('province_id')->orderBy('id')->get()->groupBy('province_id')->map->first()->values();
            if ($schools->isEmpty() || $schools->contains(fn ($school): bool => $school->province === null)) {
                throw new RuntimeException('Cần danh mục tỉnh/trường hợp lệ đã có trong database.');
            }
            $majors = collect(['DEMO-7480201', 'DEMO-7480101', 'DEMO-7340101'])->map(fn ($code) => Major::where('code', $code)->where('is_active', true)->first());
            $methods = collect(['DEMO-THPT-A00', 'DEMO-HB-D01'])->mapWithKeys(fn ($code) => [$code => AdmissionMethod::where('code', $code)->where('is_active', true)->first()]);
            if ($majors->contains(null) || $methods->contains(null)
                || $methods['DEMO-THPT-A00']->score_config !== ['weights' => ['MATH' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1]]
                || $methods['DEMO-HB-D01']->score_config !== ['weights' => ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 2]]) {
                throw new RuntimeException('Thiếu ngành/phương thức DEMO tương thích; không tự sửa danh mục.');
            }
            $hash = Hash::make($password);
            $programs = [];
            foreach (['ENGINE' => 'closed', 'REVIEW' => 'open'] as $kind => $status) {
                $round = AdmissionRound::create(['code' => 'AT-V1-'.$kind, 'name' => 'KIỂM THỬ V1 - '.$kind,
                    'year' => now()->year, 'start_date' => now()->subDays(30),
                    'end_date' => $kind === 'ENGINE' ? now()->subDay() : now()->addDays(365), 'status' => $status]);
                foreach ($majors as $index => $major) {
                    $program = AdmissionProgram::create(['admission_round_id' => $round->id, 'major_id' => $major->id,
                        'admission_method_id' => $methods[$index === 1 ? 'DEMO-HB-D01' : 'DEMO-THPT-A00']->id,
                        'quota' => [10, 20, 35][$index], 'minimum_score' => 18, 'status' => 'active']);
                    CandidateMajorOffering::create(['admission_round_id' => $round->id, 'major_id' => $major->id, 'admission_program_id' => $program->id, 'is_selectable' => true]);
                    $programs[$kind][] = $program;
                }
            }
            $identities = [];
            for ($number = 1; $number <= 100; $number++) {
                $tag = sprintf('%03d', $number);
                $paths = $this->evidence($tag);
                $missing = $number >= 51 && $number <= 65;
                $pending = $number >= 66 && $number <= 80;
                $group = match (true) {
                    $number <= 50 => 'Hợp lệ', $missing => 'Cần bổ sung', $pending => 'Chờ duyệt', $number <= 90 => 'Điểm cao', $number <= 95 => 'Điểm thấp', default => 'Bằng điểm'
                };
                $score = match (true) {
                    $number <= 50 => 6 + $number * .03, $number <= 80 => 7, $number <= 90 => 9 + ($number - 80) * .01, $number <= 95 => 3, default => 8
                };
                $school = $schools[($number - 1) % $schools->count()];
                $user = User::create(['name' => 'Thí sinh kiểm thử '.$tag.' - '.$group, 'email' => 'admission-testing-'.$tag.'@example.test',
                    'password' => $hash, 'role' => 'candidate', 'status' => 'active', 'email_verified_at' => now()]);
                $profile = CandidateProfile::create(['user_id' => $user->id, 'candidate_code' => 'AT-V1-'.$tag,
                    'date_of_birth' => (now()->year - 18).'-01-01', 'gender' => 'male', 'ethnicity' => 'Kinh',
                    'citizen_id' => 'TEST-AT-'.$tag, 'citizen_id_issued_date' => now()->subYears(2)->toDateString(), 'citizen_id_issued_place' => 'DỮ LIỆU KIỂM THỬ',
                    'phone' => '0000000'.$tag, 'address' => $missing ? null : 'Địa chỉ giả lập phục vụ kiểm thử '.$tag,
                    'province_code' => $school->province->code, 'high_school_code' => $school->code, 'high_school_name' => $school->name,
                    'graduation_year' => now()->year, 'priority_area' => 'KV3', 'priority_object' => null,
                    'photo_path' => $paths['candidate-photos'], 'citizen_id_front_path' => $paths['citizen-front'], 'citizen_id_back_path' => $paths['citizen-back'],
                    'profile_status' => $missing ? 'needs_revision' : 'complete']);
                $selected = $programs[$missing || $pending ? 'REVIEW' : 'ENGINE'];
                $application = Application::create(['application_code' => 'AT-V1-'.$tag, 'candidate_profile_id' => $profile->id,
                    'admission_round_id' => $selected[0]->admission_round_id, 'registration_mode' => 'legacy',
                    'status' => $missing ? 'needs_revision' : ($pending ? 'submitted' : 'verified'), 'submitted_at' => now(),
                    'reviewed_at' => $pending ? null : now(), 'revision_reason' => $missing ? 'KIỂM THỬ: bổ sung địa chỉ liên hệ.' : null]);
                if ($number >= 86 && $number <= 90) {
                    $selected = [$selected[1], $selected[0], $selected[2]];
                }
                foreach ($selected as $index => $program) {
                    AdmissionWish::create(['application_id' => $application->id, 'admission_program_id' => $program->id, 'priority' => $index + 1, 'status' => 'pending']);
                }
                CandidateDocument::create(['application_id' => $application->id, 'document_type' => 'transcript', 'original_name' => 'KIEM-THU-HOC-BA.png',
                    'file_path' => $paths['candidate-documents'], 'mime_type' => 'image/png', 'file_size' => Storage::disk(CandidateFiles::DISK)->size($paths['candidate-documents']),
                    'status' => 'verified', 'verified_at' => now()]);
                $exam = CandidateExamResult::create(['candidate_profile_id' => $profile->id, 'exam_type' => 'thpt', 'exam_year' => now()->year,
                    'registration_number' => 'AT-V1-'.$tag, 'evidence_path' => $paths['candidate-exam-results'], 'status' => 'pending']);
                $transcript = CandidateTranscript::create(['candidate_profile_id' => $profile->id, 'school_name' => $school->name,
                    'graduation_year' => now()->year, 'evidence_path' => $paths['candidate-transcripts'], 'status' => 'pending']);
                $transcript->evidenceImages()->create(['path' => $paths['candidate-transcripts'], 'original_name' => 'KIEM-THU.png', 'mime_type' => 'image/png',
                    'size' => Storage::disk(CandidateFiles::DISK)->size($paths['candidate-transcripts']), 'sort_order' => 1]);
                foreach (['thpt' => ['MATH' => 'Toán', 'PHYSICS' => 'Vật lý', 'CHEMISTRY' => 'Hóa học'], 'hoc_ba' => ['MATH' => 'Toán', 'LITERATURE' => 'Ngữ văn', 'ENG' => 'Tiếng Anh']] as $type => $subjects) {
                    foreach ($subjects as $code => $name) {
                        CandidateScore::create(['candidate_profile_id' => $profile->id, 'score_type' => $type, 'subject_code' => $code, 'subject_name' => $name,
                            'score' => $score, 'exam_year' => now()->year, 'verified' => true, 'evidence_path' => $paths[$type.'-'.$code]]);
                        if ($type === 'thpt') {
                            $exam->subjectScores()->create(['subject_code' => $code, 'subject_name' => $name, 'score' => $score]);
                        } else {
                            foreach (['10', '11', '12'] as $grade) {
                                $transcript->scores()->create(['subject_code' => $code, 'subject_name' => $name, 'grade_level' => $grade, 'score' => $score]);
                            }
                        }
                    }
                }
                $identities[] = ['user' => $user->id, 'profile' => $profile->id, 'application' => $application->id, 'email' => $user->email, 'code' => $profile->candidate_code];
            }
            DB::table('activity_logs')->insert(['action' => self::MARKER, 'new_values' => json_encode($identities, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });
    }

    /** @return array<string, string> */
    private function evidence(string $tag): array
    {
        $bytes = file_get_contents(database_path('data/admission-testing.png'));
        if ($bytes === false) {
            throw new RuntimeException('Thiếu ảnh minh chứng kiểm thử.');
        }
        $disk = Storage::disk(CandidateFiles::DISK);
        $paths = [];
        $directories = array_combine(['candidate-photos', 'candidate-documents', 'candidate-exam-results', 'candidate-transcripts'], ['candidate-photos', 'candidate-documents', 'candidate-exam-results', 'candidate-transcripts']);
        $directories += ['citizen-front' => 'candidate-citizen-ids', 'citizen-back' => 'candidate-citizen-ids'];
        foreach (['thpt-MATH', 'thpt-PHYSICS', 'thpt-CHEMISTRY', 'hoc_ba-MATH', 'hoc_ba-LITERATURE', 'hoc_ba-ENG'] as $key) {
            $directories[$key] = 'candidate-scores';
        }
        foreach ($directories as $key => $directory) {
            $path = $directory.'/admission-testing-v1-'.$tag.'-'.$key.'.png';
            if ($disk->exists($path) && $disk->get($path) !== $bytes) {
                throw new RuntimeException('Đường dẫn minh chứng đã có nội dung khác; không ghi đè.');
            }
            $paths[$key] = $path;
        }
        foreach ($paths as $path) {
            if (! $disk->exists($path) && ! $disk->put($path, $bytes, ['visibility' => 'private'])) {
                throw new RuntimeException('Không lưu được ảnh kiểm thử.');
            }
        }

        return $paths;
    }
}
