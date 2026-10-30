# Website tuyển sinh trực tuyến

Đồ án liên ngành **Xây dựng Website Tuyển sinh Trực tuyến**
## Nhóm thực hiện

| Thành viên | Mã sinh viên |
| --- | --- |
| Nguyễn Thị Thùy Linh | 23010633 |
| Nguyễn Anh Quân | 23010375 |
| Trần Thảo Vy | 23010588 |

**Giảng viên hướng dẫn:** ThS. Vũ Quang Dũng.  
**Đơn vị:** Trường Công nghệ Thông tin – Đại học Phenikaa.  

## Giới thiệu

UNICAMP là hệ thống web hỗ trợ quản lý quy trình tuyển sinh, từ đăng ký tài khoản, khai báo hồ sơ, đăng ký nguyện vọng, kiểm tra và xác minh hồ sơ đến tổ chức xét tuyển và công bố kết quả. Hệ thống phục vụ ba nhóm người dùng: **Thí sinh**, **Nhân viên tuyển sinh** và **Quản trị viên**.

## Chức năng chính

- **Thí sinh:** Đăng ký, đăng nhập; quản lý hồ sơ cá nhân, điểm và minh chứng; đăng ký nguyện vọng; theo dõi tình trạng hồ sơ và tra cứu kết quả.
- **Nhân viên tuyển sinh:** Tiếp nhận, kiểm tra, xác minh và xử lý hồ sơ; hỗ trợ quản lý hoạt động xét tuyển.
- **Quản trị viên:** Quản lý tài khoản, phân quyền, đợt tuyển sinh, ngành đào tạo, phương thức và chương trình tuyển sinh; tổ chức xét tuyển và công bố kết quả; quản lý tin tức và theo dõi thống kê.
- **Chatbot Gemini AI:** Hỗ trợ giải đáp thông tin tuyển sinh. Chatbot không thay thế quy trình xác minh hoặc quyết định kết quả xét tuyển.

### Hai chế độ xét tuyển

| Chế độ | Mô tả |
| --- | --- |
| **Legacy** | Quản lý nguyện vọng theo **chương trình tuyển sinh**, sử dụng cấu hình điều kiện, điểm và chỉ tiêu tương ứng. |
| **Native** | Quản lý nguyện vọng theo **ngành học** và **phương thức xét tuyển** được hỗ trợ, áp dụng quy tắc xét tuyển, chỉ tiêu và thứ tự ưu tiên nguyện vọng. |

Hai chế độ sử dụng các luồng nghiệp vụ riêng và được áp dụng theo cấu hình đợt tuyển sinh.

## Công nghệ sử dụng

- **Backend:** PHP, Laravel, Laravel Fortify.
- **Frontend:** Blade, Livewire.
- **Cơ sở dữ liệu:** MySQL.
- **Tích hợp:** Gemini API cho chatbot tư vấn thông tin.
- **Công cụ phát triển:** Composer, Laravel Artisan, Git/GitHub, XAMPP.
- **Kiểm thử:** Laravel Test; cơ sở dữ liệu SQLite `:memory:` trong môi trường kiểm thử.

## Hướng dẫn cài đặt và chạy cục bộ

### 1. Chuẩn bị

Cài đặt PHP, Composer, MySQL, Node.js/npm (nếu dự án sử dụng công cụ biên dịch tài nguyên frontend) và Git. Có thể dùng XAMPP để chạy MySQL trên Windows.

### 2. Lấy mã nguồn

```bash
git clone https://github.com/ttlinhnguyen205/online-admission-system.git
cd online-admission-system
```

**Lưu ý:** Trong quá trình phát triển, mã nguồn Laravel có thể được đặt trong thư mục `src/`. Hãy chuyển vào thư mục **chứa tệp `artisan` và `composer.json`** trước khi chạy các lệnh bên dưới.

### 3. Cài đặt thư viện

```bash
composer install
```

Nếu mã nguồn có `package.json`, cài đặt các thư viện frontend:

```bash
npm install
```

### 4. Thiết lập môi trường

Sao chép tệp cấu hình mẫu và tạo khóa ứng dụng:

```bash
cp .env.example .env
php artisan key:generate
```

Trên Windows PowerShell, có thể dùng `Copy-Item .env.example .env` thay cho `cp`.

Tạo cơ sở dữ liệu MySQL, ví dụ `online_admission`, rồi cập nhật các biến tương ứng trong `.env`:

```dotenv
APP_ENV=local
APP_DEBUG=true
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=online_admission
DB_USERNAME=root
DB_PASSWORD=
```

Đặt mật khẩu MySQL đúng với máy của bạn; không đưa mật khẩu hoặc khóa API thật lên GitHub. Nếu sử dụng chatbot, cấu hình khóa Gemini theo tên biến được mã nguồn dự án yêu cầu.

### 5. Khởi tạo cơ sở dữ liệu

```bash
php artisan migrate
```

Nếu dự án cung cấp seeders phù hợp với môi trường thử nghiệm, có thể chạy:

```bash
php artisan db:seed
```

**Chú ý:** Seeder có thể tạo hoặc thay đổi dữ liệu. Chỉ chạy trên cơ sở dữ liệu thử nghiệm sau khi kiểm tra nội dung seeder.

### 6. Chạy ứng dụng

```bash
php artisan serve
```

Mặc định có thể truy cập tại `http://127.0.0.1:8000`.

Nếu dự án sử dụng Vite và có cấu hình frontend tương ứng, mở terminal khác:

```bash
npm run dev
```

## Kiểm thử

Tại thư mục chứa tệp `artisan`, chạy:

```bash
php artisan test
```

Các bài kiểm thử tự động được thiết kế để kiểm tra các nghiệp vụ của hệ thống. Việc chạy kiểm thử và kết quả thực tế phụ thuộc phiên bản mã nguồn, cấu hình và dữ liệu kiểm thử đang sử dụng.

## Tài liệu và thiết kế

- **Mã nguồn:** https://github.com/ttlinhnguyen205/online-admission-system
- **Thiết kế Figma:** https://www.figma.com/design/899TWwMF0XzUuW2ZOcbcpj/%C4%90ALN


## Phạm vi

Dự án được xây dựng và kiểm thử trong môi trường phát triển cục bộ. Khả năng triển khai ở quy mô lớn, vận hành thực tế và hiệu năng khi có nhiều người dùng đồng thời cần được đánh giá bổ sung.

