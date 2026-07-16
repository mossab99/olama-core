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
        $family_financial_years = $wpdb->prefix . 'olama_core_family_financial_years';
        $family_financial_dues = $wpdb->prefix . 'olama_core_family_financial_dues';
        $financial_transactions = $wpdb->prefix . 'olama_core_financial_transactions';
        $student_transportation = $wpdb->prefix . 'olama_core_student_transportation';
        $staff_profiles = $wpdb->prefix . 'olama_core_staff_profiles';
        $employees = $wpdb->prefix . 'olama_core_employees';
        $audit_logs = $wpdb->prefix . 'olama_logs';

        dbDelta("CREATE TABLE {$families} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid VARCHAR(100) NOT NULL,
            oracle_family_id VARCHAR(100) NOT NULL,
            sponsor_full_name VARCHAR(255) NULL,
            father_name VARCHAR(255) NULL,
            mother_name VARCHAR(255) NULL,
            father_national_no VARCHAR(50) NULL,
            father_nation VARCHAR(100) NULL,
            father_email VARCHAR(150) NULL,
            father_job VARCHAR(150) NULL,
            father_work_place VARCHAR(255) NULL,
            father_work_phone VARCHAR(30) NULL,
            father_is_employee TINYINT NULL,
            mother_national_no VARCHAR(50) NULL,
            mother_nation VARCHAR(100) NULL,
            mother_email VARCHAR(150) NULL,
            mother_job VARCHAR(150) NULL,
            mother_work_place VARCHAR(255) NULL,
            mother_work_phone VARCHAR(30) NULL,
            mother_is_employee TINYINT NULL,
            father_mobile VARCHAR(30) NULL,
            mother_mobile VARCHAR(30) NULL,
            primary_mobile VARCHAR(30) NULL,
            email VARCHAR(150) NULL,
            address TEXT NULL,
            family_address TEXT NULL,
            family_home_phone VARCHAR(30) NULL,
            building_no VARCHAR(50) NULL,
            home_no VARCHAR(50) NULL,
            trans_region_id VARCHAR(50) NULL,
            trans_region_name VARCHAR(150) NULL,
            family_class_id VARCHAR(50) NULL,
            family_class_name VARCHAR(150) NULL,
            is_active TINYINT NULL,
            family_status VARCHAR(50) NULL,
            family_status_name VARCHAR(100) NULL,
            students_count INT UNSIGNED NULL,
            notes TEXT NULL,
            oracle_created_at DATETIME NULL,
            oracle_modified_at DATETIME NULL,
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
            mother_name VARCHAR(255) NULL,
            email VARCHAR(150) NULL,
            birth_date DATE NULL,
            birth_place VARCHAR(150) NULL,
            nationality VARCHAR(100) NULL,
            registration_date DATE NULL,
            previous_school VARCHAR(255) NULL,
            previous_school_average DECIMAL(10,3) NULL,
            has_renew TINYINT NULL,
            renew_year VARCHAR(20) NULL,
            renew_date DATE NULL,
            will_not_renew TINYINT NULL,
            will_not_renew_reason TEXT NULL,
            student_health TEXT NULL,
            social_case VARCHAR(150) NULL,
            refugee_emigrant VARCHAR(100) NULL,
            black_list TINYINT NULL,
            black_list_reason TEXT NULL,
            religion_id VARCHAR(50) NULL,
            pass_fail VARCHAR(50) NULL,
            monthly_income DECIMAL(12,3) NULL,
            student_status VARCHAR(50) NULL,
            student_status_name VARCHAR(100) NULL,
            oracle_created_at DATETIME NULL,
            oracle_modified_at DATETIME NULL,
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
            branch_id VARCHAR(50) NULL,
            branch_name VARCHAR(100) NULL,
            section_id VARCHAR(50) NULL,
            section_name VARCHAR(100) NULL,
            student_status VARCHAR(50) NULL,
            student_status_name VARCHAR(100) NULL,
            student_year_status VARCHAR(50) NULL,
            registration_date DATE NULL,
            withdraw_date DATE NULL,
            renew_student VARCHAR(50) NULL,
            system_respect VARCHAR(50) NULL,
            no_absent INT NULL,
            final_mark_result VARCHAR(100) NULL,
            notes TEXT NULL,
            oracle_created_at DATETIME NULL,
            oracle_modified_at DATETIME NULL,
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

        dbDelta("CREATE TABLE {$family_financial_years} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid VARCHAR(100) NOT NULL,
            oracle_family_id VARCHAR(100) NOT NULL,
            study_year VARCHAR(20) NOT NULL,
            begin_debit DECIMAL(15,3) NOT NULL DEFAULT 0,
            begin_credit DECIMAL(15,3) NOT NULL DEFAULT 0,
            year_debit DECIMAL(15,3) NOT NULL DEFAULT 0,
            year_credit DECIMAL(15,3) NOT NULL DEFAULT 0,
            balance DECIMAL(15,3) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'JOD',
            source_system VARCHAR(50) NOT NULL DEFAULT 'oracle',
            source_hash VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_family_financial_year (family_uid, study_year),
            KEY idx_oracle_family_year (oracle_family_id, study_year),
            KEY idx_balance (balance),
            KEY idx_last_synced (last_synced_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$family_financial_dues} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid VARCHAR(100) NOT NULL,
            oracle_family_id VARCHAR(100) NOT NULL,
            study_year VARCHAR(20) NOT NULL,
            due_date DATE NULL,
            percent_value DECIMAL(10,3) NULL,
            due_amount DECIMAL(15,3) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,3) NOT NULL DEFAULT 0,
            receipt_paid DECIMAL(15,3) NOT NULL DEFAULT 0,
            balance DECIMAL(15,3) NOT NULL DEFAULT 0,
            due_status VARCHAR(20) NOT NULL DEFAULT 'open',
            source_hash VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_family_due_scope (family_uid, study_year),
            KEY idx_oracle_family_due (oracle_family_id, study_year),
            KEY idx_due_date (due_date),
            KEY idx_due_status (due_status)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$financial_transactions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid VARCHAR(100) NOT NULL,
            student_uid VARCHAR(100) NULL,
            oracle_family_id VARCHAR(100) NOT NULL,
            oracle_student_id VARCHAR(100) NULL,
            study_year VARCHAR(20) NOT NULL,
            serial_id VARCHAR(100) NULL,
            receipt_id VARCHAR(100) NULL,
            transaction_date DATE NULL,
            title_id VARCHAR(100) NULL,
            title_type VARCHAR(100) NULL,
            title VARCHAR(255) NULL,
            debit_amount DECIMAL(15,3) NOT NULL DEFAULT 0,
            credit_amount DECIMAL(15,3) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            transaction_status VARCHAR(50) NULL,
            begin_year VARCHAR(50) NULL,
            source_hash VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_family_transaction_scope (family_uid, study_year),
            KEY idx_student_transaction_scope (student_uid, study_year),
            KEY idx_oracle_transaction_scope (oracle_family_id, oracle_student_id, study_year),
            KEY idx_serial_id (serial_id),
            KEY idx_receipt_id (receipt_id),
            KEY idx_transaction_date (transaction_date)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$student_transportation} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            family_uid VARCHAR(100) NULL,
            student_uid VARCHAR(100) NULL,
            oracle_family_id VARCHAR(100) NULL,
            oracle_student_id VARCHAR(100) NULL,
            study_year VARCHAR(20) NULL,
            family_id BIGINT UNSIGNED NULL,
            student_id BIGINT UNSIGNED NULL,
            class_id VARCHAR(50) NULL,
            class_name VARCHAR(190) NULL,
            section_id VARCHAR(50) NULL,
            section_name VARCHAR(190) NULL,
            group_id VARCHAR(100) NULL,
            group_name VARCHAR(190) NULL,
            departure_bus VARCHAR(100) NULL,
            departure_bus_name VARCHAR(190) NULL,
            departure_bus_seq VARCHAR(50) NULL,
            arrival_bus VARCHAR(100) NULL,
            arrival_bus_name VARCHAR(190) NULL,
            arrival_bus_seq VARCHAR(50) NULL,
            trans_route VARCHAR(100) NULL,
            trans_route_name VARCHAR(190) NULL,
            from_date DATE NULL,
            to_date DATE NULL,
            is_active TINYINT NULL,
            is_active_name VARCHAR(50) NULL,
            trans_amount DECIMAL(15,3) NULL,
            source_hash VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            oracle_modified_at DATETIME NULL,
            last_synced_at DATETIME NULL,
            synced_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_transport_family_year (family_uid, study_year),
            KEY idx_transport_student_year (student_uid, study_year),
            KEY idx_study_year (study_year),
            KEY idx_family_id (family_id),
            KEY idx_student_id (student_id),
            KEY idx_departure_bus (departure_bus),
            KEY idx_arrival_bus (arrival_bus),
            KEY idx_trans_route (trans_route),
            KEY idx_transport_active (is_active),
            KEY idx_synced_at (synced_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$staff_profiles} (
            user_id BIGINT UNSIGNED NOT NULL,
            employee_id VARCHAR(50) NULL,
            phone_number VARCHAR(30) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (user_id),
            KEY idx_employee_id (employee_id)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$employees} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id VARCHAR(50) NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            national_number VARCHAR(50) NULL,
            birth_date DATE NULL,
            gender VARCHAR(30) NULL,
            job_title VARCHAR(255) NULL,
            appointment_date DATE NULL,
            address TEXT NULL,
            phones VARCHAR(100) NULL,
            certificate_grade VARCHAR(150) NULL,
            certificate_type VARCHAR(150) NULL,
            certificate_date DATE NULL,
            certificate_average DECIMAL(10,3) NULL,
            employee_status VARCHAR(50) NOT NULL,
            source_system VARCHAR(30) NOT NULL DEFAULT 'oracle',
            source_hash VARCHAR(64) NULL,
            raw_json LONGTEXT NULL,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_employee_id (employee_id),
            KEY idx_employee_status (employee_status),
            KEY idx_employee_name (full_name),
            KEY idx_employee_job (job_title),
            KEY idx_employee_synced (last_synced_at)
        ) {$charset_collate};");

        dbDelta("CREATE TABLE {$audit_logs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(50) NOT NULL DEFAULT 'school',
            action VARCHAR(255) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY source (source),
            KEY created_at (created_at)
        ) {$charset_collate};");

        $legacy_teachers = $wpdb->prefix . 'olama_teachers';
        if (self::table_exists($legacy_teachers)) {
            $wpdb->query(
                "INSERT INTO `" . esc_sql($staff_profiles) . "` (user_id, employee_id, phone_number, created_at, updated_at)
                 SELECT id, employee_id, phone_number, NOW(), NOW() FROM `" . esc_sql($legacy_teachers) . "`
                 ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id), phone_number = VALUES(phone_number)"
            );
        }
    }

    public static function required_tables() {
        global $wpdb;

        return array(
            $wpdb->prefix . 'olama_core_families',
            $wpdb->prefix . 'olama_core_students',
            $wpdb->prefix . 'olama_core_student_years',
            $wpdb->prefix . 'olama_core_family_financial_years',
            $wpdb->prefix . 'olama_core_family_financial_dues',
            $wpdb->prefix . 'olama_core_financial_transactions',
            $wpdb->prefix . 'olama_core_student_transportation',
            $wpdb->prefix . 'olama_core_staff_profiles',
            $wpdb->prefix . 'olama_logs',
        );
    }

    public static function table_exists($table) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}
