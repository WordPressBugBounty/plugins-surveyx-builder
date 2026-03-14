<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

/**
 * Registers cron events for SurveyX analytics processing.
 */
function surveyx_register_cron_events() {
	if ( ! wp_next_scheduled( 'surveyx_process_sessions_hourly' ) ) {
		wp_schedule_event( time(), 'hourly', 'surveyx_process_sessions_hourly' );
	}
}

/**
 * Unregisters cron events when plugin is deactivated.
 */
function surveyx_unregister_cron_events() {
	$timestamp = wp_next_scheduled( 'surveyx_process_sessions_hourly' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'surveyx_process_sessions_hourly' );
	}
}

/**
 * Main cron handler - Processes sessions every hour.
 * Only handles cleanup tasks. Summary updates are on-demand.
 */
function surveyx_process_sessions_cron() {
	SurveyX_Session_Manager::mark_stale_sessions_as_dropped();

	/**
	 * Action hook for Pro to extend cron processing.
	 * Used for respondent sync and other Pro-only tasks.
	 */
	do_action( 'surveyx_after_process_sessions' );
}

add_action( 'surveyx_process_sessions_hourly', 'surveyx_process_sessions_cron' );
