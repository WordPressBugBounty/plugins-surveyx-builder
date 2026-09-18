<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

/**
 * SurveyX Revisions Manager — autosave with a snapshot strategy.
 *
 * Types:
 * - autosave: overwrites the previous one (keeps 1)
 * - snapshot: auto-created every 5 min
 * - manual:   saved on request by a caller that asks for it
 *
 * snapshot and manual share ONE retention pool of MAX_SNAPSHOTS rows: cleanup_snapshots()
 * prunes `revision_type IN ('snapshot','manual')` together, oldest first. A manual row is
 * therefore NOT kept forever — enough newer snapshots push it out exactly as they push out
 * an older snapshot.
 *
 * Nothing in either plugin creates a manual revision today: the REST endpoint accepts the
 * type, but the admin app only ever sends 'autosave'.
 */
class SurveyX_Revisions {

	/** Max snapshots to keep per survey */
	const MAX_SNAPSHOTS = 5;

	/** Minimum interval between snapshots (5 minutes) */
	const SNAPSHOT_INTERVAL = 300;

	private static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'surveyx_revisions';
	}

	/**
	 * Smart save revision
	 *
	 * @param int    $survey_id Survey ID
	 * @param array  $data      Full survey data { survey, questions, answers }
	 * @param string $type      'autosave', 'snapshot', or 'manual'
	 * @return int|null Revision ID on success, null if skipped
	 */
	public static function save_revision( $survey_id, $data, $type = 'autosave' ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'surveyx_revisions';
		$now     = surveyx_get_utc_now();
		$user_id = get_current_user_id();

		// AUTOSAVE: Upsert - always overwrite existing
		if ( 'autosave' === $type ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}surveyx_revisions WHERE survey_id = %d AND revision_type = 'autosave'",
					$survey_id
				)
			);

			if ( $existing_id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
				$wpdb->update(
					$table,
					[
						'data'       => wp_json_encode( $data ),
						'user_id'    => $user_id,
						'created_at' => $now,
					],
					[ 'id' => $existing_id ],
					[ '%s', '%d', '%s' ],
					[ '%d' ]
				);
				$revision_id = (int) $existing_id;
			} else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
				$wpdb->insert(
					$table,
					[
						'survey_id'     => $survey_id,
						'revision_type' => 'autosave',
						'data'          => wp_json_encode( $data ),
						'user_id'       => $user_id,
						'created_at'    => $now,
					],
					[ '%d', '%s', '%s', '%d', '%s' ]
				);
				$revision_id = $wpdb->insert_id;
			}

			self::maybe_create_snapshot( $survey_id, $data, $user_id, $now );

			return $revision_id;
		}

		// MANUAL: Always insert new revision
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		$wpdb->insert(
			$table,
			[
				'survey_id'     => $survey_id,
				'revision_type' => $type,
				'data'          => wp_json_encode( $data ),
				'user_id'       => $user_id,
				'created_at'    => $now,
			],
			[ '%d', '%s', '%s', '%d', '%s' ]
		);

		return $wpdb->insert_id;
	}

	/**
	 * Create snapshot if enough time has passed since last snapshot
	 */
	private static function maybe_create_snapshot( $survey_id, $data, $user_id, $now ) {
		global $wpdb;
		$table = $wpdb->prefix . 'surveyx_revisions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		$last_snapshot_time = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$wpdb->prefix}surveyx_revisions
                WHERE survey_id = %d AND revision_type = 'snapshot'
                ORDER BY created_at DESC
                LIMIT 1",
				$survey_id
			)
		);

		if ( $last_snapshot_time ) {
			// Parse the stored value as UTC explicitly (it is always UTC), rather than
			// relying on WP having set PHP's default timezone to UTC.
			$diff = time() - strtotime( $last_snapshot_time . ' UTC' );
			if ( $diff < self::SNAPSHOT_INTERVAL ) {
				return;
			}
		}

		// Prune to one BELOW the cap, because the insert immediately below adds the row
		// that fills it. Pruning to the cap itself and then inserting settled the table
		// at MAX_SNAPSHOTS + 1 — measured at 6 rows for a constant that says 5.
		self::cleanup_snapshots( $survey_id, self::MAX_SNAPSHOTS - 1 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		$wpdb->insert(
			$table,
			[
				'survey_id'     => $survey_id,
				'revision_type' => 'snapshot',
				'data'          => wp_json_encode( $data ),
				'user_id'       => $user_id,
				'created_at'    => $now,
			],
			[ '%d', '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * Get latest autosave for a survey
	 */
	public static function get_latest_autosave( $survey_id ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		$revision = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, survey_id, revision_type, data, user_id, created_at
                FROM {$wpdb->prefix}surveyx_revisions
                WHERE survey_id = %d AND revision_type = 'autosave'
                LIMIT 1",
				$survey_id
			)
		);

		if ( $revision ) {
			$revision->data = json_decode( $revision->data, true );
		}

		return $revision;
	}

	/**
	 * Get revision history (snapshots + manual saves)
	 */
	public static function get_revisions( $survey_id, $limit = 10 ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, survey_id, revision_type, user_id, created_at
                FROM {$wpdb->prefix}surveyx_revisions
                WHERE survey_id = %d
                ORDER BY created_at DESC
                LIMIT %d",
				$survey_id,
				$limit
			)
		);
	}

	/**
	 * Get a specific revision by ID
	 */
	public static function get_revision( $revision_id ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		$revision = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, survey_id, revision_type, data, user_id, created_at
                FROM {$wpdb->prefix}surveyx_revisions
                WHERE id = %d",
				$revision_id
			)
		);

		if ( $revision ) {
			$revision->data = json_decode( $revision->data, true );
		}

		return $revision;
	}

	/**
	 * Check autosave status for recovery dialog
	 */
	public static function get_autosave_status( $survey_id, $last_updated_at = null ) {
		$latest = self::get_latest_autosave( $survey_id );

		if ( ! $latest ) {
			return [
				'has_autosave'        => false,
				'autosave_date'       => null,
				'revision_id'         => null,
				'is_newer_than_saved' => false,
			];
		}

		$is_newer = false;
		if ( $last_updated_at ) {
			$is_newer = strtotime( $latest->created_at . ' UTC' ) > strtotime( $last_updated_at . ' UTC' );
		} else {
			$is_newer = true;
		}

		return [
			'has_autosave'        => true,
			'autosave_date'       => $latest->created_at,
			'revision_id'         => $latest->id,
			'is_newer_than_saved' => $is_newer,
		];
	}

	/**
	 * Delete a survey's oldest snapshot/manual revisions, keeping the newest $keep.
	 *
	 * $keep defaults to MAX_SNAPSHOTS, which is what a caller that only prunes wants.
	 * maybe_create_snapshot() passes MAX_SNAPSHOTS - 1 instead, because it inserts a row
	 * straight afterwards and that row is the one that fills the cap.
	 *
	 * @param int      $survey_id Survey ID.
	 * @param int|null $keep      How many rows to keep. Null means MAX_SNAPSHOTS.
	 * @return int Rows deleted.
	 */
	public static function cleanup_snapshots( $survey_id, $keep = null ) {
		global $wpdb;

		$keep = null === $keep ? self::MAX_SNAPSHOTS : (int) $keep;

		// Keeping nothing. The "newest $keep" read below would return no ids, and the
		// empty-result guard after it returns without deleting — so a cap of 1 (which
		// reaches here as $keep = 0) would let the table grow instead of pruning it.
		// Delete every snapshot/manual row of this survey instead.
		if ( $keep < 1 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
			return (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}surveyx_revisions
                    WHERE survey_id = %d AND revision_type IN ('snapshot', 'manual')",
					$survey_id
				)
			);
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		$keep_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}surveyx_revisions
                WHERE survey_id = %d AND revision_type IN ('snapshot', 'manual')
                ORDER BY created_at DESC
                LIMIT %d",
				$survey_id,
				$keep
			)
		);

		if ( empty( $keep_ids ) ) {
			return 0;
		}

		// $placeholders contains only %d format specifiers, safely generated
		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );

		$params = array_merge( [ $survey_id ], $keep_ids );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}surveyx_revisions
                WHERE survey_id = %d
                AND revision_type IN ('snapshot', 'manual')
                AND id NOT IN ({$placeholders})",
				...$params
			)
		) ?: 0;
	}

	/**
	 * Delete autosave for a survey (called after manual save)
	 */
	public static function delete_autosave( $survey_id ) {
		global $wpdb;
		$survey_id = absint( $survey_id );
		if ( ! $survey_id ) {
			return false;
		}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		return $wpdb->delete(
			self::get_table_name(),
			[
				'survey_id'     => $survey_id,
				'revision_type' => 'autosave',
			],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Update temp IDs to real IDs in all revisions for a survey
	 * Uses string replacement for efficiency - replaces all occurrences in JSON
	 *
	 * @param int   $survey_id  Survey ID
	 * @param array $id_mapping ['questions' => [temp_id => real_id], 'answers' => [temp_id => real_id]]
	 * @return int Number of revisions updated
	 */
	public static function update_ids_in_revisions( $survey_id, $id_mapping ) {
		global $wpdb;
		$table = $wpdb->prefix . 'surveyx_revisions';

		if ( empty( $id_mapping['questions'] ) && empty( $id_mapping['answers'] ) ) {
			return 0;
		}

		$search  = [];
		$replace = [];

		if ( ! empty( $id_mapping['questions'] ) ) {
			foreach ( $id_mapping['questions'] as $temp_id => $real_id ) {
				// Match both "id":"temp-xxx" and "question_id":"temp-xxx"
				$search[]  = '"' . $temp_id . '"';
				$replace[] = '"' . $real_id . '"';
			}
		}

		if ( ! empty( $id_mapping['answers'] ) ) {
			foreach ( $id_mapping['answers'] as $temp_id => $real_id ) {
				$search[]  = '"' . $temp_id . '"';
				$replace[] = '"' . $real_id . '"';
			}
		}

		if ( empty( $search ) ) {
			return 0;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		$revisions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, data FROM {$wpdb->prefix}surveyx_revisions WHERE survey_id = %d",
				$survey_id
			)
		);

		$updated_count = 0;

		foreach ( $revisions as $rev ) {
			$original_data = $rev->data;
			$updated_data  = str_replace( $search, $replace, $original_data );

			if ( $updated_data !== $original_data ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
				$wpdb->update(
					$table,
					[ 'data' => $updated_data ],
					[ 'id' => $rev->id ],
					[ '%s' ],
					[ '%d' ]
				);
				++$updated_count;
			}
		}

		return $updated_count;
	}
}
