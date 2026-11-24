# beantragt_maintenance

Platform maintenance mode with a break-glass recovery path. Lets operators
take the whole platform into maintenance (branded outage page in the Nuxt
frontend) and recover access even when normal staff login is unavailable.

## How it works

The platform is in maintenance when EITHER flag is on:

- `beantragt_maintenance.platform_maintenance` (state, this module)
- `system.maintenance_mode` (Drupal core state)

The toggle endpoint always sets BOTH flags together, so Drupal core and the
frontend agree. The Nuxt frontend polls the public status endpoint and steers
all citizen and staff routes to `/maintenance` while it reports `true`.

## Endpoints

| Route | Access | Purpose |
|-------|--------|---------|
| `GET /api/platform/maintenance` | public, no-store | `{"maintenance": true\|false}` |
| `PATCH /api/platform/maintenance` | uid 1 or permission `administer beantragt maintenance` + `X-CSRF-Token`; OR `Authorization: Bearer <recoveryToken>` | body exactly `{"maintenance": true\|false}`; a recovery token may ONLY send `false` |
| `POST /api/platform/maintenance/recovery/request-code` | anonymous via trusted Nuxt proxy | body `{"email": "..."}`; generic response regardless of account existence |
| `POST /api/platform/maintenance/recovery/verify-code` | anonymous via trusted Nuxt proxy | body `{"email": "...", "code": "123456"}`; returns a one-time recovery token (5 min TTL) |

All routes carry `_maintenance_access: TRUE` and stay reachable during core
maintenance mode; a route subscriber additionally keeps `/session/token`
available so the CSRF-protected admin toggle works while the site is down.

## Turning maintenance on and off

Normal path (staff with the permission, e.g. via the frontend or curl):

```bash
# on
curl -X PATCH https://<host>/api/platform/maintenance \
  -H "X-CSRF-Token: $(curl -s https://<host>/session/token)" \
  -H "Content-Type: application/json" --cookie "<session>" \
  -d '{"maintenance": true}'
# off: same call with false
```

Server-side fallback (SSH, no HTTP needed):

```bash
drush state:set beantragt_maintenance.platform_maintenance 1   # on
drush state:set beantragt_maintenance.platform_maintenance 0   # off
drush state:set system.maintenance_mode 0                      # if core flag was set too
```

## Break-glass recovery (locked out during maintenance)

For operators who cannot log in normally while the platform is down. On the
frontend `/maintenance` page, "Zugang für Betreiber":

1. The operator email is entered. Codes are only issued to ACTIVE accounts holding
   Drupal core's `access site in maintenance mode` permission. The response is
   always generic (anti-enumeration).
2. The mailed 6-digit code is entered (10 min validity, bcrypt-hashed at rest,
   timing-safe verification).
3. On success the frontend receives a one-time recovery token (5 min TTL,
   only its SHA-256 stored) and immediately PATCHes `{"maintenance": false}`.
   The token cannot ENABLE maintenance and is permission-rechecked and
   invalidated on use. Toggling maintenance also invalidates all outstanding
   codes and tokens.

## Security properties

- Anonymous recovery endpoints require the trusted Nuxt service header
  (`X-Beantragt-Service-Authorization`, fail-closed when unconfigured) and a
  32-hex per-client rate-limit identifier (`X-Beantragt-Rate-Limit-Identifier`).
- Request limits apply per proxy identifier and per proxy identifier plus email
  hash. A global ceiling of 60 requests per address and hour also applies.
  Verification lockout and backoff apply per proxy identifier plus email hash.
  Bearer token consumption has its own flood limit.
- A live code is retained until first use or expiry. Repeat requests and wrong
  guesses leave it intact. Recovery mail is sent after the response in the
  account's preferred language. Dummy bcrypt work covers ineligible requests
  and missing-code verification. Codes and tokens live in separate expirable
  key-value collections.
- Deployment assumption: the per-client rate-limit identifier is derived from
  the TCP socket in Nuxt. If a reverse proxy is ever placed IN FRONT of Nuxt,
  per-client flood buckets degrade to per-proxy. Either Nuxt remains the socket
  terminator or identifier derivation is switched to a trusted-proxy variant.

## Permissions

- `administer beantragt maintenance` (restricted): toggle maintenance.
- Core `access site in maintenance mode`: eligibility for break-glass codes.
