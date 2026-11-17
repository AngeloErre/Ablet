-- ABLET · Migrazione 002 · sessioni, messaggi, eventi, stato SRL, adattamenti, annotazioni

CREATE TABLE sessions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id           CHAR(16) NOT NULL,
  student_id          BIGINT UNSIGNED NOT NULL,
  activity_id         BIGINT UNSIGNED NOT NULL,
  protocol_version_id BIGINT UNSIGNED NULL COMMENT 'NULL solo per attività senza agente',
  prompt_hash         CHAR(64) NULL COMMENT 'copia dell''hash del prompt usato: la sessione resta legata alla versione',
  state               ENUM('created','forethought','performance','self_reflection','paused','safety_hold','closed_complete','closed_incomplete') NOT NULL DEFAULT 'created',
  resume_state        ENUM('forethought','performance','self_reflection') NULL,
  state_version       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'blocco ottimistico: più schede, richieste concorrenti',
  support_level       TINYINT UNSIGNED NOT NULL DEFAULT 3,
  step_size           TINYINT UNSIGNED NOT NULL DEFAULT 3,
  srl_data            JSON NOT NULL COMMENT 'slot della fase di forethought e riflessione; valori dichiarati dallo studente',
  provider_key        VARCHAR(40) NULL,
  model_name          VARCHAR(120) NULL,
  is_demo             TINYINT(1) NOT NULL DEFAULT 0,
  is_simulation       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'studente sintetico',
  started_at          DATETIME(3) NULL,
  ended_at            DATETIME(3) NULL,
  close_reason        VARCHAR(60) NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_sessions_public (public_id),
  KEY ix_sessions_student (student_id, created_at),
  KEY ix_sessions_activity (activity_id),
  KEY ix_sessions_state (state),
  CONSTRAINT ck_sessions_support CHECK (support_level BETWEEN 0 AND 3),
  CONSTRAINT ck_sessions_step CHECK (step_size BETWEEN 1 AND 5),
  CONSTRAINT fk_sessions_student  FOREIGN KEY (student_id)  REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sessions_version  FOREIGN KEY (protocol_version_id) REFERENCES protocol_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE session_goals (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id        BIGINT UNSIGNED NOT NULL,
  position          SMALLINT UNSIGNED NOT NULL,
  description       VARCHAR(500) NOT NULL,
  estimated_minutes SMALLINT UNSIGNED NULL,
  status            ENUM('pending','active','completed','not_completed','dropped') NOT NULL DEFAULT 'pending',
  hints_used        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  started_at        DATETIME(3) NULL,
  ended_at          DATETIME(3) NULL,
  KEY ix_goals_session (session_id, position),
  CONSTRAINT fk_goals_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id    BIGINT UNSIGNED NOT NULL,
  role          ENUM('student','agent','system') NOT NULL,
  modality      ENUM('text','voice') NOT NULL DEFAULT 'text' COMMENT 'voice = trascrizione confermata; l''audio non è conservato',
  content       TEXT NOT NULL,
  phase         ENUM('forethought','performance','self_reflection','safety_hold') NOT NULL,
  in_reply_to   BIGINT UNSIGNED NULL COMMENT 'messaggio dell''agente che ha sollecitato la produzione',
  is_simulated  TINYINT(1) NOT NULL DEFAULT 0,
  llm_meta      JSON NULL COMMENT 'campi interni dell''output strutturato: mai inviati al frontend',
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_messages_session (session_id, id),
  CONSTRAINT fk_messages_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_reply   FOREIGN KEY (in_reply_to) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro eventi append-only: fonte dei timer e degli indicatori.
CREATE TABLE events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id      BIGINT UNSIGNED NOT NULL,
  actor           ENUM('student','agent','professional','system') NOT NULL,
  type            VARCHAR(60) NOT NULL,
  payload         JSON NULL,
  client_event_id CHAR(36) NULL COMMENT 'chiave di idempotenza generata dal client',
  client_ts       DATETIME(3) NULL COMMENT 'solo informativo: i calcoli usano server_ts',
  server_ts       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_events_idem (session_id, client_event_id),
  KEY ix_events_session (session_id, id),
  KEY ix_events_type (session_id, type),
  CONSTRAINT fk_events_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE state_transitions (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  action     VARCHAR(40) NOT NULL,
  from_state VARCHAR(30) NOT NULL,
  to_state   VARCHAR(30) NOT NULL,
  actor      ENUM('student','agent','professional','system') NOT NULL,
  event_id   BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_st_session (session_id, id),
  CONSTRAINT fk_st_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_st_event   FOREIGN KEY (event_id)   REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Autovalutazioni dello studente: tenute separate da dati osservati e da valutazioni professionali.
CREATE TABLE self_assessments (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  goal_id    BIGINT UNSIGNED NULL,
  kind       ENUM('autoefficacia','difficolta_attesa','stima_tempo_min','sicurezza','valutazione_risultato','reazione_emotiva','studio_fuori_app_min') NOT NULL,
  value_num  DECIMAL(8,2) NULL,
  value_text VARCHAR(500) NULL,
  scale_min  DECIMAL(6,2) NULL,
  scale_max  DECIMAL(6,2) NULL,
  message_id BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_sa_session (session_id, kind),
  CONSTRAINT fk_sa_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_sa_goal    FOREIGN KEY (goal_id)    REFERENCES session_goals(id) ON DELETE SET NULL,
  CONSTRAINT fk_sa_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valutazioni del professionista (prestazione con rubrica; osservazione).
CREATE TABLE professional_ratings (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT UNSIGNED NOT NULL,
  goal_id    BIGINT UNSIGNED NULL,
  rubric_id  BIGINT UNSIGNED NOT NULL,
  rater_id   BIGINT UNSIGNED NOT NULL,
  score      DECIMAL(6,2) NULL,
  detail     JSON NULL,
  note       TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_pr_session (session_id),
  CONSTRAINT fk_pr_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_goal    FOREIGN KEY (goal_id)    REFERENCES session_goals(id) ON DELETE SET NULL,
  CONSTRAINT fk_pr_rubric  FOREIGN KEY (rubric_id)  REFERENCES rubrics(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pr_rater   FOREIGN KEY (rater_id)   REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE professional_notes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  author_id  BIGINT UNSIGNED NOT NULL,
  body       TEXT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_notes_student (student_id, created_at),
  CONSTRAINT fk_notes_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notes_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_notes_author  FOREIGN KEY (author_id)  REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modello dello studente persistente (stato corrente; la storia è in adaptations).
CREATE TABLE student_models (
  student_id    BIGINT UNSIGNED PRIMARY KEY,
  support_level TINYINT UNSIGNED NOT NULL DEFAULT 3,
  step_size     TINYINT UNSIGNED NOT NULL DEFAULT 3,
  preferences   JSON NULL COMMENT 'preferenze espresse dallo studente, es. blocco del livello di supporto',
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_sm_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE adaptations (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id         BIGINT UNSIGNED NOT NULL,
  student_id         BIGINT UNSIGNED NOT NULL,
  source             ENUM('regola','professionista','preferenza_studente') NOT NULL,
  rule_key           VARCHAR(80) NULL,
  rule_snapshot      JSON NULL,
  action             VARCHAR(60) NOT NULL,
  outcome            ENUM('applicata','proposta','sospesa_per_preferenza') NOT NULL,
  indicators         JSON NOT NULL COMMENT 'soli dati osservati',
  evidence_event_ids JSON NOT NULL,
  prev_support_level TINYINT UNSIGNED NOT NULL,
  new_support_level  TINYINT UNSIGNED NOT NULL,
  prev_step_size     TINYINT UNSIGNED NOT NULL,
  new_step_size      TINYINT UNSIGNED NOT NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_adapt_session (session_id, id),
  KEY ix_adapt_student (student_id, created_at),
  CONSTRAINT fk_adapt_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_adapt_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE codebook_versions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version      VARCHAR(20) NOT NULL,
  content_json JSON NOT NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_codebook_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Annotazioni append-only: una correzione crea una nuova riga che punta alla precedente.
CREATE TABLE text_annotations (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id          BIGINT UNSIGNED NOT NULL,
  message_id          BIGINT UNSIGNED NOT NULL,
  code_key            VARCHAR(40) NULL COMMENT 'NULL = astensione',
  segment_text        VARCHAR(1000) NULL,
  segment_start       INT UNSIGNED NULL,
  segment_end         INT UNSIGNED NULL,
  srl_phase           ENUM('forethought','performance','self_reflection') NOT NULL,
  rationale           VARCHAR(500) NULL COMMENT 'motivazione osservabile, non ragionamento interno del modello',
  uncertainty         ENUM('bassa','media','alta','astensione') NOT NULL,
  elicitation         ENUM('spontanea','sollecitata','non_determinabile') NOT NULL,
  annotator_type      ENUM('modello','umano') NOT NULL,
  annotator_user_id   BIGINT UNSIGNED NULL,
  model_name          VARCHAR(120) NULL,
  codebook_version_id BIGINT UNSIGNED NOT NULL,
  review_status       ENUM('da_rivedere','confermata','corretta','rifiutata') NOT NULL DEFAULT 'da_rivedere',
  supersedes_id       BIGINT UNSIGNED NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_ta_session (session_id, code_key),
  KEY ix_ta_message (message_id),
  CONSTRAINT fk_ta_session  FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_message  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_ta_codebook FOREIGN KEY (codebook_version_id) REFERENCES codebook_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ta_user     FOREIGN KEY (annotator_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ta_prev     FOREIGN KEY (supersedes_id) REFERENCES text_annotations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
