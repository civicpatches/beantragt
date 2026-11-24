# beantragt_docgen

Decision notices from Word templates use a template catalog per
organisation, placeholder catalog per form, drafts through the docgen service,
mandatory PDF/A-2b preview and release by a person, provision to the applicant
through `ExternalResponseService` (source `docgen`).

The service API and deployment instructions are described in the
[document service README](../../../../services/docgen/README.md).

Drupal calls docgen only; Gotenberg and veraPDF are never reachable from
Drupal. The configuration sets `BEANTRAGT_DOCGEN_BASE_URL` and the token
(`BEANTRAGT_DOCGEN_TOKEN` or its secret file).
