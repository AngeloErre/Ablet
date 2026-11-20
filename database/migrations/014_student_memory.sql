-- Memoria compatta: un delta numerico per sessione, nessuna copia delle chat.
CREATE TABLE student_memory_deltas (
 session_id BIGINT UNSIGNED PRIMARY KEY,
 facts LONGTEXT NOT NULL,
 excluded TINYINT(1) NOT NULL DEFAULT 0,
 CONSTRAINT fk_memory_session FOREIGN KEY(session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE student_memory_settings (
 owner_id BIGINT UNSIGNED NOT NULL,
 student_id BIGINT UNSIGNED NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 guidance VARCHAR(500) NOT NULL DEFAULT '',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(owner_id,student_id),
 CONSTRAINT fk_memory_owner FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_memory_student FOREIGN KEY(student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
