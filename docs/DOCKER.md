# Running eppitnic with Docker

`docker compose up -d` brings up one eppitnic instance: a `web` container
(nginx and php-fpm) serving the REST API on `127.0.0.1:8080`, a `scheduler`
sidecar running `eppitnic cron run` every minute, and an `eppitnic-cli`
service for one-shot commands. No database is included — the instance
connects to a MariaDB you provide. Ready-made images and an installation
bundle, including the web frontend, are provided to customers by the
publisher. To run the published image instead of building it, see
[DOCKER-IMAGE.md](DOCKER-IMAGE.md).

## Prerequisites

- Docker Engine with the **Compose v2** and **Buildx** plugins. On Ubuntu the
  `docker.io` package ships neither:

  ```bash
  apt install docker-compose-v2 docker-buildx
  ```

  Without Compose v2, `docker compose` is not a command at all (the older,
  hyphenated `docker-compose` is not supported). Without Buildx it still
  runs, with a `configured to build using Bake, but buildx isn't installed`
  warning.
- A MariaDB database and user for eppitnic, reachable from the containers
  (see "Connect to a database on the host").
- The EPP registry credentials, entered during setup.

## Start an instance

1. Build and start the stack from the repository root:

   ```bash
   docker compose up -d --build
   ```

2. Run the first-time setup, either interactively:

   ```bash
   docker compose run --rm eppitnic-cli setup
   ```

   or in a browser at `http://127.0.0.1:8080/setup.html`. Set the database
   host to `host.docker.internal` for a database on the Docker host. Setup
   writes `config.php` to `./data/config/`.
3. Confirm the API answers:

   ```bash
   curl http://127.0.0.1:8080/
   ```

   The expected output is `Hello, World!`.
4. Put a TLS-terminating reverse proxy in front (see "Put a reverse proxy in
   front") and set `trusted_proxies`.

The scheduler starts working once `config.php` exists. `cron run` decides
each minute which jobs are due from their `enabled` and `frequency_minutes`
settings — see "Scheduled jobs" in [INSTALL.md](INSTALL.md).

## Connect to a database on the host

Every service carries `extra_hosts: ["host.docker.internal:host-gateway"]`,
so `DB_HOST` `host.docker.internal` in `config.php` reaches the Docker host.
If the database is itself a compose service, use that service's name instead.

On Docker Desktop (macOS, Windows), connections to `host.docker.internal`
reach the host's MariaDB as coming from `localhost`, so a
`'<DB_USER>'@'localhost'` account with a password works as it is. Unix-socket
authentication does not: the container connects over TCP.

On Docker Engine (Linux), a container's loopback isn't the host's: traffic to
`host.docker.internal` arrives over the bridge interface with a non-loopback
source address, so a MariaDB bound to `127.0.0.1` refuses it. Change three
things on the host:

1. Set `bind-address` in MariaDB's config from `127.0.0.1` to `0.0.0.0`, then
   restart MariaDB. This listens on the bridge too; it does not make the
   database internet-reachable unless you forward the port.
2. Firewall it anyway. Find the bridge subnet with
   `docker network inspect eppitnic_default | grep Subnet`, allow port 3306
   only from that subnet, and confirm nothing allows it from the public
   interface.
3. Grant access from the bridge rather than `localhost`:

   ```sql
   GRANT ALL PRIVILEGES ON eppitnic.* TO '<DB_USER>'@'172.18.%.%' IDENTIFIED BY '<DB_PASSWORD>';
   FLUSH PRIVILEGES;
   ```

   Adjust `172.18.%.%` to the subnet from step 2. `<DB_USER>` and
   `<DB_PASSWORD>` are the credentials you give setup.

## Change a published port

If `127.0.0.1:8080` is already taken on the host, publish the web container
elsewhere in a `compose.override.yaml` next to `compose.yaml`. Compose merges
that file automatically (so does `./eppitnic`), and git ignores it:

```yaml
services:
  web:
    ports: !override
      - "127.0.0.1:8090:80"
```

Point the reverse proxy at the new port.

## Put a reverse proxy in front

`web` publishes plain HTTP on `127.0.0.1:8080` only; nothing in the image
terminates TLS or knows the real hostname. Port 8080 leaves port 80 free for
the proxy.

Use `config/nginx-proxy.sample` or `config/apache-proxy.sample`: each
terminates TLS at the real `server_name`/`ServerName` and proxies to
`127.0.0.1:8080`. If you change the published port in `compose.yaml`, change
the sample's target port to match. (`config/nginx-vhost.sample` and
`config/apache-vhost.sample` are for a bare-metal install without Docker.)

Then list the proxy in `trusted_proxies`, or `X-Forwarded-For` is ignored and
every client shares one login rate-limit bucket. Docker's NAT usually rewrites
traffic from the host to a published port so it arrives from the bridge
gateway, not `127.0.0.1`. Find the address that actually arrives:

```bash
curl -s -H "Authorization: Bearer <TOKEN>" http://127.0.0.1:8080/v1/trusted-proxies
```

The `peer` field is the address to add (`<TOKEN>` is an admin's JWT or API
token). Then add it:

```bash
docker compose run --rm eppitnic-cli config trusted-proxies add <PEER_ADDRESS>
```

See "Login rate limiting" in [INSTALL.md](INSTALL.md) for what the setting
controls.

## Run CLI commands

CLI commands run in a one-shot `eppitnic-cli` container rather than through
`docker exec`, because the ones most needed — `setup`, `doctor ownership`,
`doctor epp-password` — are needed exactly when the stack isn't healthy:

```bash
docker compose run --rm eppitnic-cli domain info example.it
```

The `./eppitnic` wrapper at the repository root does the same, with the
instance name first (`default` for `compose.yaml`'s single instance):

```bash
./eppitnic default domain info example.it
```

`docker compose run` allocates a TTY, which lets destructive commands ask for
confirmation. Unattended use needs both `-T` (no TTY) and `--yes`; either
alone still refuses:

```bash
./eppitnic -T default domain delete example.it --yes
```

## Run several instances on one host

`compose.multi.yaml.sample` runs two instances, `alpha` and `beta`: the same
three services each, published on `127.0.0.1:8081` and `127.0.0.1:8082`,
with their own `./data/alpha` and `./data/beta` folders. Each needs its own
scheduler, and each instance's `config.php` may point at the same database
server or a different one.

1. Copy `compose.multi.yaml.sample` to `compose.yaml`, renaming or adding
   instances as needed.
2. Start the stack with `docker compose up -d --build` and run setup once per
   instance, e.g. `./eppitnic alpha setup`.
3. Reach each instance's CLI by name:

   ```bash
   ./eppitnic alpha domain info example.it
   ```

Only the port and the `/data` folder differ between instances — `/data`
holds the instance's `config.php` and self-test notes, while its logs go to
`docker compose logs <service>`; `EPPITNIC_CONFIG_DIR` and `EPPITNIC_VAR_DIR`
never need setting.

## Rebuild or start over

To pick up a code or `Dockerfile` change, keeping the build cache:

```bash
docker compose down
docker compose up -d --build
```

For a clean rebuild that discards the built image and the build cache:

```bash
docker compose down --rmi all
docker compose build --no-cache
docker compose up -d
```

`--rmi all` removes every image the compose file references, including the
`php:8.5-fpm-alpine` and `composer:2` base images, so the next build pulls
them again. `--no-cache` is what clears BuildKit's layer cache; removing the
image alone doesn't.

Neither command touches `./data`. It is a bind mount, not a named volume, so
`config.php` and the self-test notes survive.

## Run Docker in rootless mode

Rootless mode is recommended; see the
[official documentation](https://docs.docker.com/engine/security/rootless/).
It needs Docker's own apt repository, and these packages instead of the ones
in "Prerequisites":

```bash
apt install uidmap docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Two differences from the default setup:

- `host.docker.internal` does not reach the host. Set the database host to a
  DNS name or an IP address.
- `./data` looks unowned from the host. `docker/entrypoint.sh` chowns it to
  the container's `www-data`, which rootless mode maps through `/etc/subuid`
  to a host id far from your own, so `ls -l` shows a bare number and reading
  the files needs `sudo`. That is expected. To reuse a `./data` copied from
  elsewhere, make it yours before the first start and the entrypoint takes
  over from there:

  ```bash
  chown -R $(id -u):$(id -g) ./data
  ```

## How the image is put together

- **One build owner.** Only `web` (`web-alpha` in the multi-instance sample)
  declares `build: .`; `scheduler` and `eppitnic-cli` use `image: eppitnic`
  without building. Several services building the same `Dockerfile` to the
  same tag race on the final export step and fail with
  `image "docker.io/library/eppitnic:latest": already exists`. Give any new
  service that uses the image `image: eppitnic` and no `build:`.
- **Extensions.** The image adds `pdo_mysql` and `intl` to
  `php:8.5-fpm-alpine`, with ICU's full locale data (`icu-data-full`): musl
  has no locale data of its own, so amounts are formatted per
  `region.lc_monetary` through ICU, which also encodes internationalised
  domain names. Everything else the code uses (`curl`,
  `dom`, `simplexml`, `mbstring`, `posix`, …) ships with the base image. `xsd/` is left out: nothing reads it
  at runtime.
- **Configuration.** As on bare metal (see "Configuration" in
  [INSTALL.md](INSTALL.md)), except `config.php` and the self-test notes live
  outside the image, in `EPPITNIC_CONFIG_DIR` and `EPPITNIC_VAR_DIR`
  (`/data/config` and `/data/var`, both under the one `./data:/data` bind
  mount). The rest of `config/` — `constants.php` and the schema files — stays
  in the image and is read on every request, so don't bind-mount over
  `config/`.
