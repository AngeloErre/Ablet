-- Profili privati degli account professionali. Il tipo professionale è autodichiarato.
CREATE TABLE professional_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  full_name VARCHAR(80) NOT NULL,
  profession ENUM('educator','psychologist') NOT NULL,
  organization VARCHAR(160) NULL,
  biography TEXT NULL,
  accepted_notice_version VARCHAR(40) NULL,
  accepted_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_professional_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
