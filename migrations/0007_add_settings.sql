-- Migration 0007 : table "settings" generique (cle/valeur) pour les
-- reglages modifiables depuis une page d'administration, sans avoir a
-- toucher config.php ni redeployer le site (ex : reglages des rappels
-- par email).

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;