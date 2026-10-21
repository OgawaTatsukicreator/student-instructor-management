-- 1. 特権テーブル (roles)
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY, -- 権限ID
    name VARCHAR(50) NOT NULL          -- 特権名
);

-- 2. 講師テーブル (teachers)
CREATE TABLE teachers (
    id INT AUTO_INCREMENT PRIMARY KEY, -- 講師ID
    last_name VARCHAR(50) NOT NULL,    -- 姓
    first_name VARCHAR(50) NOT NULL,   -- 名
    email VARCHAR(255) NOT NULL UNIQUE,-- メールアドレス（重複不可）
    password VARCHAR(255) NOT NULL,    -- パスワード
    role_id INT NOT NULL,              -- 権限ID (外部キー)
    notes TEXT,                        -- 特記事項
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

-- 3. 生徒テーブル (students)
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY, -- 生徒ID
    last_name VARCHAR(50) NOT NULL,    -- 姓
    first_name VARCHAR(50) NOT NULL,   -- 名
    birth_date DATE,                   -- 生年月日
    notes TEXT                         -- 特記事項
);

-- 4. 生徒対応テーブル (student_teacher 中間テーブル)
CREATE TABLE student_teacher (
    student_id INT NOT NULL,           -- 生徒ID (外部キー)
    teacher_id INT NOT NULL,           -- 講師ID (外部キー)
    PRIMARY KEY (student_id, teacher_id), -- 複合主キー（同じ生徒と講師の組み合わせの重複を防ぐ）
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);