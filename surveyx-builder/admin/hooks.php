<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

/**
 * Reset session status when survey content changes.
 * Only triggers if allow_revote_on_update is enabled for this survey
 * AND actual content (questions/answers) has changed.
 *
 * @param array $data The request body data before processing.
 * @return void
 */
function surveyx_handle_revote_on_update( $data ) {
	$survey_id = absint( $data['id'] ?? 0 );

	if ( empty( $survey_id ) ) {
		return;
	}

	// Get survey settings (per-survey, not global)
	$survey = SurveyX_Admin_Db::get_survey_by_id( $survey_id );

	if ( ! $survey ) {
		return;
	}

	$settings = ! empty( $survey['settings'] ) ? json_decode( $survey['settings'], true ) : [];

	if ( empty( $settings['allow_revote_on_update'] ) ) {
		return;
	}

	$new_questions = $data['questions'] ?? [];
	$new_answers   = $data['answers'] ?? [];

	// Skip if no content data (not an editor save)
	if ( empty( $new_questions ) && empty( $new_answers ) ) {
		return;
	}

	// Check if count changed (question/answer added or deleted)
	if ( ! SurveyX_Db::has_count_changed( $survey_id, $new_questions, $new_answers ) ) {
		return;
	}

	SurveyX_Db::reset_sessions_for_survey( $survey_id );
}

add_action( 'surveyx_before_doing_save', 'surveyx_handle_revote_on_update' );
