# beantragt_nachreichung

Documents sent later ("Nachreichung"), step 1: cover sheet identifiers with QR code, request records of request letters, working-day deadlines.

## What it does

- **Request record ("Akte")** for every request letter draft (docgen purpose `request_info`): deadline, requested proofs (from providers such as `beantragt_bulk`), state `draft`, then `open` at release; waiting records of earlier releases of the application become `superseded` (request letters released together stay open side by side).
- **Cover sheet** while the jurisdiction section `nachreichung.coverSheet` is on: a standalone one-page PDF/A ("Deckblatt für Ihre Unterlagen") on the letter's own docgen row (`cover_pdf_*` columns of `beantragt_docgen_document`), rendered by `DocumentService::printable()` and released only with its letter, as the second document of the same response. The letter text points to it through the system value `nachforderung.deckblatt_hinweis`.
- **Identifier**: 16 random Crockford characters plus a GF(32) check character, QR payload `BA1-` plus the 17 characters. Stored only as an HMAC (`code_hash`, Variant A); a draft identifier is inactive (unknown on lookup) until the release books the letter, then active. A re-render or a discard voids the draft identifier.
- **Staff card** "Nachreichungen" with "Deckblatt drucken" (new identifier per print, at most 10 valid per application, checked again in the storing transaction; the per-application lock spans the docgen chain and is renewed before storing; audit row `cover_code`/`cover_printed`) and "Deckblatt sperren".

The separate cover sheet avoids a LibreOffice 26.8.1.1 issue that produced a damaged tagged PDF/A when inserting a blank page after a one-page letter.

## Tables

| Table | Content |
|---|---|
| `beantragt_cover_code` | keyed identifier hash, key version, origin (`letter`, `staff`), state (`draft`, `active`, `revoked`), docgen document, request record |
| `beantragt_nachforderung` | request records; step-2 columns exist and stay NULL |
| `beantragt_nachreichung_event` | events without person reference (codes, counts, dates), purged after 365 days |

No identifier in clear, no jurisdiction or organisation column: the application decides the scope.

## Key version and key check

The lookup key comes from `BEANTRAGT_APPLICATION_DATA_ENCRYPTION_KEY` by HKDF (`beantragt_cover_code_lookup_v<N>`). `beantragt_nachreichung.settings:key_version` (default 1) names the version for new identifiers. A key check value per version in State (`beantragt_nachreichung.key_checks`) refuses issuing after a silent change of the key material; the status report errors when a stored version can no longer be resolved and warns per jurisdiction with cover sheets on and no `docgen.holidayRegion`.

## Configuration

- Jurisdiction section `nachreichung`: `{"coverSheet": true}` (closed keys).
- Jurisdiction section `docgen`: `holidayRegion` (`DE-NW` and the other 15 state codes), needed while cover sheets are on; deadlines end on the next working day of that state (`WorkingDays`).

## Routes (internal, Nuxt proxy only, no OpenAPI)

| Route | Method |
|---|---|
| `/api/admin/applications/{application}/nachreichung` | GET, card |
| `/api/admin/applications/{application}/cover-sheet` | POST, PDF |
| `/api/admin/applications/{application}/cover-sheets/{cover}/revoke` | POST, card |

Gate: `application.view`, `ApplicationDataController::protectedDataAccess`, permission `view protected application data`; POST also the CSRF header token.

Docgen routes of the same feature: `GET .../docgen/documents/{document}/cover-pdf`, `POST .../docgen/documents/{document}/rerender`.

## Deletion

`hook_application_predelete` and the anonymization branch of `hook_application_update` delete every row of the application. Cron activates rows of released letters left in draft, removes draft rows of discarded letters after a day and purges old events.

## Step 2: receipts (Nachreichung)

- **Receipt record ("Eingangsvermerk")** `beantragt_nachreichung_eingang`: one row per decision "Übernehmen und fortsetzen" (`take_over`), "Erneut nachfordern" (`request_again`) or "Nur ablegen" (`file_only`) of an envelope (module `beantragt_bulk`): receipt date and its source, request record, request state before and after, files (hash, pages, cover pages, attachment UUID) and the result per requested proof. No names, no file names, no identifier.
- **Request record states**: `answered` after W, `partly_answered` after N; "Frist der Nachforderung verlängern" stores the new `deadline`, keeps `deadline_printed`, also after expiry.
- **Unassign**: "Vom Antrag entfernen" deletes the attachments of a receipt and sets the request record back to its state before, only while nobody changed it since (D16); "Neu zuordnen" does the same and sends the envelope back to the Sichtung while the batch files exist. Permission `revert nachreichung` (department admin, administrator).
- **"Erneut nachfordern" letter**: `RequestAgainProofs` makes the next request letter (and its record and cover sheet) list only what was insufficient or missing, with the reasons.
- **Sources**: modules holding envelopes implement `NachreichungSourceInterface` (tag `beantragt_nachreichung_source`); revocation, request changes and unassigning reach them through `beantragt_nachreichung.sources`. This module never knows bulk tables (N1).

| Route | Method |
|---|---|
| `/api/admin/applications/{application}/nachforderung/{nachforderung}/deadline` | POST, card |
| `/api/admin/applications/{application}/nachreichung/{entry}/remove` | POST, card (`revert nachreichung`) |
| `/api/admin/applications/{application}/nachreichung/{entry}/reassign` | POST, card (`revert nachreichung`) |
