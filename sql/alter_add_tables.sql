-- À exécuter seulement si la table reservations existe déjà sans ces colonnes.
ALTER TABLE reservations
  ADD COLUMN table_id VARCHAR(32) NOT NULL DEFAULT '' AFTER note,
  ADD COLUMN table_seats TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER table_id,
  ADD COLUMN table_label VARCHAR(64) NOT NULL DEFAULT '' AFTER table_seats;
