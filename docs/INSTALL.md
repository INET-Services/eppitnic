# Installation

Install eppitnic on a server with PHP and MariaDB, run the setup once, and
schedule one cron line. To run it in containers instead, see
[DOCKER.md](DOCKER.md); to upgrade a 6.x installation, see
[UPGRADING.md](UPGRADING.md).

## Quick start

You need PHP 8.1 or later with the curl, XML, PDO MySQL and intl extensions,
[Composer](https://getcomposer.org/), MariaDB or MySQL, a web server with
TLS, and the credentials of your nic.it EPP account.

1. Install the dependencies from the repository root:

   ```bash
   composer install
   ```

2. Create a database and a user for it:

   ```sql
   CREATE DATABASE <DATABASE_NAME>;
   GRANT ALL PRIVILEGES ON <DATABASE_NAME>.* TO '<DB_USER>'@'localhost' IDENTIFIED BY '<DB_PASSWORD>';
   FLUSH PRIVILEGES;
   ```

3. Give the web server user write access to `config/`:

   ```bash
   chown <WEB_USER> config/
   ```

   Setup writes `config/config.php` there, readable by its owner only. Run
   every `bin/eppitnic` command below — and the cron job — as that same user.
4. Configure a virtual host from `config/apache-vhost.sample` or
   `config/nginx-vhost.sample` (see "Configure the web server").
5. Run the setup, either on the command line:

   ```bash
   sudo -u <WEB_USER> bin/eppitnic setup
   ```

   or in a browser at `https://<YOUR_HOSTNAME>/setup.html`. Both ask for the
   database credentials from step 2, the first admin account, your registrar
   tag (the `…-REG` ID nic.it gave you), and the EPP account's username and
   password. The username starts as the registrar tag; change it if you log
   in as an EPP user.
6. If the EPP account is one for the public test registry, switch to it:

   ```bash
   sudo -u <WEB_USER> bin/eppitnic config epp-server test
   ```

7. Add the scheduler to the web server user's crontab (see "Scheduled jobs"),
   and create its log directory, writable by that user:

   ```text
   * * * * *  /path/to/bin/eppitnic cron run >> /var/log/eppitnic/cron.log 2>&1
   ```

`<DATABASE_NAME>`, `<DB_USER>` and `<DB_PASSWORD>` are yours to choose.
`<WEB_USER>` is the user the web server runs PHP as (`www-data` on Debian and
Ubuntu), and `<YOUR_HOSTNAME>` the virtual host's name.

### Verify the installation

1. Check the API routing — the route is public:

   ```bash
   curl -i https://<YOUR_HOSTNAME>/v1/network-check
   ```

   The expected result is a JSON body, `{"safe_network": ..., "client_ip": ...}`.
   An HTML 404 means the front-controller rule is missing (see "Configure the
   web server").
2. Log in as the admin created during setup, and keep the `token` from the
   answer:

   ```bash
   curl -s -X POST https://<YOUR_HOSTNAME>/v1/users/authenticate \
     -H "Content-Type: application/json" \
     -d '{"username":"<ADMIN_USERNAME>","password":"<ADMIN_PASSWORD>"}' | jq -r .token
   ```

3. Check the stored EPP configuration (local only, no registry contact):

   ```bash
   curl -s https://<YOUR_HOSTNAME>/v1/session/epp \
     -H "Authorization: Bearer <TOKEN>" | jq .
   ```

   `server` must be the registry you intend, and `password_set` `true`.
4. Log in to the registry end to end, by asking it for the account's credit:

   ```bash
   curl -s https://<YOUR_HOSTNAME>/v1/session/credit \
     -H "Authorization: Bearer <TOKEN>" | jq .
   ```

   The expected result is `{"credit": ...}`. A `502` means the registry
   login failed: check the EPP username and password, and that the registry
   accepts connections from this server's address (on a server with several
   addresses, choose the outgoing one with `config epp-set interface`).
   `sudo -u <WEB_USER> bin/eppitnic session credit` runs the same check from
   the command line.

`<TOKEN>` is the value printed in step 2.

### After the first start

- Behind a reverse proxy, list it in `trusted_proxies` (see "Login rate
  limiting").
- Every browser client needs its origin in `allowed_origins`, including a
  frontend on the API's own host (see "Configuration").
- To exercise the whole registry lifecycle against the public test registry,
  run `selftest` (see "Test against the live test registry" in
  [TESTING.md](TESTING.md)).

## Set up without the installer

To do by hand what `eppitnic setup` does:

1. Copy `config/config.php-template` to `config/config.php` and fill in the
   database credentials.
2. Apply `config/mariadb-schema.sql` to the database.
3. Create the first admin:

   ```bash
   bin/eppitnic user create <ADMIN_USERNAME> --password='<ADMIN_PASSWORD>' --email=<ADMIN_EMAIL> --role=admin
   ```

4. Set the registrar tag and the EPP account (see "Change the EPP account
   settings"):

   ```bash
   bin/eppitnic config epp-set registrar_tag <REGISTRAR_TAG>
   bin/eppitnic config epp-set username <EPP_USERNAME>
   ```

`eppitnic setup` also takes every answer as a flag (`--db-name=`,
`--admin-username=`, `--registrar-tag=`, …) for a scripted install.
`--registrar-tag` is required; `--epp-username` defaults to it, and the
clTRID prefix to the tag without `-REG`.

## Configuration

Configuration is split in two:

1. `config/config.php` holds the database credentials only. Once it exists,
   `eppitnic setup` refuses to run again and the setup routes answer `404`.
2. Everything else lives in the `settings` table, seeded by
   `config/mariadb-schema.sql`. `jwt_psk` is generated on first connection,
   and setup fills in the EPP `registrar_tag`, `username`, `password` and
   `cl_trid_prefix`.
   Everything else can stay at its default. Leave `epp.lastPasswordUpdate`
   at `0` on a fresh install (see "Registry password rotation").

`allowed_origins` lists the browser origins allowed to call the API. Every
browser client needs its origin there, including a frontend served from the
API's own host: browsers send `Origin` on same-origin writes too.

```bash
bin/eppitnic config allowed-origins                                 # show
bin/eppitnic config allowed-origins add https://<APP_HOSTNAME>      # scheme, host, optional port
bin/eppitnic config allowed-origins remove https://<APP_HOSTNAME>
bin/eppitnic config allowed-origins clear
```

Entries are stored as browsers send them: lower case, without a default port
or trailing slash. A change takes effect with the next request and is
recorded in `history` (`object='allowed_origins'`). Admins can make the same
changes through `GET`/`PUT /v1/allowed-origins` or the frontend's Settings.
Clients without an `Origin` header (curl, scripts) are not affected — see
"CORS" in [API.md](API.md).

The schema is versioned: `settings.schema_version` (`MMmmrr`, e.g. `070100`)
is compared with `SCHEMA_VERSION` in `config/constants.php` on every
initialization, and the `config/mariadb-schema-upgrade-{from}-to-{to}.sql`
files are applied in sequence. One process per database migrates at a
time; others wait up to 60 seconds for it. A new migration is one more such
file plus a bumped `SCHEMA_VERSION`.

### Show the current settings

```bash
bin/eppitnic config show          # every setting
bin/eppitnic config show epp      # just one
```

Secrets (`jwt_psk`, the registry password) are never printed; `epp` reports
`password_set` and `rotation_pending` instead, like `GET /v1/session/epp`.

### Set the time zone and locale

The `region` setting holds the time zone every request and CLI command runs
in, including the scheduled jobs, and the locale amounts are formatted in:

```bash
bin/eppitnic config region-set timezone Europe/Rome
bin/eppitnic config region-set lc_monetary it_IT.UTF-8
```

The time zone must be a zone name PHP knows. The locale is read through ICU,
so it needs no system locale. Admins can make the same changes through `GET`/`PATCH /v1/region`.

### Turn DNSSEC on or off

The `dnssec` setting is off by default. Off, login does not announce the
secDNS extensions, and creating or updating a domain with DS records is
refused. Algorithm and digest type are given per DS record, not here:

```bash
bin/eppitnic config dnssec on
bin/eppitnic config dnssec off
```

Admins can make the same change through `GET`/`PATCH /v1/dnssec`. Turning it
on takes effect for the next registry session; with `keepalive` on, that is
after the open session has ended.

### Change the EPP account settings

Give a value to set one of the `epp` setting's 8 plain fields, or omit it to
unset the field. `server`, `registrar_tag`, `username`, `lang` and
`cl_trid_prefix` are required and cannot be unset; `server` also has a preset verb (see "Switch
between the test and production registry").

```bash
bin/eppitnic config epp-set server https://epp.nic.it          # must be https://
bin/eppitnic config epp-set server_deleted https://epp-deleted.nic.it
bin/eppitnic config epp-set port 8443                          # 1-65535, or omit the value to unset
bin/eppitnic config epp-set interface 203.0.113.5              # IPv4 only, or omit the value to unset
bin/eppitnic config epp-set lang it                            # 'it' or 'en'
bin/eppitnic config epp-set cl_trid_prefix MYPREFIX            # 1-32 characters, A-Z and 0-9 only
bin/eppitnic config epp-set registrar_tag MYCOMPANY-REG        # upper case, ending '-REG', up to 64 characters
bin/eppitnic config epp-set username mario.rossi               # 3-64 characters
```

`registrar_tag` is your registrar's ID, the name registry poll messages use
for you: `poll process` compares it with a transfer's acting registrar to
tell a transfer away from you from one to you. `username` is the login,
which is the registrar tag itself unless you log in as an EPP user. An
installation without a tag uses the username in its place.

Admins can make the same changes through `GET`/`PATCH /v1/session/epp`. Every
change is recorded in `history` (`object='epp'`).

The registry `password` is not one of these fields: a value this installation
and the registry disagree about breaks every EPP call, so it is changed
through the registry.

```bash
bin/eppitnic config epp-password <NEW_PASSWORD>             # changes it at the registry, then stores it
bin/eppitnic config epp-password --force <LIVE_PASSWORD>    # verifies it with a login, then adopts it as-is
```

Give the password as `<NEW_PASSWORD>`, or keep it out of the process list
with `--password-stdin` (one line; add `--yes`, as there is no terminal to
confirm at). With neither, a terminal is asked for it twice, hidden. Nothing
is generated.

Both take 6 to 16 characters (EPP `pwType`) and are verified with a real
login before anything is written, so a refused password changes nothing
locally. They update `lastPasswordUpdate`, `password_set` and
`rotation_pending` the same way the automatic rotation does.

### Switch between the test and production registry

```bash
bin/eppitnic config epp-server              # show the current endpoint
bin/eppitnic config epp-server test         # -> https://epp.pubtest.nic.it
bin/eppitnic config epp-server production   # -> https://epp.nic.it
bin/eppitnic config epp-server toggle       # switch to whichever it isn't
```

This changes the local `server` and `server_deleted` settings only, the
latter to `https://epp-deleted.nic.it` or `https://epp-deleted.pubtest.nic.it`.
`username`, `password` and `cl_trid_prefix` stay as they are, although
production and the test registry normally use separate accounts. `--dry-run`
shows the change without writing it; `--yes` skips the confirmation.


Run `bin/eppitnic` for the full list of commands, `bin/eppitnic --version` for
the release version, and see
[COOKBOOK.md](COOKBOOK.md) for using the library from PHP.

## Configure the web server

`bin/eppitnic` needs nothing beyond PHP. The REST API ([API.md](API.md))
needs a web server configured so that:

1. **The document root is `public/`, and only `public/`.**
   `config/config.php` holds database credentials, and `bin/`, `src/` and
   `vendor/` have no reason to be reachable over HTTP.
2. **Anything that isn't a real file routes to `public/index.php`.** Slim is a
   front controller: `/v1/domains` exists only as a route inside `index.php`.
   Without this rule every API URL is a 404 before PHP runs.

Copy `config/apache-vhost.sample` or `config/nginx-vhost.sample`, adjust the
host, paths and certificates, and enable it. In front of a Docker install use
`config/apache-proxy.sample` or `config/nginx-proxy.sample` instead (see
[DOCKER.md](DOCKER.md)). Then check the routing:

```bash
curl -i https://<YOUR_HOSTNAME>/v1/network-check
```

The answer must be JSON (the route is public). An HTML 404 means the
front-controller rule is missing — the same cause as *"The API is not
reachable at this address"* on the setup page.

`public/.htaccess` covers hosts that allow per-directory overrides, but
configure the vhost anyway: `AllowOverride None` spares Apache a `stat()` per
request.

For local development, PHP's built-in server routes everything already:

```bash
php -S localhost:8080 -t public public/index.php
```

Setting `EPPITNIC_DEBUG=true` in the web server's environment adds the
exception class, file, line and trace to error responses and gives 500s their
real message. Errors always go to the PHP error log.

> **Warning:** leave `EPPITNIC_DEBUG` unset in production. It exposes
> internals to anyone who can trigger an error, including unauthenticated
> requests.

To have the web server handle login instead of eppitnic (Basic auth, LDAP,
OpenID Connect, …), see [REMOTE-AUTH.md](REMOTE-AUTH.md).

## Resellers and users

Contacts, domains and pending transfers belong to a **reseller**. Reseller 1,
"Registrar (self)", is the registrar itself: it is created with the schema,
holds every admin, and can be renamed but never deactivated. Every user
belongs to one reseller for good and has a role — `admin` (reseller 1 only),
`manager` (also manages the reseller's users, defaults and NS sets) or
`user`. Each reseller has its own daily quota of registrations and
transfer-in requests (`0` = unlimited). See "Authorization model" in
[API.md](API.md).

Setup creates the first admin. Create resellers and further accounts through
the API (`/v1/resellers`, `/v1/users`) or the CLI:

```bash
bin/eppitnic reseller create 'Example Reseller' --max-operations=20
bin/eppitnic reseller list
bin/eppitnic reseller set 2 max_operations 50
bin/eppitnic reseller set 2 active false       # its users lose access at once
bin/eppitnic user create admin2 --password='<PASSWORD>' --email=admin2@example.it --role=admin
bin/eppitnic user create jdoe --password='<PASSWORD>' --email=jdoe@example.it --role=manager --reseller=2
```

Passwords need at least 12 characters with a lower-case letter, an upper-case
letter, a digit and one other character.

Reset a password or enable and disable accounts. The same rules as the API
apply, as `--user` (default 1): a manager reaches only non-admin users of their
reseller, and the last active admin or manager of an active reseller stays.

```bash
bin/eppitnic user password jdoe --generate               # prints it once
echo "$NEW" | bin/eppitnic user password jdoe --password-stdin --must-change-password
bin/eppitnic user password jdoe                          # asks twice, no echo
bin/eppitnic user deactivate jdoe mroe                   # already inactive is a no-op
bin/eppitnic user activate jdoe
```

`user password` takes one of `--password=VALUE`, `--password-stdin` or
`--generate`, or prompts at a terminal. It keeps existing sessions and API
tokens valid; `--must-change-password` forces a change at next login.

Issue a fixed API token for scripted access:

```bash
bin/eppitnic user token admin --days=365
```

`--days=N` sets the validity from now. Omitted or `0`, the token never
expires and the command prints a `WARNING`, since nothing rotates it; prefer a
finite `--days`.

## Check that domains and registrants agree

A domain belongs to its registrant contact's reseller:
`domains.reseller_id` and the registrant's `contacts.reseller_id` must agree.
Every domain route scopes by the domain's reseller, and the API refuses a
registrant from another reseller. 6.x data never enforced this, so an
upgraded database can disagree, and re-saving such a domain doesn't fix it.

```bash
bin/eppitnic doctor ownership
```

It lists mismatched domains and pending transfers that would create one, and
changes nothing. Exit code `0` means coherent, `6` means mismatches were found
(cron-friendly); `--quiet` lists the rows without the explanation of how to
fix them. Move a mismatched domain with
`bin/eppitnic domain set-owner --new-reseller=<RESELLER_ID> <DOMAIN>`, which
copies its contacts into that reseller.

## Remove unused contacts

The registry has no administrative or technical contacts any more, but older
domains still carry them locally. `contact cleanup` clears `admin` and `tech`
on the domains, then deletes at the registry every active contact that no
active domain or pending transfer has as registrant, and deactivates it
locally. That includes registrants nothing uses.

```bash
bin/eppitnic contact cleanup --dry-run   # domains touched, contacts to delete
bin/eppitnic contact cleanup             # asks first; --yes skips the prompt
```

Per contact it reports `deleted`, `already gone at the registry` (deactivated
locally too) or `still linked at the registry, kept` (for example a domain in
redemption). Only other registry errors count as failures and give exit code
`33`.

It is a sysop tool: it always covers every reseller's domains and contacts and
ignores `--user`. A run that changed anything writes one `history` row (object
`domains`, id 0, no user) with the counts.

## Scheduled jobs

One crontab line runs everything:

```text
* * * * *  /path/to/bin/eppitnic cron run >> /var/log/eppitnic/cron.log 2>&1
```

`cron run` decides which jobs are due this minute and runs only those, each
through its own command. `--dry-run` prints which jobs would run without
running them. In Docker, the `scheduler` container runs this line for you.

A job is due when it is `enabled` and `frequency_minutes` have passed since
its `last_run_at`. Every field can be set with the CLI verbs below or, by an
admin, through `GET`/`PATCH /v1/cronjobs` (see [API.md](API.md)). Omitting
the value on the command line, or sending `null`, resets a field to its
default (an empty list for `pdns` APIs and nameservers). Every job
is also an ordinary command you can run by hand at any time, due or not.

### `poll process` — on by default

Drains the registry's message queue into `messages`, reconciles domain
transfer state, then rotates the registry password if a reminder asked for
it. `--no-rotate` and `--no-transfers` skip a step on a manual run; it never
prompts.

```bash
bin/eppitnic config poll-process-set enabled false
bin/eppitnic config poll-process-set frequency_minutes <N>  # default 5
```

> **Warning:** with `poll process` off, the shared registry password no
> longer rotates on a `passwdReminder`, and nothing else watches for that
> reminder. Once the password expires, every EPP call fails.

### `domain sync` — on by default

Reconciles domains already known locally against the registry, and refreshes
their linked contacts (see "Domain reconciliation").

```bash
bin/eppitnic config domain-sync on
bin/eppitnic config domain-sync off
bin/eppitnic config domain-sync-set batch_size <N>         # default 25
bin/eppitnic config domain-sync-set frequency_minutes <N>  # default 5
```

### `domain reap-deletions` — on by default

Deletes at the registry every domain whose deletion, scheduled with
`DELETE /v1/domains/{name}?mode=expiry|date`, has come due. The deletion was
confirmed when it was scheduled, so there is nothing to opt into.
`bin/eppitnic domain reap-deletions --dry-run` shows what a run would delete.

```bash
bin/eppitnic config domain-reap-set enabled false
bin/eppitnic config domain-reap-set frequency_minutes <N>  # default 15
```

### `pdns sync` — off by default

Keeps a PowerDNS zone for every domain delegated to your own DNS servers,
through the PowerDNS HTTP API. Enable it only if PowerDNS serves your zones;
setup, the settings and troubleshooting are in [POWERDNS.md](POWERDNS.md).

```bash
bin/eppitnic config pdns-set enabled true
bin/eppitnic config pdns-set frequency_minutes <N>     # default 15
```

### `session keepalive` — follows the `keepalive` setting

Runs on every tick and has no `frequency_minutes`. It prints nothing and
exits `0` while `keepalive` is off (see "Session keep-alive").

### Queued tasks and retries

`pdns sync` and `domain reap-deletions` consume rows from the `tasks` table
(`object` `pdns` and `registry`). Only a successful run retires a row; a
failure records `exit_code` and `exit_message` and leaves the row active, so
the next run retries it. A scheduled deletion the registry refuses is retried
twice; the third refusal deactivates the task, its `exit_message` saying it
gave up (`tasks.attempts` counts the refusals).

## Email notifications

`poll process` and `domain reap-deletions` can email what they find, through
the `smtp` setting (`Service\Notifier`, shared by `config smtp-set` and the
admin-only `GET`/`PATCH /v1/smtp`). Off by default:

```bash
bin/eppitnic config smtp-set enabled true
bin/eppitnic config smtp-set host smtp.example.it              # default: localhost
bin/eppitnic config smtp-set port 587                          # optional, 1-65535
bin/eppitnic config smtp-set sender eppitnic@example.it
bin/eppitnic config smtp-set recipient_mode system             # system, user, both (default), or none
bin/eppitnic config smtp-set recipient admin@example.it
bin/eppitnic config smtp-set username eppitnic@example.it      # optional, for SMTP AUTH
bin/eppitnic config smtp-set password '<SMTP_PASSWORD>'        # optional, for SMTP AUTH
bin/eppitnic config smtp-set auth_type starttls                # plain (default), tls, or starttls
bin/eppitnic config smtp-set message_types passwdReminder,scheduled_deletion  # comma-separated; omit for every type
bin/eppitnic config smtp-set fulltext expired                  # a plain substring filter over type, domain and message
```

`recipient_mode` decides who receives mail:

| Mode | Recipients |
|---|---|
| `system` | the fixed `recipient` mailbox |
| `user` | the active users of the domain's reseller who have an address and notifications on |
| `both` | both of the above |
| `none` | nobody — notifications off, the rest of the configuration kept |

Each recipient class is judged only by its own filter. A blank `recipient`
under `system` or `both` means nothing is sent to it yet; saving an
incomplete configuration is never blocked. A message without a domain (an
account-level registry message such as `passwdReminder`) only ever reaches
the system recipient.

`domain reap-deletions` sends one summary per run listing every outcome,
success and failure alike: the system recipient's copy covers the whole run,
and each reseller's users (under `user` or `both`) get a copy covering only
that reseller's domains.

When a transfer-in completes, `poll process` applies the requested
nameservers at once. If the registry refuses, it stores the
domain as the registry has it and tries again on a later run, at least 20
minutes after the previous attempt, up to 3 more times. After the fourth
refusal it sends one `transfer_update_failed` email, with the registry's
error, the requested and the current nameservers, to the system recipient and
the owning reseller's users, and stops: the update is then done by hand.

Every user can switch their own notifications on or off and set their own
filter (`GET`/`PATCH /v1/users/{id}/notifications`; their manager or an admin
may act for them). This only has an effect while `recipient_mode` includes
`user`. Admins and managers start with notifications on, plain users off.

`message_types` is any of `Service\Notifier::MESSAGE_TYPES`: every registry
poll message type (`passwdReminder`, `chgStatusMsgData`,
`clientApprovedTransfer`, …) plus `scheduled_deletion`, which is
`domain reap-deletions`' own summary, and `transfer_update_failed`, which is
`poll process`' report of a transfer-in whose nameserver update failed. An empty list lets every type through;
`fulltext` (empty by default) matches case-insensitively against the
message's type, domain and text together.

## Session keep-alive

By default every EPP operation connects, sends hello and login, runs, and
logs out. Turning `keepalive` on holds one authenticated session open across
processes instead, reused while fresh and refreshed from cron before nic.it's
idle timeout:

```bash
bin/eppitnic config keepalive on
bin/eppitnic config keepalive off
```

With it on, `session keepalive` sends `hello` once the session is more than
230 seconds old — nic.it documents `hello` for exactly this — well inside the
300-second limit, so one missed run is survivable. If the registry drops the
session anyway (a restart, maintenance), the next operation logs in again
once and replays that one command; nothing bulk is ever replayed.

Turning it off logs the open session out first (if the registry is
reachable), so nothing is left idling.

`domain restore`, which talks to the separate `-deleted` endpoint, and the
registry password rotation never use the shared session: a different host,
or a login `poll process` expects may fail, must not touch what other
requests share.

## Session serialization

Whether nic.it tolerates overlapping commands on one shared session is
unconfirmed, so locking them against each other is opt-in, off by default,
and only meaningful with `keepalive` on:

```bash
bin/eppitnic config session-serialize on
bin/eppitnic config session-serialize off
```

With both on, every command takes a MariaDB advisory lock (`GET_LOCK`,
scoped to this installation's database) before it runs. If the lock isn't
obtained within 10 seconds, or the database has no `GET_LOCK`, the command
proceeds unlocked rather than blocking.

## Domain reconciliation

`domain sync` re-checks domains already in the local database against the
registry (`domain check`, then `domain info` for anything still registered)
and writes drifted nameservers, contacts, authinfo, DNSSEC, status and expiry
back onto the local row. Each run processes one batch, advancing a persisted
cursor (the `domain_sync` setting's `cursor_id`) through `domains.id` and
wrapping around at the end, so every active domain is revisited without an
unbounded number of registry calls in one run.

On a manual run, `--batch-size=<N>` overrides `batch_size` for that run only,
and `--report-only` queries the registry as normal but writes nothing and
leaves the cursor where it is.

Every contact linked to a processed domain is refreshed locally with
`contact info`. The registry returns a registrant only, so `domains.admin`
and `domains.tech` are emptied when it reports none.

A domain the registry no longer holds is reported but never deactivated
automatically: this job reconciles data, it does not prune it. The exit code
is `6` when anything was reconciled or found gone (the code
`doctor inactive-domains` also uses for "ran fine, found drift"), `21` when a
registry query failed, and `0` otherwise.

## Skip MFA from safe networks

Logins from a `safe_networks` range need only the password, not the MFA
code. The default, `["127.0.0.1/32"]`, exempts logins from the machine
itself. The token of such a login is not MFA-verified: routes behind the MFA
gate (admin, manager) accept it only on requests from a safe network.

```bash
bin/eppitnic config safe-networks
bin/eppitnic config safe-networks add 10.0.0.0/8
bin/eppitnic config safe-networks remove 10.0.0.0/8
bin/eppitnic config safe-networks clear
```

Both address families work, and a bare address is a full-length prefix
(`203.0.113.7` means `203.0.113.7/32`). Host bits are cleared before an entry
is stored, so `10.1.2.3/8` becomes `10.0.0.0/8` — what it actually matches.
An empty list means every account with MFA is always asked for its code.

Behind a reverse proxy this depends on `trusted_proxies` (below): the address
compared is the resolved client address, so a range here that covers the
proxy would exempt every request passing through it.

## Login rate limiting

Every login is recorded in `history` as a `security` row with the address,
network, username and headers — never the password or token. `action` is
`login`, `denied`, or `secread` for a request the limit turned away. Enough
failures from one network and `POST /v1/users/authenticate` answers `429`
until they age out.

Two settings control it:

- `login_ratelimit` — `{"max_failures":10,"timespan":900,"ipv4_prefix":24,"ipv6_prefix":48}`.
  Failures are counted per network, not per address, since an IPv6 customer
  gets a whole allocation. `max_failures: 0` disables the limit.
- `trusted_proxies` — **set this if the API runs behind a reverse proxy.**
  `X-Forwarded-For` is only believed when the connecting address is listed
  here. `GET /v1/trusted-proxies` shows, as `peer`, the address your own
  request arrived from — the one to add. Edit it with
  `PUT /v1/trusted-proxies` or:

  ```bash
  bin/eppitnic config trusted-proxies                       # show
  bin/eppitnic config trusted-proxies add 172.18.0.1        # a bare address is a /32
  bin/eppitnic config trusted-proxies remove 172.18.0.1/32
  bin/eppitnic config trusted-proxies clear
  ```

  Catch-all ranges (`0.0.0.0/0`, `::/0`) are refused, and changes are
  recorded in `history` (`object='trusted_proxies'`).

Getting `trusted_proxies` wrong fails in opposite ways: left empty behind a
proxy, every request seems to come from the proxy and all clients share one
bucket; set without a proxy, it is never matched and does nothing.

Review unacknowledged entries with `GET /v1/history?object=security&acknowledged=0`
and acknowledge them with `POST /v1/history/acknowledge`, or query the table
directly:

```sql
SELECT timestamp, network, action, JSON_VALUE(data,'$.event'), JSON_VALUE(data,'$.username')
FROM history
WHERE object = 'security' AND acknowledged_time IS NULL
ORDER BY id DESC LIMIT 20;
```

## Registry password rotation

nic.it warns through the EPP poll queue that the account password is nearing
expiry. `eppitnic poll process` acts on these `passwdReminder` messages: it
generates a new password, sets it at the registry (a login with the new
password), stores it in the `epp` setting and acknowledges the message.
Without the scheduled job, reminders go unread until the credential expires
and every EPP call fails.

Passwords come from `Eppitnic\Support\PasswordGenerator`: 16 characters
(EPP's maximum) drawn with `random_int()`, always including all four
character classes, and excluding easily misread characters (`l`/`I`,
`O`/`0`) and `$`, `%` and `!`.

At most one rotation runs per 24 hours, tracked by `epp.lastPasswordUpdate`,
which is stamped before the attempt; the registry sends its reminder well
before the actual expiry.

The new password is written to the `epp` setting as `pendingPassword` before
it is sent, and promoted once accepted. An interrupted run leaves both
stored; the next run settles it by asking the registry which one it accepts.
To settle it immediately:

```bash
bin/eppitnic doctor epp-password
```

`GET /v1/session/epp` reports `rotation_pending` meanwhile. If the registry
accepts neither password, the candidate is kept and the failure logged —
that's an account problem (expired, locked, IP not authorized) to resolve
with the registry.

Every rotation that lands — automatic, manual (`config epp-password` or the
API), a settled interrupted one, or an adopted password (`--force`) — is a
`security` history entry with action `rotate`, which stays outstanding until
an admin acknowledges it. It is also mailed to the SMTP system recipient, if
mail is enabled and a recipient is set, whatever the recipient mode and
filters say. Neither contains the password.

## Log the registry traffic for debugging

The registry debug log records every exchange with the registry: curl's
connection trace, each request and each response. It is off by default.

```bash
bin/eppitnic config debugfile                    # show: off, or the file and its size
bin/eppitnic config debugfile epp-debug.log      # log to <var directory>/epp-debug.log
bin/eppitnic config debugfile delete             # delete the file, then turn logging off
```

> **Warning:** the log holds every command sent and every contact's personal
> data in clear. Registry passwords (`<pw>`, `<newPW>`), domain and contact
> auth codes, `Authorization` headers and session cookie values are masked,
> but treat the file as confidential: turn it on only while debugging.

The file must be a `.log` name inside the var directory (`EPPITNIC_VAR_DIR`,
else `var/` in the checkout): the log holds text users typed, which must
never land where it could run as PHP. It is created with mode `600` before
the setting is stored, so a file that cannot be written leaves the setting
as it was. While one log is recording, another cannot be started.

Logging stops only by deleting the log: `delete` removes the file first and
clears the setting only if that worked, so a file that cannot be deleted
keeps logging on. A file that stops being writable does not stop registry
traffic: each connection writes a warning to the PHP error log instead.

Admins can make the same changes through `GET`/`PUT /v1/debugfile` or the
frontend's Settings, which also opens the log in a new browser tab. Every
change is recorded in `history` (`object='debugfile'`), and every read of the
log as a `security` row.
