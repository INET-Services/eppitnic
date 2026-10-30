-- ============================================================================
-- Migration: resellers.entitytype, the contact type new contacts start from,
-- and the DNSSEC algorithm and digest type new DS records start from
-- (NULL = no default; the form falls back to company, 13 and 2), and the
-- contact email and regcode columns widened to the registry's limits, and
-- users.token_lifetime, a user's own session duration (NULL = 15 minutes),
-- users.email widened to 256 and unique, and the password-reset tables
-- ============================================================================

ALTER TABLE `resellers`
  ADD COLUMN IF NOT EXISTS `entitytype` tinyint unsigned NULL AFTER `countrycode`,
  ADD COLUMN IF NOT EXISTS `dnssec_algorithm` tinyint unsigned NULL AFTER `dnsset`,
  ADD COLUMN IF NOT EXISTS `dnssec_digesttype` tinyint unsigned NULL AFTER `dnssec_algorithm`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `token_lifetime` smallint unsigned NULL AFTER `max_token_age`;

ALTER TABLE `contacts`
  MODIFY COLUMN `email` varchar(256) NULL,
  MODIFY COLUMN `regcode` varchar(36) NULL;

-- An empty address is no address. The lowest id keeps a shared address (the
-- collation compares case-insensitively, as the unique key will); the others
-- lose it, and each is recorded.
UPDATE `users` SET `email` = NULL WHERE `email` = '';

INSERT INTO `history` (`user_id`, `object`, `object_id`, `action`, `data`)
  SELECT NULL, 'users', u.`id`, 'update',
         JSON_OBJECT('email', NULL, 'reason', 'duplicate address removed by the 7.1 upgrade')
  FROM `users` u
  WHERE u.`email` IS NOT NULL
    AND EXISTS (SELECT 1 FROM `users` k WHERE k.`email` = u.`email` AND k.`id` < u.`id`);

UPDATE `users` u JOIN `users` k ON k.`email` = u.`email` AND k.`id` < u.`id`
  SET u.`email` = NULL;

ALTER TABLE `users`
  MODIFY COLUMN `email` varchar(256) NULL,
  ADD UNIQUE KEY IF NOT EXISTS `email` (`email`);

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`          serial,
  `user_id`     bigint unsigned NOT NULL,
  `token_hash`  char(64) NOT NULL,
  `created_at`  datetime NOT NULL,
  `expires_at`  datetime NOT NULL,
  `used_at`     datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`token_hash`),
  KEY (`user_id`),
  KEY (`expires_at`),
  CONSTRAINT FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_attempts` (
  `id`          serial,
  `kind`        enum('request','confirm') NOT NULL,
  `network`     varchar(64) NULL,
  `email_hash`  char(64) NULL,
  `created_at`  datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY (`network`, `created_at`),
  KEY (`email_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('schema_version', '"070100"')
  ON DUPLICATE KEY UPDATE `value` = '"070100"';
