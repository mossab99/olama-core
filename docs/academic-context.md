# Olama academic context contract

Olama Core is the sole owner of academic-year definitions, semester definitions,
their dates, and the active year/semester pair.

## Read contract

Plugins must use these APIs and must fail closed when no context is configured:

```php
$calendar = olama_core()->academic_calendar();
$context  = olama_core()->academic_context();

$years           = $calendar->years();
$year            = $calendar->year($academic_year_id);
$year             = $calendar->resolve_year_code($study_year);
$semesters       = $calendar->semesters($academic_year_id);
$semester        = $calendar->semester($semester_id);
$current         = $context->current();
$current_year    = $context->current_year();
$current_semester = $context->current_semester();
```

Do not infer the current year from the newest row, imported Oracle data, the
server date, or a hard-coded string. Do not query `is_active` in a consumer.

Olama School keeps `Olama_School_Academic::get_active_year()` and related read
methods as compatibility facades. They delegate to Core and never write year or
semester definitions.

## Write contract

Only the Core Academic Calendar administration page may create or edit
definitions or change the active pair. A context change updates the year and
semester atomically and increments the context revision.

Transactional plugins should validate new writes with:

```php
$allowed = olama_core()->academic_context()->assert_writable_year($academic_year_id);
if (is_wp_error($allowed)) {
    return $allowed;
}
```

Numeric academic-year and semester IDs are permanent identifiers. Never derive
an ID from the display label and never renumber existing definitions.

## External year-code mappings

Core stores one canonical code in `YYYY-YYYY` form. External systems may use a
different representation without changing Core identity. Source mappings are
owned and edited only on the Core Academic Calendar page and are stored in
`olama_core_academic_year_source_mappings`.

```php
$calendar = olama_core()->academic_calendar();

$canonical = $calendar->canonical_year_code($academic_year_id);
$outbound  = $calendar->external_year_code($academic_year_id, 'oracle');
$year      = $calendar->resolve_external_year('oracle', $incoming_value);
```

Oracle Sync translates `study_year` only at its shared HTTP-client boundary.
For example, Core `2025-2026` may be sent to Oracle as `2025/2026`, while Core
`2026-2027` may be sent unchanged. Incoming slash or dash values must resolve to
one known Core year before storage; Core-owned tables store only the canonical
dash code. Unknown values fail closed instead of creating a second year label.

Operational plugins do not own or edit external mappings and must not contain
Oracle-specific year formatting logic.

## Year closeout

Historical purge is intentionally separate from context switching. No plugin
may delete historical transactions as a side effect of activating a new year.

Core owns the complete closeout sequence on the Academic Calendar page:

1. Activate the new academic year and semester. The previous year must be
   closed and cannot be the active context.
2. Run the read-only dry preview. Every table with `academic_year_id` or
   `study_year` data must have an explicit archive/purge or preservation policy.
   Unknown scoped tables block all later steps.
3. Create a private JSONL archive. Archives are stored outside the public
   WordPress directory in `olama-private-archives` (filterable through
   `olama_core_year_archive_root`). The manifest records table schemas, row
   counts, and SHA-256 checksums.
4. Verify the manifest and every dataset checksum, row count, JSON record, and
   current-table restore compatibility.
5. Purge only a verified archive. Core rechecks the archive, rescans for unknown
   tables, detects data drift since export, requires the exact year code and an
   acknowledgement, and deletes the classified rows in one transaction.
6. Restore only an archive whose status is `purged`. Core re-verifies all files,
   requires the target year to remain closed and non-current, refuses to restore
   over existing classified rows, and imports datasets in reverse manifest order
   so parents precede children. The exact year code and acknowledgement are
   required. Any insert conflict, checksum failure, or post-restore count mismatch
   rolls back the entire transaction. A restored archive can be purged again
   through the same verified workflow.

Archive creation and purge are never automatic. An administrator may retain
historical data indefinitely and use only the context switch.

Restore is also never automatic and never changes the active academic year or
semester. It restores only the operational rows contained in the archive;
preservation data was never removed and is therefore not duplicated.

Curriculum units, lessons, questions, dates, uploaded media/video links,
reusable exam definitions and attachments, evaluation templates, academic
definitions, and master catalogue/person records are preservation data. The
purge set consists of explicitly classified year-specific operational records,
including lesson plans, student results/attendance, Store activity, invoices,
messages, transportation, employee scheduling logs, and Oracle financial
imports.
