<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Container {
    private static $instance = null;
    private $families;
    private $students;
    private $student_years;
    private $financial;
    private $transportation;
    private $transport_master;
    private $knowledge;
    private $audiences;
    private $staff;
    private $employees;
    private $academic;
    private $admin;
    private $users_admin;
    private $admin_initialized = false;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init() {
        $schema_upgrade_required = get_option('olama_core_db_version') !== OLAMA_CORE_VERSION;
        $schema_repair_required = is_admin() && !Olama_Core_Migrator::schema_is_current();
        if ($schema_upgrade_required || $schema_repair_required) {
            Olama_Core_Migrator::create_tables();
            if (Olama_Core_Migrator::schema_is_current()) {
                update_option('olama_core_db_version', OLAMA_CORE_VERSION);
            }
        }

        add_action('init', array('Olama_Core_Permissions', 'init'));

        if (is_admin() && !$this->admin_initialized) {
            $this->admin_initialized = true;
            $this->admin = new Olama_Core_Admin($this);
            $this->admin->init();
            $this->users_admin = new Olama_Core_Users_Admin($this);
            $this->users_admin->init();
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

    public function financial() {
        if (!$this->financial) {
            $this->financial = new Olama_Core_Financial_Service(new Olama_Core_Repository());
        }

        return $this->financial;
    }

    public function transportation() {
        if (!$this->transportation) {
            $this->transportation = new Olama_Core_Transportation_Service(new Olama_Core_Repository());
        }

        return $this->transportation;
    }

    public function transport_master() {
        if (!$this->transport_master) {
            $this->transport_master = new Olama_Core_Transport_Master_Service(new Olama_Core_Repository());
        }

        return $this->transport_master;
    }

    public function knowledge() {
        if (!$this->knowledge) {
            $this->knowledge = new Olama_Core_Knowledge_Service();
        }

        return $this->knowledge;
    }

    public function audiences() {
        if (!$this->audiences) {
            $this->audiences = new Olama_Core_Audience_Service(new Olama_Core_Repository());
        }

        return $this->audiences;
    }

    public function staff() {
        if (!$this->staff) {
            $this->staff = new Olama_Core_Staff_Service(new Olama_Core_Repository());
        }

        return $this->staff;
    }

    public function employees() {
        if (!$this->employees) {
            $this->employees = new Olama_Core_Employee_Service(new Olama_Core_Repository());
        }

        return $this->employees;
    }

    public function academic() {
        if (!$this->academic) {
            $this->academic = new Olama_Core_Academic_Service(new Olama_Core_Repository());
        }

        return $this->academic;
    }
}
