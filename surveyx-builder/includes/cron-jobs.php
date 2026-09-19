<?php

/** Don't load directly */
defined( 'ABSPATH' ) || exit;

/**
 * Registers cron events for SurveyX analytics processing.
 */
if ( ! function_exists( 'surveyx_register_cron_events' ) ) {
	function surveyx_register_cron_events() {
		// Core guards its own init-scheduled events the same way — see
		// wp_schedule_delete_old_privacy_export_files() in wp-includes/functions.php.
		if ( wp_installing() ) {
			return;
		}

		if ( ! wp_next_scheduled( 'surveyx_process_sessions_hourly' ) ) {
			wp_schedule_event( time(), 'hourly', 'surveyx_process_sessions_hourly' );
		}
	}

	// Self-heal: the activation hook does not fire on a plugin update, so an install whose
	// activation never registered the event would run forever without the hourly cleanup.
	// wp_next_scheduled() only reads the autoloaded `cron` option, so this adds no query.
	add_action( 'init', 'surveyx_register_cron_events' );
}

/**
 * Unregisters cron events when plugin is deactivated.
 */
if ( ! function_exists( 'surveyx_unregister_cron_events' ) ) {
	function surveyx_unregister_cron_events() {
		$timestamp = wp_next_scheduled( 'surveyx_process_sessions_hourly' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'surveyx_process_sessions_hourly' );
		}
	}
}

/**
 * Main cron handler - runs hourly on surveyx_process_sessions_hourly.
 *
 * Its one job is marking abandoned sessions dropped_off. It recomputes NO analytics:
 * every analytics figure is measured from the raw tables on a cache miss, and no summary
 * recount exists any more.
 *
 * This event also carries weight beyond its own work: the migration runner's DDL gate
 * refuses to run ALTERs on a visitor's request, so on a site with WP-Cron enabled this
 * hourly tick is what actually lands a pending schema upgrade. Unscheduling it would
 * strand that work on any install whose owner never opens wp-admin.
 */
if ( ! function_exists( 'surveyx_process_sessions_cron' ) ) {
	function surveyx_process_sessions_cron() {
		SurveyX_Session_Manager::mark_stale_sessions_as_dropped();

		/** Fires after session processing; Pro hooks its orphaned-upload sweep here. */
		do_action( 'surveyx_after_process_sessions' );
	}

	add_action( 'surveyx_process_sessions_hourly', 'surveyx_process_sessions_cron' );
}
