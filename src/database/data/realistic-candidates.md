# Hồ sơ giả lập RC26

Seeder chỉ thêm dữ liệu trong môi trường `local` hoặc `testing`. Không được gọi từ DatabaseSeeder.

## Điều kiện chạy

- Có 34 tỉnh và 2.784 trường, danh mục RAC26 đủ 15 chương trình mỗi đợt.
- RAC26-ENGINE ở trạng thái closed và chưa có đơn; RAC26-REGISTER đang mở trong thời gian đăng ký. Seeder không đổi lịch hoặc trạng thái đợt.
- Python 3 có Pillow và font Segoe UI (Windows) hoặc DejaVu Sans (Linux). Đặt `REALISTIC_CANDIDATE_PYTHON` là đường dẫn Python nếu không dùng lệnh `python` mặc định. Kiểm tra bằng `python -c "import PIL; print(PIL.__version__)"` với đúng executable đó.
- Đặt `REALISTIC_CANDIDATE_PASSWORD` trong môi trường hoặc `.env` local, ít nhất 12 ký tự. Không commit mật khẩu. Nếu dùng config cache, cập nhật cấu hình cache theo quy trình local trước khi chạy.

Từ thư mục `src`, sau khi tự kiểm tra đúng kết nối local:

```sh
php artisan db:seed --class=RealisticCandidateSeeder
```

## Phân bổ và kiểm tra

- RC26-001–065: ENGINE, verified; 051–060 điểm cao; 061–065 đồng điểm dưới ngưỡng.
- RC26-066–070: ENGINE, draft, thiếu địa chỉ và điểm Hóa; engine bỏ qua.
- RC26-071–080: REGISTER, needs_revision, thiếu địa chỉ và điểm Hóa.
- RC26-081–100: REGISTER, submitted, chờ Staff duyệt.

Email `realistic-candidate-001@example.test` đến `realistic-candidate-100@example.test`. Các định danh RC26 và số liên hệ bắt đầu 000 là dữ liệu kiểm thử, không phải định danh công dân hợp lệ. Ảnh thẻ/giấy tờ là bảng mô phỏng có watermark, không phải ảnh căn cước thật.

Candidate: đăng nhập tài khoản 081 để xem hồ sơ chờ duyệt; 071 để xem yêu cầu bổ sung. Staff: mở RC26-081, xem ảnh điểm và tải minh chứng, bắt đầu kiểm tra và duyệt theo quy trình hiện có. Admin: xem trước ENGINE phải có 65 verified, 5 draft, không có blocker trên dữ liệu nguyên bản. Chỉ chạy xét tuyển trên đợt kiểm thử này khi chủ động muốn tạo kết quả. Với danh mục nguyên bản, kỳ vọng 195 kết quả nguyện vọng và 40 thí sinh trúng tuyển.

Seeder chỉ dùng hai phương thức legacy có thuật toán thật: DEMO-THPT-A00 (30 điểm), DEMO-HB-D01 (40 điểm, tiếng Anh hệ số 2). Không tạo đơn PLAN hoặc dùng phương thức chưa hỗ trợ.

Chạy lại kiểm tra marker và định danh rồi bỏ qua; không đặt lại mật khẩu, trạng thái, điểm, kết quả hay sửa dữ liệu đã được xử lý. Nếu định danh xung đột/thiếu dữ liệu, dừng để kiểm tra thủ công. Transaction rollback các bản ghi của lần chạy lỗi và chỉ dọn các file mới do lần đó tạo; giữ file đã tồn tại.

## Số bản ghi mới dự kiến

| Bảng | Số lượng |
|---|---:|
| users | 100 |
| candidate_profiles | 100 |
| applications | 100 |
| admission_wishes | 300 |
| candidate_scores | 585 |
| candidate_documents | 200 |
| candidate_exam_results | 100 |
| candidate_exam_subject_scores | 285 |
| candidate_transcripts | 100 |
| candidate_transcript_scores | 900 |
| candidate_transcript_evidence | 100 |
| activity_logs | 1 |

Không thêm admission_results trước khi chạy engine. 300 ảnh có nội dung riêng được lưu thành 1.285 file private theo từng tài nguyên, tránh việc sửa/xóa minh chứng của một tài nguyên ảnh hưởng tài nguyên khác. Các lớp học ghi chuỗi `'10'`, `'11'`, `'12'` để tương thích MySQL ENUM.
