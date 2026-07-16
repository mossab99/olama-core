# Olama Oracle Sync and Olama Core Review

Date: 2026-07-15

## Executive conclusion

The intended architecture is sound: Oracle ERP is the upstream authority, Olama Oracle Sync is the ingestion boundary, and Olama Core is the local operational source of truth for families, students, and student-year enrollment data.

The current implementation is a useful prototype, but it does not yet deliver that architecture reliably. The main problems are:

1. The API endpoints used for bulk synchronization ignore the plugin's `limit`, `offset`, and (in some paths) `study_year` parameters. They return unbounded datasets.
2. Olama Core detects identical payloads with hashes and skips unchanged database writes, but all Oracle rows are still downloaded and normalized on every full run.
3. The "full sync" screen does not perform a full sync. It synchronizes students for families that already exist locally; families must be imported separately first.
4. The two main buttons—student sync and student-year sync—ultimately call the same importer method.
5. Inactive, deleted, withdrawn, or otherwise absent Oracle records are not reliably reflected locally. Current API list queries filter to active records, so stale local records can remain indefinitely.
6. The "Scheduled" setting has no scheduler or background worker behind it.
7. Parts of Olama Core still call the Oracle API live through the Sync plugin for family, student, financial, and transportation cards. Therefore Olama Core is not yet the sole local source of truth and those screens depend on Oracle availability.

Recommendation: redesign the synchronization engine before redesigning only the screen. The target experience should be one primary **Sync now** action backed by a resumable server-side job and a real incremental API cursor. Diagnostic and recovery tools should live under an Advanced section.

## Current architecture

### Intended flow

Oracle ERP → Python API bridge → Olama Oracle Sync → Olama Core tables → other Olama plugins

### Local source-of-truth tables

Olama Core creates three central Oracle-backed entities:

- `wp_olama_core_families`
- `wp_olama_core_students`
- `wp_olama_core_student_years`

The identity model is good:

- Family: unique `oracle_family_id`, stable `family_uid = ORA-FAM-{id}`
- Student: unique `(oracle_family_id, oracle_student_id)`, stable `student_uid = ORA-STU-{family}-{student}`
- Student year: unique `(student_uid, study_year)`

These unique keys make retries and idempotent upserts practical.

### Existing unchanged-record protection

All three Olama Core services normalize their incoming data, calculate a SHA-256 `source_hash`, and return `skipped` when the stored hash is identical. This prevents unnecessary UPDATE statements and is worth keeping.

However, this optimization happens only after the payload has been retrieved from Oracle. It saves local writes, not Oracle queries, network traffic, API serialization, WordPress processing, per-record comparisons, or raw-payload logging.

Also, `last_synced_at` is not updated for skipped rows because the service returns before setting it. It therefore behaves like "last changed locally," not "last seen in Oracle." A separate `last_seen_at` is needed for reconciliation.

## Direct answer: must unchanged data be pulled again?

### With the current code: yes

Every normal bulk run pulls the source rows again. Olama Core then hashes each record and skips the local write if nothing changed.

There is no working incremental contract in the bulk API:

- `/api/families` does not read `limit`, `offset`, or `modified_since`.
- `/api/students` does not read `limit`, `offset`, `study_year`, or `modified_since`.
- The repositories return complete result sets and filter them to currently active data.
- The WordPress importer sends pagination parameters, but the API ignores them.

The importers contain duplicate-page guards, so they detect the repeated first record and stop. This prevents an infinite loop, but it is not pagination. The first API response is still unbounded and is processed in one request.

### Better approach: incremental cursor plus periodic reconciliation

Oracle tables already expose `DATE_MODIFIED` in several repository queries, so an incremental design is feasible. The bridge should expose change feeds ordered by a stable compound cursor:

`(date_modified, family_id[, student_id, study_year])`

Do not use only a timestamp: multiple rows can share the same timestamp. The IDs provide deterministic tie-breaking.

Suggested endpoints:

```text
GET /api/sync/families?cursor=...&limit=500
GET /api/sync/students?cursor=...&limit=500
GET /api/sync/student-years?study_year=2026-2027&cursor=...&limit=500
```

Suggested response:

```json
{
  "items": [],
  "next_cursor": "opaque-token",
  "has_more": false,
  "snapshot_as_of": "2026-07-15T10:20:30Z"
}
```

Each item should include a source modification timestamp and active/deleted state. The Sync plugin should persist a successful cursor per stream and study year. It should advance a cursor only after the corresponding local batch has committed successfully.

Use a small overlap window when starting the next run, then rely on the existing idempotent upserts and hashes to remove duplicates safely. In addition, run a scheduled full reconciliation (for example weekly) to catch missed changes, timestamp anomalies, and deletions.

## Findings by priority

### Critical: the API and importer disagree about pagination

The plugin calls `/api/families` and `/api/students` with `limit` and `offset`. The Flask routes call `get_all_families()` and `get_all_students()` without reading those parameters. As data volume grows, this creates large Oracle queries, large JSON responses, memory pressure, request timeouts, and misleading progress reporting.

Required fix: define pagination or cursor behavior in the API first, then make the WordPress client consume that contract. Prefer cursor pagination over offset pagination for mutable Oracle tables.

### Critical: full synchronization is not complete or atomic as a workflow

The automatic full-sync panel only iterates family IDs already in the local Core table. A new installation needs a separate **Import All Families** action before the apparent full sync can work.

Required fix: one orchestrated job should perform these phases automatically:

1. Validate connection and configuration.
2. Synchronize families.
3. Synchronize students.
4. Synchronize student-year records for the selected/current year.
5. Reconcile inactive/missing records.
6. Validate counts and relationships.
7. Publish a single run summary.

### Critical: stale and inactive records are not reconciled

The family list query includes only `IS_ACTIVE = 1`; student list queries include only active rows for the configured current year. If an Oracle family becomes inactive, a student withdraws, or a row is deleted, it disappears from the feed. The local copy is not deactivated because the sync never receives a tombstone.

Required fix: incremental feeds must include state changes, including inactive records. For hard deletions, provide tombstones/change-log rows or use snapshot reconciliation. Prefer soft deactivation in Core over destructive deletion so dependent plugins retain historical references.

### High: two advertised sync modes are the same operation

`import_student_years_for_imported_families()` simply returns `import_all_imported_families()`. Both paths upsert the student and its student-year data. The separate buttons, progress state, and AJAX actions create complexity without distinct behavior.

Required fix: remove the duplicate operator choices. Keep entity phases inside the job engine and show them as progress, not buttons.

### High: the completed progress state can block a later run

The AJAX handler returns "Sync is already completed" when a new request starts from an offset at or behind the stored completed offset. The normal Start action does not create a new run identity or reset that state. This makes **Reset progress** part of the ordinary workflow when it should only be a recovery tool.

Required fix: every click on **Sync now** should create a new run with its own ID and state. Resume should target an interrupted run; it should not reuse a permanent option keyed only by mode and study year.

### High: "Scheduled" mode is only a label

The setting accepts `scheduled_read_only`, and the dashboard displays "Scheduled," but there is no cron registration, queue, recurring action, or worker in the plugin.

Required fix: either remove this choice until implemented or add a real scheduler and a server-side job runner. Browser JavaScript should observe jobs, not be responsible for keeping them alive.

### High: Olama Core still has live Oracle dependencies

Olama Core family 360, family card, student card, financial card, and transportation card handlers call `olama_oracle_sync_api_get()` directly. This bypasses the local source-of-truth promise and couples Core's UI to both the Sync plugin and current Oracle/API availability.

Recommended boundary:

- Families, students, and student-year enrollment: always read from Olama Core locally.
- Financial and transportation data: explicitly choose either local synchronized projections or clearly labeled live Oracle queries with caching/fallback. Do not mix the two models invisibly.
- Olama Core should not depend on a Sync-plugin helper for its own core family/student read paths.

### Medium: data mappings are inconsistent

Examples:

- Family list API returns `father_email`, while the family importer reads `email`.
- Family list API returns `is_active`, while the importer expects `family_status` or `status`.
- Some student endpoints return `student_status_text`, while the importer expects `student_status_name` or `status_name`.
- The family-card student query returns `sch_mother_mobile`, while the importer reads `mother_mobile`.

Required fix: publish versioned canonical DTOs from the API and map them once. Avoid fallback lists that differ by endpoint.

### Medium: raw payload storage can grow without bound

Raw payload storage is enabled by default. Every processed family/student can append another full payload on every run, including unchanged records. There is no retention rule or uniqueness policy.

Required fix: default this off in production, or store only failures/latest payloads with a retention period. Do not insert a new raw payload for unchanged records unless audit requirements demand it.

### Medium: error reporting can claim success after database failures

The repository insert/update helpers do not check WordPress database errors. Service methods can return `created` or `updated` without verifying that the write succeeded.

Required fix: check affected-row/error results and throw a typed exception so the batch does not advance its cursor after a failed write.

## Recommended synchronization engine

### Job model

Add durable tables rather than storing progress in WordPress options:

- `olama_oracle_sync_runs`: one row per requested run, with trigger, mode, selected year, phase, status, timestamps, and aggregate counts.
- `olama_oracle_sync_batches`: cursor before/after, phase, attempt count, lease/lock, counts, and error.
- `olama_oracle_sync_state`: last successful cursor per stream and study year.
- Existing item/error logs can remain, but successful per-item logs should be sampled or retained briefly to control growth.

The worker should be server-side and resumable. Each batch must be idempotent. Use a run lock to prevent two syncs for the same stream/year from overlapping.

### State semantics in Core

Add or clarify:

- `source_updated_at`: timestamp supplied by Oracle.
- `last_seen_at`: most recent successful reconciliation in which the record was observed.
- `is_active` or a clear status field for every entity.
- `source_deleted_at` or `deactivated_at` where applicable.
- `last_synced_at`: define whether this means last processed or last locally changed; do not overload it.

Keep `source_hash` as a second line of defense and for APIs/tables that cannot provide trustworthy modification timestamps.

### Initial and recurring behavior

- First run: complete cursor-paged snapshot.
- Normal runs: incremental changes only.
- Weekly or nightly: reconciliation snapshot, depending on data volume and operational tolerance.
- New study year: explicit bootstrap of that year's student-year stream; family/student master streams remain incremental.
- Failures: retry the failed batch; never restart from offset zero and never advance the successful cursor prematurely.

## Interface redesign

The normal operator should not see offsets, batch sizes, entity-specific import buttons, or reset controls.

### Main page

**Connection**

- Status: Connected / Disconnected
- API endpoint (safe display)
- Last successful check
- **Test connection**

**Synchronization**

- Current study year
- Last successful sync time
- Data freshness/lag
- Families, students, and enrollments totals
- Current status and current phase
- Primary action: **Sync now**
- Secondary action: **Cancel** only when a run is active

**Current/latest run**

- Phase: Families → Students → Enrollments → Reconciliation → Validation
- One progress bar
- Created, updated, unchanged, deactivated, failed
- Clear summary and a link to errors

**Schedule**

- Automatic sync on/off
- Frequency and next run time
- Full reconciliation frequency

**History**

- Recent runs with status, duration, trigger, counts, and error link

### Advanced page or disclosure

Keep only recovery/diagnostic actions here:

- Rebuild local cache/full reconciliation
- Re-import one family
- Retry failed run/batch
- Validation report
- Raw payload diagnostics with retention warning
- Batch size and timeout tuning

Remove the normal-use Start Offset field. Resume should be automatic from durable job state.

## Proposed delivery sequence

### Phase 1: correctness contract

1. Define canonical family, student, and student-year DTOs.
2. Implement cursor pagination and source modification timestamps in the API.
3. Include inactive/tombstone semantics.
4. Add API contract tests for ordering, pagination boundaries, repeated timestamps, and study-year filtering.

### Phase 2: durable sync engine

1. Add run, batch, and cursor state.
2. Build the single orchestrated sync pipeline.
3. Preserve hashes and idempotent unique keys.
4. Add locking, retries, database error checks, and reconciliation.
5. Add a real scheduler.

### Phase 3: simplify the interface

1. Replace the current manual page with the main design above.
2. Move repair tools to Advanced.
3. Consolidate run history, validation, and errors.
4. Remove duplicate actions and user-managed offsets.

### Phase 4: enforce the Core boundary

1. Move family/student read screens to local Core services.
2. Decide and document whether financial/transportation are synchronized locally or queried live.
3. Give other Olama plugins stable Core service/query APIs so they do not read Oracle or Sync tables directly.

## Acceptance criteria for the redesign

- A new installation can connect and complete its first population with one action.
- A normal second run requests only changed records, apart from the intentional overlap window.
- Re-running any batch produces no duplicates.
- A family/student deactivated in Oracle becomes inactive locally without losing history.
- A failed batch resumes without restarting the whole run.
- Two sync jobs for the same scope cannot run concurrently.
- Study-year selection is honored by the API and verifiable in run metadata.
- Closing the browser does not stop a run.
- Other Olama plugins can read families/students from Core when Oracle is unavailable.
- The main UI contains no offset input and no duplicate student/student-year actions.

## Final recommendation

Do not spend the next implementation step only restyling the existing screen. First establish the incremental API and durable job model; otherwise the simplified interface will hide the same scaling and correctness problems. Once the job becomes one coherent operation, the interface naturally reduces to connection status, **Sync now**, progress, schedule, history, and an Advanced recovery area.
