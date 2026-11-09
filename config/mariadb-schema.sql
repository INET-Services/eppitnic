-- Who contacts, domains and pending transfers belong to. Reseller 1 is the
-- registrar itself: every admin belongs to it, and it cannot be deactivated
-- (enforced by Service\ResellerService: a CHECK cannot reference `id`).
CREATE TABLE `resellers` (
  `id`                    serial,
  `name`                  varchar(64) NOT NULL,
  -- daily cap on registrations + transfer-in requests; 0 = unlimited
  `max_operations`        int NOT NULL DEFAULT 0,
  `active`                tinyint NOT NULL DEFAULT 1,
  `creation_time`         timestamp DEFAULT CURRENT_TIMESTAMP,
  -- what new contacts and domains start from (see Service\ResellerSettings):
  -- techc is a JSON list of handles, nssets a JSON list of {name, ns[]}, and
  -- dnsset the name of the set new domains start with
  `techc`                 text,
  `countrycode`           varchar(2),
  -- contact type 1-7 new contacts start from; NULL = none
  `entitytype`            tinyint unsigned NULL,
  `nssets`                text,
  `dnsset`                varchar(64),
  -- DNSSEC algorithm and digest type new DS records start from; NULL = none
  `dnssec_algorithm`      tinyint unsigned NULL,
  `dnssec_digesttype`     tinyint unsigned NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `resellers` (`id`, `name`) VALUES (1, 'Registrar (self)');

CREATE TABLE `users` (
  `id`                    serial,
  -- fixed at creation: a user never moves to another reseller
  `reseller_id`           bigint unsigned NOT NULL DEFAULT 1,
  `role`                  enum('admin','manager','user') NOT NULL DEFAULT 'user',
  `description`           varchar(64),
  `username`              varchar(32),
  `password`              varchar(255),
  -- login is refused until the user has changed the password / enrolled MFA
  -- (Auth::withAccount(), POST /v1/users/authenticate)
  `must_change_password`  tinyint NOT NULL DEFAULT 0,
  -- unique: the address a password reset is sent to
  `email`                 varchar(256) NULL,
  -- this user's own email notifications (see Service\Notifier), applied
  -- only while the system-wide `smtp.recipient_mode` includes 'user': a
  -- switch, a JSON list of Notifier::MESSAGE_TYPES values (NULL/empty =
  -- unfiltered) and a plain substring filter
  `notify_enabled`        tinyint NOT NULL DEFAULT 0,
  `notify_message_types`  text,
  `notify_fulltext`       varchar(255),
  `active`                tinyint DEFAULT 1,
  `totp_secret`           varchar(64),
  `totp_secret_pending`   varchar(64),
  `must_enroll_mfa`       tinyint NOT NULL DEFAULT 0,
  -- what a login must prove (see Service\WebAuthn): a passkey, a TOTP code,
  -- either, or nothing beyond the password
  `signin_requirement`    enum('passkey','totp','totp_or_passkey','none') NOT NULL DEFAULT 'totp',
  -- 1: the password alone may register this user's first key; set when a
  -- key becomes required, cleared once the minimum is reached
  `passkey_enroll_open`   tinyint NOT NULL DEFAULT 0,
  -- random, handed to authenticators as the user handle; never the id
  `webauthn_handle`       varbinary(64) NULL,
  `max_token_age`         int,
  `token_lifetime`        smallint unsigned NULL,
  `max_idle_time`         int,
  `last_activity`         datetime DEFAULT NULL,
  `debug`                 tinyint    DEFAULT 0,
  `api_token`             varchar(64),
  `api_token_expires`     bigint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`api_token`),
  UNIQUE KEY (`username`),
  UNIQUE KEY (`email`),
  UNIQUE KEY (`webauthn_handle`),
  -- RESTRICT, not CASCADE: MariaDB refuses a CHECK over a cascading column
  CONSTRAINT FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `admins_belong_to_reseller_1` CHECK (`role` <> 'admin' OR `reseller_id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only a SHA-256 of the emailed token is kept; used_at is set when it is spent
CREATE TABLE `password_resets` (
  `id`                    serial,
  `user_id`               bigint unsigned NOT NULL,
  `token_hash`            char(64) NOT NULL,
  `created_at`            datetime NOT NULL,
  `expires_at`            datetime NOT NULL,
  `used_at`               datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`token_hash`),
  KEY (`user_id`),
  KEY (`expires_at`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the password-reset rate limits count, one row per attempt answered
CREATE TABLE `password_reset_attempts` (
  `id`                    serial,
  `kind`                  enum('request','confirm') NOT NULL,
  `network`               varchar(64) NULL,
  `email_hash`            char(64) NULL,
  `created_at`            datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY (`network`, `created_at`),
  KEY (`email_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- credential_id is the raw id the authenticator chose; source is the
-- WebAuthn library's credential record as JSON
CREATE TABLE `passkeys` (
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
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only a SHA-256 of the challenge is kept; used_at is set when it is spent.
-- A login challenge is signed, not stored: its row appears when it is spent,
-- and the unique hash is what refuses a second use. context is what the
-- request that issued it had verified.
CREATE TABLE `webauthn_challenges` (
  `id`                    serial,
  `purpose`               enum('register','login','stepup') NOT NULL,
  `user_id`               bigint unsigned NULL,
  `challenge_hash`        char(64) NOT NULL,
  `context`               varchar(16) NULL,
  `created_at`            datetime NOT NULL,
  `expires_at`            datetime NOT NULL,
  `used_at`               datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY (`challenge_hash`),
  KEY (`expires_at`),
  KEY (`user_id`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FIDO MDS3 entries by AAGUID
CREATE TABLE `authenticator_metadata` (
  `aaguid`                char(36) NOT NULL,
  `description`           varchar(255) NULL,
  `status`                varchar(64) NULL,
  `entry`                 longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`entry`)),
  `updated_at`            datetime NOT NULL,
  PRIMARY KEY (`aaguid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Also holds `security` rows for non-mutating events (e.g. credential
-- reads, action='secread') -- hence `history`, not `changelog`.
CREATE TABLE `history` (
  `id`                    serial,
  `timestamp`             datetime NOT NULL DEFAULT current_timestamp(),
  -- nullable: a login against a nonexistent username has no user to
  -- attribute it to; defaulting to user 1 would misattribute it.
  `user_id`               bigint unsigned DEFAULT NULL,
  `object`                enum('users', 'contacts', 'domains', 'security', 'cronjobs', 'epp', 'smtp', 'remote_auth', 'trusted_proxies', 'resellers', 'region', 'allowed_origins', 'debugfile', 'dnssec', 'passkeys') NOT NULL,
  `object_id`             int(11) NOT NULL,
  -- 'request': a registration or transfer-in a user asked for (object
  -- 'domains'), what the daily reseller quota counts; 'rotate': the
  -- registry password changed (object 'security')
  `action`                enum('create','update','delete','secread','login','denied','request','rotate') NOT NULL,
  -- client address masked to its rate-limit prefix (`security` rows only);
  -- own column, not JSON, so it can be indexed.
  `network`               varchar(64) DEFAULT NULL,
  `data`                  longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  -- who reviewed this and when; NULL = not yet (security rows only, kept
  -- sparse here rather than a table of their own, at this size). Timestamp
  -- + user, not a flag: who acted matters too. Matches messages.archived_time.
  `acknowledged_time`     datetime DEFAULT NULL,
  `acknowledged_user_id`  bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `object_lookup` (`object`,`object_id`),
  KEY `rate_limit_window` (`network`,`timestamp`),
  -- what an operator opens: the security rows nobody has looked at yet
  KEY `outstanding` (`object`,`acknowledged_time`,`timestamp`),
  CONSTRAINT FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transactions` (
  `id`                    serial,
  `cl_trid`               varchar(64),
  `cl_trtype`             varchar(32),
  `cl_trobject`           varchar(256),
  `cl_trdata`             text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `responses` (
  `id`                    serial,
  `cl_trid`               varchar(64),
  `sv_trid`               varchar(64),
  `sv_code`               varchar(4),
  `status`                tinyint unsigned,
  `sv_httpcode`           smallint unsigned,
  `sv_httpheaders`        text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  `sv_httpdata`           text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  `extvaluereasoncode`    varchar(4),
  `extvaluereason`        text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `msgqueue` (
  `id`                    serial,
  `cl_trid`               varchar(64),
  `sv_trid`               varchar(64),
  `sv_code`               varchar(4),
  `status`                tinyint unsigned,
  `sv_httpcode`           smallint unsigned,
  `sv_httpheaders`        text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads',
  `sv_httpdata`           text COMMENT 'plain EPP body; rows written before 7.0 may carry a deprecated __SERIALIZED: envelope -- read via StoredPayload::decode(), strip with: eppitnic doctor normalize-payloads'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contacts` (
  `id`                    serial,
  `reseller_id`           bigint unsigned NOT NULL DEFAULT 1,
  `status`                text,
  `handle`                varchar(32) unique NOT NULL,
  `name`                  varchar(256),
  `org`                   varchar(256),
  `street`                varchar(256),
  `street2`               varchar(128),
  `street3`               varchar(128),
  `city`                  varchar(128),
  `province`              varchar(128),
  `postalcode`            varchar(16),
  `countrycode`           varchar(2),
  `voice`                 varchar(64),
  `fax`                   varchar(64),
  `email`                 varchar(256),
  `authinfo`              varchar(64),
  `consentforpublishing`  tinyint unsigned,
  `nationalitycode`       varchar(2),
  `entitytype`            tinyint unsigned,
  `regcode`               varchar(36),
  `schoolcode`            varchar(32),
  `active`                tinyint DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY (`handle`),
  CONSTRAINT FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `domains` (
  `id`                    serial,
  -- always the registrant contact's reseller (see Domain::storeDB())
  `reseller_id`           bigint unsigned NOT NULL DEFAULT 1,
  `active`                tinyint DEFAULT 1,
  `status`                text,
  -- domain names compare byte for byte: unicode_ci equates 'città.it' with
  -- 'citta.it'. They are stored as Idn::domain() gives them (lower case, NFC)
  `domain`                varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin unique NOT NULL,
  `authinfo`              varchar(64),
  `ns`                    text,
  `registrant`            varchar(32) NOT NULL,
  `admin`                 varchar(32),
  `tech`                  text,
  `cr_date`               date,
  `ex_date`               date,
  `dnssec`                text,
  `last_invoice`          timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT FOREIGN KEY (registrant) REFERENCES contacts(handle) ON DELETE RESTRICT ON UPDATE CASCADE,
  -- lower case only; LOWER() leaves a capital U+1E9E alone, so it is
  -- tested for apart. tasks.domain follows this column by its foreign key
  CONSTRAINT `domains_domain_lower` CHECK (`domain` = LOWER(`domain`) AND LOCATE(CHAR(0xE1BA9E USING utf8mb4), `domain`) = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transfers` (
  `id`                    serial,
  `reseller_id`           bigint unsigned NOT NULL DEFAULT 1,
  `domain`                varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin unique NOT NULL,
  `techc`                 text,
  `dns`                   text,
  -- the registrant a transfer-trade changes the domain to (kind 'trade');
  -- NULL for a plain transfer, which keeps the domain's registrant
  `registrant`            varchar(32) DEFAULT NULL,
  -- the code the request was made with: it lets the registry answer a query
  -- about a domain another registrar still sponsors
  `authinfo`              varchar(64) DEFAULT NULL,
  `kind`                  enum('transfer','trade') NOT NULL DEFAULT 'transfer',
  `status`                enum('pending','cancelled') NOT NULL DEFAULT 'pending',
  `time`                  timestamp DEFAULT CURRENT_TIMESTAMP,
  -- nameserver updates the registry refused after the transfer completed
  `attempts`              tinyint unsigned NOT NULL DEFAULT 0,
  `attempted_at`          datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT FOREIGN KEY (registrant) REFERENCES contacts(handle) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `transfers_domain_lower` CHECK (`domain` = LOWER(`domain`) AND LOCATE(CHAR(0xE1BA9E USING utf8mb4), `domain`) = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `messages` (
  `id`                    serial,
  `cl_trid`               varchar(64),
  `sv_trid`               varchar(64),
  `type`                  varchar(64) NOT NULL,
  `domain`                varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `ac_id`                 varchar(255),
  `re_id`                 varchar(255),
  `data`                  text NOT NULL,
  `archived_time`         datetime DEFAULT NULL,
  `archived_user_id`      bigint unsigned,
  `created_time`          timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `messages_domain_lower` CHECK (`domain` = LOWER(`domain`) AND LOCATE(CHAR(0xE1BA9E USING utf8mb4), `domain`) = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A queue of two things: human-facing scheduled notices (`object` NULL, e.g.
-- a per-domain reminder someone set by hand) and rows a consumer owns and
-- executes (`object` says which one -- 'registry' for a scheduled domain
-- deletion, 'pdns' for a DNS-sync event). A consumer reads its own `object`,
-- due (`date` <= today) and still `active`; once it has actually run a row it
-- records `executed_time`/`exit_code`/`exit_message` -- a success also clears
-- `active`, a failure or a skip stays active so the next run retries it.
CREATE TABLE `tasks` (
  `id`                    serial,
  `domain`                varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `date`                  date NOT NULL,
  `notice`                varchar(255),
  `email`                 varchar(64),
  `object`                enum('registry','pdns'),
  `action`                enum('create','update','delete'),
  `active`                tinyint DEFAULT 1,
  `created_time`          timestamp DEFAULT CURRENT_TIMESTAMP,
  `executed_time`         timestamp NULL DEFAULT NULL,
  `exit_code`             tinyint,
  `exit_message`          varchar(255),
  `attempts`              tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY (`domain`),
  KEY (`action`),
  KEY (`object`),
  CONSTRAINT FOREIGN KEY (domain) REFERENCES domains(domain) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `key`   varchar(64) NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`value`)),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('schema_version', '"070100"'),
  ('region', '{"timezone":"Europe/Rome","lc_monetary":"it_IT.UTF-8"}'),
  ('jwt_psk', '""'),
  ('safe_networks', '["127.0.0.1/32"]'),
  -- Proxies whose X-Forwarded-For is trusted; empty = none (else
  -- client-supplied). Also decides safe_network membership (skips MFA)
  -- and rate-limit attribution. List only your reverse proxy here.
  ('trusted_proxies', '[]'),
  -- Failed logins per network per timespan (s) before 429; per-network
  -- since IPv6 makes per-address limits useless. /48 is the usual
  -- end-site allocation (narrow via ipv4/6_prefix). 0 disables the limit.
  ('login_ratelimit', '{"max_failures":10,"timespan":900,"ipv4_prefix":24,"ipv6_prefix":48}'),
  ('allowed_origins', '[]'),
  ('allowed_headers', '["Authorization","Content-Type","X-Api-Key","Content-Disposition"]'),
  ('allowed_methods', '["GET","POST","PUT","PATCH","DELETE","OPTIONS"]'),
  -- epp.lastPasswordUpdate: unix time of the last automatic password
  -- rotation attempt (via `eppitnic poll process`), capping it to once
  -- per 24h. 0 = never attempted.
  ('epp', '{"server":"https://epp.nic.it","server_deleted":"https://epp-deleted.nic.it","port":null,"interface":"","registrar_tag":"","username":"","password":"","lang":"en","cl_trid_prefix":"EPPITNIC","lastPasswordUpdate":0}'),
  ('dnssec', '{"active":0}'),
  ('debugfile', '""'),
  ('certificatefile', 'null'),
  -- keepalive: hold one registry session open across processes instead of
  -- logging out per request; refreshed by `session keepalive` before the
  -- registry's 300s timeout. session_* hold that session's state (code-only).
  ('keepalive', 'false'),
  ('session_serialize', 'false'),
  ('session_cookies', '{}'),
  ('session_timestamp', '0'),
  -- pdns: enabled, apis and nameservers must all be non-empty to queue a
  -- sync (PowerDnsApis/PowerDnsNameservers). ttl: NS TTL. delay_hours:
  -- delete grace period. frequency_minutes/last_run_at: cron bookkeeping.
  ('pdns', '{"enabled":false,"apis":[],"nameservers":[],"ttl":3600,"delay_hours":12,"frequency_minutes":15,"last_run_at":null}'),
  -- domain_sync: periodic `domain sync` reconciliation against the registry
  -- (domain check/domain info), plus a refresh of every linked contact via
  -- contact info. enabled: on by default -- turn off via
  -- `config domain-sync off`. batch_size: how many active domains one
  -- run processes. cursor_id: the last domains.id processed, so the next
  -- run resumes after it and wraps to the start once every active domain
  -- has been covered. frequency_minutes/last_run_at: `cron run` bookkeeping.
  ('domain_sync', '{"enabled":true,"batch_size":25,"cursor_id":0,"frequency_minutes":5,"last_run_at":null}'),
  -- domain_reap_deletions: carries out a domain deletion once its
  -- DELETE /v1/domains/{name}?mode=expiry|date schedule comes due. Enabled
  -- by default -- unlike pdns it needs no external infrastructure, only
  -- ever acts on deletions a user explicitly scheduled through the app
  -- itself, and is a no-op until one exists.
  ('domain_reap_deletions', '{"enabled":true,"frequency_minutes":15,"last_run_at":null}'),
  -- poll_process: enabled by default -- rotating the shared EPP password
  -- on a passwdReminder normally runs from here, so this starts on;
  -- turning it off is a real foot-gun, but the operator's call to make.
  ('poll_process', '{"enabled":true,"frequency_minutes":5,"last_run_at":null}'),
  -- smtp: off by default (see Service\Notifier). recipient_mode:
  -- system/user/both/none; recipient is the system mailbox, required
  -- while recipient_mode is system/both. auth_type: plain/tls/starttls.
  -- message_types: Notifier::MESSAGE_TYPES subset, empty = unfiltered.
  -- fulltext: plain substring filter over type/domain/message.
  ('smtp', '{"enabled":false,"host":"localhost","port":null,"sender":"","recipient_mode":"both","recipient":"","username":"","password":"","auth_type":"plain","message_types":[],"fulltext":""}'),
  -- remote_auth: off by default (see Api\Auth/Service\RemoteAuthSettings).
  -- header unset means server mode (REMOTE_USER); set it only when a
  -- trusted proxy injects it. `config remote-auth-set`, `/v1/remote-auth`.
  ('remote_auth', '{"enabled":false,"header":null}'),
  -- passkeys: rp_id empty = disabled (see Service\PasskeySettings). origins:
  -- https origins at or below rp_id. type: any|device_bound. models and
  -- extra_aaguids narrow device_bound.
  ('passkeys', '{"rp_id":"","origins":[],"type":"any","models":[],"extra_aaguids":[]}'),
  -- passkey_challenge_purge: deletes expired WebAuthn challenges, daily.
  ('passkey_challenge_purge', '{"enabled":true,"frequency_minutes":1440,"last_run_at":null}'),
  -- passkey_metadata: fetches the FIDO MDS3 BLOB, daily; passkey_metadata_blob
  -- is the one in authenticator_metadata (serial, next_update, fetched_at).
  ('passkey_metadata', '{"enabled":true,"frequency_minutes":1440,"last_run_at":null}'),
  ('passkey_metadata_blob', '{"serial":null,"next_update":null,"fetched_at":null}');
