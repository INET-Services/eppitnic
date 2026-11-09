# Testing

Two levels of tests: the PHPUnit suite runs offline and proves the right EPP
XML is generated and parsed, and `selftest` runs against nic.it's public test
registry and proves the registry still accepts it.

## Run the test suite

1. Install the dependencies, including the development ones:

   ```bash
   composer install
   ```

2. Run the suite from the repository root:

   ```bash
   vendor/bin/phpunit
   ```

   `composer test` does the same. With a local MariaDB reachable (see below)
   the run ends with a plain `OK`.

The suite needs no MariaDB and no network: it runs on
`Config::loadForTesting()` and a fake transport. Some tests open an in-memory
SQLite database (`ext-pdo_sqlite`, bundled with PHP by default) to exercise
real `Config::set()` writes.

### Run part of the suite

Narrow a run the usual PHPUnit ways — by suite (`unit`, `wire`, `http`,
`cli`), directory or test name:

```bash
vendor/bin/phpunit --testsuite wire
vendor/bin/phpunit tests/Cli
vendor/bin/phpunit --filter <TEST_NAME>
```

### Tests that need a local MariaDB

`Setup\InstallerTest`, `Setup\SchemaInstallerTest` and
`Setup\PasskeySchemaTest` (the 7.1 upgrade and its re-run, the fresh install,
binary credential ids and the foreign keys) also drive a real, disposable
database: `mysql:host=localhost` as the current shell user with no
password (local socket authentication), with privileges to create and drop a
database. Without one reachable, those tests skip themselves rather than
fail, and the run ends with `OK, but some tests were skipped!`.

### Captured registry responses

`Wire\ResponseParsingTest` parses real registry answers stored in
`tests/fixtures/responses/`. They were captured with
`tests/capture-responses.php`, a maintainer tool that anonymises responses out
of a populated installation's database; ordinary test runs don't need it.

### Passkey tests

The passkey tests need neither an authenticator nor a network.
`tests/Support/SoftAuthenticator.php` is a software authenticator that answers
the options WebAuthn produces, and every field a verifier checks can be forged,
so a test breaks one thing at a time. `tests/Support/TestPki.php` builds
throw-away certificate authorities, attestation certificates and signed FIDO
metadata BLOBs, so no test depends on the real FIDO root.

`AttestationRecordedTest` replays registrations recorded from real devices
(packed, TPM and fido-u2f) in `tests/fixtures/attestation/`. They come from the
test suite of `web-auth/webauthn-framework`; its `NOTICE` file carries their
licence.

## Test against the live test registry

`selftest` registers, reads back, changes and deletes real contacts and a
real domain at the public test registry, checking each answer against what
was sent.

Point the installation at the test registry first, with a test account in the
`epp` setting (see "Switch between the test and production registry" in
[INSTALL.md](INSTALL.md)):

```bash
bin/eppitnic config epp-server test
```

A quick run needs nothing else prepared:

```bash
bin/eppitnic selftest run
```

A full run also proves the nameserver round-trip, and needs a zone you
control:

```bash
bin/eppitnic selftest run --yes \
    --domain=<SELFTEST_DOMAIN> \
    --ns=<NS1>:<NS2>:<NS3>
```

`<SELFTEST_DOMAIN>` is a second-level `.it` name you are willing to register
and delete, e.g. `my-selftest-1.it`. `<NS1>` and `<NS2>` must answer
authoritatively for it; `<NS3>` is only the swap target of the update and
need not exist.

Either prints one line per operation, and nothing else unless something
fails:

```text
endpoint  https://epp.pubtest.nic.it
run       TJCF9M

Contact lifecycle
  + contact create                 STTJCF9MD1           0.44s  authinfo K7#mQx2_pLdR9wZt
  ...
Domain lifecycle
  + domain create                  st-tjcf9m-1.it       0.62s  authinfo 3Rp_xW8@qN4zVbLm, ns ns1.example.it ns2.example.it
  ~ contact delete                 STTJCF9MR2           0.23s  EPP code '2305': still linked to the deleted domain (cleared by `selftest reap` once purged)
  ...
21 steps: 20 ok, 0 failed, 1 deferred, 0 skipped, in 5.6s
```

A quick run is 21 steps (8 contact lifecycle, 13 domain), 1 of them deferred:
the registrant the domain carried when deleted. The default `--ns` has three
nameservers; the update is skipped only when `--ns` gives fewer than three.
With `--domain` it is 23 steps, adding the two verification pauses.

Every contact the run creates is a registrant (entity type 2) whose `org`
equals its `name`; the domain has a registrant and nameservers, nothing else.

`--verbose` adds the full request and response on failure and records every
command in `transactions`/`responses`. `--json`/`--jsonl` emit one object per
step. The exit code is the verdict: `0` all well, `51` something failed, `50`
refused to start.

**Nameservers.** By default the domain uses `example.it`'s reserved
nameservers, which answer nothing. nic.it doesn't report a nameserver until
delegation validates, so a quick run's nameservers come back empty; that is
expected. A green quick run therefore does not prove the nameserver
round-trip — only a `--domain` run does. It pauses 10 seconds after each
delegation change so the registry's checks can finish, shown as its own step.
A used name can't be reused until it is purged 30 days later, so keep a small
pool (`-1`, `-2`, …) for repeated runs.

**Production is unreachable by design.** Before a session opens, the endpoint
is checked against an allowlist of test endpoints, with no override flag (see
`Eppitnic\Selftest\Guard`). An unconfigured deployment gets exit code `50`.

### Reap deferred selftest leftovers

nic.it keeps a contact linked to a domain until that domain is purged, 30
days after the delete (through redemptionPeriod, then pendingDelete). So the
contacts that were *on the domain when it was deleted* can't be removed the
same day; those attempts report `~ deferred`, not failed. Everything else —
a leftover domain, a registrant swapped off before the deletion, contacts from a
run that failed before creating a domain — is deleted on sight.

Leftovers are written to `var/selftest/<RUN>.json`:

```bash
bin/eppitnic selftest reap --list      # what is outstanding
bin/eppitnic selftest reap             # delete what is ready
```

`reap` deletes everything not held by a domain immediately. `--min-age`
applies only to held contacts and counts from **their domain's deletion**,
not from the run; `--min-age=0` attempts them anyway. Anything the registry
still refuses stays on file for next time:

```text
nothing to reap
nothing to reap yet: 3 contact(s) are held by a domain the registry has not
purged; try again in 6 day(s), or --min-age=0 to attempt them now
```

Every name a run creates shares one base-36 timestamp (`STTJCF9MR1`,
`st-tjcf9m-1.it`). That keeps runs from colliding and lets `reap` attribute an
object to its run by name alone.

### Test a transfer-in

A transfer-in needs a domain someone else holds and the authinfo its current
registrar issued, so it is a separate run:

```bash
bin/eppitnic selftest run --transfer=<DOMAIN> --authinfo=<AUTHINFO>
```

It confirms the name is registered, requests the transfer and reads the
status back. It doesn't clean up after itself: withdraw the request with
`bin/eppitnic domain transfer cancel <DOMAIN>`.
