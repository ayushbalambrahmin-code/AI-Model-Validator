<?php
if (!defined('ABSPATH')) exit;

class MV_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
    }

    public static function admin_menu(): void {
        if (!current_user_can('mv_manage_portal') && !current_user_can('manage_options')) {
            return;
        }

        add_menu_page(
            'MV Portal',
            'MV Portal',
            'manage_options',
            'mv-portal',
            [__CLASS__, 'render_admin_page'],
            'dashicons-analytics',
            56
        );
    }

    public static function render_admin_page(): void {
        echo '<div class="wrap">';
        echo '<h1>MV Portal</h1>';
        echo '<p>System ready.</p>';
        echo '</div>';
    }
}
