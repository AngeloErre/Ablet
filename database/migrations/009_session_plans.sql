CREATE TABLE session_plans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,
 student_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 KEY ix_plan_student (student_id, status),
 CONSTRAINT fk_plan_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT fk_plan_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE session_plan_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 plan_id BIGINT UNSIGNED NOT NULL,
 position INT UNSIGNED NOT NULL,
 activity_id BIGINT UNSIGNED NOT NULL,
 protocol_version_id BIGINT UNSIGNED NULL,
 session_id BIGINT UNSIGNED NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 UNIQUE KEY uq_plan_position (plan_id, position),
 UNIQUE KEY uq_plan_session (session_id),
 CONSTRAINT fk_step_plan FOREIGN KEY (plan_id) REFERENCES session_plans(id) ON DELETE CASCADE,
 CONSTRAINT fk_step_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT,
 CONSTRAINT fk_step_protocol FOREIGN KEY (protocol_version_id) REFERENCES protocol_versions(id) ON DELETE RESTRICT,
 CONSTRAINT fk_step_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
