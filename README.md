# Requirements

1. PHP 8.2.0 or newer (verified against both the codebase's own syntax and
   every Composer dependency's declared PHP requirement; the code itself is
   8.0-compatible, but `web-auth/webauthn-lib` — which provides passkeys —
   binds the minimum at `>=8.2`)
2. [Composer](https://getcomposer.org/), to install the third-party
   dependencies declared in `composer.json` — run `composer install` before
   first use
3. CURL, XML, PDO, intl, mbstring and ctype modules for PHP
4. either a MariaDB/MySQL or another database

None of the above if you'd rather run the whole thing in Docker instead — see
[DOCKER.md](docs/DOCKER.md).


# ** Warning **

This installation is a breaking change. It will remove the accounting table
from an existing installation in case of upgrade. Please read the Upgrading
documentation for more details!


# Why using such a library instead of a client with direct access?

Here is a list of a few very simple reasons:

1. Saving messages from the polling queue
2. Automatic password rotation
3. Automatic DNS server updates (post transfer-in)
4. Usage history (who did what and when?)
5. User management (multiple distinct operators, resellers)
6. DB driven session keepalive


# Documentation

Detailed instructions can be found in:

* [INSTALL.md](docs/INSTALL.md)
* [UPGRADING.md](docs/UPGRADING.md)
* [DOCKER.md](docs/DOCKER.md)
* [DOCKER-IMAGE.md](docs/DOCKER-IMAGE.md)

For more specific information see:

* [COOKBOOK.md](docs/COOKBOOK.md)
* [POWERDNS.md](docs/POWERDNS.md)
* [REMOTE-AUTH.md](docs/REMOTE-AUTH.md)
* [TESTING.md](docs/TESTING.md)
* [API.md](docs/API.md)
