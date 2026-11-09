# PowerDNS sync

eppitnic can keep a zone in PowerDNS for every domain delegated to your own
DNS servers: it creates the zone, keeps its apex NS records in line with the
registry, and deletes it once the domain is gone. It talks to the PowerDNS
HTTP API, so it works the same from a server and from a Docker container.

## Prerequisites

- PowerDNS Authoritative Server with its HTTP API, on one or more servers.
- Network access from the eppitnic host (or container) to each API's port.
- Shell access to `bin/eppitnic` as the web server user, or an eppitnic admin
  account for the REST API.

## Set up PowerDNS sync

1. Enable the API on every PowerDNS server in `pdns.conf`, then restart
   PowerDNS:

   ```text
   api=yes
   api-key=<API_KEY>
   webserver=yes
   webserver-address=<LISTEN_ADDRESS>
   webserver-port=8081
   webserver-allow-from=<EPPITNIC_ADDRESS>
   default-soa-content=<PRIMARY_NS>. hostmaster.@ 0 10800 3600 604800 3600
   ```

   `default-soa-content` is the SOA that zones created through the API get;
   PowerDNS's own default names `a.misconfigured.dns.server.invalid`.

2. From the eppitnic host, check that the API answers:

   ```bash
   curl -s -H "X-API-Key: <API_KEY>" http://<PDNS_HOST>:8081/api/v1/servers/localhost
   ```

   The expected result is a JSON object with `"id": "localhost"`.
3. Add each PowerDNS API to eppitnic:

   ```bash
   bin/eppitnic config pdns-api add http://<PDNS_HOST>:8081 --api-key=<API_KEY>
   ```

4. List your own DNS servers — the hostnames domains are delegated to:

   ```bash
   bin/eppitnic config pdns-nameserver add ns1.example.it ns2.example.it
   ```

5. Turn the job on:

   ```bash
   bin/eppitnic config pdns-set enabled true
   ```

6. Preview what the next run would send:

   ```bash
   bin/eppitnic pdns sync --dry-run
   ```

   It prints each request (`GET`, `POST`, `PATCH` or `DELETE`, the URL and the
   JSON body) without sending it and without the API key.

`<API_KEY>` is the `api-key` from step 1, `<PRIMARY_NS>` your primary
nameserver's hostname, `<LISTEN_ADDRESS>` the address
PowerDNS's web server listens on, `<EPPITNIC_ADDRESS>` the address eppitnic's
requests arrive from, and `<PDNS_HOST>` the PowerDNS host. Use `https://` if
the API sits behind TLS; the certificate must be trusted by the eppitnic host.

Admins can make the same changes through `PATCH /v1/cronjobs/pdns` (see
"Cronjobs" in [API.md](API.md)). From then on the scheduler runs `pdns sync`
every `frequency_minutes`; it is also an ordinary command you can run by hand.

## Which domains are synced

A DNS change is queued only while `pdns sync` is enabled, at least one API is
listed, and the domain's nameservers include at least one hostname from the
`nameservers` list. An empty list syncs nothing.

| Event | Condition | Queued |
|---|---|---|
| Domain registered or restored | its nameservers include a listed server | create |
| Nameservers changed | the new ones include a listed server | update |
| Nameservers changed | only the old ones did (moved away) | delete |
| Domain deleted | its nameservers include a listed server | delete |

The latest intent wins: queuing a create or an update cancels a delete still
waiting for the same domain, so a domain that comes back keeps its zone, and
queuing a delete cancels a create or update that has not synced yet.

## Zones are prepared before the registry is asked

When a domain is registered, or its nameservers change, onto listed DNS
servers, eppitnic creates or updates the zone on every API *before* sending
the request to the registry. The registry's DNS check then finds the
nameservers answering for the zone, so the domain goes live without waiting
for the registry to check again.

- If PowerDNS fails, the registration or change still goes ahead. The
  response carries a warning, and the queued task retries on the next run.
- If the registry refuses the request, the preparation is taken back: a zone
  created for it is deleted, and an existing zone gets its previous NS
  records again.
- The queued task is still written, and re-applying it changes nothing.

## What a sync run does

For a create or an update, on every listed API:

1. Look the zone up.
2. Create it if it is missing, as a `Native` zone.
3. Replace its apex NS records with the domain's nameservers, at `ttl`.

Zone and NS names go to PowerDNS in punycode: the zone of `sesamstraße.it`
is `xn--sesamstrae-e4a.it.`, although eppitnic stores and shows the Unicode
name.

A delete waits until the task is `delay_hours` old, then deletes the zone on
every listed API. A zone that already exists, or is already gone, counts as
success, so every step is safe to repeat.

A task counts as applied only when every API accepted it. Otherwise it stays
queued, its `exit_message` names each failing server with PowerDNS's own
error, and the next run tries again. A run with any failure exits with code
`40`.

## Settings

| Field | Default | Meaning |
|---|---|---|
| `enabled` | `false` | whether changes are queued and synced |
| `apis` | `[]` | up to 6 PowerDNS APIs: `protocol`, `host`, `port`, `api_key` |
| `nameservers` | `[]` | up to 6 hostnames of your DNS servers; no IP addresses |
| `ttl` | `3600` | TTL of the NS records, in seconds |
| `delay_hours` | `12` | grace period before a zone is deleted |
| `frequency_minutes` | `15` | how often the scheduler runs `pdns sync` |

Change the lists with `config pdns-api` and `config pdns-nameserver` (each
takes `add`, `remove` and `clear`, and shows the list without arguments), and
the other fields with `config pdns-set <FIELD> <VALUE>`:

```bash
bin/eppitnic config pdns-api                                   # list, keys redacted
bin/eppitnic config pdns-api remove http://<PDNS_HOST>:8081
bin/eppitnic config pdns-nameserver remove ns2.example.it
bin/eppitnic config pdns-set ttl 86400
bin/eppitnic config pdns-set delay_hours 24
```

API keys are write-only: the CLI, the REST API and `history` only ever show
whether one is set. Adding a server again without `--api-key` keeps its key.

## Run it from Docker

Nothing changes inside a container: the `scheduler` container runs
`pdns sync` like any other job. Make sure the container can reach each API,
and that `webserver-allow-from` admits the address its requests arrive from.
Which address that is depends on Docker's networking and where PowerDNS runs;
the step 2 `curl`, run with `docker compose run --rm --entrypoint curl
eppitnic-cli …`, fails in the same way if it is not admitted.

## Troubleshooting

| Output | Meaning |
|---|---|
| `pdns sync is off` | `enabled` is `false`; nothing is queued or synced |
| `pdns sync is on but no PowerDNS API is configured` | add one with `config pdns-api add` |
| `no pending DNS-sync events` | nothing queued: check `nameservers` against the domains' nameservers |
| `not due yet (delay 12h)` | a delete is waiting out `delay_hours` |
| `SKIPPED: domain has no nameservers on record` | the local domain row has no NS; retried next run |
| `FAILED: <URL>: 401 …` | wrong API key for that server |
| `FAILED: <URL>: … connect …` | server unreachable: address, port, `webserver-allow-from`, or TLS trust |
| `FAILED: <URL>: 422 …` | PowerDNS refused the data; its message follows |

To see what is queued and how the last attempt went:

```sql
SELECT id, domain, action, created_time, executed_time, exit_code, exit_message
FROM tasks WHERE object = 'pdns' AND active = 1 ORDER BY id;
```

## Check a synced zone

After a run, ask PowerDNS for the zone:

```bash
curl -s -H "X-API-Key: <API_KEY>" http://<PDNS_HOST>:8081/api/v1/servers/localhost/zones/<DOMAIN>. | jq '.rrsets[] | select(.type == "NS")'
```

The NS records must match the domain's nameservers at the registry.
`<DOMAIN>` is the domain name; note the trailing dot.
