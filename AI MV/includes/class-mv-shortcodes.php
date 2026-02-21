<?php
if (!defined('ABSPATH')) exit;

class MV_Shortcodes {

    // Set this once you create a page with [mv_case].
    private static string $case_page_slug = 'mv-case';
    private static bool $case_script_localized = false;

    public static function init(): void {
        add_shortcode('mv_dashboard', [__CLASS__, 'dashboard']);
        add_shortcode('mv_new_case', [__CLASS__, 'new_case']);
        add_shortcode('mv_case', [__CLASS__, 'case_detail']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
    }

    public static function register_assets(): void {
        $script_path = MV_PORTAL_PATH . 'assets/mv-app.js';
        $script_ver = file_exists($script_path) ? (string) filemtime($script_path) : MV_PORTAL_VERSION;
        wp_register_script(
            'mv-app',
            MV_PORTAL_URL . 'assets/mv-app.js',
            [],
            $script_ver,
            true
        );
    }

    public static function case_page_url(): string {
        // You can change this later to a page ID lookup.
        return home_url('/' . self::$case_page_slug . '/');
    }

    private static function login_block(string $message = 'Please log in to continue.'): string {
        $login_url = wp_login_url($_SERVER['REQUEST_URI'] ?? home_url('/'));
        return '<div class="mv-box">
            <p>' . esc_html($message) . '</p>
            <p><a class="button button-primary" href="' . esc_url($login_url) . '">Log in</a></p>
        </div>';
    }

    private static function wrap(string $html): string {
        return '<div class="mv-portal">' . $html . '</div>' . self::styles();
    }

    private static function styles(): string {
        return '<style>
            .mv-portal { max-width: 980px; margin: 20px auto; }
            .mv-grid { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; }
            .mv-page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin:16px 0 20px; }
            .mv-page-head-main { min-width:0; }
            .mv-page-title { margin:0; font-size:32px; line-height:1.2; word-break:break-word; }
            .mv-page-meta { margin-top:6px; color:#6b7280; font-size:14px; }
            .mv-page-head-actions { display:flex; gap:10px; align-items:center; }
            .mv-box { background:#fff; border:1px solid #e5e7eb; padding:16px; border-radius:10px; }
            .mv-table { width:100%; border-collapse: collapse; }
            .mv-table th, .mv-table td { border-bottom: 1px solid #eee; padding:10px; text-align:left; vertical-align: top; }
            .mv-pill { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; border:1px solid #ddd; }
            .mv-pill--queued { background:#f8fafc; border-color:#e2e8f0; color:#334155; }
            .mv-pill--running { background:#fff7ed; border-color:#fdba74; color:#9a3412; }
            .mv-pill--done { background:#ecfdf5; border-color:#86efac; color:#166534; }
            .mv-pill--failed { background:#fff7ed; border-color:#fdba74; color:#9a3412; }
            .mv-muted { color:#666; font-size:13px; }
            .mv-actions { display:flex; gap:10px; align-items:center; justify-content:flex-end; }
            .mv-title { font-size: 22px; margin: 0 0 8px; }
            .mv-sub { margin:0 0 14px; }
            .mv-alert { padding:14px 16px; border-radius:10px; margin:12px 0; font-size:14px; }
            .mv-alert--error { background:#fff3f3; border:1px solid #ffd1d1; color:#8a1f1f; }
            .mv-score { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-radius:12px; background:#f6f3fb; border:1px solid #e5ddf6; margin:12px 0; }
            .mv-score__label { font-size:13px; color:#4b3b6b; }
            .mv-score__value { font-size:22px; font-weight:700; color:#3d1b5a; }
            .mv-score__meta { margin-top:4px; font-size:12px; color:#5b4b76; }
            .mv-score__kicker { margin-top:2px; font-size:11px; color:#6d5a93; text-transform:uppercase; letter-spacing:.04em; }
            .mv-progress { margin:12px 0; padding:12px; border:1px solid #ececf4; border-radius:10px; background:#fafafe; }
            .mv-progress--hidden { display:none; }
            .mv-progress__bar { height:8px; background:#ececf4; border-radius:999px; overflow:hidden; }
            .mv-progress__fill { height:100%; width:0%; background:#5b2a86; transition: width .45s ease; }
            .mv-progress__meta { margin-top:8px; display:flex; justify-content:space-between; gap:10px; color:#4a4f5a; font-size:13px; }
            .mv-progress__stage { font-weight:500; }
            .mv-retry { margin:12px 0; padding:14px 16px; border-radius:12px; background:#fffbeb; border:1px solid #facc15; color:#854d0e; }
            .mv-retry__title { margin:0 0 6px; font-size:16px; color:#78350f; }
            .mv-retry__actions { margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; }
            .mv-tech { margin-top:10px; font-size:12px; color:#374151; }
            .mv-tech summary { cursor:pointer; user-select:none; }
            .mv-tech pre { margin:8px 0 0; background:#111827; color:#f9fafb; border-radius:8px; padding:10px; overflow:auto; max-height:220px; }
            @media (max-width: 720px) {
                .mv-page-header { flex-direction:column; }
                .mv-page-title { font-size:26px; }
            }
        </style>';
    }

    private static function enqueue_case_assets(): void {
        if (!wp_script_is('mv-app', 'registered')) {
            self::register_assets();
        }

        wp_enqueue_script('mv-app');

        if (self::$case_script_localized) {
            return;
        }

        wp_localize_script('mv-app', 'MVPortal', [
            'startRunUrl' => esc_url_raw(rest_url('mv/v1/start-run')),
            'runStatusUrl' => esc_url_raw(rest_url('mv/v1/run-status')),
            'resetRunUrl' => esc_url_raw(rest_url('mv/v1/reset-run')),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
        self::$case_script_localized = true;
    }

    public static function dashboard(): string {
        $html = '<div class="mv-grid">
            <div>
                <h2 class="mv-title">Portal Dashboard</h2>
                <p class="mv-muted mv-sub">Create a case, upload a PDF, and track processing runs.</p>
            </div>
            <div class="mv-actions">
                <a class="button button-primary" href="' . esc_url(self::new_case_link()) . '">New Case</a>
            </div>
        </div>';

        if (!is_user_logged_in()) {
            $html .= self::login_block('Log in to view your cases and create a new one.');
            return self::wrap($html);
        }

        $cases = MV_Portal::get_user_cases(get_current_user_id());

        if (empty($cases)) {
            $html .= '<div class="mv-box"><p>No cases yet. Click <b>New Case</b> to create one.</p></div>';
            return self::wrap($html);
        }

        $html .= '<div class="mv-box"><table class="mv-table">
            <thead><tr>
                <th>Case</th>
                <th>Framework</th>
                <th>Status</th>
                <th></th>
            </tr></thead><tbody>';

        foreach ($cases as $c) {
            $case_url = add_query_arg(['case_id' => (int) $c['id']], self::case_page_url());
            $status_ui = self::run_status_ui((string) ($c['status'] ?? 'queued'));
            $html .= '<tr>
                <td><b>' . esc_html($c['title']) . '</b><div class="mv-muted">Case ID: ' . (int) $c['id'] . '</div></td>
                <td>' . esc_html($c['framework_key']) . '</td>
                <td><span class="mv-pill ' . esc_attr($status_ui['class']) . '">' . esc_html($status_ui['label']) . '</span></td>
                <td><a class="button" href="' . esc_url($case_url) . '">Open</a></td>
            </tr>';
        }

        $html .= '</tbody></table></div>';

        return self::wrap($html);
    }

    public static function new_case(): string {
        $html = '<h2 class="mv-title">Create a New Case</h2>
                 <p class="mv-muted mv-sub">Upload a PDF and start a processing run.</p>';

        if (!is_user_logged_in()) {
            $html .= self::login_block('Log in to create a case and upload documents.');
            return self::wrap($html);
        }

        $html .= '<div class="mv-box">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="mv_action" value="create_case" />
                ' . wp_nonce_field('mv_create_case', 'mv_nonce', true, false) . '

                <p>
                    <label><b>Case Title</b></label><br/>
                    <input type="text" name="case_title" required style="width:100%; max-width:520px;" />
                </p>

                <p>
                    <label><b>Framework</b></label><br/>
                    <select name="framework_key" style="width:100%; max-width:320px;">
                        <option value="default">Default</option>
                        <option value="nfra">NFRA</option>
                        <option value="indas">Ind AS</option>
                    </select>
                </p>

                <p>
                    <label><b>Upload PDF</b></label><br/>
                    <input type="file" name="case_pdf" accept="application/pdf" required />
                </p>

                <p style="margin-top:14px;">
                    <button type="submit" class="button button-primary">Create Case</button>
                    <a class="button" href="' . esc_url(self::dashboard_link()) . '">Back</a>
                </p>
            </form>
        </div>';

        return self::wrap($html);
    }

    public static function case_detail(): string {
        $case_id = isset($_GET['case_id']) ? (int) $_GET['case_id'] : 0;

        if ($case_id <= 0) {
            $dash = esc_url(self::dashboard_link());
            return self::wrap('<div class="mv-box">
                <p>No case selected.</p>
                <p><a class="button button-primary" href="' . $dash . '">Go to Dashboard</a></p>
            </div>');
        }

        $case = MV_Portal::get_case($case_id);
        if (!$case) {
            return self::wrap('<div class="mv-box"><p>Case not found.</p></div>');
        }

        // Public page allowed, but viewing details requires login AND ownership (for participant).
        if (!is_user_logged_in()) {
            return self::wrap(self::login_block('Log in to view this case.'));
        }

        $user_id = get_current_user_id();
        if (!MV_Portal::user_can_view_case($case, $user_id)) {
            return self::wrap('<div class="mv-box"><p>You do not have permission to view this case.</p></div>');
        }

        $doc = MV_Portal::get_case_document($case_id);
        $run = MV_Portal::get_latest_run($case_id);
        self::enqueue_case_assets();

        $html = '<div class="mv-page-header">
            <div class="mv-page-head-main">
                <h1 class="mv-page-title">' . esc_html($case['title']) . '</h1>
                <p class="mv-page-meta">Framework: <b>' . esc_html(strtoupper((string) $case['framework_key'])) . '</b> &middot; Case #' . (int) $case['id'] . '</p>
            </div>
            <div class="mv-page-head-actions">
                <a class="button" href="' . esc_url(self::dashboard_link()) . '">Dashboard</a>
            </div>
        </div>';

        $html .= '<div class="mv-box">';
        $html .= '<p><b>Document</b><br/>';
        if ($doc) {
            $html .= '<a href="' . esc_url($doc['file_url']) . '" target="_blank" rel="noopener">View uploaded PDF</a><br/>';
            $html .= '<span class="mv-muted">' . esc_html($doc['file_name'] ?? '') . '</span>';
        } else {
            $html .= '<span class="mv-muted">No document found.</span>';
        }
        $html .= '</p>';

        $html .= '<p><b>Latest Run</b><br/>';
        if ($run) {
            $run_id = (int) $run['id'];
            $status_ui = self::run_status_ui((string) $run['status']);
            $findings = [];
            $assessed_findings = [];
            $unknown_findings = [];
            $score = null;
            $coverage_percent = null;
            $pass_count = 0;
            $fail_count = 0;
            $unknown_count = 0;
            $assessed_total = 0;
            $total_rules = 0;
            if ($run['status'] === 'done') {
                $findings = MV_Portal::get_run_findings($run_id);
                if (!empty($findings)) {
                    foreach ($findings as $f) {
                        if (!is_array($f)) {
                            continue;
                        }

                        $f['status'] = self::normalize_finding_status((string) ($f['status'] ?? 'unknown'));
                        if ($f['status'] === 'pass') {
                            $pass_count++;
                            $assessed_findings[] = $f;
                        } elseif ($f['status'] === 'fail') {
                            $fail_count++;
                            $assessed_findings[] = $f;
                        } else {
                            $unknown_count++;
                            $unknown_findings[] = $f;
                        }
                    }

                    $assessed_total = $pass_count + $fail_count;
                    $total_rules = $assessed_total + $unknown_count;
                    if ($total_rules > 0) {
                        $score = (int) round(($pass_count / $total_rules) * 100);
                    } else {
                        $score = 0;
                    }
                    if ($total_rules > 0) {
                        $coverage_percent = (int) round(($assessed_total / $total_rules) * 100);
                    }
                }
            }

            $html .= '<span id="mv-run-pill" class="mv-pill ' . esc_attr($status_ui['class']) . '" data-failed-label="Needs retry" data-failed-class="mv-pill--failed">' . esc_html($status_ui['label']) . '</span>';
            $progress_class = ($run['status'] === 'running') ? 'mv-progress' : 'mv-progress mv-progress--hidden';
            $html .= '<div id="mv-progress" class="' . esc_attr($progress_class) . '" aria-live="polite">
                <div class="mv-progress__bar"><div id="mv-progress-fill" class="mv-progress__fill"></div></div>
                <div class="mv-progress__meta">
                    <span id="mv-progress-percent">0%</span>
                    <span id="mv-progress-stage" class="mv-progress__stage">Initializing...</span>
                </div>
            </div>';

            if ($run['status'] === 'failed') {
                $raw_error = (string) ($run['error_message'] ?? '');
                $friendly_message = self::friendly_failed_message($raw_error);
                $html .= '<div class="mv-retry">
                    <h3 class="mv-retry__title">We couldn\'t complete the analysis for this PDF</h3>
                    <div>' . esc_html($friendly_message) . '</div>
                    <div class="mv-retry__actions">
                        <button type="button" class="button button-primary" id="mv-try-again">Try Again</button>
                        <a class="button" href="' . esc_url(self::new_case_link()) . '">Upload another PDF</a>
                    </div>';

                if (current_user_can('manage_options')) {
                    $debug = MV_Portal::get_run_debug($run_id);
                    $reference = '';
                    if (isset($debug['payload']['reference']) && is_string($debug['payload']['reference'])) {
                        $reference = $debug['payload']['reference'];
                    } elseif (preg_match('/Reference:\s*([A-Z0-9\-]+)/', $raw_error, $m)) {
                        $reference = $m[1];
                    }

                    $details = $debug;
                    if (!is_array($details) || empty($details)) {
                        $details = ['error' => $raw_error];
                    }
                    if ($reference !== '') {
                        $details['reference'] = $reference;
                    }

                    $detail_json = wp_json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    if (!is_string($detail_json)) {
                        $detail_json = 'No technical details available.';
                    } elseif (function_exists('mb_substr')) {
                        $detail_json = mb_substr($detail_json, 0, 12000);
                    } else {
                        $detail_json = substr($detail_json, 0, 12000);
                    }

                    $html .= '<details class="mv-tech">
                        <summary>Technical details (admin)</summary>
                        <pre>' . esc_html($detail_json) . '</pre>
                    </details>';
                }

                $html .= '</div>';
            }

            if ($run['status'] === 'done') {
                $score_display = ((string) (int) $score) . '%';
                $coverage_display = ($coverage_percent === null) ? '0%' : ((string) $coverage_percent . '%');
                $coverage_rules = ($total_rules > 0) ? ($assessed_total . '/' . $total_rules . ' rules analyzed') : '0/0 rules analyzed';
                $basis_label = 'Pass / Total applicable rules';
                $html .= '<div class="mv-score">
                    <div>
                        <div class="mv-score__label">AI Compliance Assessment</div>
                        <div class="mv-score__kicker">' . esc_html($basis_label) . '</div>
                        <div class="mv-score__meta">Coverage: <b>' . esc_html($coverage_display) . '</b> (' . esc_html($coverage_rules) . ')</div>
                        <div class="mv-score__meta">Pass: ' . (int) $pass_count . ' &middot; Fail: ' . (int) $fail_count . ' &middot; Not assessed: ' . (int) $unknown_count . '</div>
                    </div>
                    <div class="mv-score__value">' . esc_html($score_display) . '</div>
                </div>';
            }

            // Process button (Owner + admin/reviewer).
            $html .= '<div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">';
            $html .= '<button type="button" class="button button-primary" id="mv-start-run" data-case="' . (int) $case['id'] . '" data-run="' . $run_id . '">Process</button>';

            if (current_user_can('manage_options')) {
                $html .= '<button type="button" class="button" id="mv-reset-run" data-run="' . $run_id . '">Reset to Queued</button>';
            }
            $html .= '</div>';

            // Findings preview (only if done).
            if ($run['status'] === 'done') {
                $html .= '<hr style="margin:16px 0;">';
                $html .= '<b>Findings</b><br/>';
                if (empty($findings)) {
                    $html .= '<span class="mv-muted">No findings saved yet.</span>';
                } else {
                    if (empty($assessed_findings)) {
                        $html .= '<span class="mv-muted">No assessed rules yet for this run.</span>';
                    } else {
                        $html .= '<table class="mv-table" style="margin-top:10px;">
                            <thead><tr><th>Rule</th><th>Status</th><th>Reason</th></tr></thead><tbody>';
                        foreach ($assessed_findings as $f) {
                            $html .= '<tr>
                                <td><b>' . esc_html((string) ($f['rule_code'] ?? '')) . '</b></td>
                                <td><span class="mv-pill">' . esc_html((string) ($f['status'] ?? '')) . '</span></td>
                                <td>' . esc_html(wp_strip_all_tags((string) ($f['reason'] ?? ''))) . '</td>
                            </tr>';
                        }
                        $html .= '</tbody></table>';
                    }

                    if (current_user_can('manage_options') && !empty($unknown_findings)) {
                        $html .= '<details class="mv-tech" style="margin-top:10px;">
                            <summary>Show not assessed rules (admin)</summary>
                            <table class="mv-table" style="margin-top:10px;">
                                <thead><tr><th>Rule</th><th>Status</th><th>Reason</th></tr></thead><tbody>';
                        foreach ($unknown_findings as $f) {
                            $html .= '<tr>
                                <td><b>' . esc_html((string) ($f['rule_code'] ?? '')) . '</b></td>
                                <td><span class="mv-pill">unknown</span></td>
                                <td>' . esc_html(wp_strip_all_tags((string) ($f['reason'] ?? 'Not assessed'))) . '</td>
                            </tr>';
                        }
                        $html .= '</tbody></table></details>';
                    }
                }
            }

        } else {
            $html .= '<span class="mv-muted">No run found.</span>';
        }
        $html .= '</p>';

        $html .= '</div>';

        return self::wrap($html);
    }

    private static function dashboard_link(): string {
        // Use a page slug you create for dashboard.
        return home_url('/mv-dashboard/');
    }

    private static function new_case_link(): string {
        // Use a page slug you create for new case.
        return home_url('/mv-new-case/');
    }

    private static function run_status_ui(string $status): array {
        $key = strtolower(trim($status));
        switch ($key) {
            case 'running':
                return ['label' => 'Processing', 'class' => 'mv-pill--running'];
            case 'done':
                return ['label' => 'Completed', 'class' => 'mv-pill--done'];
            case 'failed':
                return ['label' => 'Needs retry', 'class' => 'mv-pill--failed'];
            case 'queued':
            default:
                return ['label' => 'Queued', 'class' => 'mv-pill--queued'];
        }
    }

    private static function friendly_failed_message(string $raw_error): string {
        $msg = strtolower($raw_error);
        if (strpos($msg, 'too large') !== false) {
            return 'Please upload a smaller PDF (under 5MB) and try again.';
        }
        return 'Please try again, or upload a different PDF (text-based PDFs work best).';
    }

    private static function normalize_finding_status(string $status): string {
        $status = strtolower(trim($status));
        if ($status === 'warn') {
            $status = 'unknown';
        }
        if (!in_array($status, ['pass', 'fail', 'unknown'], true)) {
            $status = 'unknown';
        }
        return $status;
    }
}
