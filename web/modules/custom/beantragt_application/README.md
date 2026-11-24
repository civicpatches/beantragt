# Beantragt Application runtime configuration

Attachment analysis uses the following environment variables:

- `BEANTRAGT_NUXT_INTERNAL_BASE_URL`: Nuxt worker base URL. Local DDEV defaults to `http://node-dev:3000`.
- `BEANTRAGT_INTELLIGENCE_SERVICE_KEY`: Legacy Intelligence license key. It must match the frontend `NUXT_INTELLIGENCE_SERVICE_KEY` and is sent as an `Authorization: Bearer` credential.
- `NUXT_DRUPAL_SERVICE_USER` and `NUXT_DRUPAL_SERVICE_PASSWORD`: Existing Drupal service credential sent separately to the Nuxt worker.
- `BEANTRAGT_ATTACHMENT_ANALYSIS_MAX_ATTEMPTS`: Optional retry limit. Defaults to 3.
- `BEANTRAGT_ATTACHMENT_ANALYSIS_TIMEOUT_SECONDS`: Optional request timeout of the queue dispatch to Nuxt. Defaults to 240 seconds, capped at 300 (a sovereign image model needs about 18 s per page).

The document guard client accepts a timeout of 1 to 120 seconds via the legacy setting `BEANTRAGT_RAG_SERVICE_TIMEOUT_SECONDS`. It uses a minimum of 50 seconds for document analysis.

Story profiles (a field with `citizenPrompt` or `staffPrompt`) store version 2 metadata: one record per story under `stories.citizen` (upload check, story A) and `stories.staff` (purpose-bound reading after the claim, story B, only under `sovereign_only`), each with its own run id and lease. Legacy profiles keep the version 1 record.

Services:

- `AttachmentAnalysisMetadataWriter`: the only writer of `stories.*`. `mergeStory()` takes the attachment claim lock, reloads the entity and replaces one story; `startRerun()` starts a staff rerun of one story with a new run id and lease.
- `AttachmentAnalysisSourcePreparer` selects the source for sovereign and local runs. Under `when_possible`, citizen and legacy checks use up to eight rendered pages from a transient guard derivative. A JPEG, PNG or PDF scan without a text layer ends the check with `redaction_not_possible`. The attachment stays available for submission. A separately configured staff run may use an original image or rendered scan pages marked `unredacted_needs_review`. A guard outage reports `guard_unavailable`.

Routes (all JSON, no cache):

- `POST /api/internal/attachments/{uuid}/analysis` (service auth): result callback. For a story profile it needs `story` and the `analysisRunId` Drupal minted, and is accepted only while that story is `pending` with that run id (409 `attachment.analysis_run_invalid` otherwise).
- `POST /api/internal/attachments/{uuid}/analysis-source?story=citizen` (service auth, claim token and run id in the body): pages of an unclaimed `sovereign_only` PDF for story A. Nothing is stored; a preparation failure is written into `stories.citizen` of that run.
- `POST /api/internal/attachments/{uuid}/citizen-analysis` (service auth, claim token in the body): the applicant's reduction of story A, read by the Nuxt poll `GET /api/attachments/{id}/analysis`. Unknown attachment, foreign token and claimed upload answer the same 404.
- `POST /api/admin/applications/{application}/attachments/{uuid}/analysis/{story}` (`legacy`, `citizen` or `staff`; case worker with protected access, CSRF header): staff rerun. It sets only that story `pending` with a new run id and lease and queues it; the queue prepares the source in Drupal. Answers 202, or 409 with `attachment.analysis_running`, `attachment.guard_blocked`, `attachment.analysis_story_required`, `attachment.analysis_not_available` or `attachment.claim_in_progress`.

Known limits:

- Story A under `sovereign_only` runs only on the upload path. If its write-back never arrives (Nuxt restart, lost proxy request), `stories.citizen` stays `pending` until its lease expires (3600 seconds for the queue and the rerun route); nothing re-enqueues it. The staff rerun is the recovery. A story A run that fails after the claim is queued again only under the external policies (`enqueueAfterUploadRunFailed()`).
- A failure write after a started run waits out the claim lock (7 rounds of 5 seconds, longer than the 30 second lock TTL). Only a writer that keeps re-taking the lock beyond that leaves the run `pending` until the rerun.
- Profile switch: when a field moves from a legacy profile to a story profile, an attachment whose version 1 record already holds a run (`pending`, `complete` or `failed`) keeps that record. The queue and the consent route skip it, a late legacy callback still lands, and no story runs for it until a staff rerun of a story replaces the legacy record. Only the start record (`status: not_requested`) is lifted to version 2.

When `IS_DDEV_PROJECT=true` or `BEANTRAGT_ENV` is `dev`, `local`, `ddev`, or `development`, an omitted Intelligence key uses the frontend development-only default `beantragt-intelligence-local-development`. Outside these explicit development environments the dispatcher does not send attachment bytes, logs the missing `BEANTRAGT_INTELLIGENCE_SERVICE_KEY`, and follows its existing retry and failure path.

## Protected application data

Staff v1 uses the same case scope and `view protected application data` permission for clear `submittedData` in the list and detail. Successful protected reads in either response use the `staff_api` audit channel.

The administrative data view treats an encrypted stored value as protected even if the submitted schema has no sensitive field. Without reveal access the value is redacted. Clear output is audited on `admin_data`, subject to the application read deduplication window.

Attachment field sensitivity and the schema returned by the administrative data view use the form revision pinned to the application. Publishing a later revision leaves the earlier attachment's sensitivity unchanged.

## Staff v1 start page counts and reports

Two read-only routes return figures for the applications a staff account may see in one workspace. They exist so that no admin page counts applications by paging a list; Nuxt only renders what they return.

- `GET /api/v1/staff/applications/counts`
- `GET /api/v1/staff/applications/report`

Auth, profile and workspace checks are those of `GET /api/v1/staff/applications`, through the same controller methods: staff session or basic auth (`_auth: basic_auth, cookie`), staff role required (service accounts and citizens get 403 `auth.unsupported_profile`), `jurisdiction=<slug>` required (400 `workspace.required`), optional `organisation=<slug>` (also `organization`, `org`), unknown slug 404 `workspace.not_found`, a scope the account may not read 403 `workspace.forbidden` (for example an organisation case worker asking for the jurisdiction). Errors use the v1 envelope `{error: {code, message}, meta: {apiVersion}}`.

Visibility is the staff list's rule set, applied in SQL: `TenantScopedEntityQuery::scopeQuery()` (confirmed applications, account memberships), `applyWorkspaceScope()` (the requested workspace) and the published/unpublished view permissions that the list checks per row in `ApplicationAccessControlHandler`. `StaffApplicationStatistics::scopedSelect()` compiles that scope once per request through `ScopedAggregateSelectQuery` into a select on the application base table. Each aggregate runs `COUNT ... GROUP BY` on its own clone. The query fails closed: a condition or column that needs any table besides the application base table throws instead of compiling. Workflow statuses the account may not view are reported as `unknown`, as in the list.

The kernel test `StaffApplicationStatisticsKernelTest` asserts that every count equals the staff list (its query plus per-row view access) for case workers, a jurisdiction administrator, an account with `administer application entities`, a scope-bypass account, legacy rows and an organisation moved to another jurisdiction. Two known differences, both pinned in that test:

- an unpublished application the staff member owns is listed through `view own application entities` but not counted when the account lacks `view unpublished application entities` (no staff role has that combination today);
- a legacy row with only `field_group` set (no `field_jurisdiction`, no `field_organisation`; the scope normalization on save fills both for every new or saved row) is counted for a jurisdiction administrator, while the access handler hides it from the list. The tenant query includes such rows on purpose (`TenantScopedEntityQueryTest`); which layer is right is part of issue #126.

The list drops rows after its query; the aggregates cannot. A new view rule in `ApplicationAccessControlHandler` or in a `hook_application_access()` implementation must therefore be mirrored in `StaffApplicationStatistics`, or the counts and the list drift apart.

### Counts

Request: workspace parameters only. One aggregate query over applications, plus the visible form groups (`form_schema_group`, same workspace scope and entity view access).

```json
{
  "data": {
    "total": 152,
    "applicationsByForm": { "<form uuid>": 117 },
    "applicationsByFormGroup": { "<form group uuid>": 129 },
    "formGroupsByForm": { "<form uuid>": 2 },
    "formGroups": [ { "id": "<form group uuid>", "label": "…", "formIds": ["<form uuid>"] } ]
  },
  "meta": { "apiVersion": "1.0", "resource": "staffApplicationCounts", "scope": { "jurisdiction": "musterstadt", "organisation": null } }
}
```

- `total`: every visible application of the workspace; `applicationsByForm` holds the forms that have visible applications (a missing key means 0).
- `formGroupsByForm` replaces the Nuxt `schema-group-counts` (in how many visible flows a form is used); `applicationsByFormGroup` sums `applicationsByForm` over the forms of a flow.

### Report

Request: workspace parameters plus optional `form=<form uuid>` (also `schema`, `formSchema`; resolved in the workspace like the list, 404 `form.not_found`), `from` and `to`. A date `YYYY-MM-DD` is a whole day in Europe/Berlin, an ISO 8601 date-time with offset is taken as given, both bounds are inclusive on `created`. Invalid values or `from` after `to` give 400 `report.range_invalid`.

```json
{
  "data": {
    "totals": { "applications": 150000, "completed": 138000, "open": 12000, "followUps": 3999, "averageProcessingHours": 374.2, "medianProcessingHours": 361 },
    "deadlines": { "overdue": 0, "dueToday": 0, "dueSoon": 1, "today": "2026-10-28", "horizonDays": 7 },
    "byStatus": [ { "key": "abgeschlossen", "label": "Abgeschlossen", "color": "#16a34a", "completed": true, "count": 138000 } ],
    "byForm": [ { "key": "<form uuid>", "label": "…", "count": 45000, "averageProcessingHours": 374.2, "followUpCount": 1199 } ],
    "byDay": [ { "date": "2026-10-27", "count": 214 } ]
  },
  "meta": { "apiVersion": "1.0", "resource": "staffApplicationReport", "generatedAt": "2026-10-28T19:00:00Z", "scope": { "jurisdiction": "synthstadt", "organisation": null }, "filters": { "form": null, "from": null, "to": null }, "timeZone": "Europe/Berlin", "dayTimeZone": "UTC", "truncated": false }
}
```

Semantics, taken over from the former Nuxt computation (`reports/overview.get.ts`):

- completed: the status is terminal or carries `approved`, `rejected`, `closed` or `withdrawn`; follow-up: the status carries `request_info`;
- `byStatus` merges statuses by machine name across organisations (fallback label, then UUID; no status gives `unknown`);
- processing time is `changed - created` for rows with `changed >= created`; averages and the median are computed on seconds and rounded to 0.1 hours at the end;
- deadlines count open applications only; "today" is the Europe/Berlin date, `dueSoon` covers today up to `horizonDays` days ahead;
- `byDay` buckets `created` by UTC day, like the former UI;
- CSV export stays in Nuxt and is rendered from this payload. There is no row limit, so `truncated` is always `false`.

Cost: four SQL statements for the report (status x form, day, median offset, form labels) on one compiled scope, plus loading the statuses that occur. Measured on 150,000 applications in one jurisdiction: counts 0.19 s, report 0.65 s. The scaling limit is the median: the database sorts every scoped row by processing time to reach the middle offset (about 0.2 s at 150,000 rows, growing with n log n).

### Caching

Success responses are `CacheableJsonResponse` with contexts `user`, `user.permissions` and `url.query_args:<name>` for exactly the parameters read (`jurisdiction`, `organisation`, `organization`, `org`; the report adds `form`, `schema`, `formSchema`, `from`, `to`) and tags `application_list`, `group_list`, `user:<uid>`, `group_relationship_list:plugin:group_membership:entity:<uid>`, the workspace groups, `form_schema_list`, plus `form_schema_group_list` (counts) or `workflow_status_list` (report). Visibility never expires by time. The counts are permanent until a tag is invalidated; the report sets max-age to the next Europe/Berlin midnight because the deadline figures change with the date. The routes do not set `no_cache`, but the dynamic page cache currently declines per-user responses (`X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)`), so every call is computed. The HTTP header stays private and uncacheable for browsers and proxies. Error responses are plain, uncacheable JSON.

The public OpenAPI document does not list these routes yet.
