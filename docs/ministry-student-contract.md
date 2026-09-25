# Ministry student statistical data contract

## Source and population

The school-supplied `البيانات.xlsx` is the working output specification. SHA-256:
`1687433c5a77408feb903ab11df18a95176819477083676e553d9e51e9be2837`.
Its `بيانات الطالب` sheet has two printed pages. The exact workbook labels and
positions are recorded in [ministry-workbook-columns.csv](ministry-workbook-columns.csv).
Page 2 repeats student and school identifiers; repeated cells use the same
semantic value. `بيانات الموظفين` is outside this feature.

The prior-year workbook `السجل الإحصائي  روضة علماء المستقبل 25-26.xlsx` is
historical implementation evidence, not the current authority. It contains 65
student rows across three class sheets. Its school-information sheet provides
the school's national number `117571`; school administration has confirmed the
building national number is `1005`. The Core kindergarten record is `school_id` 1 and the basic-school record is
`school_id` 2. School administration confirmed both identifiers apply to both
school IDs. The completed student
rows contain Jordanian nationality only and no non-Jordanian document-type
examples. Therefore they do not settle non-Jordanian identity applicability.

Observed values in that historical sample include: study type `نظامية`,
father education `أساسي`, `ثانوي`, `دبلوم`, `بكالوريوس`, `دكتوراه`, mother
education `ثانوي`, `دبلوم`, `بكالوريوس`, `ماجستير`, guardian relation `والد`,
health status `سليم`, academic status `ناجح`, and external aid `لا يوجد`.
These are values used in this school's prior-year rows, not proof of the full
Ministry-approved vocabularies. The current workbook itself explicitly lists
the two health values and the academic-status and study-type examples in its
field labels. School-provided Ministry guidance confirms the only accepted
non-Jordanian document types are passport (`جواز سفر`) and ID card (`هوية`).
The student national number is a separate value, not the passport/ID number.
When it is absent from Core, the family may submit it for staff review. Other accepted choices and applicability
remain unresolved until confirmed by the Ministry form guide or school owner.

School administration supplied this governorate list on 2026-09-25, with Amman
named `عمّان (العاصمة)`: إربد، الزرقاء، المفرق، عجلون، جرش، مادبا، البلقاء،
الكرك، الطفيلة، معان، العقبة. This provides the governorate choices for the
reference layer. It does not provide the districts, subdistricts, localities,
or their parent-child relationships required by the cascading residence
fields.

Only a Core student with an enrollment row in the selected academic year is in
the official denominator. Gateway temporary members are not Core students and
are excluded until an authorized enrollment/linking process creates a canonical
record. Unresolved relationships are excluded and reported separately.

## Resolution and readiness

Each registry entry specifies its workbook position, source, owner, applicability,
write policy, sensitivity, and validation. A starred workbook label is recorded
as `required_in_workbook`; it is not automatically applicable to every child.
An applicable required field is resolved only by a valid accepted value.
Pending submissions and drafts are candidate values, never export values.
An approved submission may resolve a field through its declared policy.

| Student condition | National number | Jordanian civil register | Non-Jordanian document type |
| --- | --- | --- | --- |
| Recognized Jordanian | Required | Required | Not applicable |
| Recognized non-Jordanian | Required; family may submit if missing | Not applicable | Required: `جواز سفر` or `هوية` |
| Nationality unknown | School decision pending | School decision pending | School decision pending |

Other fields currently follow the workbook's starred/group-required structure.
An unstarred field or classification whose applicability is not established
remains in the unresolved register; it must not be silently treated as optional
or accepted for export.

For Oracle-backed data, accepted imported values remain in Core's synchronized
tables. Family supplied values and corrections are stored separately with
provenance. A later Oracle conflict creates a review issue; it does not silently
erase the family submission. A protected identity value is never overwritten
by a family request. An existing protected identity value changes the effective
policy from `REVIEW_REQUIRED` to `CORRECTION_REQUEST_ONLY`. No field is
currently assigned `DIRECT_IF_MISSING`; assigning one requires the source
and write decision in MIN-010.

Blocking reviews prevent readiness. Non-blocking corrections remain operational
flags and may coexist with `COMPLETE`. Each student retains all issue flags.
The mutually exclusive primary status uses: `NEEDS_REVIEW`, then
`NEEDS_SCHOOL`, then `NEEDS_FAMILY`, then `COMPLETE`. Draft progress is a
separate workflow state. Summary counts must reconcile to the eligible
population, and overlapping issue counts are labeled separately.

## Semantic field map

The registry in `class-olama-core-ministry-service.php` is the executable
field map. Its source keys refer to Core's `students`, `families`, and
`student_years` records, school configuration, or a supplementary accepted
value. The CSV records the workbook's literal cells; the registry records each
semantic key once. Where Core only has `student_name`, the separate first,
father, grandfather and family names are **not** inferred by splitting it.

## Source precedence matrix

| Field type | Resolved value order | Conflict behavior |
| --- | --- | --- |
| Oracle-backed identity or profile | Approved family value while the Oracle field still equals its approval-time source snapshot; otherwise current Oracle value | A changed Oracle value with a different approved family value blocks readiness for review |
| Academic enrollment | Selected year's active enrollment | Missing or unverified enrollment excludes the student from the official population |
| School configuration | Configuration for the enrollment's school ID | Missing configuration is school-owned; family cannot supply it |
| Supplementary family data | Approved year-specific supplementary value | Draft and pending values remain candidates; they do not resolve a field |
| Derived display name | Accepted first and family names | Missing component blocks the derived value |
| Unresolved mapping | No resolved value | School or data steward decision is required |

## Write policy matrix

| Policy | Current behavior |
| --- | --- |
| `READ_ONLY` | Family submission rejected; school-owned or blocked mapping |
| `ACADEMIC_ONLY` | Read from verified enrollment; family submission rejected |
| `SYSTEM_DERIVED` | Generated from accepted components; family submission rejected |
| `REVIEW_REQUIRED` | Family proposal remains pending until authorized review |
| `CORRECTION_REQUEST_ONLY` | Effective policy for an existing protected identity value; current value is preserved during review |
| `DIRECT_IF_MISSING` | Reserved; no field uses it until MIN-010 is resolved |

## Unresolved decisions register

| ID | Field | Question / evidence | Owner | Impact | Blocking |
| --- | --- | --- | --- | --- | --- |
| MIN-001 | School national and building numbers | National school number `117571` is in the prior-year workbook and school administration supplied building number `1005`. Contabo Core data maps kindergarten to `school_id` 1 and basic school to `school_id` 2; school administration confirmed both identifiers apply to both. | School administration | School configuration and readiness | No |
| MIN-002 | Jordan geography below governorate | School administration supplied the 12 governorate names, including Amman (`عمّان / العاصمة`). District, subdistrict and locality parent-child data have not been supplied or imported. The [Ministry of Interior](https://moi.gov.jo/AR/List/%D8%A7%D9%84%D9%85%D8%AD%D8%A7%D9%81%D8%B8%D8%A7%D8%AA_%D9%88%D8%A7%D9%84%D9%85%D8%B1%D8%A7%D9%83%D8%B2_%D8%A7%D9%84%D8%A5%D8%AF%D8%A7%D8%B1%D9%8A%D8%A9) and [Department of Statistics](https://dosweb.dos.gov.jo/population/population-2/) publish relevant administrative/locality references. | School administration / data steward | Geography picker and hierarchy validation | Yes for lower levels |
| MIN-003 | Controlled categories | The current workbook lists health and academic-status choices and examples for study type and refugee status. The prior-year sample shows values used at this school, but does not establish exhaustive accepted codes for education, aid, religion and other categories. | Ministry-form owner | Validation and export formatter | Yes for affected fields |
| MIN-004 | Nationality and identity applicability | Ministry guidance supplied by school administration limits non-Jordanian document types to passport or ID card. The student national number is separate; the family may provide it when missing, subject to staff review. | Ministry-form owner | Conditional readiness | No for documented rule |
| MIN-005 | Guardian authority | Core has father/mother fields and a sponsor name, but no verified guardian identity/relationship mapping. | School administration | Guardian resolver | Yes |
| MIN-006 | Existing Oracle values versus family corrections | Field-specific acceptance and conflict decisions need school policy. | OLAMA data steward | Review workflow | Yes for disputed fields |
| MIN-007 | Classroom number | The enrollment row has grade and section, but no verified classroom number source. | School administration | School-owned readiness | Yes |
| MIN-008 | Student identifier on page 2 | The workbook does not establish that OLAMA's internal `student_uid` is the Ministry identifier. | Ministry-form owner | Identifier resolver | Yes |
| MIN-009 | Building configuration scope | School administration confirmed building number `1005` applies to both school IDs 1 and 2. No evidence indicates multiple buildings under either ID. | School administration | Building resolver | No |
| MIN-010 | Direct family write authority | No field is assigned `DIRECT_IF_MISSING` until the school confirms which family statements can immediately become accepted Ministry values. Current submissions require review. | OLAMA data steward | Write policy and family workflow | Yes for direct-write behavior |

The implementation must keep fields with blocking decisions at a safe
configuration or review boundary. No placeholder may be counted as a Ministry
value. Change this contract whenever a decision is resolved; record the source
and version of the evidence.
