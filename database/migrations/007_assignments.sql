-- Le attività esistenti mantengono la precedente visibilità; quelle nuove possono essere riservate.
ALTER TABLE activities ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT 'all';
CREATE TABLE activity_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  protocol_version_id BIGINT UNSIGNED NULL,
  assigned_by BIGINT UNSIGNED NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_activity_student (activity_id, student_id),
  KEY ix_assignment_student (student_id, active),
  CONSTRAINT fk_aa_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_aa_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_aa_version FOREIGN KEY (protocol_version_id) REFERENCES protocol_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_aa_author FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
