<?php
/**
 * Netlify API連携クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Netlify_API {

	private CP_Logger $logger;
	const string API_BASE_URL = 'https://api.netlify.com/api/v1';

	public function __construct(
		private readonly string $api_token,
		private readonly string $site_id,
	) {
		$this->logger = CP_Logger::get_instance();
	}

	public function test_connection(): true|\WP_Error {
		$response = wp_remote_get(
			self::API_BASE_URL . '/sites/' . $this->site_id,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code === 200 ) {
			return true;
		}

		$body = wp_remote_retrieve_body( $response );
		return new WP_Error( 'connection_failed', 'Netlify接続に失敗しました: ' . $status_code );
	}
}
