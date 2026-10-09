
# Online Admission System

**Hệ thống tuyển sinh trực tuyến – Online Admission System**

Online Admission System là ứng dụng web hỗ trợ quản lý quy trình tuyển sinh đại học, từ đăng ký tài khoản, khai báo thông tin thí sinh, nộp hồ sơ xét tuyển đến kiểm tra hồ sơ, xử lý xét tuyển và công bố kết quả.

Dự án được phát triển bằng **Laravel 13, Livewire 4 và Tailwind CSS 4**, hướng đến việc số hóa quy trình tuyển sinh, giảm thao tác thủ công và nâng cao hiệu quả quản lý.

##  1. Giới thiệu dự án

### Mục tiêu

- Xây dựng hệ thống đăng ký và xét tuyển trực tuyến.
- Cho phép thí sinh quản lý hồ sơ và nguyện vọng xét tuyển.
- Hỗ trợ cán bộ tuyển sinh kiểm tra, xác minh hồ sơ.
- Hỗ trợ quản trị viên cấu hình các đợt và phương thức xét tuyển.
- Tự động hóa một phần quy trình xử lý kết quả tuyển sinh.
- Cung cấp thông báo và kết quả xét tuyển cho thí sinh.
- Hỗ trợ tư vấn thông tin tuyển sinh.

### Đối tượng sử dụng

| Vai trò | Mô tả |
|---|---|
| Candidate | Thí sinh đăng ký và nộp hồ sơ xét tuyển |
| Staff | Cán bộ tuyển sinh kiểm tra, xác minh hồ sơ |
| Admin | Quản trị viên quản lý và vận hành hệ thống |

---

##  2. Chức năng chính

###  2.1. Thí sinh (Candidate)

**Quản lý tài khoản**
- Đăng ký và đăng nhập.
- Xác minh địa chỉ email.
- Cập nhật thông tin tài khoản.
- Thay đổi mật khẩu và thiết lập bảo mật.

**Quản lý hồ sơ cá nhân**
- Cập nhật thông tin cá nhân.
- Khai báo thông tin định danh.
- Cập nhật thông tin học tập.
- Tải ảnh chân dung và minh chứng.

**Quản lý thông tin xét tuyển**
- Khai báo kết quả thi.
- Nhập điểm học bạ.
- Khai báo chứng chỉ và thông tin ưu tiên.
- Tải lên tài liệu minh chứng.
- Theo dõi trạng thái xác minh thông tin.

**Đăng ký xét tuyển**
- Xem thông tin tuyển sinh.
- Xem ngành và chương trình đào tạo.
- Lựa chọn phương thức xét tuyển.
- Đăng ký và quản lý nguyện vọng.
- Theo dõi trạng thái hồ sơ.

**Kết quả và thông báo**
- Tra cứu kết quả xét tuyển.
- Nhận thông báo từ hệ thống.
- Theo dõi các cập nhật liên quan đến hồ sơ.

**Tư vấn tuyển sinh**
- Truy cập trang tư vấn tuyển sinh.
- Hỗ trợ giải đáp thông tin tuyển sinh thông qua chatbot khi được cấu hình và kích hoạt.

###  2.2. Cán bộ tuyển sinh (Staff)

- Xem danh sách hồ sơ thí sinh theo quyền được cấp.
- Kiểm tra thông tin đăng ký xét tuyển.
- Kiểm tra tài liệu và minh chứng.
- Xác minh thông tin và điểm xét tuyển.
- Thực hiện quy trình xét duyệt hồ sơ.
- Theo dõi trạng thái xử lý hồ sơ.
- Tra cứu lịch sử xét duyệt.

###  2.3. Quản trị viên (Admin)

**Quản lý tuyển sinh**
- Quản lý các đợt tuyển sinh.
- Quản lý danh mục ngành học.
- Quản lý phương thức xét tuyển.
- Quản lý chương trình tuyển sinh.
- Quản lý các lựa chọn ngành dành cho thí sinh.

**Quản lý hồ sơ**
- Xem và theo dõi hồ sơ đăng ký.
- Kiểm tra tiến trình xét duyệt.
- Theo dõi lịch sử xử lý hồ sơ.

**Xử lý xét tuyển**
- Vận hành công cụ xử lý xét tuyển.
- Quản lý kết quả xét tuyển.
- Thực hiện công bố kết quả theo quyền được cấp.

---

##  3. Quy trình hoạt động

Hệ thống được thiết kế theo quy trình tuyển sinh:

```text
Thí sinh đăng ký tài khoản
           |
           v
     Xác minh email
           |
           v
   Hoàn thiện hồ sơ
           |
           v
 Nhập thông tin xét tuyển
           |
           v
 Tải tài liệu minh chứng
           |
           v
 Đăng ký nguyện vọng
           |
           v
   Nộp hồ sơ xét tuyển
           |
           v
 Cán bộ kiểm tra hồ sơ
           |
           v
 Xác minh và xét duyệt
           |
           v
   Xử lý xét tuyển
           |
           v
    Công bố kết quả
           |
           v
 Thí sinh tra cứu kết quả
```

Các bước xử lý phụ thuộc vào trạng thái hồ sơ, cấu hình đợt tuyển sinh và quyền của từng vai trò.

---

##  4. Kiểm thử

Dự án sử dụng Pest PHP để thực hiện kiểm thử.

Chạy toàn bộ kiểm thử:

```bash
php artisan test
```

Chạy kiểm thử với kết quả ngắn gọn:

```bash
php artisan test --compact
```

Kiểm tra định dạng PHP:

```bash
composer run lint:check
```

Kiểm tra phân tích tĩnh:

```bash
composer run types:check
```

Chạy quy trình kiểm tra tổng hợp:

```bash
composer run test
```

Các nhóm kiểm thử bao gồm:

- Kiểm thử chức năng.
- Kiểm thử xác thực và phân quyền.
- Kiểm thử quy trình hồ sơ tuyển sinh.
- Kiểm thử dữ liệu và quy tắc nghiệp vụ.
- Kiểm thử các trường hợp hợp lệ và không hợp lệ.

---

##  5. Bảo mật

Hệ thống sử dụng các cơ chế bảo mật của Laravel và các quy tắc nghiệp vụ riêng.

- Xác thực người dùng.
- Xác minh email.
- Phân quyền truy cập.
- Kiểm tra quyền thông qua Policies.
- Kiểm tra và xác thực dữ liệu đầu vào.
- Bảo vệ CSRF.
- Mã hóa mật khẩu.
- Kiểm soát truy cập tài liệu và minh chứng.
- Giới hạn truy cập một số chức năng.
- Bảo vệ thông tin cấu hình và API key.

Các thiết lập bảo mật cần được kiểm tra trước khi triển khai thực tế.

