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

    private CP_Logger $logger;

    const string API_BASE_URL = 'https://api.cloudflare.com/client/v4';
    const int MAX_FILES_FREE = 20000;
    const int MAX_FILE_SIZE = 26214400; // 25 MiB

    public function __construct(
        private readonly string $api_token,
        private readonly string $account_id,
        private readonly string $script_name,
    ) {
        $this->logger = CP_Logger::get_instance();
    }

    public function test_connection(): true|\WP_Error {
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

        $error_message = $body['errors'][0]['message'] ?? '接続テストに失敗しました';
        return new WP_Error( 'connection_failed', $error_message );
    }

    public function deploy( string $base_dir ): bool|\WP_Error {
        $start_time = microtime( true );
        $this->logger->info( 'Cloudflare Workersへのデプロイを開始' );

        if ( ! is_dir( $base_dir ) ) {
            $this->logger->error( 'デプロイディレクトリが存在しません: ' . $base_dir );
            return new WP_Error( 'dir_not_found', 'デプロイディレクトリが存在しません' );
        }

        $files = $this->scan_directory( $base_dir );
        if ( is_wp_error( $files ) ) {
            return $files;
        }

        $file_count = count( $files );
        $this->logger->debug( "デプロイ対象: {$file_count}ファイル" );

        if ( $file_count > self::MAX_FILES_FREE ) {
            $this->logger->error( "ファイル数が上限を超えています: {$file_count} > " . self::MAX_FILES_FREE );
            return new WP_Error( 'too_many_files', 'ファイル数が上限（20,000）を超えています' );
        }

        $this->logger->debug( 'Phase 1: マニフェストを送信中...' );
        $manifest_result = $this->upload_manifest( $files, $base_dir );
        if ( is_wp_error( $manifest_result ) ) {
            return $manifest_result;
        }

        $upload_token = $manifest_result['jwt'];
        $buckets = $manifest_result['buckets'];

        if ( ! empty( $buckets ) ) {
            $this->logger->debug( 'Phase 2: ファイルをアップロード中...' );
            $upload_result = $this->upload_files( $files, $base_dir, $buckets, $upload_token );
            if ( is_wp_error( $upload_result ) ) {
                return $upload_result;
            }
            $completion_token = $upload_result;
        } else {
            $this->logger->debug( '全ファイルがキャッシュ済み、アップロードをスキップ' );
            $completion_token = $upload_token;
        }

        $this->logger->debug( 'Phase 3: Workerをデプロイ中...' );
        $deploy_result = $this->deploy_worker( $completion_token );
        if ( is_wp_error( $deploy_result ) ) {
            return $deploy_result;
        }

        $elapsed = microtime( true ) - $start_time;
        $this->logger->info( sprintf( 'Cloudflare Workersへのデプロイ完了 (%.1f秒)', $elapsed ) );

        return true;
    }

    private function scan_directory( string $base_dir ): array|\WP_Error {
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

                // Direct Upload APIでは_headersが機能しない
                if ( basename( $relative_path ) === '_headers' ) {
                    continue;
                }

                if ( $size > self::MAX_FILE_SIZE ) {
                    $this->logger->warning( "ファイルサイズ上限超過（スキップ）: {$relative_path} ({$size} bytes)" );
                    continue;
                }

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

    /** SHA-256(account_id + content) の先頭32文字 */
    private function compute_file_hash( string $content ): string {
        $hash_input = $this->account_id . $content;
        $full_hash = hash( 'sha256', $hash_input );
        return substr( $full_hash, 0, 32 );
    }

    private function upload_manifest( array $files, string $base_dir ): array|\WP_Error {
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
            $error_message = $response_body['errors'][0]['message']
                ?? 'マニフェストのアップロードに失敗しました';
            $this->logger->error( "マニフェストエラー: {$error_message} (Status: {$status_code})" );
            return new WP_Error( 'manifest_failed', $error_message );
        }

        if ( empty( $response_body['result']['jwt'] ) ) {
            $this->logger->error( 'マニフェストレスポンスにJWTがありません' );
            return new WP_Error( 'manifest_invalid', 'JWTが取得できませんでした' );
        }

        $buckets = $response_body['result']['buckets'] ?? array();
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

    private function upload_files( array $files, string $base_dir, array $buckets, string $upload_token ): string|\WP_Error {
        $hash_to_path = array();
        foreach ( $files as $path => $info ) {
            $hash_to_path[ $info['hash'] ] = $path;
        }

        $total_buckets = count( $buckets );
        $completion_token = $upload_token;

        foreach ( $buckets as $bucket_index => $bucket_hashes ) {
            $bucket_num = $bucket_index + 1;
            $this->logger->debug( "バケット {$bucket_num}/{$total_buckets} をアップロード中..." );

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

                $encoded_content = base64_encode( $content );

                $body .= "--{$boundary}\r\n";
                $body .= "Content-Disposition: form-data; name=\"{$hash}\"\r\n\r\n";
                $body .= $encoded_content . "\r\n";
            }

            $body .= "--{$boundary}--\r\n";

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

            if ( $status_code === 200 || $status_code === 201 || $status_code === 202 ) {
                if ( ! empty( $response_body['jwt'] ) ) {
                    $completion_token = $response_body['jwt'];
                } elseif ( ! empty( $response_body['result']['jwt'] ) ) {
                    $completion_token = $response_body['result']['jwt'];
                }
                $this->logger->debug( "バケット {$bucket_num} アップロード成功 (Status: {$status_code})" );
            } else {
                $error_message = $response_body['errors'][0]['message']
                    ?? 'ファイルアップロードに失敗しました';
                $this->logger->error( "アップロードエラー: {$error_message} (Status: {$status_code})" );
                return new WP_Error( 'upload_failed', $error_message );
            }

            if ( $bucket_index < $total_buckets - 1 ) {
                usleep( 500000 );
            }
        }

        if ( $completion_token === $upload_token ) {
            $this->logger->warning( '完了トークンが更新されませんでした。初期トークンを使用します。' );
        }

        return $completion_token;
    }

    private function deploy_worker( string $completion_token ): bool|\WP_Error {
        $worker_script = <<<'WORKER'
export default {
    async fetch(request, env) {
        // ASSETSバインディングの存在確認
        if (!env.ASSETS) {
            return new Response(
                'Service configuration error. Please contact the administrator.',
                {
                    status: 503,
                    headers: { 'Content-Type': 'text/plain; charset=utf-8' }
                }
            );
        }

        // リクエストされたアセットを取得
        const response = await env.ASSETS.fetch(request);

        // 404エラーの場合、カスタム404.htmlがあればそれを返す
        if (response.status === 404) {
            try {
                // 元のリクエストURLから404.htmlへのURLを構築
                const url = new URL(request.url);
                const notFoundUrl = url.origin + '/404.html';
                const notFoundResponse = await env.ASSETS.fetch(notFoundUrl);

                // 404.htmlが存在する場合のみ、それをステータス404で返す
                if (notFoundResponse.status === 200) {
                    return new Response(notFoundResponse.body, {
                        status: 404,
                        statusText: 'Not Found',
                        headers: notFoundResponse.headers
                    });
                }
            } catch (e) {
                // 404.htmlの取得に失敗した場合は元のレスポンスを返す
                // エラーをコンソールに出力（Cloudflare Workersログで確認可能）
                console.error('404.html fetch error:', e.message);
            }
        }

        return response;
    }
};
WORKER;

        $boundary = bin2hex( random_bytes( 16 ) );

        $metadata = array(
            'main_module'        => 'worker.js',
            'compatibility_date' => date( 'Y-m-d' ),
            'bindings'           => array(
                array(
                    'type' => 'assets',
                    'name' => 'ASSETS',
                ),
            ),
            'assets'             => array(
                'jwt' => $completion_token,
            ),
        );

        $body = '';

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"metadata\"\r\n";
        $body .= "Content-Type: application/json\r\n\r\n";
        $body .= wp_json_encode( $metadata ) . "\r\n";

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
            $error_message = $response_body['errors'][0]['message']
                ?? 'Workerのデプロイに失敗しました';
            $this->logger->error( "デプロイエラー: {$error_message} (Status: {$status_code})" );

            if ( ! empty( $response_body['errors'] ) ) {
                foreach ( $response_body['errors'] as $error ) {
                    if ( isset( $error['message'] ) ) {
                        $this->logger->debug( "  - {$error['message']}" );
                    }
                }
            }

            return new WP_Error( 'deploy_failed', $error_message );
        }

        if ( ! empty( $response_body['result']['id'] ) ) {
            $worker_url = "https://{$this->script_name}.{$this->account_id}.workers.dev";
            $this->logger->info( "Worker URL: {$worker_url}" );
        }

        $this->logger->info( 'ASSETSバインディングの設定状態を確認中...' );
        $binding_status = $this->verify_assets_binding();

        if ( is_wp_error( $binding_status ) ) {
            $this->logger->debug( 'ASSETSバインディングの確認をスキップしました: ' . $binding_status->get_error_message() );
        } elseif ( $binding_status === true ) {
            $this->logger->info( 'ASSETSバインディングが正常に設定されています' );
        } else {
            $this->logger->debug( 'ASSETSバインディングの検証をスキップしました（デプロイは正常に完了しています）' );
        }

        return true;
    }

    private function api_request( string $endpoint, string $method = 'GET', ?array $body = null ): array|\WP_Error {
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

    public function get_workers_subdomain(): string|\WP_Error {
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

    public function delete_worker(): bool|\WP_Error {
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
        $error_message = $body['errors'][0]['message']
            ?? 'Workerの削除に失敗しました';

        return new WP_Error( 'delete_failed', $error_message );
    }

    private function verify_assets_binding(): bool|\WP_Error {
        $endpoint = "accounts/{$this->account_id}/workers/scripts/{$this->script_name}/settings";
        $response = $this->api_request( $endpoint, 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 ) {
            if ( $status_code === 404 ) {
                $this->logger->debug( 'Worker設定が見つかりませんでした（デプロイ直後の場合は正常）' );
                return false;
            }
            return new WP_Error( 'binding_check_failed', 'バインディング情報の取得に失敗しました' );
        }

        if ( ! empty( $body['result']['bindings'] ) && is_array( $body['result']['bindings'] ) ) {
            foreach ( $body['result']['bindings'] as $binding ) {
                if ( isset( $binding['name'] ) && $binding['name'] === 'ASSETS' &&
                     isset( $binding['type'] ) && $binding['type'] === 'assets' ) {
                    return true;
                }
            }
        }

        if ( ! empty( $body['bindings'] ) && is_array( $body['bindings'] ) ) {
            foreach ( $body['bindings'] as $binding ) {
                if ( isset( $binding['name'] ) && $binding['name'] === 'ASSETS' &&
                     isset( $binding['type'] ) && $binding['type'] === 'assets' ) {
                    return true;
                }
            }
        }

        return false;
    }
}
