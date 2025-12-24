<?php
/**
 * Cloudflare Workers Static Assets API連携クラス
 *
 * Workers Static Assets Direct Upload APIを使用して静的サイトをデプロイ
 *
 * @see https://developers.cloudflare.com/workers/static-assets/direct-upload/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_Cloudflare_Workers {

    /**
     * Cloudflare APIトークン
     */
    private $api_token;

    /**
     * Cloudflare Account ID
     */
    private $account_id;

    /**
     * Worker Script名
     */
    private $script_name;

    /**
     * ロガーインスタンス
     */
    private $logger;

    /**
     * API Base URL
     */
    const API_BASE_URL = 'https://api.cloudflare.com/client/v4';

    /**
     * ファイル数上限（Freeプラン）
     */
    const MAX_FILES_FREE = 20000;

    /**
     * ファイルサイズ上限（25 MiB）
     */
    const MAX_FILE_SIZE = 26214400;

    /**
     * コンストラクタ
     *
     * @param string $api_token Cloudflare APIトークン
     * @param string $account_id Cloudflare Account ID
     * @param string $script_name Worker Script名
     */
    public function __construct( $api_token, $account_id, $script_name ) {
        $this->api_token = $api_token;
        $this->account_id = $account_id;
        $this->script_name = $script_name;
        $this->logger = CP_Logger::get_instance();
    }

    /**
     * 接続テスト
     *
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function test_connection() {
        // アカウント情報を取得してトークンの有効性を確認
        $response = $this->api_request( "accounts/{$this->account_id}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code === 200 && ! empty( $body['success'] ) ) {
            $this->logger->info( 'Cloudflare接続テスト成功' );
            return true;
        }

        $error_message = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : '接続テストに失敗しました';
        return new WP_Error( 'connection_failed', $error_message );
    }

    /**
     * 静的サイトをデプロイ
     *
     * @param string $base_dir デプロイするディレクトリのパス
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function deploy( $base_dir ) {
        $start_time = microtime( true );
        $this->logger->info( 'Cloudflare Workersへのデプロイを開始' );

        // ディレクトリの存在確認
        if ( ! is_dir( $base_dir ) ) {
            $this->logger->error( 'デプロイディレクトリが存在しません: ' . $base_dir );
            return new WP_Error( 'dir_not_found', 'デプロイディレクトリが存在しません' );
        }

        // ファイル一覧を取得
        $files = $this->scan_directory( $base_dir );
        if ( is_wp_error( $files ) ) {
            return $files;
        }

        $file_count = count( $files );
        $this->logger->debug( "デプロイ対象: {$file_count}ファイル" );

        // ファイル数チェック
        if ( $file_count > self::MAX_FILES_FREE ) {
            $this->logger->error( "ファイル数が上限を超えています: {$file_count} > " . self::MAX_FILES_FREE );
            return new WP_Error( 'too_many_files', 'ファイル数が上限（20,000）を超えています' );
        }

        // Phase 1: マニフェスト作成・送信
        $this->logger->debug( 'Phase 1: マニフェストを送信中...' );
        $manifest_result = $this->upload_manifest( $files, $base_dir );
        if ( is_wp_error( $manifest_result ) ) {
            return $manifest_result;
        }

        $upload_token = $manifest_result['jwt'];
        $buckets = $manifest_result['buckets'];

        // Phase 2: ファイルアップロード
        if ( ! empty( $buckets ) ) {
            $this->logger->debug( 'Phase 2: ファイルをアップロード中...' );
            $upload_result = $this->upload_files( $files, $base_dir, $buckets, $upload_token );
            if ( is_wp_error( $upload_result ) ) {
                return $upload_result;
            }
            $completion_token = $upload_result;
        } else {
            // すべてのファイルが既にアップロード済み
            $this->logger->debug( '全ファイルがキャッシュ済み、アップロードをスキップ' );
            $completion_token = $upload_token;
        }

        // Phase 3: Workerスクリプトをデプロイ
        $this->logger->debug( 'Phase 3: Workerをデプロイ中...' );
        $deploy_result = $this->deploy_worker( $completion_token );
        if ( is_wp_error( $deploy_result ) ) {
            return $deploy_result;
        }

        $elapsed = microtime( true ) - $start_time;
        $this->logger->info( sprintf( 'Cloudflare Workersへのデプロイ完了 (%.1f秒)', $elapsed ) );

        return true;
    }

    /**
     * ディレクトリをスキャンしてファイル一覧を取得
     *
     * @param string $base_dir ベースディレクトリ
     * @return array|WP_Error ファイル情報の配列（パス => ハッシュ/サイズ）
     */
    private function scan_directory( $base_dir ) {
        $files = array();
        $base_dir = rtrim( $base_dir, '/' );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $full_path = $file->getPathname();
                $relative_path = '/' . ltrim( substr( $full_path, strlen( $base_dir ) ), '/' );
                $size = $file->getSize();

                // ファイルサイズチェック
                if ( $size > self::MAX_FILE_SIZE ) {
                    $this->logger->warning( "ファイルサイズ上限超過（スキップ）: {$relative_path} ({$size} bytes)" );
                    continue;
                }

                // ハッシュを計算（SHA-256の先頭32文字）
                $content = file_get_contents( $full_path );
                if ( $content === false ) {
                    continue;
                }

                $hash = $this->compute_file_hash( $content );

                $files[ $relative_path ] = array(
                    'hash'      => $hash,
                    'size'      => $size,
                    'full_path' => $full_path,
                );
            }
        }

        return $files;
    }

    /**
     * ファイルハッシュを計算
     *
     * @param string $content ファイル内容
     * @return string 32文字のハッシュ
     */
    private function compute_file_hash( $content ) {
        // Account ID + ファイル内容でSHA-256を計算し、先頭32文字を使用
        $hash_input = $this->account_id . $content;
        $full_hash = hash( 'sha256', $hash_input );
        return substr( $full_hash, 0, 32 );
    }

    /**
     * Phase 1: マニフェストをアップロード
     *
     * @param array $files ファイル情報の配列
     * @param string $base_dir ベースディレクトリ
     * @return array|WP_Error 成功ならJWTとbucketsを含む配列
     */
    private function upload_manifest( $files, $base_dir ) {
        $manifest = array();

        foreach ( $files as $path => $info ) {
            $manifest[ $path ] = array(
                'hash' => $info['hash'],
                'size' => $info['size'],
            );
        }

        $endpoint = "accounts/{$this->account_id}/workers/scripts/{$this->script_name}/assets-upload-session";
        $body = array(
            'manifest' => $manifest,
        );

        $response = $this->api_request( $endpoint, 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 && $status_code !== 201 ) {
            $error_message = isset( $response_body['errors'][0]['message'] )
                ? $response_body['errors'][0]['message']
                : 'マニフェストのアップロードに失敗しました';
            $this->logger->error( "マニフェストエラー: {$error_message} (Status: {$status_code})" );
            return new WP_Error( 'manifest_failed', $error_message );
        }

        if ( empty( $response_body['result']['jwt'] ) ) {
            $this->logger->error( 'マニフェストレスポンスにJWTがありません' );
            return new WP_Error( 'manifest_invalid', 'JWTが取得できませんでした' );
        }

        $buckets = isset( $response_body['result']['buckets'] ) ? $response_body['result']['buckets'] : array();
        $total_to_upload = 0;
        foreach ( $buckets as $bucket ) {
            $total_to_upload += count( $bucket );
        }

        $this->logger->debug( "マニフェスト送信完了: {$total_to_upload}ファイルをアップロード予定" );

        return array(
            'jwt'     => $response_body['result']['jwt'],
            'buckets' => $buckets,
        );
    }

    /**
     * Phase 2: ファイルをアップロード
     *
     * @param array $files ファイル情報の配列
     * @param string $base_dir ベースディレクトリ
     * @param array $buckets アップロードするファイルハッシュのバケット
     * @param string $upload_token アップロード用JWT
     * @return string|WP_Error 成功なら完了トークン
     */
    private function upload_files( $files, $base_dir, $buckets, $upload_token ) {
        // ハッシュからパスへのマップを作成
        $hash_to_path = array();
        foreach ( $files as $path => $info ) {
            $hash_to_path[ $info['hash'] ] = $path;
        }

        $total_buckets = count( $buckets );
        $completion_token = $upload_token;

        foreach ( $buckets as $bucket_index => $bucket_hashes ) {
            $bucket_num = $bucket_index + 1;
            $this->logger->debug( "バケット {$bucket_num}/{$total_buckets} をアップロード中..." );

            // multipart/form-data用のboundary（暗号学的に安全な乱数を使用）
            $boundary = bin2hex( random_bytes( 16 ) );
            $body = '';

            foreach ( $bucket_hashes as $hash ) {
                if ( ! isset( $hash_to_path[ $hash ] ) ) {
                    $this->logger->warning( "ハッシュに対応するファイルが見つかりません: {$hash}" );
                    continue;
                }

                $path = $hash_to_path[ $hash ];
                $full_path = $files[ $path ]['full_path'];
                $content = file_get_contents( $full_path );

                if ( $content === false ) {
                    $this->logger->warning( "ファイル読み込み失敗: {$path}" );
                    continue;
                }

                // Base64エンコード
                $encoded_content = base64_encode( $content );

                // multipart/form-dataのパートを追加
                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"{$hash}\"\r\n\r\n";
                $body .= $encoded_content . "\r\n";
            }

            $body .= "--{$boundary}--\r\n";

            // アップロードリクエスト
            $endpoint = "accounts/{$this->account_id}/workers/assets/upload?base64=true";

            $response = wp_remote_post(
                self::API_BASE_URL . '/' . $endpoint,
                array(
                    'timeout' => 300,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $upload_token,
                        'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
                    ),
                    'body' => $body,
                )
            );

            if ( is_wp_error( $response ) ) {
                $this->logger->error( 'ファイルアップロードエラー: ' . $response->get_error_message() );
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

            // 成功ステータスの処理（200, 201, 202）
            if ( $status_code === 200 || $status_code === 201 || $status_code === 202 ) {
                // jwtが含まれている場合は完了トークンを取得
                if ( ! empty( $response_body['jwt'] ) ) {
                    $completion_token = $response_body['jwt'];
                    $this->logger->debug( '完了トークンを取得' );
                } elseif ( ! empty( $response_body['result']['jwt'] ) ) {
                    // resultオブジェクト内にjwtがある場合
                    $completion_token = $response_body['result']['jwt'];
                    $this->logger->debug( '完了トークンを取得（result内）' );
                }
                $this->logger->debug( "バケット {$bucket_num} アップロード成功 (Status: {$status_code})" );
            } else {
                $error_message = isset( $response_body['errors'][0]['message'] )
                    ? $response_body['errors'][0]['message']
                    : 'ファイルアップロードに失敗しました';
                $this->logger->error( "アップロードエラー: {$error_message} (Status: {$status_code})" );
                return new WP_Error( 'upload_failed', $error_message );
            }

            // バケット間で少し待機（レート制限対策）
            if ( $bucket_index < $total_buckets - 1 ) {
                usleep( 500000 ); // 0.5秒
            }
        }

        // 完了トークンが更新されていない場合はエラー
        if ( $completion_token === $upload_token ) {
            $this->logger->warning( '完了トークンが更新されませんでした。初期トークンを使用します。' );
        }

        return $completion_token;
    }

    /**
     * Phase 3: Workerスクリプトをデプロイ
     *
     * @param string $completion_token 完了トークン
     * @return bool|WP_Error 成功ならtrue
     */
    private function deploy_worker( $completion_token ) {
        // 静的アセット専用のシンプルなWorkerスクリプト
        $worker_script = <<<'WORKER'
export default {
    async fetch(request, env) {
        return env.ASSETS.fetch(request);
    }
};
WORKER;

        // multipart/form-data でスクリプトとメタデータを送信（暗号学的に安全な乱数を使用）
        $boundary = bin2hex( random_bytes( 16 ) );

        // メタデータ
        $metadata = array(
            'main_module'        => 'worker.js',
            'compatibility_date' => date( 'Y-m-d' ),
            'assets'             => array(
                'jwt' => $completion_token,
            ),
        );

        $body = '';

        // メタデータパート
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"metadata\"\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= wp_json_encode( $metadata ) . "\r\n";

        // スクリプトパート
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"worker.js\"; filename=\"worker.js\"\r\n";
        $body .= "Content-Type: application/javascript+module\r\n\r\n";
        $body .= $worker_script . "\r\n";

        $body .= "--{$boundary}--\r\n";

        $endpoint = "accounts/{$this->account_id}/workers/scripts/{$this->script_name}";

        $response = wp_remote_request(
            self::API_BASE_URL . '/' . $endpoint,
            array(
                'method'  => 'PUT',
                'timeout' => 120,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
                ),
                'body' => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            $this->logger->error( 'Workerデプロイエラー: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 && $status_code !== 201 ) {
            $error_message = isset( $response_body['errors'][0]['message'] )
                ? $response_body['errors'][0]['message']
                : 'Workerのデプロイに失敗しました';
            $this->logger->error( "デプロイエラー: {$error_message} (Status: {$status_code})" );

            // エラー詳細があれば出力
            if ( ! empty( $response_body['errors'] ) ) {
                foreach ( $response_body['errors'] as $error ) {
                    if ( isset( $error['message'] ) ) {
                        $this->logger->debug( "  - {$error['message']}" );
                    }
                }
            }

            return new WP_Error( 'deploy_failed', $error_message );
        }

        // デプロイURL取得
        if ( ! empty( $response_body['result']['id'] ) ) {
            $worker_url = "https://{$this->script_name}.{$this->account_id}.workers.dev";
            $this->logger->info( "Worker URL: {$worker_url}" );
        }

        return true;
    }

    /**
     * Cloudflare APIリクエスト
     *
     * @param string $endpoint APIエンドポイント
     * @param string $method HTTPメソッド
     * @param array|null $body リクエストボディ
     * @return array|WP_Error レスポンス
     */
    private function api_request( $endpoint, $method = 'GET', $body = null ) {
        $url = self::API_BASE_URL . '/' . $endpoint;

        $args = array(
            'method'  => $method,
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type'  => 'application/json',
            ),
        );

        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->logger->error( 'Cloudflare API接続エラー: ' . $response->get_error_message() );
        }

        return $response;
    }

    /**
     * Workerのサブドメインを取得
     *
     * @return string|WP_Error サブドメイン名
     */
    public function get_workers_subdomain() {
        $endpoint = "accounts/{$this->account_id}/workers/subdomain";
        $response = $this->api_request( $endpoint, 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['result']['subdomain'] ) ) {
            return $body['result']['subdomain'];
        }

        return new WP_Error( 'subdomain_not_found', 'Workers サブドメインが設定されていません' );
    }

    /**
     * Worker削除
     *
     * @return bool|WP_Error 成功ならtrue
     */
    public function delete_worker() {
        $endpoint = "accounts/{$this->account_id}/workers/scripts/{$this->script_name}";
        $response = $this->api_request( $endpoint, 'DELETE' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            $this->logger->info( "Worker '{$this->script_name}' を削除しました" );
            return true;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $error_message = isset( $body['errors'][0]['message'] )
            ? $body['errors'][0]['message']
            : 'Workerの削除に失敗しました';

        return new WP_Error( 'delete_failed', $error_message );
    }
}
