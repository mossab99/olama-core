<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Permissions {
    public static function init() {
        if (get_option('olama_core_caps_version') === OLAMA_CORE_VERSION) {
            return;
        }

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
        $groups = self::get_all_capabilities();
        foreach (array('administrator') as $role_name) {
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
        return user_can($user_id, $capability);
    }

    public static function grant_supervisor_capabilities($allcaps, $caps, $args, $user) {
        $requested = isset($args[0]) ? (string) $args[0] : '';
        $is_olama_cap = $requested && strpos($requested, 'olama_') === 0;
        $is_administrator = in_array('administrator', (array) $user->roles, true);
        if ($is_olama_cap && $is_administrator) {
            $allcaps[$requested] = true;
        }
        return $allcaps;
    }
}
