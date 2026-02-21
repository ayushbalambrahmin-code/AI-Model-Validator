<?php
if (!defined('ABSPATH')) exit;

class MV_Gemini {

    private static array $debug_trace = [];
    private static string $last_candidate_text = '';
    private static string $last_error = '';

    public static function call_gemini_with_pdf(string $framework, string $pdf_url, array $rules): array {
        self::reset_debug_state();

        $key = self::resolve_api_key();
        if ($key === '') {
            throw new Exception('Gemini API key missing. Add MV_GEMINI_API_KEY in wp-config.php.');
        }

        if (empty($rules)) {
            throw new Exception('Rule set missing for selected framework.');
        }

        $response = wp_remote_get($pdf_url, [
            'timeout' => 60,
            'redirection' => 5,
        ]);
        if (is_wp_error($response)) {
            throw new Exception('Failed to download PDF: ' . $response->get_error_message());
        }

        $download_code = (int) wp_remote_retrieve_response_code($response);
        if ($download_code < 200 || $download_code >= 300) {
            throw new Exception('Failed to download PDF: HTTP ' . $download_code);
        }

        $pdf_data = wp_remote_retrieve_body($response);
        if (!is_string($pdf_data) || $pdf_data === '') {
            throw new Exception('PDF download returned empty body');
        }

        $bytes = strlen($pdf_data);
        $mb = $bytes / (1024 * 1024);
        if ($mb > 5.0) {
            throw new Exception('PDF too large for inline processing (' . round($mb, 2) . 'MB).');
        }

        $base64 = base64_encode($pdf_data);
        unset($pdf_data);

        $model = self::resolve_model();
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . rawurlencode($key);

        $base_prompt = self::build_prompt($framework, $rules, false);
        $strict_prompt = self::build_prompt($framework, $rules, true);

        $attempt_1 = self::try_request_findings(
            $url,
            self::build_pdf_request_body($base64, $base_prompt, 0.2, 2048),
            'attempt_1_primary'
        );
        if (is_array($attempt_1)) {
            return $attempt_1;
        }

        $attempt_2 = self::try_request_findings(
            $url,
            self::build_pdf_request_body($base64, $strict_prompt, 0.1, 1400),
            'attempt_2_strict'
        );
        if (is_array($attempt_2)) {
            return $attempt_2;
        }

        if (self::$last_candidate_text !== '') {
            $repair_prompt = self::build_repair_prompt($rules, self::$last_candidate_text);
            $attempt_3 = self::try_request_findings(
                $url,
                self::build_text_request_body($repair_prompt, 0.0, 1400),
                'attempt_3_repair'
            );
            if (is_array($attempt_3)) {
                return $attempt_3;
            }
        }

        $msg = self::$last_error !== '' ? self::$last_error : 'Gemini did not return valid JSON';
        throw new Exception($msg);
    }

    public static function get_debug_trace(): array {
        return self::$debug_trace;
    }

    private static function try_request_findings(string $url, array $body, string $attempt): ?array {
        try {
            $resp = wp_remote_post($url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($body),
                'timeout' => 180,
            ]);

            if (is_wp_error($resp)) {
                throw new Exception('HTTP request failed: ' . $resp->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($resp);
            $raw = wp_remote_retrieve_body($resp);
            $raw_excerpt = self::clip((string) $raw, 3000);

            if ($code === 429) {
                throw new Exception('Gemini quota/ratelimit (429).');
            }
            if ($code >= 500) {
                throw new Exception('Gemini server error (' . $code . ').');
            }
            if ($code < 200 || $code >= 300) {
                $snippet = trim(wp_strip_all_tags((string) $raw));
                if ($snippet !== '') {
                    throw new Exception('Gemini HTTP error ' . $code . ': ' . self::clip($snippet, 500));
                }
                throw new Exception('Gemini HTTP error ' . $code . '.');
            }

            $json = json_decode((string) $raw, true);
            if (!is_array($json)) {
                throw new Exception('Gemini response was not valid JSON');
            }

            $textOut = self::extract_text_from_response($json);
            if ($textOut !== '') {
                self::$last_candidate_text = $textOut;
            }

            $findings = self::parse_findings_array($textOut);
            self::add_debug([
                'attempt' => $attempt,
                'status' => $findings === null ? 'invalid_json' : 'ok',
                'response_code' => $code,
                'output_excerpt' => self::clip($textOut, 1800),
                'raw_excerpt' => $raw_excerpt,
            ]);

            if (!is_array($findings)) {
                self::$last_error = 'Gemini did not return valid JSON';
                return null;
            }

            return $findings;
        } catch (Throwable $e) {
            self::$last_error = $e->getMessage();
            self::add_debug([
                'attempt' => $attempt,
                'status' => 'error',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private static function build_prompt(string $framework, array $rules, bool $strict_mode): string {
        $rules_json = wp_json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rule_count = count($rules);
        $schema_code = isset($rules[0]['rule_code']) ? sanitize_text_field((string) $rules[0]['rule_code']) : 'INDAS-01-001';

        $strict_line = $strict_mode
            ? "STRICT MODE: Output ONLY JSON array text. NO markdown. NO notes. NO extra text.\n"
            : '';

        return
            "You are a compliance assistant.\n" .
            "Framework: {$framework}\n\n" .
            "Return ONLY a valid JSON array.\n" .
            $strict_line .
            "Schema (exact keys):\n" .
            "[\n" .
            "  {\"rule_code\":\"{$schema_code}\",\"status\":\"pass|fail|unknown\",\"reason\":\"...\",\"evidence\":[{\"page\":1,\"snippet\":\"...\"}]}\n" .
            "]\n" .
            "Rules:\n" .
            "- Provide exactly {$rule_count} items.\n" .
            "- Every rule_code from Rules JSON must appear exactly once.\n" .
            "- Use ONLY provided rule_code values from Rules JSON. Do not invent new codes.\n" .
            "- status must be one of pass, fail, unknown.\n" .
            "- evidence must be an array (can be empty).\n\n" .
            "Rules JSON:\n{$rules_json}\n";
    }

    private static function build_repair_prompt(array $rules, string $previous_output): string {
        $rules_json = wp_json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $clipped_output = self::clip($previous_output, 5000);

        return
            "Convert the text below into a valid JSON array only.\n" .
            "Do not include markdown or any explanation.\n" .
            "Use only these rule codes from Rules JSON.\n\n" .
            "Rules JSON:\n{$rules_json}\n\n" .
            "Text to repair:\n" .
            $clipped_output;
    }

    private static function build_pdf_request_body(string $base64, string $prompt, float $temperature, int $max_output_tokens): array {
        return [
            "contents" => [[
                "role" => "user",
                "parts" => [
                    [
                        "inlineData" => [
                            "mimeType" => "application/pdf",
                            "data" => $base64,
                        ],
                    ],
                    [
                        "text" => $prompt,
                    ],
                ],
            ]],
            "generationConfig" => [
                "temperature" => $temperature,
                // Keep both keys for broader compatibility across API revisions.
                "responseMimeType" => "application/json",
                "response_mime_type" => "application/json",
                "maxOutputTokens" => $max_output_tokens,
            ],
        ];
    }

    private static function build_text_request_body(string $prompt, float $temperature, int $max_output_tokens): array {
        return [
            "contents" => [[
                "role" => "user",
                "parts" => [[
                    "text" => $prompt,
                ]],
            ]],
            "generationConfig" => [
                "temperature" => $temperature,
                "responseMimeType" => "application/json",
                "response_mime_type" => "application/json",
                "maxOutputTokens" => $max_output_tokens,
            ],
        ];
    }

    private static function resolve_api_key(): string {
        if (defined('MV_GEMINI_API_KEY') && trim((string) MV_GEMINI_API_KEY) !== '') {
            return trim((string) MV_GEMINI_API_KEY);
        }

        $from_option = get_option('mv_gemini_api_key', '');
        return is_string($from_option) ? trim($from_option) : '';
    }

    private static function resolve_model(): string {
        if (defined('MV_GEMINI_MODEL') && trim((string) MV_GEMINI_MODEL) !== '') {
            return trim((string) MV_GEMINI_MODEL);
        }
        return 'gemini-3-flash-preview';
    }

    private static function extract_text_from_response(array $response): string {
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        if (!is_array($parts)) {
            return '';
        }

        foreach ($parts as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                return trim($part['text']);
            }
        }

        return '';
    }

    private static function parse_findings_array(string $textOut): ?array {
        $textOut = trim($textOut);
        if ($textOut === '') {
            return null;
        }

        $parsed = json_decode($textOut, true);
        if (is_array($parsed) && self::is_list($parsed)) {
            return $parsed;
        }
        if (is_array($parsed) && isset($parsed['findings']) && is_array($parsed['findings'])) {
            return $parsed['findings'];
        }

        $textOut = (string) preg_replace('/```(?:json)?/i', '', $textOut);
        $textOut = str_replace('```', '', $textOut);
        $textOut = trim($textOut);

        $parsed = json_decode($textOut, true);
        if (is_array($parsed) && self::is_list($parsed)) {
            return $parsed;
        }
        if (is_array($parsed) && isset($parsed['findings']) && is_array($parsed['findings'])) {
            return $parsed['findings'];
        }

        $start = strpos($textOut, '[');
        $end = strrpos($textOut, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $slice = substr($textOut, $start, $end - $start + 1);
            $parsed = json_decode($slice, true);
            if (is_array($parsed) && self::is_list($parsed)) {
                return $parsed;
            }
        }

        $obj_start = strpos($textOut, '{');
        $obj_end = strrpos($textOut, '}');
        if ($obj_start !== false && $obj_end !== false && $obj_end > $obj_start) {
            $obj_slice = substr($textOut, $obj_start, $obj_end - $obj_start + 1);
            $parsed = json_decode($obj_slice, true);
            if (is_array($parsed) && isset($parsed['findings']) && is_array($parsed['findings'])) {
                return $parsed['findings'];
            }
        }

        return null;
    }

    private static function add_debug(array $entry): void {
        $entry['at'] = gmdate('c');
        self::$debug_trace[] = $entry;
        if (count(self::$debug_trace) > 20) {
            self::$debug_trace = array_slice(self::$debug_trace, -20);
        }
    }

    private static function reset_debug_state(): void {
        self::$debug_trace = [];
        self::$last_candidate_text = '';
        self::$last_error = '';
    }

    private static function clip(string $text, int $limit): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/', ' ', $text);
        if (!is_string($text)) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit);
        }
        return substr($text, 0, $limit);
    }

    private static function is_list(array $arr): bool {
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }
}
