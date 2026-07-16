<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Knowledge_Service {
    public function get_family_card($family_id, $study_year = '') {
        $family = olama_core()->families()->get_by_oracle_id($family_id);
        if (!$family) {
            return null;
        }

        $family['family_id'] = $family['oracle_family_id'];
        $students = $this->family_students($family['family_uid'], $study_year);
        return array(
            'status' => 'success',
            'family_id' => $family['oracle_family_id'],
            'study_year' => $study_year,
            'family' => $family,
            'students' => $students,
            'data_source' => 'local',
            'last_synced_at' => $this->latest_sync(array_merge(array($family), $students)),
        );
    }

    public function get_student_card($family_id, $student_id, $study_year = '') {
        $family = olama_core()->families()->get_by_oracle_id($family_id);
        $student = olama_core()->students()->get_by_oracle_keys($family_id, $student_id);
        if (!$family || !$student) {
            return null;
        }

        $family['family_id'] = $family['oracle_family_id'];
        $student['family_id'] = $student['oracle_family_id'];
        $student['student_id'] = $student['oracle_student_id'];
        $academic = olama_core()->student_years()->get_current_year($student['student_uid'], $study_year ?: null);
        $history = olama_core()->student_years()->get_by_student($student['student_uid']);
        return array(
            'status' => 'success',
            'family_id' => $family['oracle_family_id'],
            'student_id' => $student['oracle_student_id'],
            'study_year' => $study_year,
            'student' => $student,
            'family' => $family,
            'academic_current' => $academic,
            'academic_history' => $history,
            'transportation_current' => $study_year ? olama_core()->transportation()->get_student($family_id, $student_id, $study_year) : null,
            'data_source' => 'local',
            'last_synced_at' => $this->latest_sync(array_merge(array($family, $student), $history)),
        );
    }

    public function get_family_360($family_id, $study_year) {
        $card = $this->get_family_card($family_id, $study_year);
        if (!$card) {
            return null;
        }
        $card['financial'] = olama_core()->financial()->get_family_card($family_id, $study_year);
        $card['transportation'] = olama_core()->transportation()->get_family($family_id, $study_year);
        $card['domain_freshness'] = array(
            'family' => $card['last_synced_at'],
            'financial' => $card['financial']['last_synced_at'],
            'transportation' => $this->latest_sync($card['transportation']),
        );
        return $card;
    }

    private function family_students($family_uid, $study_year) {
        $students = olama_core()->students()->get_by_family_uid($family_uid);
        $years = olama_core()->student_years()->get_by_family($family_uid, $study_year ?: null);
        $years_by_student = array();
        foreach ($years as $year) {
            if (!isset($years_by_student[$year['student_uid']])) {
                $years_by_student[$year['student_uid']] = $year;
            }
        }
        foreach ($students as &$student) {
            $student['family_id'] = $student['oracle_family_id'];
            $student['student_id'] = $student['oracle_student_id'];
            if (isset($years_by_student[$student['student_uid']])) {
                $student = array_merge($student, $years_by_student[$student['student_uid']]);
                $student['family_id'] = $student['oracle_family_id'];
                $student['student_id'] = $student['oracle_student_id'];
            }
        }
        unset($student);
        return $students;
    }

    private function latest_sync($rows) {
        $latest = '';
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array('last_synced_at', 'synced_at') as $key) {
                if (!empty($row[$key]) && $row[$key] > $latest) {
                    $latest = $row[$key];
                }
            }
        }
        return $latest ?: null;
    }
}

