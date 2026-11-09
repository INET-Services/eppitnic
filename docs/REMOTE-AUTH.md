# Remote authentication

eppitnic can leave the login to the web server in front of it. The web server
authenticates the person (Basic auth, LDAP, OpenID Connect, …) and passes on a
username; eppitnic maps it to one of its own users, and the frontend opens
without a login screen.

## Requirements

- Serve the frontend and the API **from the same origin**, and put the web
  server's login in front of **both**. The browser then sends the web
  server's credentials or session cookie with every API call.
- Every person who logs in needs an active eppitnic user with the same name.
  Usernames are at most 32 characters, so pass a plain name (`jdoe`), not
  `jdoe@EXAMPLE.COM`.
- An admin account, to turn the feature on.

## How it works

- **Who you are** comes from the web server. It has to match the `username`
  of an existing, active eppitnic user; case does not matter. Users are not
  created automatically, and role, reseller and quota still come from the
  local user.
- **Two factors:** eppitnic does not ask for its own TOTP code or a passkey,
  and a user's sign-in requirement does not apply to a remote sign-in: the web
  server authenticates. If you need MFA, enforce it at the web server. The
  requirement still holds for the password fallback below.
- **Bearer tokens take precedence.** A request with `Authorization: Bearer …`
  is authenticated by that token, so scripts using API tokens keep working.
  Remote authentication applies to every other request.
- **Password login keeps working** as a fallback, so turning the feature on
  cannot lock anyone out.
- **The frontend** checks `GET /v1/users/me` once at startup. When the web
  server has signed someone in, the frontend skips its login page and hides
  logout, password change and TOTP setup, since the web server manages those.

The web server passes the username in one of two ways:

| Mode | eppitnic reads | Use when |
|---|---|---|
| server variable (default) | `REMOTE_USER` (or `REDIRECT_REMOTE_USER`), set by the web server itself | PHP runs on the same Apache or nginx that handles the login |
| header | a request header you name, e.g. `X-Remote-User` | the login happens on a reverse proxy in front of another web server, e.g. the Docker container |

In header mode, eppitnic believes the header only when the connection comes
directly from an address in `trusted_proxies`; from any other address the
header is ignored.

## Configure Apache

### PHP on the same server (`REMOTE_USER`)

Add a login to the virtual host from `config/apache-vhost.sample`:

```apache
<Location />
    AuthType        Basic
    AuthName        "eppitnic"
    AuthUserFile    /etc/apache2/eppitnic.htpasswd
    Require         valid-user
</Location>
```

Any authentication module that sets `REMOTE_USER` works the same way. For
OpenID Connect with `mod_auth_openidc`, for example, use
`AuthType openid-connect` with `OIDCRemoteUserClaim preferred_username`.

### Reverse proxy (header)

Add a login and the header to the proxy from `config/apache-proxy.sample`
(Apache 2.4.10 or later):

```apache
<Location />
    AuthType        Basic
    AuthName        "eppitnic"
    AuthUserFile    /etc/apache2/eppitnic.htpasswd
    Require         valid-user

    # `set` replaces any X-Remote-User the client sent
    RequestHeader   set X-Remote-User "expr=%{REMOTE_USER}"
</Location>
```

Then set header mode and trust the proxy (see "Turn it on").

## Configure nginx

### PHP-FPM on the same server (`REMOTE_USER`)

Add a login to the server block from `config/nginx-vhost.sample`, and pass
the user on to PHP:

```nginx
server {
    # ... the rest of nginx-vhost.sample stays as it is
    auth_basic              "eppitnic";
    auth_basic_user_file    /etc/nginx/eppitnic.htpasswd;

    location ~ \.php$ {
        # ... the sample's existing fastcgi lines stay as they are
        fastcgi_param   REMOTE_USER $remote_user;
    }
}
```

With SSO through `auth_request` (for example oauth2-proxy), pass the variable
you set with `auth_request_set` instead of `$remote_user`.

### Reverse proxy (header)

In the proxy from `config/nginx-proxy.sample`:

```nginx
location / {
    auth_basic              "eppitnic";
    auth_basic_user_file    /etc/nginx/eppitnic.htpasswd;

    # replaces any X-Remote-User the client sent
    proxy_set_header        X-Remote-User $remote_user;
    proxy_pass              http://127.0.0.1:8080;
}
```

Then set header mode and trust the proxy (see "Turn it on").

## Turn it on

From the command line:

```bash
bin/eppitnic config remote-auth-set enabled true
bin/eppitnic config remote-auth-set header X-Remote-User    # header mode only
bin/eppitnic config remote-auth-set header                  # back to REMOTE_USER
bin/eppitnic config remote-auth-set enabled false
```

An admin can do the same through `GET`/`PATCH /v1/remote-auth` (see
[API.md](API.md)). Every change is recorded in `history`
(`object='remote_auth'`).

In header mode, also add the proxy's address, as eppitnic sees it, to
`trusted_proxies`. Behind Docker this is usually the gateway of the
container's network, e.g. `172.18.0.1`. `GET /v1/trusted-proxies` shows it as
`peer`, the address your own request arrived from:

```bash
bin/eppitnic config trusted-proxies add <PROXY_ADDRESS>
```

## Check that it works

Ask the API who you are, through the web server's login:

```bash
curl -u <USERNAME> https://<YOUR_HOSTNAME>/v1/users/me
```

With a browser-based SSO login, open the same URL in the browser instead. The
answer carries `"remote_auth": true` and your local user's claims. If it
doesn't:

| The API answers | Meaning |
|---|---|
| `401` | No username arrived. Check that the login covers the path, the `fastcgi_param` (nginx), or, in header mode, that the proxy's address is in `trusted_proxies`. |
| `403` `Remote user '…' has no active account` | The web server signed the person in, but no eppitnic user has that name. The frontend shows this above its login form. |
| `403` `Your account is deactivated` | The eppitnic user exists but is deactivated. |
| `400` on `/v1/users/renew-token` | Expected: there is no token to renew under remote authentication. |

## Security considerations

- Anyone who gets past the web server's login and has a local user with the
  same name *is* that user. The web server's login is the only gate.
- In server-variable mode, clients cannot set `REMOTE_USER`. A path the web
  server does not protect has no user, and the request is refused as
  unauthenticated.

> **Warning:** in header mode, the proxy must always **overwrite** the
> header, as both examples do, and `trusted_proxies` must never include
> networks where ordinary clients live. Otherwise a client can name any user.
