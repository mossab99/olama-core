<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One resolver and completion evaluator for Ministry student data.
 * Oracle-backed rows are never changed by family submissions.
 */
final class Olama_Core_Ministry_Service {
    private $container;
    private $preloaded = array();

    public function __construct(Olama_Core_Container $container) {
        $this->container = $container;
    }

    private function field($label, $page, $column, $domain, $source, $policy, $sensitive = false, $applicability = 'required') {
        return compact('label', 'page', 'column', 'domain', 'source', 'policy', 'sensitive', 'applicability');
    }

    public function fields() {
        return array(
            'student_identifier' => $this->field('الرقم التعريفي للطالب', 2, 'S', 'SCHOOL', '', 'READ_ONLY', true),
            'national_id' => $this->field('الرقم الوطني للطالب', 1, 'B', 'CORE_IDENTITY', 'student.student_national_no', 'REVIEW_REQUIRED', true, 'conditional'),
            'document_type' => $this->field('نوع الوثيقة لغير الأردني', 1, 'C', 'CORE_IDENTITY', '', 'REVIEW_REQUIRED', true, 'conditional'),
            'civil_register' => $this->field('رقم القيد للطالب الأردني', 1, 'D', 'CORE_IDENTITY', '', 'REVIEW_REQUIRED', true, 'conditional'),
            'first_name' => $this->field('الاسم الأول', 1, 'E', 'CORE_IDENTITY', '', 'REVIEW_REQUIRED'),
            'father_name' => $this->field('اسم الأب', 1, 'F', 'CORE_IDENTITY', '', 'REVIEW_REQUIRED'),
            'grandfather_name' => $this->field('اسم الجد', 1, 'G', 'CORE_IDENTITY', '', 'REVIEW_REQUIRED'),
            'family_name' => $this->field('اسم العائلة', 1, 'H', 'CORE_IDENTITY', '', 'REVIEW_REQUIRED'),
            'birth_place' => $this->field('مكان الولادة', 1, 'I', 'CORE_IDENTITY', 'student.birth_place', 'REVIEW_REQUIRED'),
            'birth_date' => $this->field('تاريخ الميلاد', 1, 'J', 'CORE_IDENTITY', 'student.birth_date', 'REVIEW_REQUIRED', true),
            'nationality' => $this->field('الجنسية', 1, 'K', 'CORE_IDENTITY', 'student.nationality', 'REVIEW_REQUIRED'),
            'gender' => $this->field('الجنس', 1, 'L', 'CORE_IDENTITY', 'student.student_gender_name', 'REVIEW_REQUIRED'),
            'governorate' => $this->field('المحافظة', 1, 'M', 'RESIDENCE', '', 'READ_ONLY'),
            'district' => $this->field('اللواء', 1, 'N', 'RESIDENCE', '', 'READ_ONLY'),
            'subdistrict' => $this->field('القضاء', 1, 'O', 'RESIDENCE', '', 'READ_ONLY'),
            'locality' => $this->field('التجمع', 1, 'P', 'RESIDENCE', '', 'READ_ONLY'),
            'neighborhood' => $this->field('الحي', 1, 'Q', 'RESIDENCE', '', 'READ_ONLY'),
            'student_display_name' => $this->field('اسم الطالب (الأول والعائلة)', 2, 'T', 'DERIVED', 'derived.name', 'SYSTEM_DERIVED'),
            'social_status' => $this->field('الحالة الاجتماعية', 2, 'U', 'FAMILY_PROFILE', '', 'READ_ONLY', true),
            'mother_name' => $this->field('اسم الأم', 2, 'V', 'FAMILY_PROFILE', 'student.mother_name', 'REVIEW_REQUIRED'),
            'study_type' => $this->field('نوع الدراسة', 2, 'W', 'ACADEMIC', '', 'ACADEMIC_ONLY'),
            'father_education' => $this->field('المستوى التعليمي للأب', 2, 'X', 'FAMILY_PROFILE', '', 'READ_ONLY'),
            'mother_education' => $this->field('المستوى التعليمي للأم', 2, 'Y', 'FAMILY_PROFILE', '', 'READ_ONLY'),
            'guardian_name' => $this->field('اسم ولي الأمر', 2, 'Z', 'GUARDIAN', '', 'REVIEW_REQUIRED', true),
            'guardian_id' => $this->field('الرقم الوطني / الشخصي لولي الأمر', 2, 'AA', 'GUARDIAN', '', 'REVIEW_REQUIRED', true),
            'guardian_relation' => $this->field('علاقة ولي الأمر بالطالب', 2, 'AB', 'GUARDIAN', '', 'REVIEW_REQUIRED'),
            'guardian_job' => $this->field('عمل ولي الأمر', 2, 'AC', 'GUARDIAN', '', 'REVIEW_REQUIRED'),
            'family_size' => $this->field('عدد أفراد الأسرة مع الوالدين', 2, 'AD', 'FAMILY_PROFILE', '', 'REVIEW_REQUIRED'),
            'sibling_order' => $this->field('ترتيب الطالب بين إخوته', 2, 'AE', 'FAMILY_PROFILE', '', 'REVIEW_REQUIRED'),
            'health_status' => $this->field('الوضع الصحي للطالب', 2, 'AF', 'SENSITIVE_FAMILY_DATA', '', 'REVIEW_REQUIRED', true),
            'academic_status' => $this->field('الوضع الدراسي للطالب', 2, 'AG', 'ACADEMIC', '', 'ACADEMIC_ONLY'),
            'external_aid' => $this->field('نوع المساعدة الخارجية', 2, 'AH', 'SENSITIVE_FAMILY_DATA', '', 'READ_ONLY', true),
            'monthly_income' => $this->field('دخل الأسرة الشهري', 2, 'AI', 'SENSITIVE_FAMILY_DATA', '', 'REVIEW_REQUIRED', true),
            'religion' => $this->field('الديانة', 2, 'AJ', 'SENSITIVE_FAMILY_DATA', '', 'READ_ONLY', true),
            'refugee_card' => $this->field('صفة بطاقة الغوث الدولية', 2, 'AK', 'SENSITIVE_FAMILY_DATA', '', 'READ_ONLY', true),
            'guardian_phone' => $this->field('هاتف ولي أمر الطالب', 2, 'AL', 'GUARDIAN', '', 'REVIEW_REQUIRED', true),
            'school_name' => $this->field('اسم المدرسة', 1, 'A2', 'SCHOOL', 'enrollment.school_name', 'READ_ONLY'),
            'school_national_id' => $this->field('الرقم الوطني للمدرسة', 1, 'A3', 'SCHOOL', 'config.school_national_id', 'READ_ONLY'),
            'building_national_id' => $this->field('الرقم الوطني للبناء', 1, 'A4', 'SCHOOL', 'config.building_national_id', 'READ_ONLY'),
            'grade' => $this->field('الصف', 1, 'J3', 'ACADEMIC', 'enrollment.class_name', 'ACADEMIC_ONLY'),
            'section' => $this->field('الشعبة', 1, 'N3', 'ACADEMIC', 'enrollment.section_name', 'ACADEMIC_ONLY'),
            'education_branch' => $this->field('الفرع التعليمي', 1, 'M2', 'ACADEMIC', 'enrollment.branch_name', 'ACADEMIC_ONLY'),
            'classroom' => $this->field('رقم الغرفة الصفية', 1, 'M4', 'SCHOOL', 'config.classroom', 'READ_ONLY'),
        );
    }

    public function allowed_values($field_key) {
        $known = array(
            'health_status' => array('سليم', 'غير سليم'),
            'document_type' => array('جواز سفر', 'هوية'),
        );
        return isset($known[$field_key]) ? $known[$field_key] : array();
    }

    public function school_config($school_id) {
        $config = get_option('olama_ministry_school_config', array());
        return is_array($config) && isset($config[$school_id]) && is_array($config[$school_id])
            ? $config[$school_id] : array();
    }

    public function save_school_config($school_id, $school_national_id, $building_national_id) {
        $school_id = sanitize_text_field((string) $school_id);
        if ($school_id === '') return new WP_Error('missing_school', 'Select a school.');
        $entry = array(
            'school_national_id' => sanitize_text_field((string) $school_national_id),
            'building_national_id' => sanitize_text_field((string) $building_national_id),
        );
        if (strlen($entry['school_national_id']) > 50 || strlen($entry['building_national_id']) > 50) {
            return new WP_Error('invalid_school_config', 'School identifiers are too long.');
        }
        $config = get_option('olama_ministry_school_config', array());
        if (!is_array($config)) $config = array();
        $config[$school_id] = $entry;
        update_option('olama_ministry_school_config', $config, false);
        return true;
    }

    public function schools($study_year) {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_student_years';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT school_id, school_name FROM {$table} WHERE study_year = %s AND school_id IS NOT NULL AND school_id <> '' ORDER BY school_name",
            $study_year
        ), ARRAY_A);
    }

    public function study_years() {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_student_years';
        return $wpdb->get_col("SELECT DISTINCT study_year FROM {$table} WHERE study_year <> '' ORDER BY study_year DESC");
    }

    public function evaluate($student_uid, $study_year) {
        $cached = isset($this->preloaded[$student_uid]) ? $this->preloaded[$student_uid] : null;
        $student = $cached ? $cached['student'] : $this->container->students()->get_by_uid($student_uid);
        $enrollment = $cached ? $cached['enrollment'] : $this->container->student_years()->get_current_year($student_uid, $study_year);
        $active = $enrollment && in_array(strtoupper((string) $enrollment['student_status']), array('1', 'ACTIVE'), true);
        if (!$student || !$enrollment || (string) $enrollment['study_year'] !== (string) $study_year || !$active) {
            return new WP_Error('ministry_ineligible', __('No canonical enrollment for this academic year.', 'olama-core'));
        }
        $family = $cached ? $cached['family'] : $this->container->families()->get_by_uid($student['family_uid']);
        $accepted = $this->accepted_values($student_uid, $study_year);
        $pending = $this->pending_values($student_uid, $study_year);
        $drafts = $this->draft_values($student_uid, $study_year);
        $result = array('student_uid' => $student_uid, 'study_year' => $study_year, 'fields' => array(),
            'required_fields' => 0, 'resolved_fields' => 0, 'family_missing' => array(),
            'school_missing' => array(), 'review_required' => array(), 'operational_review' => array(), 'is_complete' => false,
            'workflow' => array('has_draft' => !empty($drafts), 'family_submission_pending' => !empty($pending),
                'correction_open' => false));
        foreach ($this->fields() as $key => $definition) {
            $applicable = $this->applicable($key, $student);
            $nationality_class = $this->jordanian_status($student);
            $identity_uncertain = ($key === 'national_id' && $nationality_class === null) ||
                (in_array($key, array('document_type', 'civil_register'), true) && $nationality_class === null);
            $value = $this->source_value($definition['source'], $student, $family, $enrollment);
            $source = $value !== '' ? $definition['source'] : '';
            $conflict = false;
            if (isset($accepted[$key])) {
                $recorded_source = (string) $accepted[$key]['original_value'];
                $conflict = $value !== $recorded_source && $value !== (string) $accepted[$key]['value'];
                if (!$conflict) {
                    $value = (string) $accepted[$key]['value'];
                    $source = (string) $accepted[$key]['source'];
                }
            }
            if ($key === 'student_display_name') {
                $first = isset($result['fields']['first_name']['value']) ? $result['fields']['first_name']['value'] : '';
                $last = isset($result['fields']['family_name']['value']) ? $result['fields']['family_name']['value'] : '';
                $value = $first !== '' && $last !== '' ? $first . ' ' . $last : '';
                $source = $value !== '' ? 'derived.name' : '';
            }
            $has_pending = isset($pending[$key]);
            $blocking_pending = $has_pending && $pending[$key] !== 'NON_BLOCKING';
            if ($has_pending && $value !== '') $result['workflow']['correction_open'] = true;
            $effective_policy = $value !== '' && $definition['domain'] === 'CORE_IDENTITY' &&
                $definition['policy'] === 'REVIEW_REQUIRED' ? 'CORRECTION_REQUEST_ONLY' : $definition['policy'];
            if ($identity_uncertain) $effective_policy = 'READ_ONLY';
            if (!$applicable) {
                $state = 'NOT_APPLICABLE';
            } elseif ($blocking_pending || $conflict) {
                $state = 'NEEDS_REVIEW';
            } elseif ($identity_uncertain) {
                $state = 'NEEDS_SCHOOL';
            } elseif ($value !== '') {
                $state = 'AVAILABLE';
            } else {
                $state = in_array($definition['policy'], array('READ_ONLY', 'ACADEMIC_ONLY', 'SYSTEM_DERIVED'), true)
                    ? 'NEEDS_SCHOOL' : 'NEEDS_FAMILY';
            }
            if ($applicable) {
                $result['required_fields']++;
                if ($state === 'AVAILABLE') $result['resolved_fields']++;
                if ($state === 'NEEDS_REVIEW') $result['review_required'][] = $key;
                if ($has_pending && !$blocking_pending) $result['operational_review'][] = $key;
                if ($state === 'NEEDS_SCHOOL') $result['school_missing'][] = $key;
                if ($state === 'NEEDS_FAMILY') $result['family_missing'][] = $key;
            }
            $result['fields'][$key] = array(
                'label' => $definition['label'], 'value' => $value, 'source' => $source,
                'status' => $state, 'policy' => $effective_policy,
                'sensitive' => $definition['sensitive'], 'domain' => $definition['domain'],
                'applicable' => $applicable, 'has_pending' => $has_pending,
                'readiness_impact' => $has_pending ? $pending[$key] : '',
                'draft_value' => isset($drafts[$key]) ? $drafts[$key] : '',
            );
        }
        $result['completion_percentage'] = $result['required_fields']
            ? round(100 * $result['resolved_fields'] / $result['required_fields'], 1) : 0;
        $result['is_complete'] = $result['required_fields'] > 0 &&
            $result['required_fields'] === $result['resolved_fields'];
        $result['needs_family'] = !empty($result['family_missing']);
        $result['needs_school'] = !empty($result['school_missing']);
        $result['needs_review'] = !empty($result['review_required']);
        $result['primary_status'] = $result['review_required'] ? 'NEEDS_REVIEW' :
            ($result['school_missing'] ? 'NEEDS_SCHOOL' :
            ($result['family_missing'] ? 'NEEDS_FAMILY' : 'COMPLETE'));
        return $result;
    }

    public function population($study_year, $grade = '', $section = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_student_years';
        $sql = "SELECT DISTINCT student_uid FROM {$table} WHERE study_year = %s AND (student_status = '1' OR UPPER(student_status) = 'ACTIVE')";
        $args = array($study_year);
        if ($grade !== '') {
            $sql .= ' AND class_id = %s';
            $args[] = $grade;
        }
        if ($section !== '') {
            $sql .= ' AND section_id = %s';
            $args[] = $section;
        }
        $uids = $wpdb->get_col($wpdb->prepare($sql, $args));
        $this->preload($uids, $study_year);
        $summary = array('TOTAL' => 0, 'COMPLETE' => 0, 'NEEDS_FAMILY' => 0, 'NEEDS_SCHOOL' => 0, 'NEEDS_REVIEW' => 0);
        $causes = array('family' => 0, 'school' => 0, 'review' => 0);
        $students = array();
        $grades = array();
        $missing_fields = array();
        $families = array();
        foreach ((array) $uids as $uid) {
            $evaluation = $this->evaluate($uid, $study_year);
            if (is_wp_error($evaluation)) continue;
            $record = isset($this->preloaded[$uid]) ? $this->preloaded[$uid]['student'] : null;
            $enrollment = isset($this->preloaded[$uid]) ? $this->preloaded[$uid]['enrollment'] : null;
            $summary['TOTAL']++;
            $summary[$evaluation['primary_status']]++;
            if ($evaluation['needs_family']) $causes['family']++;
            if ($evaluation['needs_school']) $causes['school']++;
            if ($evaluation['needs_review']) $causes['review']++;
            $grade_key = $enrollment && $enrollment['class_id'] !== '' ? $enrollment['class_id'] : 'unknown';
            if (!isset($grades[$grade_key])) $grades[$grade_key] = array('name' => $enrollment && $enrollment['class_name'] !== '' ? $enrollment['class_name'] : 'غير محدد',
                'TOTAL' => 0, 'COMPLETE' => 0, 'NEEDS_FAMILY' => 0, 'NEEDS_SCHOOL' => 0, 'NEEDS_REVIEW' => 0);
            $grades[$grade_key]['TOTAL']++;
            $grades[$grade_key][$evaluation['primary_status']]++;
            foreach (array_merge($evaluation['family_missing'], $evaluation['school_missing'], $evaluation['review_required']) as $field_key) {
                if (!isset($missing_fields[$field_key])) $missing_fields[$field_key] = 0;
                $missing_fields[$field_key]++;
            }
            if ($record && $record['family_uid'] !== '') {
                if (!isset($families[$record['family_uid']])) $families[$record['family_uid']] = array('TOTAL' => 0, 'INCOMPLETE' => 0);
                $families[$record['family_uid']]['TOTAL']++;
                if ($evaluation['primary_status'] !== 'COMPLETE') $families[$record['family_uid']]['INCOMPLETE']++;
            }
            $students[] = array(
                'student_uid' => $uid,
                'name' => $record ? $record['student_name'] : $uid,
                'family_uid' => $record ? $record['family_uid'] : '',
                'grade' => $enrollment ? $enrollment['class_name'] : '',
                'section' => $enrollment ? $enrollment['section_name'] : '',
                'status' => $evaluation['primary_status'],
                'missing' => count($evaluation['family_missing']) + count($evaluation['school_missing']) + count($evaluation['review_required']),
                'issues' => array_values(array_unique(array_merge($evaluation['family_missing'], $evaluation['school_missing'], $evaluation['review_required']))),
            );
        }
        arsort($missing_fields);
        $this->preloaded = array();
        return array('summary' => $summary, 'causes' => $causes, 'students' => $students, 'grades' => $grades,
            'missing_fields' => $missing_fields, 'families' => $families);
    }

    private function preload($uids, $study_year) {
        global $wpdb;
        $this->preloaded = array();
        foreach (array_chunk((array) $uids, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $students = $wpdb->prefix . 'olama_core_students';
            $years = $wpdb->prefix . 'olama_core_student_years';
            $values = $wpdb->prefix . 'olama_core_ministry_values';
            $submissions = $wpdb->prefix . 'olama_core_ministry_submissions';
            $student_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT student_uid, family_uid, student_name, student_national_no, nationality, birth_place, birth_date, student_gender_name, mother_name
                 FROM {$students} WHERE student_uid IN ({$placeholders})", $chunk
            ), ARRAY_A);
            $year_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT student_uid, study_year, student_status, school_id, school_name, class_id, class_name, section_id, section_name, branch_name
                 FROM {$years} WHERE study_year = %s AND student_uid IN ({$placeholders})",
                array_merge(array($study_year), $chunk)
            ), ARRAY_A);
            foreach ((array) $student_rows as $row) {
                $this->preloaded[$row['student_uid']] = array(
                    'student' => $row, 'enrollment' => null, 'family' => null,
                    'accepted' => array(), 'pending' => array(), 'drafts' => array(),
                );
            }
            foreach ((array) $year_rows as $row) {
                if (isset($this->preloaded[$row['student_uid']])) $this->preloaded[$row['student_uid']]['enrollment'] = $row;
            }
            $family_uids = array_values(array_unique(array_column((array) $student_rows, 'family_uid')));
            if ($family_uids) {
                $families = $wpdb->prefix . 'olama_core_families';
                $family_placeholders = implode(',', array_fill(0, count($family_uids), '%s'));
                $family_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT family_uid FROM {$families} WHERE family_uid IN ({$family_placeholders})", $family_uids
                ), ARRAY_A);
                $family_map = array();
                foreach ((array) $family_rows as $row) $family_map[$row['family_uid']] = $row;
                foreach ((array) $student_rows as $row) {
                    $this->preloaded[$row['student_uid']]['family'] = isset($family_map[$row['family_uid']]) ? $family_map[$row['family_uid']] : null;
                }
            }
            $value_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT student_uid, field_key, value, source, original_value FROM {$values} WHERE study_year = %s AND student_uid IN ({$placeholders})",
                array_merge(array($study_year), $chunk)
            ), ARRAY_A);
            foreach ((array) $value_rows as $row) {
                if (isset($this->preloaded[$row['student_uid']])) $this->preloaded[$row['student_uid']]['accepted'][$row['field_key']] = $row;
            }
            $submission_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT student_uid, field_key, status, readiness_impact, proposed_value FROM {$submissions} WHERE study_year = %s AND status IN ('PENDING','DRAFT') AND student_uid IN ({$placeholders}) ORDER BY id ASC",
                array_merge(array($study_year), $chunk)
            ), ARRAY_A);
            foreach ((array) $submission_rows as $row) {
                if (!isset($this->preloaded[$row['student_uid']])) continue;
                if ($row['status'] === 'PENDING') $this->preloaded[$row['student_uid']]['pending'][$row['field_key']] = $row['readiness_impact'];
                else $this->preloaded[$row['student_uid']]['drafts'][$row['field_key']] = $row['proposed_value'];
            }
        }
    }

    public function pending_submissions($study_year) {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_ministry_submissions';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, student_uid, field_key, current_value, proposed_value, status, readiness_impact, submitted_at FROM {$table} WHERE study_year = %s AND status = 'PENDING' ORDER BY submitted_at ASC LIMIT 200",
            $study_year
        ), ARRAY_A);
    }

    private function jordanian_status($student) {
        $nationality = trim((string) $student['nationality']);
        if ($nationality === '') return null;
        return in_array($nationality, array('أردني', 'أردنية', 'اردني', 'اردنية', 'الأردن', 'الأردنية', 'Jordanian', 'Jordan', 'JOR'), true);
    }

    private function applicable($key, $student) {
        if (!in_array($key, array('national_id', 'document_type', 'civil_register'), true)) return true;
        $jordanian = $this->jordanian_status($student);
        if ($jordanian === null || $key === 'national_id') return true;
        return $key === 'document_type' ? !$jordanian : $jordanian;
    }

    private function source_value($source, $student, $family, $enrollment) {
        if ($source === 'derived.name') return '';
        if (strpos($source, 'config.') === 0) {
            $config = $this->school_config(isset($enrollment['school_id']) ? (string) $enrollment['school_id'] : '');
            return isset($config[substr($source, 7)]) ? trim((string) $config[substr($source, 7)]) : '';
        }
        $parts = explode('.', $source, 2);
        $record = isset($parts[0]) && $parts[0] === 'student' ? $student :
            (isset($parts[0]) && $parts[0] === 'family' ? $family : $enrollment);
        return isset($parts[1], $record[$parts[1]]) ? trim((string) $record[$parts[1]]) : '';
    }

    private function accepted_values($uid, $study_year) {
        if (isset($this->preloaded[$uid])) return $this->preloaded[$uid]['accepted'];
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_ministry_values';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT field_key, value, source, original_value FROM {$table} WHERE student_uid = %s AND study_year = %s", $uid, $study_year), ARRAY_A);
        $values = array();
        foreach ((array) $rows as $row) $values[$row['field_key']] = $row;
        return $values;
    }

    private function pending_values($uid, $study_year) {
        if (isset($this->preloaded[$uid])) return $this->preloaded[$uid]['pending'];
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_ministry_submissions';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT field_key, readiness_impact FROM {$table} WHERE student_uid = %s AND study_year = %s AND status = 'PENDING'", $uid, $study_year), ARRAY_A);
        $values = array();
        foreach ((array) $rows as $row) $values[$row['field_key']] = $row['readiness_impact'];
        return $values;
    }

    private function draft_values($uid, $study_year) {
        if (isset($this->preloaded[$uid])) return $this->preloaded[$uid]['drafts'];
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_ministry_submissions';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT field_key, proposed_value FROM {$table} WHERE student_uid = %s AND study_year = %s AND status = 'DRAFT' ORDER BY id ASC",
            $uid, $study_year
        ), ARRAY_A);
        $values = array();
        foreach ((array) $rows as $row) $values[$row['field_key']] = $row['proposed_value'];
        return $values;
    }

    public function submit($student_uid, $study_year, $field_key, $proposed_value, $user_id, $draft = false) {
        global $wpdb;
        $fields = $this->fields();
        if (!isset($fields[$field_key])) return new WP_Error('invalid_field', 'Unknown Ministry field.');
        $evaluation = $this->evaluate($student_uid, $study_year);
        if (is_wp_error($evaluation)) return $evaluation;
        $field = $evaluation['fields'][$field_key];
        if (!$field['applicable'] || in_array($field['policy'], array('READ_ONLY', 'ACADEMIC_ONLY', 'SYSTEM_DERIVED'), true)) {
            return new WP_Error('field_read_only', 'This field cannot be supplied by a family.');
        }
        if ($field['has_pending']) return new WP_Error('already_pending', 'A submission for this field is already awaiting review.');
        if (!is_scalar($proposed_value)) return new WP_Error('invalid_value', 'Enter a valid value.');
        $value = sanitize_text_field((string) $proposed_value);
        if ($value === '' || strlen($value) > 500) return new WP_Error('invalid_value', 'Enter a valid value.');
        if ($field_key === 'birth_date') {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date_parts) ||
                !checkdate((int) $date_parts[2], (int) $date_parts[3], (int) $date_parts[1])) {
                return new WP_Error('invalid_date', 'Use a valid YYYY-MM-DD date.');
            }
        }
        if (in_array($field_key, array('family_size', 'sibling_order'), true) &&
            (!ctype_digit($value) || (int) $value < 1 || (int) $value > 50)) {
            return new WP_Error('invalid_number', 'Enter a number between 1 and 50.');
        }
        if ($field_key === 'monthly_income' && !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $value)) {
            return new WP_Error('invalid_income', 'Enter a non-negative amount with up to three decimal places.');
        }
        $allowed = $this->allowed_values($field_key);
        if ($allowed && !in_array($value, $allowed, true)) {
            return new WP_Error('invalid_choice', 'Select an allowed value.');
        }
        $table = $wpdb->prefix . 'olama_core_ministry_submissions';
        $inserted = $wpdb->insert($table, array(
            'student_uid' => $student_uid, 'study_year' => $study_year,
            'field_key' => $field_key, 'current_value' => $field['value'],
            'proposed_value' => $value, 'submitted_by' => (int) $user_id,
            'status' => $draft ? 'DRAFT' : 'PENDING', 'readiness_impact' => 'BLOCKING',
            'submitted_at' => current_time('mysql'),
        ));
        $submission_id = $inserted ? (int) $wpdb->insert_id : 0;
        if ($inserted && !$draft) {
            $wpdb->delete($table, array(
                'student_uid' => $student_uid, 'study_year' => $study_year,
                'field_key' => $field_key, 'status' => 'DRAFT',
                'submitted_by' => (int) $user_id,
            ));
        }
        return $inserted ? $submission_id : new WP_Error('save_failed', 'Submission could not be saved.');
    }

    public function review($submission_id, $approve, $reviewer_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_ministry_submissions';
        $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $submission_id), ARRAY_A);
        if (!$submission || $submission['status'] !== 'PENDING') return new WP_Error('invalid_submission', 'Submission is no longer pending.');
        $definitions = $this->fields();
        if (!isset($definitions[$submission['field_key']]) ||
            in_array($definitions[$submission['field_key']]['policy'], array('READ_ONLY', 'ACADEMIC_ONLY', 'SYSTEM_DERIVED'), true)) {
            return new WP_Error('policy_changed', 'This field is no longer open for family submissions.');
        }
        if ($approve) {
            $current = $this->evaluate($submission['student_uid'], $submission['study_year']);
            if (is_wp_error($current) || !isset($current['fields'][$submission['field_key']]) ||
                $current['fields'][$submission['field_key']]['value'] !== (string) $submission['current_value']) {
                return new WP_Error('source_changed', 'The current source value changed. Review the conflict before approval.');
            }
            $student = $this->container->students()->get_by_uid($submission['student_uid']);
            $enrollment = $this->container->student_years()->get_current_year($submission['student_uid'], $submission['study_year']);
            $family = $student ? $this->container->families()->get_by_uid($student['family_uid']) : null;
            $raw_source = $this->source_value($definitions[$submission['field_key']]['source'], $student, $family, $enrollment);
            $values = $wpdb->prefix . 'olama_core_ministry_values';
            $replaced = $wpdb->replace($values, array(
                'student_uid' => $submission['student_uid'], 'study_year' => $submission['study_year'],
                'field_key' => $submission['field_key'],
                'value' => $submission['proposed_value'], 'source' => 'approved_family',
                'original_value' => $raw_source,
                'submission_id' => (int) $submission_id,
                'submitted_by' => $submission['submitted_by'], 'reviewed_by' => (int) $reviewer_id,
                'submitted_at' => $submission['submitted_at'],
                'reviewed_at' => current_time('mysql'),
            ));
            if (!$replaced) return new WP_Error('approval_failed', 'The approved value could not be stored.');
        }
        $updated = $wpdb->update($table, array(
            'status' => $approve ? 'APPROVED' : 'REJECTED',
            'reviewed_by' => (int) $reviewer_id, 'reviewed_at' => current_time('mysql'),
        ), array('id' => (int) $submission_id));
        return $updated === false ? new WP_Error('review_failed', 'Review status could not be saved.') : true;
    }
}
