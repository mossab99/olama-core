<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Permissions {
    private static $runtime_filter_added = false;

    public static function init() {
        if (!self::$runtime_filter_added) {
            self::$runtime_filter_added = true;
            add_filter('user_has_cap', array(__CLASS__, 'grant_supervisor_capabilities'), 10, 4);
        }

        if (get_option('olama_core_caps_version') === OLAMA_CORE_VERSION) {
            return;
        }

        self::add_capabilities();
        update_option('olama_core_caps_version', OLAMA_CORE_VERSION);
    }

    public static function get_all_capabilities() {
        $groups = array(
            'users' => array(
                'label' => __('Users & Permissions', 'olama-core'),
                'caps' => array(
                    'olama_access_users_mgmt' => __('Access management', 'olama-core'),
                    'olama_manage_users_families' => __('View families', 'olama-core'),
                    'olama_manage_users_students' => __('View students and enrollment', 'olama-core'),
                    'olama_manage_users_teachers' => __('Manage staff profiles', 'olama-core'),
                    'olama_manage_users_permissions' => __('Manage permissions', 'olama-core'),
                    'olama_manage_users_logs' => __('View activity logs', 'olama-core'),
                ),
            ),
        );

        return apply_filters('olama_core_capability_groups', $groups);
    }

    public static function add_capabilities() {
        $author = get_role('author');
        $editor = get_role('editor');
        if (!get_role('teacher') && $author) {
            add_role('teacher', __('Teacher', 'olama-core'), $author->capabilities);
        }
        if (!get_role('supervisor') && $editor) {
            add_role('supervisor', __('Supervisor', 'olama-core'), $editor->capabilities);
        }
        if (!get_role('assistant') && $author) {
            add_role('assistant', __('Assistant', 'olama-core'), $author->capabilities);
        }
        if (!get_role('accountant') && $author) {
            add_role('accountant', __('Accountant', 'olama-core'), $author->capabilities);
        }

        $groups = self::get_all_capabilities();
        foreach (array('administrator', 'editor', 'supervisor') as $role_name) {
            $role = get_role($role_name);
            if (!$role) {
                continue;
            }
            foreach ($groups as $group) {
                foreach ((array) $group['caps'] as $capability => $label) {
                    $role->add_cap($capability);
                }
            }
        }
    }

    public static function can($capability, $user_id = null) {
        $user_id = $user_id ? absint($user_id) : get_current_user_id();
        if (!$user_id) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $user = get_userdata($user_id);
        if ($user && in_array('supervisor', (array) $user->roles, true) && strpos($capability, 'olama_') === 0) {
            return true;
        }
        return user_can($user_id, $capability);
    }

    public static function grant_supervisor_capabilities($allcaps, $caps, $args, $user) {
        $requested = isset($args[0]) ? (string) $args[0] : '';
        $is_olama_cap = $requested && strpos($requested, 'olama_') === 0;
        $is_privileged = !empty($allcaps['manage_options']) || in_array('supervisor', (array) $user->roles, true);
        if ($is_olama_cap && $is_privileged) {
            $allcaps[$requested] = true;
        }
        return $allcaps;
    }
}
