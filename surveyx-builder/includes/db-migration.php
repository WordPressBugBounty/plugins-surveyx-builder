<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

/**
 * Creates database tables for SurveyX Builder.
 *
 * @return void
 * @global wpdb $wpdb WordPress database access abstraction object.
 */
if ( ! function_exists( 'surveyx_create_database' ) ) {
	function surveyx_create_database() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Surveys table
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_surveys (
            id int(11) NOT NULL AUTO_INCREMENT,
            title TEXT NOT NULL,
            author_id int(11) NOT NULL,
            survey_type varchar(30) NOT NULL,
            cover varchar(255) NOT NULL,
            status varchar(30) NOT NULL,
            settings TEXT NOT NULL,
            content TEXT NOT NULL,
            s_mode varchar(30) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id)
        ){$charset_collate};"
		);

		// Questions table
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_questions (
            id int(11) NOT NULL AUTO_INCREMENT,
            title TEXT NOT NULL,
            survey_id int(11) NOT NULL,
            sorder int(11) NOT NULL DEFAULT 1,
            content TEXT NOT NULL,
            PRIMARY KEY (id),
            KEY survey_id (survey_id)
        ){$charset_collate};"
		);

		// Answers table
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_answers (
            id int(11) NOT NULL AUTO_INCREMENT,
            title TEXT NOT NULL,
            survey_id int(11) NOT NULL,
            question_id int(11) NOT NULL,
            sorder int(11) NOT NULL DEFAULT 1,
            content TEXT NOT NULL,
            PRIMARY KEY (id),
            KEY survey_id (survey_id),
            KEY question_id (question_id)
        ){$charset_collate};"
		);

		// Sessions table - Track survey respondent sessions
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_sessions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            survey_id INT(11) NOT NULL,
            respondent_id CHAR(36) NOT NULL,
            session_status VARCHAR(20) NOT NULL DEFAULT 'viewed',
            restart_pending TINYINT(1) NOT NULL DEFAULT 0,
            current_question_id INT(11) NOT NULL DEFAULT 0,
            question_order TEXT NOT NULL,
            total_questions INT(11) NOT NULL DEFAULT 0,
            progress_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            started_at DATETIME NOT NULL,
            last_activity_at DATETIME NOT NULL,
            completed_at DATETIME DEFAULT NULL,
            time_spent INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY survey_id (survey_id),
            KEY respondent_id (respondent_id),
            KEY session_status (session_status),
            KEY last_activity_at (last_activity_at),
            UNIQUE KEY unique_session (survey_id, respondent_id),
            KEY idx_status_activity (session_status, last_activity_at)
        ){$charset_collate};"
		);

		// Summary table - Pre-calculated aggregated metrics
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_summary (
            id INT(11) NOT NULL AUTO_INCREMENT,
            survey_id INT(11) NOT NULL,
            total_views INT(11) NOT NULL DEFAULT 0,
            total_starts INT(11) NOT NULL DEFAULT 0,
            total_completions INT(11) NOT NULL DEFAULT 0,
            total_dropoffs INT(11) NOT NULL DEFAULT 0,
            completion_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            dropoff_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            average_time_seconds INT(11) NOT NULL DEFAULT 0,
            most_common_dropoff_question_id INT(11) DEFAULT NULL,
            question_seen_counts TEXT DEFAULT NULL,
            answer_votes_json TEXT DEFAULT NULL,
            response_count_by_question TEXT DEFAULT NULL,
            last_updated DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY survey_id (survey_id)
        ){$charset_collate};"
		);

		// Respondents table - Central repository for all respondent contact information
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_respondents (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            respondent_id CHAR(36) NOT NULL,
            user_name VARCHAR(255) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL DEFAULT '',
            company VARCHAR(255) NOT NULL DEFAULT '',
            website VARCHAR(255) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(1024) NOT NULL DEFAULT '',
            location VARCHAR(1024) NOT NULL DEFAULT '',
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            total_surveys INT(11) NOT NULL DEFAULT 0,
            synced_at DATETIME DEFAULT NULL,
            sync_status VARCHAR(20) DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY respondent_id (respondent_id),
            KEY email (email),
            KEY phone (phone),
            KEY first_seen_at (first_seen_at),
            KEY sync_status (sync_status)
        ){$charset_collate};"
		);

		// Custom themes table
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_themes (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL DEFAULT '',
            data TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id)
        ){$charset_collate};"
		);

		// Revisions table - For autosave and version history
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_revisions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            survey_id INT(11) NOT NULL,
            revision_type VARCHAR(20) NOT NULL DEFAULT 'autosave',
            data LONGTEXT NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY survey_id (survey_id),
            KEY revision_type (revision_type),
            KEY created_at (created_at)
        ){$charset_collate};"
		);

		// Individual responses
		dbDelta(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}surveyx_responses (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT(20) UNSIGNED NOT NULL,
            survey_id INT(11) NOT NULL,
            question_id INT(11) NOT NULL,
            answer_id INT(11) NOT NULL DEFAULT 0,
            respondent_id CHAR(36) NOT NULL,
            response_content TEXT NOT NULL,
            response_status VARCHAR(20) NOT NULL DEFAULT 'answered',
            viewed_at DATETIME DEFAULT NULL,
            answered_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY survey_id (survey_id),
            KEY question_id (question_id),
            KEY answer_id (answer_id),
            KEY respondent_id (respondent_id),
            KEY response_status (response_status),
            KEY viewed_at (viewed_at),
            KEY idx_session_question_respondent (session_id, question_id, respondent_id),
            KEY idx_survey_respondent (survey_id, respondent_id),
            KEY idx_survey_answer_status (survey_id, answer_id, response_status),
            KEY idx_survey_viewed (survey_id, viewed_at)
        ){$charset_collate};"
		);

		update_option( 'surveyx_db_version', '1.1.0' );
	}
}

/**
 * Adds composite indexes to surveyx_responses table for better query performance.
 * Runs on existing installations that don't have these indexes yet.
 */
if ( ! function_exists( 'surveyx_add_response_indexes' ) ) {
	function surveyx_add_response_indexes() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_responses';

		// Check if table exists first
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return;
		}

		// Check if indexes already exist
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );

		$existing = [];
		foreach ( $indexes as $index ) {
			$existing[ $index->Key_name ] = true;
		}

		// Add missing indexes
		if ( ! isset( $existing['idx_session_question_respondent'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_session_question_respondent (session_id, question_id, respondent_id)" );
		}

		if ( ! isset( $existing['idx_survey_respondent'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_respondent (survey_id, respondent_id)" );
		}

		// Indexes for vote counting and analytics
		if ( ! isset( $existing['idx_survey_answer_status'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_answer_status (survey_id, answer_id, response_status)" );
		}

		if ( ! isset( $existing['idx_survey_viewed'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_viewed (survey_id, viewed_at)" );
		}
	}
}

/**
 * Adds composite index to surveyx_sessions table for stale session detection.
 */
if ( ! function_exists( 'surveyx_add_session_indexes' ) ) {
	function surveyx_add_session_indexes() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );

		$existing = [];
		foreach ( $indexes as $index ) {
			$existing[ $index->Key_name ] = true;
		}

		if ( ! isset( $existing['idx_status_activity'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_status_activity (session_status, last_activity_at)" );
		}
	}
}

/**
 * Runs database migrations if needed.
 * Called on plugins_loaded to upgrade existing installations.
 */
if ( ! function_exists( 'surveyx_maybe_upgrade_db' ) ) {
	function surveyx_maybe_upgrade_db() {
		$current_version = get_option( 'surveyx_db_version', '1.0.0' );

		if ( version_compare( $current_version, '1.1.0', '<' ) ) {
			surveyx_add_response_indexes();
			surveyx_add_session_indexes();
			update_option( 'surveyx_db_version', '1.1.0' );
		}
	}
}

// Auto-run migrations when file is loaded.
surveyx_maybe_upgrade_db();
