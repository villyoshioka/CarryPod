<?php
/**
 * GitLab API連携クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_GitLab_API implements CP_Git_Provider_Interface {

    private string $api_url;
    private readonly string $project_id;
    private CP_Logger $logger;

    public function __construct(
        private readonly string $token,
        private readonly string $project_path,
        private readonly string $branch,
        string $api_url = 'https://gitlab.com/api/v4',
    ) {
        $this->project_id = rawurlencode( $project_path );
        $this->api_url = rtrim( $api_url, '/' );
        $this->logger = CP_Logger::get_instance();
    }

    private function api_request( string $endpoint, string $method = 'GET', ?array $body = null ): array|\WP_Error {
        $url = $this->api_url . '/' . ltrim( $endpoint, '/' );

        $args = array(
            'method'  => $method,
            'timeout' => 60,
            'headers' => array(
                'PRIVATE-TOKEN' => $this->token,
                'Content-Type'  => 'application/json',
            ),
        );

        if ( $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    #[\Override]
    public function check_repo_exists(): bool|\WP_Error {
        $response = $this->api_request( "projects/{$this->project_id}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            return true;
        } elseif ( $status_code === 404 ) {
            return false;
        } else {
            return new WP_Error( 'api_error', '指定されたプロジェクトへのアクセス中にエラーが発生しました。' );
        }
    }

    #[\Override]
    public function create_repo(): bool|\WP_Error {
        $parts = explode( '/', $this->project_path );
        $project_name = end( $parts );
        $namespace_path = count( $parts ) > 1 ? implode( '/', array_slice( $parts, 0, -1 ) ) : null;

        $body = array(
            'name'       => $project_name,
            'path'       => $project_name,
            'visibility' => 'private',
        );

        if ( $namespace_path ) {
            $namespace_id = $this->get_namespace_id( $namespace_path );
            if ( is_wp_error( $namespace_id ) ) {
                $this->logger->debug( '名前空間が見つからないため、個人プロジェクトとして作成します' );
            } else {
                $body['namespace_id'] = $namespace_id;
            }
        }

        $response = $this->api_request( 'projects', 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 201 ) {
            $this->logger->info( "プロジェクト {$this->project_path} を作成しました" );
            return true;
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $message = isset( $body['message'] ) ? wp_json_encode( $body['message'] ) : 'プロジェクトの作成に失敗しました';
            return new WP_Error( 'create_project_failed', $message );
        }
    }

    private function get_namespace_id( string $namespace_path ): int|\WP_Error {
        $response = $this->api_request( 'namespaces?search=' . rawurlencode( $namespace_path ), 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body ) ) {
            foreach ( $body as $namespace ) {
                if ( $namespace['full_path'] === $namespace_path || $namespace['path'] === $namespace_path ) {
                    return $namespace['id'];
                }
            }
        }

        return new WP_Error( 'namespace_not_found', '名前空間が見つかりません' );
    }

    #[\Override]
    public function check_branch_exists(): bool|\WP_Error {
        $branch_encoded = rawurlencode( $this->branch );
        $response = $this->api_request( "projects/{$this->project_id}/repository/branches/{$branch_encoded}", 'GET' );

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

    #[\Override]
    public function get_default_branch(): string|\WP_Error {
        $response = $this->api_request( "projects/{$this->project_id}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['default_branch'] ) ) {
            return $body['default_branch'];
        }

        return new WP_Error( 'no_default_branch', 'デフォルトブランチの取得に失敗しました。' );
    }

    #[\Override]
    public function push_files_batch_from_disk( array $file_paths, string $base_dir, string $commit_message, int $batch_size = 300 ): bool|\WP_Error {
        $start_time = microtime( true );
        $this->logger->debug( 'GitLabプッシュ開始: ' . date( 'Y-m-d H:i:s' ) );

        if ( ! is_dir( $base_dir ) ) {
            $this->logger->error( '一時ディレクトリが存在しません: ' . $base_dir );
            return new WP_Error( 'temp_dir_not_found', '一時ディレクトリが存在しません' );
        }

        $existing_files = $this->get_repository_tree_with_sha();
        $existing_file_map = array();
        if ( ! is_wp_error( $existing_files ) ) {
            foreach ( $existing_files as $file ) {
                if ( $file['type'] === 'blob' ) {
                    $existing_file_map[ $file['path'] ] = $file['id'] ?? null;
                }
            }
        }

        $changed_file_paths = $this->get_changed_file_paths_from_disk( $file_paths, $base_dir, $existing_file_map );

        if ( empty( $changed_file_paths ) ) {
            $this->logger->debug( '変更なし: GitLabへのプッシュをスキップしました' );
            return true;
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
                sleep( 3 );
            }

            $actions = array();
            foreach ( $batch_paths as $relative_path ) {
                $relative_path = str_replace( '\\', '/', $relative_path );
                $full_path = trailingslashit( $base_dir ) . $relative_path;

                if ( ! is_readable( $full_path ) ) {
                    continue;
                }
                $content = file_get_contents( $full_path );
                if ( $content === false ) {
                    continue;
                }

                $action = array_key_exists( $relative_path, $existing_file_map ) ? 'update' : 'create';

                $actions[] = array(
                    'action'    => $action,
                    'file_path' => $relative_path,
                    'content'   => base64_encode( $content ),
                    'encoding'  => 'base64',
                );

                $existing_file_map[ $relative_path ] = 'new';
            }

            if ( empty( $actions ) ) {
                continue;
            }

            $result = $this->create_commit( $actions, $batch_message );

            if ( is_wp_error( $result ) ) {
                $this->logger->error( "バッチ {$batch_num} の処理に失敗しました: " . $result->get_error_message() );
                return $result;
            }

            unset( $actions );
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
        }

        $total_elapsed = microtime( true ) - $start_time;
        $this->logger->debug( sprintf(
            'GitLabプッシュ完了: %s (合計処理時間: %.2f秒)',
            date( 'Y-m-d H:i:s' ),
            $total_elapsed
        ) );

        return true;
    }

    private function get_repository_tree(): array|\WP_Error {
        $all_files = array();
        $page = 1;
        $per_page = 100;

        do {
            $branch_encoded = rawurlencode( $this->branch );
            $response = $this->api_request(
                "projects/{$this->project_id}/repository/tree?ref={$branch_encoded}&recursive=true&per_page={$per_page}&page={$page}",
                'GET'
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            if ( $status_code !== 200 ) {
                if ( $status_code === 404 ) {
                    return array();
                }
                return new WP_Error( 'tree_fetch_failed', 'リポジトリツリーの取得に失敗しました' );
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( empty( $body ) ) {
                break;
            }

            $all_files = array_merge( $all_files, $body );
            $page++;

            $total_pages = wp_remote_retrieve_header( $response, 'x-total-pages' );
            if ( $total_pages && $page > (int) $total_pages ) {
                break;
            }

        } while ( count( $body ) === $per_page );

        return $all_files;
    }

    /** ツリーAPIのidフィールドにGit blob SHAが含まれる */
    private function get_repository_tree_with_sha(): array|\WP_Error {
        return $this->get_repository_tree();
    }

    private function get_changed_file_paths_from_disk( array $file_paths, string $base_dir, array $existing_file_map ): array {
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

            if ( ! array_key_exists( $relative_path, $existing_file_map ) ) {
                $changed_file_paths[] = $relative_path;
                continue;
            }

            $existing_sha = $existing_file_map[ $relative_path ];
            if ( empty( $existing_sha ) ) {
                $changed_file_paths[] = $relative_path;
                continue;
            }

            $blob_content = 'blob ' . strlen( $content ) . "\0" . $content;
            $new_sha = sha1( $blob_content );

            if ( $existing_sha !== $new_sha ) {
                $changed_file_paths[] = $relative_path;
            }
        }

        $total_files = count( $file_paths );
        $changed_count = count( $changed_file_paths );
        $unchanged_count = $total_files - $changed_count;

        $this->logger->debug( "差分検出: {$changed_count}個が変更、{$unchanged_count}個が未変更（全{$total_files}個）" );

        return $changed_file_paths;
    }

    private function create_commit( array $actions, string $commit_message ): bool|\WP_Error {
        $body = array(
            'branch'         => $this->branch,
            'commit_message' => $commit_message,
            'actions'        => $actions,
        );

        $branch_exists = $this->check_branch_exists();
        if ( $branch_exists === false ) {
            $default_branch = $this->get_default_branch();
            if ( ! is_wp_error( $default_branch ) ) {
                $body['start_branch'] = $default_branch;
            }
        }

        $response = $this->api_request( "projects/{$this->project_id}/repository/commits", 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 201 ) {
            return true;
        } else {
            $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $message = $response_body['message'] ?? 'コミットの作成に失敗しました';

            if ( is_array( $message ) ) {
                $message = wp_json_encode( $message );
            }

            return new WP_Error( 'commit_failed', $message );
        }
    }

    public function create_branch( string $ref ): bool|\WP_Error {
        $body = array(
            'branch' => $this->branch,
            'ref'    => $ref,
        );

        $response = $this->api_request( "projects/{$this->project_id}/repository/branches", 'POST', $body );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 201 ) {
            $this->logger->info( "ブランチ {$this->branch} を作成しました" );
            return true;
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $message = $body['message'] ?? 'ブランチの作成に失敗しました';
            return new WP_Error( 'create_branch_failed', $message );
        }
    }

    public function test_connection(): true|\WP_Error {
        $response = $this->api_request( 'user', 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            return isset( $body['username'] ) ? true : new WP_Error( 'invalid_response', '無効なレスポンス' );
        } elseif ( $status_code === 401 ) {
            return new WP_Error( 'unauthorized', 'アクセストークンが無効です' );
        } else {
            return new WP_Error( 'api_error', 'API接続に失敗しました（ステータス: ' . $status_code . '）' );
        }
    }
}
