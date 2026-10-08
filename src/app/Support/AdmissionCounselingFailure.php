<?php

namespace App\Support;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class AdmissionCounselingFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function displayMessage(): string
    {
        return match ($this->reason) {
            'disabled' => 'Tư vấn AI chưa được bật hoặc chưa được cấu hình. Bạn vẫn có thể sử dụng các trang tuyển sinh.',
            'limited', 'quota' => 'Đã đạt giới hạn câu hỏi. Vui lòng chờ và thử lại sau.',
            'busy' => 'Một câu hỏi đang được xử lý. Vui lòng chờ trước khi gửi tiếp.',
            'stale' => 'Cuộc trò chuyện đã thay đổi ở tab khác. Vui lòng tải lại trang rồi gửi lại.',
            'changed' => 'Thông tin tuyển sinh vừa thay đổi. Vui lòng gửi lại để nhận thông tin mới nhất.',
            'refused' => 'Không thể trả lời câu hỏi này. Hãy hỏi về ngành, đợt tuyển sinh hoặc thời hạn đăng ký.',
            'invalid' => 'Chưa thể xác minh câu trả lời. Vui lòng diễn đạt lại câu hỏi.',
            'configuration' => 'Dịch vụ tư vấn chưa sẵn sàng. Vui lòng thử lại sau.',
            default => 'Dịch vụ tư vấn tạm thời không phản hồi. Câu hỏi của bạn vẫn được giữ để thử lại.',
        };
    }
}
