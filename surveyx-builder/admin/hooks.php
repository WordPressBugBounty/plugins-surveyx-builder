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

	$new_questions     = $data['questions'] ?? [];
	$new_answers       = $data['answers'] ?? [];
	$removed_questions = $data['remove_question_ids'] ?? [];
	$removed_answers   = $data['remove_answer_ids'] ?? [];

	// Skip if no content data at all (not an editor save)
	if ( empty( $new_questions ) && empty( $new_answers ) && empty( $removed_questions ) && empty( $removed_answers ) ) {
		return;
	}

	// A structural change is a deletion, an addition (a new/temp id in the payload), or a
	// net count change. A pure text edit (same items, same count) must NOT reset. Count
	// alone misses a swap (delete 1 + add 1 keeps the count equal), so also honor the
	// explicit delete lists and detect any temp id among the new items.
	$has_deletion = ! empty( $removed_questions ) || ! empty( $removed_answers );

	$has_addition = false;
	foreach ( array_merge( $new_questions, $new_answers ) as $item ) {
		if ( isset( $item['id'] ) && SurveyX_Admin_Helpers::is_temp_id( $item['id'] ) ) {
			$has_addition = true;
			break;
		}
	}

	$count_changed = SurveyX_Db::has_count_changed( $survey_id, $new_questions, $new_answers );

	if ( ! $has_deletion && ! $has_addition && ! $count_changed ) {
		return;
	}

	SurveyX_Db::reset_sessions_for_survey( $survey_id );
}

add_action( 'surveyx_before_doing_save', 'surveyx_handle_revote_on_update' );
