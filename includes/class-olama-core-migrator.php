<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Migrator {
    public static function activate() {
        self::create_tables();
        update_option('olama_core_db_version', OLAMA_CORE_VERSION);
    }

    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $families = $wpdb->prefix . 'olama_core_families';
        $students = $wpdb->prefix . 'olama_core_students';
        $student_years = $wpdb->prefix . 'olama_core_student_years';

        dbDelta("CREATE TABLE {$families} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid VARCHAR(100) NOT NULL,
            oracle_family_id VARCHAR(100) NOT NULL,
            sponsor_full_name VARCHAR(255) NULL,
            father_name VARCHAR(255) NULL,
            mother_name VARCHAR(255) NULL,
            father_mobile VARCHAR(30) NULL,
            mother_mobile VARCHAR(30) NULL,
            primary_mobile VARCHAR(30) NULL,
            email VARCHAR(150) NULL,
            address TEXT NULL,
            family_address TEXT NULL,
            trans_region_id VARCHAR(50) NULL,
            trans_region_name VARCHAR(150) NULL,
            family_status VARCHAR(50) NULL,
            family_status_name VARCHAR(100) NULL,
            students_count INT UNSIGNED NULL,
            source_system VARCHAR(50) DEFAULT 'oracle',
            source_hash VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_family_uid (family_uid),
            UNIQUE KEY uniq_oracle_family (oracle_family_id),
            KEY idx_primary_mobile (primary_mobile),
            KEY idx_family_status (family_status),
            KEY idx_trans_region (trans_region_id)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$students} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_uid VARCHAR(100) NOT NULL,
            family_uid VARCHAR(100) NOT NULL,
            oracle_family_id VARCHAR(100) NOT NULL,
            oracle_student_id VARCHAR(100) NOT NULL,
            student_name VARCHAR(255) NOT NULL,
            student_national_no VARCHAR(50) NULL,
            student_gender VARCHAR(20) NULL,
            student_gender_name VARCHAR(50) NULL,
            student_mobile VARCHAR(30) NULL,
            mother_mobile VARCHAR(30) NULL,
            student_status VARCHAR(50) NULL,
            student_status_name VARCHAR(100) NULL,
            source_system VARCHAR(50) DEFAULT 'oracle',
            source_hash VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_student_uid (student_uid),
            UNIQUE KEY uniq_oracle_student (oracle_family_id, oracle_student_id),
            KEY idx_family_uid (family_uid),
            KEY idx_student_status (student_status),
            KEY idx_student_name (student_name),
            KEY idx_student_national_no (student_national_no),
            KEY idx_student_gender (student_gender)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$student_years} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_uid VARCHAR(100) NOT NULL,
            family_uid VARCHAR(100) NOT NULL,
            oracle_family_id VARCHAR(100) NOT NULL,
            oracle_student_id VARCHAR(100) NOT NULL,
            study_year VARCHAR(20) NOT NULL,
            school_id VARCHAR(50) NULL,
            school_name VARCHAR(150) NULL,
            class_id VARCHAR(50) NULL,
            class_name VARCHAR(100) NULL,
            section_id VARCHAR(50) NULL,
            section_name VARCHAR(100) NULL,
            student_status VARCHAR(50) NULL,
            student_status_name VARCHAR(100) NULL,
            student_year_status VARCHAR(50) NULL,
            registration_date DATE NULL,
            withdraw_date DATE NULL,
            source_system VARCHAR(50) DEFAULT 'oracle',
            source_hash VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_student_year (student_uid, study_year),
            KEY idx_family_year (family_uid, study_year),
            KEY idx_class_section (class_id, section_id),
            KEY idx_study_year (study_year),
            KEY idx_oracle_family (oracle_family_id),
            KEY idx_oracle_student (oracle_student_id),
            KEY idx_student_status (student_status)
        ) {$charset_collate};");
    }

    public static function required_tables() {
        global $wpdb;

        return array(
            $wpdb->prefix . 'olama_core_families',
            $wpdb->prefix . 'olama_core_students',
            $wpdb->prefix . 'olama_core_student_years',
        );
    }

    public static function table_exists($table) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
