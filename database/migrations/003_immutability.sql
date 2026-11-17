-- ABLET · Migrazione 003 · immutabilità delle versioni pubblicate
-- In questo file le istruzioni sono separate dal doppio simbolo del dollaro (vedi bin/migrate.php).

CREATE TRIGGER trg_pv_no_update BEFORE UPDATE ON protocol_versions
FOR EACH ROW
BEGIN
  IF OLD.status = 'published' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ABLET: una versione pubblicata non è modificabile';
  END IF;
END$$

CREATE TRIGGER trg_pv_no_delete BEFORE DELETE ON protocol_versions
FOR EACH ROW
BEGIN
  IF OLD.status = 'published' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ABLET: una versione pubblicata non è eliminabile';
  END IF;
END$$

CREATE TRIGGER trg_sessions_pin_version BEFORE UPDATE ON sessions
FOR EACH ROW
BEGIN
  IF NOT (OLD.protocol_version_id <=> NEW.protocol_version_id) OR NOT (OLD.prompt_hash <=> NEW.prompt_hash) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ABLET: la versione del protocollo di una sessione non è modificabile';
  END IF;
END$$
