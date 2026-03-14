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

			// Validate sort column
			$allowed_sort_columns = [ 'id', 'title', 'survey_type', 'status', 'created_at', 'updated_at' ];
			if ( ! in_array( $sort_by, $allowed_sort_columns, true ) ) {
				$sort_by = 'created_at';
			}

			// Validate sort order
			$sort_order = 'ASC' === strtoupper( $sort_order ) ? 'ASC' : 'DESC';

			// Build WHERE clause for search
			$where      = '';
			$where_args = [];
			if ( ! empty( $search ) ) {
				$like       = '%' . $wpdb->esc_like( $search ) . '%';
				$where      = 'WHERE (title LIKE %s OR id = %d)';
				$where_args = [ $like, intval( $search ) ];
			}

			// Get total count
			$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
			if ( ! empty( $where_args ) ) {
                // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- $count_sql is safely built with whitelisted column names
				$count_sql = $wpdb->prepare( $count_sql, ...$where_args );
			}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB -- Table/columns are hardcoded, safe
			$total = (int) $wpdb->get_var( $count_sql );

			// Calculate offset
			$offset = ( $page - 1 ) * $per_page;

			// Get paginated results - only select fields needed for list display
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
		 * This function fetches a survey record from the database based on the given survey ID.
		 *
		 * @param int $survey_id The ID of the survey to retrieve.
		 *
		 * @return array|null An array of survey objects if found, or null if no survey exists with the given ID.
		 * @global wpdb $wpdb The WordPress database object.
		 *
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
		 * This method inserts a new row into the `surveyx_surveys` table
		 * with the given title and survey type. By default, the survey type is "vote".
		 *
		 *
		 * @param string $title The title of the survey.
		 * @param string $survey_type Optional. The type of survey to create. Default 'vote'.
		 *
		 * @return int|false The ID of the newly created survey on success, or false on failure.
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
		 */
		public static function create_survey( $title, $survey_type = 'vote' ) {
			global $wpdb;

			// Default settings for new surveys
			$default_settings = [
				'skip_submit_button'       => true,
				'show_header_branding'     => true,
				'show_footer_branding'     => true,
				'show_start_again'         => true,
				'view_votes_in_results'    => true,
				'allow_revote_on_update'   => false,
			];

			// Navigation bar settings only for survey, trivia, personality types
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
					'content'     => wp_json_encode( [ 'title' => $title ] ),
					'settings'    => wp_json_encode( $default_settings ),
					's_mode'      => 'basic',
					'created_at'  => surveyx_get_utc_now(),
					'updated_at'  => surveyx_get_utc_now(),
				],
				[ '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			return $inserted ? $wpdb->insert_id : false;
		}

		/**
		 * Deletes a survey from the database.
		 *
		 * This function removes a survey record from the `surveyx_surveys` table based on the given survey ID.
		 *
		 * @param int $survey_id The ID of the survey to be deleted.
		 *
		 * @return int|false The number of rows deleted, or `false` on failure.
		 */
		public static function delete_survey( $survey_id ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->delete( $wpdb->prefix . 'surveyx_surveys', [ 'id' => $survey_id ], [ '%d' ] );
		}

		/**
		 * Deletes all questions associated with a specific survey.
		 *
		 * This method deletes all entries from the `surveyx_questions` table that are
		 * linked to the given survey ID.
		 *
		 * @param int $survey_id The ID of the survey whose questions should be deleted.
		 *
		 * @return int|false The number of rows deleted on success, false on failure.
		 * @global wpdb $wpdb WordPress database abstraction object.
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
		 * This method first retrieves all question IDs related to the given survey ID,
		 * then deletes the corresponding answers from the surveyx_answers table.
		 *
		 * @param int $survey_id The ID of the survey whose answers should be deleted.
		 *
		 * @return bool True on success, false if any delete operation fails.
		 * @global wpdb $wpdb WordPress database abstraction object.
		 *
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
		 * Removes rows from surveyx_responses, surveyx_sessions, and surveyx_summary tables.
		 *
		 * @param int $survey_id The survey ID.
		 * @return int|false The number of rows deleted on success, or false on failure.
		 */
		public static function delete_responses_by_survey_id( $survey_id ) {
			global $wpdb;

			// Delete from responses table
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->prefix . 'surveyx_responses',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);

			// Delete from sessions table (complete history for this survey)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->prefix . 'surveyx_sessions',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);

			// Delete from summary table
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->delete(
				$wpdb->prefix . 'surveyx_summary',
				[ 'survey_id' => $survey_id ],
				[ '%d' ]
			);
		}

		/**
		 * Delete responses associated with the given question IDs in chunks for large datasets.
		 *
		 * This method deletes responses in batches of a configurable size to avoid
		 * large queries that could cause performance issues or exceed MySQL limits.
		 *
		 * @param array $question_ids An array of question IDs whose responses should be deleted.
		 * @param int $chunk_size Optional. Number of IDs to delete per query. Default is 500.
		 *
		 * @return bool True on success, false if any delete query fails.
		 */
		public static function delete_responses_by_question_ids( array $question_ids = [], int $chunk_size = 500 ): bool {
			global $wpdb;

			if ( empty( $question_ids ) ) {
				return true; // Nothing to delete
			}

			// Process IDs in chunks
			foreach ( array_chunk( $question_ids, $chunk_size ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

				// Delete from responses table
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
		 * Delete responses associated with the given answer IDs in chunks.
		 *
		 * This method deletes responses in batches to prevent performance issues
		 * when dealing with a large number of answer IDs. It processes the IDs
		 * in chunks to avoid large queries that could exceed MySQL limits.
		 *
		 * @param int[] $answer_ids Array of answer IDs to delete responses for.
		 * @param int $chunk_size Optional. Number of IDs to delete per query. Default is 500.
		 *
		 * @return bool True on success, false if any delete query fails.
		 */
		public static function delete_responses_by_answer_ids( array $answer_ids = [], int $chunk_size = 500 ): bool {
			global $wpdb;

			if ( empty( $answer_ids ) ) {
				return true; // Nothing to delete
			}

			// Process IDs in chunks
			foreach ( array_chunk( $answer_ids, $chunk_size ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

				// Delete from responses table
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
		 * Retrieves survey editor data including survey details, questions, answers, and votes.
		 *
		 * This function fetches:
		 * - The survey's content (decoded from JSON).
		 * - All questions belonging to the survey.
		 * - All answers related to those questions.
		 * - All vote records related to the survey.
		 *
		 * @param int $survey_id The ID of the survey to retrieve.
		 *
		 * @return array An associative array containing data
		 */
		public static function get_survey_editor_data( $survey_id ) {
			global $wpdb;

			// GET SURVEY DATA
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$survey_result = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT content, s_mode
                    FROM {$wpdb->prefix}surveyx_surveys
                    WHERE id = %d AND `s_mode` = %s",
					$survey_id,
					'basic'
				)
			);

			if ( ! $survey_result ) {
				return [];
			}

			$survey           = json_decode( $survey_result->content, true ) ?: [];
			$survey['id']     = $survey_id;
			$survey['s_mode'] = $survey_result->s_mode;

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

			// Decode JSON and map to expected format for Vue
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

			// Decode JSON and map to expected format for Vue
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
		 * Updates the title, survey_type, status, settings, and updated_at fields
		 * of the survey identified by the given survey ID.
		 *
		 * @param int $survey_id The ID of the survey to update.
		 *
		 * @return int|false Number of rows updated on success, false on failure.
		 */
		public static function quick_update_survey( $survey_id, $data ) {
			global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->update(
				$wpdb->prefix . 'surveyx_surveys',
				[
					'survey_type' => $data['survey_type'],
					'status'      => $data['status'],
					'settings'    => wp_json_encode( $data['settings'] ),
					'updated_at'  => surveyx_get_utc_now(),
				],
				[ 'id' => $survey_id ],
				[
					'%s', // survey_type
					'%s', // status
					'%s', // settings (json string)
					'%s', // updated_at (datetime string)
				],
				[ '%d' ] // id as integer
			);
		}

		/**
		 * Update a survey record in the database.
		 * Saves content to database.
		 *
		 * @param int   $survey_id   The ID of the survey to update.
		 * @param array $survey_data The survey data to save.
		 *
		 * @return int|false Number of rows updated, or false on error.
		 */
		public static function update_survey_base( int $survey_id, array $survey_data = [] ) {
			global $wpdb;

			// Encode survey data as JSON for storage
			$survey_json = wp_json_encode( $survey_data );

			// Update content and timestamps directly
			$update_data = [
				'content'    => $survey_json,
				'cover'      => $survey_data['image_url'] ?? '',
				'title'      => $survey_data['title'] ?? '',
				'updated_at' => surveyx_get_utc_now(),
			];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->update(
				$wpdb->prefix . 'surveyx_surveys',
				$update_data,
				[ 'id' => (int) $survey_id ],
				[ '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		}

		/**
		 * Save or update questions and answers for a survey.
		 * Saves content to database.
		 *
		 * @param int   $survey_id Survey ID.
		 * @param array $questions Array of questions data.
		 * @param array $answers   Array of answers data.
		 *
		 * @return array ID mapping ['questions' => [temp_id => real_id], 'answers' => [temp_id => real_id]]
		 */
		public static function update_questions_and_answers_base( int $survey_id, array $questions = [], array $answers = [] ) {
			global $wpdb;

			// Start transaction
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'START TRANSACTION' );

			// Question types that require answers (must match JS constant)
			$question_types_requiring_answers = [ 'check_box', 'select', 'check_box_image', 'yes_no' ];

			// ID mapping for temp IDs to real IDs
			$id_mapping = [
				'questions' => [],
				'answers'   => [],
			];

			try {
				foreach ( $questions as $q ) {
					// Skip if the question has no ID
					if ( empty( $q['id'] ) ) {
						continue;
					}

					// Skip if question has no title
					$question_title = $q['content']['title'] ?? '';
					if ( empty( trim( $question_title ) ) ) {
						continue;
					}

					// Get question type
					$question_type = $q['content']['type'] ?? '';

					// Check if question requires answers
					$requires_answers = in_array( $question_type, $question_types_requiring_answers, true );

					// If question requires answers, check if it has valid answers
					if ( $requires_answers ) {
						$question_answers = array_filter(
							$answers,
							function ( $a ) use ( $q ) {
								if ( ! isset( $a['question_id'] ) || (string) $a['question_id'] !== (string) $q['id'] ) {
									return false;
								}
								// Check if answer has non-empty title
								$title = $a['content']['title'] ?? '';
								return ! empty( trim( $title ) );
							}
						);

						// Skip this question if it has no valid answers
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

					// Update answers and collect ID mappings
					$answer_mappings       = self::update_answers_base( $survey_id, $real_qid, $q, $answers );
					$id_mapping['answers'] = array_merge( $id_mapping['answers'], $answer_mappings );
				}

				// Commit transaction
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'COMMIT' );

				return $id_mapping;
			} catch ( Exception $exception ) {
				// Rollback on error
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );

				return $id_mapping;
			}
		}

		/**
		 * Update or add answers for a specific survey question.
		 * Saves content to database.
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
				// Skip if the answer does not belong to any question
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
		 * Saves content to database.
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
		 * Saves content to database.
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
		 * Saves content to database.
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
		 * Saves content to database.
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
		 * No more trash logic - questions are deleted immediately.
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

			// Delete questions
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

			// Delete associated responses
			self::delete_responses_by_question_ids( $remove_question_ids );
		}

		/**
		 * Delete survey answers permanently.
		 * No more trash logic - answers are deleted immediately.
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

			// Delete answers
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

			// Delete associated responses
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

			// Sync status column from settings data if present
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

			return false !== $result ? $survey_id : false;
		}
	}
}
