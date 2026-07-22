<?php
/**
 * Plugin Name: Olama Core
 * Description: Clean Oracle-backed core family and student foundation for Olama plugins.
 * Version: 0.6.2
 * Author: Olama
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_CORE_VERSION', '0.6.2');
define('OLAMA_CORE_FILE', __FILE__);
define('OLAMA_CORE_PATH', plugin_dir_path(__FILE__));
define('OLAMA_CORE_URL', plugin_dir_url(__FILE__));

require_once OLAMA_CORE_PATH . 'includes/class-olama-core-migrator.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-repository.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-family-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-student-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-student-year-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-financial-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-transportation-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-transport-master-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-knowledge-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-audience-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-staff-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-employee-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-academic-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-permissions.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-logger.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-container.php';
require_once OLAMA_CORE_PATH . 'admin/class-olama-core-admin.php';
require_once OLAMA_CORE_PATH . 'admin/class-olama-core-users-admin.php';

function olama_core() {
    return Olama_Core_Container::instance();
}

/**
 * Register Olama Core in the Olama Hub module registry.
 */
function olama_core_register_hub_card($cards) {
    foreach ($cards as $card) {
        if (($card['id'] ?? '') === 'olama-core') {
            return $cards;
        }
    }

    $cards[] = array(
        'id' => 'olama-core',
        'label' => __('Olama Core', 'olama-core'),
        'description' => __('Shared family, student, academic, enrollment, staff, and permissions foundation.', 'olama-core'),
        'icon' => 'dashicons-database-view',
        'accent' => '#0f766e',
        'accent_rgb' => '15,118,110',
        'active' => true,
        'capability' => 'olama_access_users_mgmt',
        'primary_url' => admin_url('admin.php?page=olama-core'),
        'submenus' => array(
            array(
                'id' => 'core.dashboard',
                'label' => __('Dashboard', 'olama-core'),
                'icon' => 'dashicons-dashboard',
                'url' => admin_url('admin.php?page=olama-core'),
                'capability' => 'olama_access_users_mgmt',
                'color' => '#0f766e',
            ),
            array(
                'id' => 'core.directory',
                'label' => __('Directory', 'olama-core'),
                'icon' => 'dashicons-search',
                'url' => admin_url('admin.php?page=olama-core-directory'),
                'capability' => 'manage_options',
                'color' => '#0f766e',
            ),
            array(
                'id' => 'core.academic-info',
                'label' => __('Academic Info', 'olama-core'),
                'icon' => 'dashicons-welcome-learn-more',
                'url' => admin_url('admin.php?page=olama-core-academic-info'),
                'capability' => 'manage_options',
                'color' => '#0f766e',
            ),
            array(
                'id' => 'core.employee-360',
                'label' => __('Employee 360', 'olama-core'),
                'icon' => 'dashicons-id-alt',
                'url' => admin_url('admin.php?page=olama-core-employee-360'),
                'capability' => 'manage_options',
                'color' => '#0f766e',
            ),
            array(
                'id' => 'core.users',
                'label' => __('Users & Permissions', 'olama-core'),
                'icon' => 'dashicons-groups',
                'url' => admin_url('admin.php?page=olama-core-users'),
                'capability' => 'olama_access_users_mgmt',
                'color' => '#0f766e',
            ),
        ),
    );

    return $cards;
}
add_filter('olama_dashboard_cards', 'olama_core_register_hub_card', 20);

register_activation_hook(__FILE__, array('Olama_Core_Migrator', 'activate'));

add_action('plugins_loaded', array(olama_core(), 'init'));
