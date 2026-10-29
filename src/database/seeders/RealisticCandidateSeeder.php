<?php

namespace Database\Seeders;

use App\Actions\AdmissionCatalogLock;
use App\Actions\CandidateApplications;
use App\Actions\CandidateFiles;
use App\Models\AdmissionRound;
use App\Models\AdmissionWish;
use App\Models\Application;
use App\Models\CandidateDocument;
use App\Models\CandidateExamResult;
use App\Models\CandidateProfile;
use App\Models\CandidateScore;
use App\Models\CandidateTranscript;
use App\Models\HighSchool;
use App\Models\Province;
use App\Models\User;
use App\Support\RealisticCandidateEvidence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RealisticCandidateSeeder extends Seeder
{
    public const MARKER = 'realistic_candidates.v1.seeded';

    public const WATERMARK = 'DỮ LIỆU MẪU – KHÔNG CÓ GIÁ TRỊ PHÁP LÝ';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('RealisticCandidateSeeder chỉ dành cho local/testing.');
        }
        $createdFiles = [];
        try {
            DB::transaction(function () use (&$createdFiles): void {
                AdmissionCatalogLock::acquire();
                $marker = DB::table('activity_logs')->where('action', self::MARKER)->first();
                if ($marker !== null) {
                    $identities = json_decode($marker->new_values, true, flags: JSON_THROW_ON_ERROR);
                    if (count($identities) !== 100) {
                        throw new RuntimeException('Dấu hoàn tất RC26 không hợp lệ.');
                    }
                    foreach ($identities as $identity) {
                        if (! User::whereKey($identity['user'])->where('email', $identity['email'])->exists()
                            || ! CandidateProfile::whereKey($identity['profile'])->where('user_id', $identity['user'])->where('candidate_code', $identity['code'])->exists()
                            || ! Application::whereKey($identity['application'])->where('candidate_profile_id', $identity['profile'])->where('admission_round_id', $identity['round'])->exists()) {
                            throw new RuntimeException('Dữ liệu RC26 thiếu hoặc đổi định danh; không tự khôi phục.');
                        }
                    }

                    return;
                }
                if (User::where('email', 'like', 'realistic-candidate-%@example.test')->exists()
                    || CandidateProfile::where('candidate_code', 'like', 'RC26-%')->orWhere('citizen_id', 'like', 'RC26-%')->exists()
                    || Application::where('application_code', 'like', 'RC26-%')->exists()) {
                    throw new RuntimeException('Định danh RC26 đã được sử dụng; không ghi đè.');
                }
                $password = config('realistic_candidate.password');
                if (! is_string($password) || strlen($password) < 12) {
                    throw new RuntimeException('Cần REALISTIC_CANDIDATE_PASSWORD ít nhất 12 ký tự.');
                }
                $rounds = AdmissionRound::whereIn('code', ['RAC26-ENGINE', 'RAC26-REGISTER'])->with(['programs.major', 'programs.admissionMethod', 'candidateMajorOfferings'])->get()->keyBy('code');
                if ($rounds->count() !== 2 || $rounds['RAC26-ENGINE']->getRawOriginal('status') !== 'closed'
                    || ! CandidateApplications::roundIsOpen($rounds['RAC26-REGISTER']) || $rounds['RAC26-ENGINE']->applications()->exists()) {
                    throw new RuntimeException('Cần ENGINE đã đóng và chưa có hồ sơ, REGISTER đang trong thời gian mở. Không tự đổi trạng thái đợt.');
                }
                foreach ($rounds as $round) {
                    if ($round->year !== 2026 || $round->nativeRegistrationState() !== 'legacy' || $round->programs->count() !== 15) {
                        throw new RuntimeException('Danh mục RAC26 không khớp bộ 15 chương trình legacy năm 2026.');
                    }
                    foreach ($round->programs as $program) {
                        $code = $program->admissionMethod?->code;
                        $weights = match ($code) {
                            'DEMO-THPT-A00' => ['MATH' => 1, 'PHYSICS' => 1, 'CHEMISTRY' => 1],
                            'DEMO-HB-D01' => ['MATH' => 1, 'LITERATURE' => 1, 'ENG' => 2],
                            default => null,
                        };
                        $offering = $round->candidateMajorOfferings->firstWhere('admission_program_id', $program->id);
                        if ($weights === null || ! $program->admissionMethod->is_active || $program->admissionMethod->score_config != ['weights' => $weights]
                            || config('admission_engine.methods.'.$code.'.type') !== 'weighted'
                            || config('admission_engine.methods.'.$code.'.subjects') !== array_keys($weights)
                            || config('admission_engine.methods.'.$code.'.score_type') !== ($code === 'DEMO-THPT-A00' ? 'thpt' : 'hoc_ba')
                            || $program->status !== 'active' || ! $program->major?->is_active || ! str_starts_with($program->major->code, 'RAC26-')
                            || (float) $program->minimum_score <= 16 || $offering === null || $offering->major_id !== $program->major_id
                            || ($round->code === 'RAC26-REGISTER' && ! $offering->is_selectable)) {
                            throw new RuntimeException('Chương trình RAC26 không tương thích; không tự chỉnh danh mục.');
                        }
                    }
                }
                $provinces = Province::orderBy('code')->get();
                $schools = HighSchool::orderBy('code')->orderBy('id')->get()->groupBy('province_id');
                if ($provinces->count() !== 34 || $schools->flatten()->count() !== 2784 || $provinces->contains(fn ($province) => ! isset($schools[$province->id]))) {
                    throw new RuntimeException('Cần danh mục 34 tỉnh/thành và 2.784 trường THPT hiện có.');
                }
                $records = [];
                for ($number = 1; $number <= 100; $number++) {
                    $province = $provinces[($number - 1) % 34];
                    $localSchools = $schools[$province->id];
                    $school = $localSchools[(intdiv($number - 1, 34) * 7) % $localSchools->count()];
                    $records[] = $this->record($number, $province->code, $province->name, $school->code, $school->name);
                }
                $recordsByCode = array_column($records, null, 'code');
                $passwordHash = Hash::make($password);
                $identities = [];
                foreach (app(RealisticCandidateEvidence::class)->render($records) as $code => $images) {
                    $record = $recordsByCode[$code];
                    $number = $record['number'];
                    $verified = $number <= 65;
                    $missing = $number >= 66 && $number <= 80;
                    $round = $rounds[$number <= 70 ? 'RAC26-ENGINE' : 'RAC26-REGISTER'];
                    $time = $number <= 70 ? $round->end_date->copy()->subHour() : now();
                    $photo = $this->store($record['code'], 'candidate-photos', 'profile', $images['profile'], $createdFiles);
                    $front = $this->store($record['code'], 'candidate-citizen-ids', 'front', $images['profile'], $createdFiles);
                    $back = $this->store($record['code'], 'candidate-citizen-ids', 'back', $images['profile'], $createdFiles);
                    $user = User::create(['name' => $record['name'], 'email' => sprintf('realistic-candidate-%03d@example.test', $number),
                        'password' => $passwordHash, 'role' => 'candidate', 'status' => 'active', 'email_verified_at' => $time]);
                    $profile = CandidateProfile::create(['user_id' => $user->id, 'candidate_code' => $record['code'], 'date_of_birth' => $record['birth'],
                        'gender' => $record['gender'], 'ethnicity' => 'Kinh', 'citizen_id' => $record['code'].'-ID',
                        'citizen_id_issued_date' => '2024-06-01', 'citizen_id_issued_place' => 'Định danh kiểm thử, không phải CCCD',
                        'phone' => sprintf('0000000%03d', $number), 'address' => $missing ? null : 'Địa chỉ mô phỏng '.$number.', Khu dân cư mẫu, '.$record['province'],
                        'province_code' => $record['province_code'], 'high_school_code' => $record['school_code'], 'high_school_name' => $record['school'],
                        'graduation_year' => 2026, 'priority_area' => 'KV3', 'priority_object' => null,
                        'photo_path' => $photo, 'citizen_id_front_path' => $front, 'citizen_id_back_path' => $back,
                        'profile_status' => $verified ? 'verified' : ($missing ? ($number <= 70 ? 'incomplete' : 'needs_revision') : 'complete'),
                        'verified_at' => $verified ? $time : null, 'verification_note' => $verified ? 'Trạng thái xác minh giả lập để kiểm thử; không phải xác nhận của nhân viên.' : null]);
                    $status = $verified ? 'verified' : ($number <= 70 ? 'draft' : ($number <= 80 ? 'needs_revision' : 'submitted'));
                    $application = Application::create(['application_code' => $record['code'], 'candidate_profile_id' => $profile->id,
                        'admission_round_id' => $round->id, 'registration_mode' => 'legacy', 'status' => $status,
                        'submitted_at' => $status === 'draft' ? null : $time, 'reviewed_at' => in_array($status, ['verified', 'needs_revision'], true) ? $time : null,
                        'revision_reason' => $status === 'needs_revision' ? 'Dữ liệu mẫu: bổ sung địa chỉ và điểm Hóa học cùng minh chứng.' : null]);
                    $programs = $round->programs->keyBy('major.code');
                    foreach ($record['wishes'] as $index => $code) {
                        $program = $programs->get('RAC26-'.$code) ?? throw new RuntimeException('Thiếu chương trình ngành '.$code);
                        AdmissionWish::create(['application_id' => $application->id, 'admission_program_id' => $program->id,
                            'candidate_major_offering_id' => $round->candidateMajorOfferings->firstWhere('admission_program_id', $program->id)->id,
                            'priority' => $index + 1, 'status' => 'pending']);
                    }
                    foreach (['exam', 'transcript'] as $kind) {
                        $path = $this->store($record['code'], 'candidate-documents', $kind, $images[$kind], $createdFiles);
                        CandidateDocument::create(['application_id' => $application->id, 'document_type' => $kind === 'exam' ? 'exam_result' : 'transcript',
                            'original_name' => $record['code'].'-'.$kind.'-DU-LIEU-MAU.png', 'file_path' => $path, 'mime_type' => 'image/png',
                            'file_size' => strlen($images[$kind]), 'status' => $verified ? 'verified' : 'pending', 'verified_at' => $verified ? $time : null]);
                    }
                    $exam = CandidateExamResult::create(['candidate_profile_id' => $profile->id, 'exam_type' => 'thpt', 'exam_year' => 2026,
                        'registration_number' => $record['code'], 'evidence_path' => $this->store($record['code'], 'candidate-exam-results', 'exam', $images['exam'], $createdFiles),
                        'status' => $verified ? 'verified' : 'pending', 'verified_at' => $verified ? $time : null]);
                    $transcriptPath = $this->store($record['code'], 'candidate-transcripts', 'transcript', $images['transcript'], $createdFiles);
                    $transcript = CandidateTranscript::create(['candidate_profile_id' => $profile->id, 'school_name' => $record['school'], 'graduation_year' => 2026,
                        'evidence_path' => $transcriptPath, 'status' => $verified ? 'verified' : 'pending', 'verified_at' => $verified ? $time : null]);
                    $transcript->evidenceImages()->create(['path' => $transcriptPath, 'original_name' => $record['code'].'-hoc-ba.png', 'mime_type' => 'image/png', 'size' => strlen($images['transcript']), 'sort_order' => 1]);
                    foreach (['thpt' => ['MATH', 'PHYSICS', 'CHEMISTRY'], 'hoc_ba' => ['MATH', 'LITERATURE', 'ENG']] as $type => $subjects) {
                        foreach ($subjects as $subject) {
                            if (! isset($record['scores'][$subject])) {
                                continue;
                            }
                            $score = $record['scores'][$subject];
                            $name = config('admission_data.subjects.'.$subject);
                            $kind = $type === 'thpt' ? 'exam' : 'transcript';
                            CandidateScore::create(['candidate_profile_id' => $profile->id, 'score_type' => $type, 'subject_code' => $subject, 'subject_name' => $name,
                                'score' => $score, 'exam_year' => 2026, 'verified' => $verified,
                                'evidence_path' => $this->store($record['code'], 'candidate-scores', $type.'-'.$subject, $images[$kind], $createdFiles)]);
                            if ($type === 'thpt') {
                                $exam->subjectScores()->create(['subject_code' => $subject, 'subject_name' => $name, 'score' => $score]);
                            } else {
                                foreach (['10', '11', '12'] as $grade) {
                                    $transcript->scores()->create(['subject_code' => $subject, 'subject_name' => $name, 'grade_level' => $grade, 'score' => $score]);
                                }
                            }
                        }
                    }
                    $identities[] = ['user' => $user->id, 'email' => $user->email, 'profile' => $profile->id, 'code' => $record['code'], 'application' => $application->id, 'round' => $round->id];
                }
                DB::table('activity_logs')->insert(['action' => self::MARKER, 'new_values' => json_encode($identities, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            });
        } catch (Throwable $exception) {
            foreach ($createdFiles as $path) {
                Storage::disk(CandidateFiles::DISK)->delete($path);
            }
            throw $exception;
        }
    }

    /** @param list<string> $createdFiles */
    private function store(string $code, string $directory, string $kind, string $bytes, array &$createdFiles): string
    {
        $path = $directory.'/'.$code.'-'.$kind.'.png';
        $disk = Storage::disk(CandidateFiles::DISK);
        if ($disk->exists($path)) {
            if ($disk->get($path) !== $bytes) {
                throw new RuntimeException('Minh chứng RC26 đã có nội dung khác; không ghi đè.');
            }
        } else {
            $createdFiles[] = $path;
            if (! $disk->put($path, $bytes, ['visibility' => 'private'])) {
                throw new RuntimeException('Không lưu được minh chứng RC26.');
            }
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function record(int $number, string $provinceCode, string $province, string $schoolCode, string $school): array
    {
        $surnames = ['Nguyễn', 'Trần', 'Lê', 'Phạm', 'Hoàng', 'Huỳnh', 'Vũ', 'Đặng', 'Bùi', 'Đỗ'];
        $names = ['Minh Quân', 'Đức Anh', 'Gia Huy', 'Hoàng Nam', 'Tuấn Kiệt', 'Ngọc Anh', 'Thu Hà', 'Khánh Linh', 'Phương Thảo', 'Minh Châu'];
        $all = ['7480201', '7480101', '7480103', '7480107', '7480202', '7340101', '7340301', '7340201', '7510605', '7340115', '7220201', '7340122', '7340120', '7460108', '7340205'];
        $popular = ['7480201', '7480101', '7480103', '7510605', '7340115'];
        $wishes = array_slice(array_values(array_unique([$popular[($number - 1) % 5], $all[($number + 4) % 15], $all[($number + 9) % 15], ...$all])), 0, 3);
        $base = match (true) {
            $number <= 50 => 610 + $number * 3, $number <= 60 => 850 + ($number - 50) * 5, $number <= 65 => 375, default => 680 + ($number - 66) * 4
        };
        $scores = [];
        foreach (['MATH' => 12, 'PHYSICS' => -6, 'CHEMISTRY' => 3, 'LITERATURE' => -15, 'ENG' => 8] as $subject => $offset) {
            if ($subject === 'CHEMISTRY' && $number >= 66 && $number <= 80) {
                continue;
            }
            $scores[$subject] = number_format(($base + $offset) / 100, 2, '.', '');
        }
        $examRows = [];
        foreach (['MATH', 'PHYSICS', 'CHEMISTRY'] as $subject) {
            $examRows[] = ['label' => config('admission_data.subjects.'.$subject), 'value' => $scores[$subject] ?? 'CHƯA CÓ'];
        }
        $transcriptRows = [];
        foreach (['10', '11', '12'] as $grade) {
            foreach (['MATH', 'LITERATURE', 'ENG'] as $subject) {
                $transcriptRows[] = ['label' => 'Lớp '.$grade.' · '.config('admission_data.subjects.'.$subject), 'value' => $scores[$subject]];
            }
        }

        return ['number' => $number, 'code' => sprintf('RC26-%03d', $number), 'name' => $surnames[intdiv($number - 1, 10)].' '.$names[($number - 1) % 10],
            'birth' => sprintf('%d-%02d-%02d', 2007 + $number % 2, 1 + $number % 12, 1 + $number % 28), 'gender' => ($number - 1) % 10 < 5 ? 'male' : 'female',
            'province_code' => $provinceCode, 'province' => $province, 'school_code' => $schoolCode, 'school' => $school,
            'scores' => $scores, 'wishes' => $wishes, 'variant' => $number, 'examRows' => $examRows, 'transcriptRows' => $transcriptRows,
            'examTotal' => isset($scores['CHEMISTRY']) ? 'Tổng A00: '.number_format($scores['MATH'] + $scores['PHYSICS'] + $scores['CHEMISTRY'], 2).' / 30' : 'Chưa đủ dữ liệu tính tổng A00',
            'transcriptTotal' => 'D01: Toán + Văn + 2 × Anh = '.number_format($scores['MATH'] + $scores['LITERATURE'] + 2 * $scores['ENG'], 2).' / 40',
            'labels' => ['brand' => 'HỒ SƠ TUYỂN SINH · BẢN MINH HỌA 2026', 'profile' => 'THẺ HỒ SƠ MÔ PHỎNG', 'exam' => 'BẢNG ĐIỂM THI THPT MẪU', 'transcript' => 'BẢNG HỌC BẠ THPT MẪU',
                'profileNote' => 'Ảnh đại diện minh họa. Định danh kiểm thử, không phải CCCD hoặc giấy tờ do cơ quan nhà nước cấp.',
                'note' => 'Số liệu giả lập phục vụ kiểm thử phần mềm. Điểm từng môn theo thang 10. Không có chữ ký, dấu xác nhận hay mã xác thực.', 'watermark' => self::WATERMARK]];
    }
}
