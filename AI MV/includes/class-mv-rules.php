<?php
if (!defined('ABSPATH')) exit;

class MV_Rules {

    public static function get_rules(string $framework_key): array {
        $framework_key = strtolower(trim($framework_key));

        switch ($framework_key) {
            case 'indas':
            case 'default':
            default:
                return self::indas_rules();
        }
    }

    public static function normalize_findings(array $rules, array $findings): array {
        $allowed = [];
        foreach ($rules as $rule) {
            $code = isset($rule['rule_code']) ? sanitize_text_field((string) $rule['rule_code']) : '';
            if ($code === '') {
                continue;
            }
            $allowed[$code] = $rule;
        }

        $picked = [];
        foreach ($findings as $f) {
            if (!is_array($f)) {
                continue;
            }

            $code = isset($f['rule_code']) ? sanitize_text_field((string) $f['rule_code']) : '';
            if ($code === '' || !isset($allowed[$code]) || isset($picked[$code])) {
                continue;
            }

            $status = isset($f['status']) ? strtolower(trim((string) $f['status'])) : 'unknown';
            if ($status === 'warn') {
                $status = 'unknown';
            }
            if (!in_array($status, ['pass', 'fail', 'unknown'], true)) {
                $status = 'unknown';
            }

            $reason = isset($f['reason']) ? wp_strip_all_tags((string) $f['reason']) : '';
            $reason = trim($reason);
            if ($reason === '') {
                $reason = 'No reason provided.';
            }

            $evidence = [];
            if (isset($f['evidence']) && is_array($f['evidence'])) {
                foreach ($f['evidence'] as $ev) {
                    if (!is_array($ev)) {
                        continue;
                    }
                    $page = isset($ev['page']) && is_numeric($ev['page']) ? (int) $ev['page'] : null;
                    $snippet = isset($ev['snippet']) ? trim(wp_strip_all_tags((string) $ev['snippet'])) : '';
                    if ($snippet === '') {
                        continue;
                    }
                    if (function_exists('mb_substr')) {
                        $snippet = mb_substr($snippet, 0, 800);
                    } else {
                        $snippet = substr($snippet, 0, 800);
                    }
                    $evidence[] = [
                        'page' => $page,
                        'snippet' => $snippet,
                    ];
                }
            }

            $picked[$code] = [
                'rule_code' => $code,
                'status' => $status,
                'reason' => $reason,
                'evidence' => $evidence,
            ];
        }

        $normalized = [];
        foreach ($rules as $rule) {
            $code = sanitize_text_field((string) $rule['rule_code']);
            if (isset($picked[$code])) {
                $normalized[] = $picked[$code];
                continue;
            }

            $normalized[] = [
                'rule_code' => $code,
                'status' => 'unknown',
                'reason' => 'Not enough evidence in the document excerpt.',
                'evidence' => [],
            ];
        }

        return $normalized;
    }

    private static function indas_rules(): array {
        return [
            [
                'rule_code' => 'INDAS-01-001',
                'title' => 'Complete set of financial statements',
                'requirement' => 'Balance Sheet, P&L, Cash Flow, Statement of Changes in Equity, and Notes are presented.',
            ],
            [
                'rule_code' => 'INDAS-01-002',
                'title' => 'Current versus non-current classification',
                'requirement' => 'Assets and liabilities are classified as current/non-current or liquidity presentation is explained.',
            ],
            [
                'rule_code' => 'INDAS-01-003',
                'title' => 'Material accounting policies disclosed',
                'requirement' => 'Material accounting policy information is disclosed in notes.',
            ],
            [
                'rule_code' => 'INDAS-01-004',
                'title' => 'Comparative information presented',
                'requirement' => 'Comparative figures for prior period are disclosed for all primary statements.',
            ],
            [
                'rule_code' => 'INDAS-01-005',
                'title' => 'Going concern basis',
                'requirement' => 'Financial statements state going concern basis or disclose uncertainty.',
            ],
            [
                'rule_code' => 'INDAS-07-001',
                'title' => 'Cash flow statement present',
                'requirement' => 'Cash flow statement is included for the period.',
            ],
            [
                'rule_code' => 'INDAS-07-002',
                'title' => 'Operating investing financing sections',
                'requirement' => 'Cash flows are classified under operating, investing, and financing activities.',
            ],
            [
                'rule_code' => 'INDAS-08-001',
                'title' => 'Changes in accounting policies disclosed',
                'requirement' => 'Nature and effect of accounting policy changes are disclosed.',
            ],
            [
                'rule_code' => 'INDAS-10-001',
                'title' => 'Events after reporting period',
                'requirement' => 'Material adjusting/non-adjusting events after reporting period are disclosed.',
            ],
            [
                'rule_code' => 'INDAS-12-001',
                'title' => 'Current and deferred tax disclosures',
                'requirement' => 'Current tax and deferred tax amounts and basis are disclosed.',
            ],
            [
                'rule_code' => 'INDAS-16-001',
                'title' => 'Property plant equipment reconciliation',
                'requirement' => 'Opening to closing carrying amount reconciliation for PPE is disclosed.',
            ],
            [
                'rule_code' => 'INDAS-24-001',
                'title' => 'Related party disclosures',
                'requirement' => 'Related parties and material transactions/balances are disclosed.',
            ],
            [
                'rule_code' => 'INDAS-33-001',
                'title' => 'EPS disclosure',
                'requirement' => 'Basic and diluted earnings per share are disclosed when applicable.',
            ],
            [
                'rule_code' => 'INDAS-36-001',
                'title' => 'Impairment assessment disclosure',
                'requirement' => 'Indicators or impairment testing and outcomes are disclosed where required.',
            ],
            [
                'rule_code' => 'INDAS-37-001',
                'title' => 'Provisions and contingencies',
                'requirement' => 'Nature, timing, and uncertainty of material provisions/contingent liabilities are disclosed.',
            ],
        ];
    }
}
