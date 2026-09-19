<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Admin_Db', false ) ) {
	class SurveyX_Admin_Db extends SurveyX_Db {

		/**
		 * Retrieves surveys with server-side pagination, search, and sorting.
		 *
		 * @param int    $page       Current page number (1-based).
		 * @param int    $per_page   Number of items per page.
		 * @param string $search     Search term for title or ID.
		 * @param string $sort_by    Column to sort by.
		 * @param string $sort_order Sort order ('asc' or 'desc').
		 *
		 * @return array Array with 'items', 'total', 'page', 'per_page'.
		 */
		public static function get_surveys_paginated( $page = 1, $per_page = 10, $search = '', $sort_by = 'created_at', $sort_order = 'desc' ) {
			global $wpdb;

			$table = $wpdb->prefix . 'surveyx_surveys';

			$allowed_sort_columns = [ 'id', 'title', 'survey_type', 'status', 'created_at', 'updated_at' ];
			if ( ! in_array( $sort_by, $allowed_sort_columns, true ) ) {
				$sort_by = 'created_at';
			}

			$sort_order = 'ASC' === strtoupper( $sort_order ) ? 'ASC' : 'DESC';

			$where      = '';
			$where_args = [];
			if ( ! empty( $search ) ) {
				$like       = '%' . $wpdb->esc_like( $search ) . '%';
				$where      = 'WHERE (title LIKE %s OR id = %d)';
				$where_args = [ $like, intval( $search ) ];
			}

			$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
			if ( ! empty( $where_args ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- $count_sql is safely built with whitelisted column names
				$count_sql = $wpdb->prepare( $count_sql, ...$where_args );
			}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Table/columns are hardcoded, safe
			$total = (int) $wpdb->get_var( $count_sql );

			$offset = ( $page - 1 ) * $per_page;

			$sql = "SELECT id, title, survey_type, status, cover, s_mode, created_at, updated_at
			        FROM {$table} {$where}
			        ORDER BY {$sort_by} {$sort_order}
			        LIMIT %d OFFSET %d";

			$query_args = array_merge( $where_args, [ $per_page, $offset ] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Table/columns are hardcoded, safe
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$query_args ), ARRAY_A );

			if ( empty( $rows ) ) {
				$rows = [];
			}

			/*
			 * The dashboard greys a row and blocks the click on `s_mode === 'pro'`, and the
			 * editor endpoints refuse on SurveyX_Db::survey_needs_pro(). An unstamped row
			 * ('' in this NOT NULL varchar) would make those two disagree: the row looks
			 * editable, opens, and comes back 403. Resolving the stamp here means the list
			 * carries the same answer the server will give, so no new field is needed and
			 * the client keeps its existing comparison.
			 *
			 * Costs one extra read per UNSTAMPED row on the page only — the migration step
			 * [stamp_survey_modes] leaves none.
			 */
			foreach ( $rows as &$row ) {
				$row['s_mode'] = SurveyX_Db::resolve_survey_mode( $row['id'], (string) $row['s_mode'] );
			}
			unset( $row );

			return [
				'items'    => $rows,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			];
		}

		/**
		 * Retrieves a survey by its ID.
		 *
		 * @param int $survey_id The ID of the survey to retrieve.
		 *
		 * @return array|null Survey row, or null if no survey has that ID.
		 */
		public static function get_survey_by_id( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, title, author_id, survey_type, cover, status, settings, content, s_mode, created_at, updated_at
					FROM {$wpdb->prefix}surveyx_surveys
					WHERE id = %d",
					$survey_id
				),
				ARRAY_A
			);
		}

		/**
		 * Creates a new survey entry in the database.
		 *
		 * @param string $title       The title of the survey.
		 * @param string $survey_type Optional. The type of survey to create. Default 'vote'.
		 *
		 * @return int|false The ID of the newly created survey on success, or false on failure.
		 */
		public static function create_survey( $title, $survey_type = 'vote' ) {
			global $wpdb;

			$default_settings = [
				'skip_submit_button'       => true,
				'show_header_branding'     => false,
				'show_footer_branding'     => true,
				'show_start_again'         => true,
				'view_votes_in_results'    => true,
				'allow_revote_on_update'   => false,
			];

			if ( in_array( $survey_type, [ 'survey', 'trivia', 'personality' ], true ) ) {
				$default_settings['allow_return']   = true;
				$default_settings['navigation_bar'] = 'both';
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert(
				"{$wpdb->prefix}surveyx_surveys",
				[
					'title'       => $title,
					'survey_type' => $survey_type,
					'author_id'   => get_current_user_id(),
					'status'      => 'inactive',
					'content'     => wp_json_encode(
						[
							'title'        => $title,
							// 'default' records the intent to follow the site-wide Default Cover
							// Layout; creation is the only moment we know the row is new. Surveys
							// predating the feature carry no cover_layout key and resolve to 'stacked'.
							'cover_layout' => 'default',
						]
					),
					'settings'    => wp_json_encode( $default_settings ),
					's_mode'      => 'basic',
					'created_at'  => surveyx_get_utc_now(),
					'updated_at'  => surveyx_get_utc_now(),
				],
				[ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			if ( ! $inserted ) {
				return false;
			}

			$new_survey_id = $wpdb->insert_id;

			/**
			 * Fires after a survey is created, or its settings / content / questions /
			 * answers are saved. Cache layers (the static /init payload, and the summary /
			 * analytics caches) subscribe to invalidate their entry so nothing stale
			 * survives the write. Here it also covers a fresh id reusing a just-deleted
			 * survey's slot.
			 *
			 * @param int $survey_id ID of the saved survey.
			 */
			do_action( 'surveyx_survey_saved', $new_survey_id );

			return $new_survey_id;
		}

		/**
		 * Deletes a survey from the database.
		 *
		 * @param int $survey_id The ID of the survey to be deleted.
		 *
		 * @return int|false The number of rows deleted, or `false` on failure.
		 */
		public static function delete_survey( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete( $wpdb->prefix . 'surveyx_surveys', [ 'id' => $survey_id ], [ '%d' ] );

			/**
			 * Fires after a survey is deleted. Cache layers subscribe to drop any entry
			 * keyed by this survey ID so nothing stale survives the deletion.
			 *
			 * @param int $survey_id ID of the deleted survey.
			 */
			do_action( 'surveyx_survey_deleted', $survey_id );

			return $deleted;
		}

		/**
		 * Deletes every autosave/snapshot revision belonging to a survey.
		 *
		 * Revisions are the one child table the delete-survey path does not clear on its
		 * own. Each row holds a FULL survey snapshot in a LONGTEXT column, so skipping
		 * this costs hundreds of KB per install and leaves question and answer wording
		 * readable long after the owner deleted the survey. Call it only after the parent
		 * survey row is gone, so nothing can reach these rows any more.
		 *
		 * @param int $survey_id The survey ID.
		 * @return int|false Rows deleted, or false on failure.
		 */
		public static function delete_revisions_by_survey_id( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->delete(
				$wpdb->prefix . 'surveyx_revisions',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);
		}

		/**
		 * Deletes all questions associated with a specific survey.
		 *
		 * @param int $survey_id The ID of the survey whose questions should be deleted.
		 *
		 * @return int|false The number of rows deleted on success, false on failure.
		 */
		public static function delete_questions_by_survey_id( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->delete(
				$wpdb->prefix . 'surveyx_questions',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);
		}

		/**
		 * Deletes all answers associated with a specific survey.
		 *
		 * @param int $survey_id The ID of the survey whose answers should be deleted.
		 *
		 * @return bool True on success, false if any delete operation fails.
		 */
		public static function delete_answers_by_survey_id( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->delete(
				$wpdb->prefix . 'surveyx_answers',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);
		}

		/**
		 * Deletes all response records associated with a specific survey ID.
		 *
		 * Removes rows from surveyx_responses, surveyx_sessions and surveyx_summary,
		 * and zeroes the view accumulator on surveyx_surveys.
		 *
		 * The accumulator must be zeroed explicitly: since [views_to_surveys] moved it
		 * out of the summary row, deleting that row no longer zeroes views, and a survey
		 * whose data has been cleared would show its old view count beside zeros for
		 * everything else. On the delete-survey path the survey row is already gone, so
		 * the UPDATE matches nothing — the correct outcome there.
		 *
		 * @param int $survey_id The survey ID.
		 * @return int|false The number of rows deleted on success, or false on failure.
		 */
		public static function delete_responses_by_survey_id( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->prefix . 'surveyx_responses',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->prefix . 'surveyx_sessions',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);

			// Zero the view accumulator in whichever table currently owns it. Before
			// the migration that is the summary row deleted just below.
			if ( function_exists( 'surveyx_views_on_surveys_table' ) && surveyx_views_on_surveys_table() ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'surveyx_surveys',
					[ 'total_views' => 0 ],
					[ 'id' => $survey_id ],
					[ '%d' ],
					[ '%d' ]
				);
			}

			// Every figure the analytics cache holds was measured from the rows just deleted.
			SurveyX_Db::flush_analytics_cache( $survey_id );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->delete(
				$wpdb->prefix . 'surveyx_summary',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);
		}

		/**
		 * Delete responses associated with the given question IDs.
		 *
		 * Chunked so the IN () list cannot outgrow MySQL's query limits.
		 *
		 * @param array $question_ids An array of question IDs whose responses should be deleted.
		 * @param int $chunk_size Optional. Number of IDs to delete per query. Default is 500.
		 *
		 * @return bool True on success, false if any delete query fails.
		 */
		public static function delete_responses_by_question_ids( array $question_ids = [], int $chunk_size = 500 ): bool {
			global $wpdb;

			if ( empty( $question_ids ) ) {
				return true;
			}

			foreach ( array_chunk( $question_ids, $chunk_size ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

				$sql = $wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}surveyx_responses WHERE question_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...$chunk
				);

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->query( $sql );

				if ( false === $result ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Delete responses associated with the given answer IDs.
		 *
		 * Chunked so the IN () list cannot outgrow MySQL's query limits.
		 *
		 * @param int[] $answer_ids Array of answer IDs to delete responses for.
		 * @param int $chunk_size Optional. Number of IDs to delete per query. Default is 500.
		 *
		 * @return bool True on success, false if any delete query fails.
		 */
		public static function delete_responses_by_answer_ids( array $answer_ids = [], int $chunk_size = 500 ): bool {
			global $wpdb;

			if ( empty( $answer_ids ) ) {
				return true;
			}

			foreach ( array_chunk( $answer_ids, $chunk_size ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

				$sql = $wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}surveyx_responses WHERE answer_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...$chunk
				);

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->query( $sql );

				if ( false === $result ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Retrieves survey editor data: survey content, questions and answers.
		 *
		 * Free reads only surveys the free engine may edit; a Pro survey returns []. The
		 * endpoints ahead of this already refuse those through block_if_pro_survey(), so
		 * the test here is the second lock on the same door rather than the only one.
		 *
		 * `s_mode` is reported RESOLVED, never raw: an unstamped row would otherwise reach
		 * the dashboard as '' and be greyed or not on a different rule than the one that
		 * let it through here.
		 *
		 * @param int $survey_id The ID of the survey to retrieve.
		 *
		 * @return array An associative array containing data
		 */
		public static function get_survey_editor_data( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$survey_result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT content, s_mode
                    FROM {$wpdb->prefix}surveyx_surveys
                    WHERE id = %d",
					$survey_id
				)
			);

			if ( ! $survey_result ) {
				return [];
			}

			if ( SurveyX_Db::survey_needs_pro( $survey_id, (string) $survey_result->s_mode ) ) {
				return [];
			}

			$survey           = json_decode( $survey_result->content, true ) ?: [];
			$survey['id']     = $survey_id;
			$survey['s_mode'] = SurveyX_Db::resolve_survey_mode( $survey_id, (string) $survey_result->s_mode );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$questions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, sorder, content
			         FROM {$wpdb->prefix}surveyx_questions
			         WHERE survey_id = %d
			         ORDER BY sorder ASC",
					$survey_id
				)
			);

			foreach ( $questions as &$question ) {
				$question->content = json_decode( $question->content, true );
			}
			unset( $question );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$answers = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, question_id, sorder, content
			         FROM {$wpdb->prefix}surveyx_answers
			         WHERE survey_id = %d
			         ORDER BY sorder ASC",
					$survey_id
				)
			);

			foreach ( $answers as &$answer ) {
				$answer->content = json_decode( $answer->content, true );
			}
			unset( $answer );

			return [
				'survey'    => $survey,
				'questions' => $questions,
				'answers'   => $answers,
			];
		}

		/**
		 * Quickly updates a survey record in the database.
		 *
		 * Writes ONLY the columns the caller actually supplied — `survey_type`, `status`
		 * and `settings` are each optional — plus `updated_at`, which always moves. A
		 * caller that owns one field (the activation toggle owns `status`) must be able
		 * to persist it without restating, and so without overwriting, the rest of the row.
		 *
		 * @param int   $survey_id The ID of the survey to update.
		 * @param array $data      Any of 'survey_type', 'status', 'settings'.
		 *
		 * @return int|false Number of rows updated on success, false on failure.
		 */
		public static function quick_update_survey( $survey_id, $data ) {
			global $wpdb;

			$update_data = [];
			$formats     = [];

			foreach ( [ 'survey_type', 'status' ] as $column ) {
				if ( array_key_exists( $column, $data ) ) {
					$update_data[ $column ] = $data[ $column ];
					$formats[]              = '%s';
				}
			}

			if ( array_key_exists( 'settings', $data ) ) {
				$update_data['settings'] = wp_json_encode( $data['settings'] );
				$formats[]               = '%s';
			}

			if ( empty( $update_data ) ) {
				return 0;
			}

			$update_data['updated_at'] = surveyx_get_utc_now();
			$formats[]                 = '%s';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->prefix . 'surveyx_surveys',
				$update_data,
				[ 'id' => $survey_id ],
				$formats,
				[ '%d' ]
			);

			// Status / settings (theme, branding, s_mode) and publish state all feed the
			// static /init payload.
			do_action( 'surveyx_survey_saved', $survey_id );

			return $result;
		}

		/**
		 * Update a survey record in the database.
		 *
		 * @param int   $survey_id   The ID of the survey to update.
		 * @param array $survey_data The survey data to save.
		 *
		 * @return int|false Number of rows updated, or false on error.
		 */
		public static function update_survey_base( int $survey_id, array $survey_data = [] ) {
			global $wpdb;

			$survey_json = wp_json_encode( $survey_data );

			$update_data = [
				'content'    => $survey_json,
				'cover'      => $survey_data['image_url'] ?? '',
				'title'      => $survey_data['title'] ?? '',
				'updated_at' => surveyx_get_utc_now(),
			];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->prefix . 'surveyx_surveys',
				$update_data,
				[ 'id' => (int) $survey_id ],
				[ '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);

			do_action( 'surveyx_survey_saved', $survey_id );

			return $result;
		}

		/**
		 * Save or update questions and answers for a survey.
		 *
		 * @param int   $survey_id Survey ID.
		 * @param array $questions Array of questions data.
		 * @param array $answers   Array of answers data.
		 *
		 * @return array ID mapping ['questions' => [temp_id => real_id], 'answers' => [temp_id => real_id]]
		 */
		public static function update_questions_and_answers_base( int $survey_id, array $questions = [], array $answers = [] ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'START TRANSACTION' );

			// Question types that require answers (must match JS constant)
			$question_types_requiring_answers = [ 'check_box', 'select', 'check_box_image', 'yes_no' ];

			$id_mapping = [
				'questions' => [],
				'answers'   => [],
			];

			try {
				// Bucket answers by question_id once so each question does not rescan the full array.
				$answers_by_qid = [];
				foreach ( $answers as $a ) {
					if ( empty( $a['question_id'] ) ) {
						continue;
					}
					$answers_by_qid[ (string) $a['question_id'] ][] = $a;
				}

				// A question with no title — or one of the answer-bearing types with no
				// non-empty answer — is silently dropped from the save.
				foreach ( $questions as $q ) {
					if ( empty( $q['id'] ) ) {
						continue;
					}

					$question_title = $q['content']['title'] ?? '';
					if ( empty( trim( $question_title ) ) ) {
						continue;
					}

					$question_type = $q['content']['type'] ?? '';

					$requires_answers = in_array( $question_type, $question_types_requiring_answers, true );

					if ( $requires_answers ) {
						$question_answers = array_filter(
							$answers_by_qid[ (string) $q['id'] ] ?? [],
							function ( $a ) {
								$title = $a['content']['title'] ?? '';
								return ! empty( trim( $title ) );
							}
						);

						if ( empty( $question_answers ) ) {
							continue;
						}
					}

					if ( SurveyX_Admin_Helpers::is_temp_id( $q['id'] ) ) {
						$real_qid                            = self::add_new_question( $survey_id, $q );
						$id_mapping['questions'][ $q['id'] ] = $real_qid;
					} else {
						$real_qid = self::update_existing_question( $survey_id, $q );
					}

					$question_answer_rows = $answers_by_qid[ (string) $q['id'] ] ?? [];
					if ( (string) $real_qid !== (string) $q['id'] && isset( $answers_by_qid[ (string) $real_qid ] ) ) {
						$question_answer_rows = array_merge( $question_answer_rows, $answers_by_qid[ (string) $real_qid ] );
					}
					$answer_mappings       = self::update_answers_base( $survey_id, $real_qid, $q, $question_answer_rows );
					$id_mapping['answers'] = array_merge( $id_mapping['answers'], $answer_mappings );
				}

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'COMMIT' );

				do_action( 'surveyx_survey_saved', $survey_id );

				return $id_mapping;
			} catch ( Exception $exception ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );

				return $id_mapping;
			}
		}

		/**
		 * Update or add answers for a specific survey question.
		 *
		 * @param int   $survey_id The ID of the survey.
		 * @param int   $real_qid  The real ID of the question (after insert/update).
		 * @param array $q         The question data array. Expected keys include 'id'.
		 * @param array $answers   Array of answers data.
		 *
		 * @return array Answer ID mappings [temp_id => real_id]
		 */
		public static function update_answers_base( int $survey_id, int $real_qid, array $q, array $answers ) {
			$answer_mappings = [];

			foreach ( $answers as $a ) {
				if ( empty( $a['question_id'] ) ) {
					continue;
				}

				$answer_qid = $a['question_id'];

				if ( $answer_qid === $q['id'] || intval( $answer_qid ) === $real_qid ) {
					if ( empty( $a['id'] ) || SurveyX_Admin_Helpers::is_temp_id( $a['id'] ) ) {
						$real_aid = self::add_new_answer( $survey_id, $real_qid, $a );
						if ( ! empty( $a['id'] ) && SurveyX_Admin_Helpers::is_temp_id( $a['id'] ) ) {
							$answer_mappings[ $a['id'] ] = $real_aid;
						}
					} else {
						self::update_existing_answer( $survey_id, $real_qid, $a );
					}
				}
			}

			return $answer_mappings;
		}

		/**
		 * Add a new survey question.
		 *
		 * @param int   $survey_id The ID of the survey.
		 * @param array $q         Question data array with 'content' and 'sorder'.
		 *
		 * @return int The inserted question ID.
		 */
		public static function add_new_question( int $survey_id, array $q ) {
			global $wpdb;

			$content = wp_json_encode( $q['content'] ?? [] );
			$sorder  = intval( $q['sorder'] ?? 1 );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'surveyx_questions',
				[
					'survey_id' => $survey_id,
					'title'     => sanitize_text_field( $q['content']['title'] ?? '' ),
					'sorder'    => $sorder,
					'content'   => $content,
				],
				[ '%d', '%s', '%d', '%s' ]
			);

			return $wpdb->insert_id;
		}

		/**
		 * Update an existing survey question.
		 *
		 * @param int   $survey_id The ID of the survey.
		 * @param array $q         Question data array with 'id', 'content', and 'sorder'.
		 *
		 * @return int The question ID that was updated.
		 */
		public static function update_existing_question( int $survey_id, array $q ) {
			global $wpdb;

			$question_id = intval( $q['id'] );
			$content     = wp_json_encode( $q['content'] ?? [] );
			$sorder      = intval( $q['sorder'] ?? 1 );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'surveyx_questions',
				[
					'title'   => $q['content']['title'] ?? '',
					'content' => $content,
					'sorder'  => $sorder,
				],
				[
					'id'        => $question_id,
					'survey_id' => $survey_id,
				],
				[ '%s', '%s', '%d' ],
				[ '%d', '%d' ]
			);

			return $question_id;
		}

		/**
		 * Add a new answer to a survey question.
		 *
		 * @param int   $survey_id   The ID of the survey.
		 * @param int   $question_id The ID of the question.
		 * @param array $a           Answer data array with 'content' and 'sorder'.
		 *
		 * @return int Inserted answer ID.
		 */
		public static function add_new_answer( int $survey_id, int $question_id, array $a ) {
			global $wpdb;

			$content = wp_json_encode( $a['content'] ?? [] );
			$sorder  = intval( $a['sorder'] ?? 1 );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'surveyx_answers',
				[
					'survey_id'   => $survey_id,
					'question_id' => $question_id,
					'title'       => $a['content']['title'] ?? '',
					'sorder'      => $sorder,
					'content'     => $content,
				],
				[ '%d', '%d', '%s', '%d', '%s' ]
			);

			return $wpdb->insert_id;
		}

		/**
		 * Update an existing answer for a survey question.
		 *
		 * @param int   $survey_id   The ID of the survey.
		 * @param int   $question_id The ID of the question.
		 * @param array $a           Answer data array with 'id', 'content', and 'sorder'.
		 *
		 * @return int The updated answer ID.
		 */
		public static function update_existing_answer( int $survey_id, int $question_id, array $a ) {
			global $wpdb;

			$answer_id = intval( $a['id'] );
			$content   = wp_json_encode( $a['content'] ?? [] );
			$sorder    = intval( $a['sorder'] ?? 1 );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'surveyx_answers',
				[
					'question_id' => $question_id,
					'title'       => $a['content']['title'] ?? '',
					'content'     => $content,
					'sorder'      => $sorder,
				],
				[
					'id'        => $answer_id,
					'survey_id' => $survey_id,
				],
				[ '%d', '%s', '%s', '%d' ],
				[ '%d', '%d' ]
			);

			return $answer_id;
		}

		/**
		 * Delete survey questions permanently.
		 *
		 * @param int   $survey_id           The ID of the survey.
		 * @param array $remove_question_ids An array of question IDs to delete.
		 *
		 * @return void
		 */
		public static function delete_questions( int $survey_id, array $remove_question_ids ) {
			global $wpdb;

			if ( empty( $remove_question_ids ) ) {
				return;
			}

			self::delete_answers_by_question_ids( $survey_id, $remove_question_ids );

			foreach ( $remove_question_ids as $rid ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete(
					$wpdb->prefix . 'surveyx_questions',
					[
						'id'        => (int) $rid,
						'survey_id' => (int) $survey_id,
					],
					[ '%d', '%d' ]
				);
			}

			self::delete_responses_by_question_ids( $remove_question_ids );
		}

		/**
		 * Deletes answers associated with specified question IDs in a survey.
		 *
		 * @param int   $survey_id           The ID of the survey.
		 * @param array $remove_question_ids An array of question IDs whose answers should be deleted.
		 *
		 * @return void
		 */
		public static function delete_answers_by_question_ids( int $survey_id, array $remove_question_ids ) {
			global $wpdb;

			if ( empty( $remove_question_ids ) ) {
				return;
			}

			foreach ( $remove_question_ids as $question_id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete(
					$wpdb->prefix . 'surveyx_answers',
					[
						'question_id' => (int) $question_id,
						'survey_id'   => $survey_id,
					],
					[ '%d', '%d' ]
				);
			}
		}

		/**
		 * Delete survey answers permanently.
		 *
		 * @param int   $survey_id         The survey ID.
		 * @param array $remove_answer_ids Array of answer IDs to delete.
		 *
		 * @return void
		 */
		public static function delete_answers( int $survey_id, array $remove_answer_ids ) {
			global $wpdb;

			if ( empty( $remove_answer_ids ) ) {
				return;
			}

			foreach ( $remove_answer_ids as $rid ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete(
					$wpdb->prefix . 'surveyx_answers',
					[
						'id'        => (int) $rid,
						'survey_id' => (int) $survey_id,
					],
					[ '%d', '%d' ]
				);
			}

			self::delete_responses_by_answer_ids( $remove_answer_ids );
		}

		/**
		 * Import survey data to database.
		 * Used for template imports to update content and settings.
		 *
		 * @param int   $survey_id   Survey ID to update.
		 * @param array $survey_data Survey data array with 'content', 'settings', etc.
		 *
		 * @return int|false Returns survey ID on success, false on failure.
		 */
		public static function import_survey_data( $survey_id, $survey_data ) {
			global $wpdb;

			if ( empty( $survey_id ) || ! is_array( $survey_data ) ) {
				return false;
			}

			$update_data   = [
				'content'  => wp_json_encode( $survey_data['content'], JSON_UNESCAPED_UNICODE ),
				'settings' => wp_json_encode( $survey_data['settings'], JSON_UNESCAPED_UNICODE ),
			];
			$format_values = [ '%s', '%s' ];

			// status lives in the settings JSON and in its own column; keep them in sync.
			if ( ! empty( $survey_data['settings']['status'] ) ) {
				$allowed_statuses = [ 'active', 'inactive' ];
				$status           = sanitize_text_field( $survey_data['settings']['status'] );
				if ( in_array( $status, $allowed_statuses, true ) ) {
					$update_data['status'] = $status;
					$format_values[]       = '%s';
				}
			}

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->prefix . 'surveyx_surveys',
				$update_data,
				[ 'id' => $survey_id ],
				$format_values,
				[ '%d' ]
			);

			do_action( 'surveyx_survey_saved', $survey_id );

			return false !== $result ? $survey_id : false;
		}
	}
}
