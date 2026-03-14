<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access.
}

/**
 * SurveyX Media Helper
 *
 * Provides utilities for processing external image URLs:
 * - Detects whether a URL is external.
 * - Validates image URLs and MIME types.
 * - Uploads external images into the WordPress media library.
 * - Recursively processes data arrays to replace image URLs with local media references.
 *
 * @since 1.0.0
 */
if ( ! class_exists( 'SurveyX_Media_Helper' ) ) {
	class SurveyX_Media_Helper {

		/**
		 * Maximum file size for uploads (12MB).
		 *
		 * @since 1.0.0
		 */
		const MAX_FILE_SIZE = 12582912; // 12MB in bytes

		/**
		 * Request timeout in seconds.
		 *
		 * @since 1.0.0
		 */
		const REQUEST_TIMEOUT = 180;

		/**
		 * Allowed image MIME types.
		 *
		 * @since 1.0.0
		 * @var array
		 */
		private static $allowed_mime_types = [
			'image/jpeg',
			'image/jpg',
			'image/png',
			'image/gif',
			'image/webp',
		];

		/**
		 * Allowed image extensions.
		 *
		 * @since 1.0.0
		 * @var array
		 */
		private static $allowed_extensions = [
			'jpg',
			'jpeg',
			'png',
			'gif',
			'webp',
		];

		/**
		 * Determine if a given URL points to an external host.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url The URL to check.
		 * @return bool True if the URL is external, false otherwise.
		 */
		public static function is_external_url( $url ) {
			if ( empty( $url ) || ! is_string( $url ) ) {
				return false;
			}

			$site_url = wp_parse_url( site_url(), PHP_URL_HOST );
			$url_host = wp_parse_url( $url, PHP_URL_HOST );

			return ! empty( $url_host ) && $url_host !== $site_url;
		}

		/**
		 * Validate URL scheme (only allow http/https).
		 *
		 * @since 1.0.0
		 *
		 * @param string $url The URL to validate.
		 * @return bool True if scheme is valid, false otherwise.
		 */
		private static function is_valid_url_scheme( $url ) {
			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
			return in_array( $scheme, [ 'http', 'https' ], true );
		}

		/**
		 * Check whether a string is a valid image URL.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url The URL to check.
		 * @return bool True if the string looks like an image URL, false otherwise.
		 */
		protected static function is_image_url( $url ) {
			if ( empty( $url ) || ! is_string( $url ) ) {
				return false;
			}

			// Validate URL format
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return false;
			}

			// Validate URL scheme
			if ( ! self::is_valid_url_scheme( $url ) ) {
				return false;
			}

			// Check file extension
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( empty( $path ) ) {
				return false;
			}

			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( empty( $ext ) ) {
				return false;
			}

			// Only allow specific image extensions
			if ( in_array( $ext, self::$allowed_extensions, true ) ) {
				return true;
			}

			// Fallback: check MIME type against allowed types
			$filetype = wp_check_filetype( $url );
			if ( ! empty( $filetype['type'] ) && in_array( $filetype['type'], self::$allowed_mime_types, true ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Upload an external image into the WordPress media library.
		 *
		 * @since 1.0.0
		 *
		 * @param string $image_url The source URL of the image.
		 * @param string $filename Optional custom filename for the uploaded file.
		 * @return array|WP_Error Returns an array with 'id' and 'url' on success, or WP_Error on failure.
		 */
		public static function upload_image( $image_url, $filename = '' ) {
			// Validate URL
			if ( empty( $image_url ) || ! is_string( $image_url ) ) {
				return new WP_Error( 'invalid_url', esc_html__( 'Invalid image URL provided.', 'surveyx-builder' ) );
			}

			// Validate URL format and scheme
			if ( ! filter_var( $image_url, FILTER_VALIDATE_URL ) || ! self::is_valid_url_scheme( $image_url ) ) {
				return new WP_Error( 'invalid_url', esc_html__( 'Invalid image URL format.', 'surveyx-builder' ) );
			}

			// Validate it's an image URL
			if ( ! self::is_image_url( $image_url ) ) {
				return new WP_Error( 'not_image', esc_html__( 'URL does not point to a valid image.', 'surveyx-builder' ) );
			}

			// Include necessary WordPress files
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			// Verify the URL points to an image by checking Content-Type and size
			$response = wp_safe_remote_head(
				$image_url,
				[
					'timeout'     => self::REQUEST_TIMEOUT,
					'redirection' => 5,
					'sslverify'   => true,
				]
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'remote_request_failed',
					sprintf(
						/* translators: %s: error message */
						esc_html__( 'Failed to retrieve image: %s', 'surveyx-builder' ),
						$response->get_error_message()
					)
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				return new WP_Error(
					'invalid_response',
					sprintf(
					/* translators: %d: HTTP response code */
						esc_html__( 'Invalid response code: %d', 'surveyx-builder' ),
						$code
					)
				);
			}

			// Validate Content-Type
			$content_type = wp_remote_retrieve_header( $response, 'content-type' );
			if ( ! in_array( $content_type, self::$allowed_mime_types, true ) ) {
				return new WP_Error( 'invalid_mime_type', esc_html__( 'Invalid image MIME type.', 'surveyx-builder' ) );
			}

			// Check Content-Length to prevent large file uploads
			$content_length = wp_remote_retrieve_header( $response, 'content-length' );
			if ( $content_length && $content_length > self::MAX_FILE_SIZE ) {
				return new WP_Error(
					'file_too_large',
					sprintf(
					/* translators: %s: maximum file size */
						esc_html__( 'Image file is too large. Maximum size: %s', 'surveyx-builder' ),
						size_format( self::MAX_FILE_SIZE )
					)
				);
			}

			// Clean filename: strip query string and use basename
			if ( empty( $filename ) ) {
				$url_path = wp_parse_url( $image_url, PHP_URL_PATH );
				$filename = sanitize_file_name( basename( $url_path ) );
			} else {
				$filename = sanitize_file_name( $filename );
			}

			// Ensure filename has extension
			if ( empty( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
				// Try to get extension from URL or content type
				$url_ext = strtolower( pathinfo( wp_parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				if ( in_array( $url_ext, self::$allowed_extensions, true ) ) {
					$filename .= '.' . $url_ext;
				} else {
					// Default to jpg
					$filename .= '.jpg';
				}
			}

			// Download the image to a temporary file
			$tmp = download_url( $image_url, self::REQUEST_TIMEOUT );
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error(
					'download_failed',
					sprintf(
						/* translators: %s: error message */
						esc_html__( 'Failed to download image: %s', 'surveyx-builder' ),
						$tmp->get_error_message()
					)
				);
			}

			// Double-check file size after download
			if ( file_exists( $tmp ) ) {
				$file_size = filesize( $tmp );
				if ( $file_size > self::MAX_FILE_SIZE ) {
					wp_delete_file( $tmp );
					return new WP_Error( 'file_too_large', esc_html__( 'Downloaded file exceeds size limit.', 'surveyx-builder' ) );
				}

				// Verify MIME type of downloaded file
				$file_type = wp_check_filetype_and_ext( $tmp, $filename );
				if ( ! $file_type['ext'] || ! $file_type['type'] || ! in_array( $file_type['type'], self::$allowed_mime_types, true ) ) {
					wp_delete_file( $tmp );
					return new WP_Error( 'invalid_file_type', esc_html__( 'Invalid or disallowed file type.', 'surveyx-builder' ) );
				}
			} else {
				return new WP_Error( 'file_not_found', esc_html__( 'Downloaded file not found.', 'surveyx-builder' ) );
			}

			// Prepare file array for sideload
			$file_array = [
				'name'     => $filename,
				'tmp_name' => $tmp,
			];

			// Sideload the image into WordPress
			$attachment_id = media_handle_sideload(
				$file_array,
				0,
				'',
				[
					'post_title'  => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
					'post_status' => 'inherit',
				]
			);

			// Cleanup temp file
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			if ( is_wp_error( $attachment_id ) ) {
				return new WP_Error(
					'sideload_failed',
					sprintf(
						/* translators: %s: error message */
						esc_html__( 'Failed to sideload image: %s', 'surveyx-builder' ),
						$attachment_id->get_error_message()
					)
				);
			}

			// Get the URL of the uploaded image
			$url = wp_get_attachment_url( $attachment_id );
			if ( ! $url ) {
				return new WP_Error( 'attachment_url_failed', esc_html__( 'Failed to get attachment URL.', 'surveyx-builder' ) );
			}

			return [
				'id'  => $attachment_id,
				'url' => esc_url_raw( $url ),
			];
		}

		/**
		 * Recursively scan and process image URLs inside an array.
		 *
		 * - Converts external image URLs into local uploads.
		 * - Replaces the original URL with the uploaded one.
		 * - Adds a corresponding attachment ID (`image_id`) where applicable.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $data The input data array (or value).
		 * @return mixed The processed data with updated image references.
		 */
		protected static function process_recursive( $data ) {
			if ( ! is_array( $data ) ) {
				return $data;
			}

			$processed = [];

			foreach ( $data as $key => $value ) {
				if ( is_string( $value ) && ! empty( $value ) && self::is_image_url( $value ) ) {
					// Found an image URL
					if ( self::is_external_url( $value ) ) {
						$upload_result = self::upload_image( $value );

						if ( ! is_wp_error( $upload_result ) ) {
							$processed[ $key ]     = $upload_result['url'];
							$processed['image_id'] = $upload_result['id'];
						} else {
							// keep original URL
							$processed[ $key ] = $value;
						}
					} else {
						// Local image → keep URL
						$processed[ $key ]     = $value;
						$processed['image_id'] = absint( $data['image_id'] ?? 0 );
					}
				} elseif ( 'image_id' === $key ) {
					// Sanitize image_id
					$processed[ $key ] = absint( $processed['image_id'] ?? 0 );
				} else {
					// Recurse deeper
					$processed[ $key ] = is_array( $value ) ? self::process_recursive( $value ) : $value;
				}
			}

			return $processed;
		}

		/**
		 * Public entry point: process all image URLs within a dataset.
		 *
		 * @since 1.0.0
		 *
		 * @param array $data Input data containing image URLs.
		 * @return array Data with external images uploaded and replaced by local references.
		 */
		public static function process_image_urls( $data ) {
			if ( ! is_array( $data ) ) {
				return $data;
			}

			return self::process_recursive( $data );
		}
	}
}
