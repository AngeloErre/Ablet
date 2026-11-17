-- ABLET · Migrazione 005 · impostazioni privacy del professionista

ALTER TABLE professional_settings
  ADD COLUMN privacy JSON NULL COMMENT 'obbligo di autorizzazioni prima dell''uso, versione dell''informativa';

CREATE INDEX ix_sessions_created ON sessions (created_at);
CREATE INDEX ix_llm_created ON llm_calls (created_at);
