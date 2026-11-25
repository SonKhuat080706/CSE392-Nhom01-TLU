<?php

declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

$pages = ['dashboard', 'books', 'readers', 'loans', 'reports'];
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'book_save':
                $id = post_int('id', 0);
                $title = post_string('title', 200);
                $author = post_string('author', 160);
                $isbn = post_string('isbn', 32);
                $category = post_string('category', 100);
                $yearRaw = trim((string) ($_POST['published_year'] ?? ''));
                $year = $yearRaw === '' ? null : filter_var($yearRaw, FILTER_VALIDATE_INT);
                $copies = post_int('copies_total', 1, 100000);
                if ($title === '' || $author === '') {
                    throw new InvalidArgumentException('Tên sách và tác giả là bắt buộc.');
                }
                if ($year !== null && ($year === false || $year < 0 || $year > 2200)) {
                    throw new InvalidArgumentException('Năm xuất bản không hợp lệ.');
                }
                if ($id === 0) {
                    $stmt = db()->prepare('INSERT INTO books (title, author, isbn, category, published_year, copies_total, copies_available) VALUES (:title, :author, NULLIF(:isbn, \'\'), NULLIF(:category, \'\'), :year, :total, :total)');
                    $stmt->execute(['title' => $title, 'author' => $author, 'isbn' => $isbn, 'category' => $category, 'year' => $year, 'total' => $copies]);
                } else {
                    db()->beginTransaction();
                    $stmt = db()->prepare('SELECT copies_total, copies_available FROM books WHERE id = :id FOR UPDATE');
                    $stmt->execute(['id' => $id]);
                    $old = $stmt->fetch();
                    if (!$old) {
                        throw new RuntimeException('Không tìm thấy sách cần cập nhật.');
                    }
                    $borrowed = (int) $old['copies_total'] - (int) $old['copies_available'];
                    if ($copies < $borrowed) {
                        throw new InvalidArgumentException("Tổng số bản không thể nhỏ hơn {$borrowed} bản đang được mượn.");
                    }
                    $stmt = db()->prepare('UPDATE books SET title=:title, author=:author, isbn=NULLIF(:isbn, \'\'), category=NULLIF(:category, \'\'), published_year=:year, copies_total=:total, copies_available=:available WHERE id=:id');
                    $stmt->execute(['title' => $title, 'author' => $author, 'isbn' => $isbn, 'category' => $category, 'year' => $year, 'total' => $copies, 'available' => $copies - $borrowed, 'id' => $id]);
                    db()->commit();
                }
                flash('Đã lưu thông tin sách.');
                redirect('books');

            case 'book_delete':
                $stmt = db()->prepare('DELETE FROM books WHERE id = :id');
                $stmt->execute(['id' => post_int('id', 1)]);
                flash('Đã xóa sách.');
                redirect('books');

            case 'reader_save':
                $id = post_int('id', 0);
                $name = post_string('name', 160);
                $email = post_string('email', 254);
                $phone = post_string('phone', 40);
                if ($name === '') {
                    throw new InvalidArgumentException('Tên độc giả là bắt buộc.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Email không hợp lệ.');
                }
                if ($id === 0) {
                    $stmt = db()->prepare('INSERT INTO readers (full_name, email, phone) VALUES (:name, NULLIF(:email, \'\'), NULLIF(:phone, \'\'))');
                } else {
                    $stmt = db()->prepare('UPDATE readers SET full_name=:name, email=NULLIF(:email, \'\'), phone=NULLIF(:phone, \'\') WHERE id=:id');
                }
                $params = ['name' => $name, 'email' => $email, 'phone' => $phone];
                if ($id !== 0) $params['id'] = $id;
                $stmt->execute($params);
                flash('Đã lưu thông tin độc giả.');
                redirect('readers');

            case 'reader_delete':
                $stmt = db()->prepare('DELETE FROM readers WHERE id=:id');
                $stmt->execute(['id' => post_int('id', 1)]);
                flash('Đã xóa độc giả.');
                redirect('readers');

            case 'loan_create':
                $bookId = post_int('book_id', 1);
                $readerId = post_int('reader_id', 1);
                $dueDate = trim((string) ($_POST['due_date'] ?? ''));
                if ($dueDate === '') $dueDate = date('Y-m-d', strtotime('+14 days'));
                $date = DateTime::createFromFormat('Y-m-d', $dueDate);
                if (!$date || $date->format('Y-m-d') !== $dueDate || $dueDate < date('Y-m-d')) {
                    throw new InvalidArgumentException('Hạn trả phải là ngày hợp lệ, không ở trong quá khứ.');
                }
                db()->beginTransaction();
                $stmt = db()->prepare('SELECT id, copies_available FROM books WHERE id=:id FOR UPDATE');
                $stmt->execute(['id' => $bookId]);
                $book = $stmt->fetch();
                if (!$book || (int) $book['copies_available'] < 1) {
                    throw new InvalidArgumentException('Sách không tồn tại hoặc hiện đã hết bản có thể mượn.');
                }
                $stmt = db()->prepare('SELECT id FROM readers WHERE id=:id');
                $stmt->execute(['id' => $readerId]);
                if (!$stmt->fetch()) throw new InvalidArgumentException('Không tìm thấy độc giả.');
                $stmt = db()->prepare('INSERT INTO loans (book_id, reader_id, borrowed_on, due_date, status) VALUES (:book, :reader, CURRENT_DATE, :due, \'borrowed\')');
                $stmt->execute(['book' => $bookId, 'reader' => $readerId, 'due' => $dueDate]);
                db()->prepare('UPDATE books SET copies_available=copies_available-1 WHERE id=:id')->execute(['id' => $bookId]);
                db()->commit();
                flash('Đã tạo phiếu mượn.');
                redirect('loans');

            case 'loan_return':
                db()->beginTransaction();
                $stmt = db()->prepare('SELECT id, book_id, status FROM loans WHERE id=:id FOR UPDATE');
                $stmt->execute(['id' => post_int('id', 1)]);
                $loan = $stmt->fetch();
                if (!$loan) throw new InvalidArgumentException('Không tìm thấy phiếu mượn.');
                if ($loan['status'] === 'borrowed') {
                    db()->prepare('UPDATE loans SET status=\'returned\', returned_on=CURRENT_DATE WHERE id=:id')->execute(['id' => $loan['id']]);
                    db()->prepare('UPDATE books SET copies_available=copies_available+1 WHERE id=:id')->execute(['id' => $loan['book_id']]);
                }
                db()->commit();
                flash('Đã ghi nhận trả sách.');
                redirect('loans');

            default:
                throw new InvalidArgumentException('Thao tác không hợp lệ.');
        }
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $message = $exception instanceof PDOException && str_contains($exception->getMessage(), 'unique')
            ? 'Email hoặc ISBN đã tồn tại.'
            : ($exception instanceof PDOException ? 'Không thể lưu dữ liệu. Kiểm tra ràng buộc hoặc kết nối cơ sở dữ liệu.' : $exception->getMessage());
        flash($message, 'error');
        redirect($page);
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$nav = ['dashboard' => 'Tổng quan', 'books' => 'Sách', 'readers' => 'Độc giả', 'loans' => 'Mượn–trả', 'reports' => 'Báo cáo'];
function active_link(string $current, string $target): string { return $current === $target ? 'active' : ''; }
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Quản lý thư viện</title><link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar"><a class="brand" href="?page=dashboard"><span class="brand-mark">TV</span><span>Thư viện<span class="brand-sub">Hệ thống quản lý</span></span></a><nav><?php foreach ($nav as $key => $label): ?><a class="<?= active_link($page, $key) ?>" href="?page=<?= e($key) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></header>
<main class="container">
  <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
  <?php if ($page === 'dashboard'):
    $stats = [
      ['Sách trong thư viện', (int) db()->query('SELECT COALESCE(SUM(copies_total),0) FROM books')->fetchColumn(), 'Đầu sách: ' . (int) db()->query('SELECT COUNT(*) FROM books')->fetchColumn()],
      ['Độc giả', (int) db()->query('SELECT COUNT(*) FROM readers')->fetchColumn(), 'Bạn đọc đã đăng ký'],
      ['Đang cho mượn', (int) db()->query("SELECT COUNT(*) FROM loans WHERE status='borrowed'")->fetchColumn(), 'Phiếu chưa trả'],
      ['Quá hạn', (int) db()->query("SELECT COUNT(*) FROM loans WHERE status='borrowed' AND due_date < CURRENT_DATE")->fetchColumn(), 'Cần nhắc trả sách'],
    ]; ?>
    <section class="hero"><div><p class="eyebrow">BẢNG ĐIỀU KHIỂN</p><h1>Chào mừng đến thư viện</h1><p>Quản lý sách, độc giả và hoạt động mượn trả tại một nơi.</p><a class="button primary" href="?page=loans">Tạo phiếu mượn</a></div><div class="hero-art" aria-hidden="true">▤</div></section>
    <section class="stat-grid"><?php foreach ($stats as [$label, $number, $hint]): ?><article class="stat-card"><span><?= e($label) ?></span><strong><?= e($number) ?></strong><small><?= e($hint) ?></small></article><?php endforeach; ?></section>
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">HOẠT ĐỘNG</p><h2>Phiếu mượn gần đây</h2></div><a class="text-link" href="?page=loans">Xem tất cả →</a></div><?php $recent = db()->query('SELECT l.id,l.borrowed_on,l.due_date,l.status,b.title,r.full_name FROM loans l JOIN books b ON b.id=l.book_id JOIN readers r ON r.id=l.reader_id ORDER BY l.id DESC LIMIT 5')->fetchAll(); ?>
    <?php if (!$recent): ?><p class="empty">Chưa có phiếu mượn nào.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Sách</th><th>Độc giả</th><th>Ngày mượn</th><th>Hạn trả</th><th>Trạng thái</th></tr></thead><tbody><?php foreach ($recent as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= e($row['full_name']) ?></td><td><?= e($row['borrowed_on']) ?></td><td><?= e($row['due_date']) ?></td><td><span class="badge <?= e($row['status']) ?>"><?= $row['status'] === 'borrowed' ? 'Đang mượn' : 'Đã trả' ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  <?php elseif ($page === 'books'):
    $edit = null;
    if (isset($_GET['edit'])) { $s=db()->prepare('SELECT * FROM books WHERE id=:id'); $s->execute(['id'=>(int)$_GET['edit']]); $edit=$s->fetch() ?: null; }
    $books = db()->query('SELECT * FROM books ORDER BY id DESC')->fetchAll(); ?>
    <div class="page-heading"><div><p class="eyebrow">DANH MỤC</p><h1>Quản lý sách</h1><p>Thêm mới và theo dõi số lượng sách trong thư viện.</p></div></div>
    <section class="panel form-panel"><h2><?= $edit ? 'Cập nhật sách' : 'Thêm sách mới' ?></h2><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="book_save"><input type="hidden" name="id" value="<?= e($edit['id'] ?? 0) ?>"><label class="wide">Tên sách *<input required maxlength="200" name="title" value="<?= e($edit['title'] ?? '') ?>"></label><label>Tác giả *<input required maxlength="160" name="author" value="<?= e($edit['author'] ?? '') ?>"></label><label>ISBN<input maxlength="32" name="isbn" value="<?= e($edit['isbn'] ?? '') ?>"></label><label>Thể loại<input maxlength="100" name="category" value="<?= e($edit['category'] ?? '') ?>"></label><label>Năm xuất bản<input type="number" min="0" max="2200" name="published_year" value="<?= e($edit['published_year'] ?? '') ?>"></label><label>Tổng số bản<input required type="number" min="1" name="copies_total" value="<?= e($edit['copies_total'] ?? 1) ?>"></label><div class="form-actions"><button class="button primary" type="submit"><?= $edit ? 'Lưu thay đổi' : 'Thêm sách' ?></button><?php if ($edit): ?><a class="button" href="?page=books">Hủy</a><?php endif; ?></div></form></section>
    <section class="panel"><div class="panel-heading"><h2>Danh sách sách <span class="count"><?= count($books) ?></span></h2></div><div class="table-wrap"><table><thead><tr><th>Tên sách</th><th>Tác giả</th><th>Thể loại</th><th>Còn / Tổng</th><th></th></tr></thead><tbody><?php foreach ($books as $b): ?><tr><td><strong><?= e($b['title']) ?></strong><small class="cell-sub"><?= e($b['isbn'] ?: 'Chưa có ISBN') ?></small></td><td><?= e($b['author']) ?></td><td><?= e($b['category'] ?: '—') ?></td><td><?= e($b['copies_available']) ?> / <?= e($b['copies_total']) ?></td><td class="actions"><a class="text-link" href="?page=books&edit=<?= e($b['id']) ?>">Sửa</a><form method="post" onsubmit="return confirm('Xóa sách này?')"><?= csrf_field() ?><input type="hidden" name="action" value="book_delete"><input type="hidden" name="id" value="<?= e($b['id']) ?>"><button class="link danger-text">Xóa</button></form></td></tr><?php endforeach; ?></tbody></table><?php if (!$books): ?><p class="empty">Chưa có sách. Hãy thêm cuốn đầu tiên.</p><?php endif; ?></div></section>
  <?php elseif ($page === 'readers'):
    $edit=null; if(isset($_GET['edit'])){$s=db()->prepare('SELECT * FROM readers WHERE id=:id');$s->execute(['id'=>(int)$_GET['edit']]);$edit=$s->fetch()?:null;}
    $readers=db()->query('SELECT r.*, COUNT(l.id) FILTER (WHERE l.status=\'borrowed\') AS active_loans FROM readers r LEFT JOIN loans l ON l.reader_id=r.id GROUP BY r.id ORDER BY r.id DESC')->fetchAll(); ?>
    <div class="page-heading"><div><p class="eyebrow">BẠN ĐỌC</p><h1>Quản lý độc giả</h1><p>Lưu thông tin liên hệ và theo dõi lượt mượn đang mở.</p></div></div>
    <section class="panel form-panel"><h2><?= $edit ? 'Cập nhật độc giả' : 'Thêm độc giả' ?></h2><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="reader_save"><input type="hidden" name="id" value="<?= e($edit['id'] ?? 0) ?>"><label>Họ và tên *<input required maxlength="160" name="name" value="<?= e($edit['full_name'] ?? '') ?>"></label><label>Email<input type="email" maxlength="254" name="email" value="<?= e($edit['email'] ?? '') ?>"></label><label>Số điện thoại<input maxlength="40" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></label><div class="form-actions"><button class="button primary"><?= $edit ? 'Lưu thay đổi' : 'Thêm độc giả' ?></button><?php if ($edit): ?><a class="button" href="?page=readers">Hủy</a><?php endif; ?></div></form></section>
    <section class="panel"><div class="panel-heading"><h2>Danh sách độc giả <span class="count"><?= count($readers) ?></span></h2></div><div class="table-wrap"><table><thead><tr><th>Họ tên</th><th>Email</th><th>Điện thoại</th><th>Đang mượn</th><th></th></tr></thead><tbody><?php foreach($readers as $r): ?><tr><td><strong><?= e($r['full_name']) ?></strong></td><td><?= e($r['email'] ?: '—') ?></td><td><?= e($r['phone'] ?: '—') ?></td><td><?= e($r['active_loans']) ?></td><td class="actions"><a class="text-link" href="?page=readers&edit=<?= e($r['id']) ?>">Sửa</a><form method="post" onsubmit="return confirm('Xóa độc giả này?')"><?= csrf_field() ?><input type="hidden" name="action" value="reader_delete"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="link danger-text">Xóa</button></form></td></tr><?php endforeach; ?></tbody></table><?php if(!$readers): ?><p class="empty">Chưa có độc giả.</p><?php endif; ?></div></section>
  <?php elseif ($page === 'loans'):
    $books=db()->query('SELECT id,title,copies_available FROM books WHERE copies_available>0 ORDER BY title')->fetchAll(); $readers=db()->query('SELECT id,full_name FROM readers ORDER BY full_name')->fetchAll();
    $loans=db()->query('SELECT l.*,b.title,r.full_name FROM loans l JOIN books b ON b.id=l.book_id JOIN readers r ON r.id=l.reader_id ORDER BY CASE WHEN l.status=\'borrowed\' THEN 0 ELSE 1 END,l.due_date ASC,l.id DESC')->fetchAll(); ?>
    <div class="page-heading"><div><p class="eyebrow">GIAO DỊCH</p><h1>Mượn và trả sách</h1><p>Tạo phiếu mượn và ghi nhận sách được trả.</p></div></div>
    <section class="panel form-panel"><h2>Tạo phiếu mượn</h2><?php if(!$books || !$readers): ?><p class="empty">Cần có ít nhất một sách còn bản và một độc giả trước khi lập phiếu.</p><?php else: ?><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="loan_create"><label>Sách<select name="book_id" required><?php foreach($books as $b): ?><option value="<?= e($b['id']) ?>"><?= e($b['title']) ?> — còn <?= e($b['copies_available']) ?> bản</option><?php endforeach; ?></select></label><label>Độc giả<select name="reader_id" required><?php foreach($readers as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['full_name']) ?></option><?php endforeach; ?></select></label><label>Hạn trả<input type="date" name="due_date" min="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d', strtotime('+14 days'))) ?>"></label><div class="form-actions"><button class="button primary">Tạo phiếu mượn</button></div></form><?php endif; ?></section>
    <section class="panel"><div class="panel-heading"><h2>Danh sách phiếu <span class="count"><?= count($loans) ?></span></h2></div><div class="table-wrap"><table><thead><tr><th>Sách / Độc giả</th><th>Ngày mượn</th><th>Hạn trả</th><th>Trạng thái</th><th></th></tr></thead><tbody><?php foreach($loans as $l): $overdue=$l['status']==='borrowed' && $l['due_date']<date('Y-m-d'); ?><tr><td><strong><?= e($l['title']) ?></strong><small class="cell-sub"><?= e($l['full_name']) ?></small></td><td><?= e($l['borrowed_on']) ?></td><td><?= e($l['due_date']) ?><?= $overdue ? '<small class="danger-text cell-sub">Quá hạn</small>' : '' ?></td><td><span class="badge <?= e($l['status']) ?>"><?= $l['status']==='borrowed'?'Đang mượn':'Đã trả' ?></span></td><td><?php if($l['status']==='borrowed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="loan_return"><input type="hidden" name="id" value="<?= e($l['id']) ?>"><button class="button small">Ghi nhận trả</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php if(!$loans): ?><p class="empty">Chưa có phiếu mượn.</p><?php endif; ?></div></section>
  <?php else:
    $overdue=db()->query("SELECT l.id,l.borrowed_on,l.due_date,b.title,r.full_name,r.email FROM loans l JOIN books b ON b.id=l.book_id JOIN readers r ON r.id=l.reader_id WHERE l.status='borrowed' AND l.due_date<CURRENT_DATE ORDER BY l.due_date")->fetchAll();
    $popular=db()->query('SELECT b.title,b.author,COUNT(l.id) AS loan_count FROM books b LEFT JOIN loans l ON l.book_id=b.id GROUP BY b.id ORDER BY loan_count DESC,b.title LIMIT 10')->fetchAll(); ?>
    <div class="page-heading"><div><p class="eyebrow">THỐNG KÊ</p><h1>Báo cáo thư viện</h1><p>Theo dõi sách quá hạn và các đầu sách được mượn nhiều.</p></div></div>
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">CẦN XỬ LÝ</p><h2>Sách quá hạn <span class="count"><?= count($overdue) ?></span></h2></div></div><div class="table-wrap"><table><thead><tr><th>Sách</th><th>Độc giả</th><th>Liên hệ</th><th>Hạn trả</th></tr></thead><tbody><?php foreach($overdue as $r): ?><tr><td><?= e($r['title']) ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['email'] ?: '—') ?></td><td><span class="danger-text"><?= e($r['due_date']) ?></span></td></tr><?php endforeach; ?></tbody></table><?php if(!$overdue): ?><p class="empty">Tuyệt vời! Không có phiếu quá hạn.</p><?php endif; ?></div></section>
    <section class="panel"><div class="panel-heading"><h2>Sách được mượn nhiều</h2></div><div class="table-wrap"><table><thead><tr><th>#</th><th>Tên sách</th><th>Tác giả</th><th>Lượt mượn</th></tr></thead><tbody><?php foreach($popular as $i=>$b): ?><tr><td><?= $i+1 ?></td><td><?= e($b['title']) ?></td><td><?= e($b['author']) ?></td><td><?= e($b['loan_count']) ?></td></tr><?php endforeach; ?></tbody></table><?php if(!$popular): ?><p class="empty">Chưa có dữ liệu.</p><?php endif; ?></div></section>
  <?php endif; ?>
  <footer class="footer">Quản lý thư viện · PHP + PostgreSQL · Bản demo học tập</footer>
</main>
</body></html>
