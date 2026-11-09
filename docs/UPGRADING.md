# Upgrading

## From 7.0 to 7.1

Back up the database, then pull the new image, or pull the new code and run
`composer install` (7.1 needs PHP 8.2 or later), and start it. The schema
upgrades itself (`070000` to `070100`) on the first connection. Then:

1. **Check `history` for removed e-mail addresses.** `users.email` is now
   unique and 256 characters wide. Where several users shared an address, the
   oldest account keeps it and the others lose it, each recorded as
   "duplicate address removed by the 7.1 upgrade". Empty addresses become
   NULL. Every user needs an address before they can be saved again, and
   `user create` requires `--email`.
2. **Sessions last 15 minutes by default.** Users pick their own duration in
   their profile; admins and managers cap it with the maximum session
   duration (`max_token_age`, default 240 minutes).
3. **Password reset by e-mail** needs SMTP configured and the frontend's
   public origin in `allowed_origins`, because the link points there:

   ```bash
   bin/eppitnic config allowed-origins add https://<public name>
   ```

4. **Remove admin/tech remnants** (Technical Guidelines v4.0). List first,
   then run:

   ```bash
   bin/eppitnic contact cleanup --dry-run
   bin/eppitnic contact cleanup
   ```

5. **Registry password:** `config epp-password` rotates it; if the registry
   already holds a new one, store it with Settings, "use an existing
   password", or `config epp-password --force`.
6. **Set the relying party to switch passkeys on.** Passkeys stay off until
   you do, and no user can be given a requirement that needs one:

   ```bash
   bin/eppitnic config passkeys --rp-id=<HOST_NAME> --origin=https://<HOST_NAME>
   ```

   `<HOST_NAME>` is the host name users open in the browser, never an IP
   address; each origin is an `https` origin at or below it. Settings does the
   same. Changing the host name later deletes every registered passkey.
7. **Review the users on `none`.** An existing user with an authenticator app
   or a pending `must_enroll_mfa` now has the sign-in requirement `totp`; every
   other user has `none` and signs in with the password alone, as before.
   Raise them with `user signin-requirement`, `PUT /v1/users/{id}` or the user
   dialog. New users, and the first admin `eppitnic setup` creates, start on
   `totp`.

   ```sql
   SELECT id, username, role FROM users WHERE signin_requirement = 'none';
   ```

8. **If you set a relying party, let the server reach the FIDO hosts over
   HTTPS.** The daily `passkey_metadata` job downloads the FIDO metadata that
   `device_bound` passkeys are checked against, from `mds3.fidoalliance.org`,
   which redirects to `mds.fidoalliance.org`: allow both (port 443) in the
   firewall and proxy. Without that access it fails and `cron run` exits `70`;
   either switch the job off if you will not use `device_bound`, or import the
   file by hand:

   ```bash
   bin/eppitnic config passkey-metadata-set enabled false
   bin/eppitnic passkey metadata --file=<BLOB_FILE>
   ```

   The crontab line stays the same: `cron run` also runs
   `passkey_challenge_purge` and `passkey_metadata`, both on by default. While
   passkeys are off, `passkey_metadata` does nothing and never fails.
9. **Update API clients and scripts.** `must_enroll_mfa` is accepted and
   ignored, and `GET /v1/users` no longer returns it; use
   `signin_requirement`. `user create --must-enroll-mfa` is gone. A login can
   now also answer `403` with `required` set to `passkey_enrollment`,
   `passkey` or `signin_reset` (see "Forced password change and MFA
   enrollment" in [API.md](API.md)).

   A `registrant` on a transfer request now changes the domain's registrant (a
   transfer-trade); it used to only name the contact the request belonged to.
   Leave it out for a plain transfer, in `POST /v1/domains/{name}/transfer`
   and in `domain transfer request --registrant`. `POST /v1/domains` follows
   its input: no `authinfo` registers (and needs a `registrant`), an
   `authinfo` requests a transfer. Sending one for a free name, or none for a
   taken name, is a `400`. `domain create` only registers, its `--authinfo`
   being the new domain's code, and refuses a registered name: use `domain
   transfer request`.

10. **Domain names are exact.** `domains.domain`, `transfers.domain`,
    `tasks.domain` and `messages.domain` compare byte for byte now, so
    `città.it` and `citta.it` are two domains and SQL written against
    `Example.it` no longer finds `example.it`. The upgrade lower-cases names
    stored with capitals and nothing else changes; names are lower case
    Unicode (`sesamstraße.it`, not `xn--sesamstrae-e4a.it`) from here on, in
    the API, the CLI and the database. The database refuses a name with
    capitals (`Example.it`) in `domains`, `transfers` and `messages`, and
    `tasks` only takes names `domains` holds: SQL of your own has to write
    lower case.

## From 6.x to 7.0

Upgrading a 6.x installation to 7.0 converts `config.xml`, migrates the
database in place and replaces the web interface and CLI scripts with a REST
API and `bin/eppitnic`. **Back up the database first:** the migration drops
data for good and rebuilds every text column, and re-running it undoes
neither. Rehearse on a staging copy.

## Prerequisites

- PHP 8.2 or later with the intl extension, and
  [Composer](https://getcomposer.org/) — dependencies are no longer shipped in
  the repository.
- A full backup of the 6.x database, and a staging copy to rehearse on.
- The 6.x `config.xml`.

## Export what the migration destroys

Recoverable only from a backup afterwards:

- the **`accounting` table**, dropped in full — invoicing has left this
  codebase, to be reimplemented elsewhere
- **`users.billingID`**, dropped — export it if you need the mapping

The migration also drops `users.dns`, which nothing reads.

## Upgrade step by step

1. Install the dependencies:

   ```bash
   composer install
   ```

2. Convert the configuration and migrate the database:

   ```bash
   bin/eppitnic config migrate
   ```

   This converts `config.xml` into `config/config.php` plus the `settings`
   table, and the first connection migrates the schema (see "What the
   migration does"). 6.x's `-REG` username also becomes the registrar tag;
   its `<debugfile>` is not carried over (`config debugfile` turns logging
   on again). Afterwards `config.xml` is unused and can be archived.
   Don't use `eppitnic setup` here: it installs `config/mariadb-schema.sql`
   into an empty database, for a new installation.
3. Create an admin. 6.x had no admin role, so the migration leaves none:

   ```bash
   bin/eppitnic user create <ADMIN_USERNAME> --password='<ADMIN_PASSWORD>' --email=<ADMIN_EMAIL> --role=admin
   ```

4. Reset every other user's password as that admin, through
   `PUT /v1/changepassword/{id}`. 6.x stored MD5 hashes, which 7.0 cannot
   convert, so no other login works until its password is reset.
5. Review the resellers the migration created: rename them, set their
   quotas, and add further users.
6. Point the web server's document root at `public/`, with every request that
   isn't a real file routed to `public/index.php` (see "Configure the web
   server" in [INSTALL.md](INSTALL.md)).
7. Replace the 6.x cron entries with the single `cron run` line (see
   "Scheduled jobs" in [INSTALL.md](INSTALL.md)).
8. Point your client at the REST API ([API.md](API.md)). The PHP/Smarty/jQuery
   web interface is gone; clients authenticate with bearer tokens instead of
   PHP sessions.

## What the migration does

`Config` compares `settings.schema_version` with `SCHEMA_VERSION` on every
initialization and applies the `config/mariadb-schema-upgrade-*.sql` steps in
order. From 6.x that means:

- **Pre-flight checks** run first, read-only, and abort with the database
  untouched on a problem. In practice that is domain names or usernames
  (usernames are unique now) that would collide under the new collation,
  which ignores case, accents and trailing spaces. Resolve those and re-run.
- Tables lose their `tbl_` prefix and are converted to `utf8mb4`; columns are
  renamed from camelCase to snake_case, and `reminder` becomes `tasks`.
- HTML entities 6.x stored in text columns are decoded (`Rossi &amp; Figli`
  becomes `Rossi & Figli`).
- **Resellers** are introduced: contacts, domains and pending transfers
  belong to a reseller instead of a user, and every user gets a role
  (`admin`, `manager`, `user`). Nobody sees more afterwards than before:
  - reseller 1, "Registrar (self)", gets user 1 — the account 6.x filled in
    when no owner was given — together with user 1's defaults;
  - every other user gets a reseller of their own, named after the username,
    with their daily quota and default tech contacts;
  - every migrated user becomes their reseller's `manager` and keeps
    receiving notification emails;
  - contacts, domains and transfers follow their owner into that reseller.

The migration's verification part lists where every user landed (6i) and
reports any domain whose registrant belongs to another reseller (6h).

`handleID` (MyISAM, utf8mb3) is left alone; it belongs to no schema still in
use. Drop it yourself once you have confirmed it is unneeded.

## Breaking changes for code using the library

- Classes moved from `Net/EPP` to `src/` under the `Eppitnic\` namespace and
  are loaded by Composer's autoloader: `Net_EPP_IT_Domain` is
  `Eppitnic\Epp\Domain`, `Net_EPP_Client` is `Eppitnic\Epp\Client`, and so on.
- `Net_EPP_StorageDB` and `Net_EPP_StorageInterface` are gone; persistence
  uses RedBeanPHP's `R::` facade directly. Custom storage backends need
  rewriting.
- The `CLI/`, `examples/` and `cronjobs/` scripts are gone, replaced by
  `bin/eppitnic` commands. WSDL support is gone.
- `Domain->get('tech')` always returns an array (handle => handle). It
  returned a bare string for a single technical contact — drop any branch
  special-casing that.
- `Domain->check()` and `Contact->check()` return a `CheckResult` instead of
  `array|bool|int`. Replace `=== true` with `->available()` and the `-1`/`-2`
  sentinels with `->answered()`; `->all()` gives every answer keyed by name.
- `Client->sendRequest()` returns an `HttpResponse`, so `$object->result` is
  `?HttpResponse`: `$result['code']` becomes `$result?->code`.
- Contacts, domains and pending transfers are owned by a reseller
  (`reseller_id`) instead of a user, and every read and write is scoped by a
  `Scope`. A domain's registrant must be one of its reseller's contacts.
- `users.maxOperations` and the per-user defaults (`techc`) moved to the
  reseller: `resellers.max_operations` and `/v1/resellers/{id}/settings`.

[COOKBOOK.md](COOKBOOK.md) shows the current library API.

## Clean up after the upgrade

Check that every domain and its registrant belong to the same reseller; 6.x
never enforced it:

```bash
bin/eppitnic doctor ownership
```

See "Check that domains and registrants agree" in [INSTALL.md](INSTALL.md)
for fixing a mismatch.

After upgrading to the v4.0-compatible release, `contact cleanup --dry-run`
shows the admin/tech references and unused contacts that can be removed; see
"Remove unused contacts" in [INSTALL.md](INSTALL.md):

```bash
bin/eppitnic contact cleanup --dry-run
```

Messages stored before this release may carry `type = 'unknown'` and an empty
`domain` (mostly DNS validation failures). New messages parse correctly; to
fix old rows:

```bash
bin/eppitnic doctor reparse-messages --dry-run   # report what would change
bin/eppitnic doctor reparse-messages             # rewrite type/domain
```

It rewrites only those two columns, and is optional: nothing depends on it.

6.x also stored EPP bodies as `__SERIALIZED:` + base64(serialize(…)) in
`transactions.cl_trdata`, `responses.sv_httpdata`/`sv_httpheaders`/
`extvaluereason`, and `msgqueue.sv_httpdata`/`sv_httpheaders`. Nothing writes
that format any more and reads handle either shape, so rewriting them is
optional cleanup:

```bash
bin/eppitnic doctor normalize-payloads --dry-run   # count enveloped rows
bin/eppitnic doctor normalize-payloads             # rewrite as plain bodies
```

The rewrite is one-way; rows that can't be decoded are reported and left
alone.

Then continue with [INSTALL.md](INSTALL.md) for the rest of the
configuration: email notifications, safe networks, trusted proxies and
session keep-alive.
