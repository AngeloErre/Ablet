-- Le attività esistenti restano preimpostate. Nessuna modifica alle versioni dei protocolli.
ALTER TABLE activities ADD COLUMN activity_mode VARCHAR(20) NOT NULL DEFAULT 'prepared';
