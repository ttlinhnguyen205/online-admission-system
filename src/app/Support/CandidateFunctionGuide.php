<?php

namespace App\Support;

use Illuminate\Support\Str;

class CandidateFunctionGuide
{
    private const PAGES = [
        'overview' => [
            'label' => 'Trang chủ', 'route' => 'dashboard',
            'question' => 'Tôi cần làm gì để bắt đầu đăng ký xét tuyển?',
            'body' => "Bạn có thể thực hiện lần lượt:\n1. Mở Hồ sơ cá nhân, điền thông tin bắt buộc và lưu.\n2. Khai báo thông tin tuyển sinh, điểm và minh chứng phù hợp với phương thức xét tuyển.\n3. Mở Đăng ký nguyện vọng, chọn đợt đang nhận hồ sơ, thêm và sắp xếp nguyện vọng.\n4. Kiểm tra thông tin rồi nộp hồ sơ. Theo dõi Thông báo để biết yêu cầu bổ sung và Kết quả xét tuyển khi được công bố.",
        ],
        'profile' => [
            'label' => 'Hồ sơ cá nhân', 'route' => 'candidate.profile.edit',
            'question' => 'Làm thế nào để hoàn thiện hồ sơ cá nhân?',
            'body' => "Tại Hồ sơ cá nhân, điền thông tin định danh, liên hệ, trường THPT và các trường bắt buộc, sau đó lưu hồ sơ.\nẢnh chân dung dùng JPG hoặc PNG, tỷ lệ 3:4, tối thiểu 300 × 400 pixel và tối đa 2 MB.\nNếu không lưu được, xem thông báo ngay dưới ô nhập và bổ sung hoặc sửa thông tin được yêu cầu. Việc lưu hồ sơ cá nhân chưa thay thế thao tác nộp hồ sơ xét tuyển.",
        ],
        'information' => [
            'label' => 'Thông tin tuyển sinh', 'route' => 'candidate.admission-information.index',
            'question' => 'Tôi khai báo học bạ và chứng chỉ như thế nào?',
            'body' => "Mở đúng phần thông tin cần khai báo: điểm thi, học bạ, chứng chỉ hoặc thông tin ưu tiên. Nhập các trường được yêu cầu, chọn minh chứng tương ứng và lưu.\nVới học bạ, kiểm tra năm tốt nghiệp và điểm từng môn, từng lớp. Với chứng chỉ, kiểm tra loại, điểm, ngày cấp và ngày hết hạn nếu có.\nThông tin tự khai chưa đồng nghĩa đã được xác minh hoặc đủ điều kiện trúng tuyển. Nếu dữ liệu đã bị khóa hoặc đang được xử lý, làm theo thông báo của hệ thống.",
        ],
        'scores' => [
            'label' => 'Điểm và minh chứng', 'route' => 'candidate.scores.index',
            'question' => 'Làm thế nào để thêm điểm và minh chứng?',
            'body' => "Ở Điểm và minh chứng, chọn thêm điểm, khai báo loại điểm, môn học, năm thi và điểm số, rồi đính kèm minh chứng theo yêu cầu. Kiểm tra thông báo dưới từng trường trước khi lưu.\nNếu khai báo điểm THPT, học bạ hoặc chứng chỉ ở Thông tin tuyển sinh, hãy dùng đúng phần tương ứng. Điểm tự khai cần được kiểm tra; trợ lý không sửa điểm hoặc xác minh thay cán bộ.",
        ],
        'applications' => [
            'label' => 'Đăng ký nguyện vọng', 'route' => 'candidate.applications.index',
            'question' => 'Làm thế nào để thêm, sắp xếp và nộp nguyện vọng?',
            'body' => "Mở Đăng ký nguyện vọng và tạo hồ sơ cho đợt đang nhận đăng ký. Mở hồ sơ đó, thêm ngành hoặc chương trình được phép chọn, sắp xếp thứ tự ưu tiên và lưu các thay đổi.\nTrước khi nộp, hoàn thiện hồ sơ cá nhân và kiểm tra các thông tin, điểm, minh chứng được yêu cầu. Đọc phần cảnh báo nếu nút nộp chưa khả dụng.\nHồ sơ nháp chưa được coi là đã nộp. Sau khi nộp, theo dõi trạng thái và yêu cầu bổ sung. Việc chỉnh sửa phụ thuộc trạng thái hồ sơ và thời hạn của đợt.",
        ],
        'documents' => [
            'label' => 'Tài liệu hồ sơ', 'route' => 'candidate.documents.index',
            'question' => 'Làm thế nào để tải minh chứng lên hồ sơ?',
            'body' => "Mở Tài liệu hồ sơ, chọn đúng hồ sơ xét tuyển, loại tài liệu và tệp cần đính kèm, rồi bấm Lưu tài liệu. Tệp minh chứng hỗ trợ PDF, JPG, JPEG hoặc PNG, tối đa 10 MB.\nKiểm tra nội dung, chất lượng ảnh và đúng loại giấy tờ trước khi nộp. Nếu không tải được, xem thông báo về định dạng, dung lượng hoặc trạng thái hồ sơ. Hồ sơ đã khóa không thể tự chỉnh sửa tùy ý.",
        ],
        'results' => [
            'label' => 'Kết quả xét tuyển', 'route' => 'candidate.results.index',
            'question' => 'Tôi xem kết quả và xác nhận nhập học ở đâu?',
            'body' => "Mở Kết quả xét tuyển để xem kết quả của chính bạn sau khi được công bố. Khi có kết quả trúng tuyển và đủ điều kiện thao tác, hệ thống sẽ hiển thị nút Xác nhận nhập học.\nNếu chưa có kết quả, hãy theo dõi thông báo và thời điểm công bố của đợt. Hồ sơ đã được xác minh chưa đồng nghĩa trúng tuyển. Trợ lý không dự đoán, xác nhận hoặc thay đổi kết quả thay bạn.",
        ],
        'notifications' => [
            'label' => 'Thông báo', 'route' => 'candidate.notifications.index',
            'question' => 'Tôi xem yêu cầu bổ sung hồ sơ ở đâu?',
            'body' => "Mở Thông báo để xem tin liên quan đến hồ sơ của bạn. Đọc nội dung yêu cầu bổ sung và mở hồ sơ được nhắc đến để xử lý theo hướng dẫn.\nBạn có thể đánh dấu thông báo đã đọc sau khi xem. Đánh dấu đã đọc không tự bổ sung hồ sơ và không thay đổi kết quả xét tuyển.",
        ],
        'news' => [
            'label' => 'Tin tức', 'route' => 'news',
            'question' => 'Làm thế nào để xem tin tuyển sinh?',
            'body' => 'Ở Tin tức, chọn nhóm Tin nhà trường hoặc Tin tuyển sinh, sau đó chọn bài viết để xem nội dung đầy đủ. Thông báo riêng về hồ sơ của bạn nằm trong mục Thông báo.',
        ],
        'account' => [
            'label' => 'Cài đặt tài khoản', 'route' => 'profile.edit',
            'question' => 'Tôi thay đổi thông tin tài khoản ở đâu?',
            'body' => 'Mở menu tài khoản và chọn phần cài đặt tương ứng: thông tin tài khoản, bảo mật hoặc giao diện. Thông tin hồ sơ xét tuyển được quản lý riêng tại Hồ sơ cá nhân. Không gửi mật khẩu, mã xác thực hoặc giấy tờ cá nhân vào cuộc trò chuyện.',
        ],
    ];

    public function screen(?string $route): string
    {
        return match ($route) {
            'candidate.profile.edit' => 'profile',
            'candidate.admission-information.index' => 'information',
            'candidate.scores.index' => 'scores',
            'candidate.applications.index', 'candidate.applications.show' => 'applications',
            'candidate.documents.index', 'candidate.applications.documents.index' => 'documents',
            'candidate.results.index' => 'results',
            'candidate.notifications.index' => 'notifications',
            'news', 'news.show' => 'news',
            'profile.edit', 'appearance.edit', 'security.edit', 'language.edit' => 'account',
            default => 'overview',
        };
    }

    public function label(string $screen): string
    {
        return $this->page($screen)['label'];
    }

    /** @return list<string> */
    public function suggestions(string $screen): array
    {
        return [
            'Trang này dùng để làm gì?',
            $this->page($screen)['question'],
            'Hiện có những ngành nào đang nhận đăng ký xét tuyển?',
            'Thời hạn đăng ký xét tuyển của đợt hiện tại là khi nào?',
            'Tôi có thể xem kết quả xét tuyển ở đâu?',
        ];
    }

    /** @return array{body: string, route: string, route_label: string, choices: list<string>, focus: list<string>, checked_at: string, source: string}|null */
    public function answer(string $question, string $screen): ?array
    {
        $normalized = Str::lower(Str::ascii($question));
        if (preg_match('/hoc phi|chi tieu|thoi han|han dang ky|diem chuan|nganh nao/', $normalized) === 1) {
            return null;
        }
        if (preg_match('/trang nay|man hinh nay|chuc nang nay|nut nay|huong dan su dung|bat dau tu dau|bat dau dang ky/', $normalized) !== 1) {
            if (preg_match('/lam sao|lam the nao|cach |huong dan|o dau|the nao|la gi|tai sao|vi sao|can lam|can nhap|can khai|khong the|khong .*duoc|bi loi|them |sua |xoa |cap nhat|tai len|sap xep/', $normalized) !== 1) {
                return null;
            }
            $screen = match (true) {
                preg_match('/ket qua|nhap hoc/', $normalized) === 1 => 'results',
                preg_match('/thong bao|yeu cau bo sung|bo sung ho so/', $normalized) === 1 => 'notifications',
                preg_match('/ho so ca nhan|thong tin ca nhan|hoan thien ho so|chan dung|can cuoc/', $normalized) === 1 => 'profile',
                preg_match('/nguyen vong|nop ho so|dang ky xet tuyen|chon nganh|dang ky nganh/', $normalized) === 1 => 'applications',
                preg_match('/hoc ba|chung chi|uu tien|thong tin tuyen sinh/', $normalized) === 1 => 'information',
                preg_match('/minh chung|tai lieu|tep|file/', $normalized) === 1 => in_array($screen, ['scores', 'information'], true) ? $screen : 'documents',
                preg_match('/diem thi|them diem|sua diem/', $normalized) === 1 => $screen === 'information' ? 'information' : 'scores',
                preg_match('/tai khoan|mat khau|giao dien/', $normalized) === 1 => 'account',
                preg_match('/tin tuc|tin tuyen sinh/', $normalized) === 1 => 'news',
                default => null,
            };
            if ($screen === null) {
                return null;
            }
        }
        $page = $this->page($screen);

        return [
            'body' => $page['body'], 'route' => $page['route'], 'route_label' => 'Mở '.$page['label'],
            'choices' => [], 'focus' => [], 'checked_at' => now(config('app.timezone'))->format('d/m/Y H:i'),
            'source' => 'Hướng dẫn sử dụng hệ thống. Cập nhật lúc',
        ];
    }

    /** @return array{label: string, route: string, question: string, body: string} */
    private function page(string $screen): array
    {
        return self::PAGES[$screen] ?? self::PAGES['overview'];
    }
}
