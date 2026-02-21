<?php
if (!defined('ABSPATH')) exit;

class MV_Portal {

    public static function init(): void {
        add_action('init', [__CLASS__, 'handle_new_case_submit']);
    }

    private static function tables(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'mv_';
        return [
            'cases' => $p . 'cases',
            'documents' => $p . 'documents',
            'runs' => $p . 'runs',
        ];
    }

    public static function handle_new_case_submit(): void {
        if (empty($_POST['mv_action']) || $_POST['mv_action'] !== 'create_case') {
            return;
        }

        // Public page allowed, but actions require login.
        if (!is_user_logged_in()) {
            wp_die('You must be logged in to create a case.');
        }

        if (!isset($_POST['mv_nonce']) || !wp_verify_nonce($_POST['mv_nonce'], 'mv_create_case')) {
            wp_die('Security check failed.');
        }

        $title = isset($_POST['case_title']) ? sanitize_text_field($_POST['case_title']) : '';
        $framework = isset($_POST['framework_key']) ? sanitize_text_field($_POST['framework_key']) : 'default';

        if ($title === '') {
            wp_die('Case title is required.');
        }

        if (empty($_FILES['case_pdf']) || empty($_FILES['case_pdf']['name'])) {
            wp_die('Please upload a PDF.');
        }

        $file = $_FILES['case_pdf'];

        // Validate upload.
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $allowed_mimes = [
            'pdf' => 'application/pdf',
        ];

        $overrides = [
            'test_form' => false,
            'mimes' => $allowed_mimes,
        ];

        $uploaded = wp_handle_upload($file, $overrides);

        if (isset($uploaded['error'])) {
            wp_die('Upload failed: ' . esc_html($uploaded['error']));
        }

        $file_url = $uploaded['url'];
        $file_path = $uploaded['file'];
        $file_name = basename($file_path);
        $mime_type = $uploaded['type'];

        // Hash for dedupe.
        $hash = '';
        if (file_exists($file_path)) {
            $hash = hash_file('sha256', $file_path);
        }

        $user_id = get_current_user_id();
        $t = self::tables();
        global $wpdb;

        // Insert case.
        $wpdb->insert($t['cases'], [
            'user_id' => $user_id,
            'title' => $title,
            'framework_key' => $framework,
            'status' => 'created',
            'created_at' => current_time('mysql'),
        ]);

        $case_id = (int) $wpdb->insert_id;
        if (!$case_id) {
            wp_die('Failed to create case.');
        }

        // Insert document.
        $wpdb->insert($t['documents'], [
            'case_id' => $case_id,
            'file_url' => $file_url,
            'file_name' => $file_name,
            'file_hash' => $hash,
            'mime_type' => $mime_type,
            'created_at' => current_time('mysql'),
        ]);

        $doc_id = (int) $wpdb->insert_id;
        if (!$doc_id) {
            wp_die('Failed to save document.');
        }

        // Create initial run (queued).
        $wpdb->insert($t['runs'], [
            'case_id' => $case_id,
            'document_id' => $doc_id,
            'status' => 'queued',
            'created_at' => current_time('mysql'),
        ]);

        // Redirect to case page.
        $redirect = add_query_arg(['case_id' => $case_id], MV_Shortcodes::case_page_url());
        wp_safe_redirect($redirect);
        exit;
    }

    public static function get_user_cases(int $user_id): array {
        global $wpdb;
        $t = self::tables();
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$t['cases']} WHERE user_id=%d ORDER BY id DESC", $user_id),
            ARRAY_A
        ) ?: [];
    }

    public static function get_case(int $case_id): ?array {
        global $wpdb;
        $t = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t['cases']} WHERE id=%d", $case_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_case_document(int $case_id): ?array {
        global $wpdb;
        $t = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t['documents']} WHERE case_id=%d ORDER BY id DESC LIMIT 1", $case_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_document(int $document_id): ?array {
        global $wpdb;
        $t = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t['documents']} WHERE id=%d", $document_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_latest_run(int $case_id): ?array {
        global $wpdb;
        $t = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t['runs']} WHERE case_id=%d ORDER BY id DESC LIMIT 1", $case_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function user_can_view_case(array $case, int $user_id): bool {
        $can_view_all = current_user_can('mv_view_all_cases') || current_user_can('manage_options');
        if ($can_view_all) return true;
        return ((int) $case['user_id'] === $user_id);
    }

    public static function set_run_status(int $run_id, string $status, string $error_message = ''): bool {
        global $wpdb;
        $t = self::tables();
        $data = [
            'status' => $status,
        ];

        if ($status === 'running') {
            $data['started_at'] = current_time('mysql');
            $data['finished_at'] = null;
            $data['error_message'] = null;
        }

        if ($status === 'queued') {
            $data['started_at'] = null;
            $data['finished_at'] = null;
            $data['error_message'] = null;
        }

        if (in_array($status, ['done', 'failed'], true)) {
            $data['finished_at'] = current_time('mysql');
            if ($error_message !== '') $data['error_message'] = $error_message;
        }

        $updated = $wpdb->update($t['runs'], $data, ['id' => $run_id], null, ['%d']);
        return ($updated !== false);
    }

    public static function clear_run_findings(int $run_id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'mv_findings';
        $deleted = $wpdb->delete($table, ['run_id' => $run_id], ['%d']);
        return ($deleted !== false);
    }

    public static function set_run_debug(int $run_id, array $payload): void {
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return;
        }

        $key = 'mv_run_debug_' . $run_id;
        $data = [
            'run_id' => $run_id,
            'updated_at' => current_time('mysql'),
            'payload' => $payload,
        ];

        update_option($key, wp_json_encode($data), false);
    }

    public static function get_run_debug(int $run_id): array {
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return [];
        }

        $key = 'mv_run_debug_' . $run_id;
        $raw = get_option($key, '');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function clear_run_debug(int $run_id): void {
        $run_id = (int) $run_id;
        if ($run_id <= 0) {
            return;
        }

        delete_option('mv_run_debug_' . $run_id);
    }

    public static function insert_findings(int $run_id, array $findings): void {
        global $wpdb;
        $table = $wpdb->prefix . 'mv_findings';

        foreach ($findings as $f) {
            $rule_code = isset($f['rule_code']) ? sanitize_text_field($f['rule_code']) : '';
            if ($rule_code === '') continue;

            $status = isset($f['status']) ? strtolower(sanitize_text_field($f['status'])) : 'unknown';
            if ($status === 'warn') {
                $status = 'unknown';
            }
            if (!in_array($status, ['pass', 'fail', 'unknown'], true)) $status = 'unknown';

            $reason = isset($f['reason']) ? wp_kses_post($f['reason']) : '';
            $evidence = isset($f['evidence']) ? wp_json_encode($f['evidence']) : null;

            $wpdb->insert($table, [
                'run_id' => $run_id,
                'rule_code' => $rule_code,
                'status' => $status,
                'reason' => $reason,
                'evidence_json' => $evidence,
                'created_at' => current_time('mysql'),
            ]);
        }
    }

    public static function get_run(int $run_id): ?array {
        global $wpdb;
        $t = self::tables();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t['runs']} WHERE id=%d", $run_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_run_findings(int $run_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'mv_findings';
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE run_id=%d ORDER BY id ASC", $run_id),
            ARRAY_A
        ) ?: [];
    }
}
