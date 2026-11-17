ALTER TABLE consents MODIFY COLUMN kind ENUM('informativa','uso_piattaforma','voce','invio_provider_esterno','ricerca','audio_provider') NOT NULL;
