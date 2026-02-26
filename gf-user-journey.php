<?php
/**
 * Plugin Name:  GF User Journey
 * Description:  Tracks visitor navigation and appends the full user journey to Gravity Forms notification emails and entry details.
 * Version:      1.0.1
 * Author:       Digiwise
 * Author URI:   https://digiwise.se/
 * Requires PHP: 7.4
 * Requires Plugins: gravityforms
 * Requires at least: 6.3
 * Text Domain:  gf-user-journey
 * Domain Path:  /languages
 * License:      GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GF_User_Journey {

	const VERSION             = '1.0.1';
	const STORAGE_NAME        = '_gf_uj';
	const CLEANUP_COOKIE_NAME = '_gf_uj_cleanup';
	const META_KEY_JOURNEY    = '_gf_uj_journey';
	const META_KEY_UTM        = '_gf_uj_utm';
	const MAX_DATA_ITEMS      = 100;
	const MAX_DATA_SIZE       = 10240; // 10 KB

	/**
	 * Singleton instance.
	 */
	private static $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ $this, 'init_update_checker' ] );

		if ( ! is_admin() ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tracking_script' ] );
		}

		add_action( 'gform_entry_created', [ $this, 'capture_journey' ], 10, 2 );
		add_filter( 'gform_entry_detail_meta_boxes', [ $this, 'register_meta_box' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
		add_filter( 'gform_noconflict_styles', [ $this, 'register_noconflict_styles' ] );
		add_filter( 'gform_notification', [ $this, 'append_journey_to_notification' ], 10, 3 );
		add_filter( 'gform_notification_settings_fields', [ $this, 'add_notification_setting' ], 10, 3 );
	}

	/**
	 * Load plugin textdomain for translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'gf-user-journey', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * GitHub-based plugin update checker.
	 */
	public function init_update_checker(): void {
		require_once __DIR__ . '/includes/plugin-update-checker/plugin-update-checker.php';

		$update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/landerss0n/gf-user-journey/',
			__FILE__,
			'gf-user-journey'
		);

		$update_checker->setBranch( 'main' );
		$update_checker->getVcsApi()->enableReleaseAssets();
	}

	/**
	 * Enqueue the tracking script on every frontend page.
	 */
	public function enqueue_tracking_script(): void {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script(
			'gf-user-journey',
			plugin_dir_url( __FILE__ ) . "assets/js/user-journey{$min}.js",
			[],
			self::VERSION,
			[ 'strategy' => 'defer' ]
		);

		wp_localize_script(
			'gf-user-journey',
			'gf_user_journey',
			[
				'storage_name'        => self::STORAGE_NAME,
				'cleanup_cookie_name' => self::CLEANUP_COOKIE_NAME,
				'is_ssl'              => is_ssl(),
				'max_items'           => self::MAX_DATA_ITEMS,
				'max_size'            => self::MAX_DATA_SIZE,
				'nonce'               => wp_create_nonce( 'gf_user_journey' ),
			]
		);
	}

	/**
	 * Capture journey data when a Gravity Forms entry is created.
	 *
	 * @param array $entry The entry object.
	 * @param array $form  The form object.
	 */
	public function capture_journey( array $entry, array $form ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( empty( $_POST[ self::STORAGE_NAME ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST[ self::STORAGE_NAME . '_nonce' ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::STORAGE_NAME . '_nonce' ] ) ), 'gf_user_journey' )
		) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw  = wp_unslash( $_POST[ self::STORAGE_NAME ] );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return;
		}

		$data    = array_map( 'urldecode', $data );
		$data    = $this->sanitize_and_limit( $data );
		$journey = $this->parse_journey( $data );

		if ( empty( $journey ) ) {
			return;
		}

		// Save journey as entry meta.
		gform_update_meta( $entry['id'], self::META_KEY_JOURNEY, wp_json_encode( $journey ) );

		// Capture UTM parameters.
		if ( ! empty( $_POST[ self::STORAGE_NAME . '_utm' ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$utm_raw  = wp_unslash( $_POST[ self::STORAGE_NAME . '_utm' ] );
			$utm_data = json_decode( $utm_raw, true );

			if ( is_array( $utm_data ) ) {
				$allowed  = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ];
				$utm_data = array_intersect_key(
					array_map( 'sanitize_text_field', $utm_data ),
					array_flip( $allowed )
				);

				if ( ! empty( $utm_data ) ) {
					gform_update_meta( $entry['id'], self::META_KEY_UTM, wp_json_encode( $utm_data ) );
				}
			}
		}

		// Set cleanup cookie so JS clears localStorage on next page load.
		setcookie(
			self::CLEANUP_COOKIE_NAME,
			'1',
			[
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Strict',
			]
		);
	}

	/**
	 * Server-side validation and size limiting (don't trust client data).
	 */
	private function sanitize_and_limit( array $data ): array {
		krsort( $data );

		$cutoff    = time() - YEAR_IN_SECONDS;
		$last_data = [];
		$total     = 2; // JSON outer braces.

		foreach ( $data as $key => $value ) {
			if ( (int) $key < $cutoff ) {
				break;
			}

			if ( count( $last_data ) >= self::MAX_DATA_ITEMS ) {
				break;
			}

			$pair_size = strlen( (string) $key ) + strlen( (string) $value ) + 6;

			if ( $total + $pair_size > self::MAX_DATA_SIZE ) {
				break;
			}

			$total += $pair_size;

			$last_data[ $key ] = $value;
		}

		ksort( $last_data );

		return $last_data;
	}

	/**
	 * Parse raw journey entries into structured step data.
	 */
	private function parse_journey( array $data ): array {
		$journey = [];
		$prev_ts = 0;

		foreach ( $data as $timestamp => $record ) {
			if ( empty( $record ) || strpos( $record, '|#|' ) === false ) {
				continue;
			}

			$parts = explode( '|#|', $record );
			$url   = esc_url_raw( $parts[0], [ 'http', 'https' ] );
			$title = ! empty( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '';
			$ts    = absint( $timestamp );

			$journey[] = [
				'url'      => $url,
				'title'    => $title,
				'time'     => gmdate( 'Y-m-d H:i:s', $ts ),
				'duration' => $prev_ts > 0 ? $ts - $prev_ts : 0,
			];

			$prev_ts = $ts;
		}

		return $journey;
	}

	/**
	 * Register a meta box on the GF entry detail page.
	 *
	 * @param array $meta_boxes Existing meta boxes.
	 * @param array $entry      The entry object.
	 * @param array $form       The form object.
	 * @return array
	 */
	public function register_meta_box( array $meta_boxes, array $entry, array $form ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$journey_json = gform_get_meta( $entry['id'], self::META_KEY_JOURNEY );

		if ( empty( $journey_json ) ) {
			return $meta_boxes;
		}

		$meta_boxes['gf_user_journey'] = [
			'title'    => esc_html__( 'User Journey', 'gf-user-journey' ),
			'callback' => [ $this, 'render_meta_box' ],
			'context'  => 'normal',
		];

		return $meta_boxes;
	}

	/**
	 * Render the meta box content on the entry detail page.
	 *
	 * @param array $args Meta box arguments containing 'entry' and 'form'.
	 */
	public function render_meta_box( array $args ): void {
		$entry = $args['entry'];

		$journey_json = gform_get_meta( $entry['id'], self::META_KEY_JOURNEY );
		$journey      = json_decode( $journey_json, true );

		if ( ! is_array( $journey ) || empty( $journey ) ) {
			echo '<p>' . esc_html__( 'No journey data available.', 'gf-user-journey' ) . '</p>';
			return;
		}

		$utm_json   = gform_get_meta( $entry['id'], self::META_KEY_UTM );
		$utm_data   = $utm_json ? json_decode( $utm_json, true ) : [];
		$utm_labels = self::get_utm_labels();

		if ( ! empty( $utm_data ) && is_array( $utm_data ) ) {
			$utm_data = array_intersect_key( $utm_data, $utm_labels );
			echo '<div class="gf-uj-utm">';

			foreach ( $utm_data as $key => $value ) {
				if ( isset( $utm_labels[ $key ] ) ) {
					echo '<span class="gf-uj-utm-tag">';
					echo '<strong>' . esc_html( $utm_labels[ $key ] ) . ':</strong> ' . esc_html( $value );
					echo '</span>';
				}
			}

			echo '</div>';
		}

		echo '<table class="gf-uj-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Page', 'gf-user-journey' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'gf-user-journey' ) . '</th>';
		echo '<th>' . esc_html__( 'Time', 'gf-user-journey' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration', 'gf-user-journey' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		$total_steps = count( $journey );

		foreach ( $journey as $step ) {
			$title    = ! empty( $step['title'] ) ? $step['title'] : __( '(No title)', 'gf-user-journey' );
			$duration = $step['duration'] > 0 ? self::human_duration( $step['duration'] ) : false;

			echo '<tr>';
			echo '<td>' . esc_html( $title ) . '</td>';
			echo '<td class="gf-uj-url">' . esc_html( $step['url'] ) . '</td>';
			echo '<td>' . esc_html( $step['time'] ) . '</td>';
			echo '<td>' . ( $duration ? esc_html( $duration ) : '&mdash;' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';

		// Summary row.
		if ( $total_steps > 0 ) {
			$first_ts   = strtotime( $journey[0]['time'] . ' UTC' );
			$last_ts    = strtotime( $journey[ $total_steps - 1 ]['time'] . ' UTC' );
			$total_time = self::human_duration( max( 0, $last_ts - $first_ts ) );

			echo '<tfoot><tr>';
			echo '<td colspan="4">';
			printf(
				/* translators: %1$d: number of steps, %2$s: total time */
				esc_html__( '%1$d steps over %2$s', 'gf-user-journey' ),
				absint( $total_steps ),
				esc_html( $total_time )
			);
			echo '</td>';
			echo '</tr></tfoot>';
		}

		echo '</table>';
	}

	/**
	 * Enqueue admin CSS on the GF entry detail page.
	 */
	public function enqueue_admin_styles(): void {
		if ( ! class_exists( 'GFCommon' ) || ! GFCommon::is_entry_detail() ) {
			return;
		}

		wp_enqueue_style(
			'gf-user-journey-admin',
			plugin_dir_url( __FILE__ ) . 'assets/css/entry-detail.css',
			[],
			self::VERSION
		);
	}

	/**
	 * Register our stylesheet for GF no-conflict mode.
	 *
	 * @param array $styles Allowed style handles.
	 * @return array
	 */
	public function register_noconflict_styles( array $styles ): array {
		$styles[] = 'gf-user-journey-admin';

		return $styles;
	}

	/**
	 * Add "Include User Journey" toggle to notification settings (GF 2.5+).
	 *
	 * @param array $fields       Existing settings fields.
	 * @param array $notification The notification object.
	 * @param array $form         The form object.
	 * @return array
	 */
	public function add_notification_setting( array $fields, array $notification, array $form ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$fields[] = [
			'title'  => esc_html__( 'User Journey', 'gf-user-journey' ),
			'fields' => [
				[
					'name'  => 'gf_uj_enable',
					'type'  => 'toggle',
					'label' => esc_html__( 'Include User Journey in this notification', 'gf-user-journey' ),
				],
				[
					'name'       => 'gf_uj_bcc_only',
					'type'       => 'toggle',
					'label'      => esc_html__( 'Send User Journey only to BCC address', 'gf-user-journey' ),
					'dependency' => [
						'field'  => 'gf_uj_enable',
						'values' => [ '1' ],
						'live'   => true,
					],
				],
			],
		];

		return $fields;
	}

	/**
	 * Append journey data to GF notification emails (only if opt-in is enabled).
	 *
	 * @param array $notification The notification object.
	 * @param array $form         The form object.
	 * @param array $entry        The entry object.
	 * @return array
	 */
	public function append_journey_to_notification( array $notification, array $form, array $entry ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Only include journey if the notification has opted in.
		if ( empty( $notification['gf_uj_enable'] ) ) {
			return $notification;
		}

		$journey_json = gform_get_meta( $entry['id'], self::META_KEY_JOURNEY );

		if ( empty( $journey_json ) ) {
			return $notification;
		}

		$journey = json_decode( $journey_json, true );

		if ( ! is_array( $journey ) || empty( $journey ) ) {
			return $notification;
		}

		$utm_json = gform_get_meta( $entry['id'], self::META_KEY_UTM );
		$utm_data = $utm_json ? json_decode( $utm_json, true ) : [];

		if ( ! is_array( $utm_data ) ) {
			$utm_data = [];
		}

		$journey_html = $this->render_journey_html( $journey, $utm_data );

		// BCC-only mode: send full notification + journey to BCC, original stays clean.
		if ( ! empty( $notification['gf_uj_bcc_only'] ) && ! empty( $notification['bcc'] ) ) {
			$bcc_address = $notification['bcc'];

			// Process merge tags so the separate email has resolved content.
			$subject = GFCommon::replace_variables( $notification['subject'], $form, $entry, false, false, false, 'text' );
			$message = GFCommon::replace_variables( $notification['message'], $form, $entry, false, false, false, 'html' );
			$message .= $journey_html;

			$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
			wp_mail( $bcc_address, $subject, $message, $headers );

			// Remove BCC from original so they don't get a duplicate.
			$notification['bcc'] = '';

			return $notification;
		}

		// Normal mode: append journey to notification body.
		$is_plain = ! empty( $notification['message_format'] ) && 'text' === $notification['message_format'];

		if ( $is_plain ) {
			$notification['message'] .= "\n\n" . $this->render_journey_plain_text( $journey, $utm_data );
		} else {
			$notification['message'] .= $journey_html;
		}

		return $notification;
	}

	/**
	 * Render the journey as an HTML table for HTML emails.
	 *
	 * @param array $journey  Parsed journey steps.
	 * @param array $utm_data UTM parameters.
	 */
	private function render_journey_html( array $journey, array $utm_data = [] ): string {
		$cell = 'border-right:1px solid #ddd;border-bottom:1px solid #ddd;padding:8px;';

		$html  = '<br><br>';
		$html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-top:1px solid #ddd;border-left:1px solid #ddd;border-collapse:collapse;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;font-size:13px;">';

		// Header.
		$html .= '<tr>';
		$html .= '<td colspan="3" style="' . $cell . 'font-weight:bold;font-size:14px;background:#f7f7f7;">' . esc_html__( 'User Journey', 'gf-user-journey' ) . '</td>';
		$html .= '</tr>';

		// UTM summary row (above column headers).
		if ( ! empty( $utm_data ) ) {
			$utm_labels = self::get_utm_labels();
			$utm_data   = array_intersect_key( $utm_data, $utm_labels );
			$utm_parts  = [];

			foreach ( $utm_data as $key => $value ) {
				if ( isset( $utm_labels[ $key ] ) ) {
					$utm_parts[] = '<strong>' . esc_html( $utm_labels[ $key ] ) . ':</strong> ' . esc_html( $value );
				}
			}

			if ( ! empty( $utm_parts ) ) {
				$html .= '<tr style="background:#fff8e1;">';
				$html .= '<td colspan="3" style="' . $cell . 'font-size:12px;">';
				$html .= implode( ' &nbsp;|&nbsp; ', $utm_parts );
				$html .= '</td></tr>';
			}
		}

		// Column headers.
		$html .= '<tr style="background:#fafafa;">';
		$html .= '<td style="' . $cell . 'font-weight:600;width:30%;">' . esc_html__( 'Page', 'gf-user-journey' ) . '</td>';
		$html .= '<td style="' . $cell . 'font-weight:600;">' . esc_html__( 'URL', 'gf-user-journey' ) . '</td>';
		$html .= '<td style="' . $cell . 'font-weight:600;width:80px;">' . esc_html__( 'Duration', 'gf-user-journey' ) . '</td>';
		$html .= '</tr>';

		$total_steps = count( $journey );

		foreach ( $journey as $i => $step ) {
			$title    = ! empty( $step['title'] ) ? esc_html( $step['title'] ) : esc_html__( '(No title)', 'gf-user-journey' );
			$url      = esc_html( $step['url'] );
			$duration = $step['duration'] > 0 ? esc_html( self::human_duration( $step['duration'] ) ) : '&mdash;';
			$bg       = ( 0 === $i % 2 ) ? '#ffffff' : '#f9f9f9';

			$html .= "<tr style=\"background:{$bg};\">";
			$html .= "<td style=\"{$cell}\">{$title}</td>";
			$html .= "<td style=\"{$cell}color:#666;word-break:break-all;\">{$url}</td>";
			$html .= "<td style=\"{$cell}\">{$duration}</td>";
			$html .= '</tr>';
		}

		// Summary row.
		if ( $total_steps > 0 ) {
			$first_ts   = strtotime( $journey[0]['time'] . ' UTC' );
			$last_ts    = strtotime( $journey[ $total_steps - 1 ]['time'] . ' UTC' );
			$total_time = self::human_duration( max( 0, $last_ts - $first_ts ) );

			$html .= '<tr style="background:#f0f7ff;">';
			$html .= '<td colspan="3" style="' . $cell . 'font-weight:600;">';
			$html .= sprintf(
				/* translators: %1$d: number of steps, %2$s: total time */
				esc_html__( '%1$d steps over %2$s', 'gf-user-journey' ),
				$total_steps,
				esc_html( $total_time )
			);
			$html .= '</td></tr>';
		}

		$html .= '</table>';

		return $html;
	}

	/**
	 * Render the journey as plain text for non-HTML emails.
	 *
	 * @param array $journey  Parsed journey steps.
	 * @param array $utm_data UTM parameters.
	 */
	private function render_journey_plain_text( array $journey, array $utm_data = [] ): string {
		$text = '--- ' . __( 'User Journey', 'gf-user-journey' ) . " ---\n";

		if ( ! empty( $utm_data ) ) {
			$utm_labels = self::get_utm_labels();
			$utm_data   = array_intersect_key( $utm_data, $utm_labels );
			$parts      = [];

			foreach ( $utm_data as $key => $value ) {
				if ( isset( $utm_labels[ $key ] ) ) {
					$parts[] = $utm_labels[ $key ] . ': ' . $value;
				}
			}

			$text .= __( 'Traffic source:', 'gf-user-journey' ) . ' ' . implode( ' | ', $parts ) . "\n\n";
		}

		foreach ( $journey as $step ) {
			$title    = ! empty( $step['title'] ) ? $step['title'] : __( '(No title)', 'gf-user-journey' );
			$duration = $step['duration'] > 0 ? ' (' . self::human_duration( $step['duration'] ) . ')' : '';
			$text    .= "- {$title} - {$step['url']}{$duration}\n";
		}

		$total_steps = count( $journey );

		if ( $total_steps > 0 ) {
			$first_ts   = strtotime( $journey[0]['time'] . ' UTC' );
			$last_ts    = strtotime( $journey[ $total_steps - 1 ]['time'] . ' UTC' );
			$total_time = self::human_duration( max( 0, $last_ts - $first_ts ) );
			$text      .= "\n" . sprintf(
				/* translators: %1$d: number of steps, %2$s: total time */
				__( '%1$d steps over %2$s', 'gf-user-journey' ),
				$total_steps,
				$total_time
			) . "\n";
		}

		return $text;
	}

	/**
	 * Get UTM parameter labels.
	 */
	private static function get_utm_labels(): array {
		return [
			'utm_source'   => __( 'Source', 'gf-user-journey' ),
			'utm_medium'   => __( 'Medium', 'gf-user-journey' ),
			'utm_campaign' => __( 'Campaign', 'gf-user-journey' ),
			'utm_term'     => __( 'Term', 'gf-user-journey' ),
			'utm_content'  => __( 'Content', 'gf-user-journey' ),
		];
	}

	/**
	 * Convert seconds to a human-readable duration string.
	 */
	private static function human_duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}

		$minutes = (int) floor( $seconds / 60 );
		$secs    = $seconds % 60;

		if ( $minutes < 60 ) {
			return $secs > 0 ? "{$minutes}m {$secs}s" : "{$minutes}m";
		}

		$hours = (int) floor( $minutes / 60 );
		$mins  = $minutes % 60;

		if ( $hours < 24 ) {
			return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
		}

		$days = (int) floor( $hours / 24 );
		$hrs  = $hours % 24;

		return $hrs > 0 ? "{$days}d {$hrs}h" : "{$days}d";
	}
}

GF_User_Journey::get_instance();
