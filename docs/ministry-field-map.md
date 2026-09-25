# Semantic Ministry field map

Source workbook SHA-256: `1687433c5a77408feb903ab11df18a95176819477083676e553d9e51e9be2837`. Literal workbook labels and repeated output positions are in `ministry-workbook-columns.csv`. Source means current OLAMA resolver; a blank source means no verified current source. `READ_ONLY` for unresolved fields is a safe boundary, not a claim that families can never provide them.

| Key | Ministry label | Page / position | Domain | Current source | Owner | Workbook requirement / applicability | Family input | Write policy | Sensitive | Storage if missing |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| student_identifier | الرقم التعريفي للطالب | 2 / S | SCHOOL | Unverified | School | Starred/group required | No | READ_ONLY | Yes | School or academic resolver |
| national_id | الرقم الوطني للطالب | 1 / B | CORE_IDENTITY | student.student_national_no | Core | Conditional | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review |
| document_type | نوع الوثيقة لغير الأردني | 1 / C | CORE_IDENTITY | Unverified | Core | Conditional, non-Jordanians | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review; choices: جواز سفر، هوية |
| civil_register | رقم القيد للطالب الأردني | 1 / D | CORE_IDENTITY | Unverified | Core | Conditional | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review |
| first_name | الاسم الأول | 1 / E | CORE_IDENTITY | Unverified | Core | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| father_name | اسم الأب | 1 / F | CORE_IDENTITY | Unverified | Core | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| grandfather_name | اسم الجد | 1 / G | CORE_IDENTITY | Unverified | Core | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| family_name | اسم العائلة | 1 / H | CORE_IDENTITY | Unverified | Core | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| birth_place | مكان الولادة | 1 / I | CORE_IDENTITY | student.birth_place | Core | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| birth_date | تاريخ الميلاد | 1 / J | CORE_IDENTITY | student.birth_date | Core | Starred/group required | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review |
| nationality | الجنسية | 1 / K | CORE_IDENTITY | student.nationality | Core | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| gender | الجنس | 1 / L | CORE_IDENTITY | student.student_gender_name | Core | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| governorate | المحافظة | 1 / M | RESIDENCE | Unverified | Family / school review | Starred/group required | No | READ_ONLY | No | School or academic resolver |
| district | اللواء | 1 / N | RESIDENCE | Unverified | Family / school review | Starred/group required | No | READ_ONLY | No | School or academic resolver |
| subdistrict | القضاء | 1 / O | RESIDENCE | Unverified | Family / school review | Starred/group required | No | READ_ONLY | No | School or academic resolver |
| locality | التجمع | 1 / P | RESIDENCE | Unverified | Family / school review | Starred/group required | No | READ_ONLY | No | School or academic resolver |
| neighborhood | الحي | 1 / Q | RESIDENCE | Unverified | Family / school review | Starred/group required | No | READ_ONLY | No | School or academic resolver |
| student_display_name | اسم الطالب (الأول والعائلة) | 2 / T | DERIVED | derived.name | Core | Starred/group required | No | SYSTEM_DERIVED | No | School or academic resolver |
| social_status | الحالة الاجتماعية | 2 / U | FAMILY_PROFILE | Unverified | Family / school review | Starred/group required | No | READ_ONLY | Yes | School or academic resolver |
| mother_name | اسم الأم | 2 / V | FAMILY_PROFILE | student.mother_name | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| study_type | نوع الدراسة | 2 / W | ACADEMIC | Unverified | School | Starred/group required | No | ACADEMIC_ONLY | No | School or academic resolver |
| father_education | المستوى التعليمي للأب | 2 / X | FAMILY_PROFILE | Unverified | Family / school review | Starred/group required | No | READ_ONLY | No | School or academic resolver |
| mother_education | المستوى التعليمي للأم | 2 / Y | FAMILY_PROFILE | Unverified | Family / school review | Starred/group required | No | READ_ONLY | No | School or academic resolver |
| guardian_name | اسم ولي الأمر | 2 / Z | GUARDIAN | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review |
| guardian_id | الرقم الوطني / الشخصي لولي الأمر | 2 / AA | GUARDIAN | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review |
| guardian_relation | علاقة ولي الأمر بالطالب | 2 / AB | GUARDIAN | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| guardian_job | عمل ولي الأمر | 2 / AC | GUARDIAN | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| family_size | عدد أفراد الأسرة مع الوالدين | 2 / AD | FAMILY_PROFILE | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| sibling_order | ترتيب الطالب بين إخوته | 2 / AE | FAMILY_PROFILE | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | No | Accepted supplementary value after review |
| health_status | الوضع الصحي للطالب | 2 / AF | SENSITIVE_FAMILY_DATA | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review |
| academic_status | الوضع الدراسي للطالب | 2 / AG | ACADEMIC | Unverified | School | Starred/group required | No | ACADEMIC_ONLY | No | School or academic resolver |
| external_aid | نوع المساعدة الخارجية | 2 / AH | SENSITIVE_FAMILY_DATA | Unverified | Family / school review | Starred/group required | No | READ_ONLY | Yes | School or academic resolver |
| monthly_income | دخل الأسرة الشهري | 2 / AI | SENSITIVE_FAMILY_DATA | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review |
| religion | الديانة | 2 / AJ | SENSITIVE_FAMILY_DATA | Unverified | Family / school review | Starred/group required | No | READ_ONLY | Yes | School or academic resolver |
| refugee_card | صفة بطاقة الغوث الدولية | 2 / AK | SENSITIVE_FAMILY_DATA | Unverified | Family / school review | Starred/group required | No | READ_ONLY | Yes | School or academic resolver |
| guardian_phone | هاتف ولي أمر الطالب | 2 / AL | GUARDIAN | Unverified | Family / school review | Starred/group required | Proposal / correction | REVIEW_REQUIRED | Yes | Accepted supplementary value after review |
| school_name | اسم المدرسة | 1 / A2 | SCHOOL | enrollment.school_name | School | Starred/group required | No | READ_ONLY | No | School or academic resolver |
| school_national_id | الرقم الوطني للمدرسة | 1 / A3 | SCHOOL | config.school_national_id | School | Starred/group required | No | READ_ONLY | No | Core config |
| building_national_id | الرقم الوطني للبناء | 1 / A4 | SCHOOL | config.building_national_id | School | Starred/group required | No | READ_ONLY | No | Core config |
| grade | الصف | 1 / J3 | ACADEMIC | enrollment.class_name | School | Starred/group required | No | ACADEMIC_ONLY | No | School or academic resolver |
| section | الشعبة | 1 / N3 | ACADEMIC | enrollment.section_name | School | Starred/group required | No | ACADEMIC_ONLY | No | School or academic resolver |
| education_branch | الفرع التعليمي | 1 / M2 | ACADEMIC | enrollment.branch_name | School | Unresolved | No | ACADEMIC_ONLY | No | School or academic resolver |
| classroom | رقم الغرفة الصفية | 1 / M4 | SCHOOL | config.classroom | School | Unresolved | No | READ_ONLY | No | Core config |

Validation: dates use ISO dates; family size and sibling order use positive integers. Non-Jordanian document type choices are limited to `جواز سفر` and `هوية` per school-provided Ministry guidance. Other controlled vocabularies remain blocked or review-only until MIN-003 is resolved. Export formatters remain tied to the literal workbook positions and must be finalized before enabling export.
