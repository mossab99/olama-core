<?php
/**
 * Plugin Name: Olama Core
 * Description: Shared operational data platform for people, academics, finance, transportation, and Olama integrations.
 * Version: 1.0.0
 * Author: Olama
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_CORE_VERSION', '1.0.0');
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
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-academic-calendar-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-academic-context-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-year-closeout-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-sync-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-read-model-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-permissions.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-logger.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-container.php';
require_once OLAMA_CORE_PATH . 'admin/class-olama-core-admin.php';
require_once OLAMA_CORE_PATH . 'admin/class-olama-core-academic-admin.php';
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
        'description' => __('Shared operational data platform for people, academics, finance, transportation, and Olama integrations.', 'olama-core'),
        'icon' => 'dashicons-database-view',
        'accent' => '#0f766e',
        'accent_rgb' => '15,118,110',
        'active' => true,
        'capability' => 'manage_options',
        'primary_url' => admin_url('admin.php?page=olama-core'),
        'submenus' => array(
            array(
                'id' => 'core.dashboard',
                'label' => __('Overview', 'olama-core'),
                'icon' => 'dashicons-dashboard',
                'url' => admin_url('admin.php?page=olama-core'),
                'capability' => 'manage_options',
                'color' => '#0f766e',
            ),
            array(
                'id' => 'core.directory',
                'label' => __('Data Explorer', 'olama-core'),
                'icon' => 'dashicons-search',
                'url' => admin_url('admin.php?page=olama-core-directory'),
                'capability' => 'manage_options',
                'color' => '#0f766e',
            ),
            array(
                'id' => 'core.profiles',
                'label' => __('Profiles', 'olama-core'),
                'icon' => 'dashicons-id-alt',
                'url' => admin_url('admin.php?page=olama-core-profiles'),
                'capability' => 'manage_options',
                'color' => '#0f766e',
            ),
            array(
                'id' => 'core.academic-calendar',
                'label' => __('Academic Operations', 'olama-core'),
                'icon' => 'dashicons-calendar-alt',
                'url' => admin_url('admin.php?page=olama-core-academic-calendar'),
                'capability' => 'manage_olama_academic_context',
                'color' => '#0f766e',
            ),
            array(
                'id' => 'core.health',
                'label' => __('System Health', 'olama-core'),
                'icon' => 'dashicons-heart',
                'url' => admin_url('admin.php?page=olama-core-health'),
                'capability' => 'manage_options',
                'color' => '#0f766e',
            ),
        ),
    );

    return $cards;
}
add_filter('olama_dashboard_cards', 'olama_core_register_hub_card', 20);

register_activation_hook(__FILE__, array('Olama_Core_Migrator', 'activate'));

add_action('plugins_loaded', array(olama_core(), 'init'));
