# beantragt.ai Backend

[English](README.md) · Deutsch

beantragt.ai ist eine Plattform für kommunale Online-Anträge. Das Bürgerportal enthält Formulare für die Antragstellung. In der Sachbearbeitung verwaltet die Verwaltung Anträge, Fristen, Nachforderungen und Bescheide aus Vorlagen.

Dieses Repository enthält das Drupal-Backend, den Dokumentendienst Docgen und das Paket für den Eigenbetrieb mit Docker Compose. Die zugehörige Oberfläche liegt in [beantragt-ui-base](https://github.com/civicpatches/beantragt-ui-base). Das Paket startet beantragt.ai mit der Beispielkommune Musterstadt, ihren Dienststellen, Formularen und Testkonten.

beantragt.ai hat Version 1.0 noch nicht erreicht. Schnittstellen, Datenmodell und Konfiguration können sich zwischen Releases noch ändern.

[Betriebsanleitung](deploy/selfhost/README.de.md) · [Releases](https://github.com/civicpatches/beantragt/releases)

## Schnellstart

Der Start setzt Docker mit Compose ab Version 2.17 und Internetzugang für den Download der Images voraus. Der Quellcode eines [Releases](https://github.com/civicpatches/beantragt/releases) wird heruntergeladen und entpackt. Der Start erfolgt aus dem entpackten Quellbaum.

```sh
cd deploy/selfhost
cp .env.example .env
docker compose up -d --wait --wait-timeout 300
```

Nach dem Start sind folgende Adressen erreichbar.

- [Bürgerportal Musterstadt](http://localhost:13000/musterstadt/start)
- [Anmeldung für Bürgerinnen, Bürger und Verwaltung](http://localhost:13000/auth/login)
- [Mailpit mit den Einmalcodes für die Anmeldung](http://localhost:18025)

Die Anmeldung erfolgt ohne Passwort. Nach Eingabe der E-Mail-Adresse eines Testkontos wird der Einmalcode per E-Mail versandt und steht in Mailpit bereit. Im Standardbetrieb fängt Mailpit alle Nachrichten ab. Es gehen keine Nachrichten nach außen.

| Testkonto | Rolle |
| --- | --- |
| `buerger@example.invalid` | Bürgerin, stellt Anträge im Bürgerportal |
| `verwaltung@example.invalid` | Fachadministration für Formulare, Einstellungen und alle Dienststellen |
| `stadtkasse@example.invalid` | Sachbearbeitung Stadtkasse, zum Beispiel Hundesteuer |
| `citizen-services@example.invalid` | Sachbearbeitung Bürgerbüro mit Beispielvorgängen zum Bewohnerparkausweis |

Weitere Dienststellenkonten, der Build aus den Quellen, der Versand über einen eigenen Mailserver und der Betrieb hinter TLS sind in der [Betriebsanleitung](deploy/selfhost/README.de.md) beschrieben.

## Betrieb und Verantwortung

Über den Einsatz entscheidet der Betreiber. Er verantwortet Konfiguration, Updates, Datensicherung und die Verarbeitung personenbezogener Daten. Die Images auf GHCR sind Release-Schnappschüsse ohne laufende Wartung. Ein eigener Build ermöglicht die laufende Pflege der Images im Eigenbetrieb. Als weitere Betriebsform steht ein gehosteter Dienst zur Verfügung.

## Lizenz

Der Drupal-Code der Civic Patches GmbH steht unter GPL-2.0-or-later, der Dokumentendienst Docgen unter MIT. Einzelheiten stehen in [LICENSE](LICENSE), [NOTICE](NOTICE) und [TRADEMARKS.md](TRADEMARKS.md).
