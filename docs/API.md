# eppitnic REST API

Every route lives under `/v1/`, takes and returns JSON, and authenticates with
`Authorization: Bearer <token>`. The API is a JSON/HTTP adapter (Slim 4) in
front of the `Eppitnic\Epp\*` registry classes and a MariaDB database
(RedBeanPHP's `R::` facade); the routes are in `src/Api/Routes/*.php`.

## Base URL and content type

All routes are prefixed `/v1/` except `GET /`, a plaintext "Hello, World!"
liveness check. Send `Content-Type: application/json` on request bodies.
Responses are `application/json; charset=utf-8`, except
`GET /v1/domains/export`, which returns `text/csv`.

Every error is JSON too, whatever the request's `Accept` header says — see
"Error shapes" below.

### Values from the database arrive as strings

RedBeanPHP leaves `PDO::ATTR_STRINGIFY_FETCHES` on, so integers and booleans
read straight from a table arrive as strings: a row's `id` is `"3"` and its
`active` flag is `"0"`, which is *truthy* in JavaScript. Compare explicitly
rather than testing a value for truth.

Values a handler builds or casts itself carry real JSON types: reseller
objects, the `epp`/`smtp`/`cronjobs`/`notifications` settings, the
`reseller_id` and boolean login claims, and the `total`/`outstanding`
counters.

### Responses are enveloped

A response body is an object with the data under a named key, never the data
itself:

| Route(s) | Body |
|---|---|
| `GET /v1/domains`, `.../expiring`, `.../autocomplete` | `{"domains": [...]}` |
| `GET /v1/domains/transfers` | `{"transfers": [...]}` |
| `GET /v1/domains/{name}` | `{"domain": {...}, "stale": bool}` |
| `POST /v1/domains`, `PATCH /v1/domains/{name}`, `.../registrant`, `.../status`, `.../owner` | `{"domain": {...}}` — no `stale` |
| `POST /v1/domains/import` | `{"results": {...}}` |
| `GET /v1/contacts` | `{"contacts": [...]}` |
| `GET /v1/contacts/{handle}` | `{"contact": {...}, "stale": bool}` |
| `POST /v1/contacts`, `PATCH /v1/contacts/{handle}` | `{"contact": {...}}` |
| `GET`/`POST /v1/users`, `GET`/`PUT`/`DELETE /v1/users/{id}`, `PUT`/`DELETE /v1/users/{id}/totp`, `PUT /v1/changepassword/{id}` | `{"users": [...]}` |
| `GET /v1/resellers` / `{id}` | `{"resellers": [...]}` / `{"reseller": {...}}` |
| `GET /v1/domains/{name}/tasks` | `{"tasks": [...]}` |
| `GET /v1/poll-queue` / `{id}` | `{"messages": [...], "total": n, "server_time"}` / `{"message": {...}}` |
| `GET /v1/history`, `/v1/history/{object}/{object_id}` | `{"history": [...], "total": n}`; the listing adds `server_time` |
| `POST /v1/users/authenticate`, `GET /v1/users/renew-token`, `GET /v1/users/me` | **not enveloped** — the claims are the body |

Routes that perform an action answer with a flag named after it, plus the
object's key:

| Route | Body |
|---|---|
| `DELETE /v1/domains/{name}` | `{"deleted": true, "domain": "example.it"}`, or `{"scheduled": true, "domain", "date"}` for `mode=expiry\|date` |
| `POST /v1/domains/{name}/restore` | `{"restored": true, "domain"}` |
| `POST /v1/domains/{name}/transfer` | `{"requested": true, "domain"}` (`201`) |
| `POST /v1/domains/{name}/transfer/{approve\|reject\|cancel}` | `{"approve": true, "domain"}`, `{"reject": ...}`, `{"cancel": ...}` |
| `DELETE /v1/contacts/{handle}` | `{"deleted": true, "handle"}` |
| `POST /v1/domains/{name}/tasks` | `{"created": true, "domain"}` (`201`) |
| `DELETE /v1/tasks/{id}` | `{"archived": true, "id"}` |
| `POST /v1/session/change-password` | `{"changed": true}` |
| `POST /v1/session/adopt-password` | admin | the API side of `config epp-password --force`: stores a registry password that is **already valid** there (changed in the registry's portal, or a restored install) without changing anything at the registry. Body `{"password": "..."}`, required, 6 to 16 characters. The registry is asked first with a login using it; only if that works is it stored (clearing a pending rotation) and answered `{"adopted": true}`. `400` for a missing or invalid password or one the registry rejects (EPP `2200` or reason `6005`; nothing is stored), `502` when the registry connection fails or it refuses the login for another reason (IP not allowed, session limit, ...), so the password could not be checked; `error` carries the registry's code and reason either way. On success the stored shared session is logged out and dropped so the next call logs in with the new password; if that fails the answer is still `200`, with a `warning`. Recorded as a `security`/`rotate` history row, never with the password |
| `DELETE /v1/users/{id}/api-token` | `{"revoked": true}` |

Two traps. In those action bodies `domain` is the **name as a string**, not
the object it is everywhere else. And `GET /v1/users/{id}` answers
`{"users": [row]}` — a one-element **array** under the plural key, and an
empty array rather than a `404` when the id doesn't exist.

### Successful responses with warnings

A write the registry accepted is a success, even when something after it
did not work. The response then carries `warnings`, a list of messages, next
to its usual fields; each is also written to the server log:

```json
{
  "domain": { "domain": "example.it", "...": "..." },
  "warnings": ["PowerDNS zone for example.it could not be prepared (http://pdns:8081: 401 Unauthorized); the next pdns sync retries it"]
}
```

It appears on the domain and contact write routes when the local copy could
not be updated after the registry change (a later sync reconciles it), and
when a PowerDNS zone could not be prepared before a registration or a
nameserver change (see [POWERDNS.md](POWERDNS.md)). Without warnings the
field is absent. Don't repeat such a request: the registry change is done.

## Setup

`GET /v1/setup`, `POST /v1/setup/verify` and `POST /v1/setup` are reachable
only while `config/config.php` does not exist; each answers `404` once it
does. No auth — there is nothing to authenticate against yet, and the file's
absence is the gate (see [INSTALL.md](INSTALL.md)). They're served from a
separate, database-free Slim instance (`Eppitnic\Api\SetupApp`), not from the
route list below: `public/index.php` runs one or the other, never both in the
same request.

`GET /v1/setup` → `{"required": true, "fields": [...], "password_policy": ...}`.
`fields` has one entry per input field (`name`, `label`, `default`, `secret`,
`group`, `required`), the same list `eppitnic setup` prompts from and
`public/setup.html` renders from. `group` is `database`, `admin` or `epp`.
`password_policy` describes the admin password rule (see "Users"), so a form
can state it before anybody types.

`POST /v1/setup/verify` — body: the `database` group's fields (`db_type`,
`db_host`, `db_name`, `db_charset`, `db_user`, `db_password`). Opens a
throwaway connection and discards it; `{"ok": true, "server_version": "..."}`
on success, `{"error": "..."}` / `400` otherwise. Nothing is written.

`POST /v1/setup` — body: every field from `GET /v1/setup`. `registrar_tag`
(upper case, ending `-REG`) is required; `epp_username` defaults to it and
`epp_cl_trid_prefix` to the tag without `-REG`. Applies
`config/mariadb-schema.sql`, creates the first admin account, and writes
`config/config.php` as its last step — a failure partway through leaves
nothing committed, so the call is safe to retry.
`{"ok": true, "schema_version": "070100", "admin": {"id": 1, "username": "..."}}`
on success, `{"error": "..."}` / `400` otherwise. Never returns a database or
admin password, and a schema failure's raw SQL is cut from the response body
(the full detail goes to the server log).

## Authentication

Two mechanisms produce a bearer token that every route accepts the same way: a
short-lived login JWT, or a long-lived fixed API token for scripted access.
Send either as `Authorization: Bearer <token>`. A third, remote
authentication, trusts a front server's login instead.

### Log in and get a JWT

`POST /v1/users/authenticate` — body `{"username", "password", "totp"?}`.
No auth required. Looks up an active user by `username` and checks `password`
with `password_verify()` (also for an unknown username, against a dummy hash,
so the response time does not reveal which usernames exist). If the account has a TOTP secret **and** the request
doesn't come from a `safe_networks` CIDR (the `settings` table, key
`safe_networks`), a valid `totp` code is required in the same request. There
is no separate two-step exchange: retry the whole call once you have the code.
What a password login may do depends on the user's sign-in requirement (see
"Sign-in requirements"); a passkey signs in through its own routes.

Success response — every claim plus a `token`:

```json
{
  "token": "<JWT>",
  "id": "3",
  "role": "manager",
  "reseller_id": 2,
  "reseller_name": "Example Reseller",
  "username": "reseller1",
  "has_totp": true,
  "has_passkeys": false,
  "needs_totp": true,
  "totp_verified": true,
  "amr": "pwd+otp",
  "strong_auth": true,
  "needs_second_factor": false,
  "debug": false,
  "max_token_age": null,
  "max_idle_time": null,
  "exp": 1790000000
}
```

- `role` — `admin`, `manager` or `user`; `reseller_id`/`reseller_name` —
  the reseller the account belongs to (see "Authorization model"). Shown for
  the client's convenience only: the server looks both up on every request.
- `has_totp` / `needs_totp` — whether the account has MFA configured, and
  whether *this* login required it (false from a safe network).
  `totp_verified` is true only when a TOTP code was checked for this token
  (never after a safe-network login). The server reads `has_totp` from the
  account on every request, not from the token.
- `has_passkeys` — whether the account has a passkey that meets the passkey
  policy; read from the account on every request too.
- `needs_second_factor` — whether the MFA gate refuses *this* session now: it
  used the password alone, the account has TOTP or a compliant passkey and
  the client is outside `safe_networks`. A client can hide what would answer
  `403` `MFA verification required` instead of loading it. Computed on every
  request from the current claims and network; `renew-token` carries the
  values of that moment.
- `amr` — how the session began: `pwd`, `pwd+otp` or `passkey`;
  `strong_auth` is true for the last two, and the MFA gate accepts them
  everywhere. `cred` (passkey sign-ins only) is the key's id: the session ends
  with `401` `Session ended: passkey no longer valid` once that key is removed
  or stops meeting the passkey policy. `renew-token` keeps all three.
- `max_token_age` — the longest token lifetime in minutes an admin or
  manager allows this user: one of `5`, `10`, `15`, `30`, `60`, `90`, `120`,
  `240`; `null` (and `0`, or a negative number) means `240`. Users cannot
  change it themselves.
- `token_lifetime` — the user's own choice from the same list, `null` meaning
  `15`. A token lives for the smaller of the two. `renew-token` re-reads
  `max_token_age`, `token_lifetime` and `max_idle_time` from the account, so a
  change applies from the next renewal without a new login.
- `exp` — the token's expiry as a unix timestamp.
- `max_idle_time` — minutes a session may sit idle; `null` and `0` mean no
  limit. Logging in and every request made with a session token record the
  time in `users.last_activity` (UTC, written at most once a minute). A
  request arriving after `max_idle_time` minutes without one is refused with
  `401` `Session expired after inactivity`, however much of `max_token_age`
  is left, and the user has to log in again. Fixed API tokens and remote
  authentication have no session and are exempt.

Failures:

| Status | `error` |
|---|---|
| `401` | `Please provide a username` / `Please provide a password` |
| `401` | `Wrong username or password` — whichever half was wrong |
| `401` | `MFA code required` / `Invalid MFA code` |
| `403` | `Your reseller account is deactivated` — correct password, deactivated reseller |
| `403` | `Password change required` — see "Forced password change and MFA enrollment"; no token is issued |
| `403` | `MFA enrollment required`, `Passkey enrollment required`, `Ask an administrator to reset your sign-in`, `This account signs in with a passkey` — same, see "Sign-in requirements" |
| `429` | too many failures from this network (below) |

`GET /v1/users/renew-token` (any valid token) re-issues a fresh JWT from the
current token's claims, same response shape as login. Use it to keep a
session alive without re-prompting for a password. It does **not** re-check
TOTP.

`GET /v1/users/me` (any valid token) returns the claims as-is, without
`token`.

### Sign-in requirements

Every user has a `signin_requirement`, set with `POST`/`PUT /v1/users` or
`user signin-requirement`. It says what the user must set up and which
sign-ins are refused. Passkeys themselves are voluntary: with any requirement
a user may register keys (at most 10) and sign in with one.

| Value | Must set up | Signs in with | Refused |
|---|---|---|---|
| `passkey` | one passkey | a passkey | the password, with or without a code, once a key exists, also on `safe_networks`; until then it only leads to the setup |
| `totp` (default) | the authenticator app | password and code; a passkey | the password alone, outside `safe_networks` |
| `totp_or_passkey` | the app **and** one passkey | password and code, or a passkey | the password alone, outside `safe_networks` |
| `none` | nothing | the password; a passkey | the password alone once the user has a compliant key and no authenticator app, also on `safe_networks`. Only an admin sets it |

A user may always set up more than the requirement asks for, and then the weaker
way in stops working: a user with a compliant key and no authenticator app can
no longer sign in with the password alone (`403` `required: passkey`), whatever
the requirement; open sessions are not ended, the next sign-in needs the key.

A user who wants a fallback to a lost key takes `totp_or_passkey`. A new user
gets `totp`, and so does the first admin `eppitnic setup` creates.

`safe_networks` skip only the TOTP code and the app's enrolment, never a
passkey. A user who already has the app gives its code outside
`safe_networks` whatever the requirement. A key counts only while it meets the
passkey policy (`GET /v1/settings/passkeys`); with passkeys switched off no key
counts, and a requirement that asks for one cannot be set.

`POST`/`PUT /v1/users` refuse a value outside the four with `400`
`signin_requirement must be one of: passkey, totp, totp_or_passkey, none`, and
`passkey` or `totp_or_passkey` while passkeys are off with `400` `passkeys are
not enabled: set the relying party first (settings passkeys)`.

### Sign in with a passkey

`POST /v1/users/authenticate/passkey/options` — no body, no auth, rate-limited
like a login (`429`). Returns `{"publicKey": {...}, "challenge_id": "..."}`:
WebAuthn request options for a usernameless sign-in (any resident key, user
verification required, a 5-minute timeout) and a signed challenge id, valid 5
minutes, usable once. `400` `{"error": "Passkeys are not enabled",
"passkeys_available": false}` while the relying party is empty: a login screen
can use that answer to decide whether to offer a passkey.

`POST /v1/users/authenticate/passkey` — body `{"challenge_id", "credential"}`,
`credential` being the browser's assertion as JSON (`PublicKeyCredential`'s
`toJSON()`, an object or a string). No username is sent: the key names its
user. Answers:

| Status | Body |
|---|---|
| `200` | the token body of a login, with `amr` `passkey`, `strong_auth` true and `cred` the key's id; `totp_verified` is false |
| `401` | `{"error": "Passkey sign-in failed"}`, the one answer for every failure: a malformed request, an unknown, spent or expired challenge, an unknown key, a wrong user handle, a bad signature, a counter that did not advance (a constant 0 is accepted), a key that no longer meets the passkey policy, passkeys off. The reason goes to the log only; the failure is counted for the rate limit |
| `403` | `Your reseller account is deactivated`, or what the login still owes after a right key: `password_change` or `mfa_enrollment`, as in the next section |
| `429` | too many failures from this network |

A passkey sign-in is accepted for an active user of every requirement, and a
key must be among those that meet the passkey policy. The sign-in records the
key's use (`last_used`, signature counter) and `login_succeeded` with `method`
`passkey` and the key's id.

### Forced password change and MFA enrollment

The user flag `must_change_password` is set through `POST`/`PUT /v1/users`
(0/1, by a manager or admin, with the usual scope rules) or
`bin/eppitnic user create --must-change-password`. What else a user must set up
comes from their `signin_requirement`. `must_enroll_mfa` is still accepted and
ignored. While anything is pending, `POST /v1/users/authenticate` issues no
token, after every other check has passed:

| Pending | Answer |
|---|---|
| `must_change_password` | `403` `{"error": "Password change required", "required": "password_change", "password_policy": {...}}` |
| `totp` or `totp_or_passkey`, no authenticator app yet, client outside `safe_networks` | `403` `{"error": "MFA enrollment required", "required": "mfa_enrollment"}` |
| `passkey` or `totp_or_passkey`, no compliant passkey yet (also on `safe_networks`) | `403` `{"error": "Passkey enrollment required", "required": "passkey_enrollment", "passkeys_needed": 1, "passkeys_minimum": 1, "passkeys_have": 0, "passkeys_available": bool}` if the code was just checked or the account's `passkey_enroll_open` is set; `passkeys_available` is false while passkeys are switched off |
| the same, by the password alone (a safe network, or no TOTP) with the enrolment closed | `403` `{"error": "Ask an administrator to reset your sign-in", "required": "signin_reset"}` |
| `passkey`, or `none` without an authenticator app, with a compliant passkey, by password | `403` `{"error": "This account signs in with a passkey", "required": "passkey"}` |

The password change comes first, then MFA, then the passkey. MFA enrollment is
only demanded from outside `safe_networks`. These answers are not failed
logins for the rate limit; they are recorded as `security`/`secread` rows
(`event='login_requirement_pending'`).

`passkey_enroll_open` lets the password alone register a user's first key
without a second factor. It is set when a user is created or changed to a
requirement that asks for a key they have not got, by `signin-reset`, by a
sysop's `passkey-revoke` that leaves none, and for everyone who needs a key
when the relying party changes; it is cleared when the key is registered.

The routes below need no token. Each takes `username` and `password` again
(plus `totp` for an account that has MFA, outside `safe_networks`), and is
rate-limited and failure-recorded like `authenticate`. A wrong credential is
`401`.

| Route | Body | Result |
|---|---|---|
| `POST /v1/users/authenticate/password` | `new_password` | `400` unless a change is pending, or if the new password fails the password rule (`error` and `password_policy`) or equals the current one. On success the flag is cleared, the change is recorded in `history` (`users` update, `security`/`rotate` `password_changed`), and the answer is that of a login: `403` for what is still pending, or `200` with the token body above |
| `POST /v1/users/authenticate/mfa` | — | `400` unless enrollment is pending (and no password change is). Returns `{"secret", "uri"}` |
| `PUT /v1/users/authenticate/mfa` | `totp` | verifies the code against the secret from `POST`, stores it and answers like a login: `403` for what is still pending (the passkey, for `totp_or_passkey`), else `200` with the token body (`has_totp` and `totp_verified` true). `401` on a wrong code, counted |
| `POST /v1/users/authenticate/passkey/enroll` | — | `400` unless a passkey is due and passkeys are on (`{"error": "Passkeys are not enabled", "passkeys_available": false}` when off); `403` `signin_reset` unless the code was just checked or `passkey_enroll_open` is set. Returns `{"publicKey", "challenge_id"}` (creation options and an integer challenge id, valid 5 minutes, once); the challenge remembers whether the code was checked. `400` with a `reason` (`metadata`, `limit`) when no registration can start |
| `PUT /v1/users/authenticate/passkey/enroll` | `challenge_id`, `credential`, `name?` | username and password again, **not** the code: the challenge stands for it. `400` `{"error", "reason"}` for a registration that does not verify or does not fit (see "Passkey registration failures"). `400` `No passkey enrollment is pending` unless a passkey is due. On success the key is stored, `passkey_enroll_open` is cleared, and the answer is the `200` login answer with the token body (`amr` `passkey`): a password change and MFA enrolment are checked first, so nothing else can be pending |

An open session is held to the same rules on every request, with the messages
of the login's answers: `403` `Password change required`, `MFA enrollment
required` (from outside `safe_networks`), `Passkey enrollment required`
(with `required`, `passkeys_needed`, `passkeys_minimum` and `passkeys_have`),
`Ask an administrator to reset your sign-in`, and, for a password session of a
`passkey` user who now has a key, `Passkey sign-in required`. A session that
began with a passkey ends with `401` `Session ended: passkey no longer valid`
once its key is removed or stops meeting the passkey policy. Remote
authentication and fixed API tokens are not affected.

### Step-up proof

Adding or removing one's own passkey, removing one's own TOTP secret under the
requirement `passkey`, and an admin loosening their own sign-in requirement
need a fresh proof in the same request, as `stepup`. Its kind is the
strongest the user has:

| Kind | `stepup` value |
|---|---|
| `passkey`, the user has a compliant passkey | `{"challenge_id", "credential"}`: an assertion for the options and `challenge_id` of `POST /v1/users/{id}/passkeys/stepup` |
| `totp`, the user has the authenticator app | `{"totp": "123456"}` |
| `password`, nothing else | `{"password": "..."}` |

`GET /v1/users/{id}/passkeys` shows the kind wanted as `stepup` (for the user
themselves). A missing or wrong proof is `401` `{"error": "Step-up
verification failed", "stepup": "<kind>"}`, recorded as the security event
`stepup_failed` and counted for the login rate limit like a failed login;
the proof is checked after the rate limit, so a blocked network gets `429`.

### Login rate limit

Before the credentials are examined, the caller's network is checked against
the failures it has already produced. Past `login_ratelimit.max_failures`
within `login_ratelimit.timespan` seconds, the call answers `429` with a
`Retry-After` header and `{"error": ..., "retry_after": n}`. The window
slides, so a block lifts itself as old failures age out. The same limit guards
the routes of "Forced password change and MFA enrollment", the passkey sign-in and
its options, and every step-up.

Failures are counted per **network**, not per address: IPv4 `/24` and IPv6
`/48` by default (`ipv4_prefix`, `ipv6_prefix`). An IPv6 customer gets an
allocation rather than an address, and `/48` is the usual end-site
assignment, so anything narrower leaves room to rotate subnets inside one's
own. Narrow it to `/56` or `/64` where blocking a whole site would catch too
many unrelated users. `max_failures: 0` disables the limit.

Every outcome is recorded in `history` as a `security` row carrying the
address, the network and the request headers:

| action | what it means | counted? |
|---|---|---|
| `login` | authentication succeeded | no |
| `denied` | unknown username, wrong password, wrong TOTP code, a failed passkey sign-in or step-up, or deactivated reseller | **yes** |
| `secread` | turned away by the limit, or a credential disclosure | no |

A registry password rotation is a `security` row too, with action `rotate` —
see "Registry password rotation" in [INSTALL.md](INSTALL.md).

Neither the attempted password nor the issued token is ever recorded. The
response stays vague about which half was wrong, so it cannot be used to
discover usernames; the log is precise.

**Behind a reverse proxy**, list it in the `trusted_proxies` setting
(`GET`/`PUT /v1/trusted-proxies`, below). `X-Forwarded-For` is only believed
when the address that actually connected is a trusted proxy. Without that
setting every request appears to come from the proxy: all clients share one
rate-limit bucket and none ever matches `safe_networks`.

### MFA gate

Some routes require the *current* token to be MFA-verified — not a password
session of an account with TOTP or a compliant passkey (`needs_second_factor`),
unless the request itself comes from a `safe_networks` address — or they
answer `403` `{"error": "MFA verification required"}`:

- every `admin` route (`Auth::requireAdmin()`: role `admin` plus the MFA check)
- every `manager` route (`Auth::requireManager()`)
- acting on another user's account (`Auth::actorFor()`)
- `PUT /v1/changepassword/{id}` and `DELETE /v1/users/{id}/totp`, even on
  one's own account

A login JWT passes when a code was checked at login, when it began with a
passkey, or when the account has neither TOTP nor a compliant passkey. A token
from a safe-network login carries no `totp_verified` and passes only on
requests from a safe network. Fixed API tokens and remote auth carry
`has_totp: false` and `has_passkeys: false`.

### Set up TOTP (MFA) for a user

- `POST /v1/users/{id}/totp` (self, their manager, or admin) — generates a
  pending secret and returns `{"secret": "...", "uri": "otpauth://..."}`.
  Render `uri` as a QR code; authenticator apps list it as `eppitnic (<host>)`,
  the host the request reached the API at. MFA is not active yet. `400` when
  the account already has a TOTP secret: remove it first with `DELETE`.
- `PUT /v1/users/{id}/totp` (self, their manager, or admin) — body `{"totp": "123456"}`.
  Verifies against the *pending* secret and, on success, activates it.
  `400` if there's no pending setup, `401` if the code doesn't verify.
- `DELETE /v1/users/{id}/totp` (self, their manager, or admin; MFA-verified)
  — clears the active and any pending secret, disabling MFA. For one's own
  account under the requirement `passkey` it takes a `stepup` in the body
  (`401` `Step-up verification failed` otherwise).

### Fixed API tokens for scripted access

- `POST /v1/users/{id}/api-token` (self, their manager, or admin) — body
  `{"expires"?: <UNIX_TIMESTAMP>}`, `0` or omitted for never. Generates a
  random 256-bit token and returns it **once** as
  `{"token": "...", "expires": 0}`. Only its SHA-256 hash is stored
  (`users.api_token`), so it cannot be recovered, only reissued; reissuing
  replaces the previous one.
- `DELETE /v1/users/{id}/api-token` (self, their manager, or admin) — revokes it.
- Send it like a JWT: `Authorization: Bearer <token>`. It never expires unless
  `expires` was set and carries no MFA claims. Route handlers see the same
  claims shape as for a JWT (`Auth::verify()` synthesizes it).

### Remote authentication

When the `remote_auth` setting is enabled (`config remote-auth-set` or
`PATCH /v1/remote-auth`; see [REMOTE-AUTH.md](REMOTE-AUTH.md)), a front
server's already-authenticated username is trusted in place of a bearer
token:

- A `Bearer` `Authorization` header, if present, always takes the
  JWT/fixed-token path above.
- Otherwise the username is read from `REMOTE_USER`, or in header mode from a
  configured header sent by a `trusted_proxies` peer, and mapped to a local
  user.
- A remote username with no matching active local user is **403**.
- `GET /v1/users/renew-token` answers **400**
  `{"error": "Token renewal is not available with remote authentication"}`.
- `GET /v1/users/me` carries `"remote_auth": true`.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/remote-auth` | admin | `{"remote_auth": {"enabled", "header"}, "trusted_proxies": [...]}`. `header` null = the `REMOTE_USER` server variable. `trusted_proxies` is read-only here (header mode only trusts those peers); change it with `PUT /v1/trusted-proxies` |
| `PATCH /v1/remote-auth` | admin | body = partial field map, e.g. `{"enabled": true, "header": "X-Remote-User"}`; `null`/blank `header` goes back to `REMOTE_USER`. `400` on an unknown field or an invalid header name (`Authorization`, `Cookie` and `Proxy-Authorization` are refused). Audited in `history` (`object='remote_auth'`). Returns the same shape `GET` does |
| `GET /v1/trusted-proxies` | admin | `{"trusted_proxies": ["10.0.0.0/8", ...], "peer": "172.18.0.1"}`. `peer` is the address this request itself arrived from — behind a reverse proxy, the address the proxy must be listed as |
| `PUT /v1/trusted-proxies` | admin | body `{"trusted_proxies": [...]}` replaces the whole list; entries are addresses or networks, stored canonically (`172.18.0.1` → `172.18.0.1/32`, host bits cleared), duplicates dropped. `400` on an entry that is not a network, on a catch-all (`0.0.0.0/0`, `::/0`), or when the list is missing. Audited in `history` (`object='trusted_proxies'`). Returns the same shape `GET` does |
| `GET /v1/allowed-origins` | admin | `{"allowed_origins": ["https://epp.example.it", ...]}` — the browser origins the CORS check lets through (see "CORS") |
| `PUT /v1/allowed-origins` | admin | body `{"allowed_origins": [...]}` replaces the whole list; entries are `http`/`https` origins (scheme, host, optional port), stored as browsers send them (`HTTPS://Epp.Example.it:443/` → `https://epp.example.it`), duplicates dropped. `400` on anything else (a path, `*`, `null`) or when the list is missing. The request itself is checked against the list stored before it, so removing your own origin succeeds and locks out the next write. Audited in `history` (`object='allowed_origins'`). Returns the same shape `GET` does |
| `GET /v1/debugfile` | admin | `{"debugfile": {"path", "directory", "exists", "size", "writable"}, "warning": "..."}` — the registry debug log; `path` `""` = off, `writable` false with a path = nothing is being logged. `warning` is the text to show before turning logging on |
| `PUT /v1/debugfile` | admin | body `{"path": "epp-debug.log"}` starts logging: a bare `.log` file name or an absolute path inside `directory`; the file is created (mode `600`) before the setting is stored. `400` on another name or place, or when the file cannot be written; `409` while another log is recording — the setting is then unchanged. Audited in `history` (`object='debugfile'`). Returns the same shape `GET` does |
| `DELETE /v1/debugfile` | admin | deletes the log file, then clears the setting (logging off); a file already gone only clears it. `500` when the file cannot be deleted — the setting is then kept, so logging stays on. Audited in `history` (`object='debugfile'`, `action='delete'`). Returns the same shape `GET` does |
| `GET /v1/debugfile/content` | admin | the log as `text/plain`, at most its last 2 MiB (prefixed with a line saying how much was left out). Passwords, auth codes and cookie values in it are masked. `404` while logging is off or the file is empty. **Every read is recorded** as a `security`/`secread` history row (`event='debugfile_read'`) |

### Forgot password

For a user who cannot log in, when `GET /v1/network-check` says
`password_reset` is true. All three routes are public and subject to their own
rate limits; none needs a token.

| Method & path | Notes |
|---|---|
| `POST /v1/users/password-reset` | body `{"email": "...", "locale": "en"\|"it"\|"de"?, "origin": "https://..."?}`. **Always `202 {"ok": true}`**, whatever the address: unknown, inactive, rate-limited, a failed mail or a missing origin answer alike and are only logged. For an active user (of an active reseller) with that address (case-insensitive) it retires that user's earlier unused links, stores a new token (only its SHA-256) valid **10 minutes**, and mails `<origin>/reset-password?token=<64 hex>` in `locale` (`en` when unknown). Where the work happens after the response is sent (php-fpm) the timing is the same for every address; elsewhere it follows the mail send |
| `GET /v1/users/password-reset/{token}` | `200 {"ok": true, "password_policy": [...]}` if the link can still be used, else `400 {"error": "This link is invalid or has expired"}` — the same for an unknown, used, expired or deactivated-user token |
| `POST /v1/users/password-reset/confirm` | body `{"token": "...", "password": "..."}`. Sets the password (same policy as everywhere), clears `must_change_password`, spends the link and drops the user's other links; recorded in `history` as `{"password": "PASSWORD_CHANGED", "reset": "email"}`. `400` with the same neutral error for any token problem, `400` with the policy explanation and `password_policy` for a weak password (the link stays usable), `429` when the network has made too many attempts |

The link points at the first of these that is listed in `allowed_origins`:
the request's `Origin` header, the origin of its `Referer`, the body's
`origin` (for a development proxy that strips both). The `Host` is never
used. When none qualifies nothing is sent. MFA is unaffected: a user with TOTP
still needs a code to log in.

Limits (a sliding 15 minutes): a network (the login limit's prefixes) may
make 10 requests, an address 3 (a refused request does not extend its own
block), and a network 10 confirmations or link checks. Expired links and old
attempt counters are deleted by the next request.

### Change a user's login password

`PUT /v1/changepassword/{id}` (self, the user's manager, or an admin;
MFA-verified) — body `{"password": "..."}`. Changes the local
`users.password`, unrelated to the shared EPP registry credential. The new
password must meet the password rule (see "Users"), else `400`. A missing
password is `401`; changing a password you may not change is `403`.

## Authorization model

Contacts, domains and pending transfers belong to a **reseller**, not to a
user. Every user belongs to exactly one reseller, fixed when the user is
created, and has a **role**:

| Role | Where | May |
|---|---|---|
| `admin` | reseller 1 only ("Registrar (self)") | everything, every reseller's data, the admin-only routes |
| `manager` | any reseller | everything a user may, plus manage the reseller's users (not admins), its defaults and NS sets, and archive its poll messages |
| `user` | any reseller | work on all of the reseller's contacts and domains; sees only their own `users` row |

- The role and reseller are looked up **on every request** (`Auth::verify()`),
  not taken from the token: a changed role or a deactivated user or reseller
  takes effect on the next request. A deactivated user gets **403**
  `{"error": "Your account is deactivated"}`, a user of a deactivated reseller
  **403** `{"error": "Your reseller account is deactivated"}`.
- Everyone but an admin is scoped to their own reseller — every list, read and
  write filters by `reseller_id`, or answers 403 when the check fails outright.
- Every domain **write** route checks access (`Api\Access::canAccessDomain()`)
  *before* opening an EPP session, so a rejected call never reaches the
  registry: 403 `{"error": "You are not authorized to modify domain '...'"}`.
  Two exceptions, both claim-style operations where the caller isn't expected
  to own the domain yet: `POST /v1/domains/{name}/transfer` and
  `.../transfer/cancel` (see their rows below).
- A domain always belongs to its **registrant contact's reseller**. So the
  registrant must be one of the caller's reseller's contacts
  (`Access::canUseAsRegistrant()`): `POST /v1/domains` and
  `POST /v1/domains/{name}/registrant` answer 403 otherwise. Being allowed to
  *see* a contact because it hangs off one of your domains is not grounds for
  making it the registrant of another. An admin may use any reseller's
  contact, and the domain lands in that reseller.
- Contacts have a wider read rule than domains (`Access::canAccessContact()`):
  a reseller may `GET`/`PATCH` a contact it doesn't own, as long as it's
  attached (as registrant, admin, or tech) to one of its domains. `DELETE`
  requires owning it.
- **Daily quota** (`resellers.max_operations`, `0` = unlimited; admins exempt):
  registrations and transfer-in requests by any of the reseller's users that
  day, counted from `history` rows with `object='domains'`, `action='request'`
  — whether or not the transfer later completes. Imports, registry
  reconciliation and completing transfers don't count. Over it:
  **429** `{"error": "Daily operation quota exceeded"}`.

## Talking to the registry (EPP)

Handlers that need a live round-trip to the .it registry wrap their EPP
calls in `EppSession::run()` (`src/Service/EppSession.php`). With the
`keepalive` setting off — the default — that's one session per HTTP request:
connect, `hello()`+`login()`, run the callback, always `logout()`. With it on,
requests share one session that `eppitnic session keepalive` refreshes from
cron (see "Session keep-alive" in [INSTALL.md](INSTALL.md)); a request opens
it only when there is none fresh, and never logs out.

Either way, if the session can't be opened, or (`keepalive` only) the registry
turns out to be unreachable mid-request, the route returns **502** with
`{"error": "EPP session unavailable: ..."}`. A `400` is different: the
registry responded but rejected the operation. Routes that only touch the
local DB (`GET /v1/domains`, `.../expiring`, `.../autocomplete`, `.../export`,
`.../transfers`, most of `contacts`/`tasks`) never pay this cost.

The two single-object reads — `GET /v1/domains/{name}` and
`GET /v1/contacts/{handle}` — never 502: when the registry is unavailable they
fall back to the local DB and mark the payload `"stale": true`.

## Error shapes

One shape, wherever the request failed — a route's own validation or EPP
rejection, and equally the errors raised before a handler runs (missing,
invalid or expired token → 401; wrong role or missing MFA → 403; unknown
route → 404; wrong method → 405; uncaught exception → 500):

```json
{ "error": "Domain 'example.it' not found" }
```

Always a string, never nested, and there is **no** machine-readable `code`
field — branch on the HTTP status. A few answers carry more fields beside
`error` (`required`, `reason`, `stepup`, `confirm_required`, `impact`,
`retry_after`, ...); each is documented where it occurs. Matching the message text works but is
brittle; the one place it is unavoidable is telling an expired token
(`"Token has expired: ..."`) from an absent one, since both are 401. A client
that needs that distinction is better off reading `exp` from the claims and
renewing before it passes.

`Middleware::register()` (`src/Api/Middleware.php`) replaces Slim's error
handler and its HTML renderers with a JSON one, which is why `Accept` plays no
part. The status comes from the exception: `HttpException::getCode()` when a
handler threw one, 500 otherwise. Error responses are produced *inside* the
CORS middleware, so they carry the `Access-Control-Allow-*` headers too — a
browser can read its own 401 rather than seeing an opaque network failure
(`tests/Http/ErrorResponseTest.php`).

**Details are off by default.** `displayErrorDetails` is read from the
`EPPITNIC_DEBUG` environment variable — not the `settings` table, since the
error handler has to work when the database is unreachable. With it off, a
500's real message is replaced by `{"error": "Internal server error"}` and
only the server log gets the detail; 4xx keep their own messages. With it on,
responses additionally carry `exception` (the class name), `file`, `line` and
`trace`.

> **Warning:** never set `EPPITNIC_DEBUG` on anything public — stack traces
> leak file paths and internal values.

## CORS

The `Origin` header must appear verbatim in `allowed_origins` (edited with
`config allowed-origins` or `PUT /v1/allowed-origins`) — strict string equality, no wildcards, no subdomain
matching. Preflight `OPTIONS` gets `204` if allowed, `403` if not. Actual
requests from a disallowed origin get `403` with a JSON body but *without* CORS
headers, so the browser still blocks them as a CORS failure. Allowed headers
and methods come from the `allowed_headers` and `allowed_methods` settings.

**Requests with no `Origin` header bypass this check** and are served
normally, with no `Access-Control-Allow-*` headers. This is the path every
non-browser client takes — curl, cron jobs, anything using a fixed API token —
so `allowed_origins` only ever governs browsers. The schema seeds ship it
empty, which locks out browser clients without touching scripted ones.

Three details that matter to a browser client:

- `Access-Control-Expose-Headers: Content-Disposition` is set, so a
  cross-origin caller can read the filename `GET /v1/domains/export` supplies.
  That download needs the `Authorization` header, so it cannot be a plain
  link — fetch it and turn the response into a blob.
- `Access-Control-Allow-Credentials: true` is set, but the API keeps no
  cookies and no session state; auth is the bearer header alone. Leave
  `credentials`/`withCredentials` off — turning it on buys nothing.
- `Access-Control-Max-Age` is never sent, so browsers fall back to a very
  short preflight cache and re-`OPTIONS` almost every authenticated request.
  Serving the SPA from the API's own origin avoids the preflight, but the
  origin must still be listed in `allowed_origins`.

## Pagination

Only `GET /v1/tasks` is paginated; every other list returns the full scoped
set. `GET /v1/history` and `GET /v1/poll-queue` take
`limit` and the `before_id`/`after_id` cursors instead.

```json
{
  "total": 1234,
  "page": 1,
  "pageSize": 25,
  "rows": [ { "...": "..." } ]
}
```

`page` is 1-based. `pageSize` is clamped to `[1, 200]`, default `25`.

## Route reference

Auth column values:

| Value | Who may call |
|---|---|
| `public` | anyone, no token |
| `user` | any valid token; results scoped to the caller's reseller |
| `manager` | a manager or admin, MFA-verified |
| `admin` | role `admin`, MFA-verified |

Other values name the rule directly, e.g. "self, their manager, or admin".

### Session and registry

| Method & path | Auth | Notes |
|---|---|---|
| `GET /` | public | plaintext "Hello, World!", not JSON — liveness check only |
| `GET /v1/network-check` | public | `{"safe_network": bool, "client_ip": "...", "password_reset": bool}` — lets a login screen decide before login whether to show a TOTP field, and whether to offer "forgot password": `password_reset` is true while SMTP is enabled with a host and sender and remote authentication is off |
| `GET /v1/session/epp` | admin | the shared EPP registry account this installation uses, `{"epp": {...}}`: `server`, `server_deleted`, `port`, `interface`, `registrar_tag`, `username`, `lang`, `cl_trid_prefix`, `lastPasswordUpdate` (unix timestamp of the last automated password rotation attempt, `0` = never), plus `password_set` (bool) and `rotation_pending` (bool — a password rotation was interrupted; run `eppitnic doctor epp-password`). Local DB only, no registry round-trip. **The password itself is never returned** — the field list is an allow-list, so anything added to the `epp` setting later is withheld until explicitly published |
| `PATCH /v1/session/epp` | admin | change any of the 8 plain fields above (not `lastPasswordUpdate`, not `password`). Body = partial field map, e.g. `{"lang": "it"}`; `null` (or blank) unsets an optional field (`server_deleted`/`port`/`interface`) — `server`/`registrar_tag`/`username`/`lang`/`cl_trid_prefix` are required and `400` if unset. `400` on an unknown field or a failed validator. Recorded to `history` (`object='epp'`). Returns the same shape `GET /v1/session/epp` does. Shares its validation with `config epp-set`/`config epp-server` |
| `GET /v1/session/epp/interfaces` | admin | this server's own IPv4 addresses (loopback excluded), `{"interfaces": ["..."]}` — the choices a UI offers for the `interface` field, so a typo can't silently bind outgoing registry connections to nothing. Queried live via `net_get_interfaces()` |
| `GET /v1/session/credit` | admin | live EPP registry account balance, `{"credit": 2434.64, "credit_formatted": "2.434,64 €"}` (formatted per `region.lc_monetary`). The registry reports it only on login, so this always logs in and out, even with `keepalive` on; 502 if the registry session fails |
| `GET /v1/session/epp/credentials` | admin | the shared EPP registry credential itself — `{"credentials": {"server", "username", "password"}}`. Separate from `GET /v1/session/epp` on purpose: that one is what a settings screen loads, and a secret delivered as a side effect of rendering a page ends up in caches, proxy logs and screenshots. Needed because `eppitnic poll process` rotates the password automatically on a `passwdReminder`. When a rotation was interrupted the response also carries `pending_password` and a `note`: the registry holds one of the two and only it can say which. `404` when no password is configured. **Every retrieval is recorded** in `history` as a `security`/`secread` row: the acting user, the client IP, and the request headers. The password is not written, and `Authorization`, `Cookie` and `Proxy-Authorization` are stored as `[redacted]`. A refused request records nothing |
| `POST /v1/session/change-password` | admin | rotates the **shared EPP registry** credential (not any user's login password) — records the new password in the `settings` table, then logs into EPP with it to make the change. Body `{"password"?: "..."}`, 6 to 16 characters (the EPP limit), random if omitted. Answers `{"changed": true}`; `400` for an invalid password or a registry rejection, `502` when the registry connection fails, `500` when the settings write fails. A failure before the registry is reached leaves the current credential untouched; one after it is settled by `eppitnic doctor epp-password`. Recorded as a `security`/`rotate` history row and mailed to the SMTP system recipient, like every rotation |
| `GET /v1/poll-queue` | user | raw `messages` table rows — an admin sees all, anyone else only messages about a domain (or pending transfer-in) of their reseller; account-level messages without a domain stay the admins'. `?active=1\|0` (default `1` = unarchived only), newest first. `?limit=n` (1–1000) returns only the newest `n` (no limit: everything); cursors `before_id` / `after_id` (only messages with a lower / higher `id`) page through it; `changed_since=YYYY-MM-DD HH:MM:SS` keeps messages whose `archived_time` is at or after it (`400` unless a real datetime). `total` is how many matched the filters, whatever the cursors and the limit cut off. `server_time` is the database clock read before the queries: pass it as the next `changed_since` without gaps (an overlap is possible, so upsert by `id`): `{"messages": [...], "total": n, "server_time"}` |
| `GET /v1/poll-queue/{id}` | user | single message, scoped the same way; 404 if missing or not the caller's to see |
| `POST /v1/poll-queue/{id}/archive` | manager | within the same scope (404 otherwise); sets `archived_time`/`archived_user_id`; answers `{"archived": true, "id", "archived_time", "archived_user_id"}` |
| `POST /v1/poll-queue/archive` | manager | archive several messages in one call — a manager only their reseller's (ids outside it are skipped and not counted), and `outstanding` counts within that scope too. Either the ones a screen shows, as `{"ids": [...]}` (a non-empty list of integers; `400` otherwise, and every other field is ignored), or every unarchived message up to a moment, as `{"until": "YYYY-MM-DD HH:MM:SS"}` (a real datetime; `400` otherwise), compared to `created_time` inclusively. Pass the newest message the user has loaded, so what arrived since stays in the queue rather than being archived unread. `created_time` is only second-resolution, so pass `"until_id"` (that message's `id`) as well for an exact cutoff — ids are monotonic, and when given it is used in place of `until`. Already-archived messages keep their stamp. Answers `{"archived": n, "until", "until_id", "archived_time", "archived_user_id", "outstanding"}` (`until_id` only when given) — `archived_time` and `archived_user_id` are the stamp written to every message it archived; `outstanding` is how many unarchived messages remain. By `ids`, the answer has no `until` and `until_id` |

### Users

**Password rule.** Every route that *sets* a password — `POST /v1/users`,
`PUT /v1/users/{id}` (only when one is supplied) and
`PUT /v1/changepassword/{id}` — requires at least 12 characters including a
lower-case letter, an upper-case letter, a digit and one character that is
none of those. A password that misses any of them is refused with `400` and a
message naming what is missing. `Persistence\User::create()` enforces the same
rule, so the CLI and the installer cannot route around it.

`POST /v1/users/authenticate` is deliberately **not** subject to the rule:
judging a password at login would lock out accounts whose passwords predate
it, and the refusal there stays the generic `Wrong username or password`.

A **manager** manages the users of their own reseller: creates them (as
`manager` or `user`), edits and deactivates them, but never an admin, never
`debug`, and never in another reseller. Nobody may change their own `role` or
deactivate themselves, and neither the last active admin nor an active
reseller's last active manager may be demoted or deactivated — each refused
with `400` or `403` and a message saying why. Promoting them (a manager to
admin) is allowed, and so is either change once their reseller is
deactivated.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/users` | user | an admin sees every user (`?reseller_id=` narrows it to one reseller), a manager their reseller's, a plain user only themselves. Rows: `id, active, role, reseller_id, reseller_name, username, max_token_age, max_idle_time, debug, notify_enabled, has_totp, signin_requirement, passkeys` (the compliant keys), `has_passkeys`, and for a manager or admin also `description, email, must_change_password`. Never password hashes |
| `GET /v1/users/{id}` | user | same field set and scope, still `{"users": [row]}` — a one-element array, and `[]` for an unknown id or one the caller may not see |
| `POST /v1/users` | manager | create; `201`. Required: `username`, `password`, `email`. Optional: `description`, `role` (default `user`; `admin` only in reseller 1), `reseller_id` (admin only, default 1; a manager's users join their own reseller), `notify_enabled` (default on for managers/admins, off for plain users), `active` (default `1`), `max_token_age`, `max_idle_time`, `debug` (admin only), `must_change_password` (0/1, default `0`), `signin_requirement` (`passkey`, `totp`, `totp_or_passkey` or `none`; default `totp`; `none` by an admin only, `403` otherwise). `must_enroll_mfa` is accepted and ignored. `400` if a required field is missing, the username is taken or empty or longer than 32 characters, `email` is not an address (at most 256 characters) or belongs to another account (`this e-mail address is already used by another account`; compared case-insensitively), `max_token_age` is not `null` or one of the lifetimes above, `max_idle_time` is not `null` or a non-negative whole number, or the role doesn't fit the reseller. The creation is recorded in `history` under the creating user |
| `PUT /v1/users/{id}` | manager | update of the same field set (validated the same way); **every field is optional** — anything omitted keeps its current value (including `password`) — but the account must end up with a valid, unused `email` on every save, so an account without one cannot be saved until it is given one. `reseller_id` can never change (`400`). `404` for an unknown id. `signin_requirement` follows the rules of "Sign-in requirements"; `none` is for admins (`403` for a manager) and a requirement that needs a key is `400` while passkeys are off. Nobody may give themselves a weaker one (strongest first: `passkey`, `totp_or_passkey`, `totp`, `none`) except an admin, who sends a `stepup` in the same request (see "Step-up proof"): `400` with `stepup_required: true` and the kind wanted (`stepup`) without one, `401` `Step-up verification failed` for a wrong one; a fixed API token or remote auth cannot give it and gets `400`. Managers and users keep the `400` (`you cannot loosen your own sign-in requirement`). A change that asks for a key the user has not got opens their `passkey_enroll_open`. Recorded as the security event `signin_requirement_loosened` (`from`, `to`) besides the `history` row, and `signin_requirement_none` when it becomes `none` |
| `DELETE /v1/users/{id}` | manager | deactivate (`active = 0`), with the same rules as setting it through `PUT` |
| `GET /v1/users/{id}/notifications` | self, their manager, or admin | this user's own email notifications, `{"notifications": {"enabled": bool, "message_types": [...], "fulltext": "..."}, "message_types": [...]}` (the second `message_types` is the full allow-list, for a picker). `404` for an unknown user |
| `PATCH /v1/users/{id}/notifications` | self, their manager, or admin | body: any of `enabled`, `message_types` (a list from the allow-list above) and `fulltext`; what is omitted stays. Returns `{"notifications": {...}}`. Only takes effect while the system-wide `smtp.recipient_mode` (see "Email (SMTP)") includes `user` |
| `PATCH /v1/users/{id}/session` | self, their manager, or admin | body: `token_lifetime`, one of `5`, `10`, `15`, `30`, `60`, `90`, `120`, `240` minutes, or `null`/`""` for the default (15). `400` for any other value or one above the user's `max_token_age` (`240` when unset), `404` for an unknown user. Returns `{"session": {"token_lifetime": ..., "max_token_age": ...}}`; recorded in `history`. Applies from the next `renew-token` or login |

### Passkeys

Keys are registered by the user, never on their behalf. A manager or admin
lists and removes the keys of users in their scope, and starts a user's sign-in
over with `signin-reset`. The requests marked `stepup` carry the proof described
in "Step-up proof". Challenges of the routes below are integers, valid 5
minutes and usable once.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/users/{id}/passkeys` | self, their manager, or admin | `{"passkeys": [{"id", "name", "aaguid", "model", "backup_eligible", "backup_state", "attestation_format", "attestation_verified", "compliant", "created", "last_used"}], "signin_requirement", "minimum", "compliant_count", "enroll_open", "passkeys_available"}`, plus `stepup` (the kind wanted) for the user themselves. `minimum` is the keys the requirement needs: 1 for `passkey` and `totp_or_passkey`, else 0. `model` is what the FIDO metadata calls the AAGUID, else the catalogue's name, else `null`. Never the credential id. `404` for an unknown user |
| `POST /v1/users/{id}/passkeys/options` | self only | no body; `{"publicKey", "challenge_id"}`, creation options for a new key (user verification and a resident key required; ES256, EdDSA or RS256; attestation `direct` under `device_bound`, else `none`; the user's keys excluded). `400` `{"error": "Passkeys are not enabled", "passkeys_available": false}` while passkeys are off, `400` with `reason` `metadata` or `limit` when no registration can start |
| `POST /v1/users/{id}/passkeys/stepup` | self only | no body; `{"publicKey", "challenge_id", "stepup": "passkey"}`, request options limited to the user's compliant keys. `400` `{"error": "No passkey to confirm with", "stepup": "<kind>"}` without a compliant key |
| `POST /v1/users/{id}/passkeys` | self only | body `challenge_id`, `credential`, `stepup`, `name?` (at most 64 characters; default `Passkey n`). `201` `{"passkey": {...}}` and the fields of `GET` above. `400` `credential and challenge_id are required`, `400` `{"error", "reason"}` for a registration that fails (see below), `401` `Step-up verification failed`, `429` |
| `PATCH /v1/users/{id}/passkeys/{pid}` | self only | body `{"name"}`; `{"passkey": {...}}`. `400` `name is required` for a missing, empty or over-long name, `404` `Passkey not found`. No step-up |
| `DELETE /v1/users/{id}/passkeys/{pid}` | self (with `stepup` in the body), or their manager or admin | the fields of `GET` above. `400` `{"error", "minimum": 1}` if it is the last compliant key of a user whose requirement needs one: the way out is `signin-reset`. A key that no longer complies can always go. `404` `Passkey not found` |
| `POST /v1/users/{id}/signin-reset` | manager, admin | no body. Removes every passkey and the TOTP secret, sets a generated temporary password, sets `must_change_password` and opens `passkey_enroll_open` for a requirement that needs a key; open sessions end at the next request. `{"users": [row], "temporary_password", "passkeys_revoked", "totp_removed", "enroll_open"}`: the password is shown once. `400` for oneself, `403` for a manager on an admin or another reseller's user, `404` for an unknown user |

**Passkey registration failures.** A registration that does not verify or fit
is `400` `{"error": "Passkey registration failed", "reason": "<code>"}` (or an
`error` that says more, for `policy`, `metadata`, `limit` and `credential_id`),
recorded as the security event `passkey_registration_failed`:

| `reason` | Meaning |
|---|---|
| `malformed` | the credential or its fields cannot be read, or are too long |
| `challenge` | the challenge is unknown, spent, expired, for another purpose or user, or not the one the response answers |
| `type`, `origin`, `cross_origin`, `rp_id_hash` | the client data is of the wrong type, comes from an origin not in `origins`, from another origin's frame, or the key was made for another relying party |
| `user_presence`, `user_verification` | the key did not report the touch or the PIN/biometrics check |
| `algorithm`, `signature`, `backup_flags`, `user_handle`, `invalid` | the key uses another algorithm than ES256, EdDSA or RS256, a signature or flag does not verify, or the response is otherwise refused |
| `attestation` | the attestation statement does not verify |
| `credential_id` | the credential id is malformed, or the key is already registered |
| `policy` | the key verifies but does not meet the passkey policy |
| `metadata` | `device_bound` is set and FIDO's metadata is missing or out of date |
| `limit` | the user already holds 10 keys |

The passkey policy is one setting for the whole installation:

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/settings/passkeys` | admin | `{"passkeys": {"rp_id", "origins", "type", "models", "extra_aaguids"}, "in_use": {"passkeys", "users"}, "catalogue": {"security_keys": [{"key", "label"}], "builtin": [{"key", "label"}]}, "metadata": {"state", "serial", "next_update", "fetched_at", "entries", "overdue"}}`. `in_use` counts the keys registered and the users whose requirement needs one. `models` takes the catalogue's keys. `metadata.state` is `ok` (usable), `stale` (more than 7 days past `next_update`) or `missing`; `overdue` is true past `next_update` |
| `POST /v1/settings/passkeys/impact` | admin | the same body as `PUT`, changing nothing: `{"keys_total", "keys_noncompliant", "users": [{"id", "username", "role", "signin_requirement", "compliant_before", "compliant_after", "minimum", "outcome"}], "admins_able_after"}`. `keys_noncompliant` counts keys that comply now and would not; `users` the active users whose compliant keys change, the worst first; `admins_able_after` the active admins who could still sign in. `outcome`: `ok`, `needs_enrolment` (they can register a key themselves: with a passkey they still have, the authenticator app or the open enrolment) or `locked_out` (only a reset helps). `400` `{"error"}` for a value the setting refuses |
| `PUT /v1/settings/passkeys` | admin | any of `rp_id`, `origins`, `type`, `models`, `extra_aaguids` (`null` resets a field), and `confirm`. Answers the settings as `GET` does, plus the `impact`, and records `passkey_policy_changed` with the changed fields and the counts. `400` `{"error", "impact"}` and nothing saved when no active admin could sign in afterwards (`admins_able_after` 0). Changing `rp_id` while keys exist, or clearing it while users need keys, deletes every registered key and opens the enrolment for the users who need one: `400` `{"error", "confirm_required": true, "in_use": {"passkeys", "users"}, "impact"}` until `"confirm": true` is sent. Tightening the policy never opens a user's enrolment: those it locks out need `signin-reset` |

`rp_id` is the host name the browser shows, such as `eppitnic.example.it`,
without scheme, port or path; never an IP address (`localhost` is accepted for
development), and empty switches passkeys off. Each of `origins` is an `https`
origin (`http` only for `localhost`) equal to `rp_id` or below it, and at least
one is needed while `rp_id` is set. `type` is `any` (any authenticator,
attestation `none`) or `device_bound`: the registration asks for attestation
`direct`, which has to chain to a root FIDO's metadata lists for the key's
AAGUID; the key must not be able to sync; and its model must be among `models`
and `extra_aaguids` (none given: any attested model). `none` and self
attestation fail with `policy`, and `fido-u2f` never anchors (a U2F key has no
AAGUID and cannot verify the user). Without current metadata no such
registration starts (`400` `metadata`, also from `.../passkeys/options`), though
keys already registered keep working. A model the metadata marks `REVOKED`,
`USER_VERIFICATION_BYPASS`, `ATTESTATION_KEY_COMPROMISE`,
`USER_KEY_REMOTE_COMPROMISE` or `USER_KEY_PHYSICAL_COMPROMISE` stops counting
as `device_bound`, and the sessions begun with its keys end. `models` are the
catalogue's keys: `yubikey5`, `yubikey_bio`, `yubico_security_key`,
`google_titan`, `feitian`, `token2`, `swissbit`, `nitrokey`, `solokeys`,
`thales` and `windows_hello`. `extra_aaguids` are UUIDs, not the all-zero one.

### Resellers

Who contacts, domains and pending transfers belong to. Reseller 1,
"Registrar (self)", is the registrar itself: every admin belongs to it, and it
can be renamed but never deactivated. Resellers are deactivated, never
deleted — their users lose access at once (see "Authorization model"), their
data stays.

A reseller: `{"id", "name", "max_operations", "active", "creation_time",
"users", "domains", "contacts"}` — the last three are counts.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/resellers` | user | `{"resellers": [...]}` — an admin sees all, anyone else only their own |
| `GET /v1/resellers/{id}` | user | `{"reseller": {...}}` — an admin any, anyone else only their own (`403`); `404` for an unknown id |
| `POST /v1/resellers` | admin | body `{"name"*, "max_operations"?}`; `201`. `400` for a blank, over-long (64) or taken name (compared ignoring case), or a negative quota |
| `PATCH /v1/resellers/{id}` | admin | body: any of `name`, `max_operations`, `active`. `400` on the same validation, and for deactivating reseller 1; `404` for an unknown id |

### Reseller defaults and NS sets

What a reseller's new contacts and domains start from, kept on its
`resellers` row. Anyone in the reseller may read them; its managers (and any
admin) may change them. Every route answers with the whole current state:
`{"settings": {"countrycode", "entitytype", "techc", "dnsset", "nssets", "dnssec_algorithm", "dnssec_digesttype"}}`.

- `countrycode` — a two-letter ISO 3166-1 code (stored upper-case), or `""`.
- `entitytype` — the contact type (1 to 7) new contacts start from, or `null`
  for none (the contact form then uses 2, company). Send `null`, `""` or `0`
  to clear it.
- `dnssec_algorithm` — the DNSSEC algorithm (3, 5, 6, 7, 8, 10, 12, 13 or 14)
  a new DS record starts from, or `null` for none (the frontend then uses 13).
  Send `null`, `""` or `0` to clear it.
- `dnssec_digesttype` — the DS digest type (1 to 4) a new DS record starts
  from, or `null` for none (the frontend then uses 2). Cleared like the above.
- `techc` — a list of up to six contact handles; stored and returned, never
  applied: the registry has no technical contacts.
- `nssets` — named sets `{"name", "ns": [...]}`. A name is unique per reseller
  ignoring case, at most 64 characters, without a slash. `ns` holds 2 to 6
  hostnames, lower-cased; addresses are refused, since only a single domain's
  glue records ever need them.
- `dnsset` — the name of the set new domains start with, or `""`. It follows
  a renamed set and is cleared when its set is removed.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/resellers/{id}/settings` | its users, or admin | `404` for an unknown reseller |
| `PUT /v1/resellers/{id}/settings` | its managers, or admin | body: any of `countrycode`, `entitytype`, `techc`, `dnsset`, `dnssec_algorithm`, `dnssec_digesttype`; what is omitted stays. `dnsset` must name one of the reseller's sets |
| `POST /v1/resellers/{id}/nssets` | its managers, or admin | body `{"name", "ns"}`; `201` |
| `PUT /v1/resellers/{id}/nssets/{name}` | its managers, or admin | body `{"name"?, "ns"}` — replaces the nameservers, and renames the set if `name` differs. `404` for an unknown set |
| `DELETE /v1/resellers/{id}/nssets/{name}` | its managers, or admin | `404` for an unknown set |

### Domains

`domainToArray()` is the shape of the object **inside** the `domain`
envelope, used by every single-domain response below:
`{domain, status, registrant, admin, tech: [handles], ns: [{name, ipv4, ipv6}], ns_pending: [{name, ipv4, ipv6}], authinfo, dnssec: [{keytag, algorithm, digesttype, digest}], cr_date, ex_date}`.
`tech` is flattened to a plain string array. Each `ns` entry carries the
nameserver's glue addresses, `null` for one it has none of (always `null` for a
host outside the domain). `ns_pending` is the set the registry has not
validated yet, same shape, `[]` when none: for a domain in `inactive`/`dnsHold`
it is the only nameserver set (`ns` is empty); in `pendingUpdate` it is the new
set beside the validated `ns`. A PATCH on a `dnsHold` domain sends one `add`
with the whole configuration and no `rem`, so its `ns` must be the complete
configuration (a host without address fields keeps its pending glue). The registry has no administrative or technical contacts,
so `admin` and `tech` are empty for a domain it reports without them.

`admin` and `tech` in a request body are accepted everywhere and ignored:
nothing about them reaches the registry.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/domains` | user | local DB only. Query params: `registrant` (exact match), `active` (`1`\|`0`, default `1`), `age` (months since `ex_date`, filters to older-than). Returns **raw DB rows** `{domain, registrant, reseller_id, reseller_name, ex_date, status}` (`ex_date` is `YYYY-MM-DD`, `null` for a transfer-in row) — not `domainToArray()` — plus the transfer-in requests, with `" (transfer-in)"` appended to the domain name as a literal suffix (`" (transfer-in cancelled)"` for one that was cancelled; not a separate field — parse it out to tell them apart). A transfer-in row always has `status: []`, and `active`/`age` don't filter it; a transfer whose domain is already stored as active (its nameserver update is waiting for a retry) is listed as the domain only |
| `GET /v1/domains/expiring?days=30` | user | local DB, active domains whose `ex_date` is less than `days` away (already expired ones included), joined with the registrant contact; rows include `handle, org, name, email` alongside the domain columns. `ns`, `tech`, `status` and `dnssec` are decoded into real JSON (`ns`/`tech` as objects keyed by hostname/handle, so take `Object.keys()`; `status` as an array, `dnssec` as a list of `{keytag, algorithm, digesttype, digest}`) — **not** the flattened `domainToArray()` shape |
| `GET /v1/domains/autocomplete?term=&limit=10` | user | domain-name substring search (`LIKE %term%`), including transfer-ins (not those whose domain is already stored as active) with the same suffixes: `{"domains": ["a.it", "b.it (transfer-in)", "c.it (transfer-in cancelled)", ...]}` |
| `GET /v1/domains/export` | user | **not JSON** — `text/csv` (`;`-separated) with `Content-Disposition: attachment; filename="domains-export.csv"`, columns `Active;Domain;Auth-Info;Created;Expires;Registrant Handle;Registrant Org;Registrant Name;Registrant Email` |
| `GET /v1/domains/transfers?registrant=` | user | local transfer-in requests (the `transfers` table, not registry `pendingTransfer` state) — rows `{id, domain, status, techc, dns, reseller_id, name, email}` (`status` is `pending` or `cancelled`; `name` and `email` are the registrant's), `techc`/`dns` as arrays. Scoped by `transfers.reseller_id`, matching what `.../transfer/cancel` authorizes against |
| `GET /v1/domains/{name}` | user | registry-first: live EPP `fetch()`, in the `domainToArray()` shape with `"stale": false`. If the registry can't answer — `fetch()` fails **or** the session itself fails — falls back to the local DB row with `"stale": true`. `404`, without asking the registry, for a domain (or pending transfer-in) not of the caller's reseller; otherwise `404` only when neither source has it. Never `502` |
| `POST /v1/domains` | user | create-or-transfer-request in one call: `check()`s the name first, `create()`s if available, otherwise requests a `transfer()` if it's held elsewhere. Body: `domain*, registrant*, ns?: [{name,ipv4?,ipv6?}...], authinfo?` (`*` = required). `400` for a name that is not a valid .it domain. The `registrant` must be one of your reseller's contacts, else 403 (400 when it is not a stored contact and the name is held elsewhere), and the domain belongs to the registrant's reseller. A requested transfer is recorded exactly like `POST /v1/domains/{name}/transfer`: a pending `transfers` row (registrant, `ns`), never a domain of yours until it completes. Counts against the reseller's **daily quota** (see "Authorization model"), whether it registers or requests a transfer — `429` when exceeded. `201` + `domainToArray()` on success |
| `POST /v1/domains/import` | user | body `{"domains": ["a.it", ...]}` — pulls each from the registry into the local DB (idempotent reconciliation, not a create). Answers `{"results": {...}}`, keyed by domain name, each entry with `domain` and `registrant` (`"found"`/`"not found"`/`"held by another reseller"` — another reseller's domain is left untouched) and `contact_stored` and `domain_stored` (`"stored"`/`"not stored"`). A step never reached reads `"skipped"`, so the first non-`skipped` failure is where that import stopped. A failed step adds an `error` with the reason, e.g. a value longer than its database column. A domain the registry doesn't have is also removed locally |
| `PATCH /v1/domains/{name}` | user | partial update: `authinfo` set directly; `ns` and `dnssec` are **full-target-list diffs** — send the complete desired array and the server computes add/remove, don't send deltas. `ns` entries are a name, or `{"name": "ns1.example.it", "ipv4": "192.0.2.1", "ipv6": "2001:db8::1"}` for a nameserver with glue (`400` for a missing name, a malformed address, or an IPv4 in `ipv6` and vice versa); the older `{"name", "ip": "…" | ["…", "…"]}` is still accepted. An entry **without** `ipv4`, `ipv6` and `ip` leaves a nameserver already set as it is. An entry with any of them sets exactly the addresses given: `null` or `""` means none, so `{"name", "ipv4": "192.0.2.1", "ipv6": null}` drops an IPv6 and `"ip": []` or both fields `null` removes all glue. A nameserver whose addresses differ from what is set is sent as a removal (name only) plus an addition with the new addresses. Registry rules, checked before the registry is asked (`400`): glue only for a nameserver under the domain itself (`glue addresses are only allowed for nameservers under <domain>`), where it needs exactly one IPv4 and may add one IPv6 (`nameserver <ns> under <domain> needs an IPv4 address`). The same shape and rules apply to `ns` in `POST /v1/domains` and `POST /v1/domains/{name}/transfer`. `dnssec` entries are `{keytag, algorithm, digesttype, digest}`, all four non-empty (`400` otherwise, before the registry is asked); removals are sent before additions, so a key rollover fits the two-record limit. `400` too while the `dnssec` setting is off (see "DNSSEC"). Registrant changes are **not** accepted here — see the dedicated endpoint below |
| `POST /v1/domains/{name}/registrant` | user | registrant change (`Domain::updateRegistrant()`, a distinct EPP command from generic update). Body `{"registrant"*, "authinfo"?}`. The new registrant must be one of your reseller's contacts (403 otherwise), since this also moves the domain to that contact's reseller. Authinfo is rotated too (server-generated if omitted), since the registry requires it to change alongside the registrant |
| `POST /v1/domains/{name}/status` | user | body `{"state"*, "action"?: "add"\|"rem" (default "add")}` — the client status flags `clientDeleteProhibited`, `clientUpdateProhibited`, `clientTransferProhibited`, `clientHold`. Returns and stores the status the registry reports after the change |
| `DELETE /v1/domains/{name}?mode=now\|expiry\|date&date=YYYY-MM-DD` | user | ownership is checked first, so a domain you don't own is **403** in every mode. `mode=now` (default): immediate EPP delete + local deactivate. `mode=expiry`/`mode=date`: **does not touch the registry** — inserts a future-dated `tasks` row (`object='registry'`, `action='delete'`) for `eppitnic domain reap-deletions` to act on once due. The date is the domain's `ex_date` for `mode=expiry`; `mode=date` requires `date`, a real day in `YYYY-MM-DD` form that is today or later (`400` otherwise). A domain with a deletion already scheduled and active is **409**. Scheduling is recorded in `history` (`object='domains'`, `action='update'`, `scheduled_deletion: "scheduled"`) |
| `POST /v1/domains/{name}/restore` | user | undelete a `pendingDelete`/redemption-period domain, through the registry's `epp.server_deleted` endpoint (`409` while that is not configured) |
| `POST /v1/domains/{name}/owner` | admin | moves the domain to another reseller: duplicates the registrant contact into it, runs `updateRegistrant()`, then points `domains.reseller_id` and `registrant` at the new owner and copy. Body `{"reseller_id"*}`; `404` for an unknown reseller. Multi-step — can partially fail (e.g. registrant duplicated but registrant change rejected); show the `error` to the user |
| `POST /v1/domains/{name}/transfer` | user | request a transfer-in, storing the desired post-transfer registrant/ns locally (`transfers` table) for `PollProcessor` to apply once the registry confirms. Body `{"authinfo"*, "registrant"*, "ns"?: [...]}` (`400` without either required field). **Not** ownership-checked — you're claiming a domain you don't hold yet — but 403 if another reseller already holds it, or if `registrant` is not one of your reseller's contacts (404 when it is not a stored contact). The pending row belongs to the registrant's reseller; a cancelled row for the same name is replaced by it. Counts against the daily quota — `429` when exceeded |
| `POST /v1/domains/{name}/transfer/approve` | user | body `{"authinfo"?}`; ownership-checked against `domains` (you're answering a request for a domain you sponsor). Deletes the local `transfers` row |
| `POST /v1/domains/{name}/transfer/reject` | user | body `{"authinfo"?}`; ownership-checked against `domains`. Deletes the local `transfers` row |
| `POST /v1/domains/{name}/transfer/cancel` | user | body `{"authinfo"?}`; withdraws your own outgoing request. Ownership check accepts a pending `transfers` row, since the domain isn't in `domains` yet. Keeps the local `transfers` row as the record that you wanted the domain, with `status` `cancelled`: it no longer counts as a pending request, and no longer grants access to the domain or its poll messages |

### Contacts

`contactToArray()` is the shape of the object **inside** the `contact`
envelope: `{handle, status, linked, name, org, street, street2, street3, city, province, postalcode, countrycode, voice, fax, email, authinfo, consentforpublishing, nationalitycode, entitytype, regcode, schoolcode}`.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/contacts?active=1\|0` | user | local DB only, scoped rows `{handle, org, name, entitytype, status, linked, reseller_id, reseller_name}` — `status` is the list of flags as of the contact's last sync, e.g. `["ok", "linked"]` — plus `linked`, a boolean that is `true` when `status` says so, or when an active local domain or a pending transfer-in names the contact as registrant (inactive and `dnsHold` domains count; deleted ones do not). Not the full `contactToArray()` shape; fetch by handle for full detail |
| `GET /v1/contacts/{handle}` | user (+ownership/attachment check) | `linked` as above, in both the live and the stale answer. Registry-first, same contract as `GET /v1/domains/{name}`: live EPP `fetch()` with `"stale": false`, falling back to the local row with `"stale": true` when the registry can't answer. 403 if `Access::canAccessContact()` fails, 404 when neither source has it. Never `502` |
| `POST /v1/contacts` | user | body: any of the `contactToArray()` fields except `status` (server-managed), plus optional `handle` (16 random hex chars, registry-checked for uniqueness, if omitted) and `authinfo` (server-generated if omitted). `name` is required, and `org` must equal it: whichever of the two is given is copied into the other, and different values are a `400` ("name and org must be the same"). Every contact is a registrant, so `entitytype` must be 1 to 7 (`400` otherwise) and `nationalitycode` and `regcode` go with it. `fax` is accepted and ignored. `email`, when given, must be a valid address. `consentforpublishing` is a boolean (or `1`/`0`); the registry refuses withdrawing it for entity types other than 1 and 3. An admin may pass `reseller_id` to create it for that reseller (default: their own; `404` for an unknown one); anyone else passing it gets `403`. `201` + full contact on success |
| `PATCH /v1/contacts/{handle}` | user (+ownership/attachment check) | same field allow-list as create, partial update. `fax` is ignored; a `name` or `org` different from the stored one is a `400` ("name and org cannot be changed once the contact exists") |
| `DELETE /v1/contacts/{handle}` | user | the contact must belong to your reseller (`403` otherwise), checked before the registry is asked. `409` when the contact is the registrant of an active local domain or a pending transfer-in ("contact X is still the registrant of N domain(s): …"), also before the registry is asked. Otherwise it succeeds only if the registry itself allows the delete |

### Tasks

Three things share this table, told apart by `object`: rows a consumer owns
and executes (`object='pdns'`, applied by `eppitnic pdns sync`;
`object='registry'`, applied by `eppitnic domain reap-deletions`) and
human-facing scheduled notices (`object` NULL, e.g. "renew this domain") that
nothing automated reads.

A consumer-owned row also carries the result of its last run:
`executed_time`, `exit_code` (0 success, nonzero failure), `exit_message` —
all NULL until a consumer has acted on it. Only a success retires a row
(`active=0`); a failure records the result and stays active for the next run
to retry.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/tasks?page=&pageSize=&object=&action=&active=` | user | paginated (see "Pagination"); the raw queue including consumer-owned rows, an admin all of it, anyone else the tasks of their reseller's domains. `object=null`/`action=null` (literal string) filter to `IS NULL` |
| `GET /v1/domains/{name}/tasks` | user | scoped to the caller's reseller's domains (all, for an admin); only `active = 1` rows with `object IS NULL` (the human notices), and only `{id, date, domain, email, notice}` |
| `POST /v1/domains/{name}/tasks` | user | body `{"date"*, "notice"*, "email"?}`; `date` is a real day in `YYYY-MM-DD` form, today or later (`400` otherwise). 403 if the domain isn't the caller's reseller's (and caller isn't admin) |
| `DELETE /v1/tasks/{id}` | user | soft-delete (`active = 0`) — for anyone in the domain's reseller a notice or scheduled deletion (`object` null or `registry`), for an admin any task; 403 otherwise. Deactivating a scheduled deletion is recorded in `history` against its domain (`update`, `{"domain", "scheduled_deletion": "deactivated", "date", "task_id"}`) |

### Cronjobs

The five scheduled jobs' settings (see "Scheduled jobs" in
[INSTALL.md](INSTALL.md)). They go through the same
`Eppitnic\Service\CronjobSettings` every `config *-set` CLI command uses, so a
change made here or on the command line is validated and audited identically
(`history`, `object='cronjobs'`).

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/cronjobs` | admin | every job's current settings, as `{"jobs": {"pdns": {...}, "domain_sync": {...}, "domain_reap_deletions": {...}, "poll_process": {...}, "passkey_challenge_purge": {...}, "passkey_metadata": {...}, "keepalive": {...}}}`. Job-state fields (`cursor_id`, `last_run_at`) are included read-only, not part of what `PATCH` accepts |
| `PATCH /v1/cronjobs/{job}` | admin | body = partial field map for that job, e.g. `{"enabled": true, "frequency_minutes": 10}`; `null` resets a field to its default (`pdns`: disabled, `ttl` 3600, `delay_hours` 12, `frequency_minutes` 15, empty `apis`/`nameservers`; `domain_sync`: enabled, `batch_size` 25, `frequency_minutes` 5; `domain_reap_deletions`: enabled, `frequency_minutes` 15; `poll_process`: enabled, `frequency_minutes` 5; `passkey_challenge_purge`: enabled, `frequency_minutes` 1440; `passkey_metadata`: enabled, `frequency_minutes` 1440; `keepalive`: disabled). `400` with `{"error": "..."}` on an unknown job/field or a failed validator (the same message the CLI produces). Returns `{"job": "...", "settings": {...}}`, the job's full updated settings |

`keepalive` is stored as a bare boolean; this route wraps it as
`{"enabled": bool}` so every job has the same shape.

`pdns` carries `apis`, the PowerDNS servers `pdns sync` applies every change
to (see [POWERDNS.md](POWERDNS.md)): `[{"protocol": "http"|"https", "host": "...", "port": 8081, "api_key_set": true}]`.
The API key is never returned; `api_key_set` stands in for it, and `history`
records it redacted. `PATCH` takes the whole list, each entry with
`protocol`, `host`, `port` and optionally `api_key`: an entry without a key
keeps the stored key of the same protocol, host and port, and a new server
without one is `400`, as are duplicates and more than 6 servers.

`pdns` also carries `nameservers`, the DNS server hostnames PowerDNS answers
for: `["ns1.example.it", "ns2.example.it"]`, lower-case without a trailing
dot. Only domains whose nameservers include one of them are synced, and an
empty list syncs nothing. `PATCH` takes the whole list; an IP address, an
invalid hostname, a duplicate or more than 6 entries is `400`.

> **Warning:** turning `poll_process` off also stops the shared registry
> password from auto-rotating on a `passwdReminder`, alongside the queue
> drain and transfer reconciliation.

### About

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/about` | any user | `{"name", "version", "license", "copyright", "dependencies": [{"name", "version", "license"}]}`. `version` is the release version, `APP_VERSION`. `dependencies` lists every non-dev Composer package, transitive included, sorted by name; a package's `license` is its SPDX identifiers joined with ` OR ` |

### Regional settings

The `region` setting: the time zone every request and CLI command runs in,
and the locales passed to `setlocale()` for `LC_MONETARY` and `LC_TIME`.
It goes through `Service\RegionSettings`, shared with `config region-set`,
and changes are recorded in `history` (`object='region'`).

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/region` | admin | `{"region": {"timezone", "lc_monetary"}, "timezones": [...]}`. `timezones` lists every zone the server accepts, for a picker |
| `PATCH /v1/region` | admin | body: either field; what is omitted stays. `400` for an unknown time zone, a value that isn't a locale name (`C`, `POSIX`, `it_IT`, `it_IT.UTF-8`, …), a blank value or an unknown field. Returns `{"region": {...}}` |

### DNSSEC

The `dnssec` setting switches DS records on or off. Off (the default), login
does not announce the secDNS extensions and a domain create or update that
carries DS records is refused. It goes through `Service\DnssecSettings`,
shared with `config dnssec`, and changes are recorded in `history`
(`object='dnssec'`).

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/dnssec` | admin | `{"dnssec": {"active": false}}` |
| `PATCH /v1/dnssec` | admin | body `{"active": true\|false}`; `400` for anything else. Returns the same shape `GET` does |

### Email (SMTP)

The system-wide `smtp` setting that `poll process` and
`domain reap-deletions` send through. It goes through `Service\Notifier`, the
same class `config smtp-set` uses, so a change made here or on the command
line is validated and audited identically (`history`, `object='smtp'`). See
"Email notifications" in [INSTALL.md](INSTALL.md) for the full field list and
`recipient_mode`'s `system`/`user`/`both`/`none` routing.

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/smtp` | admin | `{"smtp": {...}, "message_types": [...]}`. `password` is never returned — `password_set` (bool) stands in for it, same allow-list pattern as `GET /v1/session/epp`. `message_types` is the full allow-list, for a picker |
| `PATCH /v1/smtp` | admin | body = partial field map, e.g. `{"enabled": true, "host": "smtp.example.it"}`; `null`/blank unsets an optional field. `400` on an unknown field, an unknown `message_types` entry, or a failed validator. Returns `{"smtp": {...}}` — the same `smtp` object `GET` returns, without the allow-list |
| `POST /v1/smtp/test` | admin | body = any `smtp` fields, merged over the **stored** config (a blank/omitted `password` reuses the stored one) — nothing is persisted or audited. `recipient` is required here regardless of `recipient_mode`, since there is no domain/owner context for a test. Works even while `smtp.enabled` is false. `200 {"sent": true}` on success, `502 {"error": ...}` on a send failure, `400` on an unknown field or a failed validator |

### History (audit trail)

| Method & path | Auth | Notes |
|---|---|---|
| `GET /v1/history` | user | the audit trail, newest first, as `{"history": [...], "total": n, "server_time"}`. **Scoped to what the caller may see**: an admin sees everything; everyone else sees their reseller's `domains` and `contacts` (whoever changed them), the registrations and transfer-ins its users requested (`action=request`), and their own `users` row — a manager also every user of the reseller and the reseller itself (`object=resellers`) — and never `security`. `total` counts what they may see, not what exists. `server_time` is the database clock read before the queries: pass it as the next `changed_since` without gaps (an overlap is possible, so upsert by `id`). Admins also get `outstanding`: how many `security` entries nobody has acknowledged. Filters: `object`, `object_id`, `action` (or several, comma-separated: `action=denied,secread`), `network`, `acknowledged` (`0` = not yet acknowledged, `1` = acknowledged, anything else is ignored), `since`, `until`, `changed_since` (`YYYY-MM-DD HH:MM:SS`, only entries acknowledged at or after it; `400` unless a real datetime), `limit` (max 1000, default 100), `offset`, and the cursors `before_id` / `after_id` (only entries with a lower / higher `id`: the next older batch, or what arrived since). `total` counts every match of the filters, whatever the cursors cut off. Filters narrow what is visible and never widen it, so `?object=security` as a non-admin is an empty list rather than a 403 |
| `GET /v1/history/{object}/{object_id}` | user | shorthand for `GET /v1/history?object=…&object_id=…`, scoped identically. Only `limit` is honoured here (default **500**, not 100) — no `offset`, no further filters. Answers `{"history": [...], "total": n}` without `outstanding` |
| `POST /v1/history/acknowledge` | admin | acknowledge several entries in one call: the ones a screen shows, as `{"ids": [...]}` (a non-empty list of integers; `400` otherwise, and every other field is ignored), or every entry still unacknowledged up to a moment, as `{"until": "YYYY-MM-DD HH:MM:SS"}` (a real datetime; `400` otherwise), compared to the entry's timestamp inclusively, and optionally `"actions": [...]` to acknowledge only entries of those actions (`400` unless a non-empty list of known ones). Pass the newest entry the user has loaded, so what arrived since stays outstanding rather than being acknowledged unread. The timestamp is only second-resolution, so pass `"until_id"` (that entry's `id`) as well for an exact cutoff — ids are monotonic, and when given it is used in place of `until`. `"object"` (default `security`, `400` unless one of the `object` enum) scopes it — `outstanding` only ever counts `security`, and this is the call that clears that badge. Already-acknowledged entries keep their stamp. Answers `{"acknowledged": n, "object", "until", "until_id", "acknowledged_time", "acknowledged_user_id", "outstanding"}`; by `ids`, without `object`, `until` and `until_id`. `acknowledged_time` and `acknowledged_user_id` are the stamp written to every entry it acknowledged |
| `POST /v1/history/{id}/acknowledge` | admin | mark one entry as reviewed. Records `acknowledged_time` and `acknowledged_user_id` rather than a flag, so a dismissed entry can be asked about later. Does not alter what the entry says happened. Re-acknowledging re-stamps it, so the last person to look at it is the one on record. Returns `{"acknowledged": true, "id": n, "entry": {…}}` with the entry as it now stands. `404` for an unknown id. Unscoped: an admin may acknowledge any entry, including a non-`security` one |

An entry is the table row as stored:

```json
{
  "id": "42", "timestamp": "2026-08-20 04:43:39", "user_id": "1",
  "object": "security", "object_id": "0", "action": "denied",
  "network": "203.0.113.0/24",
  "data": "{\"event\":\"login_failed\",\"username\":\"x\"}",
  "acknowledged_time": null, "acknowledged_user_id": null
}
```

`data` arrives as a **JSON string**, not a nested object — parse it a second
time — and the integer columns arrive as numeric strings. `user_id` is `null`
for events with no authenticated actor (a failed login at an unknown
username), and `object_id` is `0` where there is no object to point at.
What `data` holds depends on the event and is not a fixed schema; `security`
rows carry at least `event`, the client address (`ip`) and the request
`headers`, with `Authorization`, `Cookie` and `Proxy-Authorization` stored as
`[redacted]`.

The sign-in and passkey events are `security` rows with `data.event` one of:

| `event` | `action` | When |
|---|---|---|
| `login_succeeded` | `login` | a sign-in succeeded; `method` is `pwd`, `pwd+otp` or `passkey`, and a passkey sign-in names the `credential` |
| `login_failed` | `denied` | a wrong password or code, an unknown user, a failed passkey sign-in (`method` `passkey`, with the `reason`); counted for the rate limit |
| `login_requirement_pending` | `secread` | the credentials were right and a setup is due (`required`); not counted |
| `passkey_registered` | `secread` | a key was added (`credential`, `aaguid`, `attested`) |
| `passkey_registration_failed` | `secread` | a registration was refused (`reason`) |
| `passkey_removed` | `secread` | a key was removed, by the user, a manager or admin, or a sysop from the CLI |
| `passkey_counter_regression` | `secread` | a key's signature counter did not advance |
| `stepup_failed` | `denied` | a step-up proof was wrong (`method`, `reason`); counted |
| `signin_reset` | `secread` | a user's sign-in was reset (`target`, `passkeys_revoked`, `totp_removed`) |
| `signin_requirement_none` | `secread` | a requirement became `none` (`target`) |
| `signin_requirement_loosened` | `secread` | an admin loosened their own requirement (`from`, `to`) |
| `passkey_policy_changed` | `secread` | the passkey policy was changed (`changes`, and the impact's counts) |
| `passkeys_invalidated` | `secread` | a changed `rp_id` deleted the registered keys (`passkeys`, `users`) |

A change of the passkey policy is also a `history` row with `object`
`passkeys`, and a key added, renamed or removed one with `object` `users` for
the account.

## Limitations to design around

- No refresh-token flow — `GET /v1/users/renew-token` re-signs the claims of
  the token you already have; once it has expired, the user logs in again.
- Write endpoints don't return field-level validation errors —
  `requireFields()`/`maxLength()` return a single string naming the first
  problem found, not a per-field list.
- `POST /v1/contacts` only requires `name`, while the registry rejects a
  contact lacking any of `name`, `street`, `city`, `province`, `postalcode`,
  `countrycode`, `voice`, `email` (the list `contact create` enforces, in
  `src/Cli/Command/ContactCreateCommand.php`). The API leaves those to a
  registry round-trip that comes back `400`, so a form should require them
  client-side.
- `allowed_headers` is seeded with `X-Api-Key`, which nothing reads — a fixed
  API token goes in `Authorization` like a JWT.
