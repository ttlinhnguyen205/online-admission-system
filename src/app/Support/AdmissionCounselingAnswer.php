<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdmissionCounselingAnswer
{
    public const INTENTS = ['majors', 'rounds', 'availability', 'description', 'methods', 'programs', 'tuition', 'clarify', 'unsupported', 'results'];

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['intent', 'template', 'sources', 'fields', 'choices'],
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => self::INTENTS],
                'template' => ['type' => 'string', 'enum' => self::INTENTS],
                'sources' => ['type' => 'array', 'items' => ['type' => 'string']],
                'fields' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['description', 'method', 'program', 'tuition']]],
                'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /** @param array<string, mixed> $plan
     * @param  array<string, mixed>  $context
     * @return array{body: string, route: string|null, choices: list<string>, focus: list<string>, checked_at: string}
     */
    public function render(array $plan, array $context): array
    {
        $validator = Validator::make(['plan' => $plan], [
            'plan' => ['required', 'array:intent,template,sources,fields,choices'],
            'plan.intent' => ['required', Rule::in(self::INTENTS)],
            'plan.template' => ['required', 'same:plan.intent'],
            'plan.sources' => ['present', 'array', 'list', 'max:20'],
            'plan.sources.*' => ['string', 'distinct'],
            'plan.fields' => ['present', 'array', 'list', 'max:1'],
            'plan.fields.*' => [Rule::in(['description', 'method', 'program', 'tuition'])],
            'plan.choices' => ['present', 'array', 'list', 'max:5'],
            'plan.choices.*' => ['string', 'distinct'],
        ]);
        if ($validator->fails()) {
            throw new AdmissionCounselingFailure('invalid');
        }
        $intent = $plan['intent'];
        $field = match ($intent) {
            'description' => 'description', 'methods' => 'method', 'programs' => 'program', 'tuition' => 'tuition',
            default => null,
        };
        if ($plan['fields'] !== ($field === null ? [] : [$field])
            || ($intent !== 'clarify' && $plan['choices'] !== [])
            || (in_array($intent, ['clarify', 'unsupported', 'results'], true) && $plan['sources'] !== [])) {
            throw new AdmissionCounselingFailure('invalid');
        }
        $facts = array_column($context['facts'], null, 'ref');
        foreach ([...$plan['sources'], ...$plan['choices']] as $reference) {
            if (! isset($facts[$reference])) {
                throw new AdmissionCounselingFailure('invalid');
            }
        }
        $selected = array_map(fn (string $reference): array => $facts[$reference], $plan['sources']);
        foreach ($selected as $fact) {
            if (($intent === 'rounds' ? 'round' : 'offering') !== $fact['kind']) {
                throw new AdmissionCounselingFailure('invalid');
            }
        }
        $route = null;
        $choices = [];
        $focus = [];
        if ($intent === 'unsupported') {
            $body = 'Chưa có dữ liệu được phê duyệt để tư vấn nội dung này. Tôi không thể dự đoán khả năng trúng tuyển, cam kết kết quả hoặc đưa ra chính sách chưa được công bố.';
        } elseif ($intent === 'results') {
            $body = 'Điểm và kết quả cá nhân không được gửi đến AI. Hãy mở trang Kết quả xét tuyển để xem thông tin được phép công bố của bạn.';
            $route = 'candidate.results.index';
        } elseif ($intent === 'clarify') {
            if (count($plan['choices']) < 2) {
                throw new AdmissionCounselingFailure('invalid');
            }
            $body = 'Bạn muốn hỏi về ngành hoặc đợt nào? Hãy chọn một mục bên dưới.';
            foreach ($plan['choices'] as $reference) {
                $fact = $facts[$reference];
                $choices[] = $fact['name'].' ('.$fact['code'].')'.(isset($fact['round_code']) ? ' — '.$fact['round_code'] : '');
            }
        } elseif ($selected === []) {
            $body = $context['truncated']
                ? 'Danh mục vượt giới hạn một câu trả lời. Hãy nêu rõ mã ngành hoặc đợt tuyển sinh để tra cứu.'
                : 'Chưa tìm thấy thông tin phù hợp trong danh mục hiện đang mở và được phép công bố. Điều này không có nghĩa trường không đào tạo ngành đó. Hãy kiểm tra lại tên ngành hoặc thời điểm đăng ký.';
        } else {
            $lines = ['Thông tin trong danh mục hiện đang nhận đăng ký:'];
            $answerTruncated = false;
            foreach ($selected as $fact) {
                $label = $fact['name'].' ('.$fact['code'].')';
                $details = [($fact['demo'] ? '[DỮ LIỆU DEMO — không phải thông báo chính thức] ' : '').$label];
                if ($fact['kind'] === 'offering') {
                    $details[] = 'Đợt: '.$fact['round'].' ('.$fact['round_code'].').';
                }
                if ($field !== null) {
                    $details[] = match (true) {
                        ! isset($fact[$field]) => 'Thông tin này chưa được phê duyệt hoặc chưa đủ dữ liệu để công bố cho thí sinh.',
                        $field === 'tuition' => $this->tuition($fact['tuition'], $fact['demo']),
                        default => $fact[$field],
                    };
                } else {
                    $details[] = 'Thời gian đăng ký: '.$fact['start'].' đến '.$fact['end'].' ('.$context['timezone'].').';
                }
                if (strlen(implode("\n\n", [...$lines, ...$details])) > 8000) {
                    $answerTruncated = true;
                    break;
                }
                $focus[] = $label.(isset($fact['round_code']) ? ' — '.$fact['round'].' ('.$fact['round_code'].')' : '');
                array_push($lines, ...$details);
            }
            $lines[] = 'Khả dụng tại thời điểm tra cứu; không phải cam kết trúng tuyển hoặc số chỗ còn lại. Hồ sơ vẫn phải đáp ứng kiểm tra khi nộp.';
            if ($context['truncated'] || $answerTruncated) {
                $lines[] = 'Đây là một phần danh mục. Hãy nêu rõ ngành hoặc đợt để thu hẹp kết quả.';
            }
            $body = implode("\n\n", $lines);
            $route = 'candidate.applications.index';
        }

        return ['body' => $body, 'route' => $route, 'choices' => $choices, 'focus' => array_values(array_unique($focus)), 'checked_at' => $context['checked_at']];
    }

    /** @param array{amount: string, currency: string, period: string, basis: string} $fee */
    private function tuition(array $fee, bool $demo): string
    {
        $period = match ($fee['period']) {
            'semester' => 'học kỳ', 'year' => 'năm học', 'credit' => 'tín chỉ', 'course' => 'khóa học',
            default => throw new AdmissionCounselingFailure('invalid'),
        };

        return ($demo ? 'Học phí minh họa: ' : 'Học phí được phê duyệt công bố: ')
            .$fee['amount'].' '.$fee['currency'].' / '.$period
            .($fee['basis'] === 'default' ? ' (mức mặc định của ngành, không phải báo giá riêng cho đợt).' : '.')
            .' Không suy ra miễn học phí hoặc học bổng từ giá trị bằng 0.';
    }
}
