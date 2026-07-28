<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whitelisted read-model contract for cross-plugin reporting joins.
 *
 * Consumers may read these models but must never construct private Core table
 * names themselves or write to a model returned here.
 */
class Olama_Core_Read_Model_Service {
    private $repo;

    private const MODELS = array(
        'families' => 'olama_core_families',
        'students' => 'olama_core_students',
        'student_years' => 'olama_core_student_years',
        'employees' => 'olama_core_employees',
        'staff_profiles' => 'olama_core_staff_profiles',
        'academic_grades' => 'olama_core_academic_grades',
        'academic_sections' => 'olama_core_academic_sections',
        'academic_grade_sections' => 'olama_core_academic_grade_sections',
        'academic_students' => 'olama_core_academic_students',
        'academic_grade_subjects' => 'olama_core_academic_grade_subjects',
    );

    public function __construct(Olama_Core_Repository $repo) {
        $this->repo = $repo;
    }

    public function table($model) {
        $model = sanitize_key((string) $model);
        if (!isset(self::MODELS[$model])) {
            throw new InvalidArgumentException('Unknown Olama Core read model: ' . $model);
        }
        return $this->repo->table(self::MODELS[$model]);
    }

    public function available($model) {
        try {
            return Olama_Core_Migrator::table_exists($this->table($model));
        } catch (InvalidArgumentException $exception) {
            return false;
        }
    }

    public function revision(array $models, $study_year = '') {
        global $wpdb;

        $revision = array();
        foreach ($models as $model) {
            $model = sanitize_key((string) $model);
            if (!$this->available($model)) {
                $revision[$model] = array('row_count' => 0, 'synced_at' => null);
                continue;
            }
            $table = $this->table($model);
            $year_scoped = in_array($model, array('student_years', 'academic_grade_sections', 'academic_students', 'academic_grade_subjects'), true);
            if ($year_scoped && '' !== (string) $study_year) {
                $revision[$model] = $wpdb->get_row($wpdb->prepare(
                    'SELECT COUNT(*) AS row_count, MAX(last_synced_at) AS synced_at FROM `' . esc_sql($table) . '` WHERE study_year=%s',
                    sanitize_text_field((string) $study_year)
                ), ARRAY_A);
            } else {
                $revision[$model] = $wpdb->get_row(
                    'SELECT COUNT(*) AS row_count, MAX(last_synced_at) AS synced_at FROM `' . esc_sql($table) . '`',
                    ARRAY_A
                );
            }
        }
        return $revision;
    }
}
