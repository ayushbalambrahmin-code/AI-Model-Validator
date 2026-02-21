<?php
if (!defined('ABSPATH')) exit;

class MV_API {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {

        register_rest_route('mv/v1', '/start-run', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'start_run'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        register_rest_route('mv/v1', '/run-status', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'run_status'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        register_rest_route('mv/v1', '/reset-run', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'reset_run'],
            'permission_callback' => function () {
                return is_user_logged_in() && current_user_can('manage_options');
            },
        ]);

        // Webhook from AI engine (secured with a secret token in Phase 3.2).
        register_rest_route('mv/v1', '/webhook/run-complete', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'webhook_run_complete'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function start_run(WP_REST_Request $request) {
        $case_id = (int) $request->get_param('case_id');
        if ($case_id <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'missing_case_id'], 400);

        $case = MV_Portal::get_case($case_id);
        if (!$case) return new WP_REST_Response(['ok' => false, 'error' => 'case_not_found'], 404);

        $user_id = get_current_user_id();
        if (!MV_Portal::user_can_view_case($case, $user_id)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $run = MV_Portal::get_latest_run($case_id);
        if (!$run) return new WP_REST_Response(['ok' => false, 'error' => 'run_not_found'], 404);

        // Allow restart for development.
        $allowed = ['queued', 'failed', 'done', 'running'];
        if (!in_array($run['status'], $allowed, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'run_not_startable', 'status' => $run['status']], 409);
        }

        MV_Portal::set_run_status((int) $run['id'], 'running');
        MV_Portal::clear_run_findings((int) $run['id']);
        MV_Portal::clear_run_debug((int) $run['id']);

        $total_rules = count(MV_Rules::get_rules((string) $case['framework_key']));
        MV_Portal::set_run_debug((int) $run['id'], [
            'status' => 'running',
            'total_rules' => $total_rules,
            'evaluated_rules' => 0,
            'assessed_rules' => 0,
            'pass_count' => 0,
            'fail_count' => 0,
            'unknown_count' => 0,
            'score_current' => 0,
            'coverage_percent' => 0,
            'progress_percent' => 0,
            'eta_seconds' => null,
            'status_text' => 'Queued for analysis',
        ]);

        wp_clear_scheduled_hook('mv_portal_process_run', [(int) $run['id']]);
        $scheduled = wp_schedule_single_event(time() + 1, 'mv_portal_process_run', [(int) $run['id']]);

        if ($scheduled === false || (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            // Fallback if scheduling fails in this environment.
            MV_Worker::process_run((int) $run['id']);
        } else {
            if (function_exists('spawn_cron')) {
                spawn_cron(time());
            }
        }

        return new WP_REST_Response([
            'ok' => true,
            'run_id' => (int) $run['id'],
            'status' => 'running'
        ], 200);
    }

    public static function run_status(WP_REST_Request $request) {
        $run_id = (int) $request->get_param('run_id');
        if ($run_id <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'missing_run_id'], 400);

        $run = MV_Portal::get_run($run_id);
        if (!$run) return new WP_REST_Response(['ok' => false, 'error' => 'run_not_found'], 404);

        $case = MV_Portal::get_case((int) $run['case_id']);
        if (!$case) return new WP_REST_Response(['ok' => false, 'error' => 'case_not_found'], 404);

        if (!MV_Portal::user_can_view_case($case, get_current_user_id())) {
            return new WP_REST_Response(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $progress = null;
        $debug = MV_Portal::get_run_debug($run_id);
        if (isset($debug['payload']) && is_array($debug['payload'])) {
            $payload = $debug['payload'];
            $progress = [
                'evaluated_rules' => isset($payload['evaluated_rules']) ? (int) $payload['evaluated_rules'] : 0,
                'assessed_rules' => isset($payload['assessed_rules']) ? (int) $payload['assessed_rules'] : 0,
                'total_rules' => isset($payload['total_rules']) ? (int) $payload['total_rules'] : 0,
                'pass_count' => isset($payload['pass_count']) ? (int) $payload['pass_count'] : 0,
                'fail_count' => isset($payload['fail_count']) ? (int) $payload['fail_count'] : 0,
                'unknown_count' => isset($payload['unknown_count']) ? (int) $payload['unknown_count'] : 0,
                'score_current' => isset($payload['score_current']) ? (int) $payload['score_current'] : 0,
                'coverage_percent' => isset($payload['coverage_percent']) ? (int) $payload['coverage_percent'] : 0,
                'progress_percent' => isset($payload['progress_percent']) ? (int) $payload['progress_percent'] : 0,
                'eta_seconds' => isset($payload['eta_seconds']) ? (int) $payload['eta_seconds'] : 0,
                'status_text' => isset($payload['status_text']) ? sanitize_text_field((string) $payload['status_text']) : '',
            ];
        }

        return new WP_REST_Response([
            'ok' => true,
            'run' => [
                'id' => (int) $run['id'],
                'status' => $run['status'],
                'error_message' => $run['error_message'],
                'started_at' => $run['started_at'],
                'finished_at' => $run['finished_at'],
                'progress' => $progress,
            ],
        ], 200);
    }

    public static function webhook_run_complete(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) $payload = [];

        $secret = isset($payload['secret']) ? (string) $payload['secret'] : '';
        if (!hash_equals((string) MV_ENGINE_SECRET, $secret)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 403);
        }

        $run_id = isset($payload['run_id']) ? (int) $payload['run_id'] : 0;
        if ($run_id <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'missing_run_id'], 400);

        $status = isset($payload['status']) ? sanitize_text_field($payload['status']) : 'done';
        if (!in_array($status, ['done', 'failed'], true)) $status = 'done';

        $run = MV_Portal::get_run($run_id);
        if (!$run) return new WP_REST_Response(['ok' => false, 'error' => 'run_not_found'], 404);

        // If done: store findings.
        if ($status === 'done') {
            $findings = isset($payload['findings']) && is_array($payload['findings']) ? $payload['findings'] : [];
            MV_Portal::insert_findings($run_id, $findings);
            MV_Portal::set_run_status($run_id, 'done');
        } else {
            $err = isset($payload['error_message']) ? sanitize_text_field($payload['error_message']) : 'Engine error';
            MV_Portal::set_run_status($run_id, 'failed', $err);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function reset_run(WP_REST_Request $request) {
        $run_id = (int) $request->get_param('run_id');
        if ($run_id <= 0) return new WP_REST_Response(['ok' => false, 'error' => 'missing_run_id'], 400);

        $run = MV_Portal::get_run($run_id);
        if (!$run) return new WP_REST_Response(['ok' => false, 'error' => 'run_not_found'], 404);

        MV_Portal::clear_run_findings($run_id);
        MV_Portal::clear_run_debug($run_id);
        MV_Portal::set_run_status($run_id, 'queued');

        return new WP_REST_Response(['ok' => true, 'status' => 'queued'], 200);
    }
}
