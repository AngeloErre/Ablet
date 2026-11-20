-- Il PIN non viene conservato in chiaro. Il lookup è HMAC con chiave applicativa.
CREATE TABLE access_pins (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 lookup_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 pin_hash VARCHAR(255) NOT NULL,
 issued_version INT UNSIGNED NOT NULL,
 created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
 UNIQUE KEY uq_pin_lookup (lookup_hash),
 CONSTRAINT fk_pin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
