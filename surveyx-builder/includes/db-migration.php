<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

/**
 * Schema level of the CREATE TABLE statements in surveyx_create_database().
 *
 * Those statements run through dbDelta() at BOTH install and update time; this constant
 * is what tells the update path they changed, so bump it whenever a table, column or
 * index below is added or altered. An install whose stored `surveyx_db_schema_version`
 * differs reconciles once and stores the new value; every other request skips it on a
 * plain autoloaded-option compare.
 *
 * Deliberately NOT bumped for the surveyx_responses index reshape that ships as the
 * [responses_status_covering_index] step: the declaration below already carries the new
 * index set for a FRESH install, and the step applies the same reshape to existing ones,
 * so both paths end on an identical index set.
 *
 * Bumping is safe with respect to visitor cost, and that is a property of the code rather
 * than of remembering not to bump: surveyx_maybe_upgrade_db() refuses to call
 * surveyx_create_database() outside an administrative request, so no anonymous front-end
 * hit ever pays for the ALTERs (an ADD INDEX measured 490 ms on 365k rows).
 */
if ( ! defined( 'SURVEYX_DB_SCHEMA_VERSION' ) ) {
	define( 'SURVEYX_DB_SCHEMA_VERSION', '2.0.0' );
}

/**
 * Rows a batched migration step may delete in ONE request.
 *
 * The two steps that delete from surveyx_responses take one bounded slice per request; a
 * step with rows left over reports itself unfinished, is not recorded, and the next
 * request takes the next slice (see surveyx_maybe_upgrade_db()). Batching caps what an
 * anonymous visitor pays AND keeps a single statement from holding a write lock on
 * surveyx_responses for minutes while /progress is trying to insert into it.
 *
 * ONE slice per request, not several: measured on a 227k-row responses table, the dedup
 * step costs a flat ~0.72s full-table GROUP BY per slice plus ~0.09s to delete 1000 rows
 * by primary key, so extra slices in the same request multiply the part that does not
 * shrink. 1000 keeps each DELETE's InnoDB write set, undo log and row-lock hold small
 * enough to stay out of the way of live /progress writes.
 */
if ( ! defined( 'SURVEYX_MIGRATION_BATCH' ) ) {
	define( 'SURVEYX_MIGRATION_BATCH', 1000 );
}

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

		// Fresh install or reactivation? Decided BEFORE dbDelta creates anything,
		// because the answer decides whether the migration keys may be stamped.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$is_fresh = ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'surveyx_responses' ) );

		/*
		 * May the surveyx_responses declaration be applied on this pass? It is the one
		 * declaration here that live data can reject: dbDelta() adds a declared-but-missing key
		 * with an unconditional ALTER TABLE ... ADD UNIQUE KEY, which fails with MySQL 1062 on a
		 * table still holding duplicate answer rows, and wpdb prints the statement raw at the top
		 * of wp-admin. Asked here as well as in surveyx_maybe_upgrade_db() because ACTIVATION
		 * calls this function directly and never goes through the runner — and the dedup step
		 * clears only SURVEYX_MIGRATION_BATCH rows per request, so an install with a larger
		 * backlog reaches activation with the key still impossible. $is_fresh short-circuits it:
		 * no table means dbDelta() CREATEs it with the key and there is no data to reject it, so
		 * the fresh-install path costs no extra query and can never be gated off.
		 */
		$responses_ok = $is_fresh || surveyx_responses_accept_unique_key();

		// total_views is an ACCUMULATOR, not a derived figure: nothing else records a view,
		// because someone who opens a survey and leaves may never create a session. It lived in
		// surveyx_summary until [views_to_surveys] moved it here, so a fresh install gets it from
		// this declaration and an existing one from that step. Both paths end on the same column.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}surveyx_surveys (
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
            total_views int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ){$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}surveyx_questions (
            id int(11) NOT NULL AUTO_INCREMENT,
            title TEXT NOT NULL,
            survey_id int(11) NOT NULL,
            sorder int(11) NOT NULL DEFAULT 1,
            content TEXT NOT NULL,
            PRIMARY KEY (id),
            KEY survey_id (survey_id)
        ){$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}surveyx_answers (
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

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}surveyx_sessions (
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

		// Summary table, still declared on purpose: its total_views column is what an install
		// that rolls BACK to a pre-2.0 build resumes incrementing, so the table has to survive
		// this release even though [views_to_surveys] moved the authority onto surveyx_surveys.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}surveyx_summary (
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

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}surveyx_respondents (
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

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}surveyx_themes (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL DEFAULT '',
            data TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id)
        ){$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}surveyx_revisions (
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

		/*
		 * Individual responses. The single-column session_id / survey_id / response_status /
		 * viewed_at keys are deliberately ABSENT — benchmarking showed the optimizer rejects all
		 * four on every query in either plugin (see [responses_status_covering_index] for the
		 * per-key reason). Do NOT re-add them: dbDelta() adds declared-but-missing indexes and
		 * never drops, so a single line here would silently undo that migration on the next
		 * schema-version bump.
		 */
		if ( $responses_ok ) {
			dbDelta(
				"CREATE TABLE {$wpdb->prefix}surveyx_responses (
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
            KEY question_id (question_id),
            KEY answer_id (answer_id),
            KEY respondent_id (respondent_id),
            KEY idx_session_question_respondent (session_id, question_id, respondent_id),
            KEY idx_survey_respondent (survey_id, respondent_id),
            KEY idx_survey_answer_status (survey_id, answer_id, response_status),
            KEY idx_survey_viewed (survey_id, viewed_at),
            KEY idx_survey_status_question_session (survey_id, response_status, question_id, session_id),
            UNIQUE KEY unique_response (session_id, question_id, respondent_id, answer_id)
        ){$charset_collate};"
			);
		}

		/*
		 * Record the level the tables were just reconciled to, on the install AND the update
		 * path, so the version compare in surveyx_maybe_upgrade_db() only fires dbDelta() again
		 * after the constant above is bumped. NOT written when the responses declaration was
		 * deferred: stamping 2.0.0 while unique_response is absent is exactly the drift
		 * [verify_responses_unique_key] exists to repair, and it would also switch off the
		 * compare that brings this function back. Left unstamped, the next administrative request
		 * finds the schema pending again, by which time the dedup step has cleared another batch.
		 */
		if ( $responses_ok ) {
			update_option( 'surveyx_db_schema_version', SURVEYX_DB_SCHEMA_VERSION, true );
		}

		// On a REACTIVATION the tables already existed, so they may carry steps that are still
		// pending or that failed earlier. Leave the tracking option alone and let
		// surveyx_maybe_upgrade_db() decide — stamping here would mark unfinished work as done.
		if ( ! $is_fresh ) {
			return;
		}

		/*
		 * Fresh schema already has every index and no data, so every migration is marked done.
		 * EVERY key in $steps must appear here: a key left out is a step a fresh install runs
		 * against tables the declarations above have just built to their final shape, so it does
		 * redundant work or — like [views_to_surveys], which copies from a surveyx_summary that
		 * exists but holds no data — reports a state it cannot reach and fails forever.
		 */
		update_option(
			'surveyx_migrations_done',
			[ 'perf_indexes', 'clean_settings_reserved_keys', 'clean_expired_session_responses', 'reconcile_dropped_off_path', 'responses_unique_constraint', 'verify_responses_unique_key', 'fix_viewed_at_datetime', 'clean_orphan_summaries', 'autoload_page_options', 'responses_status_covering_index', 'views_to_surveys', 'clean_dead_cron_state', 'clean_orphan_revisions', 'fix_tied_answer_sorder', 'stamp_survey_modes' ]
		);
	}
}

/**
 * Runs the pending DB work: the migration steps below, plus the declared schema via
 * dbDelta() when SURVEYX_DB_SCHEMA_VERSION has moved ahead of the stored one. Invoked at
 * file scope at the bottom of this file, so it also fires on anonymous front-end hits.
 *
 * The dbDelta() reconcile is not simply "first": it applies a declared-but-missing UNIQUE
 * key with an unconditional ALTER, so it must run AFTER the steps that make the data able
 * to satisfy the declaration and BEFORE the steps that may read a column it adds. That
 * position is $schema_blockers, applied inside the step loop.
 *
 * Steps are tracked by their own keys in `surveyx_migrations_done`, NOT by a version
 * number (which drifted during development). Every step is idempotent, so an install with
 * no keys yet re-verifies all of them once, self-healing earlier drift; add a future
 * migration by appending one entry.
 *
 * On virtually every request this returns after two autoloaded-option reads and NO query
 * at all. That must hold on an install whose only outstanding work is DDL as well, since
 * the gate below leaves those steps permanently unrunnable for visitors — hence the
 * context filter runs BEFORE the retry-transient read, the SHOW TABLES and the advisory
 * lock, or every request of the site would pay 3 queries plus GET_LOCK/RELEASE_LOCK,
 * forever.
 *
 * Each completed step is recorded INSIDE the loop, the moment it succeeds: this is
 * unbounded work on ordinary front-end requests, so a killed request is normal rather than
 * exceptional, and recording after the loop would discard everything that had finished.
 * Steps auto-run rather than waiting behind a manual "upgrade database" click — rendering
 * must never stay broken waiting for one — and the keys that ran go into a short-lived
 * transient for surveyx_db_upgrade_admin_notice().
 *
 * Concurrency: pending steps are serialized behind a non-blocking MySQL advisory lock so
 * exactly ONE process runs the ALTER/DELETE statements; every other concurrent request
 * renders with the current schema. Prevents duplicate-DDL races in the post-update window.
 */
if ( ! function_exists( 'surveyx_maybe_upgrade_db' ) ) {
	function surveyx_maybe_upgrade_db() {
		global $wpdb;

		$done = (array) get_option( 'surveyx_migrations_done', [] );

		// WordPress does not fire the activation hook on a plugin update, so the
		// declared schema would otherwise only ever reach a fresh install. Compare the
		// stored level with the constant: on a match (the case on every request of an
		// up-to-date install) this is one autoloaded-option read and nothing else.
		$schema_pending = SURVEYX_DB_SCHEMA_VERSION !== (string) get_option( 'surveyx_db_schema_version', '' );

		/*
		 * key => callable, IN THE ORDER THEY MUST RUN. These steps are NOT order-agnostic — a new
		 * one cannot simply be inserted anywhere:
		 *
		 * 1. responses_unique_constraint before the dbDelta() reconcile. The declared CREATE TABLE
		 *    for surveyx_responses carries UNIQUE KEY unique_response and dbDelta() applies a
		 *    declared-but-missing key with an unconditional ALTER TABLE ... ADD UNIQUE KEY, so on
		 *    every install still holding duplicate answer rows that ALTER fails with MySQL 1062
		 *    and wpdb prints it raw at the top of wp-admin and into debug.log.
		 * 2. fix_viewed_at_datetime before perf_indexes. idx_survey_viewed cannot be built while
		 *    viewed_at is still TEXT (MySQL 1170 — see surveyx_add_response_indexes()), so the
		 *    other order DESIGNS perf_indexes to fail its first pass on a legacy install and tells
		 *    the admin the database update "could not be completed" on a healthy database.
		 *
		 * verify_responses_unique_key and responses_status_covering_index verify their own
		 * preconditions and report 'incomplete', so their position carries no meaning. The two
		 * data-repair steps at the end are plain DML over long-existing columns and are APPENDED
		 * rather than inserted for a second reason: the reconcile lands at the first key that is
		 * not a $schema_blocker, so a step inserted above fix_viewed_at_datetime would move it.
		 */
		$steps = [
			'responses_unique_constraint'     => 'surveyx_add_responses_unique_constraint',
			'verify_responses_unique_key'     => 'surveyx_verify_responses_unique_key',
			'fix_viewed_at_datetime'          => 'surveyx_fix_viewed_at_datetime',
			'perf_indexes'                    => 'surveyx_migrate_add_indexes',
			'clean_settings_reserved_keys'    => 'surveyx_clean_settings_reserved_keys',
			'clean_expired_session_responses' => 'surveyx_clean_expired_session_responses',
			'clean_orphan_summaries'          => 'surveyx_clean_orphan_summaries',
			'autoload_page_options'           => 'surveyx_autoload_page_options',
			'responses_status_covering_index' => 'surveyx_responses_status_covering_index',
			'views_to_surveys'                => 'surveyx_migrate_views_to_surveys',
			'clean_dead_cron_state'           => 'surveyx_clean_dead_cron_state',
			'clean_orphan_revisions'          => 'surveyx_clean_orphan_revisions',
			'fix_tied_answer_sorder'          => 'surveyx_fix_tied_answer_sorder',
			'stamp_survey_modes'              => 'surveyx_stamp_survey_modes',
		];

		// Steps the dbDelta() reconcile must not run ahead of, in $steps order — it is applied at
		// the FIRST key that is not on this list, so adding one here also moves the reconcile. A
		// step belongs here only when the declared schema cannot be applied until it has run.
		$schema_blockers = [ 'responses_unique_constraint', 'verify_responses_unique_key' ];

		// reconcile_dropped_off_path needs SurveyX_Session_Manager, loaded AFTER this file, so it
		// runs on 'init' and records its own key. Scheduled before the fast-path return so it is
		// registered even when every synchronous step is already applied.
		if ( ! in_array( 'reconcile_dropped_off_path', $done, true ) ) {
			add_action( 'init', 'surveyx_migrate_reconcile_dropped_off_path', 20 );
		}

		// Fast path, the case on virtually every request: nothing to do beyond the reads above.
		$pending = array_diff( array_keys( $steps ), $done );
		if ( empty( $pending ) && ! $schema_pending ) {
			return;
		}

		/*
		 * DDL gate. It lives in the runner, not in the steps: a per-step gate is
		 * opt-in, and it cannot cover dbDelta() at all because dbDelta() is not a
		 * step. Here an unlisted step counts as DDL-capable, so a step added later
		 * is gated by default; opting one out is a one-line edit in
		 * surveyx_migration_step_is_public_safe().
		 *
		 * Gated work still lands: the plugin's own hourly cron event is spawned by
		 * ordinary front-end traffic, and wp-admin or WP-CLI pick it up sooner. The
		 * install this leaves unmigrated is DISABLE_WP_CRON with no system cron, no
		 * WP-CLI and an owner who never opens wp-admin — accepted, because every step
		 * here is optional for rendering.
		 */
		$administrative = surveyx_is_administrative_request();

		$attemptable = [];

		foreach ( $pending as $pending_key ) {
			if ( $administrative || surveyx_migration_step_is_public_safe( $pending_key ) ) {
				$attemptable[] = $pending_key;
			}
		}

		// dbDelta() is DDL by definition, so the declared schema is reconciled under the same gate
		// as the steps. A public request finding it pending does NEITHER: the steps below may
		// depend on a column dbDelta() adds, so running them alone would use an older schema.
		$schema_pending = $schema_pending && $administrative;

		// Nothing this request is allowed to touch. Return BEFORE the retry transient, the SHOW
		// TABLES and the advisory lock, so a fully-migrated install whose only outstanding work is
		// DDL costs an anonymous visitor the two option reads above and not one query more.
		if ( empty( $attemptable ) && ! $schema_pending ) {
			return;
		}

		/*
		 * Retry backoff for steps that FAILED on an earlier pass, so a broken ALTER is not
		 * re-issued on every request while the underlying problem persists. Keyed PER STEP, in one
		 * transient holding step key => [ retry_at, attempts ]: the same per-request cost as the
		 * single global flag it replaces, but a failing step now backs off ALONE — a global flag
		 * suppressed the whole runner, so one permanently failing step starved every healthy step
		 * behind it. Only read when this request actually has something it may run.
		 */
		$backoff       = surveyx_migration_backoff_read();
		$backoff_dirty = false;
		$now           = time();

		// WP-CLI is the documented way out of a stuck migration, so it ignores the retry windows:
		// an owner who has just fixed the underlying problem gets the work now instead of up to a
		// day later. Nothing else may skip a window — this is a human with no execution limit.
		$ignore_backoff = ( defined( 'WP_CLI' ) && WP_CLI );

		// Every step this request may attempt is inside its own backoff window, so
		// there is nothing to do. Return before the lock.
		if ( ! $schema_pending && ! $ignore_backoff ) {
			$runnable = false;

			foreach ( $attemptable as $pending_key ) {
				if ( ! isset( $backoff[ $pending_key ] ) || surveyx_migration_retry_at( $backoff[ $pending_key ] ) <= $now ) {
					$runnable = true;
					break;
				}
			}

			if ( ! $runnable ) {
				return;
			}
		}

		// Schema not installed yet: this is a fresh activation and surveyx_create_database()
		// runs next (and marks every step done). Skip so a new install neither does
		// pointless pre-create work nor emits a spurious "database updated" notice.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'surveyx_surveys' ) ) ) {
			return;
		}

		// Serialize with a non-blocking advisory lock so exactly ONE request runs the ALTER/DELETE
		// statements; losers return and render with the current schema (steps are idempotent and
		// optional for rendering). Connection-scoped, so it is released even if RELEASE_LOCK is missed.
		$lock_name = substr( $wpdb->prefix . 'surveyx_migrate', 0, 64 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) ) ) {
			return;
		}

		try {
			// Re-read under the lock: another request may have finished the migration
			// between our fast-path read and acquiring the lock.
			$done      = (array) get_option( 'surveyx_migrations_done', [] );
			$newly_run = [];
			$failed    = [];

			foreach ( $steps as $key => $callback ) {
				/*
				 * Declared-schema reconcile, HERE inside the loop at the first step that is not a
				 * $schema_blocker. dbDelta() applies the declared UNIQUE KEY unique_response with an
				 * unconditional ADD UNIQUE KEY, so running it on a database that still holds duplicate
				 * answer rows guarantees a MySQL 1062 that wpdb prints raw onto the admin screen and into
				 * debug.log; running the dedup step first means dbDelta() finds the key present and issues
				 * no ALTER for it at all. Silencing wpdb around the reconcile instead is rejected on
				 * purpose: a dbDelta() whose ALTER failed silently is exactly how an install ends up
				 * recorded as migrated while unique_response is missing, which is the drift
				 * verify_responses_unique_key had to be written to repair.
				 *
				 * It still runs BEFORE every non-blocking step, because those may depend on a column it
				 * adds. surveyx_create_database() is a no-op for the migration keys here — the tables
				 * already exist, so it only runs dbDelta() and re-stamps the schema version.
				 */
				if ( $schema_pending && ! in_array( $key, $schema_blockers, true ) ) {
					// One attempt per pass either way. If the blockers above could not finish — a
					// >1000-row dedup backlog reports 'incomplete', a failed one reports false — the
					// declaration waits for the next administrative request rather than being forced
					// onto data that cannot satisfy it. Nothing is stamped, so this is true again next pass.
					$schema_pending = false;

					if ( surveyx_responses_accept_unique_key() ) {
						surveyx_create_database();
					}
				}

				if ( in_array( $key, $done, true ) ) {
					continue;
				}

				// The gate, applied to every step rather than to the ones that thought
				// to ask for it. Not recorded and no backoff armed: the step simply
				// runs on the next request that qualifies.
				$public_safe = surveyx_migration_step_is_public_safe( $key );

				if ( ! $administrative && ! $public_safe ) {
					continue;
				}

				// This step failed recently and its retry window has not elapsed. Skip
				// THIS step only; the others are untouched.
				if ( ! $ignore_backoff && isset( $backoff[ $key ] ) && surveyx_migration_retry_at( $backoff[ $key ] ) > $now ) {
					continue;
				}

				/*
				 * WRITE-AHEAD FAILURE RECORD, for the steps that can issue DDL. An ALTER on a large table
				 * can take the whole request down with it: PHP-FPM's request_terminate_timeout SIGTERMs
				 * the worker mid-statement, InnoDB rolls the DDL back, and no PHP after the query ever
				 * runs — so a backoff armed AFTER the call is never armed at all, and the next wp-admin
				 * page load reissues the same doomed statement, forever. The failure is therefore recorded
				 * BEFORE the statement is issued and cleared only by getting a result back: a killed
				 * request leaves the window armed exactly as a returned false would, and the ladder in
				 * surveyx_migration_retry_delay() turns an unfixable step into one attempt a day.
				 */
				if ( ! $public_safe ) {
					$attempts        = isset( $backoff[ $key ] ) ? surveyx_migration_attempts( $backoff[ $key ] ) + 1 : 1;
					$backoff[ $key ] = [
						'retry_at' => $now + surveyx_migration_retry_delay( $attempts ),
						'attempts' => $attempts,
					];

					// Persisted NOW: the end of the loop is what a killed request does not reach.
					surveyx_migration_backoff_save( $backoff, $now );
					$backoff_dirty = false;

					// Best-effort room for the statement to finish rather than be rolled back.
					surveyx_raise_limits_for_ddl();
				}

				/*
				 * Every step verifies its own post-condition and reports true (finished), 'incomplete'
				 * (this request's bounded slice is done but rows remain, or a precondition is not met yet)
				 * or false (failed). Only true is recorded, so the other two resume on a later request.
				 * They are told apart because only a real failure earns the admin error notice and a retry
				 * backoff — telling the admin a step "could not be completed" while it is steadily working
				 * through its batches would be untrue.
				 */
				$result = call_user_func( $callback );

				if ( true === $result ) {
					$done[]      = $key;
					$newly_run[] = $key;

					// Persist THIS step now, not once after the loop: a kill part-way through is
					// normal here, and recording at the end would discard every step that had
					// already succeeded and restart the whole migration next request.
					update_option( 'surveyx_migrations_done', array_values( array_unique( $done ) ) );

					// It succeeded, so the write-ahead record armed just above is history.
					if ( isset( $backoff[ $key ] ) ) {
						unset( $backoff[ $key ] );
						$backoff_dirty = true;
					}

					continue;
				}

				// No bookkeeping and no backoff for 'incomplete': the next request takes the next slice.
				// What is bounded is per STEP, not per request — the request holding the lock runs every
				// pending step, so it pays at most ONE slice of each and never two of the same one.
				if ( 'incomplete' === $result ) {
					// It returned, so the write-ahead record is withdrawn: nothing failed, and the
					// next qualifying request should take the next slice immediately rather than
					// wait out a window. The attempt counter resets too — the ladder counts failures.
					if ( ! $public_safe && isset( $backoff[ $key ] ) ) {
						unset( $backoff[ $key ] );
						$backoff_dirty = true;
					}

					continue;
				}

				// A reported failure. For a DDL step the window is already armed by the write-ahead
				// record above and is left as it stands, so failing by returning false and failing by
				// taking the worker down with it are treated identically.
				$failed[] = $key;

				if ( $public_safe ) {
					$attempts        = isset( $backoff[ $key ] ) ? surveyx_migration_attempts( $backoff[ $key ] ) + 1 : 1;
					$backoff[ $key ] = [
						'retry_at' => $now + surveyx_migration_retry_delay( $attempts ),
						'attempts' => $attempts,
					];
				}

				$backoff_dirty = true;
			}

			// Signal that a DB update ran this pass so the admin notice can show once.
			if ( ! empty( $newly_run ) ) {
				set_transient( 'surveyx_db_upgrade_notice', $newly_run, 5 * MINUTE_IN_SECONDS );
			}

			/*
			 * Tell the admin the truth about the steps that did not complete — and WITHDRAW the ones
			 * that since have, so a step that failed in one pass and succeeded in the next does not
			 * leave its red "could not be completed" standing beside the green "database updated" it
			 * has just earned. Filtered by what is RECORDED AS DONE rather than blanket-cleared: a
			 * step merely waiting out its retry window is attempted in neither $newly_run nor $failed,
			 * and blanket-clearing would silently drop a failure that is still outstanding.
			 */
			$standing = get_transient( 'surveyx_db_upgrade_failed' );
			$carried  = is_array( $standing ) ? array_diff( $standing, $done ) : [];
			$failed   = array_values( array_unique( array_merge( $carried, $failed ) ) );

			if ( ! empty( $failed ) ) {
				set_transient( 'surveyx_db_upgrade_failed', $failed, 5 * MINUTE_IN_SECONDS );
			} elseif ( false !== $standing ) {
				delete_transient( 'surveyx_db_upgrade_failed' );
			}

			// Persist the per-step retry windows. Only written when something changed, and an empty
			// map removes the transient entirely so the next pending pass reads nothing at all.
			if ( $backoff_dirty ) {
				surveyx_migration_backoff_save( $backoff, $now );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}

/**
 * Index names currently present on a table, keyed for isset() lookups.
 *
 * Used both to skip an ADD that is already applied and to verify the post-condition
 * afterwards: a suppressed ALTER failure raises nothing, so "the index exists" is the
 * only trustworthy success signal a migration step has.
 *
 * @param string $table Full table name (always built from $wpdb->prefix).
 * @return array<string,bool> Index name => true. Empty when the table has none.
 */
if ( ! function_exists( 'surveyx_get_table_indexes' ) ) {
	function surveyx_get_table_indexes( $table ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes  = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$existing = [];

		foreach ( (array) $indexes as $index ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$existing[ $index->Key_name ] = true;
		}

		return $existing;
	}
}

/**
 * Whether surveyx_responses can accept the declared UNIQUE KEY unique_response.
 *
 * The one precondition the dbDelta() reconcile in surveyx_maybe_upgrade_db() has to check:
 * dbDelta() adds a declared-but-missing key with a plain ALTER TABLE ... ADD UNIQUE KEY
 * and has no notion of a key the data cannot yet satisfy, so on a table still holding
 * duplicate answer rows that ALTER fails with MySQL 1062 and wpdb prints it. Asking first
 * turns that into a deferral.
 *
 * Two ways to answer yes, both meaning "dbDelta() will not issue that ALTER": the key is
 * already there, or the table does not exist at all and dbDelta() will CREATE it with the
 * key. Only called on the rare pass where the schema version has moved, so its two queries
 * cost a settled install nothing.
 *
 * @return bool True when the reconcile may proceed.
 */
if ( ! function_exists( 'surveyx_responses_accept_unique_key' ) ) {
	function surveyx_responses_accept_unique_key() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_responses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return true;
		}

		$existing = surveyx_get_table_indexes( $table );

		return isset( $existing['unique_response'] );
	}
}

/**
 * Whether UNIQUE KEY unique_response actually exists on surveyx_responses yet.
 *
 * Answered from the RECORDED MIGRATION KEY, not from SHOW INDEX, for the same two reasons
 * surveyx_views_on_surveys_table() is: `surveyx_migrations_done` is autoloaded and already
 * read on every request, where a probe would cost a query on the /progress hot path — and
 * the key answers "has the ALTER happened on THIS install", which is the real question,
 * because shipping new files changes no schema.
 *
 * The caller is the /progress dedup, which has to hold its pre-2.0 named lock for as long
 * as the answer is false; the reasoning lives beside it, in
 * SurveyX_Progress_Handler::create_response_and_update_session().
 *
 * @return bool True once the [responses_unique_constraint] step has completed here — from
 *              which point the database itself rejects duplicate answer rows.
 */
if ( ! function_exists( 'surveyx_responses_unique_key_migrated' ) ) {
	function surveyx_responses_unique_key_migrated() {
		return in_array( 'responses_unique_constraint', (array) get_option( 'surveyx_migrations_done', [] ), true );
	}
}

/**
 * Migration step [perf_indexes]: add the performance composite indexes. Each ADD is
 * guarded by a SHOW INDEX check inside the helpers, so it is safe on any install.
 *
 * @return bool True only when every expected index exists afterwards.
 */
if ( ! function_exists( 'surveyx_migrate_add_indexes' ) ) {
	function surveyx_migrate_add_indexes() {
		global $wpdb;
		// Best-effort perf indexes: on a schema-drifted install an ADD INDEX may fail (e.g. a
		// column with the wrong type), so DB errors are suppressed and the helpers' own SHOW INDEX
		// post-conditions decide. Both always run — done only once BOTH tables carry their indexes.
		$suppress     = $wpdb->suppress_errors( true );
		$responses_ok = surveyx_add_response_indexes();
		$sessions_ok  = surveyx_add_session_indexes();
		$wpdb->suppress_errors( $suppress );

		return $responses_ok && $sessions_ok;
	}
}

/**
 * Adds the composite indexes that existing surveyx_responses tables lack.
 *
 * @return bool True when all four indexes are present afterwards.
 */
if ( ! function_exists( 'surveyx_add_response_indexes' ) ) {
	function surveyx_add_response_indexes() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_responses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return true; // Nothing to index — not a failure this step could fix.
		}

		$existing = surveyx_get_table_indexes( $table );

		if ( ! isset( $existing['idx_session_question_respondent'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_session_question_respondent (session_id, question_id, respondent_id)" );
		}

		if ( ! isset( $existing['idx_survey_respondent'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_respondent (survey_id, respondent_id)" );
		}

		if ( ! isset( $existing['idx_survey_answer_status'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_answer_status (survey_id, answer_id, response_status)" );
		}

		if ( ! isset( $existing['idx_survey_viewed'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_viewed (survey_id, viewed_at)" );
		}

		// Post-condition: re-read SHOW INDEX. DB errors are suppressed by the caller, so a failed
		// ALTER is otherwise completely silent. idx_survey_viewed cannot be created while
		// viewed_at is still TEXT, so this reports false until fix_viewed_at_datetime has run.
		$existing = surveyx_get_table_indexes( $table );

		foreach ( [ 'idx_session_question_respondent', 'idx_survey_respondent', 'idx_survey_answer_status', 'idx_survey_viewed' ] as $name ) {
			if ( ! isset( $existing[ $name ] ) ) {
				return false;
			}
		}

		return true;
	}
}

/**
 * Adds composite index to surveyx_sessions table for stale session detection.
 *
 * @return bool True when idx_status_activity is present afterwards.
 */
if ( ! function_exists( 'surveyx_add_session_indexes' ) ) {
	function surveyx_add_session_indexes() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_sessions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return true; // Nothing to index — not a failure this step could fix.
		}

		$existing = surveyx_get_table_indexes( $table );

		if ( isset( $existing['idx_status_activity'] ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_status_activity (session_status, last_activity_at)" );

		// Post-condition: DB errors are suppressed by the caller, so only SHOW INDEX
		// can confirm the ALTER actually landed.
		$existing = surveyx_get_table_indexes( $table );

		return isset( $existing['idx_status_activity'] );
	}
}

/**
 * Migration step [clean_settings_reserved_keys]: earlier builds saved the whole
 * survey object into the `settings` JSON on the General tab, so reserved DB-column /
 * server-owned keys (content, title, updated_at, …) leaked into the settings blob
 * and could shadow the authoritative columns on load. Strip those keys from every
 * survey's stored settings. Idempotent: a cleaned row has none of the reserved keys,
 * so re-running is a no-op.
 *
 * @return bool True when every row that needed cleaning was rewritten.
 */
if ( ! function_exists( 'surveyx_clean_settings_reserved_keys' ) ) {
	function surveyx_clean_settings_reserved_keys() {
		global $wpdb;

		$reserved = [ 'id', 'title', 'author_id', 'survey_type', 'cover', 'status', 'content', 'draft_content', 's_mode', 'created_at', 'updated_at' ];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT id, settings FROM {$wpdb->prefix}surveyx_surveys WHERE settings IS NOT NULL AND settings != ''" );

		if ( empty( $rows ) ) {
			return true;
		}

		$ok = true;

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

			if ( ! $changed ) {
				continue;
			}

			// wp_json_encode() returns false on malformed UTF-8 or an over-deep blob.
			// Writing that would store an empty string and destroy the survey's whole
			// settings, so skip the row and report the step as not finished.
			$json = wp_json_encode( $settings );
			if ( false === $json ) {
				$ok = false;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$wpdb->prefix . 'surveyx_surveys',
				[ 'settings' => $json ],
				[ 'id' => (int) $row->id ],
				[ '%s' ],
				[ '%d' ]
			);

			if ( false === $updated ) {
				$ok = false;
			}
		}

		return $ok;
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
 *
 * Batched: it can run on an anonymous front-end request against the highest-cardinality
 * table in the schema, where one statement could hold a write lock for minutes and be
 * killed by max_execution_time. The single-table `IN (…)` form is what allows the LIMIT —
 * MySQL accepts none on the multi-table `DELETE r FROM … JOIN …` it replaces.
 *
 * @return bool|string True when no expired-session responses are left, 'incomplete'
 *                     when this request's slices are used up and rows remain, false
 *                     when a DELETE failed.
 */
if ( ! function_exists( 'surveyx_clean_expired_session_responses' ) ) {
	function surveyx_clean_expired_session_responses() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}surveyx_responses
				WHERE session_id IN (
					SELECT id FROM {$wpdb->prefix}surveyx_sessions WHERE session_status = 'expired'
				)
				LIMIT %d",
				SURVEYX_MIGRATION_BATCH
			)
		);
		// phpcs:enable

		// A timed-out or aborted DELETE returns false; only then is the step failed.
		if ( false === $deleted ) {
			return false;
		}

		// A short slice means the last matching row is gone. A full one means more
		// remain, so the step reports itself unfinished and resumes next request.
		return $deleted < SURVEYX_MIGRATION_BATCH ? true : 'incomplete';
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
 *
 * @return bool|string True only when the unique_response key actually exists
 *                     afterwards — verified with SHOW INDEX, never inferred from
 *                     "no error was raised". 'incomplete' when this request's
 *                     dedup slices are used up and duplicates remain, false on
 *                     a failed DELETE or a missing key after the ADD.
 */
if ( ! function_exists( 'surveyx_add_responses_unique_constraint' ) ) {
	function surveyx_add_responses_unique_constraint() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_responses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return true; // No table, no duplicates — nothing this step can fix.
		}

		$existing = surveyx_get_table_indexes( $table );
		if ( isset( $existing['unique_response'] ) ) {
			return true;
		}

		/*
		 * Self-heal first: drop duplicate rows left by the pre-lock era, keeping the lowest id per
		 * (session_id, question_id, respondent_id, answer_id) group, so the UNIQUE key can be
		 * added. Deliberately NOT the self-join form (DELETE r1 ... JOIN r1.id > r2.id): with
		 * idx_session_question_respondent present — i.e. on every real install — MySQL drives that
		 * join through the index while rows are being deleted out from under it and removes only
		 * part of each duplicate group, so the ADD below then fails on the leftovers (measured on
		 * 8.0.35: 2 of 5 duplicate rows removed, not converging across passes). The MIN(id) form
		 * names every row to drop up front, so slicing it changes only how many are dropped, never
		 * which ones; the inner derived table materialises the keeper set once instead of per row.
		 *
		 * Bounded and two-phase, because this can run on an anonymous front-end request: phase one
		 * READS at most SURVEYX_MIGRATION_BATCH ids and takes no write locks, so live /progress
		 * INSERTs are never blocked by it; phase two deletes exactly those by primary key.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$duplicate_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE id NOT IN (
					SELECT keep_id FROM (
						SELECT MIN(id) AS keep_id
						FROM {$table}
						GROUP BY session_id, question_id, respondent_id, answer_id
					) keepers
				)
				ORDER BY id
				LIMIT %d",
				SURVEYX_MIGRATION_BATCH
			)
		);
		// phpcs:enable

		if ( ! empty( $duplicate_ids ) ) {
			// Ids come straight from the column above and are cast again here, so the
			// interpolated list can only ever be integers.
			$id_list = implode( ',', array_map( 'absint', $duplicate_ids ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deduped = $wpdb->query( "DELETE FROM {$table} WHERE id IN ({$id_list})" );
			// phpcs:enable

			// A timed-out or aborted DELETE. Do not attempt the ADD (it would fail on
			// the leftover duplicates); leave the step pending for a later request.
			if ( false === $deduped ) {
				return false;
			}

			// A full slice means more duplicates are behind it. Hand them to the next
			// request rather than run an ADD that is guaranteed to fail on them.
			if ( count( $duplicate_ids ) >= SURVEYX_MIGRATION_BATCH ) {
				return 'incomplete';
			}
		}

		// Add the UNIQUE key. Combined key length is small (BIGINT + INT + CHAR(36) +
		// INT ≈ 160 bytes with utf8mb4), well within InnoDB index limits, so no column
		// change is needed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY unique_response (session_id, question_id, respondent_id, answer_id)" );

		// Post-condition, and the most important one in this file: without this key the INSERT
		// IGNORE in /progress silently degrades to a plain INSERT. Until it lands /progress falls
		// back to its pre-2.0 named lock, so confirm the key is really there before reporting success.
		$existing = surveyx_get_table_indexes( $table );

		return isset( $existing['unique_response'] );
	}
}

/**
 * Migration step [verify_responses_unique_key]: confirm surveyx_responses really carries
 * the unique_response key, and repair the install if it does not.
 *
 * An install can carry responses_unique_constraint in `surveyx_migrations_done` while the
 * key was never created — an older runner recorded a failed step as done, or dbDelta
 * re-declared the key and its ADD failed silently on pre-existing duplicate rows. The
 * runner skips any step whose key is recorded, so nothing else would ever notice: without
 * unique_response the INSERT IGNORE in /progress degrades to a plain INSERT and duplicate
 * votes accumulate for good.
 *
 * Deliberately an ordinary TRACKED step rather than the one-shot it used to be. That lived
 * in the runner behind `$schema_pending`, which surveyx_create_database() clears by
 * stamping surveyx_db_schema_version BEFORE the step loop runs, so a request killed
 * anywhere in the loop consumed the repair permanently: the stamp survived, the in-memory
 * un-recording did not. As a step it is recorded only once the key is VERIFIED to exist.
 *
 * Order-independent and cheap: while responses_unique_constraint is itself still pending
 * that step owns the (batched) repair, so this returns 'incomplete' without issuing a
 * single query rather than run the same dedup slices twice in one request.
 *
 * @return bool|string True when unique_response exists, 'incomplete' while the primary
 *                     step still owns the work, otherwise whatever
 *                     surveyx_add_responses_unique_constraint() reports.
 */
if ( ! function_exists( 'surveyx_verify_responses_unique_key' ) ) {
	function surveyx_verify_responses_unique_key() {
		global $wpdb;

		// Cached autoloaded read, no query: while the primary step has not been
		// recorded it is still in the loop and will do the work itself.
		$done = (array) get_option( 'surveyx_migrations_done', [] );
		if ( ! in_array( 'responses_unique_constraint', $done, true ) ) {
			return 'incomplete';
		}

		$table = $wpdb->prefix . 'surveyx_responses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return true; // No table, no key to verify.
		}

		$existing = surveyx_get_table_indexes( $table );
		if ( isset( $existing['unique_response'] ) ) {
			return true;
		}

		// Recorded as done while the key is missing — the drift this step exists for. Re-run the
		// primary step's idempotent dedup + ADD; it verifies the key with SHOW INDEX before
		// reporting success, so this is recorded only if the repair genuinely worked.
		return surveyx_add_responses_unique_constraint();
	}
}

/**
 * Migration step [fix_viewed_at_datetime]: convert surveyx_responses.viewed_at from
 * TEXT to DATETIME on installs upgraded from an older schema (dbDelta never changes a
 * column's type), then add idx_survey_viewed, which couldn't be created while the
 * column was TEXT (it needs a key length on a TEXT column, so the plain perf step
 * skipped it). The column already holds valid 'Y-m-d H:i:s' strings.
 *
 * The single-column `viewed_at` key this step used to add as well is gone on purpose:
 * [responses_status_covering_index] drops it as proven-unused, and re-creating it here
 * would resurrect it on any install where that step ran before this one.
 *
 * Idempotent and order-agnostic: when the column is already DATETIME the ALTER is
 * skipped and only the missing index is (re)checked; re-running is a no-op.
 *
 * @return bool True when viewed_at is DATETIME and idx_survey_viewed exists afterwards.
 */
if ( ! function_exists( 'surveyx_fix_viewed_at_datetime' ) ) {
	function surveyx_fix_viewed_at_datetime() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_responses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return true; // No table, no column to convert.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'viewed_at' ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$is_text = $col && false === stripos( (string) $col->Type, 'datetime' );

		// Convert TEXT → DATETIME. Sanitize any non-datetime value to NULL first so
		// the ALTER can't fail under strict sql_mode; existing valid datetime strings
		// are untouched.
		if ( $is_text ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$table} SET viewed_at = NULL WHERE viewed_at IS NOT NULL AND ( viewed_at = '' OR STR_TO_DATE(viewed_at, '%Y-%m-%d %H:%i:%s') IS NULL )" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN viewed_at DATETIME DEFAULT NULL" );
		}

		$existing = surveyx_get_table_indexes( $table );

		if ( ! isset( $existing['idx_survey_viewed'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_survey_viewed (survey_id, viewed_at)" );
		}

		// Post-condition: the column must really be DATETIME (an index on a TEXT
		// viewed_at cannot be created), and the index must really exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'viewed_at' ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $col || false === stripos( (string) $col->Type, 'datetime' ) ) {
			return false;
		}

		$existing = surveyx_get_table_indexes( $table );

		return isset( $existing['idx_survey_viewed'] );
	}
}

/**
 * Migration step [clean_orphan_summaries]: delete orphaned surveyx_summary rows — rows
 * whose survey no longer exists (delete cascade raced or missed) or whose survey_id is
 * <= 0 (garbage from an id<=0 refresh) — left behind before the pre-2.0 summary recount
 * gained its survey-existence guard. That recount is gone as of 2.0.0; the rows it left
 * are not, and the table is frozen rather than dropped, so this step stays.
 *
 * Deletes ONLY true orphans: the LEFT JOIN keeps a row only when NO matching
 * surveyx_surveys.id exists, and survey_id <= 0 can never match a real survey. Table names
 * come from $wpdb->prefix, so the query is static and there is nothing to bind.
 * Idempotent: once the orphans are gone, re-running matches nothing.
 *
 * @return bool True when the DELETE completed (0 rows deleted is success).
 */
if ( ! function_exists( 'surveyx_clean_orphan_summaries' ) ) {
	function surveyx_clean_orphan_summaries() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			"DELETE sm FROM {$wpdb->prefix}surveyx_summary sm
			LEFT JOIN {$wpdb->prefix}surveyx_surveys sv ON sm.survey_id = sv.id
			WHERE sm.survey_id <= 0 OR sv.id IS NULL"
		);

		return false !== $deleted;
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
 * Migration step [autoload_page_options]: mark the survey-page options that are read on
 * every request as autoloaded.
 *
 * `surveyx_page_base`, `surveyx_page_base_prev` and `surveyx_rewrite_version` are read by
 * SurveyX_Public_Page on every front-end, admin, REST and cron request but were originally
 * written with autoload disabled, which costs one SELECT each per request on any site
 * without a persistent object cache. A migration is required because update_option()
 * returns early on an unchanged value, BEFORE it ever looks at the autoload argument, so
 * passing `true` at the write sites only ever helps rows being given a new value.
 *
 * Idempotent. Rows that do not exist are left alone — there is nothing to autoload, and
 * creating them would pin defaults the getters are meant to resolve at read time.
 *
 * @return bool True when the autoload flag was applied to every existing row.
 */
if ( ! function_exists( 'surveyx_autoload_page_options' ) ) {
	function surveyx_autoload_page_options() {
		// Literal names, not SurveyX_Public_Page constants: this file runs before
		// that class is loaded, exactly as the activation hook does.
		$options = [ 'surveyx_page_base', 'surveyx_page_base_prev', 'surveyx_rewrite_version' ];

		// WP 6.4+ — one UPDATE for all three, and it manages the option caches.
		if ( function_exists( 'wp_set_options_autoload' ) ) {
			wp_set_options_autoload( $options, true );

			return true;
		}

		// WP 6.0-6.3 have no autoload API and update_option() ignores the flag on an unchanged
		// value, so the column is set directly ('yes'/'no' there; the 'on'/'off' vocabulary
		// arrived in 6.6). Deliberately not delete_option() + add_option(): that would drop the
		// stored base for as long as the two writes take, and lose it outright if the add failed.
		global $wpdb;

		$ok = true;

		foreach ( $options as $option ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update( $wpdb->options, [ 'autoload' => 'yes' ], [ 'option_name' => $option ] );

			// 0 means the row does not exist (or already says 'yes') — both fine.
			if ( false === $updated ) {
				$ok = false;
			}

			wp_cache_delete( $option, 'options' );
		}

		wp_cache_delete( 'alloptions', 'options' );

		return $ok;
	}
}

/**
 * Whether any of the named indexes is present in an index map.
 *
 * Small companion to surveyx_get_table_indexes(): it answers "is this column still
 * covered by something longer?" without re-reading SHOW INDEX.
 *
 * @param array<string,bool> $existing Index map from surveyx_get_table_indexes().
 * @param string[]           $names    Index names to look for.
 * @return bool True as soon as one of $names is present.
 */
if ( ! function_exists( 'surveyx_has_any_index' ) ) {
	function surveyx_has_any_index( $existing, $names ) {
		foreach ( $names as $name ) {
			if ( isset( $existing[ $name ] ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Whether a migration step may run on a request that is NOT administrative.
 *
 * An ALLOWLIST whose default is "no": a step not named here is treated as able to issue
 * DDL and only runs in an administrative context, so appending to $steps in
 * surveyx_maybe_upgrade_db() cannot expose a visitor to an ALTER and opting a step out is
 * a deliberate one-line edit here. To be listed, a step must issue NO schema statement of
 * any kind AND be bounded — it touches a small table, or it takes one
 * SURVEYX_MIGRATION_BATCH slice per request and reports 'incomplete':
 *
 * - clean_settings_reserved_keys    one pass over surveyx_surveys (tens of rows).
 * - clean_expired_session_responses batched DELETE, one bounded slice per request.
 * - clean_orphan_summaries          one DELETE over surveyx_summary (one row/survey).
 * - autoload_page_options           wp_options only, no plugin table touched.
 * - clean_dead_cron_state           wp_options and the `cron` option only, and O(1).
 *   Listed rather than gated because gating would defer no DDL and would cost something
 *   real: it finishes in the SAME first anonymous pass as the four above instead of
 *   leaving a dead hourly event dispatching into nothing until wp-admin or cron comes
 *   along.
 *
 * perf_indexes, responses_unique_constraint, verify_responses_unique_key (which delegates
 * to it), fix_viewed_at_datetime, responses_status_covering_index and views_to_surveys all
 * issue ALTER TABLE and are gated for that reason.
 *
 * clean_orphan_revisions, fix_tied_answer_sorder and stamp_survey_modes are gated WITHOUT
 * issuing any schema statement, so the reason is recorded rather than left looking like an
 * oversight. All three pass the bounded test, and the same three conditions have to hold
 * together: the work they repair is created ONLY in wp-admin, so an install that has any is
 * one whose owner opens wp-admin; nothing degrades while they wait, unlike the dead hourly
 * event above; and all three are irreversible writes over author content.
 *
 * stamp_survey_modes clears that bar with room to spare on the middle condition, which is
 * the one worth stating: SurveyX_Db::survey_needs_pro() resolves an unstamped survey by
 * running the rule, so every gate already gives the right answer without the stamp and the
 * step is purely a cost optimisation. Gating it also keeps anonymous front-end traffic from
 * writing to surveyx_surveys, the table every rendered survey reads.
 *
 * Being unlisted also means a visitor pays nothing at all for them: the runner finds
 * nothing it may attempt and returns before the retry transient, the SHOW TABLES and the
 * advisory lock.
 *
 * @param string $key Migration step key as it appears in $steps.
 * @return bool True when a public request may run this step.
 */
if ( ! function_exists( 'surveyx_migration_step_is_public_safe' ) ) {
	function surveyx_migration_step_is_public_safe( $key ) {
		$public_safe = [
			'clean_settings_reserved_keys',
			'clean_expired_session_responses',
			'clean_orphan_summaries',
			'autoload_page_options',
			'clean_dead_cron_state',
		];

		return in_array( $key, $public_safe, true );
	}
}

/**
 * Reads the per-step retry map, tolerating the shape an older build wrote.
 *
 * Entries used to be a bare timestamp and are now [ retry_at, attempts ]; the map
 * lives in a transient, so both shapes can be in flight across an update. Read
 * through surveyx_migration_retry_at() / surveyx_migration_attempts() rather than
 * indexing an entry directly.
 *
 * @return array<string,array|int> Step key => retry entry.
 */
if ( ! function_exists( 'surveyx_migration_backoff_read' ) ) {
	function surveyx_migration_backoff_read() {
		$backoff = get_transient( 'surveyx_db_upgrade_retry' );

		return is_array( $backoff ) ? $backoff : [];
	}
}

/**
 * Timestamp a retry entry is waiting for. Accepts both entry shapes.
 *
 * @param array|int $entry Retry entry.
 * @return int Unix timestamp, 0 when unreadable.
 */
if ( ! function_exists( 'surveyx_migration_retry_at' ) ) {
	function surveyx_migration_retry_at( $entry ) {
		if ( is_array( $entry ) ) {
			return isset( $entry['retry_at'] ) ? (int) $entry['retry_at'] : 0;
		}

		return (int) $entry;
	}
}

/**
 * How many times a step has already failed. A legacy scalar entry counts as one.
 *
 * @param array|int $entry Retry entry.
 * @return int Attempt count, at least 1.
 */
if ( ! function_exists( 'surveyx_migration_attempts' ) ) {
	function surveyx_migration_attempts( $entry ) {
		if ( is_array( $entry ) && isset( $entry['attempts'] ) ) {
			return max( 1, (int) $entry['attempts'] );
		}

		return 1;
	}
}

/**
 * Retry delay for the Nth consecutive failure of one step.
 *
 * A flat 15 minutes is right for a transient failure and wrong for a permanent one: an
 * ALTER on a table too large to index inside the request limit fails every time it is
 * attempted, which at a flat window is four slow admin page loads an hour for as long as
 * the install exists. The ladder keeps a first failure cheap to recover from and turns a
 * genuinely stuck step into one attempt a day — enough to pick up a fixed environment on
 * its own without becoming a tight retry loop. It never gives up entirely, and WP-CLI
 * skips the window altogether for an owner who does not want to wait.
 *
 * @param int $attempts Number of consecutive failures, 1-based.
 * @return int Seconds to wait before the next attempt.
 */
if ( ! function_exists( 'surveyx_migration_retry_delay' ) ) {
	function surveyx_migration_retry_delay( $attempts ) {
		$ladder = [ 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS ];
		$index  = min( max( 1, (int) $attempts ) - 1, count( $ladder ) - 1 );

		return $ladder[ $index ];
	}
}

/**
 * Persists the per-step retry map.
 *
 * The transient must OUTLIVE the longest window it holds, and by a wide margin. Expiring
 * it exactly at the window would defeat the ladder: the entry carrying the attempt count
 * would be gone at the very moment the step is retried, every failure would read
 * attempts=1, and the escalation would flatten back to a fixed 15 minutes forever
 * (measured before this margin existed: 504 ALTER attempts over 7 days instead of 10). So
 * the map is kept for a full day past the window it is waiting on; if nothing retries even
 * then, the site is dormant and starting the ladder over costs nothing.
 *
 * Nothing is pruned: an entry is removed when its step succeeds or reports 'incomplete',
 * so the map can never hold more entries than there are steps.
 *
 * @param array<string,array|int> $backoff Retry map.
 * @param int                     $now     Current timestamp.
 * @return void
 */
if ( ! function_exists( 'surveyx_migration_backoff_save' ) ) {
	function surveyx_migration_backoff_save( $backoff, $now ) {
		if ( empty( $backoff ) ) {
			delete_transient( 'surveyx_db_upgrade_retry' );

			return;
		}

		$longest = 0;

		foreach ( $backoff as $entry ) {
			$longest = max( $longest, surveyx_migration_retry_at( $entry ) - $now );
		}

		set_transient( 'surveyx_db_upgrade_retry', $backoff, $longest + DAY_IN_SECONDS );
	}
}

/**
 * Best-effort headroom for a schema statement, called immediately before one.
 *
 * set_time_limit() does not bind PHP-FPM's request_terminate_timeout or a proxy's read
 * timeout, so this makes a large ALTER more likely to finish, never certain to — which is
 * why it is paired with the write-ahead failure record in surveyx_maybe_upgrade_db() rather
 * than trusted on its own. ignore_user_abort() matters most on a wp-admin render: an admin
 * who navigates away mid-ALTER should not cost the site a rollback it then pays for again.
 *
 * @return void
 */
if ( ! function_exists( 'surveyx_raise_limits_for_ddl' ) ) {
	function surveyx_raise_limits_for_ddl() {
		ignore_user_abort( true );

		// Absent from function_exists() when the host disabled it, which is common on
		// shared hosting - hence the guard rather than an error-suppressed call.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		wp_raise_memory_limit( 'admin' );
	}
}

/**
 * Whether the current request is allowed to run schema DDL.
 *
 * surveyx_maybe_upgrade_db() is invoked at file scope, so pending steps also fire on
 * anonymous front-end hits. Most steps are bounded row work, but an
 * ALTER TABLE ... ADD INDEX on surveyx_responses is not: 490 ms on 365k rows, growing with
 * the table, so a visitor must never pay for it.
 *
 * The single caller consults this ONCE per request and uses the answer to decide what it
 * may call — every step that is not surveyx_migration_step_is_public_safe(), plus
 * surveyx_create_database() itself. No step calls it, deliberately: a gate a step has to
 * remember to call protects only the steps that remembered.
 *
 * Decided from the request ENTRY POINT alone, with no current-user lookup: this runs from
 * the plugin's own plugins_loaded handler, long before `init`, where current_user_can()
 * would resolve and cache $current_user while other plugins are still registering their
 * `determine_current_user` filters — a side effect that would outlive this check for the
 * rest of the request.
 *
 * @return bool True on WP-CLI, WP-Cron and wp-admin screen renders; false on front-end,
 *              REST, admin-ajax.php and admin-post.php requests.
 */
if ( ! function_exists( 'surveyx_is_administrative_request' ) ) {
	function surveyx_is_administrative_request() {
		// WP-CLI: nothing is being rendered and no visitor is waiting, and `wp cron event run` is
		// the documented way to force the work on demand.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		// WP-Cron. wp-cron.php is spawned as a NON-BLOCKING loopback request, so the page view
		// that triggered it never waits for it. This clause is also what guarantees the step still
		// completes on a site whose owner rarely opens wp-admin.
		if ( wp_doing_cron() ) {
			return true;
		}

		// REST. is_admin() is already false here; stated explicitly because the public /init,
		// /progress and /vote-results routes are precisely what this gate exists to protect.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// Anything else outside wp-admin is an ordinary front-end page view.
		if ( ! is_admin() ) {
			return false;
		}

		// admin-ajax.php sets is_admin() true but is NOT administrative: every
		// wp_ajax_nopriv_* action on it is reachable by an anonymous visitor.
		if ( wp_doing_ajax() ) {
			return false;
		}

		// admin-post.php is the same trap with no core helper to detect it — admin_post_nopriv_*
		// actions make it a public form endpoint. $pagenow comes from wp-includes/vars.php, which
		// core loads before any plugin file, so it is always available here.
		if ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) {
			return false;
		}

		// A wp-admin screen render. Plugins load before admin.php's auth_redirect(), so an
		// anonymous request can still reach this code; accepted rather than papered over with a
		// capability check, since the DDL is idempotent, lock-serialized and runs at most once.
		return true;
	}
}

/**
 * Migration step [responses_status_covering_index]: reshape the surveyx_responses indexes
 * — add one covering index, drop four that benchmarking proved dead.
 *
 * ADD idx_survey_status_question_session (survey_id, response_status, question_id,
 * session_id) for the analytics snapshot. SurveyX_Db::get_response_count_by_question() is
 * `WHERE survey_id = ? AND response_status = 'answered' GROUP BY question_id` — this
 * index's leading three columns in that order, so EXPLAIN reports `Using index`: rows come
 * out already grouped, with no temporary table, no filesort and no touch of the base
 * table. The other per-question counts in the same snapshot (get_participation_counts(),
 * the text-response tallies in SurveyX_Analytics_Db) read the same
 * (survey_id, response_status) range. Nothing recounts on a clock: the snapshot is
 * rebuilt on a cache MISS behind SurveyX_Analytics_Db::CACHE_TTL (15 minutes) and whenever
 * a write flushes it, so the index is paid back once per rebuild rather than on a timer.
 *
 * DROP session_id, survey_id, response_status, viewed_at. EXPLAIN offers each of them to
 * the optimizer on every query in either plugin, reads and writes alike, and it rejects
 * all four every time: session_id and survey_id are strict PREFIXES of composites that
 * already exist (idx_session_question_respondent / unique_response, and
 * idx_survey_respondent / idx_survey_answer_status / idx_survey_viewed), response_status
 * has 3 distinct values, and viewed_at is never a leading predicate. Dropping them made
 * writes ~13% faster (0.252 -> 0.220 ms per submitted answer) and freed 37 MB on the same
 * fixture.
 *
 * Gated to an administrative request by the RUNNER, not re-checked here — one gate, in one
 * place — and nothing is recorded for a request that may not run it.
 *
 * Idempotent and safe to interrupt, which matters because the ADD is the longest single
 * statement in this file: every ADD and DROP is guarded by a SHOW INDEX read, so a killed
 * request leaves the table in a state those guards already handle.
 *
 * Position-independent: the two redundancy-based drops in the $dead map below are gated on
 * their replacement composite existing, so running before perf_indexes defers them rather
 * than leaving a column unindexed.
 *
 * @return bool|string True when the covering index exists and none of the four do,
 *                     'incomplete' when a replacement composite is still missing so a
 *                     drop had to be deferred, false when an ALTER did not land.
 */
if ( ! function_exists( 'surveyx_responses_status_covering_index' ) ) {
	function surveyx_responses_status_covering_index() {
		global $wpdb;

		$table = $wpdb->prefix . 'surveyx_responses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return true; // No table, no indexes to reshape.
		}

		$covering = 'idx_survey_status_question_session';

		/*
		 * dead index => the longer indexes that make it redundant. The two reasons for dropping
		 * are NOT equivalent and only one has a precondition: session_id and survey_id go because
		 * a longer composite already LEADS with the same column, which is only true if that
		 * composite actually exists — on an install where perf_indexes has not run, or ran and
		 * failed, dropping the single-column key would leave the column with no usable index and
		 * send the /progress DELETE and the public /init lookups to full scans. response_status
		 * and viewed_at are dropped for low selectivity rather than redundancy, so nothing has to
		 * replace them and their lists are empty. This is what makes the step independent of its
		 * position in $steps: run before perf_indexes it defers the two gated drops and reports
		 * 'incomplete'; run after, it completes.
		 */
		$dead = [
			'session_id'      => [ 'idx_session_question_respondent', 'unique_response' ],
			'survey_id'       => [ 'idx_survey_respondent', 'idx_survey_answer_status', 'idx_survey_viewed' ],
			'response_status' => [],
			'viewed_at'       => [],
		];

		// A schema-drifted install can refuse an ALTER. Suppress DB errors so the
		// failure stays quiet and is reported by the SHOW INDEX post-condition below,
		// which is the only trustworthy signal a suppressed ALTER leaves behind.
		$suppress = $wpdb->suppress_errors( true );
		$existing = surveyx_get_table_indexes( $table );

		/*
		 * ADD first, DROP second, with the DROPs additionally gated on the SHOW INDEX re-read
		 * below. The four keys are dropped from the CREATE TABLE declaration as well, so dropping
		 * one before its replacement is confirmed present would leave the table in a shape nothing
		 * in the plugin re-creates; verifying the ADD first means a killed or failed ALTER leaves
		 * the existing, fully-working index set and costs nothing but a retry. (The declaration
		 * does carry the covering index, so a loss to something outside this plugin — a partial
		 * restore, a manual DROP — is re-added by the next dbDelta().)
		 */
		if ( ! isset( $existing[ $covering ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX `{$covering}` (survey_id, response_status, question_id, session_id)" );

			$existing = surveyx_get_table_indexes( $table );
		}

		// Never drop anything while the replacement is missing: leave the table on its
		// current index set and let the step retry on a later request.
		if ( ! isset( $existing[ $covering ] ) ) {
			$wpdb->suppress_errors( $suppress );

			return false;
		}

		$deferred = false;

		foreach ( $dead as $name => $replacements ) {
			if ( ! isset( $existing[ $name ] ) ) {
				continue;
			}

			// Redundancy-based drop: hold it back until its replacement really exists.
			if ( ! empty( $replacements ) && ! surveyx_has_any_index( $existing, $replacements ) ) {
				$deferred = true;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$name}`" );
		}

		$wpdb->suppress_errors( $suppress );

		// A replacement composite is still missing (perf_indexes has not run, or failed). Report
		// 'incomplete', not false: nothing is wrong, the work simply cannot finish yet — that
		// records nothing and arms no backoff, so the step retries once perf_indexes catches up.
		if ( $deferred ) {
			return 'incomplete';
		}

		// Post-condition: the covering index present AND all four gone. Re-read rather
		// than trust the ALTER return values — errors were suppressed above.
		$existing = surveyx_get_table_indexes( $table );

		if ( ! isset( $existing[ $covering ] ) ) {
			return false;
		}

		foreach ( array_keys( $dead ) as $name ) {
			if ( isset( $existing[ $name ] ) ) {
				return false;
			}
		}

		return true;
	}
}

/**
 * Migration step [views_to_surveys]: move the view accumulator onto surveyx_surveys.
 *
 * total_views is the only figure on the analytics screens that cannot be recomputed from
 * surveyx_sessions or surveyx_responses: nothing records a view, because a visitor who
 * opens a survey and leaves may never create a session. It lived in surveyx_summary, whose
 * every OTHER column is a recount, which forced that cache to be kept alive purely to hold
 * one accumulator.
 *
 * ADDITIVE AND REVERSIBLE: surveyx_summary keeps its own total_views column and value, so
 * an install that rolls back to a pre-2.0 build finds the accumulator where that build
 * expects it and resumes counting (losing only the views taken while 2.0 was installed).
 * That is the whole reason surveyx_summary is not dropped in 2.0.
 *
 * The POST-CONDITION is a COUNT and must NEVER become an affected-rows check.
 * $wpdb->query() on an UPDATE returns rows CHANGED, not rows matched, so a row whose
 * summary total_views already equals the destination's default 0 is matched, left alone,
 * and never appears in that number. Measured on a 77-survey install: 79 summary rows, 77
 * joinable to a surviving survey, 35 holding a non-zero total_views — the copy is complete
 * and correct and $wpdb->query() returns 33. Any affected-rows comparison therefore
 * declares a successful migration a failure, arms the escalating retry backoff, and blocks
 * the step forever.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE ON A RE-RUN: with the column present no DDL is issued,
 * and once this step's key is recorded the copy is skipped entirely — see the guard below.
 *
 * The one race, stated rather than hidden: increment_view_count() still writes to
 * surveyx_summary until THIS key is recorded, so a view landing between the UPDATE and the
 * COUNT leaves one row differing by one; the step reports failure and the retry succeeds,
 * never recording a half-done state. Those views stay on the summary row the copy has
 * already read — visible to a rolled-back install, absent from the new column.
 *
 * @return bool True when surveyx_surveys.total_views exists and no summary row still
 *              disagrees with it.
 */
if ( ! function_exists( 'surveyx_migrate_views_to_surveys' ) ) {
	function surveyx_migrate_views_to_surveys() {
		global $wpdb;

		$surveys = $wpdb->prefix . 'surveyx_surveys';
		$summary = $wpdb->prefix . 'surveyx_summary';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $surveys ) ) ) {
			return false; // No table to add the column to; retry on a later request.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$surveys} LIKE %s", 'total_views' ) );

		if ( ! $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$surveys} ADD total_views INT(11) NOT NULL DEFAULT 0" );

			// Re-read rather than trust the ALTER's return value: the column existing
			// is the only claim worth making, and a fresh install already has it from
			// the CREATE TABLE declaration.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$surveys} LIKE %s", 'total_views' ) );
		}

		if ( ! $column ) {
			return false;
		}

		/*
		 * Past this point the column exists, which is the part of this step that is always safe to
		 * redo. The COPY is not: once this step's key is recorded, increment_view_count() writes
		 * to the column above and the summary row is frozen, so copying again rolls every view
		 * taken since back off the counter (measured: 22 views reverting to 21 after one re-run).
		 * The runner never calls a recorded step, so this is unreachable through it — but it IS
		 * reachable by hand, by clearing the key or restoring an older surveyx_migrations_done,
		 * and the counter it would silently rewind is not recoverable.
		 */
		if ( surveyx_views_on_surveys_table() ) {
			return true;
		}

		// An install with no summary table has no accumulator to carry over, and the
		// column is already in place — that is the whole job done.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $summary ) ) ) {
			return true;
		}

		// Copy the accumulator across. INNER JOIN on purpose: a survey with no summary row never
		// had a view counted, so its column stays at the default 0 rather than being written with
		// one. Table names come from $wpdb->prefix and there is nothing to bind.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE {$surveys} sv
			INNER JOIN {$summary} sm ON sm.survey_id = sv.id
			SET sv.total_views = sm.total_views
			WHERE sv.total_views <> sm.total_views"
		);

		// Post-condition: nothing still disagrees. See the docblock for why this is a
		// COUNT of the rows that are still different and never a count of rows written.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$still_differing = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$surveys} sv
			INNER JOIN {$summary} sm ON sm.survey_id = sv.id
			WHERE sv.total_views <> sm.total_views"
		);

		// A failed COUNT returns null, and (int) null is 0 — which would read as a
		// clean migration. Require a real result before believing it.
		return null !== $still_differing && 0 === (int) $still_differing;
	}
}

/**
 * Whether surveyx_surveys.total_views is the authority for a survey's view count.
 *
 * Answered from the RECORDED MIGRATION KEY, never from the column's presence:
 *
 * 1. The DDL gate in surveyx_maybe_upgrade_db() refuses REST — and /init, the route that
 *    counts a view, IS REST — while WordPress fires no activation hook on a plugin update.
 *    So the first request after an auto-update runs the new PHP against the OLD table, and
 *    any code assuming "new files therefore new column" would issue
 *    `Unknown column 'total_views'` on every single survey view, permanently on an install
 *    with DISABLE_WP_CRON, no system cron and an owner who never opens wp-admin.
 * 2. A SHOW COLUMNS probe would answer correctly but costs a query per request on the hot
 *    path, where `surveyx_migrations_done` is autoloaded and already read by the runner.
 *
 * @return bool True once the [views_to_surveys] step has completed on this install.
 */
if ( ! function_exists( 'surveyx_views_on_surveys_table' ) ) {
	function surveyx_views_on_surveys_table() {
		return in_array( 'views_to_surveys', (array) get_option( 'surveyx_migrations_done', [] ), true );
	}
}

/**
 * Migration step [clean_dead_cron_state]: remove scheduled work and stored state that no
 * longer has any code behind it. Neither leftover is removed by shipping new files,
 * because neither lives in a file:
 *
 * 1. `surveyx_sync_emails_hourly` — a RECURRING event in the `cron` option that nothing in
 *    either plugin listens for, so every dispatch boots WordPress, fires the hook into
 *    nothing, and reschedules itself an hour later. wp_reschedule_event() is driven by what
 *    the option says, not by what any file registers, so the entry outlives every update
 *    until something unschedules it explicitly. `surveyx_refresh_all_summaries` gets no
 *    such treatment on purpose: it is queued with wp_schedule_single_event(), and WP
 *    unschedules a single event BEFORE dispatching it, so a queued one clears itself.
 * 2. `surveyx_summary_refresh` and `surveyx_summary_pass_cursor` — two non-autoloaded
 *    wp_options rows holding the state of the Pro Refresh button's background pass, present
 *    on any install that ever pressed it. Run in both editions on purpose: an install that
 *    downgraded from Pro to Free still carries them.
 *
 * SCHEMA-SHAPE INDEPENDENT. It reads no plugin table, column or index, so it behaves
 * identically on a 1.7.0-shaped database, a 2.0-shaped one, and one where the surveyx_*
 * tables are missing altogether. It depends on no other step and none depends on it, so
 * its position in $steps carries no meaning.
 *
 * Idempotent, and a clean no-op where there is nothing to clean: wp_unschedule_hook() on an
 * unscheduled hook and delete_option() on an absent row each do nothing, and the
 * post-condition is a state such an install is already in. Public-safe deliberately — the
 * reasoning is in surveyx_migration_step_is_public_safe().
 *
 * @return bool True when the hook holds no scheduled event and neither option row exists
 *              any more.
 */
if ( ! function_exists( 'surveyx_clean_dead_cron_state' ) ) {
	function surveyx_clean_dead_cron_state() {
		global $wpdb;

		// wp_unschedule_hook(), not wp_clear_scheduled_hook(): the latter clears only the events
		// whose argument tuple hashes to the one it was passed, and this hook has no listener to
		// have arguments for — which is exactly why nothing here can know what it was scheduled with.
		wp_unschedule_hook( 'surveyx_sync_emails_hourly' );

		delete_option( 'surveyx_summary_refresh' );
		delete_option( 'surveyx_summary_pass_cursor' );

		/*
		 * Post-condition, read back rather than inferred from the three calls above. None of their
		 * return values distinguishes "removed" from "was never there" from "refused":
		 * wp_unschedule_hook() can be short-circuited by the pre_unschedule_hook filter, and
		 * delete_option() returns false for a row that was already absent — a success here. The
		 * cron array is scanned directly instead of through wp_next_scheduled() for the same
		 * argument-signature reason, and costs no query: `cron` is autoloaded and _set_cron_array()
		 * has just refreshed the cache.
		 */
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( is_array( $hooks ) && isset( $hooks['surveyx_sync_emails_hourly'] ) ) {
				return false;
			}
		}

		// One prepared read of wp_options rather than two get_option() calls: it states
		// the post-condition exactly and cannot be answered by a stale option cache.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
				'surveyx_summary_refresh',
				'surveyx_summary_pass_cursor'
			)
		);

		// A failed COUNT returns null, and (int) null is 0 — which would read as a clean
		// step. Require a real result before believing it.
		return null !== $remaining && 0 === (int) $remaining;
	}
}

/**
 * Migration step [clean_orphan_revisions]: delete revision rows whose survey no longer
 * exists.
 *
 * Deleting a survey did not delete its revisions before 2.0.0. That is a privacy problem
 * rather than housekeeping: a revision row is a full survey snapshot, so the question and
 * answer text of a survey its owner deleted stays readable in the database. The delete path
 * now removes them; these rows are what it left behind (reference install: 56 rows, 523 KB
 * belonging to 20+ deleted surveys).
 *
 * Deletes ONLY true orphans. `survey_id NOT IN (SELECT id FROM surveyx_surveys)` keeps a
 * row only when no survey carries that id, and `survey_id <= 0` can never match one;
 * surveyx_surveys.id is an AUTO_INCREMENT primary key, so the subquery cannot return NULL
 * and cannot make NOT IN answer UNKNOWN for a live row.
 *
 * Batched like [clean_expired_session_responses]: one SURVEYX_MIGRATION_BATCH slice per
 * request, reporting 'incomplete' while rows remain. The single-table `IN (…)` form is what
 * makes that possible — MySQL accepts no LIMIT on the multi-table `DELETE r FROM … JOIN …`
 * that [clean_orphan_summaries] uses, and that step needs none because surveyx_summary
 * holds at most one row per survey.
 *
 * @return bool|string True when no orphaned revisions are left, 'incomplete' when this
 *                     request's slice is used up and rows remain, false when the DELETE
 *                     failed.
 */
if ( ! function_exists( 'surveyx_clean_orphan_revisions' ) ) {
	function surveyx_clean_orphan_revisions() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}surveyx_revisions
				WHERE survey_id <= 0
					OR survey_id NOT IN ( SELECT id FROM {$wpdb->prefix}surveyx_surveys )
				LIMIT %d",
				SURVEYX_MIGRATION_BATCH
			)
		);
		// phpcs:enable

		// A timed-out or aborted DELETE returns false; only then is the step failed.
		if ( false === $deleted ) {
			return false;
		}

		// A short slice means LIMIT was never reached, so no rows are left. A full one means more
		// remain, so the step reports itself unfinished and resumes next request.
		return $deleted < SURVEYX_MIGRATION_BATCH ? true : 'incomplete';
	}
}

/**
 * Migration step [fix_tied_answer_sorder]: give the answers of a question a defined order
 * when every one of them carries the same sorder.
 *
 * createDefaultAnswerData() never set sorder and the PHP behind it fell back to `?? 1`, so
 * BOTH answers of every Yes/No question were written at 1, and the same helper served
 * cloned answers. `ORDER BY sorder` does not break that tie: two identical queries may
 * return Yes/No in different orders. The writer is fixed; these rows are not (reference
 * install: 20 questions, 41 answer rows).
 *
 * Repairs ONLY the tie. `MIN(sorder) = MAX(sorder)` is true only when every answer of the
 * question shares one value, so a question with any genuine ordering is never selected —
 * including a partial one such as (1, 1, 2), where a rewrite would be a regression rather
 * than a repair — and `COUNT(*) > 1` drops single-answer questions. Selected questions are
 * renumbered 1..N by id ASC, which is creation order and so the order the author entered
 * the answers in.
 *
 * Deliberately NOT repaired here: the separate clone bug that multiplied sorder by ten
 * through string concatenation (31, 41, 51 …). Those questions have a tie-free relative
 * order that already renders correctly, so the defect is cosmetic until the INT column
 * overflows, and rewriting the numbers would risk more than it fixes.
 *
 * Batched: at most one SURVEYX_MIGRATION_BATCH worth of answer rows per request, reporting
 * 'incomplete' while questions remain. Whole questions only — see the budget loop below.
 *
 * @return bool|string True when no tied question is left, 'incomplete' when this
 *                     request's slice is used up and questions remain, false when a
 *                     query failed or the renumber did not take.
 */
if ( ! function_exists( 'surveyx_fix_tied_answer_sorder' ) ) {
	function surveyx_fix_tied_answer_sorder() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tied = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT question_id, COUNT(*) AS answer_count
				FROM {$wpdb->prefix}surveyx_answers
				GROUP BY question_id
				HAVING COUNT(*) > 1 AND MIN(sorder) = MAX(sorder)
				ORDER BY question_id ASC
				LIMIT %d",
				SURVEYX_MIGRATION_BATCH
			)
		);

		// get_results() returns an empty array for a failed query as well as for one
		// that matched nothing, and those are opposite answers here. wpdb::query() clears
		// last_error before every statement, so reading it now describes this query only.
		if ( '' !== $wpdb->last_error ) {
			return false;
		}

		if ( empty( $tied ) ) {
			return true;
		}

		// Whole questions only, never a partial one: a question whose answers were split across
		// two requests would be renumbered 1..k in one and 1..m in the next, re-creating the tie
		// this step exists to remove. So the last question is allowed to overshoot the budget.
		$question_ids = [];
		$budget       = 0;

		foreach ( $tied as $row ) {
			$question_ids[] = (int) $row->question_id;
			$budget        += (int) $row->answer_count;

			if ( $budget >= SURVEYX_MIGRATION_BATCH ) {
				break;
			}
		}

		$question_placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );

		$answers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, question_id
				FROM {$wpdb->prefix}surveyx_answers
				WHERE question_id IN ({$question_placeholders})
				ORDER BY question_id ASC, id ASC",
				...$question_ids
			)
		);

		// Between the two reads an admin save can have deleted every answer of every
		// question in this slice. Nothing to renumber and nothing to report done: the
		// next request re-detects what is actually there.
		if ( empty( $answers ) ) {
			return 'incomplete';
		}

		// One UPDATE for the whole slice. Answer ids are unique across questions, so a single CASE
		// can carry each row's new rank, and the ranking below relies on the ORDER BY above: rows
		// arrive grouped by question and, within a question, in id order.
		$cases = '';
		$ranks = [];
		$ids   = [];
		$rank  = 0;
		$group = null;

		foreach ( $answers as $answer ) {
			if ( (int) $answer->question_id !== $group ) {
				$group = (int) $answer->question_id;
				$rank  = 0;
			}

			++$rank;

			$cases  .= ' WHEN %d THEN %d';
			$ranks[] = (int) $answer->id;
			$ranks[] = $rank;
			$ids[]   = (int) $answer->id;
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}surveyx_answers
				SET sorder = CASE id{$cases} END
				WHERE id IN ({$id_placeholders})",
				...array_merge( $ranks, $ids )
			)
		);

		if ( false === $updated ) {
			return false;
		}

		// Post-condition, read back over the questions THIS pass rewrote rather than inferred from
		// the affected-row count (MySQL reports 0 affected for a row written with the value it
		// already held). Failing here rather than reporting 'incomplete' is deliberate: the latter
		// records nothing and arms no backoff, so a renumber that silently did not take would
		// re-run this scan on every qualifying request forever instead of surfacing once.
		$still_tied = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (
					SELECT question_id
					FROM {$wpdb->prefix}surveyx_answers
					WHERE question_id IN ({$question_placeholders})
					GROUP BY question_id
					HAVING COUNT(*) > 1 AND MIN(sorder) = MAX(sorder)
				) repaired",
				...$question_ids
			)
		);
		// phpcs:enable

		// A failed COUNT returns null, and (int) null is 0 — which would read as a clean
		// slice. Require a real result before believing it.
		if ( null === $still_tied || 0 !== (int) $still_tied ) {
			return false;
		}

		// More tied questions exist when the budget stopped short of the rows read, or when that
		// read itself hit its LIMIT and so cannot have seen the whole table. Both resume next
		// request; a slice that leaves nothing behind reports the step finished.
		if ( count( $question_ids ) < count( $tied ) || count( $tied ) >= SURVEYX_MIGRATION_BATCH ) {
			return 'incomplete';
		}

		return true;
	}
}

/**
 * Migration step [stamp_survey_modes]: classify the surveys that never were.
 *
 * `surveyx_surveys.s_mode` is a NOT NULL varchar with no default, so every INSERT that
 * omitted the column stored '' — a survey that has never been CLASSIFIED, not one
 * classified as free. On a real 259-survey install 73 rows carried '' and 57 of those were
 * multi-question, i.e. Pro by the rule; the gates reading the column each guessed
 * differently about them, so one survey could be editable at one door and refused at the
 * next.
 *
 * The gates now resolve such a row through SurveyX_Db::survey_needs_pro(), so nothing is
 * broken while this step waits. It exists so the answer is STORED once instead of
 * recomputed on every request.
 *
 * It applies SurveyX_Db's rule rather than a second copy of it: more than one question is
 * MODE_PRO, and a lone question whose type is outside BASIC_RENDERABLE_QUESTION_TYPES is
 * MODE_PRO too. The count half is settled for the whole slice by ONE grouped read, so
 * question blobs are loaded only for the surveys that could still go either way.
 *
 * Batched like [clean_expired_session_responses]: one SURVEYX_MIGRATION_BATCH slice of
 * surveys per request, reporting 'incomplete' while rows remain. Both writes are single
 * UPDATEs over a primary-key IN list, so a slice costs a fixed handful of queries however
 * wide it is. Only rows that are neither 'pro' nor 'basic' are touched: an existing stamp
 * is authoritative and is never recomputed.
 *
 * @return bool|string True when no unclassified survey is left, 'incomplete' when this
 *                     request's slice is used up and rows remain, false when an UPDATE
 *                     failed.
 */
if ( ! function_exists( 'surveyx_stamp_survey_modes' ) ) {
	function surveyx_stamp_survey_modes() {
		global $wpdb;

		/*
		 * surveyx_maybe_upgrade_db() runs at this file's scope, and the main plugin file
		 * requires this file BEFORE includes/database.php — so the class holding the rule
		 * is not loaded yet when the step runs. Loading it here is what keeps this step
		 * from growing its own copy of the rule; the sibling path is the same one the
		 * plugin file uses, so require_once dedupes with it.
		 */
		if ( ! class_exists( 'SurveyX_Db', false ) ) {
			require_once __DIR__ . '/database.php';
		}

		$surveys   = $wpdb->prefix . 'surveyx_surveys';
		$questions = $wpdb->prefix . 'surveyx_questions';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$surveys}
				WHERE s_mode NOT IN ( %s, %s )
				ORDER BY id ASC
				LIMIT %d",
				SurveyX_Db::MODE_PRO,
				SurveyX_Db::MODE_BASIC,
				SURVEYX_MIGRATION_BATCH
			)
		);

		$ids = array_map( 'absint', (array) $ids );

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// Count half of the rule for the whole slice. A survey missing from the result
			// has no questions at all, which counts as one-or-fewer and stays undecided.
			$counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT survey_id, COUNT(*) AS total FROM {$questions}
					WHERE survey_id IN ({$placeholders})
					GROUP BY survey_id",
					...$ids
				)
			);

			$totals = [];
			foreach ( (array) $counts as $count_row ) {
				$totals[ (int) $count_row->survey_id ] = (int) $count_row->total;
			}

			$pro       = [];
			$undecided = [];

			foreach ( $ids as $id ) {
				if ( isset( $totals[ $id ] ) && $totals[ $id ] > 1 ) {
					$pro[] = $id;
					continue;
				}

				$undecided[] = $id;
			}

			// Type half, for the at-most-one-question surveys only. Their blobs are the only
			// ones this step ever reads.
			$basic = [];

			if ( ! empty( $undecided ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $undecided ), '%d' ) );

				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT survey_id, content FROM {$questions}
						WHERE survey_id IN ({$placeholders})",
						...$undecided
					)
				);

				$by_survey = [];
				foreach ( (array) $rows as $question_row ) {
					$by_survey[ (int) $question_row->survey_id ][] = $question_row;
				}

				foreach ( $undecided as $id ) {
					if ( SurveyX_Db::MODE_PRO === SurveyX_Db::classify_questions( $by_survey[ $id ] ?? [] ) ) {
						$pro[] = $id;
						continue;
					}

					$basic[] = $id;
				}
			}

			// One UPDATE per mode. Nothing else about the row is written.
			foreach ( [ SurveyX_Db::MODE_PRO => $pro, SurveyX_Db::MODE_BASIC => $basic ] as $mode => $batch ) {
				if ( empty( $batch ) ) {
					continue;
				}

				$placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );

				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$surveys} SET s_mode = %s WHERE id IN ({$placeholders})",
						$mode,
						...$batch
					)
				);

				// A timed-out or aborted UPDATE returns false; only then is the step failed.
				if ( false === $updated ) {
					return false;
				}
			}
		}

		// Post-condition: this step's own work, and nothing else — no survey is left
		// carrying a value that is neither mode. A short slice means there were none
		// remaining anyway, so the count confirms rather than assumes it.
		$remaining = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$surveys} WHERE s_mode NOT IN ( %s, %s )",
				SurveyX_Db::MODE_PRO,
				SurveyX_Db::MODE_BASIC
			)
		);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( is_null( $remaining ) ) {
			return false;
		}

		return 0 === (int) $remaining ? true : 'incomplete';
	}
}

/**
 * Admin notice mirroring WP core's post-DB-update message, built from the step keys
 * surveyx_maybe_upgrade_db() left in the `surveyx_db_upgrade_notice` transient. Shown once
 * (the transient is then deleted), admin-only.
 *
 * EXACTLY ONE notice, chosen from the CURRENT state of the migration rather than from the
 * two transients side by side. Each is a snapshot of one pass and they can easily disagree
 * with each other and with the database — a pass that completes ten steps and fails one
 * writes both, and a step that failed earlier and has since succeeded is still named in a
 * record written before it succeeded. Rendering both told an install "database updated" and
 * "the database update could not be completed" on the same screen.
 *
 * So a recorded failure is believed only while its key is still MISSING from
 * `surveyx_migrations_done`, and an outstanding failure outranks the success: the red
 * notice already says the work is being retried, and the green one shows on its own the
 * moment the last step lands.
 */
if ( ! function_exists( 'surveyx_db_upgrade_admin_notice' ) ) {
	function surveyx_db_upgrade_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// get_transient() returns false when absent, and (array) false is [ false ] —
		// which is not empty and would render an unnamed "success" notice.
		$ran    = get_transient( 'surveyx_db_upgrade_notice' );
		$failed = get_transient( 'surveyx_db_upgrade_failed' );
		$ran    = is_array( $ran ) ? $ran : [];
		$failed = is_array( $failed ) ? $failed : [];

		if ( empty( $ran ) && empty( $failed ) ) {
			return;
		}

		// Show once.
		delete_transient( 'surveyx_db_upgrade_notice' );
		delete_transient( 'surveyx_db_upgrade_failed' );

		// A step is recorded ONLY once it has verified its own post-condition, so a recorded key
		// is proof the failure it is named in is history. Filtered here as well as in the runner
		// because the record can predate that fix, or come from a pass in a different request.
		$done   = (array) get_option( 'surveyx_migrations_done', [] );
		$failed = array_values( array_diff( $failed, $done ) );

		// Deliberately no step names in either notice: they are internal keys that mean nothing to
		// a site owner, who cannot act on them either way. They go to the error log instead, where
		// support can find them without putting jargon on an admin screen.
		//
		// Never claim success while a step is still outstanding: it was NOT recorded as done and
		// is retried on a later request.
		if ( ! empty( $failed ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'SurveyX: database update step(s) pending after failure: ' . implode( ', ', $failed ) );

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'SurveyX Builder: the database update could not be completed. It will be retried automatically. If it keeps failing, running any WP-CLI command retries it immediately with no time limit.', 'surveyx-builder' )
			);

			return;
		}

		if ( ! empty( $ran ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'SurveyX Builder: database updated.', 'surveyx-builder' )
			);
		}
	}
}
add_action( 'admin_notices', 'surveyx_db_upgrade_admin_notice' );

surveyx_maybe_upgrade_db();
