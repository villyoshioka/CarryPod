<?php
/**
 * GitLab API連携クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_GitLab_API implements CP_Git_Provider_Interface {

    /**
     * GitLab API URL
     */
    private $api_url;

    /**
     * GitLabアクセストークン
     */
    private $token;

    /**
     * プロジェクトパス（namespace/project形式）
     */
    private $project_path;

    /**
     * プロジェクトID（URLエンコード済み）
     */
    private $project_id;

    /**
     * ブランチ名
     */
    private $branch;

    /**
     * ロガーインスタンス
     */
    private $logger;

    /**
     * コンストラクタ
     *
     * @param string $token GitLabアクセストークン
     * @param string $project_path プロジェクトパス（namespace/project形式）
     * @param string $branch ブランチ名
     * @param string $api_url GitLab API URL（デフォルト: gitlab.com）
     */
    public function __construct( $token, $project_path, $branch, $api_url = 'https://gitlab.com/api/v4' ) {
        $this->token = $token;
        $this->project_path = $project_path;
        $this->project_id = rawurlencode( $project_path );
        $this->branch = $branch;
        $this->api_url = rtrim( $api_url, '/' );
        $this->logger = CP_Logger::get_instance();
    }

    /**
     * API リクエストを実行
     *
     * @param string $endpoint APIエンドポイント
     * @param string $method HTTPメソッド
     * @param array|null $body リクエストボディ
     * @return array|WP_Error レスポンスまたはWP_Error
     */
    private function api_request( $endpoint, $method = 'GET', $body = null ) {
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

    /**
     * リポジトリが存在するかチェック
     *
     * @return bool|WP_Error 存在すればtrue、存在しなければfalse、エラーならWP_Error
     */
    public function check_repo_exists() {
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

    /**
     * リポジトリを作成
     *
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function create_repo() {
        $parts = explode( '/', $this->project_path );
        $project_name = end( $parts );
        $namespace_path = count( $parts ) > 1 ? implode( '/', array_slice( $parts, 0, -1 ) ) : null;

        $body = array(
            'name'       => $project_name,
            'path'       => $project_name,
            'visibility' => 'private',
        );

        // 名前空間が指定されている場合はIDを取得
        if ( $namespace_path ) {
            $namespace_id = $this->get_namespace_id( $namespace_path );
            if ( is_wp_error( $namespace_id ) ) {
                // 名前空間が見つからない場合は個人プロジェクトとして作成
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

    /**
     * 名前空間IDを取得
     *
     * @param string $namespace_path 名前空間パス
     * @return int|WP_Error 名前空間ID、エラーならWP_Error
     */
    private function get_namespace_id( $namespace_path ) {
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

    /**
     * ブランチが存在するかチェック
     *
     * @return bool|WP_Error 存在すればtrue、存在しなければfalse、エラーならWP_Error
     */
    public function check_branch_exists() {
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

    /**
     * デフォルトブランチを取得
     *
     * @return string|WP_Error デフォルトブランチ名、エラーならWP_Error
     */
    public function get_default_branch() {
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

    /**
     * ファイルをバッチ処理でプッシュ（ディスクから直接読み込み、差分検出対応）
     *
     * @param array  $file_paths ファイルパスの配列
     * @param string $base_dir ベースディレクトリ
     * @param string $commit_message コミットメッセージ
     * @param int    $batch_size バッチサイズ
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function push_files_batch_from_disk( $file_paths, $base_dir, $commit_message, $batch_size = 300 ) {
        // 開始時刻を記録
        $start_time = microtime( true );
        $this->logger->debug( 'GitLabプッシュ開始: ' . date( 'Y-m-d H:i:s' ) );

        // ディレクトリの存在確認
        if ( ! is_dir( $base_dir ) ) {
            $this->logger->error( '一時ディレクトリが存在しません: ' . $base_dir );
            return new WP_Error( 'temp_dir_not_found', '一時ディレクトリが存在しません' );
        }

        // 既存ファイルリストを取得（差分検出用：パスとSHAのマップ）
        $existing_files = $this->get_repository_tree_with_sha();
        $existing_file_map = array();
        if ( ! is_wp_error( $existing_files ) ) {
            foreach ( $existing_files as $file ) {
                if ( $file['type'] === 'blob' ) {
                    // パスとSHA（content_sha256またはid）を保持
                    $existing_file_map[ $file['path'] ] = isset( $file['id'] ) ? $file['id'] : null;
                }
            }
        }

        // 差分検出：変更されたファイルのみ抽出
        $changed_file_paths = $this->get_changed_file_paths_from_disk( $file_paths, $base_dir, $existing_file_map );

        if ( empty( $changed_file_paths ) ) {
            // 変更なし
            $this->logger->debug( '変更なし: GitLabへのプッシュをスキップしました' );
            return true;
        }

        // バッチに分割（変更ファイルのみ）
        $path_batches = array_chunk( $changed_file_paths, $batch_size );
        $total_batches = count( $path_batches );

        if ( $total_batches > 1 ) {
            $this->logger->debug( "合計 {$total_batches} バッチでコミットします（各バッチ最大{$batch_size}ファイル）" );
        }

        foreach ( $path_batches as $batch_index => $batch_paths ) {
            $batch_num = $batch_index + 1;

            // バッチごとのコミットメッセージ
            $batch_message = $commit_message;
            if ( $total_batches > 1 ) {
                $batch_message .= " (batch {$batch_num}/{$total_batches})";
            }

            // 2バッチ目以降は待機
            if ( $batch_index > 0 ) {
                sleep( 3 );
            }

            // アクションリストを構築
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

                // 既存ファイルがあればupdate、なければcreate
                $action = array_key_exists( $relative_path, $existing_file_map ) ? 'update' : 'create';

                $actions[] = array(
                    'action'    => $action,
                    'file_path' => $relative_path,
                    'content'   => base64_encode( $content ),
                    'encoding'  => 'base64',
                );

                // 新規作成したファイルは次バッチで update 扱いにする
                $existing_file_map[ $relative_path ] = 'new';
            }

            if ( empty( $actions ) ) {
                continue;
            }

            // Commits APIでコミット
            $result = $this->create_commit( $actions, $batch_message );

            if ( is_wp_error( $result ) ) {
                $this->logger->error( "バッチ {$batch_num} の処理に失敗しました: " . $result->get_error_message() );
                return $result;
            }

            // メモリ解放
            unset( $actions );
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
        }

        // 処理完了
        $total_elapsed = microtime( true ) - $start_time;
        $this->logger->debug( sprintf(
            'GitLabプッシュ完了: %s (合計処理時間: %.2f秒)',
            date( 'Y-m-d H:i:s' ),
            $total_elapsed
        ) );

        return true;
    }

    /**
     * リポジトリツリーを取得
     *
     * @return array|WP_Error ファイルリスト、エラーならWP_Error
     */
    private function get_repository_tree() {
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
                // 空のリポジトリの場合は空配列を返す
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

            // ヘッダーから次のページがあるか確認
            $total_pages = wp_remote_retrieve_header( $response, 'x-total-pages' );
            if ( $total_pages && $page > (int) $total_pages ) {
                break;
            }

        } while ( count( $body ) === $per_page );

        return $all_files;
    }

    /**
     * リポジトリツリーを取得（SHA情報付き）
     * GitLabのツリーAPIは各ファイルの 'id' フィールドにGit blob SHAを返す
     *
     * @return array|WP_Error ファイルリスト（id含む）、エラーならWP_Error
     */
    private function get_repository_tree_with_sha() {
        // get_repository_tree() と同じだが、idフィールドを確実に取得
        return $this->get_repository_tree();
    }

    /**
     * 変更されたファイルパスのみ抽出（ディスクから読み込み、差分検出）
     *
     * @param array $file_paths ファイルパスの配列（相対パス）
     * @param string $base_dir ベースディレクトリ
     * @param array $existing_file_map 既存ファイルのマップ（パス => SHA）
     * @return array 変更されたファイルパスの配列
     */
    private function get_changed_file_paths_from_disk( $file_paths, $base_dir, $existing_file_map ) {
        $changed_file_paths = array();

        foreach ( $file_paths as $relative_path ) {
            // Windowsのパスセパレータを正規化
            $relative_path = str_replace( '\\', '/', $relative_path );
            $full_path = trailingslashit( $base_dir ) . $relative_path;

            if ( ! is_readable( $full_path ) ) {
                continue;
            }
            $content = file_get_contents( $full_path );
            if ( $content === false ) {
                continue;
            }

            // ファイルが存在しない場合は新規ファイルとして変更扱い
            if ( ! array_key_exists( $relative_path, $existing_file_map ) ) {
                $changed_file_paths[] = $relative_path;
                continue;
            }

            // 既存ファイルのSHAがない場合（GitLabツリーAPIの制限）は変更扱い
            $existing_sha = $existing_file_map[ $relative_path ];
            if ( empty( $existing_sha ) ) {
                $changed_file_paths[] = $relative_path;
                continue;
            }

            // Git Blob SHA を計算（git hash-object アルゴリズム）
            $blob_content = 'blob ' . strlen( $content ) . "\0" . $content;
            $new_sha = sha1( $blob_content );

            // SHAが異なる場合は変更あり
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

    /**
     * コミットを作成
     *
     * @param array  $actions アクションの配列
     * @param string $commit_message コミットメッセージ
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    private function create_commit( $actions, $commit_message ) {
        $body = array(
            'branch'         => $this->branch,
            'commit_message' => $commit_message,
            'actions'        => $actions,
        );

        // ブランチが存在しない場合は作成
        $branch_exists = $this->check_branch_exists();
        if ( $branch_exists === false ) {
            // デフォルトブランチから作成を試みる
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
            $message = isset( $response_body['message'] ) ? $response_body['message'] : 'コミットの作成に失敗しました';

            // 配列の場合は文字列に変換
            if ( is_array( $message ) ) {
                $message = wp_json_encode( $message );
            }

            return new WP_Error( 'commit_failed', $message );
        }
    }

    /**
     * ブランチを作成
     *
     * @param string $ref 元にするブランチまたはコミットSHA
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function create_branch( $ref ) {
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
            $message = isset( $body['message'] ) ? $body['message'] : 'ブランチの作成に失敗しました';
            return new WP_Error( 'create_branch_failed', $message );
        }
    }

    /**
     * 接続テスト
     *
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function test_connection() {
        // ユーザー情報を取得してトークンの有効性を確認
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
