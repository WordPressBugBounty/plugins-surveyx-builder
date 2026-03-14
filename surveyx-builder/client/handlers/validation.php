<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Validation_Helper', false ) ) {
	/**
	 * Helper class for common validation and sanitization logic.
	 *
	 * @since 1.0.0
	 */
	class SurveyX_Validation_Helper {

		/**
		 * Sanitizes and validates a UUID v4 string.
		 *
		 * @param string|int $uuid The UUID string to sanitize.
		 * @return string|int The sanitized UUID if valid, 0 if input is 0, empty string otherwise.
		 */
		public static function sanitize_uuid( $uuid ) {
			if ( 0 === $uuid || '0' === $uuid ) {
				return 0;
			}

			$uuid = trim( (string) $uuid );

			if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid ) ) {
				return strtolower( $uuid );
			}

			return '';
		}

		/**
		 * Validates request body as JSON array.
		 *
		 * @param WP_REST_Request $request The REST request object.
		 * @return array|WP_Error Array of data or WP_Error on failure.
		 */
		public static function validate_json_body( WP_REST_Request $request ) {
			$body = json_decode( $request->get_body(), true );

			if ( ! is_array( $body ) ) {
				return new WP_Error( 'invalid_data', esc_html__( 'Invalid data', 'surveyx-builder' ), [ 'status' => 400 ] );
			}

			return surveyx_client_recursive_sanitize( $body );
		}

		/**
		 * Validates that answer IDs belong to the given question.
		 * Limits answer IDs to maximum_votes setting.
		 *
		 * @param array $answer_ids  Array of answer IDs to validate.
		 * @param int   $question_id The question ID.
		 * @return array Validated and limited answer IDs.
		 */
		public static function validate_answer_ids( $answer_ids, $question_id ) {
			$question_content = SurveyX_Db::get_question_content( $question_id );
			$maximum_votes    = absint( $question_content['maximum_votes'] ?? 1 );

			if ( count( $answer_ids ) <= $maximum_votes ) {
				return $answer_ids;
			}

			return array_slice( $answer_ids, 0, $maximum_votes );
		}

		/**
		 * Validates survey ID from request data.
		 *
		 * @param array $data Request data array.
		 * @return int|WP_Error Survey ID or WP_Error on failure.
		 */
		public static function validate_survey_id( $data ) {
			$survey_id = absint( $data['survey_id'] ?? 0 );

			if ( empty( $survey_id ) ) {
				return new WP_Error( 'invalid_survey_id', esc_html__( 'Invalid survey ID', 'surveyx-builder' ), [ 'status' => 400 ] );
			}

			return $survey_id;
		}

		/**
		 * Validates question ID from request data.
		 *
		 * @param array $data Request data array.
		 * @return int|WP_Error Question ID or WP_Error on failure.
		 */
		public static function validate_question_id( $data ) {
			$question_id = absint( $data['question_id'] ?? 0 );

			if ( empty( $question_id ) ) {
				return new WP_Error( 'invalid_question_id', esc_html__( 'Invalid question ID', 'surveyx-builder' ), [ 'status' => 400 ] );
			}

			return $question_id;
		}

		/**
		 * Validates respondent ID from request data.
		 *
		 * @param array $data Request data array.
		 * @return string|WP_Error Respondent ID or WP_Error on failure.
		 */
		public static function validate_respondent_id( $data ) {
			$respondent_id = self::sanitize_uuid( $data['respondent_id'] ?? '' );

			if ( empty( $respondent_id ) ) {
				return new WP_Error( 'invalid_respondent_id', esc_html__( 'Invalid respondent ID', 'surveyx-builder' ), [ 'status' => 400 ] );
			}

			return $respondent_id;
		}

		/**
		 * Validates text input responses.
		 *
		 * @param array $content     Response content.
		 * @param bool  $is_required Whether the field is required.
		 * @return true|WP_Error True if valid, WP_Error on failure.
		 */
		public static function validate_text_input( $content, $is_required ) {
			$has_message = isset( $content['message'] ) && ! empty( trim( $content['message'] ) );

			if ( $is_required && ! $has_message ) {
				return new WP_Error(
					'required_field',
					esc_html__( 'Please input your answer', 'surveyx-builder' ),
					[ 'status' => 400 ]
				);
			}

			return true;
		}

		/**
		 * Creates standard error response.
		 *
		 * @param WP_Error $error The WP_Error object.
		 * @return WP_REST_Response Error response.
		 */
		public static function error_response( $error ) {
			$status     = 400;
			$error_data = $error->get_error_data();

			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = $error_data['status'];
			}

			return new WP_REST_Response(
				[ 'message' => $error->get_error_message() ],
				$status
			);
		}
	}
}
