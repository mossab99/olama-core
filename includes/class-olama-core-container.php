<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Container {
    private static $instance = null;
    private $families;
    private $students;
    private $student_years;
    private $admin;
    private $admin_initialized = false;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init() {
        if (is_admin() && get_option('olama_core_db_version') !== OLAMA_CORE_VERSION) {
            Olama_Core_Migrator::create_tables();
            update_option('olama_core_db_version', OLAMA_CORE_VERSION);
        }

        if (is_admin() && !$this->admin_initialized) {
            $this->admin_initialized = true;
            $this->admin = new Olama_Core_Admin($this);
            $this->admin->init();
        }
    }

    public function families() {
        if (!$this->families) {
            $this->families = new Olama_Core_Family_Service(new Olama_Core_Repository());
        }

        return $this->families;
    }

    public function students() {
        if (!$this->students) {
            $this->students = new Olama_Core_Student_Service(new Olama_Core_Repository());
        }

        return $this->students;
    }

    public function student_years() {
        if (!$this->student_years) {
            $this->student_years = new Olama_Core_Student_Year_Service(new Olama_Core_Repository());
        }

        return $this->student_years;
    }
}
