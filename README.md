# Online Admission System

**Hệ thống tuyển sinh trực tuyến — Đồ án đại học**

Ứng dụng quản lý tuyển sinh từ đăng ký tài khoản, tiếp nhận và xác minh hồ sơ đến tính điểm, phân bổ chỉ tiêu, phê duyệt và công bố kết quả. Dự án sử dụng Laravel, Livewire và cơ sở dữ liệu quan hệ; hỗ trợ luồng tuyển sinh legacy và luồng Native có phiên bản quy tắc, snapshot hồ sơ và kết quả độc lập.

Tài liệu này đặt tại **root repository**. Mã nguồn Laravel nằm trong `src/`; các lệnh dưới đây chạy tại thư mục đó, trừ khi có ghi chú khác.

## 1. Mục tiêu và phạm vi

- Số hóa hồ sơ, điểm, minh chứng và nguyện vọng của thí sinh.
- Phân tách trách nhiệm thí sinh, cán bộ tuyển sinh và quản trị viên.
- Quản lý catalog tuyển sinh, quy tắc chấm điểm và chỉ tiêu có phiên bản.
- Thực hiện luồng Native nhiều nguyện vọng, nhiều phương thức, kiểm tra điều kiện và công bố có kiểm soát.
- Cung cấp thống kê, thông báo và báo cáo phục vụ quản lý, nghiệm thu đồ án.

Đây là hệ thống mô phỏng nghiệp vụ với những chính sách được cấu hình và phê duyệt trong ứng dụng. **Không tuyên bố thuật toán tương đương hệ thống lọc ảo chính thức của Bộ GD&ĐT**, cũng không mặc định dữ liệu demo là chính sách tuyển sinh chính thức.

## 2. Công nghệ và phiên bản

Ràng buộc phiên bản được đọc từ `src/composer.json` và `src/package.json`. Cột phiên bản cài đặt được đối chiếu với dependencies hiện có khi cập nhật tài liệu; ký hiệu `^` và `~` là ràng buộc, không phải phiên bản cố định.

| Công nghệ | Ràng buộc trong manifest | Phiên bản cài đặt đã đối chiếu |
|---|---|---|
| PHP | `^8.3` | 8.5.10 CLI |
| Laravel | `^13.17` | 13.31.0 |
| Livewire | `^4.1` | 4.4.4 |
| Flux UI | `^2.13.1` | 2.19.0 |
| Laravel Fortify | `^1.37.2` | 1.39.0 |
| Tailwind CSS | `^4.0.7` | 4.3.3 |
| Vite | `^8.0.0` | 8.3.0 |
| Vite Plus | `0.3.0` | Manifest cố định 0.3.0 |
| Laravel Vite Plugin | `^3.1` | Dùng bản khóa trong `package-lock.json` |
| Pest | `^5.1` | 5.1.4 |
| Larastan | `^3.9` | 3.12.0 |
| Laravel Pint | `^1.27` | 1.32.1 |
| OpenSpout | `~4.32` | 4.32.0 |
| Dompdf | `~3.1` | 3.1.6 |

Môi trường nghiệm thu sử dụng **MariaDB** thông qua connection `mysql` và Node.js **24.17.0**. Phiên bản MariaDB/Node.js không được khai báo như dependency PHP trong `composer.json`. Bộ kiểm thử mặc định dùng SQLite trong bộ nhớ; kiểm thử concurrency có bài nghiệm thu MariaDB riêng.

Giữ `composer.lock` và `package-lock.json` để tái lập dependencies. Không dùng `composer update` thay cho cài đặt từ lock file khi chuẩn bị nghiệm thu.

## 3. Kiến trúc hệ thống

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

## 4. Vai trò và quyền hạn

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

## 6. Quy trình end-to-end

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

## 7. Nguyên tắc Native Admission Engine

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

## 8. Cấu trúc thư mục

```text
online-admission-system/
├── README.md
└── src/                         # Laravel root
    ├── app/
    │   ├── Actions/             # Nghiệp vụ, scoring, allocation, kết quả
    │   ├── Console/Commands/    # Công cụ Artisan
    │   ├── Enums/               # Vai trò và trạng thái
    │   ├── Http/                # Controllers và middleware
    │   ├── Livewire/            # Candidate, Admin và Settings
    │   ├── Models/              # Eloquent models
    │   ├── Notifications/       # Thông báo nghiệp vụ
    │   ├── Policies/            # Phân quyền
    │   └── Support/             # Báo cáo, tư vấn, tiện ích
    ├── bootstrap/
    ├── config/                  # Native registration, engine, reports...
    ├── database/                # Migrations, factories, seeders
    ├── public/                  # Web document root
    ├── resources/               # Blade, CSS và JavaScript
    ├── routes/                  # Web, admin, candidate, settings
    ├── storage/                 # Minh chứng riêng tư, cache và logs
    ├── tests/                   # Feature, Unit và Fixtures
    ├── .env.example
    ├── composer.json / composer.lock
    ├── package.json / package-lock.json
    ├── phpunit.xml
    └── phpstan.neon
```

## 9. Cài đặt trên Windows/XAMPP

### Chuẩn bị

Sử dụng Git, Composer, Node.js/npm, MariaDB và PHP tương thích toàn bộ lock file. Môi trường đã đối chiếu dùng PHP 8.5.10 và Node.js 24.17.0; không mặc định PHP đi kèm mọi bản XAMPP đáp ứng dependency hiện tại. Kiểm tra PHP CLI và PHP phục vụ web cùng đáp ứng yêu cầu.

Khởi động dịch vụ MariaDB trong XAMPP. Tạo một database UTF-8 riêng cho cài mới và cấp quyền phù hợp cho tài khoản kết nối. Không nhập dump chứa thông tin thí sinh thật vào môi trường chia sẻ đồ án.

### Dependencies và môi trường

Ví dụ khi repository nằm tại `C:\xampp\htdocs\online-admission-system`:

```powershell
Set-Location C:\xampp\htdocs\online-admission-system\src
php -v
node -v
composer install
composer check-platform-reqs
npm ci
```

Nếu chưa có `.env`, sao chép `.env.example` rồi chỉnh cấu hình local như mục 10. **Không ghi đè `.env` đã tồn tại.** Với cài mới, tạo application key:

```powershell
Copy-Item .env.example .env
php artisan key:generate --no-interaction
php artisan config:clear --no-interaction
```

Chỉ trên **database mới, rỗng và dành riêng cho dự án**, kiểm tra rồi áp dụng migrations:

```powershell
php artisan migrate:status --no-interaction
php artisan migrate --no-interaction
npm run build
```

Với database có dữ liệu, làm theo hướng dẫn cập nhật an toàn ở mục 10; không chạy các lệnh migration trên theo thói quen. `composer run setup` có bước migrate tự động nên không dùng làm bước cập nhật database đang sử dụng.

### Chạy ứng dụng

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Mở địa chỉ do Artisan hiển thị; cấu hình `APP_URL` khớp địa chỉ truy cập. Khi phát triển frontend, mở terminal thứ hai tại `src/` và chạy `npm run dev`; khi dùng assets đã build thì không cần terminal Vite. Nếu dùng database queue, chạy worker ở terminal riêng:

```powershell
php artisan queue:work --no-interaction
```

Nếu chạy qua Apache/XAMPP thay cho Artisan, đặt DocumentRoot của virtual host vào **`src/public`**, không vào root repository hoặc `src/`, và cấu hình PHP tương thích dependencies. Không cho truy cập `.env`, storage riêng tư hay database backup qua web.

## 10. Cấu hình, dữ liệu thử nghiệm và kiểm thử

### `.env.example`

File mẫu hiện mặc định SQLite; để dùng MariaDB local, đổi connection sang `mysql` và cung cấp database/tài khoản đã chuẩn bị. Ví dụ sau có placeholder, cần thay trước khi chạy:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<database_local_cua_ban>
DB_USERNAME=<tai_khoan_database>
DB_PASSWORD=<mat_khau_database>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
MAIL_MAILER=log

ADMISSION_NATIVE_REGISTRATION=false
ADMISSION_CHATBOT_ENABLED=false
```

`ADMISSION_NATIVE_REGISTRATION` được đọc trong `config/admission_registration.php`, mặc định **false** dù file mẫu chưa có dòng này. Bật global flag không tự mở bất kỳ đợt nào; Admin vẫn phải kiểm tra và chuyển trạng thái theo đợt. Không sửa allowlist để vượt validator. Múi giờ ứng dụng hiện là `Asia/Ho_Chi_Minh`, ngôn ngữ `vi` trong `config/app.php`.

Mailer `log` phù hợp local: email xác minh/đặt lại mật khẩu được ghi log thay vì gửi thật. Nếu cần gửi email, cấu hình mailer riêng. Không đưa API keys, application key, log chứa liên kết xác thực hoặc dữ liệu hồ sơ vào Git.

### Migrations với database đã có dữ liệu

Backup bên ngoài repository và xác minh khả năng phục hồi trước khi cập nhật. Đọc migration status, schema thực tế và dependencies; pending không mặc nhiên đồng nghĩa có thể chạy an toàn. Hai migrations Native kết quả/policy là:

- `2026_10_10_215742_create_native_result_version_tables.php`
- `2026_10_10_232009_create_native_allocation_policies_table.php`

Chỉ khi đã kiểm tra và được phép cập nhật, dùng `php artisan migrate --path=database/migrations/<ten_file_da_kiem_tra>.php --no-interaction` cho từng migration theo thứ tự dependencies. Không dùng blanket migrate, `migrate:fresh`, rollback hoặc seeder để sửa database đang có hồ sơ sealed. Các migration cũ về verification/precision/constraints cần xem xét riêng; không coi README là xác nhận trạng thái của một database khác.

### Tài khoản và catalog thử nghiệm

`CandidateSeeder` thực sự khai báo tài khoản **`candidate@example.com` / `password`**, vai trò Candidate và email đã xác minh. Chỉ tạo trên database demo riêng bằng lệnh sau khi chủ động chấp nhận dữ liệu mẫu:

```powershell
php artisan db:seed --class=CandidateSeeder --no-interaction
```

Seeder này dùng `updateOrCreate`, có thể ghi đè tài khoản/hồ sơ cùng định danh khi chạy lại. Không dùng trên database có dữ liệu cần bảo toàn. Người dùng cũng có thể tự đăng ký Candidate trên giao diện.

Không có tài khoản Admin/Staff cố định được khai báo trong seeders hiện tại; cần dùng tài khoản được người quản lý môi trường cấp đúng role. Các test tạo tài khoản riêng bằng factories/fixtures, không phải tài khoản đăng nhập chung của ứng dụng.

`DatabaseSeeder` gọi cả `CandidateSeeder` và `AdmissionDemoSeeder`. Catalog demo của seeder có ngày cố định năm 2026 và có thể cập nhật lại records khi chạy lại; không tự tạo policy/rules Native đầy đủ hoặc bảo đảm đợt còn trong thời gian đăng ký.

Công cụ chuẩn bị demo đa phương thức có chế độ mặc định chỉ đọc:

```powershell
php artisan admission:prepare-native-multi-demo --help
php artisan admission:prepare-native-multi-demo --dry-run
```

Command dự kiến chuẩn bị catalog `DEMO-2026-NATIVE-MULTI`, method học bạ `DEMO-HB-EQ1` hệ số 1–1–1, hai programs và một offering; round vẫn Draft/legacy. `--apply` yêu cầu xác nhận mã đợt, database development, backup và Admin hợp lệ. Command không tự approve rule học bạ, mở Native hoặc tạo/nộp hồ sơ Candidate. Không dùng nó để sửa dữ liệu sealed hoặc thay thế quy trình phê duyệt chính sách.

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

## 11. Giới hạn nghiệp vụ và phạm vi mô phỏng

- Native chỉ hỗ trợ chấm điểm hai template THPT/học bạ nêu trên; APTITUDE_SCORE và CERTIFICATE_CONDITION còn unsupported. Có màn hình nhập dữ liệu/chứng chỉ không đồng nghĩa đã có công thức xét tuyển Native cho chúng.
- Không tự đổi DGNL legacy thành HSA/V-ACT/TSA, quy đổi chứng chỉ, cộng điểm ưu tiên hoặc áp dụng công thức học bạ legacy tiếng Anh hệ số 2 cho template Native hệ số 1.
- Không có tie-break Native tùy ý: policy hiện tại chặn đồng điểm. Không hỗ trợ tương đương điểm giữa các payload/thang đo khác nhau.
- Approved policy không được phê duyệt hồi tố cho đợt đã bắt đầu hoặc có hồ sơ. Demo đã nộp thiếu policy/rule phù hợp không được sửa snapshot để vượt điều kiện; cần một đợt/hồ sơ thử nghiệm mới đúng quy trình.
- Readiness đăng ký, nguồn điểm hợp lệ, chỉ tiêu approved và điều kiện công bố là những kiểm tra khác nhau. Một offering READY không bảo đảm một hồ sơ đủ điểm hoặc một đợt có thể allocation.
- Native và legacy độc lập; engine legacy chặn xử lý đợt Native. Không trộn dữ liệu hoặc dùng ID giả để vượt ràng buộc.
- Candidate xem kết quả Native đã công bố; không mặc định luồng xác nhận nhập học legacy là chức năng xác nhận nhập học Native đã hoàn thiện.
- Không tự allocation/publication khi submit hay scoring. Quyết định xét tuyển phải qua các bước kiểm tra, phê duyệt và công bố có quyền phù hợp.
- Tài khoản, ngưỡng, học phí, ngày và catalog có nhãn DEMO chỉ phục vụ đồ án. Triển khai thực tế cần chính sách hợp lệ, bảo vệ dữ liệu cá nhân, vận hành backup và nghiệm thu riêng cho môi trường triển khai.
