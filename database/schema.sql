-- PostgreSQL schema for the library management demo.
-- Run: psql -U postgres -d library -f database/schema.sql

CREATE TABLE IF NOT EXISTS books (
    id SERIAL PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(160) NOT NULL,
    isbn VARCHAR(32) UNIQUE,
    category VARCHAR(100),
    published_year SMALLINT CHECK (published_year IS NULL OR published_year BETWEEN 0 AND 2200),
    copies_total INTEGER NOT NULL DEFAULT 1 CHECK (copies_total >= 0),
    copies_available INTEGER NOT NULL DEFAULT 1 CHECK (copies_available >= 0 AND copies_available <= copies_total),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS readers (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(160) NOT NULL,
    email VARCHAR(254) UNIQUE,
    phone VARCHAR(40),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS loans (
    id SERIAL PRIMARY KEY,
    book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE RESTRICT,
    reader_id INTEGER NOT NULL REFERENCES readers(id) ON DELETE RESTRICT,
    borrowed_on DATE NOT NULL DEFAULT CURRENT_DATE,
    due_date DATE NOT NULL,
    returned_on DATE,
    status VARCHAR(16) NOT NULL DEFAULT 'borrowed' CHECK (status IN ('borrowed', 'returned')),
    CHECK (due_date >= borrowed_on),
    CHECK ((status = 'borrowed' AND returned_on IS NULL) OR (status = 'returned' AND returned_on IS NOT NULL)),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_loans_status_due_date ON loans(status, due_date);
CREATE INDEX IF NOT EXISTS idx_loans_book_id ON loans(book_id);
CREATE INDEX IF NOT EXISTS idx_loans_reader_id ON loans(reader_id);

INSERT INTO books (title, author, isbn, category, published_year, copies_total, copies_available)
VALUES
    ('Dế Mèn phiêu lưu ký', 'Tô Hoài', '9786042099991', 'Văn học', 1941, 4, 4),
    ('Tuổi thơ dữ dội', 'Phùng Quán', '9786042099992', 'Văn học', 1988, 3, 3),
    ('Clean Code', 'Robert C. Martin', '9780132350884', 'Công nghệ', 2008, 2, 2)
ON CONFLICT (isbn) DO NOTHING;

INSERT INTO readers (full_name, email, phone)
VALUES
    ('Nguyễn Minh Anh', 'minhanh@example.com', '0900000001'),
    ('Trần Gia Bảo', 'giabao@example.com', '0900000002')
ON CONFLICT (email) DO NOTHING;
