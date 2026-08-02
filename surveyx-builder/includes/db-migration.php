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
            KEY idx_survey_viewed (survey_id, viewed_at),
            UNIQUE KEY unique_response (session_id, question_id, respondent_id, answer_id)
        ){$charset_collate};"
		);

		// Fresh schema already has every index and no data, so mark all migrations
		// as done — nothing for surveyx_maybe_upgrade_db() to run.
		update_option(
			'surveyx_migrations_done',
			[ 'perf_indexes', 'clean_settings_reserved_keys', 'clean_expired_session_responses', 'reconcile_dropped_off_path', 'responses_unique_constraint', 'fix_viewed_at_datetime' ]
		);
	}
}

/**
 * Runs pending DB migrations (invoked at the bottom of this file, on plugins_loaded).
 *
 * Each step is tracked by its own key in the `surveyx_migrations_done` array —
 * NOT by a single version number, which drifted during development and can't be
 * trusted. A step runs whenever its key is missing; every step is idempotent, so
 * re-running an already-applied one is a safe no-op. Existing installs (no keys
 * yet) re-verify every step once — self-healing any earlier drift — then track
 * each step reliably from then on. Add a future migration by appending one entry.
 *
 * Standard-WP shape: on virtually every request all steps are already applied, so
 * this returns right after a single autoloaded-option read (the equivalent of WP
 * core's db-version gate) without touching the DB. Real work happens only in the
 * brief window right after a plugin update introduces a new step.
 *
 * Design note (auto-run + notice): steps AUTO-run here rather than being gated
 * behind a manual "upgrade database" click. Unlike WP core (which can hold back a
 * site until an admin confirms), a survey plugin must never leave rendering broken
 * while it waits for a click — every step is idempotent, optional for rendering,
 * and safe to run unattended. When one or more steps actually run this pass, their
 * keys are stashed in a short-lived transient so surveyx_db_upgrade_admin_notice()
 * can surface a WP-core-style "database updated" confirmation once.
 *
 * Concurrency: because the run can fire on any request (including anonymous front-
 * end hits), pending steps are serialized behind a non-blocking MySQL advisory lock
 * so exactly ONE process runs the ALTER/DELETE statements; every other concurrent
 * request skips and renders with the current schema. Prevents duplicate-DDL races
 * (e.g. two requests both issuing ADD UNIQUE KEY) during the post-update window.
 */
if ( ! function_exists( 'surveyx_maybe_upgrade_db' ) ) {
	function surveyx_maybe_upgrade_db() {
		global $wpdb;

		$done = (array) get_option( 'surveyx_migrations_done', [] );

		// key => callable. All steps here are independent (order-agnostic). The step
		// helpers below are defined in this same array order.
		$steps = [
			'perf_indexes'                    => 'surveyx_migrate_add_indexes',
			'clean_settings_reserved_keys'    => 'surveyx_clean_settings_reserved_keys',
			'clean_expired_session_responses' => 'surveyx_clean_expired_session_responses',
			'responses_unique_constraint'     => 'surveyx_add_responses_unique_constraint',
			'fix_viewed_at_datetime'          => 'surveyx_fix_viewed_at_datetime',
		];

		// reconcile_dropped_off_path needs SurveyX_Session_Manager, which is loaded
		// AFTER this file, so it runs on 'init'; that callback records its own key.
		// Scheduled before the fast-path return so it is registered even when every
		// synchronous step is already applied.
		if ( ! in_array( 'reconcile_dropped_off_path', $done, true ) ) {
			add_action( 'init', 'surveyx_migrate_reconcile_dropped_off_path', 20 );
		}

		// Fast path — the case on virtually every request: all synchronous steps are
		// applied, so there is nothing to do beyond the option read above.
		$pending = array_diff( array_keys( $steps ), $done );
		if ( empty( $pending ) ) {
			return;
		}

		// Schema not installed yet: this is a fresh activation and surveyx_create_database()
		// runs next (and marks every step done). Skip so a new install neither does
		// pointless pre-create work nor emits a spurious "database updated" notice.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'surveyx_surveys' ) ) ) {
			return;
		}

		// Pending work exists (an install just upgraded to a build with new steps).
		// Serialize with a non-blocking advisory lock so exactly ONE request runs the
		// ALTER/DELETE statements; losers return and render with the current schema
		// (steps are optional for rendering and idempotent, so they apply on a later
		// request). The lock is connection-scoped — auto-released at request end even
		// if RELEASE_LOCK is missed.
		$lock_name = substr( $wpdb->prefix . 'surveyx_migrate', 0, 64 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) ) {
			return;
		}

		try {
			// Re-read under the lock: another request may have finished the migration
			// between our fast-path read and acquiring the lock.
			$done      = (array) get_option( 'surveyx_migrations_done', [] );
			$original  = $done;
			$newly_run = [];

			foreach ( $steps as $key => $callback ) {
				if ( ! in_array( $key, $done, true ) ) {
					call_user_func( $callback );
					$done[]      = $key;
					$newly_run[] = $key;
				}
			}

			if ( $done !== $original ) {
				update_option( 'surveyx_migrations_done', array_values( array_unique( $done ) ) );
			}

			// Signal that a DB update ran this pass so the admin notice can show once.
			if ( ! empty( $newly_run ) ) {
				set_transient( 'surveyx_db_upgrade_notice', $newly_run, 5 * MINUTE_IN_SECONDS );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

/**
 * Migration step [perf_indexes]: add the performance composite indexes. Each ADD is
 * guarded by a SHOW INDEX check inside the helpers, so it is safe on any install.
 */
if ( ! function_exists( 'surveyx_migrate_add_indexes' ) ) {
	function surveyx_migrate_add_indexes() {
		global $wpdb;
		// Best-effort perf indexes: on a schema-drifted install an ADD INDEX may fail
		// (e.g. an old table where a column has the wrong type). Suppress DB errors so
		// the one-time re-verify stays quiet — the plugin still works without the index.
		$suppress = $wpdb->suppress_errors( true );
		surveyx_add_response_indexes();
		surveyx_add_session_indexes();
		$wpdb->suppress_errors( $suppress );
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
		// Table name is a trusted $wpdb->prefix identifier and cannot be bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );

		$existing = [];
		foreach ( $indexes as $index ) {
			$existing[ $index->Key_name ] = true;
		}

		// Add missing indexes
		if ( ! isset( $existing['idx_session_question_respondent'] ) ) {
			// One-time migration: schema change on a trusted $wpdb->prefix identifier.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_session_question_respondent (session_id, question_id, respondent_id)" );
		}

		if ( ! isset( $existing['idx_survey_respondent'] ) ) {
			// One-time migration: schema change on a trusted $wpdb->prefix identifier.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_respondent (survey_id, respondent_id)" );
		}

		// Indexes for vote counting and analytics
		if ( ! isset( $existing['idx_survey_answer_status'] ) ) {
			// One-time migration: schema change on a trusted $wpdb->prefix identifier.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_answer_status (survey_id, answer_id, response_status)" );
		}

		if ( ! isset( $existing['idx_survey_viewed'] ) ) {
			// One-time migration: schema change on a trusted $wpdb->prefix identifier.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
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

		// Table name is a trusted $wpdb->prefix identifier and cannot be bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );

		$existing = [];
		foreach ( $indexes as $index ) {
			$existing[ $index->Key_name ] = true;
		}

		if ( ! isset( $existing['idx_status_activity'] ) ) {
			// One-time migration: schema change on a trusted $wpdb->prefix identifier.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_status_activity (session_status, last_activity_at)" );
		}
	}
}

/**
 * Migration step [clean_settings_reserved_keys]: earlier builds saved the whole
 * survey object into the `settings` JSON on the General tab, so reserved DB-column /
 * server-owned keys (content, title, updated_at, …) leaked into the settings blob
 * and could shadow the authoritative columns on load. Strip those keys from every
 * survey's stored settings. Idempotent: a cleaned row has none of the reserved keys,
 * so re-running is a no-op.
 */
/**
 * Reserved survey keys: DB-column / server-owned fields that must never live inside
 * the `settings` JSON blob, where they could shadow the authoritative columns when
 * settings are merged back to the survey root. Single source of truth shared by the
 * settings-write path (quick_update_survey) and the cleanup migration below.
 *
 * @return string[] Reserved key names.
 */
if ( ! function_exists( 'surveyx_reserved_survey_keys' ) ) {
	function surveyx_reserved_survey_keys() {
		return [ 'id', 'title', 'author_id', 'survey_type', 'cover', 'status', 'content', 'draft_content', 's_mode', 'created_at', 'updated_at' ];
	}
}

if ( ! function_exists( 'surveyx_clean_settings_reserved_keys' ) ) {
	function surveyx_clean_settings_reserved_keys() {
		global $wpdb;

		$reserved = surveyx_reserved_survey_keys();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT id, settings FROM {$wpdb->prefix}surveyx_surveys WHERE settings IS NOT NULL AND settings != ''" );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$settings = json_decode( $row->settings, true );
			if ( ! is_array( $settings ) ) {
				continue;
			}

			$changed = false;
			foreach ( $reserved as $key ) {
				if ( array_key_exists( $key, $settings ) ) {
					unset( $settings[ $key ] );
					$changed = true;
				}
			}

			if ( $changed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'surveyx_surveys',
					[ 'settings' => wp_json_encode( $settings ) ],
					[ 'id' => (int) $row->id ],
					[ '%s' ],
					[ '%d' ]
				);
			}
		}
	}
}

/**
 * Migration step [clean_expired_session_responses]: earlier "Allow Revote on Update"
 * builds only marked sessions 'expired' and deleted their responses lazily (when the
 * respondent returned), so respondents who never came back left orphaned responses on
 * expired sessions. Now that expired sessions are excluded from analytics and their
 * responses are cleared eagerly at reset, delete any lingering responses of expired
 * sessions so old data matches the new behavior. Idempotent: no expired-session
 * responses → no-op.
 */
if ( ! function_exists( 'surveyx_clean_expired_session_responses' ) ) {
	function surveyx_clean_expired_session_responses() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE r FROM {$wpdb->prefix}surveyx_responses r
			INNER JOIN {$wpdb->prefix}surveyx_sessions s ON r.session_id = s.id
			WHERE s.session_status = 'expired'"
		);
	}
}

/**
 * Migration step [responses_unique_constraint]: add a UNIQUE key to surveyx_responses
 * so duplicate answer rows are prevented at the DB level, letting /progress drop its
 * per-vote MySQL named lock. Key columns (session_id, question_id, respondent_id,
 * answer_id) allow the legitimate multiple rows per question (multi-select checkbox =
 * one row per distinct answer_id; sentinel rows -8 'seen' / -4 'skipped_optional')
 * while forbidding an EXACT duplicate row — the race the named lock guarded.
 *
 * Self-healing and idempotent: dedups any pre-existing duplicate rows (keeping the
 * lowest id per group) BEFORE the ADD, and no-ops if the key already exists.
 */
if ( ! function_exists( 'surveyx_add_responses_unique_constraint' ) ) {
	function surveyx_add_responses_unique_constraint() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_responses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return;
		}

		// Already applied? SHOW INDEX and bail if unique_response exists.
		// Table name is a trusted $wpdb->prefix identifier and cannot be bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		foreach ( (array) $indexes as $index ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( 'unique_response' === $index->Key_name ) {
				return;
			}
		}

		// Self-heal first: drop duplicate rows left by the pre-lock era, keeping the
		// lowest id per (session_id, question_id, respondent_id, answer_id) group, so
		// the UNIQUE key can be added. No-op when there are no duplicates.
		// Table name is a trusted $wpdb->prefix identifier and cannot be bound via prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$wpdb->query(
			"DELETE r1 FROM {$table} r1
			INNER JOIN {$table} r2
				ON r1.session_id = r2.session_id
				AND r1.question_id = r2.question_id
				AND r1.respondent_id = r2.respondent_id
				AND r1.answer_id = r2.answer_id
				AND r1.id > r2.id"
		);
		// phpcs:enable

		// Add the UNIQUE key. Combined key length is small (BIGINT + INT + CHAR(36) +
		// INT ≈ 160 bytes with utf8mb4), well within InnoDB index limits, so no column
		// change is needed.
		// One-time migration: schema change on a trusted $wpdb->prefix identifier.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY unique_response (session_id, question_id, respondent_id, answer_id)" );
	}
}

/**
 * Migration step [fix_viewed_at_datetime]: convert surveyx_responses.viewed_at from
 * TEXT to DATETIME on installs upgraded from an older schema (dbDelta never changes a
 * column's type), then add the two viewed_at indexes that couldn't be created while
 * the column was TEXT (idx_survey_viewed needs a key length on a TEXT column, so the
 * plain perf step skipped it). The column already holds valid 'Y-m-d H:i:s' strings.
 *
 * Idempotent and order-agnostic: when the column is already DATETIME the ALTER is
 * skipped and only the missing indexes are (re)checked; re-running is a no-op.
 */
if ( ! function_exists( 'surveyx_fix_viewed_at_datetime' ) ) {
	function surveyx_fix_viewed_at_datetime() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_responses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return;
		}

		// Inspect the current column type.
		// Table name is a trusted $wpdb->prefix identifier and cannot be bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'viewed_at' ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$is_text = $col && false === stripos( (string) $col->Type, 'datetime' );

		// Convert TEXT → DATETIME. Sanitize any non-datetime value to NULL first so
		// the ALTER can't fail under strict sql_mode; existing valid datetime strings
		// are untouched.
		if ( $is_text ) {
			// Table name is a trusted $wpdb->prefix identifier and cannot be bound via prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$wpdb->query( "UPDATE {$table} SET viewed_at = NULL WHERE viewed_at IS NOT NULL AND ( viewed_at = '' OR STR_TO_DATE(viewed_at, '%Y-%m-%d %H:%i:%s') IS NULL )" );
			// One-time migration: schema change on a trusted $wpdb->prefix identifier.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN viewed_at DATETIME DEFAULT NULL" );
		}

		// Add the two viewed_at indexes if missing (guarded by SHOW INDEX).
		// Table name is a trusted $wpdb->prefix identifier and cannot be bound via prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
		$indexes  = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$existing = [];
		foreach ( (array) $indexes as $index ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$existing[ $index->Key_name ] = true;
		}

		if ( ! isset( $existing['viewed_at'] ) ) {
			// One-time migration: schema change on a trusted $wpdb->prefix identifier.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX viewed_at (viewed_at)" );
		}

		if ( ! isset( $existing['idx_survey_viewed'] ) ) {
			// One-time migration: schema change on a trusted $wpdb->prefix identifier.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_viewed (survey_id, viewed_at)" );
		}
	}
}

/**
 * Deferred migration step [reconcile_dropped_off_path]: reconcile existing dropped-off
 * sessions of skip-logic surveys whose abandoned-branch responses were never pruned
 * before drop-off pruning existed. Runs on 'init' (needs SurveyX_Session_Manager).
 * Pro-only — the method is absent in free, so free just records the key without work.
 * Retry-safe: re-checks its key and records it only once the step has completed.
 */
if ( ! function_exists( 'surveyx_migrate_reconcile_dropped_off_path' ) ) {
	function surveyx_migrate_reconcile_dropped_off_path() {
		$done = (array) get_option( 'surveyx_migrations_done', [] );
		if ( in_array( 'reconcile_dropped_off_path', $done, true ) ) {
			return;
		}
		if ( method_exists( 'SurveyX_Session_Manager', 'reconcile_dropped_off_path' ) ) {
			SurveyX_Session_Manager::reconcile_dropped_off_path();
		}
		$done[] = 'reconcile_dropped_off_path';
		update_option( 'surveyx_migrations_done', array_values( array_unique( $done ) ) );
	}
}

/**
 * Admin notice mirroring WP core's post-DB-update message: when surveyx_maybe_upgrade_db()
 * actually ran one or more steps this request, it left the run step keys in the
 * `surveyx_db_upgrade_notice` transient. Show a dismissible success notice once (then
 * delete the transient), listing the human-friendly step names. Admin-only.
 */
if ( ! function_exists( 'surveyx_db_upgrade_admin_notice' ) ) {
	function surveyx_db_upgrade_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$keys = get_transient( 'surveyx_db_upgrade_notice' );
		if ( empty( $keys ) || ! is_array( $keys ) ) {
			return;
		}

		// Show once.
		delete_transient( 'surveyx_db_upgrade_notice' );

		$labels = [
			'perf_indexes'                    => __( 'performance indexes', 'surveyx-builder' ),
			'clean_settings_reserved_keys'    => __( 'settings cleanup', 'surveyx-builder' ),
			'clean_expired_session_responses' => __( 'expired-session cleanup', 'surveyx-builder' ),
			'responses_unique_constraint'     => __( 'response de-duplication key', 'surveyx-builder' ),
			'fix_viewed_at_datetime'          => __( 'viewed_at column fix', 'surveyx-builder' ),
			'reconcile_dropped_off_path'      => __( 'drop-off path reconcile', 'surveyx-builder' ),
		];

		$names = [];
		foreach ( $keys as $key ) {
			$names[] = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		}

		$count = count( $names );

		$message = sprintf(
			/* translators: 1: number of migration steps, 2: comma-separated step names */
			_n(
				'SurveyX: database updated successfully (%1$d step: %2$s).',
				'SurveyX: database updated successfully (%1$d steps: %2$s).',
				$count,
				'surveyx-builder'
			),
			$count,
			implode( ', ', $names )
		);

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
add_action( 'admin_notices', 'surveyx_db_upgrade_admin_notice' );

// Auto-run migrations when file is loaded.
surveyx_maybe_upgrade_db();
