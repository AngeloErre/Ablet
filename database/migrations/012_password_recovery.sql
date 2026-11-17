-- Le impostazioni della posta possono superare 255 caratteri.
ALTER TABLE app_flags MODIFY COLUMN value TEXT NOT NULL;
-- Link monouso: si conserva solo l'hash del token, non il link inviato.
CREATE TABLE password_reset_tokens (
 token_hash CHAR(64) PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 issued_version INT UNSIGNED NOT NULL,
 expires_at DATETIME(3) NOT NULL,
 used_at DATETIME(3) NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 KEY ix_reset_user(user_id,expires_at),
 KEY ix_reset_expiry(expires_at),
 CONSTRAINT fk_reset_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
