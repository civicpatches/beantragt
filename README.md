# beantragt.ai backend

English · [Deutsch](README.de.md)

beantragt.ai is a platform for municipal online applications. The citizen portal contains forms for submitting applications. Municipal staff use the casework interface to manage applications, deadlines, requests for additional information and decision notices generated from templates.

This repository contains the Drupal backend, the Docgen document service and the self-hosting package for Docker Compose. The corresponding interface is available in [beantragt-ui-base](https://github.com/civicpatches/beantragt-ui-base). The package starts beantragt.ai with the example municipality of Musterstadt, its departments, forms and test accounts.

beantragt.ai has not reached version 1.0 yet. Interfaces, data model and configuration may still change between releases.

[Self-hosting guide](deploy/selfhost/README.md) · [Releases](https://github.com/civicpatches/beantragt/releases)

## Quick start

Startup requires Docker with Compose 2.17 or later and internet access to download the images. The source code of a [release](https://github.com/civicpatches/beantragt/releases) is downloaded and extracted. The package is started from the extracted source tree.

```sh
cd deploy/selfhost
cp .env.example .env
docker compose up -d --wait --wait-timeout 300
```

The following addresses are available after startup.

- [Musterstadt citizen portal](http://localhost:13000/musterstadt/start)
- [Sign-in for citizens and municipal staff](http://localhost:13000/auth/login)
- [Mailpit with one-time sign-in codes](http://localhost:18025)

Sign-in is passwordless. Once the email address of a test account has been entered, the one-time code is sent by email and is available in Mailpit. In the default setup, Mailpit intercepts all messages. No messages are sent externally.

| Test account | Role |
| --- | --- |
| `buerger@example.invalid` | Citizen submitting applications through the citizen portal |
| `verwaltung@example.invalid` | Municipal administrator for forms, settings and all departments |
| `stadtkasse@example.invalid` | City treasury caseworker, for example for dog tax |
| `citizen-services@example.invalid` | Citizen services caseworker with example residential parking permit cases |

Additional department accounts, building from source, delivery via a separate mail server and operation behind TLS are described in the [self-hosting guide](deploy/selfhost/README.md).

## Operation and responsibility

The operator decides how the software is used and is responsible for configuration, updates, backups and the processing of personal data. The images on GHCR are release snapshots without ongoing maintenance. Building from source allows the images to be maintained in a self-hosted installation. A hosted service is also available.

## License

The Drupal code from Civic Patches GmbH is licensed under GPL-2.0-or-later, and the Docgen document service is licensed under MIT. Details are available in [LICENSE](LICENSE), [NOTICE](NOTICE) and [TRADEMARKS.md](TRADEMARKS.md).
