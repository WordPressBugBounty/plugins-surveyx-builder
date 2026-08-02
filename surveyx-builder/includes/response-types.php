<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Response_Types', false ) ) {
	/**
	 * Single source of truth for the per-question-type response contract.
	 *
	 * Two things live here so they can never drift apart across the PHP layer:
	 *
	 * 1. The negative "special answer_id" sentinels stored in surveyx_responses for
	 *    question types that have no real answer row (text/contact/scale/…), plus the
	 *    0 = "Other" choice. These are a cross-file contract shared by
	 *    SurveyX_Db::create_responses / create_seen_response (writer) and the analytics
	 *    text-response queries (reader). The numeric values MUST stay exactly as-is —
	 *    they are already persisted in existing installs.
	 *
	 * 2. The per-type behaviour used by the /progress handler: how a response of a
	 *    given type is validated, and how its response_status ('answered' vs
	 *    'skipped_optional') is derived. Both progress-handler methods route through
	 *    here so the type set is defined once instead of in two parallel switches.
	 */
	class SurveyX_Response_Types {

		/** Question was viewed but not answered. */
		const SEEN = -8;

		/** Optional question the respondent skipped. */
		const SKIPPED = -4;

		/** Free-text answer (text_input). */
		const TEXT_INPUT = -2;

		/** Contact-info answer. */
		const CONTACT_INFO = -3;

		/** Opinion-scale answer. */
		const OPINION_SCALE = -5;

		/** Rating answer. */
		const RATING = -6;

		/** Date answer. */
		const DATE = -7;

		/** "Other" free-text choice on a choice question. */
		const OTHER = 0;

		/**
		 * Map of question_type => the special answer_id used for an 'answered'
		 * response of that type. Types absent here are ordinary choice questions
		 * whose responses carry real (positive) answer_id rows.
		 *
		 * @return array<string,int>
		 */
		private static function type_answer_ids() {
			return [
				'text_input'    => self::TEXT_INPUT,
				'contact_info'  => self::CONTACT_INFO,
				'opinion_scale' => self::OPINION_SCALE,
				'rating'        => self::RATING,
				'date'          => self::DATE,
			];
		}

		/**
		 * Resolves the special answer_id for a stored response.
		 *
		 * Status wins over type: a 'seen' or 'skipped_optional' row is always the
		 * status sentinel regardless of the question type.
		 *
		 * @param string $question_type   Question type.
		 * @param string $response_status  Response status.
		 * @return int Special answer_id value.
		 */
		public static function special_answer_id( $question_type, $response_status ) {
			if ( 'seen' === $response_status ) {
				return self::SEEN;
			}

			if ( 'skipped_optional' === $response_status ) {
				return self::SKIPPED;
			}

			$map = self::type_answer_ids();

			return $map[ $question_type ] ?? self::SKIPPED;
		}

		/**
		 * Returns the answer_id => type-name map used by the analytics text-response
		 * queries. Base types are text_input (-2) and other (0); Pro registers its
		 * extra types via the surveyx_text_response_type_map filter.
		 *
		 * @return array<int,string>
		 */
		public static function text_response_type_map() {
			$type_map = [
				self::TEXT_INPUT => 'text_input',
				self::OTHER      => 'other',
			];

			return apply_filters( 'surveyx_text_response_type_map', $type_map );
		}

		/**
		 * Validates a submitted response against its question type.
		 *
		 * Mutates $content by reference for the text_input empty-message
		 * normalisation only. Pro-only question types (contact_info, date) are
		 * validated through the SurveyX_Validation_Helper methods that ship with Pro;
		 * when those helpers are absent (Free) the branch is inert, which is safe
		 * because Free never renders those question types.
		 *
		 * @param string $question_type    Question type.
		 * @param bool   $is_required      Whether the question is required.
		 * @param array  $answer_ids       Selected answer IDs.
		 * @param array  $content          Response content (by reference).
		 * @param array  $question_content Full question content with settings.
		 * @return true|WP_Error True when valid, WP_Error on failure.
		 */
		public static function validate( $question_type, $is_required, $answer_ids, &$content, $question_content = [] ) {
			if ( 'text_input' === $question_type ) {
				$validation = SurveyX_Validation_Helper::validate_text_input( $content, $is_required );

				if ( is_wp_error( $validation ) ) {
					return $validation;
				}

				// Set empty message for non-required empty responses.
				if ( ! $is_required && ( ! isset( $content['message'] ) || empty( trim( $content['message'] ) ) ) ) {
					$content = [ 'message' => '' ];
				}

				return true;
			}

			if ( 'contact_info' === $question_type ) {
				if ( method_exists( 'SurveyX_Validation_Helper', 'validate_contact_info' ) ) {
					return SurveyX_Validation_Helper::validate_contact_info( $content, $question_content );
				}

				return true;
			}

			if ( 'opinion_scale' === $question_type || 'rating' === $question_type ) {
				if ( $is_required && ( ! isset( $content['value'] ) || null === $content['value'] ) ) {
					return new WP_Error(
						'missing_answer',
						esc_html__( 'Please select a rating', 'surveyx-builder' ),
						[ 'status' => 400 ]
					);
				}

				return true;
			}

			if ( 'date' === $question_type ) {
				if ( method_exists( 'SurveyX_Validation_Helper', 'validate_date' ) ) {
					return SurveyX_Validation_Helper::validate_date( $content, $is_required );
				}

				return true;
			}

			// Choice questions: a required question must have a selection.
			if ( $is_required && empty( $answer_ids ) ) {
				return new WP_Error(
					'missing_answer',
					esc_html__( 'Please select an answer', 'surveyx-builder' ),
					[ 'status' => 400 ]
				);
			}

			return true;
		}

		/**
		 * Derives the response_status ('answered' | 'skipped_optional') for a
		 * submitted response. Required questions are always 'answered'; optional
		 * ones are 'answered' only when the type-appropriate payload is non-empty.
		 *
		 * @param string $question_type Question type.
		 * @param bool   $is_required   Whether the question is required.
		 * @param array  $answer_ids    Selected answer IDs.
		 * @param array  $content       Response content.
		 * @return string 'answered' or 'skipped_optional'.
		 */
		public static function determine_status( $question_type, $is_required, $answer_ids, $content ) {
			// If question is required, it's always 'answered'.
			if ( $is_required ) {
				return 'answered';
			}

			// For text input questions: check if content is empty.
			if ( 'text_input' === $question_type ) {
				$message = trim( $content['message'] ?? '' );
				return empty( $message ) ? 'skipped_optional' : 'answered';
			}

			// For contact_info: check if all fields are empty.
			if ( 'contact_info' === $question_type ) {
				$has_data = false;
				foreach ( $content as $value ) {
					if ( ! empty( trim( (string) $value ) ) ) {
						$has_data = true;
						break;
					}
				}
				return $has_data ? 'answered' : 'skipped_optional';
			}

			// For opinion_scale and rating: check if value is set.
			if ( 'opinion_scale' === $question_type || 'rating' === $question_type ) {
				return ( isset( $content['value'] ) && null !== $content['value'] ) ? 'answered' : 'skipped_optional';
			}

			// For date: check if all fields are filled.
			if ( 'date' === $question_type ) {
				$month = trim( $content['month'] ?? '' );
				$day   = trim( $content['day'] ?? '' );
				$year  = trim( $content['year'] ?? '' );
				return ( ! empty( $month ) && ! empty( $day ) && ! empty( $year ) ) ? 'answered' : 'skipped_optional';
			}

			// For choice questions: check if any answer is selected.
			return empty( $answer_ids ) ? 'skipped_optional' : 'answered';
		}
	}
}
