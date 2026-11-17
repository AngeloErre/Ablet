-- ABLET · Migrazione 001 · utenti, relazioni, protocolli, attività
-- Compatibile con MySQL 8.0+ e MariaDB 10.6+. Motore InnoDB, utf8mb4.

CREATE TABLE users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pseudonym     CHAR(12)     NOT NULL COMMENT 'identificatore pseudonimo usato in esportazioni e prompt',
  role          ENUM('admin','psychologist','student') NOT NULL,
  login         VARCHAR(190) NOT NULL COMMENT 'per gli studenti: codice di accesso, non e-mail',
  password_hash VARCHAR(255) NOT NULL,
  display_alias VARCHAR(80)  NULL COMMENT 'alias non identificativo scelto dal professionista',
  status        ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_users_pseudonym (pseudonym),
  UNIQUE KEY uq_users_login (login),
  KEY ix_users_role (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE professional_students (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  professional_id BIGINT UNSIGNED NOT NULL,
  student_id      BIGINT UNSIGNED NOT NULL,
  active_from     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  active_to       DATETIME(3) NULL,
  UNIQUE KEY uq_prof_student (professional_id, student_id),
  KEY ix_ps_student (student_id),
  CONSTRAINT fk_ps_prof    FOREIGN KEY (professional_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_student FOREIGN KEY (student_id)      REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_groups (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id   BIGINT UNSIGNED NOT NULL,
  title      VARCHAR(120) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_groups_owner (owner_id),
  CONSTRAINT fk_groups_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_group_members (
  group_id   BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id, student_id),
  CONSTRAINT fk_gm_group   FOREIGN KEY (group_id)   REFERENCES student_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_gm_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Profilo funzionale: nessun campo diagnosi, nessun dato anagrafico.
CREATE TABLE functional_profiles (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id          BIGINT UNSIGNED NOT NULL,
  author_id           BIGINT UNSIGNED NOT NULL,
  age_band            ENUM('8-10','11-13','14-16','17-19','adulto') NOT NULL,
  strengths           TEXT NULL,
  accessibility_needs JSON NULL,
  constraints_text    TEXT NULL,
  preferences         JSON NULL,
  version_no          INT UNSIGNED NOT NULL DEFAULT 1,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_profile_version (student_id, version_no),
  CONSTRAINT fk_fp_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_author  FOREIGN KEY (author_id)  REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE consents (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id       BIGINT UNSIGNED NOT NULL,
  kind             ENUM('informativa','uso_piattaforma','voce','invio_provider_esterno','ricerca') NOT NULL,
  granted_by       ENUM('studente','esercente_responsabilita_genitoriale','entrambi') NOT NULL,
  document_version VARCHAR(40) NOT NULL,
  recorded_by      BIGINT UNSIGNED NOT NULL,
  granted_at       DATETIME(3) NOT NULL,
  revoked_at       DATETIME(3) NULL,
  KEY ix_consents_student (student_id, kind),
  CONSTRAINT fk_consents_student  FOREIGN KEY (student_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_consents_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE protocols (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id     BIGINT UNSIGNED NOT NULL,
  title        VARCHAR(160) NOT NULL,
  template_key VARCHAR(60) NULL,
  archived     TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_protocols_owner (owner_id, archived),
  CONSTRAINT fk_protocols_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE protocol_versions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  protocol_id     BIGINT UNSIGNED NOT NULL,
  version_no      INT UNSIGNED NOT NULL,
  status          ENUM('draft','published') NOT NULL DEFAULT 'draft',
  config_json     JSON NOT NULL,
  compiled_prompt MEDIUMTEXT NULL,
  prompt_hash     CHAR(64) NULL,
  platform_rules_version VARCHAR(20) NULL,
  created_by      BIGINT UNSIGNED NOT NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  published_at    DATETIME(3) NULL,
  UNIQUE KEY uq_protocol_version (protocol_id, version_no),
  CONSTRAINT fk_pv_protocol FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pv_author   FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE protocol_assignments (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  protocol_version_id BIGINT UNSIGNED NOT NULL,
  student_id          BIGINT UNSIGNED NULL,
  group_id            BIGINT UNSIGNED NULL,
  assigned_by         BIGINT UNSIGNED NOT NULL,
  active_from         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  active_to           DATETIME(3) NULL,
  KEY ix_pa_student (student_id, active_to),
  KEY ix_pa_group (group_id, active_to),
  CONSTRAINT ck_pa_target CHECK ((student_id IS NULL) <> (group_id IS NULL)),
  CONSTRAINT fk_pa_version FOREIGN KEY (protocol_version_id) REFERENCES protocol_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pa_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_group   FOREIGN KEY (group_id)   REFERENCES student_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_author  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activities (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id            BIGINT UNSIGNED NOT NULL,
  title               VARCHAR(160) NOT NULL,
  assignment_text     TEXT NOT NULL COMMENT 'consegna',
  completion_criteria TEXT NULL,
  expected_minutes    SMALLINT UNSIGNED NULL,
  agent_enabled       TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = attività senza agente per osservare l''autonomia',
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_activities_owner (owner_id),
  CONSTRAINT fk_activities_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE materials (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activity_id   BIGINT UNSIGNED NOT NULL,
  title         VARCHAR(160) NOT NULL,
  kind          ENUM('testo','link','nota') NOT NULL DEFAULT 'testo',
  content_text  MEDIUMTEXT NULL,
  source_note   VARCHAR(255) NULL,
  checksum      CHAR(64) NOT NULL,
  scan_flags    JSON NULL COMMENT 'segnalazioni euristiche di istruzioni incorporate',
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_materials_activity (activity_id),
  CONSTRAINT fk_materials_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rubrics (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id      BIGINT UNSIGNED NOT NULL,
  title         VARCHAR(160) NOT NULL,
  purpose       ENUM('prestazione','osservazione') NOT NULL,
  scale_min     DECIMAL(6,2) NOT NULL,
  scale_max     DECIMAL(6,2) NOT NULL,
  criteria_json JSON NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT ck_rubric_scale CHECK (scale_max > scale_min),
  CONSTRAINT fk_rubrics_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE provider_configs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id      BIGINT UNSIGNED NOT NULL,
  provider_key  ENUM('demo','gemini','openai','anthropic','deepseek','openai_compatible') NOT NULL,
  label         VARCHAR(80) NOT NULL,
  base_url      VARCHAR(255) NULL,
  model_name    VARCHAR(120) NULL,
  api_key_enc   VARBINARY(1024) NULL COMMENT 'cifrata con libsodium; mai esportata né registrata nei log',
  key_last4     CHAR(4) NULL,
  params        JSON NULL,
  capabilities  JSON NULL,
  monthly_cost_limit DECIMAL(10,2) NULL,
  timeout_s     SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  max_retries   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  fallback_config_id BIGINT UNSIGNED NULL COMMENT 'fallback esplicito: cambia la destinazione dei dati',
  enabled       TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_pc_owner (owner_id, enabled),
  CONSTRAINT fk_pc_owner    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_fallback FOREIGN KEY (fallback_config_id) REFERENCES provider_configs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE professional_settings (
  owner_id   BIGINT UNSIGNED PRIMARY KEY,
  voice      JSON NULL COMMENT 'voce: motore, lingua, velocità, tono',
  avatar     JSON NULL COMMENT 'avatar parlante: aspetto, nome, sincronizzazione labiale',
  retention  JSON NULL COMMENT 'conservazione configurabile',
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_settings_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id   BIGINT UNSIGNED NULL,
  action     VARCHAR(80) NOT NULL,
  entity     VARCHAR(60) NOT NULL,
  entity_id  BIGINT UNSIGNED NULL,
  meta       JSON NULL COMMENT 'mai chiavi API né trascrizioni integrali',
  ip_hash    CHAR(64) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_audit_entity (entity, entity_id),
  KEY ix_audit_actor (actor_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
