<?php
if (!defined('ABSPATH')) exit;

class MV_DB {

    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'mv_';

        // Cases: a "project" container
        $sql_cases = "CREATE TABLE {$prefix}cases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            framework_key VARCHAR(120) NOT NULL DEFAULT 'default',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY framework_key (framework_key),
            KEY status (status)
        ) $charset_collate;";

        // Documents uploaded for a case
        $sql_documents = "CREATE TABLE {$prefix}documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            file_url TEXT NOT NULL,
            file_name VARCHAR(255) NULL,
            file_hash CHAR(64) NULL,
            mime_type VARCHAR(120) NULL,
            pages INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY case_id (case_id),
            KEY file_hash (file_hash)
        ) $charset_collate;";

        // Runs (processing attempts)
        $sql_runs = "CREATE TABLE {$prefix}runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_id BIGINT UNSIGNED NOT NULL,
            document_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            engine_version VARCHAR(50) NULL,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY case_id (case_id),
            KEY document_id (document_id),
            KEY status (status)
        ) $charset_collate;";

        // Findings (rule results) per run
        $sql_findings = "CREATE TABLE {$prefix}findings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            rule_code VARCHAR(80) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'unknown', /* pass|fail|unknown */
            reason TEXT NULL,
            evidence_json LONGTEXT NULL, /* JSON string */
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY rule_code (rule_code),
            KEY status (status)
        ) $charset_collate;";

        // Rules: optional storage for frameworks/checklists
        $sql_rules = "CREATE TABLE {$prefix}rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            framework_key VARCHAR(120) NOT NULL,
            rule_code VARCHAR(80) NOT NULL,
            rule_text TEXT NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'medium',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY framework_rule (framework_key, rule_code),
            KEY framework_key (framework_key),
            KEY enabled (enabled)
        ) $charset_collate;";

        dbDelta($sql_cases);
        dbDelta($sql_documents);
        dbDelta($sql_runs);
        dbDelta($sql_findings);
        dbDelta($sql_rules);

        // Store plugin version for migrations later
        update_option('mv_portal_version', MV_PORTAL_VERSION);
    }
}
