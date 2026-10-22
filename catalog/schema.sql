-- csurgay_catalog séma (MySQL / MariaDB)
-- Futtatás: phpMyAdmin -> csurgay_catalog -> SQL fül -> beilleszt -> Indítás
-- (Az install.php ugyanezt futtatja le, ha a böngészőből szeretnéd.)

CREATE TABLE IF NOT EXISTS course (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(32)  NOT NULL DEFAULT '',
  name        VARCHAR(200) NOT NULL,
  semester    VARCHAR(32)  NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS lecture (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  course_id    INT          NOT NULL,
  lecture_date DATE         NOT NULL,
  title        VARCHAR(200) NOT NULL DEFAULT '',
  is_open      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lecture_day (course_id, lecture_date),
  CONSTRAINT fk_lecture_course FOREIGN KEY (course_id) REFERENCES course(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS student (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  neptun      VARCHAR(16)  NOT NULL,
  name        VARCHAR(200) NOT NULL DEFAULT '',
  note        VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student_neptun (neptun)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS attendance (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  lecture_id  INT          NOT NULL,
  student_id  INT          NOT NULL,
  checked_at  DATETIME     NOT NULL,
  source      VARCHAR(16)  NOT NULL DEFAULT 'web',   -- web | tintin | manual
  name_given  VARCHAR(200) NOT NULL DEFAULT '',      -- a beíráskor megadott név (eltérhet a törzsadattól)
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  UNIQUE KEY uq_att (lecture_id, student_id),
  KEY ix_att_student (student_id),
  CONSTRAINT fk_att_lecture FOREIGN KEY (lecture_id) REFERENCES lecture(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_student FOREIGN KEY (student_id) REFERENCES student(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

-- Minden feldolgozott tintin log sor nyoma: a migráció ettől ismételhető (nem duplikál)
CREATE TABLE IF NOT EXISTS import_raw (
  legacy_key  VARCHAR(64)  NOT NULL PRIMARY KEY,
  username    VARCHAR(255) NOT NULL DEFAULT '',
  key1        VARCHAR(255) NOT NULL DEFAULT '',
  logged_at   DATETIME     NULL,
  status      VARCHAR(16)  NOT NULL,                 -- imported | duplicate | skipped
  attendance_id INT        NULL,
  imported_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;

CREATE TABLE IF NOT EXISTS setting (
  k  VARCHAR(64) NOT NULL PRIMARY KEY,
  v  TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
