<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Users_Admin {
    private $core;

    public function __construct(Olama_Core_Container $core) {
        $this->core = $core;
    }

    public function init() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
    }

    public function register_menu() {
        add_submenu_page(
            'olama-core',
            __('Users & Permissions', 'olama-core'),
            __('Users & Permissions', 'olama-core'),
            'olama_access_users_mgmt',
            'olama-core-users',
            array($this, 'render')
        );

        // Preserve old bookmarks and redirects without leaving a visible School submenu.
        add_submenu_page(
            null,
            __('Users & Permissions', 'olama-core'),
            __('Users & Permissions', 'olama-core'),
            'olama_access_users_mgmt',
            'olama-school-users',
            array($this, 'redirect_legacy_page')
        );
    }

    public function redirect_legacy_page() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $import = isset($_GET['import']) ? sanitize_key(wp_unslash($_GET['import'])) : '';
        $url = add_query_arg(array_filter(array('page' => 'olama-core-users', 'tab' => $tab, 'import' => $import)), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function handle_actions() {
        if (!isset($_POST['olama_core_users_action'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['olama_core_users_action']));
        if ('save_staff' === $action) {
            $this->save_staff();
        } elseif ('save_permissions' === $action) {
            $this->save_permissions();
        } elseif ('save_notifications' === $action) {
            $this->save_notifications();
        }
    }

    private function save_staff() {
        $this->authorize('olama_manage_users_teachers', 'olama_core_save_staff');
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $result = $this->core->staff()->save($user_id, array(
            'employee_id' => isset($_POST['employee_id']) ? wp_unslash($_POST['employee_id']) : '',
            'phone_number' => isset($_POST['phone_number']) ? wp_unslash($_POST['phone_number']) : '',
        ));

        if (is_wp_error($result)) {
            $this->redirect_with_notice('teachers', $result->get_error_message(), 'error');
        }

        Olama_Core_Logger::log('staff_profile_updated', sprintf('Updated staff profile for user #%d.', $user_id), 'core');
        $this->redirect_with_notice('teachers', __('Staff profile saved.', 'olama-core'));
    }

    private function save_permissions() {
        $this->authorize('olama_manage_users_permissions', 'olama_core_save_permissions');
        wp_safe_redirect(admin_url('admin.php?page=olama-users-matrix'));
        exit;
    }

    private function save_notifications() {
        $this->authorize('olama_manage_users_logs', 'olama_core_save_notifications');
        $email = isset($_POST['olama_admin_email']) ? sanitize_email(wp_unslash($_POST['olama_admin_email'])) : '';
        if (!$email || !is_email($email)) {
            $this->redirect_with_notice('logs', __('Enter a valid notification email.', 'olama-core'), 'error');
        }
        update_option('olama_admin_email', $email);
        update_option('olama_enable_notifs', isset($_POST['olama_enable_notifs']) && 'yes' === $_POST['olama_enable_notifs'] ? 'yes' : 'no');
        Olama_Core_Logger::log('notification_settings_updated', 'Updated audit notification settings.', 'core');
        $this->redirect_with_notice('logs', __('Notification settings saved.', 'olama-core'));
    }

    private function authorize($capability, $nonce_action) {
        if (!Olama_Core_Permissions::can($capability)) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'olama-core'), '', array('response' => 403));
        }
        check_admin_referer($nonce_action);
    }

    private function redirect_with_notice($tab, $message, $type = 'success') {
        $url = add_query_arg(array(
            'page' => 'olama-core-users',
            'tab' => sanitize_key($tab),
            'olama_notice' => $message,
            'olama_notice_type' => 'error' === $type ? 'error' : 'success',
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function render() {
        if (!Olama_Core_Permissions::can('olama_access_users_mgmt')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'olama-core'), '', array('response' => 403));
        }

        $tabs = $this->allowed_tabs();
        if (!$tabs) {
            wp_die(esc_html__('You do not have access to any section on this page.', 'olama-core'), '', array('response' => 403));
        }
        $active = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : array_key_first($tabs);
        if (!isset($tabs[$active])) {
            $active = array_key_first($tabs);
        }

        echo '<div class="wrap olama-core-admin"><div class="olama-page">';
        echo '<header class="olama-page-header"><div><h1 class="olama-page-title">' . esc_html__('Users & Permissions', 'olama-core') . '</h1>';
        echo '<p class="olama-page-subtitle">' . esc_html__('Shared family, student, staff, permissions, and audit administration for Olama.', 'olama-core') . '</p></div></header>';
        $this->render_notice();
        echo '<nav class="olama-tabs" aria-label="' . esc_attr__('Users and permissions sections', 'olama-core') . '">';
        foreach ($tabs as $id => $tab) {
            $url = add_query_arg(array('page' => 'olama-core-users', 'tab' => $id), admin_url('admin.php'));
            echo '<a class="olama-tab' . ($active === $id ? ' is-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($tab['label']) . '</a>';
        }
        echo '</nav>';

        if ('families' === $active) {
            $this->render_families();
        } elseif ('students' === $active) {
            $this->render_students();
        } elseif ('teachers' === $active) {
            $this->render_teachers();
        } elseif ('permissions' === $active) {
            $this->render_permissions();
        } else {
            $this->render_logs();
        }
        echo '</div></div>';
    }

    private function allowed_tabs() {
        $tabs = array(
            'families' => array('label' => __('Families', 'olama-core'), 'cap' => 'olama_manage_users_families'),
            'students' => array('label' => __('Students / Enrollment', 'olama-core'), 'cap' => 'olama_manage_users_students'),
            'teachers' => array('label' => __('Staff', 'olama-core'), 'cap' => 'olama_manage_users_teachers'),
            'permissions' => array('label' => __('Permissions', 'olama-core'), 'cap' => 'olama_manage_users_permissions'),
            'logs' => array('label' => __('Activity Logs', 'olama-core'), 'cap' => 'olama_manage_users_logs'),
        );
        return array_filter($tabs, function ($tab) {
            return Olama_Core_Permissions::can($tab['cap']);
        });
    }

    private function render_notice() {
        if (empty($_GET['olama_notice'])) {
            return;
        }
        $message = sanitize_text_field(wp_unslash($_GET['olama_notice']));
        $class = isset($_GET['olama_notice_type']) && 'error' === $_GET['olama_notice_type'] ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function render_families() {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_core_families';
        $student_table = $wpdb->prefix . 'olama_core_students';
        list($where, $values, $search) = $this->search_where('f', array('family_uid', 'oracle_family_id', 'sponsor_full_name', 'father_name', 'father_mobile', 'mother_mobile'));
        $limit = 30;
        $page = $this->current_page();
        $total = (int) $wpdb->get_var($values ? $wpdb->prepare('SELECT COUNT(*) FROM `' . esc_sql($table) . '` f' . $where, $values) : 'SELECT COUNT(*) FROM `' . esc_sql($table) . '` f');
        $query_values = array_merge($values, array($limit, ($page - 1) * $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT f.*, COUNT(s.id) AS actual_students_count FROM `' . esc_sql($table) . '` f LEFT JOIN `' . esc_sql($student_table) . '` s ON s.family_uid=f.family_uid' . $where . ' GROUP BY f.id ORDER BY f.id DESC LIMIT %d OFFSET %d',
            $query_values
        ), ARRAY_A);
        $this->render_read_only_intro(__('Families synchronized from Oracle ERP', 'olama-core'));
        $this->render_search('families', $search, __('Search by family number, name, or mobile', 'olama-core'));
        echo '<div class="olama-table-wrap"><table class="olama-table"><thead><tr>';
        foreach (array(__('Family ID', 'olama-core'), __('Family / Sponsor', 'olama-core'), __('Father Mobile', 'olama-core'), __('Mother Mobile', 'olama-core'), __('Students', 'olama-core'), __('Status', 'olama-core'), __('Actions', 'olama-core')) as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            $this->empty_row(7);
        }
        foreach ($rows as $row) {
            $family_id = $row['oracle_family_id'];
            echo '<tr><td>' . esc_html($family_id) . '</td><td>' . esc_html($this->first_value($row, array('sponsor_full_name', 'father_name', 'family_uid'))) . '</td>';
            echo '<td>' . esc_html($this->display($row['father_mobile'])) . '</td><td>' . esc_html($this->display($row['mother_mobile'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['actual_students_count'])) . '</td><td>' . esc_html($this->display($this->first_value($row, array('family_status_name', 'family_status')))) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(array('page' => 'olama-core-family-360', 'family_id' => $family_id), admin_url('admin.php'))) . '">' . esc_html__('Open 360', 'olama-core') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
        $this->render_pagination('families', $total, $limit, $page, $search);
    }

    private function render_students() {
        global $wpdb;
        $students = $wpdb->prefix . 'olama_core_students';
        $years = $wpdb->prefix . 'olama_core_student_years';
        list($where, $values, $search) = $this->search_where('s', array('student_uid', 'oracle_student_id', 'student_name', 'student_national_no', 'oracle_family_id'));
        $limit = 30;
        $page = $this->current_page();
        $total = (int) $wpdb->get_var($values ? $wpdb->prepare('SELECT COUNT(*) FROM `' . esc_sql($students) . '` s' . $where, $values) : 'SELECT COUNT(*) FROM `' . esc_sql($students) . '` s');
        $query_values = array_merge($values, array($limit, ($page - 1) * $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT s.*, y.study_year, y.class_name, y.section_name, y.student_status_name AS year_status FROM `' . esc_sql($students) . '` s LEFT JOIN `' . esc_sql($years) . '` y ON y.id=(SELECT y2.id FROM `' . esc_sql($years) . '` y2 WHERE y2.student_uid=s.student_uid ORDER BY y2.study_year DESC LIMIT 1)' . $where . ' ORDER BY s.id DESC LIMIT %d OFFSET %d',
            $query_values
        ), ARRAY_A);
        $this->render_read_only_intro(__('Students and latest enrollment synchronized from Oracle ERP', 'olama-core'));
        $this->render_search('students', $search, __('Search by student, family, or national number', 'olama-core'));
        echo '<div class="olama-table-wrap"><table class="olama-table"><thead><tr>';
        foreach (array(__('Student ID', 'olama-core'), __('Name', 'olama-core'), __('Family ID', 'olama-core'), __('Study Year', 'olama-core'), __('Class', 'olama-core'), __('Section', 'olama-core'), __('Status', 'olama-core'), __('Actions', 'olama-core')) as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            $this->empty_row(8);
        }
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['oracle_student_id']) . '</td><td>' . esc_html($row['student_name']) . '</td><td>' . esc_html($row['oracle_family_id']) . '</td>';
            echo '<td>' . esc_html($this->display($row['study_year'])) . '</td><td>' . esc_html($this->display($row['class_name'])) . '</td><td>' . esc_html($this->display($row['section_name'])) . '</td>';
            echo '<td>' . esc_html($this->display($this->first_value($row, array('year_status', 'student_status_name', 'student_status')))) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(array('page' => 'olama-core-student-card', 'family_id' => $row['oracle_family_id'], 'student_id' => $row['oracle_student_id']), admin_url('admin.php'))) . '">' . esc_html__('Open card', 'olama-core') . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
        $this->render_pagination('students', $total, $limit, $page, $search);
        do_action('olama_core_users_after_students', $rows);
    }

    private function render_teachers() {
        $users = get_users(array(
            'role__in' => array('administrator', 'editor', 'supervisor', 'author', 'teacher', 'assistant', 'accountant'),
            'orderby' => 'display_name',
            'order' => 'ASC',
        ));
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">' . esc_html__('WordPress staff users', 'olama-core') . '</h2></div>';
        echo '<div class="olama-table-wrap"><table class="olama-table"><thead><tr><th>' . esc_html__('Name', 'olama-core') . '</th><th>' . esc_html__('Email', 'olama-core') . '</th><th>' . esc_html__('Roles', 'olama-core') . '</th><th>' . esc_html__('Employee ID', 'olama-core') . '</th><th>' . esc_html__('Phone', 'olama-core') . '</th><th>' . esc_html__('Save', 'olama-core') . '</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $profile = $this->core->staff()->get($user->ID);
            $form_id = 'olama-staff-' . $user->ID;
            echo '<tr><td>' . esc_html($user->display_name) . '</td><td>' . esc_html($user->user_email) . '</td><td>' . esc_html(implode(', ', $user->roles)) . '</td>';
            echo '<td><input form="' . esc_attr($form_id) . '" class="regular-text" type="text" name="employee_id" value="' . esc_attr($profile ? $profile['employee_id'] : '') . '"></td>';
            echo '<td><input form="' . esc_attr($form_id) . '" class="regular-text" type="text" name="phone_number" value="' . esc_attr($profile ? $profile['phone_number'] : '') . '"></td><td><form id="' . esc_attr($form_id) . '" method="post">';
            wp_nonce_field('olama_core_save_staff');
            echo '<input type="hidden" name="olama_core_users_action" value="save_staff"><input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '"><button class="button button-primary" type="submit">' . esc_html__('Save', 'olama-core') . '</button></form></td></tr>';
        }
        echo '</tbody></table></div></section>';
    }

    private function render_permissions() {
        echo '<section class="olama-section"><h2 class="olama-section-title">' . esc_html__('Role capabilities', 'olama-core') . '</h2>';
        echo '<p>' . esc_html__('Capabilities are managed centrally by OLAMA Users.', 'olama-core') . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=olama-users-matrix')) . '">' . esc_html__('Open OLAMA Users capabilities', 'olama-core') . '</a></p></section>';
    }

    private function render_logs() {
        global $wpdb;
        $logs = $wpdb->get_results(
            "SELECT l.*, u.display_name FROM {$wpdb->prefix}olama_logs l LEFT JOIN {$wpdb->users} u ON u.ID=l.user_id ORDER BY l.created_at DESC LIMIT 100"
        );
        echo '<section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">' . esc_html__('Recent activity', 'olama-core') . '</h2></div><div class="olama-table-wrap"><table class="olama-table"><thead><tr><th>' . esc_html__('Date', 'olama-core') . '</th><th>' . esc_html__('Source', 'olama-core') . '</th><th>' . esc_html__('User', 'olama-core') . '</th><th>' . esc_html__('Action', 'olama-core') . '</th><th>' . esc_html__('Details', 'olama-core') . '</th><th>' . esc_html__('IP address', 'olama-core') . '</th></tr></thead><tbody>';
        if (!$logs) {
            $this->empty_row(6);
        }
        foreach ($logs as $log) {
            echo '<tr><td>' . esc_html($log->created_at) . '</td><td>' . esc_html(isset($log->source) ? $log->source : 'school') . '</td><td>' . esc_html($log->display_name ? $log->display_name : __('System', 'olama-core')) . '</td><td>' . esc_html($log->action) . '</td><td>' . esc_html($log->details) . '</td><td>' . esc_html($log->ip_address) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';

        echo '<form method="post"><section class="olama-section"><div class="olama-section-header"><h2 class="olama-section-title">' . esc_html__('Notification settings', 'olama-core') . '</h2></div><table class="form-table"><tr><th><label for="olama_admin_email">' . esc_html__('Notification email', 'olama-core') . '</label></th><td><input class="regular-text" id="olama_admin_email" type="email" name="olama_admin_email" value="' . esc_attr(get_option('olama_admin_email', get_option('admin_email'))) . '"></td></tr><tr><th>' . esc_html__('Email notifications', 'olama-core') . '</th><td><select name="olama_enable_notifs"><option value="yes" ' . selected(get_option('olama_enable_notifs', 'yes'), 'yes', false) . '>' . esc_html__('Enabled', 'olama-core') . '</option><option value="no" ' . selected(get_option('olama_enable_notifs', 'yes'), 'no', false) . '>' . esc_html__('Disabled', 'olama-core') . '</option></select></td></tr></table>';
        wp_nonce_field('olama_core_save_notifications');
        echo '<input type="hidden" name="olama_core_users_action" value="save_notifications">';
        echo '<p class="submit"><button class="button button-primary" type="submit">' . esc_html__('Save settings', 'olama-core') . '</button></p>';
        echo '</section></form>';
    }

    private function render_read_only_intro($title) {
        echo '<div class="olama-info-notice"><strong>' . esc_html($title) . '</strong><p>' . esc_html__('These records are synchronized source data. Changes must be made in the source system and synchronized again.', 'olama-core') . '</p></div>';
    }

    private function render_search($tab, $value, $placeholder) {
        echo '<form class="olama-filter-card" method="get"><input type="hidden" name="page" value="olama-core-users"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"><div class="olama-filter-grid"><p><label class="olama-label" for="olama-users-search">' . esc_html__('Search', 'olama-core') . '</label><input id="olama-users-search" type="search" name="s" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"></p><p class="olama-filter-submit"><button class="button button-primary" type="submit">' . esc_html__('Search', 'olama-core') . '</button></p></div></form>';
    }

    private function search_where($alias, array $columns) {
        global $wpdb;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        if ('' === $search) {
            return array('', array(), '');
        }
        $like = '%' . $wpdb->esc_like($search) . '%';
        $clauses = array();
        foreach ($columns as $column) {
            $clauses[] = $alias . '.`' . esc_sql($column) . '` LIKE %s';
        }
        return array(' WHERE (' . implode(' OR ', $clauses) . ')', array_fill(0, count($columns), $like), $search);
    }

    private function render_pagination($tab, $total, $limit, $page, $search) {
        $pages = max(1, (int) ceil($total / $limit));
        if ($pages < 2) {
            return;
        }
        $base = add_query_arg(array('page' => 'olama-core-users', 'tab' => $tab, 's' => $search, 'paged' => '%#%'), admin_url('admin.php'));
        echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(array('base' => $base, 'format' => '', 'current' => $page, 'total' => $pages))) . '</div></div>';
    }

    private function current_page() {
        return isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    }

    private function editable_roles() {
        return array(
            'administrator' => __('Administrator', 'olama-core'),
            'editor' => __('Supervisor', 'olama-core'),
            'teacher' => __('Teacher', 'olama-core'),
            'author' => __('Assistant', 'olama-core'),
            'accountant' => __('Accountant', 'olama-core'),
            'os_warehouse_manager' => __('Warehouse Manager', 'olama-core'),
            'os_warehouse_staff' => __('Warehouse Staff', 'olama-core'),
            'os_viewer' => __('Stores Viewer', 'olama-core'),
        );
    }

    private function sync_mirror_roles() {
        // Role and capability synchronization belongs to OLAMA Users.
    }

    private function empty_row($columns) {
        echo '<tr><td colspan="' . esc_attr($columns) . '">' . esc_html__('No matching records found.', 'olama-core') . '</td></tr>';
    }

    private function first_value(array $row, array $keys) {
        foreach ($keys as $key) {
            if (isset($row[$key]) && '' !== (string) $row[$key]) {
                return $row[$key];
            }
        }
        return '';
    }

    private function display($value) {
        return null === $value || '' === (string) $value ? '—' : $value;
    }
}
