<?php
/**
 * Netlify API連携クラス
 *
 * Netlify Deploy APIを使用して静的サイトをデプロイ
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Netlify_API {
	/**
	 * APIトークン
	 */
	private $api_token;

	/**
	 * サイトID
	 */
	private $site_id;

	/**
	 * ロガーインスタンス
	 */
	private $logger;

	/**
	 * API ベースURL
	 */
	const API_BASE_URL = 'https://api.netlify.com/api/v1';

	/**
	 * コンストラクタ
	 *
	 * @param string $api_token APIトークン
	 * @param string $site_id サイトID
	 */
	public function __construct( $api_token, $site_id ) {
		$this->api_token = $api_token;
		$this->site_id = $site_id;
		$this->logger = CP_Logger::get_instance();
	}

	/**
	 * 接続テスト
	 * GET /sites/{site_id}
	 *
	 * @return true|WP_Error 成功時true、失敗時WP_Error
	 */
	public function test_connection() {
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
