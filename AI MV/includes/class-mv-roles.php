<?php
if (!defined('ABSPATH')) exit;

class MV_Roles {

    public static function activate(): void {
        // Add custom capabilities
        $caps_participant = [
            'read' => true,
            'mv_create_case' => true,
            'mv_upload_document' => true,
            'mv_view_own_cases' => true,
        ];

        $caps_reviewer = [
            'read' => true,
            'mv_view_all_cases' => true,
            'mv_review_cases' => true,
        ];

        $caps_admin = [
            'read' => true,
            'manage_options' => true,
            'mv_manage_portal' => true,
            'mv_view_all_cases' => true,
            'mv_review_cases' => true,
            'mv_manage_rules' => true,
        ];

        add_role('mv_participant', 'MV Participant', $caps_participant);
        add_role('mv_reviewer', 'MV Reviewer', $caps_reviewer);
        add_role('mv_admin', 'MV Admin', $caps_admin);

        // Also grant portal admin caps to WP administrators
        $admin = get_role('administrator');
        if ($admin) {
            foreach (array_keys($caps_admin) as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    public static function deactivate(): void {
        // Remove caps we added to administrator
        $admin = get_role('administrator');
        if ($admin) {
            foreach (['mv_manage_portal','mv_view_all_cases','mv_review_cases','mv_manage_rules'] as $cap) {
                $admin->remove_cap($cap);
            }
        }

        // Roles are optional to remove. Usually you keep them.
        // remove_role('mv_participant');
        // remove_role('mv_reviewer');
        // remove_role('mv_admin');
    }
}