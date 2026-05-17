<?php
/**
 * GitHub API連携クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_GitHub_API {

    private CP_Logger $logger;

    public function __construct(
        private readonly string $token,
        private readonly string $repo,
        private readonly string $branch,
    ) {
        $this->logger = CP_Logger::get_instance();
    }

    public function check_repo_exists(): bool|\WP_Error {
        $response = $this->api_request( "repos/{$this->repo}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            return true;
        } elseif ( $status_code === 404 ) {
            return false;
        } else {
            return new WP_Error( 'api_error', '指定されたリポジトリへのアクセス中にエラーが発生しました。' );
        }
    }

    public function create_repo(): bool|\WP_Error {
        list( $owner, $repo_name ) = explode( '/', $this->repo );

        $body = array(
            'name' => $repo_name,
            'private' => true,
            'auto_init' => false,
        );

        $response = $this->api_request( 'user/repos', 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 201 ) {
            $this->logger->info( "リポジトリ {$this->repo} を作成しました" );
            return true;
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $message = $body['message'] ?? 'リポジトリの作成に失敗しました';
            return new WP_Error( 'create_repo_failed', $message );
        }
    }

    public function check_branch_exists(): bool|\WP_Error {
        $response = $this->api_request( "repos/{$this->repo}/branches/{$this->branch}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            return true;
        } elseif ( $status_code === 404 ) {
            return false;
        } else {
            return new WP_Error( 'api_error', 'ブランチの確認中にエラーが発生しました。' );
        }
    }

    public function get_default_branch(): string|\WP_Error {
        $response = $this->api_request( "repos/{$this->repo}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['default_branch'] ) ) {
            return $body['default_branch'];
        }

        return new WP_Error( 'no_default_branch', 'デフォルトブランチの取得に失敗しました。' );
    }

    public function push_files( array $files, string $commit_message ): bool|\WP_Error {
        $is_empty = $this->is_repo_empty();

        if ( is_wp_error( $is_empty ) ) {
            return $is_empty;
        }

        if ( $is_empty ) {
            return $this->create_initial_file( $files, $commit_message );
        } else {
            return $this->update_files( $files, $commit_message );
        }
    }

    private function is_repo_empty(): bool|\WP_Error {
        $response = $this->api_request( "repos/{$this->repo}/contents", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 404 ) {
            return true;
        } elseif ( $status_code === 200 ) {
            return false;
        } else {
            return new WP_Error( 'api_error', 'リポジトリの状態確認中にエラーが発生しました。' );
        }
    }

    /** 空リポジトリの初期化: Contents APIで1ファイル作成後、Git Data APIで全ファイル追加 */
    private function create_initial_file( array $files, string $commit_message ): bool|\WP_Error {
        $this->logger->debug( '空のリポジトリに初回コミットを作成します (' . count( $files ) . 'ファイル)' );

        $body = array(
            'message' => $commit_message,
            'content' => base64_encode( '' ),
            'branch' => $this->branch,
        );

        $response = $this->api_request( "repos/{$this->repo}/contents/index.html", 'PUT', $body );

        if ( is_wp_error( $response ) ) {
            $this->logger->error( '初期ファイルの作成に失敗しました: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 201 ) {
            $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $response_body['message'] ?? '初期ファイルの作成に失敗しました';
            $this->logger->error( "初期ファイル作成エラー: {$error_message} (Status: {$status_code})" );
            return new WP_Error( 'create_initial_file_failed', $error_message );
        }

        $this->logger->debug( '初期ファイルを作成しました。すべてのファイルを追加します...' );

        return $this->push_files_via_tree( $files, $commit_message, true );
    }

    private function update_files( array $files, string $commit_message ): bool|\WP_Error {
        $success_count = 0;
        $error_count = 0;

        foreach ( $files as $path => $content ) {
            $sha = $this->get_file_sha( $path );

            $body = array(
                'message' => $commit_message,
                'content' => base64_encode( $content ),
                'branch' => $this->branch,
            );

            if ( ! is_wp_error( $sha ) && $sha !== false ) {
                $body['sha'] = $sha;
            }

            $response = $this->api_request( "repos/{$this->repo}/contents/{$path}", 'PUT', $body );

            if ( is_wp_error( $response ) ) {
                $this->logger->error( "ファイルの更新に失敗しました: {$path} - " . $response->get_error_message() );
                $error_count++;
            } else {
                $status_code = wp_remote_retrieve_response_code( $response );
                if ( $status_code === 200 || $status_code === 201 ) {
                    $success_count++;
                } else {
                    $this->logger->error( "ファイルの更新に失敗しました: {$path}" );
                    $error_count++;
                }
            }
        }

        $this->logger->debug( "{$success_count}個のファイルを更新しました" );

        if ( $error_count > 0 ) {
            return new WP_Error( 'update_files_partial', "{$error_count}個のファイルの更新に失敗しました。" );
        }

        return true;
    }

    private function get_file_sha( string $path ): string|false|\WP_Error {
        $response = $this->api_request( "repos/{$this->repo}/contents/{$path}", 'GET', null, array( 'ref' => $this->branch ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            return $body['sha'] ?? false;
        } elseif ( $status_code === 404 ) {
            return false;
        } else {
            return new WP_Error( 'get_sha_failed', 'ファイル情報の取得に失敗しました。' );
        }
    }

    public function push_file( string $path, string $content, string $commit_message ): bool|\WP_Error {
        $sha = $this->get_file_sha( $path );

        $body = array(
            'message' => $commit_message,
            'content' => base64_encode( $content ),
            'branch' => $this->branch,
        );

        if ( ! is_wp_error( $sha ) && $sha !== false ) {
            $body['sha'] = $sha;
        }

        $response = $this->api_request( "repos/{$this->repo}/contents/{$path}", 'PUT', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code === 200 || $status_code === 201 ) {
            return true;
        } else {
            return new WP_Error( 'push_file_failed', "ファイルのプッシュに失敗しました: {$path}" );
        }
    }

    public function push_files_batch_from_disk( array $file_paths, string $base_dir, string $commit_message, int $batch_size = 300, bool $force_full_push = false ): bool|\WP_Error {
        $start_time = microtime( true );
        $this->logger->debug( 'GitHubプッシュ開始: ' . date( 'Y-m-d H:i:s' ) );

        if ( ! is_dir( $base_dir ) ) {
            $this->logger->error( '一時ディレクトリが存在しません: ' . $base_dir );
            return new WP_Error( 'temp_dir_not_found', '一時ディレクトリが存在しません' );
        }

        $changed_file_paths = $file_paths;
        if ( ! $force_full_push ) {
            $this->logger->debug( "差分検出を実行中..." );
            $changed_file_paths = $this->get_changed_file_paths_from_disk( $file_paths, $base_dir );

            if ( is_wp_error( $changed_file_paths ) ) {
                $this->logger->debug( '差分検出失敗: 全ファイルをプッシュします' );
                $changed_file_paths = $file_paths;
            } elseif ( empty( $changed_file_paths ) ) {
                $this->logger->debug( '変更なし: GitHubへのプッシュをスキップしました' );
                return true;
            }
        } else {
            $this->logger->debug( "強制全体プッシュ: " . count( $file_paths ) . "個のファイルをプッシュします" );
        }

        $path_batches = array_chunk( $changed_file_paths, $batch_size );
        $total_batches = count( $path_batches );

        if ( $total_batches > 1 ) {
            $this->logger->debug( "合計 {$total_batches} バッチでコミットします（各バッチ最大{$batch_size}ファイル）" );
        }

        foreach ( $path_batches as $batch_index => $batch_paths ) {
            $batch_num = $batch_index + 1;

            $batch_message = $commit_message;
            if ( $total_batches > 1 ) {
                $batch_message .= " (batch {$batch_num}/{$total_batches})";
            }

            if ( $batch_index > 0 ) {
                sleep( 2 );
            }

            $batch_files = array();
            foreach ( $batch_paths as $relative_path ) {
                $relative_path = str_replace( '\\', '/', $relative_path );
                $full_path = trailingslashit( $base_dir ) . $relative_path;

                if ( is_readable( $full_path ) ) {
                    $content = file_get_contents( $full_path );
                    if ( $content !== false ) {
                        $batch_files[ $relative_path ] = $content;
                    }
                }
            }

            $result = $this->push_files_via_tree( $batch_files, $batch_message, true );
            unset( $batch_files );

            if ( is_wp_error( $result ) ) {
                $this->logger->error( "バッチ {$batch_num} の処理に失敗しました: " . $result->get_error_message() );
                return $result;
            }

            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
        }

        $total_elapsed = microtime( true ) - $start_time;
        $this->logger->debug( sprintf(
            'GitHubプッシュ完了: %s (合計処理時間: %.2f秒 / %.2f分)',
            date( 'Y-m-d H:i:s' ),
            $total_elapsed,
            $total_elapsed / 60
        ) );

        return true;
    }

    public function push_files_batch( array $files, string $commit_message, int $batch_size = 500 ): bool|\WP_Error {
        $file_batches = array_chunk( $files, $batch_size, true );
        $total_batches = count( $file_batches );

        $this->logger->debug( "合計 {$total_batches} バッチでコミットします（各バッチ最大{$batch_size}ファイル）" );

        foreach ( $file_batches as $batch_index => $batch_files ) {
            $batch_num = $batch_index + 1;
            $file_count = count( $batch_files );

            $batch_message = $commit_message;
            if ( $total_batches > 1 ) {
                $batch_message .= " (batch {$batch_num}/{$total_batches})";
            }

            $this->logger->debug( "バッチ {$batch_num}/{$total_batches} を処理中 ({$file_count}ファイル)..." );

            $result = $this->push_files_via_tree( $batch_files, $batch_message );

            if ( is_wp_error( $result ) ) {
                $this->logger->error( "バッチ {$batch_num} の処理に失敗しました: " . $result->get_error_message() );
                return $result;
            }

            $this->logger->debug( "バッチ {$batch_num}/{$total_batches} の処理が完了しました" );
        }

        $this->logger->debug( "すべてのバッチ処理が完了しました" );
        return true;
    }

    private function push_files_via_tree( array $files, string $commit_message, bool $force_full_push = false ): bool|\WP_Error {
        $latest_commit = $this->get_latest_commit();

        if ( is_wp_error( $latest_commit ) ) {
            if ( $latest_commit->get_error_code() === 'branch_not_found' ) {
                $this->logger->debug( "空のリポジトリ: 初回コミットを作成します" );
                return $this->create_initial_file( $files, $commit_message );
            }
            return $latest_commit;
        }

        $base_tree = $latest_commit['tree']['sha'];

        $files_to_push = $files;
        if ( ! $force_full_push ) {
            $files_to_push = $this->get_changed_files( $files, $base_tree );

            if ( empty( $files_to_push ) ) {
                $this->logger->debug( '変更なし: GitHubへのプッシュをスキップしました' );
                return true;
            }
        } else {
            $this->logger->debug( "バッチプッシュ: " . count( $files ) . "個のファイルをプッシュします（差分検出済み）" );
        }

        $this->logger->debug( "Blob並列作成開始: " . count( $files_to_push ) . "個のファイル" );
        $tree_items = $this->create_blobs_parallel( $files_to_push, 10 );

        if ( is_wp_error( $tree_items ) ) {
            return $tree_items;
        }

        $this->logger->debug( "すべてのBlob作成完了 (" . count( $tree_items ) . "個)" );

        $tree_sha = $this->create_tree( $tree_items, $base_tree );
        if ( is_wp_error( $tree_sha ) ) {
            return $tree_sha;
        }

        $commit_sha = $this->create_commit( $commit_message, $tree_sha, array( $latest_commit['sha'] ) );
        if ( is_wp_error( $commit_sha ) ) {
            return $commit_sha;
        }

        $result = $this->update_branch_ref( $commit_sha );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return true;
    }

    private function get_latest_commit(): array|\WP_Error {
        $response = $this->api_request( "repos/{$this->repo}/git/refs/heads/{$this->branch}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 404 || $status_code === 409 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $body['message'] ?? 'ブランチが見つかりません';
            $this->logger->debug( '空のリポジトリまたはブランチ未作成: ' . $error_message );
            return new WP_Error( 'branch_not_found', $error_message );
        }

        if ( $status_code !== 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $body['message'] ?? 'コミット情報の取得に失敗しました';
            $this->logger->error( 'GitHub API エラー (get ref): ' . $error_message . ' (Status: ' . $status_code . ')' );
            return new WP_Error( 'get_commit_failed', $error_message );
        }

        $ref_data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $ref_data['object']['sha'] ) ) {
            $this->logger->error( 'GitHub API レスポンスが不正です: ' . wp_json_encode( $ref_data ) );
            return new WP_Error( 'invalid_response', 'GitHub APIのレスポンスが不正です' );
        }

        $commit_sha = $ref_data['object']['sha'];

        $response = $this->api_request( "repos/{$this->repo}/git/commits/{$commit_sha}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $body['message'] ?? 'コミット詳細の取得に失敗しました';
            $this->logger->error( 'GitHub API エラー (get commit): ' . $error_message . ' (Status: ' . $status_code . ')' );
            return new WP_Error( 'get_commit_failed', $error_message );
        }

        $commit_data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $commit_data['tree']['sha'] ) ) {
            $this->logger->error( 'コミットデータが不正です: ' . wp_json_encode( $commit_data ) );
            return new WP_Error( 'invalid_commit_data', 'コミットデータが不正です' );
        }

        $commit_data['sha'] = $commit_sha;

        return $commit_data;
    }

    private function create_blob( string $content ): string|\WP_Error {
        $body = array(
            'content' => base64_encode( $content ),
            'encoding' => 'base64',
        );

        $response = $this->api_request( "repos/{$this->repo}/git/blobs", 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 201 ) {
            $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $response_body['message'] ?? 'Blobの作成に失敗しました';

            $error_details = "Status: {$status_code}, Message: {$error_message}";
            if ( isset( $response_body['errors'] ) ) {
                $error_details .= ', Errors: ' . wp_json_encode( $response_body['errors'] );
            }

            return new WP_Error( 'create_blob_failed', $error_details );
        }

        $blob_data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $blob_data['sha'];
    }

    private function create_blobs_parallel( array $files, int $concurrency = 10 ): array|\WP_Error {
        if ( ! class_exists( 'WpOrg\Requests\Requests' ) ) {
            $this->logger->debug( 'Requestsライブラリが利用できないため、順次処理を使用します' );
            return $this->create_blobs_sequential( $files );
        }

        $tree_items = array();
        $failed_files = array();
        $file_paths = array_keys( $files );
        $chunks = array_chunk( $file_paths, $concurrency, true );

        $total_chunks = count( $chunks );
        $processed = 0;

        foreach ( $chunks as $chunk_index => $chunk_paths ) {
            $requests = array();
            $path_map = array();

            foreach ( $chunk_paths as $original_index => $path ) {
                $content = $files[ $path ];
                $request_index = count( $requests );
                $path_map[ $request_index ] = $path;

                $requests[] = array(
                    'url' => "https://api.github.com/repos/{$this->repo}/git/blobs",
                    'type' => 'POST',
                    'headers' => array(
                        'Authorization' => 'token ' . $this->token,
                        'Accept' => 'application/vnd.github.v3+json',
                        'User-Agent' => 'Carry-Pod/' . CP_VERSION,
                        'Content-Type' => 'application/json',
                    ),
                    'data' => wp_json_encode( array(
                        'content' => base64_encode( $content ),
                        'encoding' => 'base64',
                    ) ),
                );
            }

            try {
                $responses = \WpOrg\Requests\Requests::request_multiple( $requests, array(
                    'timeout'         => 300,
                    'connect_timeout' => 30,
                ) );

                foreach ( $responses as $request_index => $response ) {
                    $path = $path_map[ $request_index ];

                    if ( is_a( $response, 'WpOrg\Requests\Response' ) ) {
                        if ( $response->status_code === 201 ) {
                            $data = json_decode( $response->body, true );
                            if ( isset( $data['sha'] ) ) {
                                $tree_items[] = array(
                                    'path' => $path,
                                    'mode' => '100644',
                                    'type' => 'blob',
                                    'sha' => $data['sha'],
                                );
                                $processed++;
                            } else {
                                $this->logger->error( "Blob作成エラー: {$path} - レスポンスにSHAがありません" );
                                return new WP_Error( 'create_blob_failed', "Blob作成に失敗: {$path}" );
                            }
                        } else {
                            if ( isset( $response->headers['x-ratelimit-remaining'] ) ) {
                                $remaining = intval( $response->headers['x-ratelimit-remaining'] );
                                if ( $remaining < 100 ) {
                                    $this->logger->warning( "APIレート制限警告: 残り {$remaining} リクエスト" );
                                }
                            }

                            $error_body = json_decode( $response->body, true );
                            $error_message = $error_body['message'] ?? 'Blob作成失敗';

                            // セカンダリレート制限: フォールバック
                            if ( $response->status_code === 403 && str_contains( $error_message, 'secondary rate limit' ) ) {
                                $this->logger->warning( "セカンダリレート制限検出: 60秒待機してリトライします..." );
                                sleep( 60 );
                                return $this->create_blobs_sequential( $files );
                            }

                            $this->logger->error( "Blob作成エラー: {$path} - {$error_message} (Status: {$response->status_code})" );
                            return new WP_Error( 'create_blob_failed', "Blob作成に失敗: {$path} - {$error_message}" );
                        }
                    } else {
                        $response_type = gettype( $response );
                        $error_details = "不正なレスポンス (型: {$response_type})";
                        $is_network_exception = false;
                        if ( is_object( $response ) ) {
                            $class_name = get_class( $response );
                            $error_details .= ', クラス: ' . $class_name;

                            if ( str_contains( $class_name, 'Exception' ) ) {
                                $is_network_exception = true;
                            }

                            if ( is_wp_error( $response ) ) {
                                $error_details .= ', エラー: ' . $response->get_error_message();
                            }

                            $properties = get_object_vars( $response );
                            if ( ! empty( $properties ) ) {
                                $error_details .= ', プロパティ: ' . implode( ', ', array_keys( $properties ) );
                            }
                        } elseif ( is_array( $response ) ) {
                            $error_details .= ', キー: ' . implode( ', ', array_keys( $response ) );
                        } elseif ( is_string( $response ) ) {
                            $error_details .= ', 値: ' . substr( $response, 0, 100 );
                        }

                        if ( $is_network_exception ) {
                            $failed_files[ $path ] = $files[ $path ];
                            $this->logger->debug( "ネットワークエラー: {$path} - リトライ対象に追加" );
                            continue;
                        }

                        $this->logger->error( "Blob作成エラー: {$path} - {$error_details}" );
                        return new WP_Error( 'create_blob_failed', "Blob作成に失敗: {$path} - {$error_details}" );
                    }
                }

            } catch ( Exception $e ) {
                $this->logger->debug( '並列リクエスト例外: ' . $e->getMessage() );
                foreach ( $chunk_paths as $chunk_path ) {
                    if ( ! isset( $failed_files[ $chunk_path ] ) && isset( $files[ $chunk_path ] ) ) {
                        $failed_files[ $chunk_path ] = $files[ $chunk_path ];
                    }
                }
            }

            if ( $total_chunks > 1 ) {
                $chunk_num = $chunk_index + 1;
                $this->logger->debug( "並列Blob作成: {$processed}/" . count( $files ) . "個完了 ({$chunk_num}/{$total_chunks})" );
            }

            if ( $chunk_index < $total_chunks - 1 ) {
                usleep( 500000 );
            }
        }

        if ( ! empty( $failed_files ) ) {
            $this->logger->debug( "リトライ処理開始: " . count( $failed_files ) . "個のファイル" );

            for ( $retry = 1; $retry <= 3; $retry++ ) {
                $wait_time = pow( 2, $retry ) * 5;
                $this->logger->debug( "リトライ {$retry}/3: {$wait_time}秒待機中..." );
                sleep( $wait_time );

                $retry_result = $this->create_blobs_sequential( $failed_files );

                if ( ! is_wp_error( $retry_result ) ) {
                    $tree_items = array_merge( $tree_items, $retry_result );
                    $failed_files = array();
                    $this->logger->debug( "リトライ成功: 全ファイルのBlob作成完了" );
                    break;
                }
            }

            if ( ! empty( $failed_files ) ) {
                $failed_count = count( $failed_files );
                $this->logger->error( "{$failed_count}個のファイルのBlob作成に失敗しました" );
                return new WP_Error( 'create_blob_failed', "{$failed_count}個のファイルのBlob作成に失敗しました（3回リトライ後）" );
            }
        }

        return $tree_items;
    }

    private function create_blobs_sequential( array $files ): array|\WP_Error {
        $tree_items = array();
        $max_retries = 3;

        foreach ( $files as $path => $content ) {
            $success = false;
            $last_error = null;

            for ( $retry = 0; $retry <= $max_retries; $retry++ ) {
                if ( $retry > 0 ) {
                    $wait = $retry * 5;
                    $this->logger->debug( "リトライ {$retry}/{$max_retries}: {$path} ({$wait}秒待機)" );
                    sleep( $wait );
                }

                $blob_sha = $this->create_blob( $content );

                if ( ! is_wp_error( $blob_sha ) ) {
                    $tree_items[] = array(
                        'path' => $path,
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha' => $blob_sha,
                    );
                    $success = true;
                    break;
                }

                $last_error = $blob_sha;
            }

            if ( ! $success ) {
                $this->logger->error( "Blob作成エラー: {$path} - " . $last_error->get_error_message() . " (全リトライ失敗)" );
                return $last_error;
            }
        }

        return $tree_items;
    }

    private function get_changed_files( array $files, string $base_tree_sha ): array {
        $response = $this->api_request( "repos/{$this->repo}/git/trees/{$base_tree_sha}", 'GET', null, array( 'recursive' => '1' ) );

        if ( is_wp_error( $response ) ) {
            $this->logger->debug( 'ツリー取得失敗、全ファイルをプッシュします: ' . $response->get_error_message() );
            return $files;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            $this->logger->debug( "ツリー取得失敗 (Status: {$status_code})、全ファイルをプッシュします" );
            return $files;
        }

        $tree_data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $tree_data['tree'] ) ) {
            $this->logger->debug( 'ツリーデータが不正、全ファイルをプッシュします' );
            return $files;
        }

        $existing_files = array();
        foreach ( $tree_data['tree'] as $item ) {
            if ( $item['type'] === 'blob' ) {
                $existing_files[ $item['path'] ] = $item['sha'];
            }
        }

        $changed_files = array();
        foreach ( $files as $path => $content ) {
            // Git Blob SHA（git hash-object アルゴリズム）
            $blob_content = 'blob ' . strlen( $content ) . "\0" . $content;
            $new_sha = sha1( $blob_content );

            if ( ! isset( $existing_files[ $path ] ) || $existing_files[ $path ] !== $new_sha ) {
                $changed_files[ $path ] = $content;
            }
        }

        $total_files = count( $files );
        $changed_count = count( $changed_files );
        $unchanged_count = $total_files - $changed_count;

        $this->logger->debug( "差分検出: {$changed_count}個が変更、{$unchanged_count}個が未変更（全{$total_files}個）" );

        return $changed_files;
    }

    private function get_changed_file_paths_from_disk( array $file_paths, string $base_dir ): array|\WP_Error {
        $latest_commit = $this->get_latest_commit();

        if ( is_wp_error( $latest_commit ) ) {
            if ( $latest_commit->get_error_code() === 'branch_not_found' ) {
                return $file_paths;
            }
            return $latest_commit;
        }

        $base_tree = $latest_commit['tree']['sha'];

        $response = $this->api_request( "repos/{$this->repo}/git/trees/{$base_tree}", 'GET', null, array( 'recursive' => '1' ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return new WP_Error( 'tree_fetch_failed', "ツリー取得失敗 (Status: {$status_code})" );
        }

        $tree_data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! isset( $tree_data['tree'] ) ) {
            return new WP_Error( 'tree_data_invalid', 'ツリーデータが不正' );
        }

        $existing_files = array();
        foreach ( $tree_data['tree'] as $item ) {
            if ( $item['type'] === 'blob' ) {
                $existing_files[ $item['path'] ] = $item['sha'];
            }
        }

        $changed_file_paths = array();
        foreach ( $file_paths as $relative_path ) {
            $relative_path = str_replace( '\\', '/', $relative_path );
            $full_path = trailingslashit( $base_dir ) . $relative_path;

            if ( ! is_readable( $full_path ) ) {
                continue;
            }
            $content = file_get_contents( $full_path );
            if ( $content === false ) {
                continue;
            }

            $blob_content = 'blob ' . strlen( $content ) . "\0" . $content;
            $new_sha = sha1( $blob_content );

            if ( ! isset( $existing_files[ $relative_path ] ) || $existing_files[ $relative_path ] !== $new_sha ) {
                $changed_file_paths[] = $relative_path;
            }
        }

        $total_files = count( $file_paths );
        $changed_count = count( $changed_file_paths );
        $unchanged_count = $total_files - $changed_count;

        $this->logger->debug( "差分検出: {$changed_count}個が変更、{$unchanged_count}個が未変更（全{$total_files}個）" );

        return $changed_file_paths;
    }

    private function create_tree( array $tree_items, ?string $base_tree = null ): string|\WP_Error {
        $body = array(
            'tree' => $tree_items,
        );

        if ( $base_tree ) {
            $body['base_tree'] = $base_tree;
        }

        $response = $this->api_request( "repos/{$this->repo}/git/trees", 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 201 ) {
            $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $response_body['message'] ?? 'ツリーの作成に失敗しました';

            $this->logger->error( 'GitHub API エラー (create tree): ' . $error_message . ' (Status: ' . $status_code . ')' );

            if ( isset( $response_body['errors'] ) ) {
                $this->logger->error( 'エラー詳細: ' . wp_json_encode( $response_body['errors'] ) );
            }

            return new WP_Error( 'create_tree_failed', $error_message );
        }

        $tree_data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $tree_data['sha'];
    }

    private function create_commit( string $message, string $tree, array $parents = array() ): string|\WP_Error {
        $body = array(
            'message' => $message,
            'tree' => $tree,
            'parents' => $parents,
        );

        $response = $this->api_request( "repos/{$this->repo}/git/commits", 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 201 ) {
            return new WP_Error( 'create_commit_failed', 'コミットの作成に失敗しました' );
        }

        $commit_data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $commit_data['sha'];
    }

    private function create_branch_ref( string $commit_sha ): bool|\WP_Error {
        $body = array(
            'ref' => 'refs/heads/' . $this->branch,
            'sha' => $commit_sha,
        );

        $response = $this->api_request( "repos/{$this->repo}/git/refs", 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 201 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $body['message'] ?? 'ブランチ参照の作成に失敗しました';
            $this->logger->error( 'GitHub API エラー (create ref): ' . $error_message . ' (Status: ' . $status_code . ')' );
            return new WP_Error( 'create_ref_failed', $error_message );
        }

        return true;
    }

    private function update_branch_ref( string $commit_sha ): bool|\WP_Error {
        $body = array(
            'sha' => $commit_sha,
            'force' => false,
        );

        $response = $this->api_request( "repos/{$this->repo}/git/refs/heads/{$this->branch}", 'PATCH', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = $response_body['message'] ?? 'ブランチ参照の更新に失敗しました';
            $this->logger->error( "GitHub API エラー (update ref): {$error_message} (Status: {$status_code})" );

            return new WP_Error( 'update_ref_failed', $error_message );
        }

        return true;
    }

    private function api_request( string $endpoint, string $method = 'GET', ?array $body = null, ?array $query = null ): array|\WP_Error {
        $url = "https://api.github.com/{$endpoint}";

        if ( $query ) {
            $url = add_query_arg( $query, $url );
        }

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'token ' . $this->token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Carry-Pod/' . CP_VERSION,
            ),
            'timeout' => 300,
        );

        if ( $body ) {
            $args['body'] = wp_json_encode( $body );
            $args['headers']['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
        if ( $remaining !== null && intval( $remaining ) === 0 ) {
            return new WP_Error( 'rate_limit', 'GitHubのAPIレート制限に達しました。しばらく待ってから再実行してください。' );
        }

        return $response;
    }
}
