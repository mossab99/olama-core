<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Admin {
    private $core;

    public function __construct(Olama_Core_Container $core) {
        $this->core = $core;
    }

    public function init() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_olama_core_load_family_financial_card', array($this, 'ajax_load_family_financial_card'));
        add_action('wp_ajax_olama_core_load_family_transportation_card', array($this, 'ajax_load_family_transportation_card'));
        add_action('wp_ajax_olama_core_load_family_card', array($this, 'ajax_load_family_card'));
        add_action('wp_ajax_olama_core_load_student_card', array($this, 'ajax_load_student_card'));
        add_action('wp_ajax_olama_core_load_family_360', array($this, 'ajax_load_family_360'));
    }

    public function register_menu() {
        add_menu_page('Olama Core', 'Olama Core', 'manage_options', 'olama-core', array($this, 'dashboard'), 'dashicons-database-view', 56);
        add_submenu_page('olama-core', 'لوحة التحكم', 'لوحة التحكم', 'manage_options', 'olama-core', array($this, 'dashboard'));
        add_submenu_page('olama-core', 'العائلات', 'العائلات', 'manage_options', 'olama-core-families', array($this, 'families'));
        add_submenu_page('olama-core', 'الطلاب', 'الطلاب', 'manage_options', 'olama-core-students', array($this, 'students'));
        add_submenu_page('olama-core', 'سنوات الطلاب', 'سنوات الطلاب', 'manage_options', 'olama-core-student-years', array($this, 'student_years'));
        add_submenu_page('olama-core', 'لوحة العائلة 360', 'لوحة العائلة 360', 'manage_options', 'olama-core-family-360', array($this, 'family_360'));
        add_submenu_page('olama-core', 'بطاقة العائلة', 'بطاقة العائلة', 'manage_options', 'olama-core-family-card', array($this, 'family_card'));
        add_submenu_page('olama-core', 'البطاقة المالية', 'البطاقة المالية', 'manage_options', 'olama-core-family-financial-card', array($this, 'family_financial_card'));
        add_submenu_page('olama-core', 'بطاقة المواصلات', 'بطاقة المواصلات', 'manage_options', 'olama-core-family-transportation-card', array($this, 'family_transportation_card'));
        add_submenu_page('olama-core', 'بطاقة الطالب', 'بطاقة الطالب', 'manage_options', 'olama-core-student-card', array($this, 'student_card'));
        add_submenu_page('olama-core', 'الصحة', 'الصحة', 'manage_options', 'olama-core-health', array($this, 'health'));
    }

    public function enqueue_admin_assets($hook_suffix) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'olama-core') !== 0) {
            return;
        }

        wp_enqueue_style(
            'olama-core-admin',
            OLAMA_CORE_URL . 'admin/css/olama-core-admin.css',
            array(),
            OLAMA_CORE_VERSION
        );
    }

    public function dashboard() {
        global $wpdb;

        $families = $this->core->families()->count();
        $students = $this->core->students()->count();
        $years = $this->core->student_years()->count();
        $family_table = $wpdb->prefix . 'olama_core_families';
        $student_table = $wpdb->prefix . 'olama_core_students';
        $last_family = $wpdb->get_row("SELECT * FROM `" . esc_sql($family_table) . "` ORDER BY last_synced_at DESC, id DESC LIMIT 1", ARRAY_A);
        $last_student = $wpdb->get_row("SELECT * FROM `" . esc_sql($student_table) . "` ORDER BY last_synced_at DESC, id DESC LIMIT 1", ARRAY_A);
        $recent = $wpdb->get_results("SELECT 'family' AS type, family_uid AS uid, sponsor_full_name AS label, last_synced_at FROM `" . esc_sql($family_table) . "` UNION ALL SELECT 'student' AS type, student_uid AS uid, student_name AS label, last_synced_at FROM `" . esc_sql($student_table) . "` ORDER BY last_synced_at DESC LIMIT 10", ARRAY_A);

        echo '<div class="wrap"><h1>Olama Core</h1>';
        echo '<div class="notice notice-warning inline"><p>Olama Core is currently isolated and is not yet used by existing Olama plugins.</p></div>';
        $this->stat_cards(array(
            'Core families' => $families,
            'Core students' => $students,
            'Student-year records' => $years,
        ));
        echo '<h2>Last Synced</h2><table class="widefat striped"><tbody>';
        echo '<tr><th>Last synced family</th><td>' . esc_html($last_family ? $last_family['family_uid'] . ' - ' . $last_family['sponsor_full_name'] : 'None') . '</td></tr>';
        echo '<tr><th>Last synced student</th><td>' . esc_html($last_student ? $last_student['student_uid'] . ' - ' . $last_student['student_name'] : 'None') . '</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Recent Records</h2>';
        $this->simple_table($recent, array('type' => 'Type', 'uid' => 'UID', 'label' => 'Name', 'last_synced_at' => 'Last Synced At'));
        echo '</div>';
    }

    public function families() {
        global $wpdb;

        $table = $wpdb->prefix . 'olama_core_families';
        $total = $this->core->families()->count();
        $rows = $this->paged_rows($table, $total);
        foreach ($rows as &$row) {
            $row['students_count'] = $this->core->students()->count(array('family_uid' => $row['family_uid']));
        }
        unset($row);

        echo '<div class="wrap"><h1>Core Families</h1>';
        $this->pagination_controls('olama-core-families', $total);
        $this->simple_table($rows, array(
            'family_uid' => 'Family UID',
            'oracle_family_id' => 'Oracle Family ID',
            'sponsor_full_name' => 'Sponsor Full Name',
            'father_mobile' => 'Father Mobile',
            'mother_mobile' => 'Mother Mobile',
            'students_count' => 'Students Count',
            'family_status' => 'Family Status',
            'last_synced_at' => 'Last Synced At',
        ));
        $this->pagination_controls('olama-core-families', $total);
        echo '</div>';
    }

    public function students() {
        global $wpdb;

        $total = $this->core->students()->count();
        $rows = $this->paged_rows($wpdb->prefix . 'olama_core_students', $total);
        echo '<div class="wrap"><h1>Core Students</h1>';
        $this->pagination_controls('olama-core-students', $total);
        $this->simple_table($rows, array(
            'student_uid' => 'Student UID',
            'student_name' => 'Student Name',
            'family_uid' => 'Family UID',
            'oracle_family_id' => 'Oracle Family ID',
            'oracle_student_id' => 'Oracle Student ID',
            'student_status' => 'Student Status',
            'last_synced_at' => 'Last Synced At',
        ));
        $this->pagination_controls('olama-core-students', $total);
        echo '</div>';
    }

    public function student_years() {
        global $wpdb;

        $years = $wpdb->prefix . 'olama_core_student_years';
        $students = $wpdb->prefix . 'olama_core_students';
        $total = $this->core->student_years()->count();
        $limit = $this->limit($total);
        $offset = $this->offset($limit);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT y.*, s.student_name FROM `" . esc_sql($years) . "` y LEFT JOIN `" . esc_sql($students) . "` s ON y.student_uid = s.student_uid ORDER BY y.id DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);

        echo '<div class="wrap"><h1>Core Student Years</h1>';
        $this->pagination_controls('olama-core-student-years', $total);
        $this->simple_table($rows, array(
            'student_uid' => 'Student UID',
            'student_name' => 'Student Name',
            'study_year' => 'Study Year',
            'class_id' => 'Class ID',
            'class_name' => 'Class Name',
            'section_id' => 'Section ID',
            'section_name' => 'Section Name',
            'student_year_status' => 'Student Year Status',
            'last_synced_at' => 'Last Synced At',
        ));
        $this->pagination_controls('olama-core-student-years', $total);
        echo '</div>';
    }

    public function family_360() {
        $default_study_year = $this->oracle_default_study_year();
        $family_id = isset($_GET['family_id']) ? absint($_GET['family_id']) : 0;
        $study_year = isset($_GET['study_year']) ? sanitize_text_field(wp_unslash($_GET['study_year'])) : $default_study_year;
        $nonce = wp_create_nonce('olama_core_family_360');
        $oracle_available = function_exists('olama_oracle_sync_api_get');

        echo '<div class="wrap olama-core-admin olama-family-360-admin" dir="rtl"><div class="olama-page">';
        echo '<header class="olama-page-header">';
        echo '<div><h1 class="olama-page-title">لوحة العائلة 360</h1>';
        echo '<p class="olama-page-subtitle">عرض شامل لبيانات العائلة والطلاب والملف المالي والمواصلات من نظام Oracle ERP</p></div>';
        echo '</header>';

        if (!$oracle_available) {
            echo '<div class="olama-error"><p>' . esc_html__('Olama Oracle Sync is required to load Family 360 data. Please activate and configure it first.', 'olama-core') . '</p></div>';
        }

        echo '<form id="olama-family-360-form" class="olama-filter-card olama-no-print">';
        echo '<input type="hidden" id="olama_family_360_nonce" value="' . esc_attr($nonce) . '">';
        echo '<div class="olama-filter-grid">';
        echo '<p><label class="olama-label" for="olama_family_360_family_id">رقم العائلة</label><input type="number" min="1" step="1" id="olama_family_360_family_id" class="regular-text" value="' . esc_attr($family_id ? $family_id : '') . '" required></p>';
        echo '<p><label class="olama-label" for="olama_family_360_study_year">السنة الدراسية</label><input type="text" id="olama_family_360_study_year" class="regular-text" value="' . esc_attr($study_year) . '" placeholder="2026-2027"></p>';
        echo '<p class="olama-filter-submit">';
        submit_button('تحميل البيانات', 'primary olama-btn olama-btn-primary', 'submit', false, $oracle_available ? array() : array('disabled' => 'disabled'));
        echo '</p></div></form>';

        echo '<div id="olama-family-360-message" aria-live="polite"></div>';
        echo '<div id="olama-family-360-results" hidden>';
        echo '<div class="olama-toolbar olama-no-print"><button type="button" class="olama-btn olama-btn-secondary" id="olama-family-360-print">طباعة</button><div id="olama-family-360-header-actions" class="olama-actions"></div></div>';
        echo '<div id="olama-family-360-report"></div>';
        echo '</div>';
        $this->family_360_assets($oracle_available);
        echo '</div></div>';
    }

    public function ajax_load_family_360() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to load Family 360.'), 403);
        }

        if (!check_ajax_referer('olama_core_family_360', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page and try again.'), 403);
        }

        if (!function_exists('olama_oracle_sync_api_get')) {
            wp_send_json_error(array('message' => 'Olama Oracle Sync is required to load Family 360 data. Please activate and configure it first.'), 400);
        }

        $family_id = isset($_POST['family_id']) ? absint($_POST['family_id']) : 0;
        $study_year = isset($_POST['study_year']) ? sanitize_text_field(wp_unslash($_POST['study_year'])) : '';

        if ($family_id <= 0) {
            wp_send_json_error(array('message' => 'أدخل رقم عائلة صحيح.'), 400);
        }

        $query_args = array('study_year' => $study_year);
        $family_result = olama_oracle_sync_api_get('/api/families/' . rawurlencode((string) $family_id) . '/card', $query_args);

        if (empty($family_result['success']) || !isset($family_result['data']) || !is_array($family_result['data']) || !empty($family_result['data']['not_found'])) {
            $status_code = isset($family_result['status_code']) ? (int) $family_result['status_code'] : 0;
            wp_send_json_error(array(
                'message' => 'لم يتم العثور على العائلة أو تعذر تحميل بياناتها.',
                'status_code' => $status_code,
            ), $status_code >= 400 ? $status_code : 502);
        }

        $financial_result = olama_oracle_sync_api_get('/api/families/' . rawurlencode((string) $family_id) . '/financial-card', $query_args);
        $transportation_result = olama_oracle_sync_api_get('/api/families/' . rawurlencode((string) $family_id) . '/transportation', $query_args);

        $financial_data = !empty($financial_result['success']) && isset($financial_result['data']) && is_array($financial_result['data']) ? $financial_result['data'] : null;
        $transportation_data = !empty($transportation_result['success']) && isset($transportation_result['data']) && is_array($transportation_result['data']) ? $transportation_result['data'] : null;

        wp_send_json_success(array(
            'html' => $this->render_family_360_dashboard($family_id, $study_year, $family_result['data'], $financial_data, $transportation_data),
            'warnings' => array(
                'financial' => $financial_data === null,
                'transportation' => $transportation_data === null,
            ),
        ));
    }

    public function family_card() {
        $default_study_year = $this->oracle_default_study_year();
        $family_id = isset($_GET['family_id']) ? absint($_GET['family_id']) : 0;
        $study_year = isset($_GET['study_year']) ? sanitize_text_field(wp_unslash($_GET['study_year'])) : $default_study_year;
        $nonce = wp_create_nonce('olama_core_family_card');
        $oracle_available = function_exists('olama_oracle_sync_api_get');

        echo '<div class="wrap olama-core-admin olama-family-profile-admin" dir="rtl"><div class="olama-page">';
        echo '<header class="olama-page-header"><div><h1 class="olama-page-title">بطاقة العائلة</h1>';
        echo '<p class="olama-page-subtitle">عرض بيانات العائلة والأب والأم والطلاب المرتبطين بها من نظام Oracle ERP</p></div></header>';
        if (!$oracle_available) {
            echo '<div class="olama-error"><p>' . esc_html__('Olama Oracle Sync is required to load family card data. Please activate and configure it first.', 'olama-core') . '</p></div>';
        }
        echo '<form id="olama-family-profile-card-form" class="olama-filter-card olama-no-print" method="post" action="" onsubmit="return false;">';
        echo '<input type="hidden" id="olama_family_profile_card_nonce" value="' . esc_attr($nonce) . '">';
        echo '<div class="olama-filter-grid">';
        echo '<p><label class="olama-label" for="olama_family_profile_card_family_id">رقم العائلة</label><input type="number" min="1" step="1" id="olama_family_profile_card_family_id" class="regular-text" value="' . esc_attr($family_id ? $family_id : '') . '" required></p>';
        echo '<p><label class="olama-label" for="olama_family_profile_card_study_year">السنة الدراسية</label><input type="text" id="olama_family_profile_card_study_year" class="regular-text" value="' . esc_attr($study_year) . '" placeholder="2026-2027"></p>';
        echo '<p class="olama-filter-submit">';
        submit_button('تحميل بطاقة العائلة', 'primary olama-btn olama-btn-primary', 'submit', false, $oracle_available ? array() : array('disabled' => 'disabled'));
        echo '</p></div></form>';
        echo '<div id="olama-family-profile-message" aria-live="polite"></div>';
        echo '<div id="olama-family-profile-results" hidden>';
        echo '<div class="olama-toolbar olama-no-print"><button type="button" class="olama-btn olama-btn-secondary" id="olama-family-profile-print">طباعة</button><div id="olama-family-profile-header-actions" class="olama-actions"></div></div>';
        echo '<div id="olama-family-profile-report">';
        echo '<div id="olama-family-profile-kpis"></div>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">بيانات العائلة</h2></div><div id="olama-family-profile-family"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">بيانات الأب</h2></div><div id="olama-family-profile-father"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">بيانات الأم</h2></div><div id="olama-family-profile-mother"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">العنوان والمنطقة</h2></div><div id="olama-family-profile-address"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">الطلاب</h2></div><div id="olama-family-profile-students"></div></section>';
        echo '<section class="olama-section olama-no-print"><div class="olama-section-header"><h2 class="olama-section-title">روابط سريعة</h2></div><div id="olama-family-profile-quick-links" class="olama-actions"></div></section>';
        echo '</div></div>';
        $this->family_card_assets($oracle_available, $family_id);
        echo '</div></div>';
    }

    public function ajax_load_family_card() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to load this family card.'), 403);
        }

        if (!check_ajax_referer('olama_core_family_card', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page and try again.'), 403);
        }

        if (!function_exists('olama_oracle_sync_api_get')) {
            wp_send_json_error(array('message' => 'Olama Oracle Sync is required to load family card data. Please activate and configure it first.'), 400);
        }

        $family_id = isset($_POST['family_id']) ? absint($_POST['family_id']) : 0;
        $study_year = isset($_POST['study_year']) ? sanitize_text_field(wp_unslash($_POST['study_year'])) : '';

        if ($family_id <= 0) {
            wp_send_json_error(array('message' => 'أدخل رقم عائلة صحيح.'), 400);
        }

        $result = olama_oracle_sync_api_get('/api/families/' . rawurlencode((string) $family_id) . '/card', array(
            'study_year' => $study_year,
        ));

        if (empty($result['success'])) {
            $status_code = isset($result['status_code']) ? (int) $result['status_code'] : 0;
            $result_message = isset($result['message']) ? sanitize_text_field($result['message']) : '';
            if ($status_code === 404) {
                $message = 'لم يتم العثور على عائلة بهذا الرقم.';
            } elseif (stripos($result_message, 'Invalid JSON') !== false) {
                $message = 'استجابة غير متوقعة من Oracle API. يرجى المحاولة لاحقاً.';
            } elseif ($status_code === 0) {
                $message = 'تعذر الاتصال بـ Oracle API: ' . ($result_message ? $result_message : 'الخدمة غير متاحة حالياً.');
            } else {
                $message = 'خطأ من Oracle API' . ($status_code ? ' (' . $status_code . ')' : '') . ': ' . ($result_message ? $result_message : 'استجابة غير متوقعة.');
            }
            wp_send_json_error(array('message' => $message, 'status_code' => $status_code), $status_code >= 400 ? $status_code : 502);
        }

        if (!isset($result['data']) || !is_array($result['data'])) {
            wp_send_json_error(array('message' => 'استجابة غير متوقعة من Oracle API. يرجى المحاولة لاحقاً.'), 502);
        }

        wp_send_json_success(array(
            'status_code' => isset($result['status_code']) ? (int) $result['status_code'] : 200,
            'card' => $result['data'],
        ));
    }

    public function family_financial_card() {
        $default_study_year = $this->oracle_default_study_year();
        $family_id = isset($_GET['family_id']) ? absint($_GET['family_id']) : 0;
        $study_year = isset($_GET['study_year']) ? sanitize_text_field(wp_unslash($_GET['study_year'])) : $default_study_year;
        $nonce = wp_create_nonce('olama_core_family_financial_card');
        $oracle_available = function_exists('olama_oracle_sync_api_get');

        echo '<div class="wrap olama-core-admin olama-family-card-admin" dir="rtl"><div class="olama-page">';
        echo '<header class="olama-page-header"><div><h1 class="olama-page-title">البطاقة المالية</h1>';
        echo '<p class="olama-page-subtitle">عرض الملخص المالي والاستحقاقات والحركات المالية للعائلة من نظام Oracle ERP</p></div></header>';
        if (!$oracle_available) {
            echo '<div class="olama-error"><p>Olama Oracle Sync is required to load the financial card. Please activate and configure it first.</p></div>';
        }
        echo '<form id="olama-family-financial-card-form" class="olama-filter-card olama-no-print" method="post" action="" onsubmit="return false;">';
        echo '<input type="hidden" id="olama_family_card_nonce" value="' . esc_attr($nonce) . '">';
        echo '<div class="olama-filter-grid">';
        echo '<p><label class="olama-label" for="olama_family_card_family_id">رقم العائلة</label><input type="number" min="1" step="1" id="olama_family_card_family_id" class="regular-text" value="' . esc_attr($family_id ? $family_id : '') . '" required></p>';
        echo '<p><label class="olama-label" for="olama_family_card_study_year">السنة الدراسية</label><input type="text" id="olama_family_card_study_year" class="regular-text" value="' . esc_attr($study_year) . '" placeholder="2026-2027"></p>';
        echo '<p class="olama-filter-submit">';
        submit_button('تحميل البطاقة المالية', 'primary olama-btn olama-btn-primary', 'submit', false, $oracle_available ? array() : array('disabled' => 'disabled'));
        echo '</p></div></form>';
        echo '<div id="olama-family-card-message" aria-live="polite"></div>';
        echo '<div id="olama-family-card-results" hidden>';
        echo '<div class="olama-toolbar olama-no-print"><button type="button" class="olama-btn olama-btn-secondary" id="olama-family-card-print">طباعة</button><div id="olama-family-card-header-actions" class="olama-actions"></div></div>';
        echo '<div id="olama-family-card-report">';
        echo '<div id="olama-family-card-kpis"></div>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">الملخص المالي</h2></div><div id="olama-family-card-summary"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">توزيع الاستحقاق</h2></div><div id="olama-family-card-dues"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">الحركات المالية</h2></div><div id="olama-family-card-transactions"></div></section>';
        echo '<section class="olama-section olama-no-print"><div class="olama-section-header"><h2 class="olama-section-title">روابط سريعة</h2></div><div id="olama-family-card-quick-links" class="olama-actions"></div></section>';
        echo '</div>';
        echo '</div>';
        $this->family_financial_card_assets($oracle_available, $family_id);
        echo '</div></div>';
    }

    public function ajax_load_family_financial_card() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to load this financial card.'), 403);
        }

        if (!check_ajax_referer('olama_core_family_financial_card', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page and try again.'), 403);
        }

        if (!function_exists('olama_oracle_sync_api_get')) {
            wp_send_json_error(array('message' => 'Olama Oracle Sync is required to load the financial card. Please activate and configure it first.'), 400);
        }

        $family_id = isset($_POST['family_id']) ? absint($_POST['family_id']) : 0;
        $study_year = isset($_POST['study_year']) ? sanitize_text_field(wp_unslash($_POST['study_year'])) : '';

        if ($family_id <= 0) {
            wp_send_json_error(array('message' => 'Enter a valid Family ID.'), 400);
        }

        $result = olama_oracle_sync_api_get('/api/families/' . rawurlencode((string) $family_id) . '/financial-card', array(
            'study_year' => $study_year,
        ));

        if (empty($result['success'])) {
            $status_code = isset($result['status_code']) ? (int) $result['status_code'] : 0;
            $result_message = isset($result['message']) ? $result['message'] : '';
            if ($status_code === 404) {
                $message = 'No financial card found for this family/study year.';
            } elseif (stripos($result_message, 'Invalid JSON') !== false) {
                $message = 'Unexpected response from Oracle API. Please try again later.';
            } elseif ($status_code === 0) {
                $message = 'API unreachable: ' . ($result_message ? $result_message : 'Unable to reach Oracle API.');
            } else {
                $message = 'API error ' . $status_code . ': ' . ($result_message ? $result_message : 'Unexpected response.');
            }
            wp_send_json_error(array('message' => $message, 'status_code' => $status_code), $status_code >= 400 ? $status_code : 502);
        }

        if (!isset($result['data']) || !is_array($result['data'])) {
            wp_send_json_error(array('message' => 'Unexpected response from Oracle API. Please try again later.'), 502);
        }

        wp_send_json_success(array(
            'status_code' => isset($result['status_code']) ? (int) $result['status_code'] : 200,
            'card' => $result['data'],
        ));
    }

    public function family_transportation_card() {
        $default_study_year = $this->oracle_default_study_year();
        $family_id = isset($_GET['family_id']) ? absint($_GET['family_id']) : 0;
        $study_year = isset($_GET['study_year']) ? sanitize_text_field(wp_unslash($_GET['study_year'])) : $default_study_year;
        $nonce = wp_create_nonce('olama_core_family_transportation_card');
        $oracle_available = function_exists('olama_oracle_sync_api_get');

        echo '<div class="wrap olama-core-admin olama-family-transportation-admin" dir="rtl"><div class="olama-page">';
        echo '<header class="olama-page-header"><div><h1 class="olama-page-title">بطاقة المواصلات</h1>';
        echo '<p class="olama-page-subtitle">عرض بيانات مواصلات الطلاب والباصات والرسوم من نظام Oracle ERP</p></div></header>';
        if (!$oracle_available) {
            echo '<div class="olama-error"><p>' . esc_html__('Olama Oracle Sync is required to load transportation data. Please activate and configure it first.', 'olama-core') . '</p></div>';
        }
        echo '<form id="olama-family-transportation-card-form" class="olama-filter-card olama-no-print" method="post" action="" onsubmit="return false;">';
        echo '<input type="hidden" id="olama_transportation_card_nonce" value="' . esc_attr($nonce) . '">';
        echo '<div class="olama-filter-grid">';
        echo '<p><label class="olama-label" for="olama_transportation_card_family_id">رقم العائلة</label><input type="number" min="1" step="1" id="olama_transportation_card_family_id" class="regular-text" value="' . esc_attr($family_id ? $family_id : '') . '" required></p>';
        echo '<p><label class="olama-label" for="olama_transportation_card_study_year">السنة الدراسية</label><input type="text" id="olama_transportation_card_study_year" class="regular-text" value="' . esc_attr($study_year) . '" placeholder="2026-2027"></p>';
        echo '<p class="olama-filter-submit">';
        submit_button('تحميل بطاقة المواصلات', 'primary olama-btn olama-btn-primary', 'submit', false, $oracle_available ? array() : array('disabled' => 'disabled'));
        echo '</p></div></form>';
        echo '<div id="olama-family-transportation-message" aria-live="polite"></div>';
        echo '<div id="olama-family-transportation-results" hidden>';
        echo '<div class="olama-toolbar olama-no-print"><button type="button" class="olama-btn olama-btn-secondary" id="olama-family-transportation-print">طباعة</button><div id="olama-family-transportation-header-actions" class="olama-actions"></div></div>';
        echo '<div id="olama-family-transportation-report">';
        echo '<div id="olama-family-transportation-kpis"></div>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">ملخص المواصلات</h2></div><div id="olama-family-transportation-summary"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">الطلاب والمواصلات</h2></div><div id="olama-family-transportation-rows"></div></section>';
        echo '<section class="olama-section olama-no-print"><div class="olama-section-header"><h2 class="olama-section-title">روابط سريعة</h2></div><div id="olama-family-transportation-quick-links" class="olama-actions"></div></section>';
        echo '</div>';
        echo '</div>';
        $this->family_transportation_card_assets($oracle_available, $family_id);
        echo '</div></div>';
    }

    public function ajax_load_family_transportation_card() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to load this transportation card.'), 403);
        }

        if (!check_ajax_referer('olama_core_family_transportation_card', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page and try again.'), 403);
        }

        if (!function_exists('olama_oracle_sync_api_get')) {
            wp_send_json_error(array('message' => 'Olama Oracle Sync is required to load transportation data. Please activate and configure it first.'), 400);
        }

        $family_id = isset($_POST['family_id']) ? absint($_POST['family_id']) : 0;
        $study_year = isset($_POST['study_year']) ? sanitize_text_field(wp_unslash($_POST['study_year'])) : '';

        if ($family_id <= 0) {
            wp_send_json_error(array('message' => 'أدخل رقم عائلة صحيح.'), 400);
        }

        $result = olama_oracle_sync_api_get('/api/families/' . rawurlencode((string) $family_id) . '/transportation', array(
            'study_year' => $study_year,
        ));

        if (empty($result['success'])) {
            $status_code = isset($result['status_code']) ? (int) $result['status_code'] : 0;
            $result_message = isset($result['message']) ? sanitize_text_field($result['message']) : '';
            if (stripos($result_message, 'Invalid JSON') !== false) {
                $message = 'استجابة غير متوقعة من Oracle API. يرجى المحاولة لاحقا.';
            } elseif ($status_code === 0) {
                $message = 'تعذر الاتصال بـ Oracle API: ' . ($result_message ? $result_message : 'الخدمة غير متاحة حاليا.');
            } else {
                $message = 'خطأ من Oracle API' . ($status_code ? ' (' . $status_code . ')' : '') . ': ' . ($result_message ? $result_message : 'استجابة غير متوقعة.');
            }
            wp_send_json_error(array('message' => $message, 'status_code' => $status_code), $status_code >= 400 ? $status_code : 502);
        }

        if (!isset($result['data']) || !is_array($result['data'])) {
            wp_send_json_error(array('message' => 'استجابة غير متوقعة من Oracle API. يرجى المحاولة لاحقا.'), 502);
        }

        wp_send_json_success(array(
            'status_code' => isset($result['status_code']) ? (int) $result['status_code'] : 200,
            'transportation' => $result['data'],
        ));
    }

    public function student_card() {
        $default_study_year = $this->oracle_default_study_year();
        $family_id = isset($_GET['family_id']) ? absint($_GET['family_id']) : 0;
        $student_id = isset($_GET['student_id']) ? absint($_GET['student_id']) : 0;
        $study_year = isset($_GET['study_year']) ? sanitize_text_field(wp_unslash($_GET['study_year'])) : $default_study_year;
        $nonce = wp_create_nonce('olama_core_student_card');
        $oracle_available = function_exists('olama_oracle_sync_api_get');

        echo '<div class="wrap olama-core-admin olama-student-card-admin" dir="rtl"><div class="olama-page">';
        echo '<header class="olama-page-header"><div><h1 class="olama-page-title">بطاقة الطالب</h1>';
        echo '<p class="olama-page-subtitle">عرض شامل لبيانات الطالب والسنة الدراسية والعائلة والمواصلات من نظام Oracle ERP</p></div></header>';
        if (!$oracle_available) {
            echo '<div class="olama-error"><p>' . esc_html__('Olama Oracle Sync is required to load student card data. Please activate and configure it first.', 'olama-core') . '</p></div>';
        }
        echo '<form id="olama-student-card-form" class="olama-filter-card olama-no-print" method="post" action="" onsubmit="return false;">';
        echo '<input type="hidden" id="olama_student_card_nonce" value="' . esc_attr($nonce) . '">';
        echo '<div class="olama-filter-grid olama-filter-grid-4">';
        echo '<p><label class="olama-label" for="olama_student_card_family_id">رقم العائلة</label><input type="number" min="1" step="1" id="olama_student_card_family_id" class="regular-text" value="' . esc_attr($family_id ? $family_id : '') . '" required></p>';
        echo '<p><label class="olama-label" for="olama_student_card_student_id">رقم الطالب</label><input type="number" min="1" step="1" id="olama_student_card_student_id" class="regular-text" value="' . esc_attr($student_id ? $student_id : '') . '" required></p>';
        echo '<p><label class="olama-label" for="olama_student_card_study_year">السنة الدراسية</label><input type="text" id="olama_student_card_study_year" class="regular-text" value="' . esc_attr($study_year) . '" placeholder="2026-2027"></p>';
        echo '<p class="olama-filter-submit">';
        submit_button('تحميل بطاقة الطالب', 'primary olama-btn olama-btn-primary', 'submit', false, $oracle_available ? array() : array('disabled' => 'disabled'));
        echo '</p></div></form>';
        echo '<div id="olama-student-card-message" aria-live="polite"></div>';
        echo '<div id="olama-student-card-results" hidden>';
        echo '<div class="olama-toolbar olama-no-print"><button type="button" class="olama-btn olama-btn-secondary" id="olama-student-card-print">طباعة</button><div id="olama-student-card-header-actions" class="olama-actions"></div></div>';
        echo '<div id="olama-student-card-report">';
        echo '<div id="olama-student-card-meta"></div><div id="olama-student-card-profile"></div>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">بيانات الطالب</h2></div><div id="olama-student-card-student"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">بيانات التجديد</h2></div><div id="olama-student-card-renewal"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">بيانات العائلة</h2></div><div id="olama-student-card-family"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">بيانات السنة الدراسية الحالية</h2></div><div id="olama-student-card-academic-current"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">سجل السنوات الدراسية</h2></div><div id="olama-student-card-academic-history"></div></section>';
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">بيانات المواصلات الحالية</h2></div><div id="olama-student-card-transportation-current"></div></section>';
        echo '<section class="olama-section olama-no-print"><div class="olama-section-header"><h2 class="olama-section-title">روابط سريعة</h2></div><div id="olama-student-card-quick-links" class="olama-actions"></div></section>';
        echo '</div></div>';
        $this->student_card_assets($oracle_available, $family_id, $student_id);
        echo '</div></div>';
    }

    public function ajax_load_student_card() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to load this student card.'), 403);
        }

        if (!check_ajax_referer('olama_core_student_card', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page and try again.'), 403);
        }

        if (!function_exists('olama_oracle_sync_api_get')) {
            wp_send_json_error(array('message' => 'Olama Oracle Sync is required to load student card data. Please activate and configure it first.'), 400);
        }

        $family_id = isset($_POST['family_id']) ? absint($_POST['family_id']) : 0;
        $student_id = isset($_POST['student_id']) ? absint($_POST['student_id']) : 0;
        $study_year = isset($_POST['study_year']) ? sanitize_text_field(wp_unslash($_POST['study_year'])) : '';

        if ($family_id <= 0) {
            wp_send_json_error(array('message' => 'أدخل رقم عائلة صحيح.'), 400);
        }

        if ($student_id <= 0) {
            wp_send_json_error(array('message' => 'أدخل رقم طالب صحيح.'), 400);
        }

        $result = olama_oracle_sync_api_get('/api/families/' . rawurlencode((string) $family_id) . '/students/' . rawurlencode((string) $student_id) . '/card', array(
            'study_year' => $study_year,
        ));

        if (empty($result['success'])) {
            $status_code = isset($result['status_code']) ? (int) $result['status_code'] : 0;
            $result_message = isset($result['message']) ? sanitize_text_field($result['message']) : '';
            if ($status_code === 404) {
                $message = 'لم يتم العثور على الطالب.';
            } elseif (stripos($result_message, 'Invalid JSON') !== false) {
                $message = 'استجابة غير متوقعة من Oracle API. يرجى المحاولة لاحقاً.';
            } elseif ($status_code === 0) {
                $message = 'تعذر الاتصال بـ Oracle API: ' . ($result_message ? $result_message : 'الخدمة غير متاحة حالياً.');
            } else {
                $message = 'خطأ من Oracle API' . ($status_code ? ' (' . $status_code . ')' : '') . ': ' . ($result_message ? $result_message : 'استجابة غير متوقعة.');
            }
            wp_send_json_error(array('message' => $message, 'status_code' => $status_code), $status_code >= 400 ? $status_code : 502);
        }

        if (!isset($result['data']) || !is_array($result['data'])) {
            wp_send_json_error(array('message' => 'استجابة غير متوقعة من Oracle API. يرجى المحاولة لاحقاً.'), 502);
        }

        wp_send_json_success(array(
            'status_code' => isset($result['status_code']) ? (int) $result['status_code'] : 200,
            'card' => $result['data'],
        ));
    }

    private function render_family_360_dashboard($family_id, $study_year, $family_card, $financial_card, $transportation_card) {
        $family = isset($family_card['family']) && is_array($family_card['family']) ? $family_card['family'] : array();
        $students = isset($family_card['students']) && is_array($family_card['students']) ? $family_card['students'] : array();
        $financial_summary = isset($financial_card['family_summary']) && is_array($financial_card['family_summary']) ? $financial_card['family_summary'] : array();
        $student_transactions = isset($financial_card['student_transactions']) && is_array($financial_card['student_transactions']) ? $financial_card['student_transactions'] : array();
        $due_allocations = isset($financial_card['due_allocations']) && is_array($financial_card['due_allocations']) ? $financial_card['due_allocations'] : array();
        $transportation_rows = $this->family_360_transportation_rows($transportation_card);
        $financial_balance = $financial_card === null ? null : $this->family_360_first($financial_summary, array('balance'));
        $transportation_total = $transportation_card === null ? null : $this->family_360_transportation_total($transportation_rows);
        $family_status = $this->family_360_first($family, array('is_active_name', 'family_status', 'status_name', 'status'));
        $bus_names = $this->family_360_unique_values($transportation_rows, array('arrival_bus_name', 'departure_bus_name'));

        ob_start();
        echo '<div class="olama-meta-row">';
        echo '<span class="olama-meta-item">رقم العائلة: <strong>' . esc_html($family_id) . '</strong></span>';
        echo '<span class="olama-meta-item">السنة الدراسية: <strong>' . esc_html($this->family_360_display($study_year)) . '</strong></span>';
        echo '<span class="olama-meta-item">الحالة: ' . $this->family_360_badge($family_status) . '</span>';
        echo '</div>';

        echo '<div class="olama-grid olama-kpi-grid">';
        $this->family_360_kpi_cards(array(
            array('عدد الطلاب', count($students), 'number'),
            array('الرصيد المالي', $financial_balance, 'money'),
            array('إجمالي المواصلات', $transportation_total, 'money'),
            array('حالة العائلة', $family_status, 'badge'),
        ));
        echo '</div>';

        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">ملخص العائلة</h2></div>';
        $this->family_360_info_grid(array(
            array('رقم العائلة', $this->family_360_first($family, array('family_id', 'oracle_family_id')), 'number'),
            array('اسم ولي الأمر / المعيل', $this->family_360_first($family, array('sponsor_full_name', 'sponsor_name', 'guardian_name'))),
            array('اسم الأب', $this->family_360_first($family, array('father_name'))),
            array('موبايل الأب', $this->family_360_first($family, array('father_mobile', 'father_phone')), 'number'),
            array('اسم الأم', $this->family_360_first($family, array('mother_name'))),
            array('موبايل الأم', $this->family_360_first($family, array('mother_mobile', 'mother_phone')), 'number'),
            array('المنطقة', $this->family_360_first($family, array('trans_region_name', 'region_name', 'area_name'))),
            array('حالة العائلة', $this->family_360_first($family, array('is_active_name', 'family_status', 'status_name', 'status'))),
        ));
        echo '</section>';

        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">الطلاب</h2></div>';
        $this->family_360_students_table($students, $family_id, $study_year);
        echo '</section>';

        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">الملخص المالي</h2></div>';
        if ($financial_card === null) {
            echo '<div class="olama-warning"><p>تعذر تحميل الملخص المالي.</p></div>';
        } else {
            $this->family_360_info_grid(array(
                array('مدين أول المدة', $this->family_360_first($financial_summary, array('begin_debit')), 'money'),
                array('دائن أول المدة', $this->family_360_first($financial_summary, array('begin_credit')), 'money'),
                array('مدين السنة', $this->family_360_first($financial_summary, array('year_debit')), 'money'),
                array('دائن السنة', $this->family_360_first($financial_summary, array('year_credit')), 'money'),
                array('الرصيد', $this->family_360_first($financial_summary, array('balance')), 'money balance'),
                array('عدد الحركات', count($student_transactions), 'number'),
                array('عدد الاستحقاقات', count($due_allocations), 'number'),
            ));
            echo '<div class="olama-section-actions"><a class="olama-btn olama-btn-secondary" href="' . esc_url($this->family_360_admin_url('olama-core-family-financial-card', $family_id, $study_year)) . '">فتح البطاقة المالية</a></div>';
        }
        echo '</section>';

        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">ملخص المواصلات</h2></div>';
        if ($transportation_card === null) {
            echo '<div class="olama-warning"><p>تعذر تحميل ملخص المواصلات.</p></div>';
        } else {
            $this->family_360_info_grid(array(
                array('عدد الطلاب المشتركين بالمواصلات', $this->family_360_transportation_count($transportation_rows), 'number'),
                array('إجمالي رسوم المواصلات', $this->family_360_transportation_total($transportation_rows), 'money'),
                array('الفئات / الخطوط', implode('، ', $this->family_360_unique_values($transportation_rows, array('group_name', 'transportation_group_name', 'category_name', 'trans_route_name', 'route_name')))),
                array('الباصات المستخدمة', $bus_names ? implode('، ', $bus_names) : 'لم يتم تعيين باص بعد'),
            ));
            echo '<div class="olama-section-actions"><a class="olama-btn olama-btn-secondary" href="' . esc_url($this->family_360_admin_url('olama-core-family-transportation-card', $family_id, $study_year)) . '">فتح بطاقة المواصلات</a></div>';
        }
        echo '</section>';

        echo '<section class="olama-section olama-no-print"><div class="olama-section-header"><h2 class="olama-section-title">روابط سريعة</h2></div><div class="olama-actions">';
        echo '<a class="olama-btn olama-btn-ghost" href="' . esc_url($this->family_360_admin_url('olama-core-family-card', $family_id, $study_year)) . '">بطاقة العائلة</a>';
        echo '<a class="olama-btn olama-btn-ghost" href="' . esc_url($this->family_360_admin_url('olama-core-family-financial-card', $family_id, $study_year)) . '">البطاقة المالية</a>';
        echo '<a class="olama-btn olama-btn-ghost" href="' . esc_url($this->family_360_admin_url('olama-core-family-transportation-card', $family_id, $study_year)) . '">بطاقة المواصلات</a>';
        echo '</div></section>';

        return ob_get_clean();
    }

    private function family_360_kpi_cards($fields) {
        foreach ($fields as $field) {
            echo '<div class="olama-card olama-kpi">';
            echo '<span class="olama-kpi-label">' . esc_html($field[0]) . '</span>';
            if (isset($field[2]) && $field[2] === 'badge') {
                echo $this->family_360_badge($field[1]);
            } else {
                echo '<strong class="olama-kpi-value" dir="auto">' . esc_html($this->family_360_display($field[1], isset($field[2]) ? $field[2] : '')) . '</strong>';
            }
            echo '</div>';
        }
    }

    private function family_360_info_grid($fields) {
        echo '<div class="olama-info-grid">';
        foreach ($fields as $field) {
            $format = isset($field[2]) ? $field[2] : '';
            echo '<div class="olama-info-item' . (strpos($format, 'money') !== false || strpos($format, 'number') !== false ? ' is-number' : '') . '">';
            echo '<span class="olama-label">' . esc_html($field[0]) . '</span>';
            if ($this->family_360_is_status_value($field[1])) {
                echo $this->family_360_badge($field[1]);
            } else {
                echo '<strong class="olama-value" dir="auto">' . esc_html($this->family_360_display($field[1], $format)) . '</strong>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private function family_360_students_table($students, $family_id, $study_year) {
        if (!$students) {
            echo '<div class="olama-family-360-empty">لا يوجد طلاب مرتبطون بهذه العائلة في السنة المحددة.</div>';
            return;
        }

        $columns = array('رقم الطالب', 'اسم الطالب', 'الرقم الوطني', 'الجنس', 'الصف', 'الشعبة', 'الحالة', 'بطاقة الطالب');
        echo '<div class="olama-table-wrap"><table class="olama-table"><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . esc_html($column) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($students as $student) {
            if (!is_array($student)) {
                continue;
            }
            $student_id = absint($this->family_360_first($student, array('student_id', 'oracle_student_id')));
            echo '<tr>';
            echo '<td class="olama-number">' . esc_html($this->family_360_display($this->family_360_first($student, array('student_id', 'oracle_student_id', 'student_uid')))) . '</td>';
            echo '<td>' . esc_html($this->family_360_display($this->family_360_first($student, array('student_name', 'student_full_name', 'name', 'full_name')))) . '</td>';
            echo '<td class="olama-number">' . esc_html($this->family_360_display($this->family_360_first($student, array('student_national_no', 'national_no', 'national_number')))) . '</td>';
            echo '<td>' . esc_html($this->family_360_display($this->family_360_first($student, array('gender_name', 'student_gender_name', 'gender')))) . '</td>';
            echo '<td>' . esc_html($this->family_360_display($this->family_360_first($student, array('class_name', 'class', 'class_id')))) . '</td>';
            echo '<td>' . esc_html($this->family_360_display($this->family_360_first($student, array('section_name', 'section', 'section_id')))) . '</td>';
            echo '<td>' . esc_html($this->family_360_display($this->family_360_first($student, array('student_status_name', 'status_name', 'student_status', 'status')))) . '</td>';
            echo '<td>';
            if ($student_id > 0) {
                echo '<a class="olama-btn olama-btn-ghost olama-btn-small" href="' . esc_url($this->family_360_student_url($family_id, $student_id, $study_year)) . '">عرض بطاقة الطالب</a>';
            } else {
                echo esc_html('—');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function family_360_admin_url($page, $family_id, $study_year) {
        return add_query_arg(array(
            'page' => $page,
            'family_id' => absint($family_id),
            'study_year' => sanitize_text_field($study_year),
        ), admin_url('admin.php'));
    }

    private function family_360_student_url($family_id, $student_id, $study_year) {
        return add_query_arg(array(
            'page' => 'olama-core-student-card',
            'family_id' => absint($family_id),
            'student_id' => absint($student_id),
            'study_year' => sanitize_text_field($study_year),
        ), admin_url('admin.php'));
    }

    private function family_360_transportation_rows($transportation_card) {
        if (!is_array($transportation_card)) {
            return array();
        }

        if (isset($transportation_card['transportation']) && is_array($transportation_card['transportation'])) {
            return $transportation_card['transportation'];
        }

        return array_keys($transportation_card) === range(0, count($transportation_card) - 1) ? $transportation_card : array();
    }

    private function family_360_transportation_count($rows) {
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->family_360_has_value($this->family_360_first($row, array('group_name', 'transportation_group_name', 'category_name', 'trans_route_name', 'arrival_bus_name', 'departure_bus_name', 'trans_amount')))) {
                $count++;
            }
        }
        return $count;
    }

    private function family_360_transportation_total($rows) {
        $total = 0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $total += (float) $this->family_360_first($row, array('trans_amount', 'amount', 'fees'));
            }
        }
        return $total;
    }

    private function family_360_unique_values($rows, $keys) {
        $values = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = $this->family_360_first($row, array($key));
                if (!$this->family_360_has_value($value) || (string) $value === '0') {
                    continue;
                }
                $values[(string) $value] = (string) $value;
            }
        }
        return array_values($values);
    }

    private function family_360_first($source, $keys) {
        foreach ($keys as $key) {
            $value = $this->family_360_value($source, $key);
            if ($this->family_360_has_value($value)) {
                return $value;
            }
        }
        return null;
    }

    private function family_360_value($source, $key) {
        if (!is_array($source)) {
            return null;
        }

        $value = $source;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function family_360_has_value($value) {
        return $value !== null && $value !== '';
    }

    private function family_360_display($value, $format = '') {
        if (!$this->family_360_has_value($value)) {
            return '—';
        }

        if (strpos($format, 'money') !== false) {
            return number_format((float) $value, 3, '.', '');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '—';
    }

    private function family_360_is_status_value($value) {
        return $this->family_360_has_value($value) && is_scalar($value);
    }

    private function family_360_badge($value) {
        $text = $this->family_360_display($value);
        return '<span class="' . esc_attr('olama-badge ' . $this->family_360_badge_class($text)) . '">' . esc_html($text) . '</span>';
    }

    private function family_360_badge_class($text) {
        $text = trim((string) $text);
        $lower = strtolower($text);

        if ($text === 'فعال' || $lower === 'active') {
            return 'olama-badge-success';
        }

        if (strpos($text, 'غير') !== false || strpos($text, 'موقوف') !== false || $lower === 'inactive') {
            return 'olama-badge-warning';
        }

        if (strpos($text, 'ملغي') !== false || $lower === 'cancelled' || $lower === 'canceled') {
            return 'olama-badge-danger';
        }

        return 'olama-badge-neutral';
    }

    private function oracle_default_study_year() {
        if (!function_exists('olama_oracle_sync_get_api_config')) {
            return '';
        }

        $config = olama_oracle_sync_get_api_config();
        return isset($config['default_study_year']) ? sanitize_text_field($config['default_study_year']) : '';
    }

    private function family_360_assets($oracle_available) {
        ?>
        <script>
        (function() {
            var oracleAvailable = <?php echo $oracle_available ? 'true' : 'false'; ?>;
            var form = document.getElementById('olama-family-360-form');
            var message = document.getElementById('olama-family-360-message');
            var results = document.getElementById('olama-family-360-results');
            var report = document.getElementById('olama-family-360-report');
            var printButton = document.getElementById('olama-family-360-print');
            var headerActions = document.getElementById('olama-family-360-header-actions');
            var submit = form ? form.querySelector('input[type="submit"], button[type="submit"]') : null;

            function clearNode(node) {
                while (node && node.firstChild) {
                    node.removeChild(node.firstChild);
                }
            }

            function showMessage(type, value) {
                clearNode(message);
                if (!value) {
                    return;
                }
                var notice = document.createElement('div');
                notice.className = type === 'error' ? 'olama-error' : (type === 'warning' ? 'olama-warning' : (type === 'loading' ? 'olama-loading' : 'olama-success'));
                var p = document.createElement('p');
                p.textContent = value;
                notice.appendChild(p);
                message.appendChild(notice);
            }

            function detailUrl(page, familyId, studyYear) {
                var url = new URL(ajaxurl.replace('admin-ajax.php', 'admin.php'), window.location.origin);
                url.searchParams.set('page', page);
                url.searchParams.set('family_id', String(familyId));
                url.searchParams.set('study_year', String(studyYear || ''));
                return url.toString();
            }

            function renderHeaderActions(familyId, studyYear) {
                clearNode(headerActions);
                [
                    ['olama-core-family-card', 'بطاقة العائلة'],
                    ['olama-core-family-financial-card', 'البطاقة المالية'],
                    ['olama-core-family-transportation-card', 'بطاقة المواصلات']
                ].forEach(function(item) {
                    var link = document.createElement('a');
                    link.className = 'olama-btn olama-btn-ghost';
                    link.href = detailUrl(item[0], familyId, studyYear);
                    link.textContent = item[1];
                    headerActions.appendChild(link);
                });
            }

            if (printButton) {
                printButton.addEventListener('click', function() {
                    window.print();
                });
            }

            if (!form || !oracleAvailable) {
                return;
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();
                var familyId = document.getElementById('olama_family_360_family_id').value;
                var studyYear = document.getElementById('olama_family_360_study_year').value;
                if (!familyId || Number(familyId) <= 0) {
                    showMessage('error', 'أدخل رقم عائلة صحيح.');
                    return;
                }

                clearNode(message);
                clearNode(report);
                clearNode(headerActions);
                results.hidden = true;
                showMessage('loading', 'جاري تحميل بيانات العائلة...');
                if (submit) {
                    submit.disabled = true;
                    submit.dataset.originalValue = submit.value || submit.textContent;
                    if (submit.value) {
                        submit.value = 'جاري التحميل...';
                    } else {
                        submit.textContent = 'جاري التحميل...';
                    }
                }

                var body = new URLSearchParams();
                body.append('action', 'olama_core_load_family_360');
                body.append('nonce', document.getElementById('olama_family_360_nonce').value);
                body.append('family_id', familyId);
                body.append('study_year', studyYear);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function(response) {
                    return response.json().catch(function() {
                        throw new Error('استجابة غير متوقعة من لوحة ووردبريس.');
                    });
                }).then(function(payload) {
                    if (!payload || !payload.success) {
                        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'تعذر تحميل لوحة العائلة 360.');
                    }
                    report.innerHTML = payload.data.html || '';
                    renderHeaderActions(familyId, studyYear);
                    results.hidden = false;
                    showMessage('success', 'تم تحميل لوحة العائلة 360.');
                }).catch(function(error) {
                    clearNode(report);
                    clearNode(headerActions);
                    results.hidden = true;
                    showMessage('error', error.message);
                }).finally(function() {
                    if (submit) {
                        submit.disabled = false;
                        if (submit.value) {
                            submit.value = submit.dataset.originalValue || 'Load Family 360';
                        } else {
                            submit.textContent = submit.dataset.originalValue || 'Load Family 360';
                        }
                    }
                });
            });
        })();
        </script>
        <?php
    }

    private function family_card_assets($oracle_available, $family_id) {
        $student_card_base_url = add_query_arg(array(
            'page' => 'olama-core-student-card',
        ), admin_url('admin.php'));
        $family_360_base_url = add_query_arg(array(
            'page' => 'olama-core-family-360',
        ), admin_url('admin.php'));
        $financial_card_base_url = add_query_arg(array(
            'page' => 'olama-core-family-financial-card',
        ), admin_url('admin.php'));
        $transportation_card_base_url = add_query_arg(array(
            'page' => 'olama-core-family-transportation-card',
        ), admin_url('admin.php'));
        ?>
        <script>
        (function() {
            var oracleAvailable = <?php echo $oracle_available ? 'true' : 'false'; ?>;
            var shouldAutoLoad = <?php echo $family_id > 0 ? 'true' : 'false'; ?>;
            var studentCardBaseUrl = <?php echo wp_json_encode(esc_url($student_card_base_url)); ?>;
            var family360BaseUrl = <?php echo wp_json_encode(esc_url($family_360_base_url)); ?>;
            var financialCardBaseUrl = <?php echo wp_json_encode(esc_url($financial_card_base_url)); ?>;
            var transportationCardBaseUrl = <?php echo wp_json_encode(esc_url($transportation_card_base_url)); ?>;
            var studentCardButtonText = <?php echo wp_json_encode(esc_html('عرض بطاقة الطالب')); ?>;
            var form = document.getElementById('olama-family-profile-card-form');
            var message = document.getElementById('olama-family-profile-message');
            var results = document.getElementById('olama-family-profile-results');
            var printButton = document.getElementById('olama-family-profile-print');
            var headerActions = document.getElementById('olama-family-profile-header-actions');
            var submit = form ? form.querySelector('input[type="submit"], button[type="submit"]') : null;
            var studentsEmptyMessage = 'لا يوجد طلاب مرتبطون بهذه العائلة في السنة المحددة.';

            function clearNode(node) {
                while (node && node.firstChild) {
                    node.removeChild(node.firstChild);
                }
            }

            function hasValue(value) {
                return value !== null && typeof value !== 'undefined' && value !== '';
            }

            function text(value) {
                return hasValue(value) ? String(value) : '\u2014';
            }

            function firstValue(item, keys) {
                item = item || {};
                for (var i = 0; i < keys.length; i++) {
                    if (hasValue(item[keys[i]])) {
                        return item[keys[i]];
                    }
                }
                return null;
            }

            function showMessage(type, value) {
                clearNode(message);
                if (!value) {
                    return;
                }
                var notice = document.createElement('div');
                notice.className = type === 'error' ? 'olama-error' : (type === 'warning' ? 'olama-warning' : (type === 'loading' ? 'olama-loading' : 'olama-success'));
                var p = document.createElement('p');
                p.textContent = value;
                notice.appendChild(p);
                message.appendChild(notice);
            }

            function badge(value) {
                var span = document.createElement('span');
                var valueText = text(value);
                span.textContent = valueText;
                span.className = 'olama-badge';
                if (valueText === 'فعال' || valueText === 'مستمر' || valueText.toLowerCase() === 'active') {
                    span.className += ' olama-badge-success';
                } else if (valueText.indexOf('غير') !== -1 || valueText.indexOf('موقوف') !== -1 || valueText.toLowerCase() === 'inactive') {
                    span.className += ' olama-badge-warning';
                } else {
                    span.className += ' olama-badge-neutral';
                }
                return span;
            }

            function formatDate(value) {
                if (!hasValue(value)) {
                    return '\u2014';
                }
                var raw = String(value);
                var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
                return match ? match[1] + '-' + match[2] + '-' + match[3] : raw;
            }

            function mapByCode(value, labels) {
                if (!hasValue(value)) {
                    return '\u2014';
                }
                var key = String(value);
                return Object.prototype.hasOwnProperty.call(labels, key) ? labels[key] : '\u2014';
            }

            function employeeText(value) {
                return mapByCode(value, {'1': 'نعم', '0': 'لا'});
            }

            function studentGenderText(item) {
                var name = firstValue(item, ['student_gender_name']);
                return hasValue(name) ? name : mapByCode(firstValue(item, ['student_gender']), {'1': 'ذكر', '2': 'أنثى'});
            }

            function studentStatusText(item) {
                var name = firstValue(item, ['student_status_name']);
                return hasValue(name) ? name : mapByCode(firstValue(item, ['student_status']), {'1': 'مستمر', '2': 'غير مستمر'});
            }

            function appendField(target, label, value, formatter, className) {
                var field = document.createElement('div');
                field.className = 'olama-info-item';
                if (className) {
                    field.className += ' ' + className;
                }
                var labelNode = document.createElement('span');
                var valueNode = document.createElement('strong');
                labelNode.className = 'olama-label';
                valueNode.className = 'olama-value';
                labelNode.textContent = label;
                valueNode.textContent = formatter ? formatter(value) : text(value);
                valueNode.dir = 'auto';
                field.appendChild(labelNode);
                field.appendChild(valueNode);
                target.appendChild(field);
            }

            function renderFields(targetId, fields) {
                var target = document.getElementById(targetId);
                clearNode(target);
                var grid = document.createElement('div');
                grid.className = 'olama-info-grid';
                fields.forEach(function(field) {
                    appendField(grid, field[0], field[1], field[2], field[3] || '');
                });
                target.appendChild(grid);
            }

            function appendCell(row, value, className, formatter) {
                var td = document.createElement('td');
                td.dir = 'auto';
                if (className) {
                    td.className = className;
                }
                td.textContent = formatter ? formatter(value) : text(value);
                row.appendChild(td);
            }

            function studentCardUrl(familyId, studentId, studyYear) {
                var url = new URL(studentCardBaseUrl, window.location.origin);
                url.searchParams.set('family_id', String(familyId));
                url.searchParams.set('student_id', String(studentId));
                url.searchParams.set('study_year', String(studyYear || ''));
                return url.toString();
            }

            function family360Url(familyId, studyYear) {
                var url = new URL(family360BaseUrl, window.location.origin);
                url.searchParams.set('family_id', String(familyId));
                url.searchParams.set('study_year', String(studyYear || ''));
                return url.toString();
            }

            function appendStudentCardCell(row, item, card) {
                var td = document.createElement('td');
                var studentId = parseInt(firstValue(item, ['student_id', 'oracle_student_id']), 10);
                var familyId = parseInt(firstValue(card.family || {}, ['family_id']) || card.family_id, 10);
                var studyYear = card.study_year || document.getElementById('olama_family_profile_card_study_year').value;
                td.dir = 'auto';
                if (!Number.isFinite(studentId) || studentId <= 0 || !Number.isFinite(familyId) || familyId <= 0) {
                    td.textContent = '\u2014';
                    row.appendChild(td);
                    return;
                }
                var link = document.createElement('a');
                link.className = 'button';
                link.className = 'olama-btn olama-btn-ghost olama-btn-small';
                link.href = studentCardUrl(familyId, studentId, studyYear);
                link.textContent = studentCardButtonText;
                td.appendChild(link);
                row.appendChild(td);
            }

            function renderStudents(rows, card) {
                card = card || {};
                var target = document.getElementById('olama-family-profile-students');
                clearNode(target);
                if (!Array.isArray(rows) || rows.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'olama-empty';
                    empty.textContent = studentsEmptyMessage;
                    target.appendChild(empty);
                    return;
                }

                var columns = [
                    {label: 'رقم الطالب', value: function(item) { return firstValue(item, ['student_id', 'oracle_student_id', 'student_uid']); }, className: 'olama-number'},
                    {label: 'اسم الطالب', value: function(item) { return firstValue(item, ['student_name', 'student_full_name', 'name', 'full_name']); }},
                    {label: 'الرقم الوطني', value: function(item) { return firstValue(item, ['student_national_no', 'national_no', 'national_number']); }, className: 'olama-number'},
                    {label: 'الجنس', value: function(item) { return studentGenderText(item); }},
                    {label: 'تاريخ الميلاد', value: function(item) { return firstValue(item, ['birth_date', 'date_of_birth', 'dob']); }, formatter: formatDate},
                    {label: 'مكان الميلاد', value: function(item) { return firstValue(item, ['birth_place', 'place_of_birth']); }},
                    {label: 'الصف', value: function(item) { return firstValue(item, ['class_name', 'class', 'class_id']); }},
                    {label: 'الشعبة', value: function(item) { return firstValue(item, ['section_name', 'section', 'section_id']); }},
                    {label: 'الحالة', value: function(item) { return studentStatusText(item); }},
                    {label: 'تاريخ التسجيل', value: function(item) { return firstValue(item, ['registration_date', 'register_date', 'date_registered', 'date_created']); }, formatter: formatDate},
                    {label: 'موبايل الطالب', value: function(item) { return firstValue(item, ['student_mobile', 'mobile', 'phone']); }, className: 'olama-number'}
                    , {label: 'بطاقة الطالب', studentCardLink: true}
                ];

                var wrap = document.createElement('div');
                wrap.className = 'olama-table-wrap';
                var table = document.createElement('table');
                table.className = 'olama-table';
                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');
                columns.forEach(function(column) {
                    var th = document.createElement('th');
                    th.textContent = column.label;
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                rows.forEach(function(item) {
                    var row = document.createElement('tr');
                    columns.forEach(function(column) {
                        if (column.studentCardLink) {
                            appendStudentCardCell(row, item, card);
                        } else {
                            appendCell(row, column.value(item), column.className || '', column.formatter);
                        }
                    });
                    tbody.appendChild(row);
                });
                table.appendChild(tbody);
                wrap.appendChild(table);
                target.appendChild(wrap);
            }

            function detailUrl(baseUrl, familyId, studyYear) {
                var url = new URL(baseUrl, window.location.origin);
                url.searchParams.set('family_id', String(familyId || ''));
                url.searchParams.set('study_year', String(studyYear || ''));
                return url.toString();
            }

            function renderQuickLinks(familyId, studyYear) {
                var targets = [document.getElementById('olama-family-profile-quick-links'), headerActions];
                targets.forEach(function(target) {
                    clearNode(target);
                    [
                        [family360BaseUrl, 'لوحة العائلة 360'],
                        [financialCardBaseUrl, 'البطاقة المالية'],
                        [transportationCardBaseUrl, 'بطاقة المواصلات']
                    ].forEach(function(item) {
                        var link = document.createElement('a');
                        link.className = 'olama-btn olama-btn-ghost';
                        link.href = detailUrl(item[0], familyId, studyYear);
                        link.textContent = item[1];
                        target.appendChild(link);
                    });
                });
            }

            function renderKpis(family, card) {
                var target = document.getElementById('olama-family-profile-kpis');
                clearNode(target);
                var students = Array.isArray(card.students) ? card.students : [];
                var fields = [
                    ['عدد الطلاب', students.length],
                    ['حالة العائلة', firstValue(family, ['is_active_name', 'family_status', 'status_name', 'status']), true],
                    ['المنطقة', firstValue(family, ['trans_region_name', 'region_name', 'area_name'])],
                    ['ولي الأمر / المعيل', firstValue(family, ['sponsor_full_name', 'sponsor_name', 'guardian_name'])]
                ];
                var grid = document.createElement('div');
                grid.className = 'olama-grid olama-kpi-grid';
                fields.forEach(function(field) {
                    var item = document.createElement('div');
                    item.className = 'olama-card olama-kpi';
                    var label = document.createElement('span');
                    label.className = 'olama-kpi-label';
                    label.textContent = field[0];
                    item.appendChild(label);
                    if (field[2]) {
                        item.appendChild(badge(field[1]));
                    } else {
                        var value = document.createElement('strong');
                        value.className = 'olama-kpi-value';
                        value.dir = 'auto';
                        value.textContent = text(field[1]);
                        item.appendChild(value);
                    }
                    grid.appendChild(item);
                });
                target.appendChild(grid);
            }

            function renderCard(card) {
                card = card || {};
                var family = card.family || {};
                if (!family || Object.keys(family).length === 0) {
                    results.hidden = true;
                    showMessage('warning', 'لم يتم العثور على عائلة بهذا الرقم.');
                    return;
                }

                var familyId = parseInt(firstValue(family, ['family_id']) || card.family_id, 10);
                var studyYear = card.study_year || document.getElementById('olama_family_profile_card_study_year').value;
                renderKpis(family, card);
                renderQuickLinks(familyId, studyYear);

                renderFields('olama-family-profile-family', [
                    ['رقم العائلة', firstValue(family, ['family_id']) || card.family_id, null],
                    ['اسم ولي الأمر / المعيل', firstValue(family, ['sponsor_full_name', 'sponsor_name', 'guardian_name']), null],
                    ['حالة العائلة', firstValue(family, ['is_active_name', 'family_status', 'status_name', 'status']), null],
                    ['تاريخ الإنشاء', firstValue(family, ['date_created', 'created_at']), formatDate],
                    ['آخر تعديل', firstValue(family, ['date_modified', 'updated_at', 'modified_at']), formatDate]
                ]);

                renderFields('olama-family-profile-father', [
                    ['اسم الأب', firstValue(family, ['father_name']), null],
                    ['الرقم الوطني', firstValue(family, ['father_national_no', 'father_national_number']), null],
                    ['الموبايل', firstValue(family, ['father_mobile', 'father_phone']), null],
                    ['البريد الإلكتروني', firstValue(family, ['father_email', 'father_mail']), null],
                    ['الوظيفة', firstValue(family, ['father_job', 'father_job_name', 'father_occupation']), null],
                    ['مكان العمل', firstValue(family, ['father_work_place', 'father_workplace', 'father_work_address']), null],
                    ['هاتف العمل', firstValue(family, ['father_work_phone', 'father_work_mobile']), null],
                    ['هل هو موظف', firstValue(family, ['father_is_employee']), employeeText]
                ]);

                renderFields('olama-family-profile-mother', [
                    ['اسم الأم', firstValue(family, ['mother_name']), null],
                    ['الرقم الوطني', firstValue(family, ['mother_national_no', 'mother_national_number']), null],
                    ['الموبايل', firstValue(family, ['mother_mobile', 'mother_phone']), null],
                    ['البريد الإلكتروني', firstValue(family, ['mother_email', 'mother_mail']), null],
                    ['الوظيفة', firstValue(family, ['mother_job', 'mother_job_name', 'mother_occupation']), null],
                    ['مكان العمل', firstValue(family, ['mother_work_place', 'mother_workplace', 'mother_work_address']), null],
                    ['هاتف العمل', firstValue(family, ['mother_work_phone', 'mother_work_mobile']), null],
                    ['هل هي موظفة', firstValue(family, ['mother_is_employee']), employeeText]
                ]);

                renderFields('olama-family-profile-address', [
                    ['العنوان', firstValue(family, ['family_address', 'address']), null, 'is-wide'],
                    ['هاتف المنزل', firstValue(family, ['home_phone', 'family_phone', 'house_phone']), null],
                    ['المنطقة', firstValue(family, ['trans_region_name', 'region_name', 'area_name']), null],
                    ['رقم المبنى', firstValue(family, ['building_no', 'building_number']), null],
                    ['رقم المنزل', firstValue(family, ['home_no', 'house_no', 'home_number', 'house_number']), null],
                    ['ملاحظات', firstValue(family, ['notes', 'family_notes']), null]
                ]);

                renderStudents(Array.isArray(card.students) ? card.students : [], card);
                results.hidden = false;
            }

            if (printButton) {
                printButton.addEventListener('click', function() {
                    window.print();
                });
            }

            if (!form || !oracleAvailable) {
                return;
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();
                var familyId = document.getElementById('olama_family_profile_card_family_id').value;
                var studyYear = document.getElementById('olama_family_profile_card_study_year').value;
                if (!familyId || Number(familyId) <= 0) {
                    showMessage('error', 'أدخل رقم عائلة صحيح.');
                    return;
                }

                clearNode(message);
                results.hidden = true;
                if (submit) {
                    submit.disabled = true;
                    submit.dataset.originalValue = submit.value || submit.textContent;
                    if (submit.value) {
                        submit.value = 'جاري التحميل...';
                    } else {
                        submit.textContent = 'جاري التحميل...';
                    }
                }

                var body = new URLSearchParams();
                body.append('action', 'olama_core_load_family_card');
                body.append('nonce', document.getElementById('olama_family_profile_card_nonce').value);
                body.append('family_id', familyId);
                body.append('study_year', studyYear);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function(response) {
                    return response.json().catch(function() {
                        throw new Error('استجابة غير متوقعة من لوحة ووردبريس.');
                    });
                }).then(function(payload) {
                    if (!payload || !payload.success) {
                        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'تعذر تحميل بطاقة العائلة.');
                    }
                    renderCard(payload.data.card || {});
                    if (!results.hidden) {
                        showMessage('success', 'تم تحميل بطاقة العائلة.');
                    }
                }).catch(function(error) {
                    showMessage('error', error.message);
                }).finally(function() {
                    if (submit) {
                        submit.disabled = false;
                        if (submit.value) {
                            submit.value = submit.dataset.originalValue || 'تحميل بطاقة العائلة';
                        } else {
                            submit.textContent = submit.dataset.originalValue || 'تحميل بطاقة العائلة';
                        }
                    }
                });
            });
            if (shouldAutoLoad) {
                form.dispatchEvent(new Event('submit', {cancelable: true}));
            }
        })();
        </script>
        <?php
    }

    private function family_financial_card_assets($oracle_available, $family_id) {
        $family_360_base_url = add_query_arg(array(
            'page' => 'olama-core-family-360',
        ), admin_url('admin.php'));
        $family_card_base_url = add_query_arg(array(
            'page' => 'olama-core-family-card',
        ), admin_url('admin.php'));
        $transportation_card_base_url = add_query_arg(array(
            'page' => 'olama-core-family-transportation-card',
        ), admin_url('admin.php'));
        ?>
        <script>
        (function() {
            var oracleAvailable = <?php echo $oracle_available ? 'true' : 'false'; ?>;
            var shouldAutoLoad = <?php echo $family_id > 0 ? 'true' : 'false'; ?>;
            var family360BaseUrl = <?php echo wp_json_encode(esc_url($family_360_base_url)); ?>;
            var familyCardBaseUrl = <?php echo wp_json_encode(esc_url($family_card_base_url)); ?>;
            var transportationCardBaseUrl = <?php echo wp_json_encode(esc_url($transportation_card_base_url)); ?>;
            var form = document.getElementById('olama-family-financial-card-form');
            var message = document.getElementById('olama-family-card-message');
            var results = document.getElementById('olama-family-card-results');
            var printButton = document.getElementById('olama-family-card-print');
            var headerActions = document.getElementById('olama-family-card-header-actions');
            var submit = form ? form.querySelector('input[type="submit"], button[type="submit"]') : null;

            function clearNode(node) {
                while (node && node.firstChild) {
                    node.removeChild(node.firstChild);
                }
            }

            function text(value) {
                if (value === null || typeof value === 'undefined' || value === '') {
                    return '\u2014';
                }
                return String(value);
            }

            function hasValue(value) {
                return value !== null && typeof value !== 'undefined' && value !== '';
            }

            function firstValue(item, keys) {
                for (var i = 0; i < keys.length; i++) {
                    if (hasValue(item[keys[i]])) {
                        return item[keys[i]];
                    }
                }
                return null;
            }

            function showMessage(type, value) {
                clearNode(message);
                if (!value) {
                    return;
                }
                var notice = document.createElement('div');
                notice.className = type === 'error' ? 'olama-error' : (type === 'warning' ? 'olama-warning' : (type === 'loading' ? 'olama-loading' : 'olama-success'));
                var p = document.createElement('p');
                p.textContent = value;
                notice.appendChild(p);
                message.appendChild(notice);
            }

            function formatCurrency(value) {
                if (value === null || typeof value === 'undefined' || value === '') {
                    return '\u2014';
                }
                var number = Number(value);
                if (!isFinite(number)) {
                    return text(value);
                }
                return new Intl.NumberFormat('en-US', {
                    minimumFractionDigits: 3,
                    maximumFractionDigits: 3
                }).format(number);
            }

            function numericValue(value) {
                var number = Number(value);
                return isFinite(number) ? number : 0;
            }

            function sumRows(rows, key) {
                return rows.reduce(function(total, item) {
                    return total + numericValue(item[key]);
                }, 0);
            }

            function formatDate(value) {
                if (!hasValue(value)) {
                    return '\u2014';
                }

                var raw = String(value);
                var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
                if (match) {
                    return match[1] + '-' + match[2] + '-' + match[3];
                }

                return raw;
            }

            function appendCell(row, value, className) {
                var td = document.createElement('td');
                td.dir = 'auto';
                if (className) {
                    td.className = className;
                }
                td.textContent = text(value);
                row.appendChild(td);
            }

            function renderTable(targetId, rows, columns, emptyMessage, totals) {
                var target = document.getElementById(targetId);
                clearNode(target);
                if (!Array.isArray(rows) || rows.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'olama-empty';
                    empty.textContent = emptyMessage;
                    target.appendChild(empty);
                    return;
                }

                var wrap = document.createElement('div');
                wrap.className = 'olama-table-wrap';
                var table = document.createElement('table');
                table.className = 'olama-table';
                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');
                columns.forEach(function(column) {
                    var th = document.createElement('th');
                    th.textContent = column.label;
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                rows.forEach(function(item) {
                    var row = document.createElement('tr');
                    columns.forEach(function(column) {
                        var value = typeof column.value === 'function' ? column.value(item) : item[column.key];
                        appendCell(row, value, column.className || '');
                    });
                    tbody.appendChild(row);
                });
                table.appendChild(tbody);
                if (totals) {
                    var tfoot = document.createElement('tfoot');
                    var totalRow = document.createElement('tr');
                    columns.forEach(function(column, index) {
                        var cell = index === 0 ? document.createElement('th') : document.createElement('td');
                        if (column.className) {
                            cell.className = column.className;
                        }
                        if (index === 0) {
                            cell.textContent = 'المجموع';
                        } else if (column.totalKey) {
                            cell.textContent = formatCurrency(sumRows(rows, column.totalKey));
                        } else {
                            cell.textContent = '';
                        }
                        totalRow.appendChild(cell);
                    });
                    tfoot.appendChild(totalRow);
                    table.appendChild(tfoot);
                }
                wrap.appendChild(table);
                target.appendChild(wrap);
            }

            function detailUrl(baseUrl, familyId, studyYear) {
                var url = new URL(baseUrl, window.location.origin);
                url.searchParams.set('family_id', String(familyId || ''));
                url.searchParams.set('study_year', String(studyYear || ''));
                return url.toString();
            }

            function renderQuickLinks(familyId, studyYear) {
                var targets = [document.getElementById('olama-family-card-quick-links'), headerActions];
                targets.forEach(function(target) {
                    clearNode(target);
                    [
                        [family360BaseUrl, 'لوحة العائلة 360'],
                        [familyCardBaseUrl, 'بطاقة العائلة'],
                        [transportationCardBaseUrl, 'بطاقة المواصلات']
                    ].forEach(function(item) {
                        var link = document.createElement('a');
                        link.className = 'olama-btn olama-btn-ghost';
                        link.href = detailUrl(item[0], familyId, studyYear);
                        link.textContent = item[1];
                        target.appendChild(link);
                    });
                });
            }

            function renderKpis(card) {
                var target = document.getElementById('olama-family-card-kpis');
                clearNode(target);
                var summary = card.family_summary || {};
                var fields = [
                    ['مدين أول المدة', formatCurrency(firstValue(summary, ['begin_debit']))],
                    ['دائن أول المدة', formatCurrency(firstValue(summary, ['begin_credit']))],
                    ['مدين السنة', formatCurrency(firstValue(summary, ['year_debit']))],
                    ['دائن السنة', formatCurrency(firstValue(summary, ['year_credit']))],
                    ['الرصيد', formatCurrency(firstValue(summary, ['balance'])), true],
                    ['عدد الحركات', Array.isArray(card.student_transactions) ? card.student_transactions.length : 0]
                ];
                var grid = document.createElement('div');
                grid.className = 'olama-grid olama-kpi-grid';
                fields.forEach(function(field) {
                    var item = document.createElement('div');
                    item.className = 'olama-card olama-kpi' + (field[2] ? ' olama-kpi-highlight' : '');
                    var label = document.createElement('span');
                    label.className = 'olama-kpi-label';
                    var value = document.createElement('strong');
                    value.className = 'olama-kpi-value';
                    label.textContent = field[0];
                    value.textContent = text(field[1]);
                    value.dir = 'auto';
                    item.appendChild(label);
                    item.appendChild(value);
                    grid.appendChild(item);
                });
                target.appendChild(grid);
            }

            function renderSummary(summary) {
                var target = document.getElementById('olama-family-card-summary');
                clearNode(target);
                summary = summary || {};
                var fields = [
                    ['مدين سابق', 'begin_debit', false],
                    ['دائن سابق', 'begin_credit', false],
                    ['مدين السنة', 'year_debit', false],
                    ['دائن السنة', 'year_credit', false],
                    ['الرصيد', 'balance', true]
                ];
                var wrap = document.createElement('div');
                wrap.className = 'olama-info-grid';
                fields.forEach(function(field) {
                    var item = document.createElement('div');
                    item.className = 'olama-info-item' + (field[2] ? ' olama-info-highlight' : '');
                    var label = document.createElement('span');
                    label.className = 'olama-label';
                    label.textContent = field[0];
                    var value = document.createElement('strong');
                    value.className = 'olama-value';
                    value.dir = 'auto';
                    value.textContent = formatCurrency(summary[field[1]]);
                    item.appendChild(label);
                    item.appendChild(value);
                    wrap.appendChild(item);
                });
                target.appendChild(wrap);
            }

            function renderCard(card) {
                card = card || {};
                var summary = card.family_summary || {};
                var familyId = firstValue(summary, ['family_id']) || card.family_id || document.getElementById('olama_family_card_family_id').value;
                var studyYear = firstValue(summary, ['study_year']) || card.study_year || document.getElementById('olama_family_card_study_year').value;
                renderKpis(card);
                renderQuickLinks(familyId, studyYear);
                renderSummary(card.family_summary);
                renderTable('olama-family-card-dues', card.due_allocations, [
                    {label: 'تاريخ الاستحقاق', value: function(item) { return formatDate(firstValue(item, ['due_date', 'date'])); }},
                    {label: 'النسبة', value: function(item) { return firstValue(item, ['percent_value', 'percent']); }},
                    {label: 'قيمة الاستحقاق', value: function(item) { return formatCurrency(item.due_amount); }, totalKey: 'due_amount', className: 'olama-money'},
                    {label: 'خصومات', value: function(item) { return formatCurrency(item.paid_amount); }, totalKey: 'paid_amount', className: 'olama-money'},
                    {label: 'دفعات ومدور دائن', value: function(item) { return formatCurrency(item.receipt_paid); }, totalKey: 'receipt_paid', className: 'olama-money'},
                    {label: 'الرصيد', value: function(item) { return formatCurrency(item.balance); }, totalKey: 'balance', className: 'olama-money'}
                ], 'لا يوجد توزيع استحقاق لهذه العائلة في السنة المحددة.', true);

                renderTable('olama-family-card-transactions', card.student_transactions, [
                    {label: 'التاريخ', value: function(item) { return formatDate(item.trans_date); }},
                    {label: 'الطالب', value: function(item) { return item.student_name; }},
                    {label: 'البيان', value: function(item) {
                        if (hasValue(item.title)) {
                            return item.title;
                        }
                        if (hasValue(item.title_id)) {
                            return 'Title #' + item.title_id;
                        }
                        return null;
                    }},
                    {label: 'رقم الوصل', value: function(item) { return item.receipt_id; }},
                    {label: 'مدين', value: function(item) { return formatCurrency(item.debit_amount); }, totalKey: 'debit_amount', className: 'olama-money'},
                    {label: 'دائن', value: function(item) { return formatCurrency(item.credit_amount); }, totalKey: 'credit_amount', className: 'olama-money'},
                    {label: 'ملاحظات', value: function(item) { return item.notes; }}
                ], 'لا توجد حركات مالية لهذه العائلة في السنة المحددة.', true);
                results.hidden = false;
            }

            if (printButton) {
                printButton.addEventListener('click', function() {
                    window.print();
                });
            }

            if (!form || !oracleAvailable) {
                return;
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();
                var familyId = document.getElementById('olama_family_card_family_id').value;
                var studyYear = document.getElementById('olama_family_card_study_year').value;
                if (!familyId || Number(familyId) <= 0) {
                    showMessage('error', 'أدخل رقم عائلة صحيح.');
                    return;
                }

                clearNode(message);
                results.hidden = true;
                if (submit) {
                    submit.disabled = true;
                    submit.dataset.originalValue = submit.value || submit.textContent;
                    if (submit.value) {
                        submit.value = 'جاري التحميل...';
                    } else {
                        submit.textContent = 'جاري التحميل...';
                    }
                }

                var body = new URLSearchParams();
                body.append('action', 'olama_core_load_family_financial_card');
                body.append('nonce', document.getElementById('olama_family_card_nonce').value);
                body.append('family_id', familyId);
                body.append('study_year', studyYear);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function(response) {
                    return response.json().catch(function() {
                        throw new Error('استجابة غير متوقعة من لوحة ووردبريس.');
                    });
                }).then(function(payload) {
                    if (!payload || !payload.success) {
                        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'تعذر تحميل البطاقة المالية.');
                    }
                    renderCard(payload.data.card || {});
                    showMessage('success', 'تم تحميل البطاقة المالية.');
                }).catch(function(error) {
                    showMessage('error', error.message);
                }).finally(function() {
                    if (submit) {
                        submit.disabled = false;
                        if (submit.value) {
                            submit.value = submit.dataset.originalValue || 'تحميل البطاقة المالية';
                        } else {
                            submit.textContent = submit.dataset.originalValue || 'تحميل البطاقة المالية';
                        }
                    }
                });
            });
            if (shouldAutoLoad) {
                form.dispatchEvent(new Event('submit', {cancelable: true}));
            }
        })();
        </script>
        <?php
    }

    private function family_transportation_card_assets($oracle_available, $family_id) {
        $family_360_base_url = add_query_arg(array(
            'page' => 'olama-core-family-360',
        ), admin_url('admin.php'));
        $family_card_base_url = add_query_arg(array(
            'page' => 'olama-core-family-card',
        ), admin_url('admin.php'));
        $financial_card_base_url = add_query_arg(array(
            'page' => 'olama-core-family-financial-card',
        ), admin_url('admin.php'));
        ?>
        <script>
        (function() {
            var oracleAvailable = <?php echo $oracle_available ? 'true' : 'false'; ?>;
            var shouldAutoLoad = <?php echo $family_id > 0 ? 'true' : 'false'; ?>;
            var family360BaseUrl = <?php echo wp_json_encode(esc_url($family_360_base_url)); ?>;
            var familyCardBaseUrl = <?php echo wp_json_encode(esc_url($family_card_base_url)); ?>;
            var financialCardBaseUrl = <?php echo wp_json_encode(esc_url($financial_card_base_url)); ?>;
            var form = document.getElementById('olama-family-transportation-card-form');
            var message = document.getElementById('olama-family-transportation-message');
            var results = document.getElementById('olama-family-transportation-results');
            var printButton = document.getElementById('olama-family-transportation-print');
            var headerActions = document.getElementById('olama-family-transportation-header-actions');
            var submit = form ? form.querySelector('input[type="submit"], button[type="submit"]') : null;
            var emptyMessage = 'لا توجد بيانات مواصلات لهذه العائلة في السنة المحددة.';

            function clearNode(node) {
                while (node && node.firstChild) {
                    node.removeChild(node.firstChild);
                }
            }

            function hasValue(value) {
                return value !== null && typeof value !== 'undefined' && value !== '';
            }

            function text(value) {
                return hasValue(value) ? String(value) : '\u2014';
            }

            function showMessage(type, value) {
                clearNode(message);
                if (!value) {
                    return;
                }
                var notice = document.createElement('div');
                notice.className = type === 'error' ? 'olama-error' : (type === 'warning' ? 'olama-warning' : (type === 'loading' ? 'olama-loading' : 'olama-success'));
                var p = document.createElement('p');
                p.textContent = value;
                notice.appendChild(p);
                message.appendChild(notice);
            }

            function firstValue(item, keys) {
                item = item || {};
                for (var i = 0; i < keys.length; i++) {
                    if (hasValue(item[keys[i]])) {
                        return item[keys[i]];
                    }
                }
                return null;
            }

            function formatAmount(value) {
                if (!hasValue(value)) {
                    return '\u2014';
                }
                var number = Number(value);
                if (!isFinite(number)) {
                    return text(value);
                }
                return number.toLocaleString('en-US', {
                    minimumFractionDigits: 3,
                    maximumFractionDigits: 3
                });
            }

            function numericValue(value) {
                var number = Number(value);
                return isFinite(number) ? number : 0;
            }

            function formatDate(value) {
                if (!hasValue(value)) {
                    return '\u2014';
                }
                var raw = String(value);
                var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
                return match ? match[1] + '-' + match[2] + '-' + match[3] : raw;
            }

            function busText(name, id) {
                if (hasValue(name) && String(name) !== '0') {
                    return String(name);
                }
                if (!hasValue(id) || String(id) === '0') {
                    return 'لم يتم تعيين باص بعد';
                }
                return text(name);
            }

            function badge(value) {
                var span = document.createElement('span');
                var valueText = text(value);
                span.textContent = valueText;
                span.className = 'olama-badge';
                if (valueText === 'فعال' || valueText.toLowerCase() === 'active') {
                    span.className += ' olama-badge-success';
                } else if (valueText.indexOf('غير') !== -1 || valueText.indexOf('موقوف') !== -1 || valueText.toLowerCase() === 'inactive') {
                    span.className += ' olama-badge-warning';
                } else {
                    span.className += ' olama-badge-neutral';
                }
                return span;
            }

            function appendCell(row, value, className, asBadge) {
                var td = document.createElement('td');
                td.dir = 'auto';
                if (className) {
                    td.className = className;
                }
                if (asBadge) {
                    td.appendChild(badge(value));
                } else {
                    td.textContent = text(value);
                }
                row.appendChild(td);
            }

            function transportationRows(card) {
                return Array.isArray(card.transportation) ? card.transportation : [];
            }

            function totalAmount(rows) {
                return rows.reduce(function(total, item) {
                    return total + numericValue(item.trans_amount);
                }, 0);
            }

            function uniqueValues(rows, getters) {
                var values = [];
                rows.forEach(function(row) {
                    getters.forEach(function(getter) {
                        var value = getter(row);
                        if (hasValue(value) && String(value) !== '0' && values.indexOf(String(value)) === -1) {
                            values.push(String(value));
                        }
                    });
                });
                return values;
            }

            function detailUrl(baseUrl, familyId, studyYear) {
                var url = new URL(baseUrl, window.location.origin);
                url.searchParams.set('family_id', String(familyId || ''));
                url.searchParams.set('study_year', String(studyYear || ''));
                return url.toString();
            }

            function renderQuickLinks(familyId, studyYear) {
                var targets = [document.getElementById('olama-family-transportation-quick-links'), headerActions];
                targets.forEach(function(target) {
                    clearNode(target);
                    [
                        [family360BaseUrl, 'لوحة العائلة 360'],
                        [familyCardBaseUrl, 'بطاقة العائلة'],
                        [financialCardBaseUrl, 'البطاقة المالية']
                    ].forEach(function(item) {
                        var link = document.createElement('a');
                        link.className = 'olama-btn olama-btn-ghost';
                        link.href = detailUrl(item[0], familyId, studyYear);
                        link.textContent = item[1];
                        target.appendChild(link);
                    });
                });
            }

            function renderKpis(card, rows) {
                var target = document.getElementById('olama-family-transportation-kpis');
                clearNode(target);
                var groups = uniqueValues(rows, [function(item) { return firstValue(item, ['group_name', 'transportation_group_name', 'category_name', 'trans_route_name']); }]);
                var buses = uniqueValues(rows, [
                    function(item) { return busText(item.arrival_bus_name, item.arrival_bus_id); },
                    function(item) { return busText(item.departure_bus_name, item.departure_bus_id); }
                ]).filter(function(value) {
                    return value !== 'لم يتم تعيين باص بعد';
                });
                var fields = [
                    ['عدد الطلاب المشتركين بالمواصلات', hasValue(card.count) ? card.count : rows.length],
                    ['إجمالي رسوم المواصلات', formatAmount(totalAmount(rows))],
                    ['الفئات / الخطوط', groups.length ? groups.join('، ') : '\u2014'],
                    ['الباصات المستخدمة', buses.length ? buses.join('، ') : 'لم يتم تعيين باص بعد']
                ];
                var grid = document.createElement('div');
                grid.className = 'olama-grid olama-kpi-grid';
                fields.forEach(function(field) {
                    var item = document.createElement('div');
                    item.className = 'olama-card olama-kpi';
                    var label = document.createElement('span');
                    label.className = 'olama-kpi-label';
                    var value = document.createElement('strong');
                    value.className = 'olama-kpi-value';
                    label.textContent = field[0];
                    value.textContent = text(field[1]);
                    value.dir = 'auto';
                    item.appendChild(label);
                    item.appendChild(value);
                    grid.appendChild(item);
                });
                target.appendChild(grid);
            }

            function renderSummary(card, rows) {
                var target = document.getElementById('olama-family-transportation-summary');
                clearNode(target);
                var groups = uniqueValues(rows, [function(item) { return firstValue(item, ['group_name', 'transportation_group_name', 'category_name', 'trans_route_name']); }]);
                var buses = uniqueValues(rows, [
                    function(item) { return busText(item.arrival_bus_name, item.arrival_bus_id); },
                    function(item) { return busText(item.departure_bus_name, item.departure_bus_id); }
                ]).filter(function(value) {
                    return value !== 'لم يتم تعيين باص بعد';
                });
                var fields = [
                    ['رقم العائلة', card.family_id],
                    ['السنة الدراسية', card.study_year],
                    ['عدد الطلاب المشتركين بالمواصلات', hasValue(card.count) ? card.count : rows.length],
                    ['إجمالي رسوم المواصلات', formatAmount(totalAmount(rows))],
                    ['الفئات / الخطوط', groups.length ? groups.join('، ') : '\u2014'],
                    ['الباصات المستخدمة', buses.length ? buses.join('، ') : 'لم يتم تعيين باص بعد']
                ];
                var wrap = document.createElement('div');
                wrap.className = 'olama-info-grid';
                fields.forEach(function(field) {
                    var item = document.createElement('div');
                    item.className = 'olama-info-item';
                    var label = document.createElement('span');
                    label.className = 'olama-label';
                    var value = document.createElement('strong');
                    value.className = 'olama-value';
                    label.textContent = field[0];
                    value.textContent = text(field[1]);
                    value.dir = 'auto';
                    item.appendChild(label);
                    item.appendChild(value);
                    wrap.appendChild(item);
                });
                target.appendChild(wrap);
            }

            function renderRows(rows) {
                var target = document.getElementById('olama-family-transportation-rows');
                clearNode(target);
                if (!Array.isArray(rows) || rows.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'olama-empty';
                    empty.textContent = emptyMessage;
                    target.appendChild(empty);
                    return;
                }

                var columns = [
                    {label: 'رقم الطالب', value: function(item) { return item.student_id; }, className: 'olama-number'},
                    {label: 'اسم الطالب', value: function(item) { return item.student_name; }},
                    {label: 'الصف', value: function(item) { return item.class_name; }},
                    {label: 'الشعبة', value: function(item) { return item.section_name; }},
                    {label: 'الفئة', value: function(item) { return item.group_name; }},
                    {label: 'اتجاه المواصلات', value: function(item) { return item.trans_route_name; }},
                    {label: 'باص الحضور', value: function(item) { return busText(item.arrival_bus_name, item.arrival_bus_id); }},
                    {label: 'ترتيب الحضور', value: function(item) { return item.arrival_bus_seq; }, className: 'olama-number'},
                    {label: 'باص العودة', value: function(item) { return busText(item.departure_bus_name, item.departure_bus_id); }},
                    {label: 'ترتيب العودة', value: function(item) { return item.departure_bus_seq; }, className: 'olama-number'},
                    {label: 'من تاريخ', value: function(item) { return formatDate(item.from_date); }},
                    {label: 'إلى تاريخ', value: function(item) { return formatDate(item.to_date); }},
                    {label: 'الرسوم', value: function(item) { return formatAmount(item.trans_amount); }, className: 'olama-money'},
                    {label: 'الحالة', value: function(item) { return item.is_active_name; }, badge: true}
                ];

                var wrap = document.createElement('div');
                wrap.className = 'olama-table-wrap';
                var table = document.createElement('table');
                table.className = 'olama-table';
                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');
                columns.forEach(function(column) {
                    var th = document.createElement('th');
                    th.textContent = column.label;
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                rows.forEach(function(item) {
                    var row = document.createElement('tr');
                    columns.forEach(function(column) {
                        appendCell(row, column.value(item), column.className || '', column.badge);
                    });
                    tbody.appendChild(row);
                });
                table.appendChild(tbody);
                wrap.appendChild(table);
                target.appendChild(wrap);
            }

            function renderCard(card) {
                card = card || {};
                var rows = transportationRows(card);
                renderKpis(card, rows);
                renderSummary(card, rows);
                renderRows(rows);
                renderQuickLinks(card.family_id || document.getElementById('olama_transportation_card_family_id').value, card.study_year || document.getElementById('olama_transportation_card_study_year').value);
                results.hidden = false;
            }

            if (printButton) {
                printButton.addEventListener('click', function() {
                    window.print();
                });
            }

            if (!form || !oracleAvailable) {
                return;
            }

            form.addEventListener('submit', function(event) {
                event.preventDefault();
                var familyId = document.getElementById('olama_transportation_card_family_id').value;
                var studyYear = document.getElementById('olama_transportation_card_study_year').value;
                if (!familyId || Number(familyId) <= 0) {
                    showMessage('error', 'أدخل رقم عائلة صحيح.');
                    return;
                }

                clearNode(message);
                results.hidden = true;
                if (submit) {
                    submit.disabled = true;
                    submit.dataset.originalValue = submit.value || submit.textContent;
                    if (submit.value) {
                        submit.value = 'جاري التحميل...';
                    } else {
                        submit.textContent = 'جاري التحميل...';
                    }
                }

                var body = new URLSearchParams();
                body.append('action', 'olama_core_load_family_transportation_card');
                body.append('nonce', document.getElementById('olama_transportation_card_nonce').value);
                body.append('family_id', familyId);
                body.append('study_year', studyYear);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function(response) {
                    return response.json().catch(function() {
                        throw new Error('استجابة غير متوقعة من لوحة ووردبريس.');
                    });
                }).then(function(payload) {
                    if (!payload || !payload.success) {
                        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'تعذر تحميل بطاقة المواصلات.');
                    }
                    renderCard(payload.data.transportation || {});
                    showMessage('success', 'تم تحميل بطاقة المواصلات.');
                }).catch(function(error) {
                    showMessage('error', error.message);
                }).finally(function() {
                    if (submit) {
                        submit.disabled = false;
                        if (submit.value) {
                            submit.value = submit.dataset.originalValue || 'تحميل بطاقة المواصلات';
                        } else {
                            submit.textContent = submit.dataset.originalValue || 'تحميل بطاقة المواصلات';
                        }
                    }
                });
            });
            if (shouldAutoLoad) {
                form.dispatchEvent(new Event('submit', {cancelable: true}));
            }
        })();
        </script>
        <?php
    }

    private function student_card_assets($oracle_available, $family_id, $student_id) {
        ?>
        <script>
        (function() {
            var oracleAvailable = <?php echo $oracle_available ? 'true' : 'false'; ?>;
            var shouldAutoLoad = <?php echo ($family_id > 0 && $student_id > 0) ? 'true' : 'false'; ?>;
            var form = document.getElementById('olama-student-card-form');
            var message = document.getElementById('olama-student-card-message');
            var results = document.getElementById('olama-student-card-results');
            var printButton = document.getElementById('olama-student-card-print');
            var headerActions = document.getElementById('olama-student-card-header-actions');
            var submit = form ? form.querySelector('input[type="submit"], button[type="submit"]') : null;
            if (submit) {
                submit.type = 'button';
                submit.removeAttribute('name');
            }
            var academicEmptyMessage = 'لا توجد بيانات أكاديمية لهذا الطالب في السنة المحددة.';
            var transportationEmptyMessage = 'لا توجد بيانات مواصلات لهذا الطالب في السنة المحددة.';

            function clearNode(node) {
                while (node && node.firstChild) {
                    node.removeChild(node.firstChild);
                }
            }

            function hasValue(value) {
                return value !== null && typeof value !== 'undefined' && value !== '';
            }

            function text(value) {
                return hasValue(value) ? String(value) : '\u2014';
            }

            function busText(value) {
                return hasValue(value) && String(value) !== '0' ? String(value) : 'لم يتم تعيين باص بعد';
            }

            function firstValue(item, keys) {
                item = item || {};
                for (var i = 0; i < keys.length; i++) {
                    if (hasValue(item[keys[i]])) {
                        return item[keys[i]];
                    }
                }
                return null;
            }

            function showMessage(type, value) {
                clearNode(message);
                if (!value) {
                    return;
                }
                var notice = document.createElement('div');
                notice.className = type === 'error' ? 'olama-error' : (type === 'warning' ? 'olama-warning' : (type === 'loading' ? 'olama-loading' : 'olama-success'));
                var p = document.createElement('p');
                p.textContent = value;
                notice.appendChild(p);
                message.appendChild(notice);
            }

            function badge(value) {
                var span = document.createElement('span');
                var valueText = text(value);
                span.textContent = valueText;
                span.className = 'olama-badge';
                if (valueText === 'مستمر' || valueText === 'فعال' || valueText.toLowerCase() === 'active') {
                    span.className += ' olama-badge-success';
                } else if (valueText.indexOf('غير') !== -1 || valueText.indexOf('موقوف') !== -1 || valueText.toLowerCase() === 'inactive') {
                    span.className += ' olama-badge-warning';
                } else {
                    span.className += ' olama-badge-neutral';
                }
                return span;
            }

            function detailUrl(page, familyId, studyYear) {
                var url = new URL(ajaxurl.replace('admin-ajax.php', 'admin.php'), window.location.origin);
                url.searchParams.set('page', page);
                url.searchParams.set('family_id', String(familyId || ''));
                url.searchParams.set('study_year', String(studyYear || ''));
                return url.toString();
            }

            function renderQuickLinks(familyId, studyYear) {
                var targets = [document.getElementById('olama-student-card-quick-links'), headerActions];
                targets.forEach(function(target) {
                    clearNode(target);
                    [
                        ['olama-core-family-360', 'لوحة العائلة 360'],
                        ['olama-core-family-card', 'بطاقة العائلة'],
                        ['olama-core-family-financial-card', 'البطاقة المالية'],
                        ['olama-core-family-transportation-card', 'بطاقة المواصلات']
                    ].forEach(function(item) {
                        var link = document.createElement('a');
                        link.className = 'olama-btn olama-btn-ghost';
                        link.href = detailUrl(item[0], familyId, studyYear);
                        link.textContent = item[1];
                        target.appendChild(link);
                    });
                });
            }

            function formatDate(value) {
                if (!hasValue(value)) {
                    return '\u2014';
                }
                var raw = String(value);
                var match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
                return match ? match[1] + '-' + match[2] + '-' + match[3] : raw;
            }

            function flagText(value, yes, no) {
                if (!hasValue(value)) {
                    return '\u2014';
                }
                var key = String(value);
                if (key === '1' || key.toLowerCase() === 'true' || key === 'نعم') {
                    return yes || 'نعم';
                }
                if (key === '0' || key.toLowerCase() === 'false' || key === 'لا') {
                    return no || 'لا';
                }
                return text(value);
            }

            function healthText(value) {
                if (String(value) === '????') {
                    return '\u2014';
                }
                return text(value);
            }

            function appendField(target, label, value, formatter, className) {
                var field = document.createElement('div');
                field.className = 'olama-info-item';
                if (className) {
                    field.className += ' ' + className;
                }
                var labelNode = document.createElement('span');
                var valueNode = document.createElement('strong');
                labelNode.className = 'olama-label';
                valueNode.className = 'olama-value';
                labelNode.textContent = label;
                valueNode.textContent = formatter ? formatter(value) : text(value);
                valueNode.dir = 'auto';
                field.appendChild(labelNode);
                field.appendChild(valueNode);
                target.appendChild(field);
            }

            function renderFields(targetId, fields, emptyMessage) {
                var target = document.getElementById(targetId);
                clearNode(target);
                if (emptyMessage) {
                    var empty = document.createElement('div');
                    empty.className = 'olama-empty';
                    empty.textContent = emptyMessage;
                    target.appendChild(empty);
                    return;
                }
                var grid = document.createElement('div');
                grid.className = 'olama-info-grid';
                fields.forEach(function(field) {
                    appendField(grid, field[0], field[1], field[2], field[3] || '');
                });
                target.appendChild(grid);
            }

            function appendCell(row, value, className, formatter) {
                var td = document.createElement('td');
                td.dir = 'auto';
                if (className) {
                    td.className = className;
                }
                td.textContent = formatter ? formatter(value) : text(value);
                row.appendChild(td);
            }

            function renderTable(targetId, rows, columns, emptyMessage) {
                var target = document.getElementById(targetId);
                clearNode(target);
                if (!Array.isArray(rows) || rows.length === 0) {
                    var empty = document.createElement('div');
                    empty.className = 'olama-empty';
                    empty.textContent = emptyMessage;
                    target.appendChild(empty);
                    return;
                }

                var wrap = document.createElement('div');
                wrap.className = 'olama-table-wrap';
                var table = document.createElement('table');
                table.className = 'olama-table';
                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');
                columns.forEach(function(column) {
                    var th = document.createElement('th');
                    th.textContent = column.label;
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                rows.forEach(function(item) {
                    var row = document.createElement('tr');
                    columns.forEach(function(column) {
                        appendCell(row, column.value(item), column.className || '', column.formatter);
                    });
                    tbody.appendChild(row);
                });
                table.appendChild(tbody);
                wrap.appendChild(table);
                target.appendChild(wrap);
            }

            function appendMeta(target, label, value, asBadge) {
                var item = document.createElement('span');
                item.className = 'olama-meta-item';
                item.appendChild(document.createTextNode(label + ': '));
                if (asBadge) {
                    item.appendChild(badge(value));
                } else {
                    var strong = document.createElement('strong');
                    strong.textContent = text(value);
                    item.appendChild(strong);
                }
                target.appendChild(item);
            }

            function renderMeta(card, academic) {
                var target = document.getElementById('olama-student-card-meta');
                clearNode(target);
                target.className = 'olama-meta-row';
                appendMeta(target, 'رقم العائلة', card.family_id || document.getElementById('olama_student_card_family_id').value, false);
                appendMeta(target, 'رقم الطالب', card.student_id || document.getElementById('olama_student_card_student_id').value, false);
                appendMeta(target, 'السنة الدراسية', card.study_year || document.getElementById('olama_student_card_study_year').value, false);
                appendMeta(target, 'الحالة', academic ? firstValue(academic, ['student_status_name', 'student_status', 'status_name', 'status']) : null, true);
            }

            function renderProfile(student, academic) {
                var target = document.getElementById('olama-student-card-profile');
                clearNode(target);
                var grid = document.createElement('div');
                grid.className = 'olama-grid olama-kpi-grid olama-student-profile-grid';
                [
                    ['اسم الطالب', firstValue(student, ['student_name', 'student_full_name', 'name', 'full_name'])],
                    ['الصف الحالي', academic ? firstValue(academic, ['class_name', 'class']) : null],
                    ['الشعبة', academic ? firstValue(academic, ['section_name', 'section']) : null],
                    ['حالة الطالب', academic ? firstValue(academic, ['student_status_name', 'student_status', 'status_name', 'status']) : null, true],
                    ['الجنس', firstValue(student, ['student_gender_name', 'gender_name', 'student_gender', 'gender'])]
                ].forEach(function(field) {
                    var cardNode = document.createElement('div');
                    cardNode.className = 'olama-card olama-kpi';
                    var label = document.createElement('span');
                    label.className = 'olama-kpi-label';
                    label.textContent = field[0];
                    cardNode.appendChild(label);
                    if (field[2]) {
                        cardNode.appendChild(badge(field[1]));
                    } else {
                        var value = document.createElement('strong');
                        value.className = 'olama-kpi-value';
                        value.dir = 'auto';
                        value.textContent = text(field[1]);
                        cardNode.appendChild(value);
                    }
                    grid.appendChild(cardNode);
                });
                target.appendChild(grid);
            }

            function renderCard(card) {
                card = card || {};
                var student = card.student || {};
                var family = card.family || {};
                var academic = card.academic_current || null;
                var transportation = card.transportation_current || null;

                if (!student || Object.keys(student).length === 0) {
                    results.hidden = true;
                    showMessage('warning', 'لم يتم العثور على الطالب.');
                    return;
                }

                renderMeta(card, academic);
                renderProfile(student, academic);
                renderQuickLinks(firstValue(family, ['family_id']) || card.family_id || document.getElementById('olama_student_card_family_id').value, card.study_year || document.getElementById('olama_student_card_study_year').value);

                renderFields('olama-student-card-student', [
                    ['رقم الطالب', firstValue(student, ['student_id', 'oracle_student_id']) || card.student_id],
                    ['اسم الطالب', firstValue(student, ['student_name', 'student_full_name', 'name', 'full_name'])],
                    ['الرقم الوطني', firstValue(student, ['student_national_no', 'national_no', 'national_number'])],
                    ['الجنس', firstValue(student, ['student_gender_name', 'gender_name', 'student_gender', 'gender'])],
                    ['تاريخ الميلاد', firstValue(student, ['birth_date', 'student_birth_date', 'date_of_birth', 'dob']), formatDate],
                    ['مكان الميلاد', firstValue(student, ['birth_place', 'student_birth_place', 'place_of_birth'])],
                    ['الجنسية', firstValue(student, ['nationality_name', 'nationality'])],
                    ['الموبايل', firstValue(student, ['student_mobile', 'mobile', 'phone'])],
                    ['البريد الإلكتروني', firstValue(student, ['student_email', 'email'])],
                    ['تاريخ التسجيل', firstValue(student, ['registration_date', 'register_date', 'date_registered', 'date_created']), formatDate],
                    ['المدرسة السابقة', firstValue(student, ['previous_school', 'prev_school', 'old_school'])],
                    ['المعدل من المدرسة السابقة', firstValue(student, ['previous_school_avg', 'prev_school_avg', 'previous_average'])],
                    ['الحالة الصحية', firstValue(student, ['student_health', 'health_status', 'health']), healthText],
                    ['الحالة الاجتماعية', firstValue(student, ['social_status_name', 'social_status'])],
                    ['لاجئ / وافد', firstValue(student, ['black_list_name', 'refugee_name', 'refugee', 'black_list'])],
                    ['الديانة', firstValue(student, ['religion_name', 'religion'])],
                    ['ناجح / راسب', firstValue(student, ['pass_fail_name', 'student_result_name', 'pass_fail', 'is_passed'])],
                    ['الدخل الشهري', firstValue(student, ['monthly_income', 'student_monthly_income', 'income'])]
                ]);

                renderFields('olama-student-card-renewal', [
                    ['له تجديد', firstValue(student, ['has_renew_name', 'has_renew']), function(value) { return firstValue(student, ['has_renew_name']) || flagText(value); }],
                    ['سنة التجديد', firstValue(student, ['renew_year', 'renew_study_year'])],
                    ['تاريخ التجديد', firstValue(student, ['renew_date', 'renewal_date']), formatDate],
                    ['لن يجدد', firstValue(student, ['will_not_renew_name', 'will_not_renew']), function(value) { return firstValue(student, ['will_not_renew_name']) || flagText(value); }],
                    ['سبب عدم التجديد', firstValue(student, ['will_not_renew_reason', 'not_renew_reason', 'renew_cancel_reason'])]
                ]);

                renderFields('olama-student-card-family', [
                    ['رقم العائلة', firstValue(family, ['family_id']) || card.family_id],
                    ['اسم ولي الأمر / المعيل', firstValue(family, ['sponsor_full_name', 'sponsor_name', 'guardian_name'])],
                    ['اسم الأب', firstValue(family, ['father_name'])],
                    ['موبايل الأب', firstValue(family, ['father_mobile', 'father_phone'])],
                    ['الرقم الوطني للأب', firstValue(family, ['father_national_no', 'father_national_number'])],
                    ['اسم الأم', firstValue(family, ['mother_name'])],
                    ['موبايل الأم', firstValue(family, ['mother_mobile', 'mother_phone'])],
                    ['الرقم الوطني للأم', firstValue(family, ['mother_national_no', 'mother_national_number'])],
                    ['المنطقة', firstValue(family, ['trans_region_name', 'region_name', 'area_name'])],
                    ['العنوان', firstValue(family, ['family_address', 'address']), null, 'is-wide'],
                    ['حالة العائلة', firstValue(family, ['is_active_name', 'family_status', 'status_name', 'status'])]
                ]);

                renderFields('olama-student-card-academic-current', academic ? [
                    ['السنة الدراسية', firstValue(academic, ['study_year']) || card.study_year],
                    ['المدرسة', firstValue(academic, ['school_name', 'school'])],
                    ['الصف', firstValue(academic, ['class_name', 'class'])],
                    ['الشعبة', firstValue(academic, ['section_name', 'section'])],
                    ['الفرع', firstValue(academic, ['branch_name', 'branch'])],
                    ['حالة الطالب', firstValue(academic, ['student_status_name', 'student_status', 'status_name', 'status'])],
                    ['تاريخ التسجيل', firstValue(academic, ['registration_date', 'register_date', 'date_registered']), formatDate],
                    ['تاريخ الانسحاب', firstValue(academic, ['withdrawal_date', 'withdraw_date']), formatDate],
                    ['طالب مجدد', firstValue(academic, ['is_renewed_name', 'renewed_name', 'is_renewed'])],
                    ['الالتزام بالنظام', firstValue(academic, ['commitment_name', 'commitment_to_system', 'system_commitment'])],
                    ['عدد الغياب', firstValue(academic, ['absence_count', 'absences_count', 'absence_days'])],
                    ['نتيجة العلامة النهائية', firstValue(academic, ['final_mark_result', 'final_result', 'final_grade_result'])],
                    ['ملاحظات', firstValue(academic, ['notes', 'academic_notes']), null, 'is-wide']
                ] : [], '');

                if (!academic) {
                    renderFields('olama-student-card-academic-current', [], academicEmptyMessage);
                }

                renderTable('olama-student-card-academic-history', Array.isArray(card.academic_history) ? card.academic_history : [], [
                    {label: 'السنة الدراسية', value: function(item) { return firstValue(item, ['study_year']); }},
                    {label: 'المدرسة', value: function(item) { return firstValue(item, ['school_name', 'school']); }},
                    {label: 'الصف', value: function(item) { return firstValue(item, ['class_name', 'class']); }},
                    {label: 'الشعبة', value: function(item) { return firstValue(item, ['section_name', 'section']); }},
                    {label: 'الحالة', value: function(item) { return firstValue(item, ['student_status_name', 'student_status', 'status_name', 'status']); }},
                    {label: 'تاريخ التسجيل', value: function(item) { return firstValue(item, ['registration_date', 'register_date', 'date_registered']); }, formatter: formatDate},
                    {label: 'تاريخ الانسحاب', value: function(item) { return firstValue(item, ['withdrawal_date', 'withdraw_date']); }, formatter: formatDate},
                    {label: 'طالب مجدد', value: function(item) { return firstValue(item, ['is_renewed_name', 'renewed_name', 'is_renewed']); }},
                    {label: 'تاريخ الإنشاء', value: function(item) { return firstValue(item, ['date_created', 'created_at']); }, formatter: formatDate},
                    {label: 'آخر تعديل', value: function(item) { return firstValue(item, ['date_modified', 'updated_at', 'modified_at']); }, formatter: formatDate}
                ], academicEmptyMessage);

                renderFields('olama-student-card-transportation-current', transportation ? [
                    ['الفئة', firstValue(transportation, ['group_name', 'transportation_group_name', 'category_name'])],
                    ['اتجاه المواصلات', firstValue(transportation, ['trans_route_name', 'route_name', 'trans_route'])],
                    ['باص الحضور', firstValue(transportation, ['arrival_bus_name', 'attendance_bus_name']), busText],
                    ['ترتيب الحضور', firstValue(transportation, ['arrival_bus_seq', 'attendance_order'])],
                    ['باص العودة', firstValue(transportation, ['departure_bus_name', 'return_bus_name']), busText],
                    ['ترتيب العودة', firstValue(transportation, ['departure_bus_seq', 'return_order'])],
                    ['من تاريخ', firstValue(transportation, ['from_date']), formatDate],
                    ['إلى تاريخ', firstValue(transportation, ['to_date']), formatDate],
                    ['الرسوم', firstValue(transportation, ['trans_amount', 'amount', 'fees'])],
                    ['الحالة', firstValue(transportation, ['is_active_name', 'status_name', 'status'])]
                ] : [], '');

                if (!transportation) {
                    renderFields('olama-student-card-transportation-current', [], transportationEmptyMessage);
                }

                results.hidden = false;
            }

            if (printButton) {
                printButton.addEventListener('click', function() {
                    window.print();
                });
            }

            if (!form || !oracleAvailable) {
                return;
            }

            function loadStudentCard(event) {
                if (event && event.preventDefault) {
                    event.preventDefault();
                }
                var familyId = document.getElementById('olama_student_card_family_id').value;
                var studentId = document.getElementById('olama_student_card_student_id').value;
                var studyYear = document.getElementById('olama_student_card_study_year').value;
                if (!familyId || Number(familyId) <= 0) {
                    showMessage('error', 'أدخل رقم عائلة صحيح.');
                    return;
                }
                if (!studentId || Number(studentId) <= 0) {
                    showMessage('error', 'أدخل رقم طالب صحيح.');
                    return;
                }

                clearNode(message);
                clearNode(headerActions);
                clearNode(document.getElementById('olama-student-card-meta'));
                clearNode(document.getElementById('olama-student-card-profile'));
                clearNode(document.getElementById('olama-student-card-quick-links'));
                results.hidden = true;
                showMessage('loading', 'جاري تحميل بطاقة الطالب...');
                if (submit) {
                    submit.disabled = true;
                    submit.dataset.originalValue = submit.value || submit.textContent;
                    if (submit.value) {
                        submit.value = 'جاري التحميل...';
                    } else {
                        submit.textContent = 'جاري التحميل...';
                    }
                }

                var body = new URLSearchParams();
                body.append('action', 'olama_core_load_student_card');
                body.append('nonce', document.getElementById('olama_student_card_nonce').value);
                body.append('family_id', familyId);
                body.append('student_id', studentId);
                body.append('study_year', studyYear);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function(response) {
                    return response.json().catch(function() {
                        throw new Error('استجابة غير متوقعة من لوحة ووردبريس.');
                    });
                }).then(function(payload) {
                    if (!payload || !payload.success) {
                        throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'تعذر تحميل بطاقة الطالب.');
                    }
                    renderCard(payload.data.card || {});
                    if (!results.hidden) {
                        showMessage('success', 'تم تحميل بطاقة الطالب.');
                    }
                }).catch(function(error) {
                    showMessage('error', error.message);
                }).finally(function() {
                    if (submit) {
                        submit.disabled = false;
                        if (submit.value) {
                            submit.value = submit.dataset.originalValue || 'تحميل بطاقة الطالب';
                        } else {
                            submit.textContent = submit.dataset.originalValue || 'تحميل بطاقة الطالب';
                        }
                    }
                });
            }

            form.addEventListener('submit', loadStudentCard);
            if (submit) {
                submit.addEventListener('click', loadStudentCard);
            }

            if (shouldAutoLoad) {
                loadStudentCard();
            }
        })();
        </script>
        <?php
    }

    public function health() {
        global $wpdb, $wp_version;

        echo '<div class="wrap"><h1>Olama Core Health</h1>';
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>Plugin active</th><td>Yes</td></tr>';
        echo '<tr><th>Plugin version</th><td>' . esc_html(OLAMA_CORE_VERSION) . '</td></tr>';
        echo '<tr><th>WordPress version</th><td>' . esc_html($wp_version) . '</td></tr>';
        echo '<tr><th>PHP version</th><td>' . esc_html(PHP_VERSION) . '</td></tr>';
        echo '<tr><th>Current DB version</th><td>' . esc_html(get_option('olama_core_db_version', 'Not set')) . '</td></tr>';
        foreach (Olama_Core_Migrator::required_tables() as $table) {
            $exists = Olama_Core_Migrator::table_exists($table);
            $count = $exists ? (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`') : 0;
            echo '<tr><th>' . esc_html($table) . '</th><td>' . esc_html($exists ? 'Exists, rows: ' . $count : 'Missing') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function stat_cards($stats) {
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
        foreach ($stats as $label => $value) {
            echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px;min-width:180px;"><strong style="display:block;font-size:24px;">' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></div>';
        }
        echo '</div>';
    }

    private function simple_table($rows, $columns) {
        echo '<table class="widefat striped"><thead><tr>';
        foreach ($columns as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="' . esc_attr(count($columns)) . '">No records found.</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $key => $label) {
                echo '<td>' . esc_html(isset($row[$key]) ? $row[$key] : '') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private function paged_rows($table, $total = null) {
        global $wpdb;

        $limit = $this->limit($total);
        $offset = $this->offset($limit);

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($table) . '` ORDER BY id DESC LIMIT %d OFFSET %d',
            $limit,
            $offset
        ), ARRAY_A);
    }

    private function pagination_controls($page_slug, $total) {
        $total = max(0, (int) $total);
        $per_page = $this->per_page_value();
        $limit = $this->limit($total);
        $current = $this->current_page();
        $total_pages = $per_page === 'all' ? 1 : max(1, (int) ceil($total / $limit));
        $current = min($current, $total_pages);

        echo '<div class="tablenav top" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 0;">';
        echo '<div class="alignleft actions">';
        echo '<form method="get" style="display:flex;align-items:center;gap:8px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
        echo '<label for="olama_per_page_' . esc_attr($page_slug) . '">Rows per page</label>';
        echo '<select id="olama_per_page_' . esc_attr($page_slug) . '" name="per_page">';
        foreach (array('50' => '50', '100' => '100', '200' => '200', '500' => '500', 'all' => 'All') as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($per_page, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        submit_button('Apply', 'secondary', '', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="tablenav-pages">';
        echo '<span class="displaying-num">' . esc_html(number_format_i18n($total)) . ' records</span> ';
        if ($per_page !== 'all' && $total_pages > 1) {
            echo $this->page_link($page_slug, 1, '&laquo;', $current <= 1);
            echo $this->page_link($page_slug, max(1, $current - 1), '&lsaquo;', $current <= 1);
            echo '<span class="paging-input"> Page ' . esc_html(number_format_i18n($current)) . ' of ' . esc_html(number_format_i18n($total_pages)) . ' </span>';
            echo $this->page_link($page_slug, min($total_pages, $current + 1), '&rsaquo;', $current >= $total_pages);
            echo $this->page_link($page_slug, $total_pages, '&raquo;', $current >= $total_pages);
        } else {
            echo '<span class="paging-input"> Showing all records </span>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function page_link($page_slug, $paged, $label, $disabled) {
        if ($disabled) {
            return '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . wp_kses_post($label) . '</span> ';
        }

        $url = add_query_arg(array(
            'page' => $page_slug,
            'paged' => max(1, absint($paged)),
            'per_page' => $this->per_page_value(),
        ), admin_url('admin.php'));

        return '<a class="button" href="' . esc_url($url) . '">' . wp_kses_post($label) . '</a> ';
    }

    private function per_page_value() {
        if (isset($_GET['per_page']) && sanitize_text_field(wp_unslash($_GET['per_page'])) === 'all') {
            return 'all';
        }

        return isset($_GET['per_page']) ? (string) max(1, min(500, absint($_GET['per_page']))) : '50';
    }

    private function limit($total = null) {
        if ($this->per_page_value() === 'all') {
            return $total ? max(1, (int) $total) : 999999;
        }

        return max(1, min(500, absint($this->per_page_value())));
    }

    private function offset($limit) {
        if ($this->per_page_value() === 'all') {
            return 0;
        }

        $paged = $this->current_page();
        return ($paged - 1) * $limit;
    }

    private function current_page() {
        return isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    }
}
