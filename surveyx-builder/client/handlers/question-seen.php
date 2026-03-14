<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Question_Seen_Handler', false ) ) {
    /**
     * Handles the /question-seen endpoint - tracks when questions are viewed.
     * Creates a response record with 'seen' status.
     *
     * @since 1.0.0
     */
    class SurveyX_Question_Seen_Handler
    {
        /**
         * Main handler for question-seen endpoint.
         *
         * @param WP_REST_Request $request The REST request object.
         * @return WP_REST_Response Response indicating success or failure.
         */
        public static function handle( WP_REST_Request $request )
        {
            global $wpdb;

            // Validate and parse request body
            $body = SurveyX_Validation_Helper::validate_json_body( $request );

            if ( is_wp_error( $body ) ) {
                return SurveyX_Validation_Helper::error_response( $body );
            }

            // Extract and validate required fields
            $survey_id = SurveyX_Validation_Helper::validate_survey_id( $body );
            if ( is_wp_error( $survey_id ) ) {
                return SurveyX_Validation_Helper::error_response( $survey_id );
            }

            $question_id = SurveyX_Validation_Helper::validate_question_id( $body );
            if ( is_wp_error( $question_id ) ) {
                return SurveyX_Validation_Helper::error_response( $question_id );
            }

            $respondent_id = SurveyX_Validation_Helper::sanitize_uuid( $body['respondent_id'] ?? '' );

            if ( empty( $respondent_id ) ) {
                return new WP_REST_Response( [
                    'message' => esc_html__( 'Invalid respondent ID', 'surveyx-builder' ),
                ], 400 );
            }

            // Get active session
            $session = SurveyX_Session_Manager::get_active_session( $survey_id, $respondent_id );
            if ( ! $session || 'completed' === $session->session_status ) {
                return new WP_REST_Response( ['ok' => true], 200 );
            }

            // Check if response already exists for this question
            if ( SurveyX_Db::has_responses_for_question( $session->id, $question_id, $respondent_id ) ) {
                return new WP_REST_Response( ['ok' => true], 200 );
            }

            $now = surveyx_get_utc_now();

            // Create 'seen' response - status updated to 'answered' or 'skipped_optional' by /progress
            SurveyX_Db::create_seen_response(
                $session->id,
                $survey_id,
                $question_id,
                $respondent_id,
                'seen',
                $now
            );

            // Update current_question_id for accurate drop-off tracking
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}surveyx_sessions
				SET current_question_id = %d, last_activity_at = %s
				WHERE survey_id = %d AND respondent_id = %s",
                $question_id,
                $now,
                $survey_id,
                $respondent_id
            ) );

            return new WP_REST_Response( [
                'ok' => true,
            ], 200 );
        }
    }
}
