<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Progress_Handler', false ) ) {
    /**
     * Handles the /progress endpoint - creates responses and updates session progress.
     *
     * @since 1.0.0
     */
    class SurveyX_Progress_Handler
    {
        /**
         * Main handler for update_progress endpoint.
         *
         * @param WP_REST_Request $request The REST request object.
         * @return WP_REST_Response Response indicating success or failure.
         */
        public static function handle( WP_REST_Request $request )
        {
            // Validate and parse request body
            $body = SurveyX_Validation_Helper::validate_json_body( $request );
            if ( is_wp_error( $body ) ) {
                return SurveyX_Validation_Helper::error_response( $body );
            }

            // Extract and validate IDs
            $survey_id = SurveyX_Validation_Helper::validate_survey_id( $body );
            if ( is_wp_error( $survey_id ) ) {
                return SurveyX_Validation_Helper::error_response( $survey_id );
            }

            $question_id = SurveyX_Validation_Helper::validate_question_id( $body );
            if ( is_wp_error( $question_id ) ) {
                return SurveyX_Validation_Helper::error_response( $question_id );
            }

            // Extract request data
            $answer_ids    = $body['answer_ids'] ?? [];
            $content       = (array) ( $body['content'] ?? [] );
            $respondent_id = SurveyX_Validation_Helper::sanitize_uuid( $body['respondent_id'] ?? '' );

            // Get question content for validation
            $question_content = SurveyX_Db::get_question_content( $question_id );
            $question_type    = $question_content['type'] ?? 'check_box';
            $is_required      = $question_content['is_required'] ?? false;

            // Validate response based on question type
            $validation_result = self::validate_response( $question_type, $is_required, $answer_ids, $content, $question_content );
            if ( is_wp_error( $validation_result ) ) {
                return SurveyX_Validation_Helper::error_response( $validation_result );
            }

            // Get active session (authentication already validated during /init)
            $session = SurveyX_Session_Manager::get_active_session( $survey_id, $respondent_id );
            if ( ! $session ) {
                return new WP_REST_Response( [
                    'message' => esc_html__( 'Session not found. Please refresh and try again.', 'surveyx-builder' ),
                ], 400 );
            }

            // Create response and update session
            $result = self::create_response_and_update_session(
                $session,
                $survey_id,
                $question_id,
                $answer_ids,
                $content,
                $respondent_id
            );

            if ( is_wp_error( $result ) ) {
                return SurveyX_Validation_Helper::error_response( $result );
            }

            return new WP_REST_Response( [
                'ok'      => true,
                'message' => esc_html__( 'Progress saved successfully!', 'surveyx-builder' ),
            ], 200 );
        }

        /**
         * Validate response based on question type.
         *
         * @param string $question_type    Question type.
         * @param bool   $is_required      Whether question is required.
         * @param array  $answer_ids       Array of answer IDs.
         * @param array  $content          Response content.
         * @param array  $question_content Question content with settings.
         * @return true|WP_Error True if valid, WP_Error on failure.
         */
        private static function validate_response( $question_type, $is_required, $answer_ids, &$content, $question_content = [] )
        {
            if ( 'text_input' === $question_type ) {
                $validation = SurveyX_Validation_Helper::validate_text_input( $content, $is_required );

                if ( is_wp_error( $validation ) ) {
                    return $validation;
                }

                // Set empty message for non-required empty responses
                if ( ! $is_required && ( ! isset( $content['message'] ) || empty( trim( $content['message'] ) ) ) ) {
                    $content = ['message' => ''];
                }
            } else {
                // For non-text questions, validate answer_ids only if required
                if ( $is_required && empty( $answer_ids ) ) {
                    return new WP_Error(
                        'missing_answer',
                        esc_html__( 'Please select an answer', 'surveyx-builder' ),
                        ['status' => 400]
                    );
                }
            }

            return true;
        }

        /**
         * Determine response status based on question type, required flag, and response data.
         *
         * @param string $question_type Question type (text_input, check_box, etc).
         * @param bool   $is_required   Whether question is required.
         * @param array  $answer_ids    Array of selected answer IDs.
         * @param array  $content       Response content.
         * @return string Response status: 'answered' or 'skipped_optional'.
         */
        private static function determine_response_status( $question_type, $is_required, $answer_ids, $content )
        {
            // If question is required, it's always 'answered'
            if ( $is_required ) {
                return 'answered';
            }

            // For text input questions: check if content is empty
            if ( 'text_input' === $question_type ) {
                $message = trim( $content['message'] ?? '' );
                return empty( $message ) ? 'skipped_optional' : 'answered';
            }

            // For contact_info: check if all fields are empty
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

            // For opinion_scale and rating: check if value is set
            if ( 'opinion_scale' === $question_type || 'rating' === $question_type ) {
                return ( isset( $content['value'] ) && null !== $content['value'] ) ? 'answered' : 'skipped_optional';
            }

            // For date: check if all fields are filled
            if ( 'date' === $question_type ) {
                $month = trim( $content['month'] ?? '' );
                $day   = trim( $content['day'] ?? '' );
                $year  = trim( $content['year'] ?? '' );
                return ( ! empty( $month ) && ! empty( $day ) && ! empty( $year ) ) ? 'answered' : 'skipped_optional';
            }

            // For choice questions: check if any answer is selected
            return empty( $answer_ids ) ? 'skipped_optional' : 'answered';
        }

        /**
         * Create response and update session progress.
         *
         * @param object $session       Session object.
         * @param int    $survey_id     Survey ID.
         * @param int    $question_id   Question ID.
         * @param array  $answer_ids    Array of answer IDs.
         * @param array  $content       Response content.
         * @param string $respondent_id Respondent UUID.
         * @return true|WP_Error True on success, WP_Error on failure.
         */
        private static function create_response_and_update_session(
            $session,
            $survey_id,
            $question_id,
            $answer_ids,
            $content,
            $respondent_id
        ) {
            $session_id = $session->id;

            // Validate answer IDs
            $answer_ids = SurveyX_Validation_Helper::validate_answer_ids( $answer_ids, $question_id );

            // Get question content for determining response status
            $question_content = SurveyX_Db::get_question_content( $question_id );
            $question_type    = $question_content['type'] ?? 'check_box';
            $is_required      = $question_content['is_required'] ?? false;

            // Determine response status
            $response_status = self::determine_response_status( $question_type, $is_required, $answer_ids, $content );

            // Check if user already answered this question (allow_return or editing mode)
            // If so, get viewed_at before deleting, then delete old responses
            $already_answered = SurveyX_Db::has_responses_for_question( $session_id, $question_id, $respondent_id );
            $viewed_at        = null;

            if ( $already_answered ) {
                // Preserve viewed_at from existing response
                $viewed_at = SurveyX_Db::get_response_viewed_at( $session_id, $question_id, $respondent_id );
                // Delete existing responses
                SurveyX_Db::delete_responses_by_question( $session_id, $question_id, $respondent_id );
            }

            // Create response with status (preserving viewed_at if exists)
            $result = SurveyX_Db::create_responses(
                $session_id,
                $survey_id,
                $question_id,
                $answer_ids,
                $content,
                $respondent_id,
                $response_status,
                $question_type,
                $viewed_at
            );

            if ( ! $result ) {
                return new WP_Error(
                    'save_failed',
                    esc_html__( 'Failed to save response.', 'surveyx-builder' ),
                    ['status' => 500]
                );
            }

            // If question type is contact_info, save contact data to respondents table
            if ( 'contact_info' === $question_type && ! empty( $content ) ) {
                SurveyX_Session_Manager::update_contact_info(
                    $respondent_id,
                    $content
                );
            }

            // Update session progress - count from responses table
            $answered_count = SurveyX_Db::count_answered_questions( $session->id, $respondent_id );
            SurveyX_Session_Manager::update_session_progress(
                $survey_id,
                $respondent_id,
                $question_id,
                $answered_count
            );

            // Note: Session completion is handled by /complete-session endpoint
            // called from frontend when navigating to closing page

            return true;
        }
    }
}
