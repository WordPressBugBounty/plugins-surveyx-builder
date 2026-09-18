<?php

/**
 * SurveyX Public Survey Page
 *
 * Serves a survey as a standalone page at /{base}/{id}/{slug}/ so it can be
 * shared as a link instead of being embedded with the [surveyx] shortcode.
 *
 * @package SurveyX
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SurveyX_Public_Page', false ) ) {
	class SurveyX_Public_Page {

		private static $instance;

		/**
		 * Per-request cache of the row that decides whether a survey has a page.
		 *
		 * Keyed by survey id; the value is the row SurveyX_Shortcode_Handler's access
		 * gate reads (`settings`, `status`, `s_mode`, `is_published`), or null when the
		 * id has no row at all.
		 *
		 * @var array
		 */
		private static $page_rows = [];

		/** @var string Option holding the URL base segment. */
		public const BASE_OPTION = 'surveyx_page_base';

		/** @var string Option holding the standalone page on/off switch. */
		public const ENABLED_OPTION = 'surveyx_page_enabled';

		/** @var string Option holding the previous base, kept for 301s. */
		public const PREV_BASE_OPTION = 'surveyx_page_base_prev';

		/** @var string Option holding the rewrite generation that was last flushed. */
		public const REWRITE_OPTION = 'surveyx_rewrite_version';

		/** @var string Bumped whenever the rule shape changes, to force one flush. */
		public const REWRITE_VERSION = '1';

		/** @var string Base used when the option is missing or invalid. */
		public const DEFAULT_BASE = 'survey';

		/** @var string Settings key holding the per-survey page mode. */
		public const MODE_KEY = 'page_mode';

		/** @var string Page mode: follow the site-wide switch. */
		public const MODE_DEFAULT = 'default';

		/** @var string Page mode: always has a page, whatever the site-wide switch says. */
		public const MODE_PAGE = 'page';

		/** @var string Page mode: never has a page, whatever the site-wide switch says. */
		public const MODE_SHORTCODE = 'shortcode';

		/**
		 * Slugs that would collide with WordPress itself.
		 *
		 * Not duplicated in JavaScript: the settings endpoint returns this list as
		 * `page_reserved_bases` and Settings.vue validates against that payload.
		 *
		 * @var array
		 */
		public const RESERVED_BASES = [
			'wp-admin',
			'wp-json',
			'wp-content',
			'wp-includes',
			'feed',
			'author',
			'category',
			'tag',
			'page',
			'comments',
		];

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		protected function __construct() {
			self::$instance = $this;

			add_action( 'init', [ $this, 'add_rewrite_rules' ] );

			// 'wp_loaded', not 'init': a flush stores the WHOLE site's rule set as it
			// stands, so it must not run before every plugin has registered its rules.
			// Core defers an early flush here anyway, but flush_rewrite_rules() then
			// returns before the work is done and maybe_flush_rules() stamps a flush
			// that has not happened yet.
			add_action( 'wp_loaded', [ $this, 'maybe_flush_rules' ] );
			add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
			add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		}

		/**
		 * Whether standalone survey pages are served at all.
		 *
		 * A missing option reads as enabled, so an install updated from a version
		 * that predates this switch keeps its survey pages without touching anything.
		 * Only an explicit '0' turns them off.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			return '0' !== (string) get_option( self::ENABLED_OPTION, '1' );
		}

		/**
		 * Normalises a raw page-mode value to one of the three allowed modes.
		 *
		 * Anything unrecognised — empty, legacy, or junk from a hand-edited settings
		 * blob — collapses to MODE_DEFAULT and NEVER to MODE_SHORTCODE: bad data must
		 * not silently take a working survey offline.
		 *
		 * sanitize_key() is deliberately NOT used: it also strips INNER characters, so a
		 * mangled 'sho rtcode' would be rescued into a real MODE_SHORTCODE — junk
		 * resolving to the one value that withholds a page.
		 *
		 * @param mixed $raw Raw value, typically straight out of a settings blob.
		 * @return string One of MODE_DEFAULT, MODE_PAGE or MODE_SHORTCODE.
		 */
		public static function sanitize_page_mode( $raw ) {
			$mode = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';

			return in_array( $mode, [ self::MODE_PAGE, self::MODE_SHORTCODE ], true )
				? $mode
				: self::MODE_DEFAULT;
		}

		/**
		 * Whether ONE survey has a standalone page.
		 *
		 * The per-survey mode is an override, not a filter: MODE_PAGE serves the page
		 * even with the site-wide switch off, MODE_SHORTCODE withholds it even with
		 * the switch on. Only MODE_DEFAULT consults is_enabled().
		 *
		 * An absent key is NOT "off": every survey saved before this setting existed
		 * carries no `page_mode` and must keep the site-wide switch alone, so absent,
		 * empty and malformed all read as MODE_DEFAULT.
		 *
		 * @param mixed $survey Decoded settings array, or a survey row (object or
		 *                      array) carrying a `settings` member — raw JSON string
		 *                      or already decoded. Anything else reads as default.
		 * @return bool
		 */
		public static function is_enabled_for( $survey ) {
			$settings = self::extract_settings( $survey );
			$mode     = self::sanitize_page_mode( $settings[ self::MODE_KEY ] ?? '' );

			if ( self::MODE_PAGE === $mode ) {
				return true;
			}

			if ( self::MODE_SHORTCODE === $mode ) {
				return false;
			}

			return self::is_enabled();
		}

		/**
		 * Pulls the settings array out of whatever shape the caller had at hand.
		 *
		 * @param mixed $survey Settings array, or a survey row (object|array) whose
		 *                      `settings` member holds raw JSON or a decoded array.
		 * @return array Empty array when nothing usable was passed.
		 */
		protected static function extract_settings( $survey ) {
			if ( is_object( $survey ) ) {
				$survey = get_object_vars( $survey );
			}

			if ( ! is_array( $survey ) ) {
				return [];
			}

			// A plain settings array only lands here if it held a `settings` key itself,
			// which no save path writes.
			if ( array_key_exists( 'settings', $survey ) ) {
				$settings = $survey['settings'];

				if ( is_string( $settings ) ) {
					$settings = json_decode( $settings, true );
				}

				return is_array( $settings ) ? $settings : [];
			}

			return $survey;
		}

		/**
		 * Loads one survey's page row, cached for the rest of the request.
		 *
		 * @param int $survey_id Survey ID.
		 * @return object|null The page row, or null when the survey does not exist.
		 */
		protected static function fetch_page_row( $survey_id ) {
			$survey_id = absint( $survey_id );

			if ( empty( $survey_id ) ) {
				return null;
			}

			if ( ! array_key_exists( $survey_id, self::$page_rows ) ) {
				self::prime_page_cache( [ $survey_id ] );
			}

			return self::$page_rows[ $survey_id ];
		}

		/**
		 * Warms the page-row cache for a batch of surveys, so a list screen resolves page
		 * URLs for a whole listing in this one query however many rows it shows.
		 *
		 * The `content` LONGTEXT is deliberately NOT selected: only its emptiness is
		 * asked, so that comparison is pushed into SQL and returns one flag instead of
		 * dragging every survey's questions across the wire. `content <> ''` yields NULL
		 * on a NULL column, so the IS NOT NULL half is what makes the flag match the PHP
		 * it mirrors in SurveyX_Db::get_survey_render_row(), `'' !== (string) $content`.
		 *
		 * @param array $survey_ids Survey IDs.
		 * @return void
		 */
		public static function prime_page_cache( array $survey_ids ) {
			$ids = array_values(
				array_diff(
					array_unique( array_filter( array_map( 'absint', $survey_ids ) ) ),
					array_keys( self::$page_rows )
				)
			);

			if ( empty( $ids ) ) {
				return;
			}

			global $wpdb;

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// Missing rows must still be cached, or every lookup for a deleted survey
			// would re-query. Seed them null first, then overwrite what came back.
			foreach ( $ids as $id ) {
				self::$page_rows[ $id ] = null;
			}

			// Direct query: no core API reads this table. Interpolation is the %d list
			// built above, and the results are cached in self::$page_rows for the request.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, settings, status, s_mode, ( content IS NOT NULL AND content <> '' ) AS has_content
					FROM {$wpdb->prefix}surveyx_surveys
					WHERE id IN ({$placeholders})",
					...$ids
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( (array) $rows as $row ) {
				$decoded = json_decode( (string) $row->settings, true );

				self::$page_rows[ absint( $row->id ) ] = (object) [
					'settings'     => is_array( $decoded ) ? $decoded : null,
					'status'       => (string) $row->status,
					's_mode'       => (string) $row->s_mode,
					'is_published' => ( 'active' === (string) $row->status && ! empty( $row->has_content ) ),
				];
			}
		}

		/**
		 * Builds a page row from a survey the caller already holds, so a screen that just
		 * loaded its survey pays no query. Returns FALSE — not null — when the shape
		 * handed in cannot supply one: null already means "this survey does not exist".
		 *
		 * @param mixed $survey Survey row: an admin array (raw JSON `settings` and
		 *                      `content`), or a render row (decoded members plus a
		 *                      pre-computed `is_published`).
		 * @return object|false The page row, or false when $survey cannot supply one.
		 */
		protected static function build_page_row( $survey ) {
			if ( is_object( $survey ) ) {
				$survey = get_object_vars( $survey );
			}

			if ( ! is_array( $survey ) || ! isset( $survey['status'] ) || ! array_key_exists( 'settings', $survey ) ) {
				return false;
			}

			$has_flag = array_key_exists( 'is_published', $survey );

			if ( ! $has_flag && ! array_key_exists( 'content', $survey ) ) {
				return false;
			}

			$settings = $survey['settings'];

			if ( is_string( $settings ) ) {
				$settings = json_decode( $settings, true );
			}

			return (object) [
				'settings'     => is_array( $settings ) ? $settings : null,
				'status'       => (string) $survey['status'],
				's_mode'       => (string) ( $survey['s_mode'] ?? '' ),
				'is_published' => $has_flag
					? (bool) $survey['is_published']
					: ( 'active' === (string) $survey['status'] && self::has_content( $survey['content'] ) ),
			];
		}

		/**
		 * Whether a survey's `content` counts as non-empty.
		 *
		 * Mirrors SurveyX_Db::get_survey_render_row(), which measures the RAW column with
		 * `'' !== (string) $content`. A decoded value is measured on its own terms so an
		 * array never lands in a string cast.
		 *
		 * @param mixed $content Raw JSON string, decoded value, or null.
		 * @return bool
		 */
		protected static function has_content( $content ) {
			if ( is_array( $content ) ) {
				return ! empty( $content );
			}

			return is_scalar( $content ) && '' !== (string) $content;
		}

		/**
		 * Whether a request for this survey's standalone page would actually render
		 * it, rather than answer 404.
		 *
		 * maybe_render() below is the specification; this repeats its gates IN THE SAME
		 * ORDER, so that no admin screen advertises a URL which then 404s. Costs nothing
		 * when given a survey, one cached row otherwise — batch with prime_page_cache().
		 *
		 * @param int   $survey_id Survey ID.
		 * @param mixed $survey    Optional. A survey the caller already holds, in
		 *                         either shape build_page_row() accepts.
		 * @return bool
		 */
		public static function renders_page( $survey_id, $survey = null ) {
			$row = self::build_page_row( $survey );

			if ( false === $row ) {
				$row = self::fetch_page_row( $survey_id );
			}

			// No such survey, or a settings blob that is absent or undecodable.
			if ( is_null( $row ) || is_null( $row->settings ) ) {
				return false;
			}

			if ( ! self::is_enabled_for( $row ) ) {
				return false;
			}

			if ( 'active' !== $row->status ) {
				return false;
			}

			if ( SurveyX_Db::survey_needs_pro( $survey_id, $row->s_mode ) ) {
				return false;
			}

			// Licence gate. A survey it closes has no page — maybe_render() 404s it — and
			// advertising a page_url that 404s is what this method exists to prevent. Runs
			// after the row because the answer is per survey. function_exists() keeps the
			// branch identical in BOTH copies of this file — inert in Free, where the
			// function is never defined.
			if ( function_exists( 'surveyx_license_blocks_survey' ) && surveyx_license_blocks_survey( $survey_id, $row->s_mode ) ) {
				return false;
			}

			return self::gate_allows( $row );
		}

		/**
		 * Whether the shortcode's access gate leaves this survey a page.
		 *
		 * Anything short of GATE_PASS is a survey nobody can usefully answer and
		 * maybe_render() 404s it — exactly the set that must not be advertised as a link.
		 * GATE_LOGIN_REQUIRED is the exception: that page answers with the login notice
		 * instead of pretending the URL does not exist, so it keeps its link. The
		 * constant does not exist in Free's handler, which is what the defined() guard
		 * tests; the two copies of this file stay byte-identical here on purpose, since a
		 * gate that silently differs between them is how they drift.
		 *
		 * The class_exists() guard fails OPEN: the handler is loaded beside this class on
		 * every request, and should that stop being true it advertises rather than hiding
		 * pages that work.
		 *
		 * @param object $row Page row.
		 * @return bool
		 */
		protected static function gate_allows( $row ) {
			if ( ! class_exists( 'SurveyX_Shortcode_Handler' ) ) {
				return true;
			}

			$allowed = [ SurveyX_Shortcode_Handler::GATE_PASS ];

			if ( defined( 'SurveyX_Shortcode_Handler::GATE_LOGIN_REQUIRED' ) ) {
				$allowed[] = SurveyX_Shortcode_Handler::GATE_LOGIN_REQUIRED;
			}

			return in_array( SurveyX_Shortcode_Handler::get_access_gate( $row ), $allowed, true );
		}

		/**
		 * Stores the standalone page on/off switch.
		 *
		 * The rewrite rules are registered either way, so this deliberately leaves the
		 * rewrite stamp alone: toggling costs one option write and never a flush.
		 *
		 * Deliberately NOT autoloaded, unlike the base and rewrite-stamp options:
		 * is_enabled() is reached only once a request has already matched a survey URL,
		 * so most requests never read it and it would only bloat `alloptions`.
		 *
		 * @param mixed $raw Raw value from the settings form.
		 * @return bool The stored state.
		 */
		public static function save_enabled( $raw ) {
			$enabled = (bool) rest_sanitize_boolean( $raw );

			update_option( self::ENABLED_OPTION, $enabled ? '1' : '0', false );

			return $enabled;
		}

		/**
		 * Returns the sanitized URL base segment.
		 *
		 * @return string
		 */
		public static function get_base() {
			$base = sanitize_title( (string) get_option( self::BASE_OPTION, self::DEFAULT_BASE ) );

			if ( empty( $base ) || in_array( $base, self::RESERVED_BASES, true ) ) {
				return self::DEFAULT_BASE;
			}

			return $base;
		}

		/**
		 * Validates and stores a new URL base, keeping the previous one for 301s.
		 *
		 * An invalid value is refused and the current base kept, so a bad edit can never
		 * take the survey pages down.
		 *
		 * `blocked` separates the two kinds of message this returns: true means the
		 * posted value was REFUSED and nothing was stored, false means it was stored and
		 * the warning is only advisory. Both travel in the same `warning` field, so
		 * without the flag the settings screen styles a refusal as a successful save.
		 *
		 * @param string $raw Raw base from the settings form.
		 * @return array{base:string,warning:string,blocked:bool}
		 */
		public static function save_base( $raw ) {
			$current = self::get_base();
			$base    = sanitize_title( (string) $raw );

			if ( empty( $base ) ) {
				return [
					'base'    => $current,
					'warning' => __( 'The URL base cannot be empty. The previous value was kept.', 'surveyx-builder' ),
					'blocked' => true,
				];
			}

			if ( in_array( $base, self::RESERVED_BASES, true ) ) {
				return [
					'base'    => $current,
					'warning' => __( 'That URL base is reserved by WordPress. The previous value was kept.', 'surveyx-builder' ),
					'blocked' => true,
				];
			}

			if ( $base === $current ) {
				return [
					'base'    => $base,
					'warning' => '',
					'blocked' => false,
				];
			}

			$warning = '';

			// Survey rules are registered with 'top', so they win over a page that
			// happens to live at the same slug. Worth a warning, not a refusal.
			if ( get_page_by_path( $base ) ) {
				$warning = __( 'A page with this slug already exists. Survey pages will take priority over it.', 'surveyx-builder' );
			}

			// Autoloaded: add_rewrite_rules() and maybe_flush_rules() read both on every
			// front-end, admin, REST and cron request, so keeping them out of
			// `alloptions` costs a SELECT each without a persistent object cache.
			update_option( self::PREV_BASE_OPTION, $current, true );
			update_option( self::BASE_OPTION, $base, true );

			// Clearing the stamp makes maybe_flush_rules() flush exactly once, on the
			// next request, with the new rules already registered.
			delete_option( self::REWRITE_OPTION );

			return [
				'base'    => $base,
				'warning' => $warning,
				'blocked' => false,
			];
		}

		/**
		 * Builds the canonical URL of a survey page, when there is one to link to.
		 *
		 * Returns an EMPTY STRING whenever a request for that page would answer 404, so
		 * no caller has to re-implement the rules to decide whether to show a link — not
		 * just the page-mode rule but the whole of maybe_render()'s admission test (see
		 * renders_page()). Callers holding the survey should pass it as $survey.
		 *
		 * @param int    $survey_id Survey ID.
		 * @param string $title     Survey title, may contain HTML.
		 * @param mixed  $survey    Optional. Survey row, as accepted by
		 *                          build_page_row(). Null looks the row up from the
		 *                          database (cached, and batchable via
		 *                          prime_page_cache()).
		 * @return string Canonical URL, or '' when this survey has no usable page.
		 */
		public static function get_survey_url( $survey_id, $title = '', $survey = null ) {
			$survey_id = absint( $survey_id );

			if ( ! self::renders_page( $survey_id, $survey ) ) {
				return '';
			}

			return self::build_url( $survey_id, $title );
		}

		/**
		 * Assembles a survey page URL, with no gate of its own.
		 *
		 * Only maybe_render(), which has just run every gate itself and so knows the page
		 * exists, calls this directly; everyone else goes through get_survey_url() and
		 * gets '' for a survey whose page would 404.
		 *
		 * @param int    $survey_id Survey ID.
		 * @param string $title     Survey title, may contain HTML.
		 * @return string Canonical URL.
		 */
		protected static function build_url( $survey_id, $title = '' ) {
			$survey_id = absint( $survey_id );
			$slug      = sanitize_title( wp_strip_all_tags( (string) $title ) );

			if ( ! get_option( 'permalink_structure' ) ) {
				return add_query_arg( 'surveyx_id', $survey_id, home_url( '/' ) );
			}

			$path = self::get_base() . '/' . $survey_id . '/';

			if ( ! empty( $slug ) ) {
				$path .= $slug . '/';
			}

			return home_url( $path );
		}

		/**
		 * Registers the survey page rules, plus a redirect rule for the previous base.
		 *
		 * @return void
		 */
		public function add_rewrite_rules() {
			foreach ( self::get_rule_patterns() as $pattern ) {
				add_rewrite_rule(
					$pattern,
					'index.php?surveyx_id=$matches[1]&surveyx_slug=$matches[2]',
					'top'
				);
			}
		}

		/**
		 * The rewrite patterns this plugin registers: the current base, plus the previous
		 * one for as long as it is still redirected.
		 *
		 * Shared with resolve_request(), which honours `surveyx_id` only on a request
		 * that actually matched one of them.
		 *
		 * @return string[]
		 */
		protected static function get_rule_patterns() {
			$base     = self::get_base();
			$patterns = [ '^' . $base . '/([0-9]+)(?:/([^/]+))?/?$' ];
			$prev     = sanitize_title( (string) get_option( self::PREV_BASE_OPTION, '' ) );

			if ( ! empty( $prev ) && $prev !== $base ) {
				$patterns[] = '^' . $prev . '/([0-9]+)(?:/([^/]+))?/?$';
			}

			return $patterns;
		}

		/**
		 * Flushes rules once per rewrite generation or base change.
		 *
		 * Hooked on 'wp_loaded' (see the constructor), so the flush happens inline and
		 * the stamp below is written only once the new rules are genuinely stored. They
		 * are also live in memory for the rest of the request, so a changed base resolves
		 * on this very request rather than opening a window where the stored set is
		 * behind the option.
		 *
		 * Concurrency: this can fire on any request, anonymous front-end hits included,
		 * so a base change on a busy site would otherwise have several requests run the
		 * expensive full regeneration before the first wrote the stamp. A non-blocking
		 * advisory lock lets exactly ONE do the work; the rest skip and serve from the
		 * rules already stored.
		 *
		 * @return void
		 */
		public function maybe_flush_rules() {
			global $wpdb;

			$stamp = self::REWRITE_VERSION . ':' . self::get_base() . ':' . (string) get_option( self::PREV_BASE_OPTION, '' );

			if ( get_option( self::REWRITE_OPTION ) === $stamp ) {
				return;
			}

			$lock = substr( $wpdb->prefix . 'surveyx_flush', 0, 64 );

			// Advisory lock, not a data read: there is no core API for it and caching it
			// would defeat the point.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 0 ) ) ) {
				return;
			}

			try {
				// Re-read under the lock: the winner may have finished between the check
				// above and this acquisition.
				if ( get_option( self::REWRITE_OPTION ) === $stamp ) {
					return;
				}

				flush_rewrite_rules( false );

				// Stamped only after the flush succeeded, so a request that dies mid-flush
				// leaves the stamp unset and the next one retries.
				update_option( self::REWRITE_OPTION, $stamp, true );
			} finally {
				// Advisory lock release; nothing to cache.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}

		/**
		 * @param array $vars Query vars.
		 * @return array
		 */
		public function add_query_vars( $vars ) {
			$vars[] = 'surveyx_id';
			$vars[] = 'surveyx_slug';

			return $vars;
		}

		/**
		 * Entry point for a survey page request.
		 *
		 * @return void
		 */
		public function maybe_render() {
			$request = $this->resolve_request();

			if ( is_null( $request ) ) {
				return;
			}

			$survey_id = $request['id'];

			// Our own URL, but with no usable id (e.g. /{base}/0/).
			if ( empty( $survey_id ) ) {
				$this->send_404();
			}

			$survey = SurveyX_Db::get_survey_render_row( $survey_id );

			if ( is_null( $survey ) || is_null( $survey->settings ) ) {
				$this->send_404();
			}

			// This survey has no standalone page. The rewrite rules stay registered
			// whatever the switch says, so this request DID match one and `surveyx_id` is
			// still a public query var: simply returning would have WordPress resolve it
			// as the blog home and answer 200. Only an explicit 404 makes the URL stop
			// existing. Checked here, after the row is loaded, because the answer is per
			// survey and not site-wide.
			if ( ! self::is_enabled_for( $survey ) ) {
				$this->send_404();
			}

			if ( 'active' !== $survey->status ) {
				$this->send_404();
			}

			// A Pro-mode survey needs Pro to render, so with only the free plugin there is
			// nothing to show a visitor and for them the page does not exist. An
			// administrator is told why instead, further down — the same answer the
			// [surveyx] shortcode gives, which is why both paths share get_access_gate().
			$needs_pro = SurveyX_Db::survey_needs_pro( $survey->id, $survey->s_mode );

			if ( $needs_pro && ! current_user_can( 'manage_options' ) ) {
				$this->send_404();
			}

			// The [surveyx] shortcode owns these gates; the page path reuses them so both
			// agree on who may answer a survey. Anything the shortcode hides from
			// everyone — missing, settings-less, or GATE_NOT_PUBLISHED (unpublished, or
			// active with no questions) — has no page either and gets the same 404 this
			// path gives a draft. GATE_LOGIN_REQUIRED is the exception: that visitor may
			// take the survey once signed in, so the page answers with the notice below.
			$gate = SurveyX_Shortcode_Handler::get_access_gate( $survey );

			if ( ! in_array(
				$gate,
				[ SurveyX_Shortcode_Handler::GATE_PASS, SurveyX_Shortcode_Handler::GATE_LOGIN_REQUIRED ],
				true
			) ) {
				$this->send_404();
			}

			// Every gate is behind us, so build the URL directly rather than through
			// get_survey_url(), which would only re-run them.
			$canonical = self::build_url( $survey->id, $survey->title );

			if ( $this->should_redirect( $survey, $request['slug'] ) ) {
				wp_safe_redirect( $canonical, 301 );
				exit;
			}

			// Debug builds only: the value reveals nothing the page body does not, but on
			// production it is a free fingerprint of which plugin served the request.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				header( 'X-SurveyX-Page: ' . absint( $survey->id ) );
			}

			// Only an administrator reaches here on a Pro-mode survey; everyone else was
			// 404'd above.
			if ( $needs_pro ) {
				$this->render_document( $survey, $canonical, $this->get_pro_notice( $survey->id ) );
			}

			// A login-required survey keeps its page: the visitor may take it once
			// signed in, so it answers with the shortcode's notice plus a login link
			// instead of pretending the URL does not exist.
			if ( SurveyX_Shortcode_Handler::GATE_LOGIN_REQUIRED === $gate ) {
				$this->render_document( $survey, $canonical, $this->get_login_notice( $canonical ) );
			}

			$this->render_document( $survey, $canonical );
		}

		/**
		 * The "this survey needs Pro" notice, for an administrator.
		 *
		 * Runs the [surveyx] shortcode rather than rebuilding its card here: the shortcode
		 * already answers a Pro-mode survey with exactly this notice for anyone who can
		 * manage options, and one copy of that wording is the point. The row it re-reads
		 * is memoised, so this costs no extra query; the edit links it appends are why
		 * render_document() leaves its own off a notice page.
		 *
		 * @param int $survey_id Survey ID.
		 *
		 * @return string HTML output.
		 */
		protected function get_pro_notice( $survey_id ) {
			return SurveyX_Shortcode_Handler::get_instance()->render_survey( [ 'id' => $survey_id ] );
		}

		/**
		 * The login notice for a logged-out visitor, plus a link back to this page.
		 *
		 * Card AND login link both come from the shortcode handler, so the two render
		 * paths cannot drift apart in what they say or what they let the visitor do.
		 *
		 * @param string $canonical Canonical URL, used as the post-login return.
		 * @return string HTML output.
		 */
		protected function get_login_notice( $canonical ) {
			return SurveyX_Shortcode_Handler::render_login_required_notice( $canonical );
		}

		/**
		 * Resolves the survey this request addresses, or null when it addresses none.
		 *
		 * `surveyx_id` is a public query var, so any URL on the site can carry it. It is
		 * honoured ONLY when one of this plugin's own rewrite rules matched — the values
		 * are then read back from the matched rule, never from the query string, so a
		 * stray ?surveyx_id= cannot re-point a survey page — or, with plain permalinks
		 * (no rules at all), on a request carrying nothing but surveyx_id: the documented
		 * /?surveyx_id=N fallback URL.
		 *
		 * @return array{id:int,slug:string}|null
		 */
		protected function resolve_request() {
			$wp = isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;

			if ( ! $wp instanceof WP ) {
				return null;
			}

			if ( in_array( (string) $wp->matched_rule, self::get_rule_patterns(), true ) ) {
				parse_str( (string) $wp->matched_query, $matched );

				return [
					'id'   => absint( $matched['surveyx_id'] ?? 0 ),
					'slug' => sanitize_title( (string) ( $matched['surveyx_slug'] ?? '' ) ),
				];
			}

			if ( get_option( 'permalink_structure' ) ) {
				return null;
			}

			// Plain permalinks: every URL is a query string, so this is the survey URL
			// only when nothing else on it resolves a post, page or archive.
			$others = array_filter(
				(array) $wp->query_vars,
				static function ( $value ) {
					return '' !== $value && null !== $value && false !== $value;
				}
			);

			unset( $others['surveyx_id'], $others['surveyx_slug'] );

			if ( ! empty( $others ) ) {
				return null;
			}

			$survey_id = absint( get_query_var( 'surveyx_id' ) );

			return empty( $survey_id ) ? null : [
				'id'   => $survey_id,
				'slug' => '',
			];
		}

		/**
		 * Prints the complete standalone HTML document and stops.
		 *
		 * @param object $survey      Survey row.
		 * @param string $canonical   Canonical URL.
		 * @param string $notice_html Optional notice to show instead of the survey.
		 *
		 * @return void
		 */
		protected function render_document( $survey, $canonical, $notice_html = '' ) {
			global $wp_query;

			$is_notice = ( '' !== $notice_html );

			// No queried object, so WordPress would resolve this as the blog home and SEO
			// plugins would print the home page's tags into our head.
			$wp_query->is_home     = false;
			$wp_query->is_404      = false;
			$wp_query->is_singular = false;

			remove_action( 'wp_head', 'rel_canonical' );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			// This page prints its own <title>; core would add a second one on any theme
			// declaring add_theme_support( 'title-tag' ).
			remove_action( 'wp_head', '_wp_render_title_tag', 1 );
			// This page prints its own <meta name="robots">; core's default (only
			// directives like max-image-preview:large) would duplicate the tag.
			remove_action( 'wp_head', 'wp_robots', 1 );

			$this->suppress_seo_plugins();

			// The admin bar forces html{margin-top:32px}, which fights min-height:100vh.
			// wp_admin_bar_render() reads this filter again, so the bar itself goes; its
			// stylesheet (which carries the bump CSS since WP 6.4) is dequeued in
			// dequeue_theme_styles().
			add_filter( 'show_admin_bar', '__return_false' );

			add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_theme_styles' ], 100 );

			if ( $is_notice ) {
				// A notice page boots no survey app, so it only needs the stylesheet.
				SurveyX_Shortcode_Handler::enqueue_client_style();

				// What it says depends on who is asking, so it must not be cached.
				nocache_headers();
			} else {
				SurveyX_Shortcode_Handler::enqueue_client_assets();
			}

			$theme = sanitize_key( $survey->settings['theme'] ?? 'normal' );

			// Otherwise a single generic document, safe for any cache;
			// render_admin_edit_links() makes this variant viewer-specific, so it must
			// not be stored by a page cache that is not keyed on the login cookie.
			if ( $this->has_admin_edit_links() ) {
				nocache_headers();
			}

			status_header( 200 );

			?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php $this->render_head_meta( $survey, $canonical ); ?>
	<?php
	ob_start();
	wp_head();
	// Core/theme head markup, already escaped by whatever printed it.
	echo self::filter_head( ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</head>
<body class="surveyx-fullpage survey-theme-<?php echo esc_attr( $theme ); ?>">
			<?php
			if ( $is_notice ) {
				// Plugin-authored markup, escaped where it is built.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<div class="surveyx surveyx-page surveyx-page-notice">' . $notice_html . '</div>';
			} else {
				// Plugin-authored markup, escaped where it is built.
				echo SurveyX_Shortcode_Handler::render_survey_container( $survey->id, 'l', 'fullpage' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				// A notice page already carries the shortcode's own edit links.
				$this->render_admin_edit_links( $survey );
			}

			wp_footer();
			?>
</body>
</html>
			<?php
			exit;
		}

		/**
		 * Prints title, description, robots, canonical and social tags.
		 *
		 * Survey pages are always excluded from search engines here: opting a page
		 * in is a Pro-only setting, so the free plugin has no indexable branch.
		 *
		 * @param object $survey    Survey row.
		 * @param string $canonical Canonical URL.
		 * @return void
		 */
		protected function render_head_meta( $survey, $canonical ) {
			$title       = wp_strip_all_tags( (string) $survey->title );
			$description = $this->get_description( $survey );

			echo '<title>' . esc_html( $title ) . '</title>' . "\n";
			echo '<meta name="robots" content="noindex,nofollow">' . "\n";
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";

			if ( ! empty( $description ) ) {
				echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
				echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
			}

			echo '<meta property="og:type" content="website">' . "\n";
			echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
			echo '<meta property="og:url" content="' . esc_url( $canonical ) . '">' . "\n";
			echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";

			if ( ! empty( $survey->cover ) ) {
				echo '<meta property="og:image" content="' . esc_url( $survey->cover ) . '">' . "\n";
				echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
			} else {
				echo '<meta name="twitter:card" content="summary">' . "\n";
			}

			echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
		}

		/**
		 * Builds a plain-text description capped at 155 characters.
		 *
		 * From the decoded `content` array: `cover_content`, falling back to
		 * `cover_title`; both are stored as HTML. The fallback turns on whether stripping
		 * tags leaves actual TEXT, not on key presence, so a builder-initialised `''`
		 * behaves like a missing key — older rows may have neither.
		 *
		 * @param object $survey Survey row.
		 * @return string
		 */
		protected function get_description( $survey ) {
			$content = is_array( $survey->content ) ? $survey->content : [];

			$text = trim( wp_strip_all_tags( (string) ( $content['cover_content'] ?? '' ) ) );

			if ( '' === $text ) {
				$text = trim( wp_strip_all_tags( (string) ( $content['cover_title'] ?? '' ) ) );
			}

			if ( '' === $text ) {
				return '';
			}

			return wp_html_excerpt( $text, 155, '…' );
		}

		/**
		 * Silences third-party SEO plugins, for this request only.
		 *
		 * Yoast and Rank Math print everything they emit — meta, canonical, social tags,
		 * JSON-LD — from their own action hooked onto wp_head, so emptying that action
		 * stops all of it while leaving the rest of wp_head intact. All in One SEO has a
		 * documented opt-out filter; anything else is dropped by filter_head().
		 *
		 * @return void
		 */
		protected function suppress_seo_plugins() {
			// Yoast SEO: wp_head -> do_action( 'wpseo_head' ).
			remove_all_actions( 'wpseo_head' );

			// Rank Math: wp_head -> do_action( 'rank_math/head' ).
			remove_all_actions( 'rank_math/head' );

			// All in One SEO.
			add_filter( 'aioseo_disable', '__return_true' );
		}

		/**
		 * Drops head tags this page already prints for itself.
		 *
		 * This page emits its own title, canonical, robots and social tags before
		 * wp_head(), so a second copy describes the wrong document — at worst a `robots`
		 * value contradicting ours. Applies to this page only.
		 *
		 * @param string $head Buffered wp_head() output.
		 * @return string
		 */
		protected static function filter_head( $head ) {
			$patterns = [
				'~<title\b[^>]*>.*?</title>~is',
				'~<link\b[^>]*\brel=[\'"]canonical[\'"][^>]*>~i',
				'~<meta\b[^>]*\bname=[\'"](?:robots|description|twitter:[^\'"]*)[\'"][^>]*>~i',
				'~<meta\b[^>]*\b(?:property|name)=[\'"]og:[^\'"]*[\'"][^>]*>~i',
				'~<script\b[^>]*\btype=[\'"]application/ld\+json[\'"][^>]*>.*?</script>~is',
			];

			$filtered = preg_replace( $patterns, '', (string) $head );

			// preg_replace() returns null when PCRE gives up (a backtrack limit on a very
			// large wp_head()). Casting that null to '' discards the ENTIRE head, this
			// plugin's own stylesheet and script tags included, and the page renders
			// blank; an unfiltered head only risks a duplicate meta tag.
			return is_string( $filtered ) ? $filtered : (string) $head;
		}

		/**
		 * Removes the active theme's own stylesheets, and the admin bar assets, from
		 * the standalone page. Runs late on wp_enqueue_scripts so it wins.
		 *
		 * Handles are matched by SOURCE, not by name: naming a handle after the theme
		 * directory is a core-theme convention most real themes ignore
		 * (`astra-theme-css`, `generate-style`, `hello-elementor`, `kadence-global`), so
		 * a name-based sweep misses them. Anything served from the stylesheet or template
		 * directory is the theme's, whatever it is called.
		 *
		 * Only the plugin's own assets and the theme generator's Google Fonts come from
		 * elsewhere, so neither can match; the survey theme's CSS is printed inline and
		 * never passes through wp_styles().
		 *
		 * @return void
		 */
		public function dequeue_theme_styles() {
			$styles = wp_styles();

			// Scheme-less prefixes, so a src stored as http:// still matches a site now
			// served over https:// (and vice versa).
			$roots = [];

			foreach ( [ get_stylesheet_directory_uri(), get_template_directory_uri() ] as $dir ) {
				$roots[] = trailingslashit( preg_replace( '~^https?://~i', '//', (string) $dir ) );
			}

			$roots = array_unique( $roots );

			// Copy: wp_dequeue_style() mutates the queue being walked.
			foreach ( (array) $styles->queue as $handle ) {
				$src = isset( $styles->registered[ $handle ] ) ? (string) $styles->registered[ $handle ]->src : '';

				if ( '' === $src ) {
					continue;
				}

				$src = preg_replace( '~^https?://~i', '//', $src );

				foreach ( $roots as $root ) {
					if ( 0 === strpos( $src, $root ) ) {
						wp_dequeue_style( $handle );
						break;
					}
				}
			}

			// Fallback for a theme registering its stylesheet with no src (nothing to
			// match on), plus two core sheets the survey client needs neither of:
			// `global-styles` (theme.json custom properties) and `wp-block-library`.
			$stylesheet = get_stylesheet();
			$template   = get_template();

			$handles = [
				$stylesheet,
				$template,
				$stylesheet . '-style',
				$template . '-style',
				'global-styles',
				'wp-block-library',
			];

			foreach ( $handles as $handle ) {
				wp_dequeue_style( $handle );
			}

			// Carries both the toolbar CSS and the html{margin-top} bump inline style.
			wp_dequeue_style( 'admin-bar' );
			wp_dequeue_script( 'admin-bar' );
		}

		/**
		 * Whether this response will carry the administrator edit links.
		 *
		 * Single source of truth for render_admin_edit_links() and for the cache
		 * headers render_document() sends, so the two can never disagree.
		 *
		 * @return bool
		 */
		protected function has_admin_edit_links() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Prints the administrator edit links, for logged-in administrators only.
		 *
		 * No branding footer here on purpose: the client bundle already renders it
		 * (BrandLogo.vue) from the survey's own `show_header_branding` /
		 * `show_footer_branding` / `enable_remove_brand` settings, so a page-level copy
		 * would either fight a site that disabled branding or double it up.
		 *
		 * @param object $survey Survey row.
		 *
		 * @return void
		 */
		protected function render_admin_edit_links( $survey ) {
			if ( ! $this->has_admin_edit_links() ) {
				return;
			}

			// Plugin-authored markup, already escaped internally; wp_kses_post() would
			// strip the inline SVG icons it contains. Built into a variable first so the
			// phpcs:ignore below sits on the line the sniff actually reports - on the
			// multi-line echo it annotated the wrong line and suppressed nothing.
			$admin_links = '<div class="sx-page-admin-links">'
				. SurveyX_Shortcode_Handler::get_instance()->public_admin_edit_links( $survey->id )
				. '</div>';

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $admin_links;
		}

		/**
		 * Whether the current request is already on the canonical URL.
		 *
		 * @param object $survey Survey row.
		 * @param string $slug   Slug this request asked for, from the matched rule.
		 * @return bool
		 */
		protected function should_redirect( $survey, $slug ) {
			if ( ! get_option( 'permalink_structure' ) ) {
				return false;
			}

			// Served through the previous base's rule: send it on to the current one
			// whatever the slug says, or a bookmarked old URL keeps answering 200 and
			// only the bare /{prev-base}/{id}/ form moves.
			if ( self::get_base() !== $this->get_request_base() ) {
				return true;
			}

			$expected = sanitize_title( wp_strip_all_tags( (string) $survey->title ) );

			return $expected !== $slug;
		}

		/**
		 * First path segment of the current request, relative to the site root.
		 *
		 * @return string
		 */
		protected function get_request_base() {
			$request = isset( $GLOBALS['wp']->request ) ? (string) $GLOBALS['wp']->request : '';

			if ( '' === $request ) {
				return '';
			}

			$segments = explode( '/', trim( $request, '/' ) );

			return sanitize_title( $segments[0] );
		}

		/**
		 * Sends a real 404 through the theme's 404 template and stops.
		 *
		 * @return void
		 */
		protected function send_404() {
			global $wp_query;

			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			$template = get_query_template( '404' );

			if ( $template ) {
				include $template;
			}

			exit;
		}
	}
}

SurveyX_Public_Page::get_instance();
