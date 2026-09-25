# Ministry student completion implementation status

## Architecture

Core owns the field registry, year-specific accepted supplementary values,
family submissions, review decisions, source conflict checks and completion
evaluation. Gateway authenticates the family with its existing access context
and verifies the selected Core student belongs to the family before a form
submission. OLAMA Users has a separate capability-controlled monitoring and
review page. Oracle synchronization remains read-only.

Two new Core tables use the current WordPress prefix:
`olama_core_ministry_values` and `olama_core_ministry_submissions`. Accepted
values are keyed by student, academic year and semantic field. A submission
records the current and proposed values, submitter, time and review decision.
The school and building national numbers are Core configuration keyed by the
enrollment's school ID. Contabo's read-only Core data check mapped the
kindergarten to `school_id` 1 and basic school to `school_id` 2. School
administration confirmed school number `117571` and building number `1005`
apply to both records; configure them when the feature is deployed.

## Implemented workflow

- Canonical Core students with an active enrollment in the selected academic
  year are evaluated. Temporary Gateway members are excluded.
- Existing verified source values are displayed. Missing family-actionable
  values can be saved as drafts or submitted for staff review.
- A pending submission never resolves a Ministry field. Approved family data
  is stored with provenance and compared with the source value observed at
  approval. A later source change raises a review state.
- Existing identity values accept correction requests; the family cannot
  overwrite the Oracle-backed student row.
- The admin page reports primary statuses, overlapping causes, grade totals,
  missing fields, students, families and pending review submissions.
- Dashboard totals are computed from the same Core evaluator used by Gateway.

## Safe boundaries

The field contract in `ministry-student-contract.md` contains blocking
decisions. The 12 governorates and allowed non-Jordanian document types have
been supplied; district, subdistrict and locality relationships and some other
controlled vocabularies remain unresolved. Those fields cannot be completed
through arbitrary free text or counted as accepted values. Ministry export is
not enabled. The family-facing Gateway view is disabled by default through
`olama_ministry_family_enabled` until the blocking field decisions and
geography import are resolved. These boundaries prevent false Ministry-ready
statuses and a premature family rollout.

## Verification

PHP syntax checks pass for all changed PHP files. All 5 Core, 16 Gateway and
2 Users PHP test scripts pass. The new evaluator test covers school-owned
missing data, nationality-based document applicability, pending and
non-blocking review effects, approved family values, and later source conflicts.
A live WordPress check was attempted but the local database connection was
unavailable, so migrations and browser behavior remain unverified.

A separate read-only check of the Contabo WordPress site found OLAMA Core
version `1.0.0`; the Ministry tables are not present there. No production code
or database changes were made. The database maps kindergarten school ID `1`
to `روضة اكاديمية علماء المستقبل`; school ID `2` is a separate basic-school
record.

## Work requiring verified inputs

Resolve the remaining blocking decisions in the contract, import the official
Arabic district/subdistrict/locality hierarchy with source/version metadata,
validate the hierarchy server-side, and enable a cascading family picker.
Resolve remaining Ministry classification codes, per-field applicability,
guardian authority, and any school-specific classroom mapping. Then run database migration and end-to-end checks in a
working WordPress environment.

School administration supplied school national number `117571` in the prior
year workbook and building number `1005` and confirmed both apply to
`school_id` 1 and `school_id` 2. Configure both records when the feature is
deployed. Family action is requested only for missing
registry fields marked `REVIEW_REQUIRED`, such as separate name components,
birthplace, family size, sibling order, guardian information and the
workbook-defined health category. No legacy geographic value has been
automatically matched or marked official because the reference import is
blocked.
