<?php
/**
 * Plugin Name: MV Portal
 * Description: MVP portal for document upload, processing runs, and compliance findings.
 * Version: 0.1.0
 * Author: MV Team
 */

if (!defined('ABSPATH')) exit;

define('MV_PORTAL_VERSION', '0.1.0');
define('MV_PORTAL_PATH', plugin_dir_path(__FILE__));
define('MV_PORTAL_URL', plugin_dir_url(__FILE__));

if (!defined('MV_ENGINE_URL')) {
    define('MV_ENGINE_URL', 'https://your-engine-domain.com/process');
}

if (!defined('MV_ENGINE_SECRET')) {
    define('MV_ENGINE_SECRET', 'CHANGE_THIS_TO_RANDOM_LONG_SECRET');
}

require_once MV_PORTAL_PATH . 'includes/class-mv-db.php';
require_once MV_PORTAL_PATH . 'includes/class-mv-roles.php';
require_once MV_PORTAL_PATH . 'includes/class-mv-admin.php';
require_once MV_PORTAL_PATH . 'includes/class-mv-portal.php';
require_once MV_PORTAL_PATH . 'includes/class-mv-rules.php';
require_once MV_PORTAL_PATH . 'includes/class-mv-shortcodes.php';
require_once MV_PORTAL_PATH . 'includes/class-mv-api.php';
require_once MV_PORTAL_PATH . 'includes/class-mv-gemini.php';
require_once MV_PORTAL_PATH . 'includes/class-mv-worker.php';

register_activation_hook(__FILE__, function () {
    MV_Roles::activate();
    MV_DB::install();
});

register_deactivation_hook(__FILE__, function () {
    // Keep tables by default (safer). If you want cleanup, add it later.
    MV_Roles::deactivate();
});

add_action('plugins_loaded', function () {
    MV_Admin::init();
    MV_Portal::init();
    MV_Shortcodes::init();
    MV_API::init();
    MV_Worker::init();
});
