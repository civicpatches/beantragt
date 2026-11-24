# Staff case layout preferences

These are internal, cookie-authenticated endpoints for the Nuxt casework proxy,
outside the public `/api/v1` contract:

- `GET /api/staff/case-layout/{workspace_id}` reads personal and workspace layouts.
- `PATCH /api/staff/case-layout/{workspace_id}` replaces the caller's preference.
- `PATCH /api/staff/case-layout/{workspace_id}/workspace` replaces the shared default.

Both PATCH routes require Drupal's `X-CSRF-Token` and exactly `{"layout": ...}`.
A layout is a version-1 object with `left` and `right` lists containing each of
the twelve `CaseLayout::SECTIONS` IDs exactly once. `null` removes a preference.
Optional pro section IDs remain valid when that frontend layer is absent.

Responses contain `layout`, `writable`, `workspaceDefault` and
`workspaceDefaultWritable` and are private/no-store. The UI resolves personal,
workspace, then its built-in default. Only staff may read. An organisation
requires its membership or an administrator of its jurisdiction tree;
workspace defaults additionally require the management site role and the
corresponding group admin role. Plain organisation membership cannot access
the enclosing jurisdiction workspace or another organisation.
Shared demo personas recognized by the active demo OTP provider are read-only:
they receive no personal override and both writable flags are false.

Personal preferences use `user.data` namespace `beantragt_group`, key
`case_layout.<workspace_id>`. Shared defaults are tenant content in
`field_jur_config.staffForms.caseLayouts.<workspace_id>`: on the jurisdiction
itself or, for an organisation, on its immediate jurisdiction. Each workspace
has a distinct key. The existing jurisdiction configuration lock protects
updates and preserves other workspaces and tenant settings. No layout value
is stored in Drupal configuration exports or exposed by the public settings API.
