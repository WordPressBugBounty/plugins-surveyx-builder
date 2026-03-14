<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

/**
 * Updates summary for a survey if not cached.
 * Sets transient to prevent repeated updates within 6 hours.
 *
 * @param int  $survey_id Survey ID.
 * @param bool $force     Force update, ignoring cache.
 * @return bool True if updated, false if cached.
 */
function surveyx_maybe_update_summary( $survey_id, $force = false ) {
	$cache_key = 'surveyx_summary_' . $survey_id;

	if ( ! $force && get_transient( $cache_key ) ) {
		return false;
	}

	surveyx_update_summary( $survey_id );
	set_transient( $cache_key, time(), 6 * HOUR_IN_SECONDS );

	return true;
}

/**
 * Updates summary table for a specific survey.
 *
 * @param int $survey_id Survey ID.
 */
function surveyx_update_summary( $survey_id ) {
	$stats                      = SurveyX_Db::get_session_stats( $survey_id );
	$most_common_dropoff        = SurveyX_Db::get_most_common_dropoff( $survey_id );
	$question_seen_counts       = SurveyX_Db::get_question_seen_counts( $survey_id );
	$answer_votes               = SurveyX_Db::get_answer_votes( $survey_id );
	$response_count_by_question = SurveyX_Db::get_response_count_by_question( $survey_id );

	$completion_rate = $stats['starts'] > 0 ? ( $stats['completions'] / $stats['starts'] ) * 100 : 0;
	$dropoff_rate    = $stats['starts'] > 0 ? ( $stats['dropoffs'] / $stats['starts'] ) * 100 : 0;

	SurveyX_Db::upsert_summary(
		$survey_id,
		[
			'total_views'                     => $stats['views'],
			'total_starts'                    => $stats['starts'],
			'total_completions'               => $stats['completions'],
			'total_dropoffs'                  => $stats['dropoffs'],
			'completion_rate'                 => $completion_rate,
			'dropoff_rate'                    => $dropoff_rate,
			'average_time_seconds'            => $stats['avg_time'],
			'most_common_dropoff_question_id' => $most_common_dropoff,
			'question_seen_counts'            => wp_json_encode( $question_seen_counts ),
			'answer_votes_json'               => wp_json_encode( $answer_votes ),
			'response_count_by_question'      => wp_json_encode( $response_count_by_question ),
		]
	);
}
