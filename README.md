# Quản lý thư viện — PHP + PostgreSQL

Ứng dụng web nhỏ cho bài tập CSE392: quản lý sách, độc giả, mượn–trả và báo cáo. Dự án dùng PHP 8.2+, PDO PostgreSQL, truy vấn có tham số, CSRF token và giao dịch/khóa hàng khi mượn trả sách.

## Chạy bằng Docker (khuyến nghị)

```bash
cp .env.example .env
# Có thể đổi DB_PASSWORD trong .env trước khi chạy
docker compose up --build
```

Mở http://localhost:8000. PostgreSQL được khởi tạo tự động bằng `database/schema.sql`. Dữ liệu DB tồn tại trong Docker volume `postgres_data`.

Dừng ứng dụng: `docker compose down`. Xóa cả dữ liệu DB: `docker compose down -v`.

## Chạy thủ công

Yêu cầu PHP 8.2+, extension `pdo_pgsql`, PostgreSQL 14+.

1. Tạo database và chạy schema: `psql -U postgres -d library -f database/schema.sql`.
2. Sao chép `.env.example` thành `.env`, sửa thông tin DB phù hợp.
3. Chạy `php -S 127.0.0.1:8000 -t public` rồi mở http://127.0.0.1:8000.

## Chức năng

- Dashboard: tổng số sách, độc giả, lượt đang mượn và lượt quá hạn.
- Sách: thêm, sửa, xóa; theo dõi tổng số bản và số bản còn sẵn.
- Độc giả: thêm, sửa, xóa.
- Mượn sách: kiểm tra tồn kho và tạo phiếu mượn với hạn trả mặc định 14 ngày.
- Trả sách: cập nhật trạng thái và số bản còn sẵn trong cùng một transaction.
- Báo cáo: danh sách phiếu quá hạn.

## Cấu trúc

```text
app/bootstrap.php       Khởi tạo session, cấu hình, PDO và helper
public/index.php        Router, xử lý biểu mẫu và giao diện
public/assets/style.css Giao diện responsive
 database/schema.sql    PostgreSQL schema, index và dữ liệu mẫu
Dockerfile              PHP CLI + pdo_pgsql
compose.yaml            App và PostgreSQL cho môi trường demo
```

## Chuyển đổi MySQL → PostgreSQL

Schema dùng `SERIAL`, `BOOLEAN`, `CHECK`, `CURRENT_DATE`, `LIMIT/OFFSET` và `FOR UPDATE`; không dùng cú pháp backtick hay `AUTO_INCREMENT`. Khi triển khai dữ liệu cũ, xuất dữ liệu MySQL, chuyển đổi kiểu/ngày tháng theo schema PostgreSQL, import vào DB mới, sau đó đối chiếu số lượng và dữ liệu mẫu trước khi chuyển cấu hình ứng dụng. Không chạy migration trên DB production khi chưa sao lưu.

## Lưu ý triển khai

Đây là bản nền phục vụ học tập/demo, chưa có đăng nhập/phân quyền. Không đưa ứng dụng lên Internet công khai trước khi bổ sung xác thực, phân quyền, HTTPS, quản lý secrets và sao lưu. Không commit file `.env` hoặc mật khẩu thật.
