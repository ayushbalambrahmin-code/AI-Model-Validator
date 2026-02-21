<?php
if (!defined('ABSPATH')) exit;

class MV_Worker {

    public static function init(): void {
        add_action('mv_portal_process_run', [__CLASS__, 'process_run'], 10, 1);
    }

    public static function process_run(int $run_id): void {
        try {
            $run = MV_Portal::get_run($run_id);
            if (!$run) throw new Exception('Run not found');

            $case = MV_Portal::get_case((int) $run['case_id']);
            if (!$case) throw new Exception('Case not found');

            $doc = MV_Portal::get_case_document((int) $run['case_id']);
            if (!$doc || empty($doc['file_url'])) throw new Exception('Document not found');

            $rules = MV_Rules::get_rules((string) $case['framework_key']);
            if (empty($rules)) {
                throw new Exception('No rules configured for framework: ' . (string) $case['framework_key']);
            }

            $total_rules = count($rules);
            $batch_size = (int) apply_filters('mv_rule_batch_size', 3);
            if ($batch_size < 1) {
                $batch_size = 1;
            }

            $pass_count = 0;
            $fail_count = 0;
            $unknown_count = 0;
            $assessed_rules = 0;
            $evaluated_rules = 0;
            $total_batches = (int) ceil($total_rules / $batch_size);
            $started = microtime(true);

            MV_Portal::set_run_debug($run_id, [
                'status' => 'running',
                'framework' => (string) $case['framework_key'],
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
                'status_text' => 'Running (0/' . $total_rules . ')',
                'last_batch_trace' => [],
            ]);

            $batches = array_chunk($rules, $batch_size);
            foreach ($batches as $batch_index => $batch_rules) {
                $batch_error = '';
                $trace = [];
                try {
                    $model_findings = MV_Gemini::call_gemini_with_pdf(
                        (string) $case['framework_key'],
                        (string) $doc['file_url'],
                        $batch_rules
                    );
                    $normalized = MV_Rules::normalize_findings($batch_rules, $model_findings);
                    $trace = MV_Gemini::get_debug_trace();
                } catch (Throwable $batch_exception) {
                    $batch_error = $batch_exception->getMessage();
                    error_log('MV rule batch failed [' . $run_id . ']: ' . $batch_error);
                    $normalized = self::build_unknown_findings(
                        $batch_rules,
                        'Could not complete automated evaluation for this rule in this run.'
                    );
                    $trace = [[
                        'attempt' => 'batch_fallback',
                        'status' => 'error',
                        'error' => $batch_error,
                        'at' => gmdate('c'),
                    ]];
                }

                MV_Portal::insert_findings($run_id, $normalized);

                foreach ($normalized as $finding) {
                    $status = self::normalize_status((string) ($finding['status'] ?? 'unknown'));
                    if ($status === 'pass') {
                        $pass_count++;
                        $assessed_rules++;
                    } elseif ($status === 'fail') {
                        $fail_count++;
                        $assessed_rules++;
                    } else {
                        $unknown_count++;
                    }
                }

                $evaluated_rules += count($batch_rules);
                $elapsed = max(0.1, microtime(true) - $started);
                $avg_per_rule = $evaluated_rules > 0 ? ($elapsed / $evaluated_rules) : 0.0;
                $remaining = max(0, $total_rules - $evaluated_rules);
                $eta_seconds = (int) max(0, round($remaining * $avg_per_rule));
                $progress_percent = $total_rules > 0 ? (int) round(($evaluated_rules / $total_rules) * 100) : 0;
                $coverage_percent = $total_rules > 0 ? (int) round(($assessed_rules / $total_rules) * 100) : 0;
                $score_current = $total_rules > 0 ? (int) round(($pass_count / $total_rules) * 100) : 0;

                MV_Portal::set_run_debug($run_id, [
                    'status' => 'running',
                    'framework' => (string) $case['framework_key'],
                    'total_rules' => $total_rules,
                    'evaluated_rules' => $evaluated_rules,
                    'assessed_rules' => $assessed_rules,
                    'pass_count' => $pass_count,
                    'fail_count' => $fail_count,
                    'unknown_count' => $unknown_count,
                    'score_current' => $score_current,
                    'coverage_percent' => $coverage_percent,
                    'progress_percent' => $progress_percent,
                    'eta_seconds' => $eta_seconds,
                    'current_batch' => ((int) $batch_index + 1),
                    'total_batches' => $total_batches,
                    'status_text' => 'Running (' . $evaluated_rules . '/' . $total_rules . ')',
                    'last_batch_error' => $batch_error,
                    'last_batch_trace' => $trace,
                ]);
            }

            $final_score = $total_rules > 0 ? (int) round(($pass_count / $total_rules) * 100) : 0;
            $final_coverage = $total_rules > 0 ? (int) round(($assessed_rules / $total_rules) * 100) : 0;
            MV_Portal::set_run_debug($run_id, [
                'status' => 'done',
                'framework' => (string) $case['framework_key'],
                'total_rules' => $total_rules,
                'evaluated_rules' => $evaluated_rules,
                'assessed_rules' => $assessed_rules,
                'pass_count' => $pass_count,
                'fail_count' => $fail_count,
                'unknown_count' => $unknown_count,
                'score_current' => $final_score,
                'coverage_percent' => $final_coverage,
                'progress_percent' => 100,
                'eta_seconds' => 0,
                'status_text' => 'Completed',
            ]);

            MV_Portal::set_run_status($run_id, 'done');
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $reference = 'RUN-' . $run_id . '-' . gmdate('YmdHis');
            error_log('MV run failed [' . $reference . ']: ' . $msg);

            if (strpos($msg, 'PDF too large') !== false) {
                $safe = 'This PDF is too large for the current processing mode. Please upload a smaller PDF (under 5MB).';
            } elseif (strpos($msg, 'quota/ratelimit') !== false || strpos($msg, 'server error') !== false) {
                $safe = 'The AI service is temporarily busy. Please try again in a moment.';
            } else {
                $safe = 'Could not generate findings for this document. Please try again.';
            }

            MV_Portal::set_run_debug($run_id, [
                'status' => 'failed',
                'reference' => $reference,
                'error' => $msg,
                'attempts' => MV_Gemini::get_debug_trace(),
            ]);

            $safe .= ' Reference: ' . $reference;
            MV_Portal::set_run_status($run_id, 'failed', $safe);
        }
    }

    private static function build_unknown_findings(array $rules, string $reason): array {
        $result = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $code = isset($rule['rule_code']) ? sanitize_text_field((string) $rule['rule_code']) : '';
            if ($code === '') {
                continue;
            }
            $result[] = [
                'rule_code' => $code,
                'status' => 'unknown',
                'reason' => $reason,
                'evidence' => [],
            ];
        }
        return $result;
    }

    private static function normalize_status(string $status): string {
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
