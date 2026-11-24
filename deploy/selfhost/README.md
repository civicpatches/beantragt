# Self-hosting beantragt.ai

English · [Deutsch](README.de.md)

beantragt.ai has not reached version 1.0 yet. Interfaces, data model and configuration may still change between releases. The operator decides how the software is used. The operator is the controller under Articles 4(7) and 24 GDPR and is responsible for technical and organisational measures, the data protection impact assessment (Article 35 GDPR), data processing agreements, updates and backups. Civic Patches supplies the software for this package. Its role as a processor depends on the services provided, for example under a separate hosting agreement.

## First start

The open repository is cloned. Its `main` branch always contains the latest release, and `git clone --branch <tag>` clones a specific release, for example `--branch v0.7.2`. Without Git, the [source archive (ZIP)](https://github.com/civicpatches/beantragt/archive/refs/heads/main.zip) is downloaded and extracted; the folder is then named `beantragt-main`. Startup requires Docker with Compose 2.17 or later and internet access to download the images. On ARM64, startup requires Docker support for amd64 images because veraPDF is supplied as an amd64 image.

```sh
git clone https://github.com/civicpatches/beantragt.git
cd beantragt/deploy/selfhost
cp .env.example .env
docker compose up -d --wait --wait-timeout 300
```

The supplied `.env.example` already contains the release tag in `BEANTRAGT_VERSION`. Compose pulls Drupal, nginx, Docgen and the UI from GHCR. The images are immutable release snapshots without ongoing maintenance. Building from source allows the images to be maintained in a self-hosted installation. A hosted service is also available.

The test uses the [Musterstadt citizen portal](http://localhost:13000/musterstadt/start), the [sign-in page](http://localhost:13000/auth/login) and [Mailpit](http://localhost:18025). The email address for the account is entered on the sign-in page. The new message for that address is opened in Mailpit and its one-time code is entered. All browser URLs use the same hostname, `localhost`.

| Role | Email address |
| --- | --- |
| Citizen | `buerger@example.invalid` |
| Municipal administrator, with the `workspace_inviter` role for invitations to Musterstadt | `verwaltung@example.invalid` |
| City treasury | `stadtkasse@example.invalid` |
| Citizen services | `citizen-services@example.invalid` |
| Central administration | `central-administration@example.invalid` |
| Personnel office | `personnel-office@example.invalid` |
| Building department | `building-department@example.invalid` |
| Mayor's office | `mayors-office@example.invalid` |
| Environment office | `environment-office@example.invalid` |
| Central procurement | `procurement-office@example.invalid` |

After sign-in with the citizen account, a dog is registered for dog tax. If the portal requires email confirmation, the confirmation is opened from Mailpit. After sign-in with the treasury account, the case is looked up by its reference number. The municipal administrator can open the forms list. Citizen services has example parking permit cases and notice templates.

Mailpit and the other published ports are reachable from loopback only. ClamAV downloads its signatures at first start. Uploads follow the scan policy until it is ready. Drupal initializes an empty database and keeps configuration, files and generated secrets for subsequent starts. The configuration directory is outside the nginx web root.

### Mail through the operator's own server

The supplied `.env.example` enables Mailpit through `COMPOSE_PROFILES`. That line is also copied into an existing `.env`. A running Mailpit instance is stopped with `docker compose --profile mailpit stop mailpit` before switching.

In `.env`, `BEANTRAGT_MAIL_MODE=smtp` enables SMTP mode. `COMPOSE_PROFILES` follows below the SMTP block. `SMTP_HOST` and `SMTP_USER` contain the hostname and username. `SMTP_FROM` contains a sender address accepted by the provider. msmtp uses STARTTLS with certificate verification for this login. `SMTP_PORT` defaults to `587`. `SMTP_EHLO_HOST` is optional and defaults to the sender's domain.

The separate file outside the source tree contains only the password. `SMTP_PASSWORD_FILE` contains the file path. Read access is restricted to the Drupal user in the container (UID 1000), for example with `sudo chown 1000 <file> && sudo chmod 400 <file>`. Compose without Swarm does not apply permissions from the secret definition. Compose mounts the file as a read-only Docker secret. The optional `BEANTRAGT_MAIL_REDIRECT_RECIPIENT` setting directs all messages to a single test mailbox.

The stack is started with `docker compose up -d --wait --wait-timeout 300`. Mailpit does not start in SMTP mode. The delivery test starts with a one-time code request at `/auth/login` for the address of a reachable test mailbox. The code received there is used to sign in. If a recipient redirect is configured, the check takes place in the configured destination mailbox.

### Startup without example data

`BEANTRAGT_EXAMPLE_DATA=0` is set in `.env` before the first start. An initialized database keeps its contents. To start with an empty database after testing, any data still needed is backed up and the volumes of the disposable trial installation are removed with `docker compose down --volumes`. The option is then set to `0` and the installation is started again.

For a blank installation, a one-time Drupal administration link is generated from the local shell. The URL is adjusted if the Drupal port has changed.

```sh
docker compose exec drupal sh /opt/selfhost/start-drupal.sh \
  drush user:login --no-browser --uri=http://localhost:18080
```

### Source build

The open backend and UI sources of the same release are placed side by side. The default expects a folder named `ui` next to the backend folder. `UI_SOURCE_DIR` in `.env` is relative to `deploy/selfhost`. The build requires BuildKit and access to package registries.

```sh
docker compose -f compose.yaml -f compose.build.yaml up -d --build --wait --wait-timeout 300
```

The override builds Drupal, nginx, Docgen and the UI locally. nginx copies its web root from the Drupal build. The derived UI uses the `casework` build scope.

### Docker networks

On hosts with many Docker networks, automatic subnet allocation can fail with `all predefined address pools have been fully subnetted`. Before Compose starts, explicit subnets for all three networks are set in `compose.override.yaml` next to `compose.yaml`. The example ranges are replaced with available ranges that do not overlap each other, existing Docker networks, or host and VPN routes. The command under “First start” loads the override automatically. The build command names its files with `-f` and takes the additional argument `-f compose.override.yaml`.

```yaml
networks:
  default:
    ipam:
      config:
        - subnet: 10.246.242.0/28
  docgen-api:
    ipam:
      config:
        - subnet: 10.246.242.16/28
  docgen-engine:
    ipam:
      config:
        - subnet: 10.246.242.32/28
```

## Source code under section 13 AGPL

`BEANTRAGT_VERSION` in `.env` is set to the installed release tag, for example `v0.3.0`. Compose passes the tag and `NUXT_PUBLIC_SOURCE_URL` to Nuxt at startup. The example configuration uses `https://github.com/civicpatches/beantragt-ui-base/tree/{version}`. Nuxt replaces `{version}` with the tag and displays the **Source code (AGPL)** link. The template requires a tag in `vX.Y.Z` format. An empty or invalid tag hides the templated link.

When a modified version is run over a network, its complete corresponding source is made available free of charge at the operator's own public URL. `NUXT_PUBLIC_SOURCE_URL` is set to that repository or download URL. The citizen portal is opened while signed out to verify that **Source code (AGPL)** is visible and points to the version being served. [AGPL Section 13](https://www.gnu.org/licenses/agpl-3.0.html)

## Health checks and shutdown

```sh
curl --fail --silent --show-error http://localhost:18080/api/v1/auth/status
docker compose exec drupal curl --fail --silent --show-error http://docgen:3000/health
docker compose down
```

The document service health check expects `status: ok` and reachable instances of Gotenberg and veraPDF. On first start, Compose generates database and service passwords, the Drupal salt, the application encryption key and a client token for this installation. Drupal receives the internal service URL and token. The document service receives the token hash and uses a separate password for Gotenberg.

`docker compose down` keeps the database, files and generated secrets. A complete backup includes the associated volumes. `docker compose down --volumes` removes the project's data and secrets.

## Updating

An update uses the source tree of the new release. The project name `beantragt-selfhost` is set in `compose.yaml`, so the new source tree takes over the database, files and secrets from the existing volumes. `.env` and an existing `compose.override.yaml` are copied from the previous directory, and `BEANTRAGT_VERSION` is set to the new release tag. A backup of the volumes before the update allows a return to the previous version.

```sh
cd deploy/selfhost
docker compose pull
docker compose up -d --wait --wait-timeout 300
docker compose exec drupal sh /opt/selfhost/start-drupal.sh \
  drush updatedb --yes
```

`drush updatedb` runs the database updates of the modules and clears the caches afterwards. Calling it through `start-drupal.sh` loads the container secrets. A process started directly with `docker compose exec drupal drush` does not receive them. For a source build, all three commands use the same `-f` options as the start. Notes on individual releases are in the operating guide of the respective tag.

## FIM data contracts for external case systems

Transferring an application to an external case system requires FIM data contracts in a separate directory. The directory is mounted read-only in the Drupal container outside its web root. The Drupal setting `$settings['beantragt_fim_contract_directory']` or the environment variable `BEANTRAGT_FIM_CONTRACT_DIRECTORY` is set to the absolute path inside the container. The Drupal setting takes precedence over the environment variable.

Example `compose.override.yaml`

```yaml
services:
  drupal:
    environment:
      BEANTRAGT_FIM_CONTRACT_DIRECTORY: /opt/fim-contracts
    volumes:
      - ./fim-contracts:/opt/fim-contracts:ro
```

For each registered contract identifier and version, `schema.json` and `manifest.json` are placed under `fim/<identifier>/<version>/`. The manifest contains `key` (with the `fim:` prefix), `version`, `sourceUri` (the schema's `$id`) and `sha256` (the digest of the exact schema file). The source URI and digest must match the values pinned in the backend. The schema is obtained from its publisher and the applicable terms are checked. Transfers requiring an unavailable contract fail with `integration.contract_unavailable`. FIM form search in the casework interface uses its own catalogue connection.

## Application data protection update

- Pending transfers to external case systems are processed before the update. Encryption changes the transfer payload hashes.
- Applications from forms with recognised IBAN or tax ID fields become protected. Staff need the `view protected application data` permission to edit these data or see them unredacted.
- Older database backups can contain plaintext IBANs. These backups are rotated after the update.
- The update resets existing citizen profile session bindings once.
- If the Drupal status report lists applications awaiting encryption, the affected submitted form revisions are restored before `docker compose exec drupal sh /opt/selfhost/start-drupal.sh drush beantragt:retry-data-protection` is run.

## TLS and sessions in production

In this configuration, a reverse proxy terminates TLS. The proxy redirects HTTP to HTTPS, preserves the public hostname and sets `X-Forwarded-Proto: https` itself. Incoming forwarded headers are overwritten at the proxy. The Compose ports stay bound to loopback or on a restricted network. The HTTP URLs in “First start” use the local loopback interface.

`UI_PUBLIC_URL` and `DRUPAL_PUBLIC_URL` in `.env` are set to the external HTTPS base URLs, and `DRUPAL_TRUSTED_HOST` is set to the hostname of the Drupal base URL. Both URLs can use the same origin when it serves both applications. Nuxt continues to reach Drupal at `http://nginx:8080` and takes the protocol for session requests from `DRUPAL_PUBLIC_URL`.

`DRUPAL_REVERSE_PROXY_ADDRESSES` contains the comma-separated, stable source addresses of the trusted peers that nginx passes to Drupal as `REMOTE_ADDR`. For session requests this is the **Nuxt container** address. For direct Drupal requests behind the reverse proxy it may also be the proxy or Docker gateway address. If needed, a Compose override assigns Nuxt a fixed address on a dedicated network. Only those exact addresses are trusted. Drupal then recognises the forwarded HTTPS scheme and marks its session cookie `Secure`. After startup, an OTP sign-in through the public HTTPS URL is used to check the attribute in the `Set-Cookie` header.

The installer uses `UI_PUBLIC_URL` on first startup. On an existing installation, `beantragt_group.settings:frontend_base_url` is also updated, and the links in confirmation and invitation emails are checked.

## Privacy before publishing a form

### 1. Privacy notice and voluntary profile

An accessible privacy notice is published for each tenant and its link is checked in the citizen form. The notice lists the controller and privacy contact, purposes and legal bases, recipients, retention, data subject rights and the supervisory authority. The applicable basis for each public service is confirmed with the responsible authority under Articles 6(1)(c) or 6(1)(e) and 6(3) GDPR, together with the relevant legislation (legal obligation or public task). The required checkbox solely acknowledges the privacy notice and is labelled “I acknowledge the privacy notice.” Saving a profile for future applications requires a separate, voluntary, unchecked opt-in. The review verifies that users can apply without saving a profile. The procedure for withdrawing consent and deleting the profile is explained.

### 2. Retention periods for confirmed applications

- **Per form.** The form is opened for editing in Drupal at `/admin/content/form-schemas`. In the **Form Schema** field, a top-level property such as `"retention": {"dataRetentionDays": 180}` is added to the existing JSON object and saved. Removing the property restores inheritance of the retention period.
- **Per organisational unit.** The organisational unit is opened for editing in Drupal at `/admin/group`. The period is set in `field_retention_days`, labelled **Aufbewahrungsfrist in Tagen** (retention period in days). The account needs group edit access and `administer beantragt retention policies`. An empty field inherits from the nearest parent organisational unit with a value, then from the tenant, then from the global default.
- The form period takes precedence. A value of `0` disables automatic deletion for the effective rule. With synthetic applications, `docker compose exec drupal sh /opt/selfhost/start-drupal.sh drush beantragt:retention --dry-run --json` is used to check which cases are due for deletion. Drupal cron handles automatic deletion.

### 3. Attachments without AI services

The open build excludes the AI analysis provider, RAG service, MCP server and chat assistant. The default stack stores attachments that meet the upload rules and scans them according to the local scan policy. It skips AI analysis as unavailable (`skipped / analysis_unavailable`). The default stack sends no application or attachment data to AI providers.

### 4. Data minimisation for each form

- Each field and attachment is mapped to a necessary step and its legal basis. Fields with no specific purpose are removed. Only essential information is required.
- The review covers conditional fields, free text, attachment types, the scope of evidence and any special categories of personal data. Structured fields or redacted evidence are preferred where the procedure permits.
- **FIM draft approval.** The responsible organisational unit is selected and the **FIM catalogue** (`/admin/fim`) is opened. The form is found through search and imported as a draft. The review covers field wording, required fields, mappings to external case systems, retention and which fields are actually stored, exported and transferred. Required changes are recorded.
- In **Forms**, **Publish** is selected for the draft. If a field requires classification and has no `privacy.kind`, `privacy_kind_required` blocks publication. The appropriate privacy classification is assigned in the dialog and saved. Publication is then retried. The **Published** status is verified and the form is opened in the citizen portal for a visual check.
- The form version, approval date, responsible team and review result are recorded. The review is repeated when the form or connected case system changes.

### 5. Record of processing activities template

The template is completed for each public service and processing activity. It includes the relevant categories of data subjects and data, legal bases, contacts, technical and organisational measures, and any international transfers. The template is maintained with the responsible team.

| Activity and purpose | Data flow and storage | Recipients and access | Retention and deletion |
| --- | --- | --- | --- |
| Application receipt, email confirmation and case processing | Form and attachments → Nuxt → Drupal, database and private file storage | Responsible office, hosting and mail providers under contract | Deadlines for unconfirmed and confirmed applications, attachments and backups, including start dates and deletion procedures |
| Voluntary profile for future applications | Consent and selected contact data → Drupal profile | Applicant and technical operators as required | Deletion on withdrawal or under the defined retention schedule |
| Optional attachment analysis or case handover | File or required case data → configured destination, response → Drupal | Configured analysis provider or case system, access roles and processors | Periods for copies, results, transfer logs and backups |

[GDPR, Articles 6, 13 and 30](https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=CELEX:32016R0679)

The open build uses the catalogue and form entry. FormSchema can retain chat-input settings as imported data. The open interface ignores those settings. The attachment upload is available. The HTTP adapters for an analysis service are in the source code; activation, the analysis endpoint and the result display of the supporting-document pre-check are part of Pro.
