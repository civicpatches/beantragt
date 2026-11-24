# beantragt.ai im Eigenbetrieb

[English](README.md) · Deutsch

beantragt.ai hat Version 1.0 noch nicht erreicht. Schnittstellen, Datenmodell und Konfiguration können sich zwischen Releases noch ändern. Über den Einsatz entscheidet der Betreiber. Er ist Verantwortlicher nach Art. 4 Nr. 7 und Art. 24 DSGVO. Er ist für technische und organisatorische Maßnahmen (TOMs), die Datenschutz-Folgenabschätzung (Art. 35 DSGVO), Auftragsverarbeitungsverträge, Updates und Backups zuständig. Civic Patches stellt für dieses Paket die Software bereit. Die Rolle als Auftragsverarbeiter richtet sich nach der tatsächlichen Leistung, etwa einem separaten Betriebsvertrag.

## Erster Start

Das offene Repository wird geklont. Der Zweig `main` enthält immer das neueste Release, `git clone --branch <Tag>` klont ein bestimmtes Release, zum Beispiel `--branch v0.7.2`. Ohne Git wird das [Quellarchiv (ZIP)](https://github.com/civicpatches/beantragt/archive/refs/heads/main.zip) heruntergeladen und entpackt, der Ordner heißt dann `beantragt-main`. Der Start setzt Docker mit Compose ab 2.17 und Internetzugriff für den Image-Download voraus. Auf ARM64 setzt der Start die Unterstützung von amd64-Images durch Docker voraus, da veraPDF als amd64-Image vorliegt.

```sh
git clone https://github.com/civicpatches/beantragt.git
cd beantragt/deploy/selfhost
cp .env.example .env
docker compose up -d --wait --wait-timeout 300
```

In der mitgelieferten `.env.example` steht der Release-Tag bereits in `BEANTRAGT_VERSION`. Compose lädt Drupal, nginx, Docgen und die Oberfläche von GHCR. Die Images sind unveränderliche Release-Schnappschüsse ohne laufende Wartung. Ein eigener Build ermöglicht die laufende Pflege der Images im Eigenbetrieb. Als weitere Betriebsform steht ein gehosteter Dienst zur Verfügung.

Für den Test werden das [Bürgerportal Musterstadt](http://localhost:13000/musterstadt/start), die [Anmeldung](http://localhost:13000/auth/login) und [Mailpit](http://localhost:18025) geöffnet. In der Anmeldung wird die gewünschte E-Mail-Adresse eingetragen. Aus der neuen Nachricht an diese Adresse in Mailpit wird der Einmalcode übernommen. Alle Browser-URLs verwenden denselben Hostnamen `localhost`.

| Rolle | E-Mail-Adresse |
| --- | --- |
| Bürgerin | `buerger@example.invalid` |
| Fachadministration, mit der Rolle `workspace_inviter` für Einladungen nach Musterstadt | `verwaltung@example.invalid` |
| Stadtkasse | `stadtkasse@example.invalid` |
| Bürgerbüro | `citizen-services@example.invalid` |
| Hauptamt | `central-administration@example.invalid` |
| Personalamt | `personnel-office@example.invalid` |
| Bauamt | `building-department@example.invalid` |
| Bürgermeisterbüro | `mayors-office@example.invalid` |
| Umweltamt | `environment-office@example.invalid` |
| Zentrale Beschaffung | `procurement-office@example.invalid` |

Mit dem Konto der Bürgerin wird in Musterstadt ein Hund zur Hundesteuer angemeldet. Wenn das Portal eine Bestätigung der E-Mail-Adresse verlangt, wird die Bestätigung aus Mailpit geöffnet. Nach der Anmeldung mit dem Stadtkasse-Konto wird der Vorgang anhand des Aktenzeichens gesucht. Das Verwaltungskonto kann die Formularübersicht öffnen. Im Bürgerbüro liegen Beispielvorgänge zum Bewohnerparkausweis und passende Bescheidvorlagen.

Mailpit und die anderen veröffentlichten Ports sind nur auf Loopback erreichbar. ClamAV lädt seine Signaturen beim ersten Start. Uploads folgen bis dahin der Scan-Policy. Drupal richtet eine leere Datenbank ein und speichert Konfiguration, Dateien und generierte Geheimnisse für spätere Starts. Das Konfigurationsverzeichnis liegt außerhalb des nginx-Webroots.

### E-Mail über einen eigenen Mailserver

Die mitgelieferte `.env.example` aktiviert Mailpit über `COMPOSE_PROFILES`. Diese Zeile wird auch in eine vorhandene `.env` übernommen. Eine laufende Mailpit-Instanz wird vor der Umstellung mit `docker compose --profile mailpit stop mailpit` angehalten.

In `.env` aktiviert `BEANTRAGT_MAIL_MODE=smtp` den SMTP-Modus. `COMPOSE_PROFILES` folgt unterhalb des SMTP-Blocks. `SMTP_HOST` und `SMTP_USER` enthalten den Hostnamen und den Benutzernamen. `SMTP_FROM` enthält eine vom Anbieter akzeptierte Absenderadresse. msmtp verwendet bei dieser Anmeldung STARTTLS mit Zertifikatsprüfung. `SMTP_PORT` ist standardmäßig `587`. `SMTP_EHLO_HOST` ist optional und entspricht standardmäßig der Absenderdomain.

Die separate Datei außerhalb des Quellbaums enthält ausschließlich das Passwort. `SMTP_PASSWORD_FILE` enthält den Dateipfad. Der Lesezugriff wird auf den Drupal-Benutzer im Container (UID 1000) beschränkt, zum Beispiel mit `sudo chown 1000 <file> && sudo chmod 400 <file>`. Compose ohne Swarm übernimmt keine Rechte aus der Secret-Definition. Compose bindet die Datei schreibgeschützt als Docker Secret ein. Die optionale Einstellung `BEANTRAGT_MAIL_REDIRECT_RECIPIENT` leitet alle Nachrichten an ein einziges Testpostfach um.

Der Stack wird mit `docker compose up -d --wait --wait-timeout 300` gestartet. Im SMTP-Modus startet Mailpit nicht. Der Zustellungstest beginnt mit der Anforderung eines Einmalcodes unter `/auth/login` für die Adresse eines erreichbaren Testpostfachs. Die Anmeldung erfolgt mit dem dort empfangenen Code. Bei aktiver Empfängerumleitung erfolgt die Prüfung im konfigurierten Zielpostfach.

### Start ohne Beispieldaten

Vor dem ersten Start wird `BEANTRAGT_EXAMPLE_DATA=0` in `.env` gesetzt. Eine bereits eingerichtete Datenbank behält ihre Inhalte. Für einen Start mit leerer Datenbank nach einem Test werden benötigte Daten gesichert und die Volumes der entbehrlichen Testinstallation mit `docker compose down --volumes` gelöscht. Danach wird der Wert auf `0` gesetzt und die Installation erneut gestartet.

Bei einer leeren Installation wird der einmalige Link für die Drupal-Verwaltung über die lokale Shell erzeugt. Bei einem abweichenden Drupal-Port wird die URL entsprechend angepasst.

```sh
docker compose exec drupal sh /opt/selfhost/start-drupal.sh \
  drush user:login --no-browser --uri=http://localhost:18080
```

### Build aus den Quellen

Die offenen Backend- und UI-Quellen derselben Release-Version werden nebeneinander abgelegt. Der Standardwert erwartet den UI-Ordner `ui` neben dem Backend-Ordner. `UI_SOURCE_DIR` in `.env` wird relativ zu `deploy/selfhost` ausgewertet. Der Build benötigt BuildKit und Zugriff auf die Paketquellen.

```sh
docker compose -f compose.yaml -f compose.build.yaml up -d --build --wait --wait-timeout 300
```

Der Override baut Drupal, nginx, Docgen und die Oberfläche lokal. nginx übernimmt den Webroot aus dem Drupal-Build. Die abgeleitete Oberfläche wird mit dem Build-Scope `casework` gebaut.

### Docker-Netze

Auf Hosts mit vielen Docker-Netzen kann die automatische Subnetzvergabe mit `all predefined address pools have been fully subnetted` scheitern. Vor dem Start werden für alle drei Netze explizite Subnetze in `compose.override.yaml` neben `compose.yaml` festgelegt. Die Beispielwerte werden durch freie Bereiche ersetzt, die sich weder untereinander noch mit vorhandenen Docker-Netzen oder Host- und VPN-Routen überschneiden. Der Startbefehl unter „Erster Start“ lädt die Override-Datei automatisch. Beim Bauen aus den Quellen nennt der Befehl die Dateien mit `-f` und wird um `-f compose.override.yaml` ergänzt.

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

## Quellcode nach § 13 AGPL

`BEANTRAGT_VERSION` in `.env` wird auf den Tag der installierten Fassung gesetzt, etwa `v0.3.0`. Compose übergibt den Tag und `NUXT_PUBLIC_SOURCE_URL` beim Start an Nuxt. Die Beispielkonfiguration verwendet `https://github.com/civicpatches/beantragt-ui-base/tree/{version}`. Nuxt ersetzt `{version}` durch den Tag und zeigt den Link **Quellcode (AGPL)** an. Die Vorlage benötigt einen Tag im Format `vX.Y.Z`. Bei leerem oder ungültigem Tag blendet die UI den Link zur Vorlage aus.

Für eine geänderte Netzwerkfassung wird der vollständige zugehörige Quellcode der eingesetzten Fassung unter einer eigenen öffentlichen URL kostenlos bereitgestellt. Dazu wird `NUXT_PUBLIC_SOURCE_URL` auf die passende Repository- oder Download-URL gesetzt. Im Bürgerportal wird ohne Anmeldung geprüft, dass **Quellcode (AGPL)** sichtbar ist und zur tatsächlich ausgelieferten Fassung führt. [AGPL § 13](https://www.gnu.org/licenses/agpl-3.0.html)

## Betriebsprüfung und Stopp

```sh
curl --fail --silent --show-error http://localhost:18080/api/v1/auth/status
docker compose exec drupal curl --fail --silent --show-error http://docgen:3000/health
docker compose down
```

Der Dokumentendienst erwartet im Gesundheitscheck `status: ok` und erreichbare Instanzen von Gotenberg und veraPDF. Beim Erststart erzeugt Compose Datenbank- und Dienstpasswörter, den Drupal-Salt, den Schlüssel zur Antragsverschlüsselung und den Client-Token für diese Installation. Drupal erhält die interne Adresse und den Token. Der Dokumentendienst erhält den Hash des Tokens und verwendet ein eigenes Gotenberg-Passwort.

`docker compose down` erhält Datenbank, Dateien und erzeugte Geheimnisse. Eine vollständige Sicherung umfasst die zugehörigen Volumes. `docker compose down --volumes` löscht die projektgebundenen Daten und Geheimnisse.

## Aktualisieren

Ein Update verwendet den Quellbaum des neuen Releases. Der Projektname `beantragt-selfhost` steht in `compose.yaml`, deshalb übernimmt der neue Quellbaum Datenbank, Dateien und Geheimnisse aus den bestehenden Volumes. `.env` und eine vorhandene `compose.override.yaml` werden aus dem bisherigen Verzeichnis übernommen, `BEANTRAGT_VERSION` wird auf den neuen Release-Tag gesetzt. Eine Sicherung der Volumes vor dem Update erlaubt die Rückkehr zur vorherigen Version.

```sh
cd deploy/selfhost
docker compose pull
docker compose up -d --wait --wait-timeout 300
docker compose exec drupal sh /opt/selfhost/start-drupal.sh \
  drush updatedb --yes
```

`drush updatedb` führt die Datenbank-Updates der Module aus und leert danach die Caches. Der Aufruf über `start-drupal.sh` lädt die Geheimnisse des Containers. Ein direkt mit `docker compose exec drupal drush` gestarteter Prozess erhält sie nicht. Beim Build aus den Quellen gelten für alle drei Befehle dieselben `-f`-Angaben wie beim Start. Hinweise zu einzelnen Releases stehen in der Betriebsanleitung des jeweiligen Tags.

## FIM-Verträge für Fachverfahren

Für die Übertragung an ein Fachverfahren hinterlegt der Betreiber die benötigten FIM-Fachdatenverträge in einem separaten Verzeichnis. Das Verzeichnis wird schreibgeschützt und außerhalb des Webroots in den Drupal-Container eingebunden. Als Wert für `$settings['beantragt_fim_contract_directory']` in Drupal oder die Umgebungsvariable `BEANTRAGT_FIM_CONTRACT_DIRECTORY` dient der absolute Pfad im Container. Die Drupal-Einstellung hat Vorrang vor der Umgebungsvariable.

Beispiel für `compose.override.yaml`

```yaml
services:
  drupal:
    environment:
      BEANTRAGT_FIM_CONTRACT_DIRECTORY: /opt/fim-contracts
    volumes:
      - ./fim-contracts:/opt/fim-contracts:ro
```

Je registrierter Vertragskennung und Version erwartet Drupal unter `fim/<Kennung>/<Version>/` eine `schema.json` und eine `manifest.json`. Das Manifest enthält `key` (mit Präfix `fim:`), `version`, `sourceUri` (die `$id` des Schemas) und `sha256` (Prüfsumme der exakten Schemadatei). Quell-URI und Prüfsumme müssen mit den im Backend hinterlegten Werten übereinstimmen. Das Schema wird beim zuständigen Herausgeber bezogen. Dort werden auch die Nutzungsrechte geprüft. Fehlt der registrierte Vertrag, wird die Übertragung mit `integration.contract_unavailable` verweigert. Die FIM-Formularsuche der Sachbearbeitung verwendet einen eigenen Katalogzugang.

## Update für den Schutz von Antragsdaten

- Wartende Übertragungen an Fachverfahren werden vor dem Update abgearbeitet. Durch die Verschlüsselung ändern sich die Prüfsummen der Übertragungsdaten.
- Anträge aus Formularen mit erkannten IBAN- oder Steuer-ID-Feldern werden geschützt. Sachbearbeitende benötigen die Berechtigung `view protected application data`, um diese Daten ungeschwärzt zu sehen oder zu bearbeiten.
- Ältere Datenbanksicherungen können IBANs im Klartext enthalten. Nach dem Update werden diese Sicherungen rotiert.
- Das Update setzt bestehende Sitzungsbindungen von Bürgerprofilen einmalig zurück.
- Meldet der Drupal-Statusbericht ausstehende Verschlüsselungen, werden die betroffenen eingereichten Formularrevisionen wiederhergestellt. Anschließend wird `docker compose exec drupal sh /opt/selfhost/start-drupal.sh drush beantragt:retry-data-protection` ausgeführt.

## TLS und Sitzungen im Produktivbetrieb

In dieser Konfiguration terminiert ein Reverse Proxy TLS. Der Proxy leitet HTTP auf HTTPS um, erhält den öffentlichen Hostnamen und setzt `X-Forwarded-Proto: https` selbst. Eingehende Forwarded-Header werden am Proxy überschrieben. Die Compose-Ports bleiben auf Loopback oder in einem abgeschotteten Netz. Die HTTP-Adressen im Abschnitt „Erster Start“ sind lokale Loopback-Adressen.

`UI_PUBLIC_URL` und `DRUPAL_PUBLIC_URL` werden in `.env` auf die externen HTTPS-Basis-URLs gesetzt, `DRUPAL_TRUSTED_HOST` auf den Hostnamen der Drupal-Basis-URL. Bei einem gemeinsamen öffentlichen Ursprung kann für beide URLs derselbe Wert verwendet werden. Nuxt verwendet für Drupal weiterhin `http://nginx:8080` und setzt für Sitzungsanfragen das Protokoll aus `DRUPAL_PUBLIC_URL`.

Unter `DRUPAL_REVERSE_PROXY_ADDRESSES` werden die stabilen Quelladressen der vertrauenswürdigen Peers kommasepariert eingetragen, die nginx als `REMOTE_ADDR` an Drupal weitergibt. Für Sitzungsanfragen ist das die Adresse des **Nuxt-Containers**, für direkte Drupal-Anfragen hinter dem Reverse Proxy gegebenenfalls dessen Adresse oder die Adresse des Docker-Gateways. Bei Bedarf erhält Nuxt per Compose-Override eine feste IP in einem dedizierten Netz. Nur die konkreten Adressen gelten als vertrauenswürdig. Drupal wertet dann das weitergereichte HTTPS-Protokoll aus und setzt das Sitzungs-Cookie mit `Secure`. Nach dem Start werden die OTP-Anmeldung über die öffentliche HTTPS-Adresse und das Attribut im `Set-Cookie`-Header geprüft.

Der Installer übernimmt `UI_PUBLIC_URL` beim ersten Start. Bei einer bestehenden Installation wird auch `beantragt_group.settings:frontend_base_url` aktualisiert. Die Links in Bestätigungs- und Einladungsmails werden geprüft.

## Datenschutz vor der Formularfreigabe

### 1. Datenschutzhinweis und freiwilliges Profil

Pro Mandant wird ein erreichbarer Datenschutzhinweis veröffentlicht und der Link direkt im Bürgerformular geprüft. Der Hinweis nennt die verantwortliche Stelle und die Kontaktstelle für Datenschutz, Zwecke und Rechtsgrundlagen je Verarbeitung, Empfänger, Fristen, Betroffenenrechte und Beschwerdestelle. Für jede Verwaltungsleistung wird mit der zuständigen Stelle die konkrete Rechtsgrundlage nach Art. 6 Abs. 1 Buchst. c oder e und Abs. 3 DSGVO in Verbindung mit der einschlägigen Fachnorm geklärt (rechtliche Verpflichtung oder öffentliche Aufgabe). Die Pflichtcheckbox bestätigt ausschließlich die Kenntnisnahme des Datenschutzhinweises. Dafür wird die Formulierung „Ich habe den Datenschutzhinweis zur Kenntnis genommen.“ verwendet. Eine freiwillige Einwilligung zum Speichern eines Profils für spätere Anträge wird getrennt und ohne Vorauswahl abgefragt. Bei der Prüfung muss der Antrag ohne Profil möglich sein. Widerruf und Löschung des Profils werden beschrieben.

### 2. Aufbewahrungsfristen für bestätigte Anträge

- **Je Formular.** Das Formular wird in Drupal unter `/admin/content/form-schemas` zur Bearbeitung geöffnet. Im Feld **Form Schema** wird auf oberster Ebene des bestehenden JSON-Objekts beispielsweise `"retention": {"dataRetentionDays": 180}` ergänzt und gespeichert. Zur Vererbung der Frist wird die Angabe entfernt.
- **Je Dienststelle.** Die Dienststelle wird in Drupal unter `/admin/group` zur Bearbeitung geöffnet und **Aufbewahrungsfrist in Tagen** (`field_retention_days`) gesetzt. Das Konto benötigt Gruppenbearbeitungsrechte und `administer beantragt retention policies`. Ein leeres Feld erbt von der nächsten übergeordneten Dienststelle mit gesetzter Frist, danach vom Mandanten und schließlich vom globalen Standard.
- Die Formularfrist hat Vorrang. `0` deaktiviert die automatische Löschung für die wirksame Regel. Welche Fälle zur Löschung anstehen, wird mit synthetischen Anträgen über `docker compose exec drupal sh /opt/selfhost/start-drupal.sh drush beantragt:retention --dry-run --json` geprüft. Die automatische Löschung erfolgt über Drupal-Cron.

### 3. Anlagen ohne KI-Dienste

Der offene Build enthält keinen KI-Analyseanbieter, keinen RAG-Dienst, keinen MCP-Server und keinen Chatassistenten. Der Standardstack speichert Anlagen, die die Upload-Regeln erfüllen, und prüft sie nach der lokalen Scan-Policy. Die KI-Analyse wird als nicht verfügbar übersprungen (`skipped / analysis_unavailable`). Der Standardstack sendet keine Antrags- oder Anlagendaten an KI-Anbieter.

### 4. Datensparsamkeit je Formular

- Für jedes Feld und jede Anlage wird geklärt, für welchen Verfahrensschritt und auf welcher Grundlage die Daten erforderlich sind. Felder ohne konkreten Zweck werden entfernt. Nur erforderliche Angaben sind Pflicht.
- Geprüft werden bedingte Felder, Freitext, Upload-Arten, der Umfang der Nachweise und besondere Kategorien personenbezogener Daten. Soweit das Verfahren es zulässt, werden geeignete strukturierte Angaben oder geschwärzte Nachweise verwendet.
- **FIM-Entwurf abnehmen.** Die zuständige Dienststelle wird gewählt und der **FIM-Katalog** (`/admin/fim`) geöffnet. Das gewünschte Formular wird gesucht und als Entwurf übernommen. Geprüft werden Feldtexte, Pflichtfelder, Zuordnungen zum Fachverfahren, Aufbewahrung und der tatsächliche Umfang der Speicherung, des Exports und der Übertragung. Abweichungen werden dokumentiert.
- Unter **Formulare** wird für den Entwurf **Veröffentlichen** gewählt. Bei einem klassifizierungspflichtigen Feld ohne `privacy.kind` stoppt `privacy_kind_required` die Publikation. Im Dialog erhalten die betroffenen Felder die passende Datenschutzklassifizierung. Nach dem Speichern wird die Veröffentlichung erneut gestartet. Der Status **Veröffentlicht** wird geprüft und das Formular im Bürgerportal zur Sichtprüfung geöffnet.
- Formversion, Freigabedatum, verantwortliche Fachstelle und Prüfergebnis werden festgehalten. Bei Änderungen am Formular oder Fachverfahren wird die Prüfung wiederholt.

### 5. Vorlage für das Verzeichnis von Verarbeitungstätigkeiten

Die Vorlage wird pro Verwaltungsleistung und Verarbeitungsvorgang ausgefüllt. Je nach Einsatz werden insbesondere Kategorien betroffener Personen und Daten, Rechtsgrundlagen, Kontaktstellen, technische und organisatorische Maßnahmen und etwaige Drittlandübermittlungen ergänzt. Die Angaben werden gemeinsam mit der Fachstelle aktuell gehalten.

| Vorgang und Zweck | Datenfluss und Speicherorte | Empfänger und Zugriff | Aufbewahrung und Löschung |
| --- | --- | --- | --- |
| Antrag entgegennehmen, E-Mail bestätigen, Fall bearbeiten | Formular und Anlagen → Nuxt → Drupal, Datenbank und privater Dateispeicher | Zuständige Dienststelle, Hosting und Maildienst nach Vertrag | Frist für unbestätigte und bestätigte Anträge, Anlagen und Sicherungen samt Fristbeginn und Löschverfahren |
| Freiwilliges Profil für weitere Anträge | Einwilligung und ausgewählte Kontaktdaten → Profil in Drupal | Antragstellende, technischer Betrieb im erforderlichen Umfang | Löschung bei Widerruf oder gemäß festgelegter Frist |
| Anlage optional prüfen oder Fall an Fachverfahren übergeben | Datei oder erforderliche Fachdaten → tatsächlich eingerichtetes Ziel, Rückmeldung → Drupal | Konkreter Analysedienst oder Fachverfahren, Rollen und Auftragsverarbeiter | Fristen für Kopien, Ergebnisse, Übertragungsprotokolle und Backups |

[DSGVO, Art. 6, 13 und 30](https://eur-lex.europa.eu/legal-content/DE/TXT/?uri=CELEX:32016R0679)

Der offene Build verwendet den Katalog und die Formulareingabe. Vorhandene Chat-Eingabefelder in FormSchema bleiben beim Import als Daten erhalten. Die offene Oberfläche wertet diese Eingabeoptionen nicht aus. Der Anlagen-Upload steht zur Verfügung. Die HTTP-Adapter für einen Analysedienst liegen im Quelltext; Freischaltung, Analyse-Endpunkt und Ergebnisanzeige der Vorprüfung von Nachweisen gehören zu Pro.
