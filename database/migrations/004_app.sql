-- ABLET · Migrazione 004 · limitazione delle richieste, registro chiamate LLM, proposte di adattamento

CREATE TABLE rate_limits (
  bucket       VARCHAR(160) PRIMARY KEY,
  window_start DATETIME(3) NOT NULL,
  hits         INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro tecnico delle chiamate ai provider: mai contenuti, mai chiavi.
CREATE TABLE llm_calls (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider_config_id BIGINT UNSIGNED NULL,
  owner_id           BIGINT UNSIGNED NULL,
  session_id         BIGINT UNSIGNED NULL,
  purpose            ENUM('turno','annotazione','prova','simulazione') NOT NULL,
  provider_key       VARCHAR(40) NOT NULL,
  model_name         VARCHAR(120) NULL,
  status             VARCHAR(30) NOT NULL,
  http_status        SMALLINT UNSIGNED NULL,
  tokens_in          INT UNSIGNED NULL,
  tokens_out         INT UNSIGNED NULL,
  duration_ms        INT UNSIGNED NULL,
  is_fallback        TINYINT(1) NOT NULL DEFAULT 0,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_llm_config (provider_config_id, created_at),
  KEY ix_llm_session (session_id),
  CONSTRAINT fk_llm_config  FOREIGN KEY (provider_config_id) REFERENCES provider_configs(id) ON DELETE SET NULL,
  CONSTRAINT fk_llm_owner   FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_llm_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE provider_configs
  ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN monthly_token_limit INT UNSIGNED NULL COMMENT 'tetto mensile di token (ingresso+uscita)';

ALTER TABLE sessions
  ADD COLUMN pending_adaptation_id BIGINT UNSIGNED NULL COMMENT 'proposta di adattamento in attesa della risposta dello studente';
