<?php
/**
 * Plugin Name: Olama Core
 * Description: Clean Oracle-backed core family and student foundation for Olama plugins.
 * Version: 0.1.1
 * Author: Olama
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OLAMA_CORE_VERSION', '0.1.1');
define('OLAMA_CORE_FILE', __FILE__);
define('OLAMA_CORE_PATH', plugin_dir_path(__FILE__));
define('OLAMA_CORE_URL', plugin_dir_url(__FILE__));

require_once OLAMA_CORE_PATH . 'includes/class-olama-core-migrator.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-repository.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-family-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-student-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-student-year-service.php';
require_once OLAMA_CORE_PATH . 'includes/class-olama-core-container.php';
require_once OLAMA_CORE_PATH . 'admin/class-olama-core-admin.php';

function olama_core() {
    return Olama_Core_Container::instance();
}

register_activation_hook(__FILE__, array('Olama_Core_Migrator', 'activate'));

add_action('plugins_loaded', array(olama_core(), 'init'));
