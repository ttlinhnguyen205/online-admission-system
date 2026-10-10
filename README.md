# Online Admission System

**Hệ thống tuyển sinh trực tuyến**

Ứng dụng quản lý tuyển sinh từ đăng ký tài khoản, tiếp nhận và xác minh hồ sơ đến tính điểm, phân bổ chỉ tiêu, phê duyệt và công bố kết quả. Dự án sử dụng Laravel, Livewire và cơ sở dữ liệu quan hệ; hỗ trợ luồng tuyển sinh legacy và luồng Native có phiên bản quy tắc, snapshot hồ sơ và kết quả độc lập.

## Mục tiêu và phạm vi
- Số hóa hồ sơ, điểm, minh chứng và nguyện vọng của thí sinh.
- Phân tách trách nhiệm thí sinh, cán bộ tuyển sinh và quản trị viên.
- Quản lý catalog tuyển sinh, quy tắc chấm điểm và chỉ tiêu có phiên bản.
- Thực hiện luồng Native nhiều nguyện vọng, nhiều phương thức, kiểm tra điều kiện và công bố có kiểm soát.
- Cung cấp thống kê, thông báo và báo cáo phục vụ quản lý, nghiệm thu đồ án.

## Kiến trúc hệ thống
Ứng dụng Laravel dạng monolith, giao diện Blade/Livewire; trạng thái tương tác và xử lý nghiệp vụ nằm phía server.

```text
Trình duyệt: Candidate / Staff / Admin
              │
       Routes + Middleware
              │
       Livewire / Controllers
              │
       Policies + Actions nghiệp vụ
              │
       Eloquent + Transactions + Locks
              │
       MariaDB / Kho minh chứng riêng tư
```

- **Xác thực:** Fortify hỗ trợ đăng ký, đăng nhập, đặt lại mật khẩu, xác minh email và xác thực hai yếu tố. Middleware kiểm tra tài khoản hoạt động; policies/gates bảo vệ từng thao tác và quyền sở hữu hồ sơ.
- **Nghiệp vụ:** `app/Actions/` chứa đăng ký Native, xác minh nguồn, chấm điểm, quản lý quy tắc/chỉ tiêu, phân bổ và vòng đời kết quả. Livewire gọi các actions thay vì chỉ dựa vào việc ẩn nút giao diện.
- **Dữ liệu Native:** hồ sơ nộp tạo `application_submission_snapshots`, `submission_wish_entries` và `wish_method_bindings`. Mỗi binding pin phiên bản rule; dữ liệu sealed không bị ghi đè khi cấu hình hiện tại thay đổi.
- **Chấm điểm và kết quả:** `native_method_evaluations` lưu đánh giá từng binding; `native_allocation_policies`, `native_result_versions` và `native_result_entries` tách biệt với kết quả legacy.
- **Tính nhất quán:** transactions, catalog locking, content hash, fingerprint và kiểm tra stale bảo vệ thao tác quản trị và kết quả phụ thuộc dữ liệu đầu vào. Activity logs ghi dấu các thao tác nghiệp vụ.
- **Minh chứng:** disk `candidate-private` lưu tại `storage/app/candidate-private`, tải qua controller có kiểm tra quyền. Không công khai thư mục này qua storage symlink.

## Vai trò và quyền hạn

| Vai trò | Quyền hạn chính đã triển khai |
|---|---|
| Candidate | Đăng ký tài khoản; quản lý hồ sơ của mình, điểm và minh chứng; lập/sắp xếp nguyện vọng, nộp hồ sơ; theo dõi thông báo và trạng thái; xem kết quả của mình khi đã công bố. |
| Staff | Xem và kiểm tra hồ sơ, lịch sử xử lý; xác minh nguồn điểm/minh chứng, yêu cầu bổ sung hoặc xử lý hồ sơ không hợp lệ; chạy chấm điểm Native theo quyền; xem thống kê và xuất báo cáo trong phạm vi được phép. |
| Admin | Có quyền nghiệp vụ kiểm tra hồ sơ; quản lý đợt, ngành, phương thức, chương trình, offering; phê duyệt rules/chỉ tiêu/chính sách phối hợp; điều khiển đăng ký Native, phân bổ, phê duyệt và công bố kết quả. |

Candidate không được đọc hồ sơ người khác hoặc kết quả chưa công bố. Staff không có quyền thay Admin thực hiện phân bổ Native, phê duyệt hay công bố kết quả. Các quyền còn phụ thuộc trạng thái tài khoản, xác minh email và trạng thái dữ liệu.

## 5. Chức năng đã triển khai

### Hồ sơ và tiếp nhận

- Hồ sơ cá nhân, thông tin tuyển sinh, điểm, tài liệu và minh chứng; nguồn THPT/học bạ được chuẩn hóa riêng.
- Quản lý hồ sơ theo đợt, trạng thái xử lý và lịch sử kiểm tra.
- Luồng legacy được giữ riêng; luồng Native đăng ký theo ngành, một nguyện vọng có nhiều method bindings.
- Thêm/xóa/sắp xếp nguyện vọng khi còn được phép chỉnh sửa; submit tạo snapshot có phiên bản, sealed và hash.
- Native có bốn chế độ theo đợt: `legacy`, `native_draft`, `native_open`, `native_closed`; độc lập với lifecycle và thời gian nhận hồ sơ.

### Cấu hình và chấm điểm Native

- Draft → approve → retire evaluation rule; approved payload bất biến, chỉnh sửa bằng phiên bản mới và bind rõ ràng vào program.
- Readiness theo offering: accepted methods, rule hợp lệ, rule thiếu và template unsupported. READY không đồng nghĩa sẵn sàng phân bổ hoặc công bố.
- `THPT_SCORE v1`: tổng ba môn hệ số 1, thang 30; kiểm tra năm thi, ngưỡng từng môn và tổng.
- `TRANSCRIPT_SCORE v1`: tổng ba môn hệ số 1 của lớp 10/11/12 được rule chỉ định; kiểm tra năm tốt nghiệp của nguồn, ngưỡng từng môn và tổng.
- Chỉ dùng nguồn đã xác minh đúng phạm vi rule đã pin. Kết quả gồm `eligible`, `ineligible`, `pending_data`, `unsupported`, `needs_resolution`, kèm điểm, provenance và phiên bản thuật toán.
- Nếu có nhiều nguồn hợp lệ, trả về `needs_resolution`; không tự lấy điểm cao nhất hoặc lần thi mới nhất. Scoring được chạy bằng thao tác rõ ràng, không tự chạy lúc submit.

### Vận hành và báo cáo

- Quản lý chỉ tiêu Q/q có phiên bản và phê duyệt; Native allocation có policy, kiểm tra bất biến và chứng nhận lại kết quả.
- Kết quả Native có draft, kiểm tra, phê duyệt, từ chối và công bố; dữ liệu đầu vào thay đổi có thể khiến phiên bản stale và bị chặn.
- Candidate chỉ tra cứu phiên bản đã công bố; thông báo kết quả được gửi qua hệ thống notifications.
- Dashboard/thống kê hồ sơ, nguyện vọng, chỉ tiêu và kết quả; xuất XLSX/PDF, cùng báo cáo scoring Native dạng CSV. Giới hạn xuất hiện tại: XLSX 100.000 dòng, PDF 1.000 dòng.
- Tư vấn tuyển sinh có tích hợp Gemini tùy chọn, mặc định tắt; cần cấu hình provider/API key riêng và không thay thế quyết định xét tuyển.

## Quy trình end-to-end

| Bước | Người thực hiện | Xử lý chính |
|---|---|---|
| 1. Chuẩn bị | Admin | Tạo catalog, approved rules, bindings, chỉ tiêu và policy phối hợp. Policy allocation phải được phê duyệt **trước thời điểm bắt đầu đợt và trước khi có hồ sơ**. |
| 2. Mở đăng ký | Admin | Kiểm tra readiness/conflicts; chuẩn bị và mở Native rõ ràng. Global feature flag phải cho phép và thời gian tiếp nhận phải hợp lệ. |
| 3. Đăng ký | Candidate | Đăng ký/xác minh email, hoàn thiện hồ sơ, nhập nguồn điểm và nộp minh chứng. |
| 4. Nộp hồ sơ | Candidate | Chọn ngành và thứ tự nguyện vọng; submit tạo snapshot sealed, entries và bindings pin rule version. |
| 5. Xác minh | Staff/Admin | Kiểm tra hồ sơ và nguồn điểm; xác minh đúng nguồn/năm/lớp, yêu cầu xử lý thiếu sót khi cần. |
| 6. Chấm điểm | Staff/Admin | Chạy tính điểm Native cho từng binding; xử lý pending/ambiguous/unsupported trước khi phân bổ. |
| 7. Xét tuyển | Admin | Đóng tiếp nhận theo điều kiện vận hành; kiểm tra policy, Q/q, nguồn và fingerprint; chạy allocation để tạo kết quả draft. |
| 8. Phê duyệt | Admin | Kiểm tra chứng nhận thuật toán, tính toàn vẹn, stale và các bất biến; phê duyệt đúng phiên bản. |
| 9. Công bố | Admin | Công bố bằng thao tác riêng sau phê duyệt; không tự công bố khi chấm điểm hoặc phân bổ. |
| 10. Tra cứu | Candidate | Xem kết quả đã công bố và thông báo của chính mình. |

Trang **Chương trình tuyển sinh** tích hợp quản lý rules; trang quản lý đợt có đăng ký Native; chi tiết hồ sơ có scoring; trang **Kết quả** tích hợp policy, allocation và phiên bản kết quả Native. Trang engine legacy không được dùng để xử lý đợt Native.

## Nguyên tắc Native Admission Engine

Thuật toán hiện tại là **Deferred Acceptance phía thí sinh**, định danh `student-da-matroid-v1`, trong phạm vi mô hình và chính sách được hỗ trợ.

- Thí sinh đề xuất theo thứ tự nguyện vọng ngành/offering của mình. NV1 không tạo ưu tiên tuyển sinh cao hơn NV2 đối với một thí sinh khác.
- Mỗi offering có thứ tự ưu tiên thí sinh cố định từ các thứ hạng hợp lệ theo phương thức. Thứ tự chung phải bảo toàn các thứ hạng đó; xung đột tạo chu trình thì BLOCKED.
- Không so sánh trực tiếp điểm khác thang đo. `method_priority` phải do Admin cấu hình và phê duyệt; chỉ hoàn thiện ưu tiên khi chưa có quan hệ thứ hạng và xác định binding cuối cùng, không ghi đè thứ hạng hợp lệ.
- Hàm chọn greedy theo thứ tự chung, kiểm tra khả năng matching bindings trong Q/q; được đổi binding của người đang giữ để tìm phép gán hợp lệ.
- **Q** là trần tổng của offering; **q_i** là trần cứng từng phương thức/program trong phạm vi đó. Không chuyển chỉ tiêu dư, không vượt Q/q và không bắt buộc dùng hết chỉ tiêu.
- Mỗi hồ sơ được phân bổ tối đa một nguyện vọng trong đợt. Không tối đa hóa số người trúng tuyển bằng cách đẩy thí sinh xếp hạng cao xuống nguyện vọng thấp hơn.
- Policy hiện tại chỉ hỗ trợ `ties=block`: đồng điểm chưa có cách xử lý được hỗ trợ thì BLOCKED, không dùng ID, timestamp hay thứ tự database làm tie-break.
- Rule versions khác nhau chỉ xếp hạng chung khi thuộc nhóm tương đương đã phê duyệt. V1 chỉ chấp nhận cùng phương thức, template, payload và ranking contract; không hỗ trợ bảng quy đổi tùy ý.
- Kết quả có tính deterministic/idempotent theo đầu vào và policy; trước phê duyệt/công bố phải kiểm tra lại dữ liệu và chứng nhận allocation.

Unit/property tests kiểm tra substitutability, IRC, LAD, ổn định và các bất biến trên những thị trường kiểm thử, bao gồm nhiều bindings/nguyện vọng, Q/q và xung đột thứ hạng. Đây không phải khẳng định tính đúng đắn cho mọi chính sách tuyển sinh ngoài mô hình hiện tại.


### Tests và quality checks

Chạy tại `src/`:

```powershell
php artisan test --compact
php vendor/bin/phpstan analyse --memory-limit=1G
php vendor/bin/pint --test
npm run build
git diff --check
```

`phpunit.xml` cấu hình `APP_ENV=testing`, SQLite `:memory:`, cache/session/mail array và queue sync; tests thông thường không dùng database tuyển sinh local. PHPStan được cấu hình level 7. `composer test` còn chạy kiểm tra định dạng và phân tích tĩnh trước test suite.

Coverage thực tế gồm đăng ký và snapshot, nguồn điểm/scoring, authorization, Q/q, chính sách phối hợp, DA/property tests, result workflow, Candidate visibility, báo cáo và luồng end-to-end. Bài `tests/Unit/NativeMariaDbAcceptanceTest.php` kiểm tra transactions đồng thời, idempotency, rollback/stale và E2E trên MariaDB; mặc định **skip** nếu chưa chỉ định database nghiệm thu riêng.

Để chạy bài MariaDB, phải chuẩn bị database nghiệm thu dùng một lần, có schema cần thiết và tên đúng dạng `online_admission_acceptance_YYYYMMDD_HHMMSS`; cấu hình connection MySQL loopback và biến `NATIVE_MARIADB_ACCEPTANCE_DATABASE` trỏ tới database đó. Sau đó chạy:

```powershell
php vendor/bin/pest tests/Unit/NativeMariaDbAcceptanceTest.php --compact
```

Bài nghiệm thu MariaDB ghi fixtures và thực hiện transactions thật, không được chạy trên `online_admission` đang sử dụng. SQLite không chứng minh được row locking/concurrency MariaDB. Kết quả tests phải lấy từ output lần chạy tương ứng; không suy ra PASS chỉ vì có file test hoặc từ số lượng tests của một phiên bản source khác.

## . Giới hạn nghiệp vụ và phạm vi mô phỏng

- Native chỉ hỗ trợ chấm điểm hai template THPT/học bạ nêu trên; APTITUDE_SCORE và CERTIFICATE_CONDITION còn unsupported. Có màn hình nhập dữ liệu/chứng chỉ không đồng nghĩa đã có công thức xét tuyển Native cho chúng.
- Không tự đổi DGNL legacy thành HSA/V-ACT/TSA, quy đổi chứng chỉ, cộng điểm ưu tiên hoặc áp dụng công thức học bạ legacy tiếng Anh hệ số 2 cho template Native hệ số 1.
- Không có tie-break Native tùy ý: policy hiện tại chặn đồng điểm. Không hỗ trợ tương đương điểm giữa các payload/thang đo khác nhau.
- Approved policy không được phê duyệt hồi tố cho đợt đã bắt đầu hoặc có hồ sơ. Demo đã nộp thiếu policy/rule phù hợp không được sửa snapshot để vượt điều kiện; cần một đợt/hồ sơ thử nghiệm mới đúng quy trình.
- Readiness đăng ký, nguồn điểm hợp lệ, chỉ tiêu approved và điều kiện công bố là những kiểm tra khác nhau. Một offering READY không bảo đảm một hồ sơ đủ điểm hoặc một đợt có thể allocation.
- Native và legacy độc lập; engine legacy chặn xử lý đợt Native. Không trộn dữ liệu hoặc dùng ID giả để vượt ràng buộc.
- Candidate xem kết quả Native đã công bố; không mặc định luồng xác nhận nhập học legacy là chức năng xác nhận nhập học Native đã hoàn thiện.
- Không tự allocation/publication khi submit hay scoring. Quyết định xét tuyển phải qua các bước kiểm tra, phê duyệt và công bố có quyền phù hợp.
- Tài khoản, ngưỡng, học phí, ngày và catalog có nhãn DEMO chỉ phục vụ đồ án. Triển khai thực tế cần chính sách hợp lệ, bảo vệ dữ liệu cá nhân, vận hành backup và nghiệm thu riêng cho môi trường triển khai.
