# Changelog

## Version 7.1.0
7.1 makes the backend compatible with the .it Registry Technical Guidelines
v4.0: no administrative or technical contacts, no fax number, and `name`
equal to `org` on every contact. It also adds password reset by e-mail,
per-user session lengths and CLI verbs for user and contact housekeeping. The
schema upgrades itself (`070000` to `070100`); see [UPGRADING.md](UPGRADING.md).

### Registry Technical Guidelines v4.0

- Domain create and update never send `admin` or `tech` contacts. The API
  (`admin`, `tech`), the CLI (`--admin`, `--tech`, `--add-tech`, `--rem-tech`),
  the reseller `techc` default and `fax` are still accepted and ignored. An
  update with nothing else to change is a no-op success (`domain update`
  says "nothing to do").
- A transfer-in does not reconcile technical contacts, and moving a domain to
  another reseller duplicates only the registrant.
- `domain info --contacts` and `Domain::fetch()` accept `all` or `registrant`.
- `selftest` creates only company registrants and its domain update changes
  only a nameserver.

### Contacts and domains

- A contact needs an entity type of 1 to 7 and its registrant details; entity
  type 0 is refused before the registry is asked. `contact create` requires
  `--entitytype` and `--nationalitycode`.
- `name` and `org` must be the same: give one and the other is copied, give
  both different and the create is refused with "name and org must be the
  same" (`400` on the API, a usage error on the CLI). An update never sends
  either and refuses a change with "name and org cannot be changed once the
  contact exists".
- Reseller defaults gain `entitytype`, the contact type new contacts start
  from, and `dnssec_algorithm` and `dnssec_digesttype`, the values a new DS
  record starts from (`GET/PUT /v1/resellers/{id}/settings`).
- `GET /v1/domains` rows carry `ex_date` (`YYYY-MM-DD`, `null` for a
  transfer-in row).
- `contacts.email` holds 256 characters and `contacts.regcode` 36. Registry
  data longer than a column is refused with a message naming the object,
  field and limit instead of failing silently or being truncated.
  `Validate::maxLength` counts characters, not bytes.
- `contact create --store`, `contact update --store`, `domain set-registrant`
  and `domain import` report a failed local write.
- Contacts carry a boolean `linked` (`GET /v1/contacts`, `GET
  /v1/contacts/{handle}`): true when the stored status says so, or when an
  active local domain (inactive and `dnsHold` included) or a pending
  transfer-in names the contact as registrant. A registrant used by a new
  domain no longer shows as unlinked until the next sync.
- Creating a domain, changing a registrant (API, `domain set-registrant`,
  moving to another reseller), deleting a domain and completing a transfer-in
  re-read the registrants involved (old and new) from the registry and store
  their status. Best effort: a failure is logged and never fails the
  operation. `domain reap-deletions` does not refresh.
- `DELETE /v1/contacts/{handle}` (`409`) and `contact delete` refuse a
  registrant of an active local domain or pending transfer-in before asking
  the registry, naming the domains.

### Users and sessions

- `users.email` is required, unique (case-insensitively) and 256 characters
  wide: `POST` and `PUT /v1/users` refuse a missing, malformed or used address
  with `400`. The first admin `eppitnic setup` creates still takes an
  optional address.
- Forgot password: `POST /v1/users/password-reset` mails a single-use link,
  valid 10 minutes, to the account's address and always answers `202`;
  `GET /v1/users/password-reset/{token}` and `POST
  /v1/users/password-reset/confirm` check and use it. The link goes to the
  request's allowed `Origin`, `Referer` or body `origin`, never the `Host`, so
  that origin must be in `allowed_origins`. Requests are rate-limited per
  network and per address, confirmations per network.
- `GET /v1/network-check` gains `password_reset`, true while SMTP is
  configured and remote authentication is off.
- Token lifetime: `users.token_lifetime` is the user's own choice from 5, 10,
  15, 30, 60, 90, 120 or 240 minutes, default 15, set with
  `PATCH /v1/users/{id}/session`. `max_token_age` is the limit an admin or
  manager sets from the same list (default 240); a token lives for the smaller
  of the two, and `renew-token` re-reads both from the account.
- `POST /v1/session/adopt-password` stores a registry password that is already
  valid there, after a login with it, like `config epp-password --force`. The
  Settings page offers it next to the rotation.
- Adopting a password tells a rejected credential (EPP `2200` / reason
  `6005`, API `400`) from a login the registry refused for another reason (IP
  not allowed, session limit; API `502`), and drops the shared registry
  session afterwards so the next call logs in with the new password.

### CLI

- `user create` requires `--email`.
- `user password` (`--password`, `--password-stdin`, `--generate`, or a hidden
  prompt; `--must-change-password`), `user activate` and `user deactivate`
  apply the API's password policy and its last-admin, last-manager and reach
  rules, shared in `UserRules`, and record history without the password.
- `user mfa-reset` removes a user's MFA like `DELETE /v1/users/{id}/totp`
  (same reach rules, shared in `UserRules`); `--must-enroll-mfa` also requires
  enrolling again at the next login outside the safe networks.
- `config epp-password` takes the password as `<new-password>`,
  `--password-stdin` or, at a terminal, a hidden prompt asked twice.
- `contact cleanup` clears `admin` and `tech` on domains, then deletes the
  contacts no active domain or pending transfer uses as registrant, at the
  registry and locally, for all resellers and regardless of `--user`. It
  writes one `history` row with the counts; `--dry-run` lists them first.

### Database

Schema `070100`, applied automatically:

- `contacts.email` widens to 256, `contacts.regcode` to 36 and `users.email`
  to 256 with a unique key.
- New columns `users.token_lifetime`, `resellers.entitytype`,
  `resellers.dnssec_algorithm` and `resellers.dnssec_digesttype`.
- New tables `password_resets` and `password_reset_attempts`.
- The users.email upgrade turns empty addresses into NULL and, where several
  users share one, keeps it on the lowest id and clears it on the others,
  recording each in `history` ("duplicate address removed by the 7.1
  upgrade"). Those users need an address before they can be saved again.

## Version 7.0.0
7.0 turns the library into a self-contained registrar backend: a JSON/REST
API with its own authentication, a single `bin/eppitnic` CLI, scheduled jobs,
resellers, and a Docker image. Upgrading from 6.7 needs a few manual steps —
see [UPGRADING.md](UPGRADING.md).

### Platform, layout and configuration

- PHP 8.1 or later with the intl extension; the Docker image runs 8.5.
  Every dependency comes through Composer; none are vendored in the
  repository any more.
- The library lives in `src/` under the `Eppitnic\` namespace
  (`Eppitnic\Epp\Domain`, `Eppitnic\Epp\Contact`, …).
  `Net_EPP_StorageDB`/`Net_EPP_StorageInterface` are gone: persistence talks
  to RedBeanPHP's `R::` facade directly.
- Tables lose their `tbl_` prefix and move to `utf8mb4`; the upgrade also
  decodes the HTML entities 6.x stored in text columns.
- `config.xml` is replaced by `config/config.php` (database credentials only)
  plus the `settings` table, read through `Eppitnic\Config`.
  `eppitnic config migrate` converts an existing `config.xml`. The `debug`,
  `debugfile`, `passwordexpirydays`, `passwordexpirynext` and `cookie_dir`
  settings and the `users.dns` column are not carried over.
- Settings are inspected and changed with `config` verbs instead of SQL:
  `config show` (credentials redacted), `config epp-server
  production|test|toggle`, `config epp-set`, `config epp-password`,
  `config safe-networks`, `config trusted-proxies`, `config allowed-origins`,
  `config debugfile`, `config keepalive`, `config session-serialize`,
  `config region-set`, `config dnssec`, `config pdns-api`,
  `config pdns-nameserver` and the job-specific `config *-set` verbs.
  `Support\Validate::eppField()` holds the EPP field rules shared by setup,
  the CLI and the API, so an invalid username or password is refused when
  entered rather than at the next `<login>`.
- The `dnssec` setting is `{"active": 0|1}`, switched with `config dnssec
  on|off` or `PATCH /v1/dnssec`. While off, DS records on a domain create or
  update are refused instead of silently dropped. `algorithm` and
  `digesttype` are no longer settings: each DS record carries its own.
- The registry debug log (`config debugfile`) masks passwords, auth codes and
  cookie values, is created with mode `600`, and only in the var directory.
  An unwritable log file warns instead of stopping every registry call.
- The `epp` setting gains `registrar_tag`, the registrar's `-REG` ID that
  poll messages name it by, so the login can be an EPP user of any name up
  to 64 characters. Setup asks for it first, and `config migrate` takes it
  from 6.x's username.
- Invoicing has been removed: the upgrade drops the `accounting` table and
  `users.billing_id`. It will be reimplemented separately.
- WSDL support and the PHP/Smarty/jQuery web interface have been dropped.

### Command line: `bin/eppitnic`

Everything runnable lives behind one entry point, `bin/eppitnic`; the
`CLI/`, `examples/` and `cronjobs/` folders are absorbed into its verbs.

First-run setup no longer needs a hand-edited config: `eppitnic setup`
(interactive, or scriptable with `--db-name=` and similar flags) and a
browser installer (`public/setup.html`, served automatically while
`config/config.php` doesn't exist) both drive `Setup\Installer`. It probes
the database credentials with a throwaway connection, applies the schema,
creates the first admin, and writes `config/config.php` last, so a failed
setup is simply re-run.

`eppitnic selftest run` exercises the library against the registry's public
test endpoint: it registers, reads back, changes and deletes real contacts
and a real domain, checking each answer against what was sent, and reports
one line per operation. It refuses to run against any other endpoint.
`--domain=NAME` registers a name whose zone you prepared, so the nameservers
given with `--ns` pass the registry's checks; the run then pauses ten seconds
after each delegation change. nic.it keeps a contact linked to a deleted
domain until it is purged 30 days later, so those contact deletes are
reported as deferred and cleared later by `eppitnic selftest reap`, from the
note the run leaves in `var/selftest/`.

### REST API and authentication

A JSON/REST API (Slim 4, `public/`, documented in [API.md](API.md)) replaces
the web interface. Authentication uses bearer-token JWTs
(`firebase/php-jwt`) with optional TOTP-based MFA, long-lived fixed API
tokens for scripted access, or — via the `remote_auth` setting — a front web
server's own login (`REMOTE_USER`, or a header from a trusted proxy; see
[REMOTE-AUTH.md](REMOTE-AUTH.md)).

- Passwords are hashed with `password_hash()` instead of MD5, so existing
  6.x passwords must be reset. Any password set from now on needs at least
  12 characters with a lower-case letter, an upper-case letter, a digit and
  one other character; the rule is enforced by `Support\PasswordPolicy`
  wherever a password is set, never at login.
- A manager or admin can require a user to change the password or set up MFA
  before the account works (`must_change_password`, `must_enroll_mfa`;
  `user create --must-change-password --must-enroll-mfa`). Login answers `403`
  with no token until it is done, through `POST /v1/users/authenticate/password`
  and `/mfa`. Enrollment is only demanded outside `safe_networks`.
- Logins are rate-limited per network — IPv4 `/24` and IPv6 `/48` by
  default — through the `login_ratelimit` setting: past `max_failures` within
  `timespan` seconds, `POST /v1/users/authenticate` answers `429` with a
  `Retry-After` header.
- `X-Forwarded-For` is believed only from a peer listed in the new
  `trusted_proxies` setting, which is empty by default. **Set it if the API
  runs behind a reverse proxy**, or every client shares one rate-limit bucket
  and none matches `safe_networks`. Address matching works for IPv4 and
  IPv6 alike.
- There is no WHOIS lookup route and no `kevinoo/phpwhois` dependency.

### Resellers and roles

Contacts, domains and pending transfers belong to a **reseller** instead of a
user, and everyone in a reseller works on all of its objects. Users belong to
one reseller for good and have a role: `admin` (only in reseller 1,
"Registrar (self)", which can never be deactivated), `manager` (also manages
the reseller's users, defaults and NS sets) or `user`. Role and reseller are
checked against the database on every request, so deactivating a user or a
reseller takes effect at once.

- A domain always belongs to its registrant's reseller, so the registrant of
  a new domain or a registrant change must be one of the caller's reseller's
  contacts.
- The daily quota, the default country code, the default tech contacts
  (`techc`, now a list) and named NS sets (`nssets`, default in `dnsset`)
  are per reseller. The quota counts transfer-in requests as well as
  registrations, at request time.
- Everyone sees the poll messages and tasks about their reseller's domains;
  managers can archive messages, and anyone in the reseller may deactivate a
  notice or a scheduled deletion.
- New: `/v1/resellers`, `/v1/resellers/{id}/settings` and `/nssets`,
  `reseller list|create|set`, `user create --role --reseller`, and
  `domain set-owner --new-reseller`. Usernames are unique.

The upgrade maps each existing user onto a reseller so nobody sees more than
before.

### Audit trail

A new `history` table records who changed what: contacts, domains, users,
resellers, and the EPP, SMTP, job, remote-auth and trusted-proxy settings.
Its `security` entries record logins (`login`), failed logins (`denied`),
rate-limit blocks and credential disclosures (`secread`) and registry
password rotations (`rotate`), each with the client address and request
headers. Passwords and tokens are never written, and `Authorization`,
`Cookie` and `Proxy-Authorization` are stored as `[redacted]`.

`GET /v1/history` reads the trail, filtered and scoped to what the caller may
see: an admin everything, everyone else their reseller's objects. Admins
acknowledge `security` entries one by one or in bulk, recording who and when.

### Registry password rotation

The registry's `passwdReminder` poll messages are acted on: `eppitnic poll
process` rotates the shared EPP password when one is outstanding, at most
once per 24 hours (tracked in the `epp` setting's `lastPasswordUpdate`). The
new password is recorded locally before it is sent, so an interrupted
rotation is settled afterwards by `eppitnic doctor epp-password`, which asks
the registry which one it holds. Every rotation, automatic or manual, is a
`security`/`rotate` history entry and is mailed to the SMTP system recipient.
Admins can read the current credential through
`GET /v1/session/epp/credentials`, and every retrieval is logged.

`Support\PasswordGenerator` draws the password from a mixed character set,
since hex spent EPP's 16-character ceiling on 64 bits. Domain authinfo codes
are drawn the same way, and every other random credential (API tokens, the
JWT signing key, contact handles, transaction ids) comes from the same class.

### Scheduled jobs

`eppitnic cron run` is the only verb a crontab needs: it runs whichever jobs
are due (`enabled`, and `frequency_minutes` elapsed since `last_run_at`) and
`session keepalive` on every tick. Each job's settings are validated and
audited by `Service\CronjobSettings`, shared by the `config *-set` verbs and
`GET`/`PATCH /v1/cronjobs`. See "Scheduled jobs" in [INSTALL.md](INSTALL.md).

| Job | Default | What it does |
|---|---|---|
| `poll process` | on | drains the poll queue, applies completed transfers, rotates the registry password |
| `domain sync` | on | reconciles local domains and their contacts against the registry |
| `domain reap-deletions` | on | deletes domains whose scheduled deletion is due |
| `pdns sync` | off | applies DNS changes for domains on your nameservers through the PowerDNS HTTP API |
| `session keepalive` | off | keeps the shared EPP session alive |

`reminder` is now `tasks`, and a row a job consumes says so in its `object`
and `action` columns (`pdns` for a DNS change; `registry`/`delete` for a
deletion scheduled with `DELETE /v1/domains/{name}?mode=expiry|date`). A job
records `executed_time`, `exit_code` and `exit_message`; only a success
retires the row, so a failure is retried on the next run.

`domain sync` works through the active domains in batches (`batch_size`,
default 25, overridable per run with `--batch-size`) from a persisted cursor,
wrapping around once exhausted. It finds domains the registry no longer
holds, reconciles drifted nameservers, contacts, authinfo, DNSSEC, status and
expiry, and refreshes every contact linked to a domain it touched.

With the `keepalive` setting on, API requests share one authenticated EPP
session instead of logging in and out for every operation, and any command
that finds the session gone logs in again and retries. `session_serialize`
optionally locks commands on that shared session against each other. The
cURL cookie jar lives in process memory instead of a file, so concurrent
requests no longer step on each other's cookies.

`poll process` and `domain reap-deletions` can email what they find through
the new `smtp` setting (`phpmailer/phpmailer`, `config smtp-set`,
`/v1/smtp`). `recipient_mode` (`system`, `user`, `both` or `none`) decides
who receives them, each recipient filtered by its own `message_types` and
`fulltext`; account-level messages such as `passwdReminder` only ever reach
the system recipient.

### Docker

`docker compose up -d` brings up a whole instance: nginx and php-fpm in one
container published on `127.0.0.1:8080`, a scheduler sidecar running
`cron run`, and an `eppitnic-cli` service for one-shot verbs. `config.php` and
the self-test notes live outside the image, wherever `EPPITNIC_CONFIG_DIR`
and `EPPITNIC_VAR_DIR` point. `config/nginx-vhost.sample` and
`config/apache-vhost.sample` serve a bare-metal install;
`config/nginx-proxy.sample` and `config/apache-proxy.sample` terminate TLS in
front of the container. See [DOCKER.md](DOCKER.md).

### Library fixes

- `Domain->get('tech')` always returns an array (keyed handle => handle). It
  returned a bare string for a single technical contact, which broke callers
  that handled the result uniformly; callers that special-cased the string
  can drop that branch.
- A `Contact` built without an explicit authinfo now has one. The generated
  code was blanked by `initValues()`, so such contacts were created with an
  empty `<contact:pw>`. The registry accepts that: `eppcom:pwAuthInfoType`
  has no minimum length, and the min-6/max-16 rule is `epp:pwType`, which
  only governs the `<login>` password.
- `Epp\Contact::setEntityType()` rejects values outside 1–7; its range check
  could never fail.
- `Epp\Transport\Curl` throws instead of calling `exit()` when its debug file
  is not writable, so the API answers `502` and the CLI `LOGIN_FAILED` rather
  than printing plain text over the response.

## Version 6.7
Fixed a minor bug which kept the `Domain->storeDB(...)` method from removing an
existing domain name prior to saving the updated record.

Initialize the current date for `crDate` in `initValues()` and current date + 1 year
for `exDate`.

## Version 6.6
Commented out `$slast` in `idna_convert.class.php` (was never used anyway and PHP
complained about creating it as a dynamic property).

## Version 6.5
Replaced obsolete/unmaintained phpwhois-4.2.2 with phpwhois-kevinoo-6.3 while
adding `libs/phpwhois/whois.main.php` as a wrapper in order to keep the previous
interface.

## Version 6.4
Some adjustments to make the library and its components compatible with newer
PHP versions (8.x).

## Version 6.3
If a message was not successfully stored by `poll()` in `Net/EPP/IT/Session.php`,
return `FALSE`.

## Version 6.2
This update includes support for the EDU SLD – please mind the schema update
to `tbl_contacts`!

## Version 6.1
Some updates to the database schema.

## Version 6.0
All code and examples updated to be usable with PHP 7. Some code cleanup was
done and examples divided into more generic CLI tools and "examples".

Support added for use with IT-NIC's upcoming DNSSEC implementation.

> **IMPORTANT:** as part of the code cleanup, Smarty 2 and ADOdb have been removed.
> This now implies usage of a PHP release greater than or equal to 5.3!

## Version 6.0-beta1
All code and examples updated to be usable with PHP 7. Some code cleanup was
done and examples divided into more generic CLI tools and "examples".

Configuration prepared for use with IT-NIC's upcoming DNSSEC implementation.
Code and DB support for DNSSEC is still missing but should be there for the
final 6.0 release.

> **IMPORTANT:** as part of the code cleanup, Smarty 2 and ADOdb have been removed.
> This now implies usage of a PHP release greater than or equal to 5.3!

## Version 5.3
Contact objects now allow for `entitytype = 0` (for example a TECHC without
any details except address information).

`infContacts` support added to domain object and WSDL interface. This includes
examples.

Minor issue related to default userid assignment to domain object fixed, which
mainly only relates to the user (agent) management provided by the graphical
interface.

## Version 5.2
Examples folder cleaned up. Modified the add/rem methods used by the Domain
object in order to allow modification when there are already 6 NS or tech
contacts assigned to a domain.

## Version 5.1
Bugfix which relates to `extValueReasonCode` in `Net/EPP/AbstractObject.php` (this
value has been placed underneath the extepp namespace by IT-NIC's 2.0 release).

## Version 5.0
This version now supports IT-NIC's 2.0 release.

It also fixes the handling of the `<debugfile></debugfile>` configuration
parameter and the entity-encoding of the authinfo parameter used in domain
transfer requests.

## Version 4.6
Reset method for HTTP connections implemented in the Client class.

phpwhois-4.2.2 added to lib folder (to be used with webinterface).

If a DNS record is added which already exists, but has a new IP address
associated with it, then automatically remove the old configuration and add
a new one.

## Version 4.5
Updated the DomainUpdate WSDL function so the amount of contacts and ns records
to add/remove is not static (to be divided by semicolons).

## Version 4.4
Added missing userID field to `tbl_transfer`. Renamed apache configuration file
for WSDL to `docs/apache-vhost-wsdl`.

Fixed a typo in `Net/EPP/IT/WSDL/contact.info.php` (space character after name
column field removed).

## Version 4.3
Now checking PHP version in order not to break PHP versions older than 5.2.3
when using `htmlspecialchars` (4th parameter was only added in v5.2.3).

Added support for delayedDebitAndRefundMsgData polling type data. Thanks
Angelo for the input!

## Version 4.2
Moved the AbstractObject object up into Net_EPP space.

The session file location can now be specified manually.

Added an `account.poll.ack.php` example for WSDL and changed the Poll method
to use ACK as default parameter. PollAll now works only with ACK.

Switched to IDNAbis-like handling of German SZ in idna_convert, as IT-NIC will
now support this.

## Version 4.1
Updated the WSDL interface to version 1.2 (added a DomainChangeRegistrant
method and removed the registrant parameter from the DomainUpdate method).

## Version 4.0
Some smaller fixes and updates (DB layout for webinterface, which now supports
automatization of TECH-C + DNS updates).

Added an example that shows how to extend the DB driver for searching
serialized DB fields.

Support for multiple Smarty versions added. The library now comes with a
version 2 and a version 3 release. Depending on your `PHP_VERSION_ID` the correct
one to be used is selected.

The set method for contacts now makes use of `htmlspecialchars()` in order to
convert ampersands and other stuff.

Big switch from the PEAR class HTTP_Client to CURL. This is the main reason
for the change in major number. The current version now allows you to choose
the leaving IP/interface, which should be useful for multi-homed servers and
hosting solutions.

## Version 3.6
The `isTrue` method inside of the Contact object did a value and type check
on its argument and therefore compared only to numeric 1 instead of also
comparing to the string "1". Fixed.

Parent constructors (domain / contact objects) are now called as first thing
in the object's own constructor.

Some troubles found when using Smarty 3 with PHP 5.2 (i.e. Ubuntu 8.04 LTS).
Still undecided whether to downgrade back to Smarty 2 or not.

When updating the registrant and using the updateDB domain method, the
owning userID (agent ID) is now changed. The variable name userID has now been
changed to lowercase `userid` everywhere. This could have some impact in
different places - please pay attention to it!

You can now remove the fax value from a contact by leaving it blank.

## Version 3.5
Changed all calls to Smarty's `clear_all_assign` to `clearAllAssign`.
Calls to `set()` on objects are now doing automatic lower-case conversion.
Thanks Marcello!

The error handling function in AbstractObject now type-casts message
variables to strings (there have been some issues there).

## Version 3.4
Updated the status fields for `tbl_contacts` and `tbl_domains`, which were still
and wrongly set to tinyint. They need to be text in order to support
serialized status information (text array). Thanks Marcello!

Added displayAccounting switch for the webinterface. When set, the
webinterface will display accounting information in the same way the polling
queue is displayed.

Updated Smarty to version 3.0.6. The client-constructor now uses the
`$_file_perms` and `$_dir_perms` variables when falling back to `/tmp` for compile_dir
is active. The fallback now emits an `E_USER_NOTICE` instead of `E_USER_WARNING`.

## Version 3.3
Updated the update-contact template in order to support authinfo-updates on
contacts. They are not used but defined by the protocol, so what... :-)

Added a config.xml parameter in order to switch on/off sending email
notifications for all polling queue messages (this only affects the
webinterface implementation though).

## Version 3.2
Support for clientLock added to the domain object (can only be viewed though).

Added the idna_convert class by phlyLabs. The eppitnic-php class by itself
should support UTF-8 IDNs as long as you feed UTF-8 data to it, but the
webinterface will have to feed punycode strings to the DNS notification
script (DNS's need to run domains in punycode format).

Fixed the sendRequest method in the client class which (wrongly) always used
`utf8_encode()` on the data to be sent. This issue led to double-encoding in
cases where your data was already in UTF-8 format. For those of you feeding
data in ISO-8859-1 format the forceUTF8 configuration parameter was added.

Added an example for IDN registration (032) and fixed deriving example (011).
German SZ (ß) will be encoded as 'ss'.

creditMsgData messages in polling queue are now parsed.

## Version 3.1
A typo inside the domain object fixed. Thanks Luca!

Support for clientTransferProhibited added to the domain object. Thanks
to the guys at the registry and the new accreditation test!

Support for multiple domain states added to the domain object including
updates to all examples.

Added support for the exDate domain information. This is a small update to the
DB as well, with the big advantage that you could do credit-forecasts. This
would only be an approximation though, and you will have to take into account
how many domains you usually register in any period of time (the crDate may be
of some help in doing so).

## Version 3.0
Access to trStatus property in examples 019/020/021 fixed.

Update to Domain storeDB method in order to permit a few not very common
cases like 're-transfer-in' or a 're-register-after-delete'.

Any transferStatus query now also fills the reID fields in `tbl_messages`.

## Version 3.0 rc 2
Include path in all examples is now based on the `__FILE__` constant so that you
may now launch a file from whatever path you are in. In order for that the
internal behaviour of the Client class (which locates and reads the config.xml
file) has been altered as well. This now extends nicely to the new WSDL class
as well and we don't need an ugly, separate config file for this.

Smarty default values (empty) should be kept from now on. The smarty compile
folder still needs write permissions (i.e. www-data on Debian/Ubuntu if you are
trying to use the WSDL interface). If this is not granted the Client class
will try to fall back to '/tmp', emit warnings in case of success and die with
an error message in case of a failure.

Any transferStatus query now fills the reID/acID fields (if set).

Added new Smarty and adoDB libraries.

## Version 3.0 rc 1
Domain and Contact object values are now re-initialized before every fetch from
either DB or EPP servers.

First implementation of a message queue parser. Messages are stored after any
poll 'req' in `tbl_messages` in more or less human readable format and can be
retrieved with the `retrieveParsedMessages()` method call. There are 3 new
example scripts on how to use this functionality, while example 009 has
been 'deactivated', since its implementation was completely wrong and is now
superseded by 029 and 030. This should now be the correct approach to the
issue hinted at by Marco about two weeks ago.

The WSDL start file has now moved into the `public/` folder in order to deny
file access to any other files through the webbrowser. Let's try to keep to
good security practices even though this will probably always be an internal
solution... ;-)

## Version 3.0 beta 4
Updates to both Contact and Domain objects in order to prevent the registration
of "changes" when a value of an existing object hadn't really changed.

## Version 3.0 beta 3
Added centralized error handling functions to Net_EPP_IT_AbstractObject and to
Net_EPP_IT_StorageDB. Updated error handling in all example scripts.

Added some modifications for consentforpublishing handling (thanks Marco!).

## Version 3.0 beta 2
Public beta release for the new version. As soon as the final 3.0 version is
out the "what's new" information will again be dumped.

## Version 3.0 beta 1 (internal only)
The DB layout changed in order to support user ownership on objects (mandants).
This obviously constitutes a breach in the continuity of some DB access
methods and is underlined by an upgrade of the major version number.

WSDL support is here! All functions can be accessed as webservices now.

Minor changes include, but are not restricted to the following:

- String quoting for values to be stored in DB added. This functionality can be
  controlled in config.xml by the new dbmagicquotes parameter.
- It is now possible to update DNS server's IP addresses.
- Some method documentation cleaned up (public functions being wrongly
  documented as protected).
- Contact status is now handled by the Contact object. Thanks Marco for pointing
  this out!
- Better error handling on DB operations (now using ADOdb's ErrorMsg method).

## Version 2.11
Initialization of change-relevant information in domain objects after calls
to loadDB added. Some more cleanups to various methods as suggested by Luca.
Thanks again!

## Version 2.10
Some code cleanup suggested by Luca!

## Version 2.9
Two more bugs found by Luca have been corrected.

1. Using an `in_array` comparison containing a boolean `TRUE` will cause this to
   match ANY string passed to it, since `in_array` only does not do type-safe
   comparisons. This error relates to the values for "consentforpublishing" and
   has caused every contact where it has been manually assigned to be created
   with it set to 'TRUE' instead of whatever was your intention!
2. The storage driver will not save the "consentforpublishing" information
   correctly. The SQL-type for it was defined to be MySQL's tinyint by the schema
   provided in all versions. Since all doStore() calls will encapsulate every
   variable in between two single apostrophes (i.e. 'value'), this has caused
   every contact to be saved with "consentforpublishing" set to '0'.

> **ATTENTION!** Everyone using the storage driver provided with an implementation
> up to 2.9 will therefore have lost information about the "consentforpublishing"
> value and is strongly advised to check backups and set things straight!

## Version 2.8.1
Cleaned up the `CLI-generic-update-domain-authinfo.php` example and combined
a common interface to all major update operations inside of a single example
called `CLI-generic-domain-script.php`.

## Version 2.8
Removed a minor error from the update-domain template. Also updated both CLI
examples for updating NS and tech-c records in order to support adding or
removing of multiple values in one go. Thanks Marco for pointing both out!

## Version 2.7
Adding NS records and technical contacts that already exist will no longer be
treated as a change to the domain. By accident `print_r` statements were left
behind in release 2.6; this has been corrected.

Cleanup of lines 224 and 225 in Contact.php causing an `E_NOTICE` if using
`sanity_check()` with a phone number that has no dot (.) as a separator. Thanks
to Luca!

## Version 2.6
The changes applied to Domain.php between r45 and r57 have been undone and
simplified by using `array_diff`.

The library should now correctly handle multiple technical contacts (also by
using `array_diff`). Technical contact handling has been adapted in both the
create and update domain templates. Thanks Marco for your input!

Please pay attention that a `get()` method call for the value of 'tech' will
still result in a string instead of an array when only one contact is set. This
may cause some issues if you end up with a domain that owns more than one, so
better DON'T rely on it being a string!

Please see the included sample files `CLI-generic-fetch-domain.php`,
`CLI-generic-update-domain-techc.php` and `028-enhanced-db-layout.php`.

## Version 2.5
The only major change can be found in class Client. Its constructor now
accepts configuration parameters as an XML parameter string. This is useful
if you store configuration values in some other back end solution.

Minor changes to:
- check definition of LOG-Priorities prior to setting them
- check existence of HTTP_Client and Smarty classes prior to importing them
- updated README file (installing HTTP_Client PEAR package)

Fixes:
- domain updates when adding NS records fixed

## Version 2.4
Added support for changing single values for registrants. If a contact has
already information set in these fields, only single fields that are still
empty can be set. Adjusted Contact.php class and update-contact template
to allow for these specific operations. Thanks Robin!

An example for this is now available as well.

## Version 2.3
Added `set_include_path('.:'.ini_get('include_path'));` to all examples. This
should help conflicts with "Net/" include paths defined in the system wide
include_path setting.

Handling of domain and contact arrays in check commands adjusted for DB usage.

Added some more examples on how to use class extension on the StorageDB driver
class.

## Version 2.2
Bugfix release. Changed an error in StorageDB.php which would log empty data
when using the retrieveDomain method. Thanks to Luca for this hint!

## Version 2.1
Bugfix release. An error was introduced in v2.0 which caused a warning to be
printed during polling (and by that the storeMessage method).
A second issue hidden in the domain-update template caused an error when
trying to remove NS records through a domain update.

## Version 2.0
This version now supports handling of extended server error codes/messages and
transports them transparently to the DB layer. The update in the major release
number is due to interface changes in the StorageInterface class.

## Version 1.4
Different changes and updates to all modules. Mainly cleanups and some small
bug fixes. This version when released will have passed the accreditation
tests.

## Version 1.3
Added support for bulk domain/contact checks. Added example scripts including
change password script which actually updates the config.xml file as well.

## Version 1.2
Added an optional "newPW" parameter to Net_EPP_IT_Session's login method.
Updated basic checks related to the Domain and Contact classes EntityType
property.

## Version 1.1
The domain fetch method now retrieves the AuthInfo code which is sent by the
epp server after a domain info query. Thanks to Mr. Bianchi for pointing this
out!

## Version 1.0
Initial release.
