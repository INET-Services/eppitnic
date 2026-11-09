-- ============================================================================
-- Migration: resellers.entitytype, the contact type new contacts start from,
-- and the DNSSEC algorithm and digest type new DS records start from
-- (NULL = no default; the form falls back to company, 13 and 2), and the
-- contact email and regcode columns widened to the registry's limits, and
-- users.token_lifetime, a user's own session duration (NULL = 15 minutes),
-- users.email widened to 256 and unique, the password-reset tables, and
-- passkeys: users.signin_requirement and its companions, the passkeys,
-- webauthn_challenges and authenticator_metadata tables, and their settings,
-- and transfers.authinfo, the code a transfer-in was requested with
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

-- The default 'none' marks signin_requirement as not yet migrated: the UPDATE
-- runs only while it is set, SET DEFAULT 'totp' ends it, so a re-run leaves
-- every user alone.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `signin_requirement` enum('passkey','totp','totp_or_passkey','none') NOT NULL DEFAULT 'none' AFTER `must_enroll_mfa`,
  ADD COLUMN IF NOT EXISTS `passkey_enroll_open` tinyint NOT NULL DEFAULT 0 AFTER `signin_requirement`,
  ADD COLUMN IF NOT EXISTS `webauthn_handle` varbinary(64) NULL AFTER `passkey_enroll_open`,
  ADD UNIQUE KEY IF NOT EXISTS `webauthn_handle` (`webauthn_handle`);

UPDATE `users` SET `signin_requirement` = 'totp'
  WHERE ((`totp_secret` IS NOT NULL AND `totp_secret` <> '') OR `must_enroll_mfa` = 1)
    AND EXISTS (SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                  AND COLUMN_NAME = 'signin_requirement' AND COLUMN_DEFAULT LIKE '%none%');

ALTER TABLE `users` ALTER COLUMN `signin_requirement` SET DEFAULT 'totp';

ALTER TABLE `history`
  MODIFY COLUMN `object` enum('users', 'contacts', 'domains', 'security', 'cronjobs', 'epp', 'smtp', 'remote_auth', 'trusted_proxies', 'resellers', 'region', 'allowed_origins', 'debugfile', 'dnssec', 'passkeys') NOT NULL;

CREATE TABLE IF NOT EXISTS `passkeys` (
  `id`                    serial,
  `user_id`               bigint unsigned NOT NULL,
  `credential_id`         varbinary(1023) NOT NULL,
  `source`                longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`source`)),
  `aaguid`                char(36) NOT NULL,
  `sign_count`            int unsigned NOT NULL DEFAULT 0,
  `backup_eligible`       tinyint NOT NULL DEFAULT 0,
  `backup_state`          tinyint NOT NULL DEFAULT 0,
  `attestation_format`    varchar(32) NOT NULL,
  `attestation_verified`  tinyint NOT NULL DEFAULT 0,
  `transports`            varchar(128) NULL,
  `name`                  varchar(64) NOT NULL,
  `created_at`            datetime NOT NULL,
  `last_used_at`          datetime NULL,
  `last_used_ip`          varchar(45) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`credential_id`),
  KEY (`user_id`),
  CONSTRAINT FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `webauthn_challenges` (
  `id`              serial,
  `purpose`         enum('register','login','stepup') NOT NULL,
  `user_id`         bigint unsigned NULL,
  `challenge_hash`  char(64) NOT NULL,
  `context`         varchar(16) NULL,
  `created_at`      datetime NOT NULL,
  `expires_at`      datetime NOT NULL,
  `used_at`         datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`challenge_hash`),
  KEY (`expires_at`),
  KEY (`user_id`),
  CONSTRAINT FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- tables created by an earlier run of this file lack these
ALTER TABLE `webauthn_challenges`
  ADD COLUMN IF NOT EXISTS `context` varchar(16) NULL AFTER `challenge_hash`,
  ADD UNIQUE KEY IF NOT EXISTS `challenge_hash` (`challenge_hash`);

CREATE TABLE IF NOT EXISTS `authenticator_metadata` (
  `aaguid`       char(36) NOT NULL,
  `description`  varchar(255) NULL,
  `status`       varchar(64) NULL,
  `entry`        longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`entry`)),
  `updated_at`   datetime NOT NULL,
  PRIMARY KEY (`aaguid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('passkeys', '{"rp_id":"","origins":[],"type":"any","models":[],"extra_aaguids":[]}'),
  ('passkey_challenge_purge', '{"enabled":true,"frequency_minutes":1440,"last_run_at":null}'),
  ('passkey_metadata', '{"enabled":true,"frequency_minutes":1440,"last_run_at":null}'),
  ('passkey_metadata_blob', '{"serial":null,"next_update":null,"fetched_at":null}');

ALTER TABLE `transfers`
  ADD COLUMN IF NOT EXISTS `authinfo` varchar(64) DEFAULT NULL AFTER `registrant`;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('schema_version', '"070100"')
  ON DUPLICATE KEY UPDATE `value` = '"070100"';
