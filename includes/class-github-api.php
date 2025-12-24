<?php
/**
 * GitHub API連携クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_GitHub_API {

    /**
     * GitHubアクセストークン
     */
    private $token;

    /**
     * リポジトリ名（owner/repo形式）
     */
    private $repo;

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
     * @param string $token GitHubアクセストークン
     * @param string $repo リポジトリ名
     * @param string $branch ブランチ名
     */
    public function __construct( $token, $repo, $branch ) {
        $this->token = $token;
        $this->repo = $repo;
        $this->branch = $branch;
        $this->logger = CP_Logger::get_instance();
    }

    /**
     * リポジトリが存在するかチェック
     *
     * @return bool|WP_Error 存在すればtrue、存在しなければfalse、エラーならWP_Error
     */
    public function check_repo_exists() {
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

    /**
     * リポジトリを作成
     *
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function create_repo() {
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
            $message = isset( $body['message'] ) ? $body['message'] : 'リポジトリの作成に失敗しました';
            return new WP_Error( 'create_repo_failed', $message );
        }
    }

    /**
     * ブランチが存在するかチェック
     *
     * @return bool|WP_Error 存在すればtrue、存在しなければfalse、エラーならWP_Error
     */
    public function check_branch_exists() {
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

    /**
     * デフォルトブランチを取得
     *
     * @return string|WP_Error デフォルトブランチ名、エラーならWP_Error
     */
    public function get_default_branch() {
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

    /**
     * ファイルをプッシュ
     *
     * @param array $files ファイルの配列（パス => 内容）
     * @param string $commit_message コミットメッセージ
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function push_files( $files, $commit_message ) {
        // リポジトリが空かどうかをチェック
        $is_empty = $this->is_repo_empty();

        if ( is_wp_error( $is_empty ) ) {
            return $is_empty;
        }

        if ( $is_empty ) {
            // 空リポジトリの場合は最初のファイルを作成
            return $this->create_initial_file( $files, $commit_message );
        } else {
            // 既存リポジトリの場合は差分のみプッシュ
            return $this->update_files( $files, $commit_message );
        }
    }

    /**
     * リポジトリが空かどうかをチェック
     *
     * @return bool|WP_Error 空ならtrue、空でないならfalse、エラーならWP_Error
     */
    private function is_repo_empty() {
        $response = $this->api_request( "repos/{$this->repo}/contents", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 404 ) {
            return true; // 空リポジトリ
        } elseif ( $status_code === 200 ) {
            return false; // ファイルが存在
        } else {
            return new WP_Error( 'api_error', 'リポジトリの状態確認中にエラーが発生しました。' );
        }
    }

    /**
     * 初回ファイル作成（空リポジトリ用・並列処理対応）
     *
     * @param array $files ファイルの配列
     * @param string $commit_message コミットメッセージ
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    private function create_initial_file( $files, $commit_message ) {
        $this->logger->debug( '空のリポジトリに初回コミットを作成します (' . count( $files ) . 'ファイル)' );

        // 空のリポジトリには最初の1ファイルをContents APIで作成する必要がある
        // 空のindex.htmlを作成してリポジトリを初期化
        $this->logger->debug( '初期ファイルを作成中: index.html' );

        $body = array(
            'message' => $commit_message,
            'content' => base64_encode( '' ), // 空のファイル
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
            $error_message = isset( $response_body['message'] ) ? $response_body['message'] : '初期ファイルの作成に失敗しました';
            $this->logger->error( "初期ファイル作成エラー: {$error_message} (Status: {$status_code})" );
            return new WP_Error( 'create_initial_file_failed', $error_message );
        }

        $this->logger->debug( '初期ファイルを作成しました。すべてのファイルを追加します...' );

        // すべてのファイルをGit Data APIで追加
        return $this->push_files_via_tree( $files, $commit_message, true );
    }

    /**
     * ファイルを更新（既存リポジトリ用）
     *
     * @param array $files ファイルの配列
     * @param string $commit_message コミットメッセージ
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    private function update_files( $files, $commit_message ) {
        $success_count = 0;
        $error_count = 0;

        foreach ( $files as $path => $content ) {
            // 既存ファイルのSHAを取得
            $sha = $this->get_file_sha( $path );

            $body = array(
                'message' => $commit_message,
                'content' => base64_encode( $content ),
                'branch' => $this->branch,
            );

            // SHAが取得できた場合は更新、できなかった場合は新規作成
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

    /**
     * ファイルのSHAを取得
     *
     * @param string $path ファイルパス
     * @return string|bool|WP_Error SHAハッシュ、ファイルが存在しなければfalse、エラーならWP_Error
     */
    private function get_file_sha( $path ) {
        $response = $this->api_request( "repos/{$this->repo}/contents/{$path}", 'GET', null, array( 'ref' => $this->branch ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            return isset( $body['sha'] ) ? $body['sha'] : false;
        } elseif ( $status_code === 404 ) {
            return false; // ファイルが存在しない
        } else {
            return new WP_Error( 'get_sha_failed', 'ファイル情報の取得に失敗しました。' );
        }
    }

    /**
     * 単一ファイルをプッシュ
     *
     * @param string $path ファイルパス
     * @param string $content ファイル内容
     * @param string $commit_message コミットメッセージ
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function push_file( $path, $content, $commit_message ) {
        // 既存ファイルのSHAを取得
        $sha = $this->get_file_sha( $path );

        $body = array(
            'message' => $commit_message,
            'content' => base64_encode( $content ),
            'branch' => $this->branch,
        );

        // SHAが取得できた場合は更新、できなかった場合は新規作成
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

    /**
     * ファイルをバッチでプッシュ（ディスクから直接読み込み、メモリ効率的、差分検出対応）
     *
     * @param array $file_paths ファイルパスの配列（相対パス）
     * @param string $base_dir ベースディレクトリ
     * @param string $commit_message コミットメッセージ
     * @param int $batch_size バッチサイズ（デフォルト300）
     * @param bool $force_full_push 差分検出をスキップして全ファイルプッシュ（デフォルト: false）
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function push_files_batch_from_disk( $file_paths, $base_dir, $commit_message, $batch_size = 300, $force_full_push = false ) {
        // 開始時刻を記録
        $start_time = microtime( true );
        $start_timestamp = date( 'Y-m-d H:i:s' );
        $this->logger->debug( "GitHubプッシュ開始: {$start_timestamp}" );

        // ディレクトリの存在確認
        if ( ! is_dir( $base_dir ) ) {
            $this->logger->error( '一時ディレクトリが存在しません: ' . $base_dir );
            return new WP_Error( 'temp_dir_not_found', '一時ディレクトリが存在しません' );
        }

        // 差分検出（強制プッシュでない場合）
        $changed_file_paths = $file_paths;
        if ( ! $force_full_push ) {
            $this->logger->debug( "差分検出を実行中..." );
            $changed_file_paths = $this->get_changed_file_paths_from_disk( $file_paths, $base_dir );

            if ( is_wp_error( $changed_file_paths ) ) {
                // ツリー取得失敗時は全ファイルをプッシュ
                $this->logger->debug( '差分検出失敗: 全ファイルをプッシュします' );
                $changed_file_paths = $file_paths;
            } elseif ( empty( $changed_file_paths ) ) {
                // 変更なし
                $this->logger->debug( '変更なし: GitHubへのプッシュをスキップしました' );
                return true;
            }
        } else {
            $this->logger->debug( "強制全体プッシュ: " . count( $file_paths ) . "個のファイルをプッシュします" );
        }

        // 変更ファイルだけをバッチ化
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

            // 2バッチ目以降は待機（GitHub API負荷軽減 & 反映待ち）
            if ( $batch_index > 0 ) {
                sleep( 2 ); // バッチ間の反映を確実にするため（5秒→2秒に短縮）
            }

            // このバッチのファイルのみ読み込み
            $batch_files = array();
            foreach ( $batch_paths as $relative_path ) {
                // Windowsのパスセパレータを正規化
                $relative_path = str_replace( '\\', '/', $relative_path );
                $full_path = trailingslashit( $base_dir ) . $relative_path;

                if ( is_readable( $full_path ) ) {
                    $content = file_get_contents( $full_path );
                    if ( $content !== false ) {
                        $batch_files[ $relative_path ] = $content;
                    }
                }
            }

            // Git Trees APIを使用してバッチコミット（差分検出はスキップ、既に実施済み）
            $result = $this->push_files_via_tree( $batch_files, $batch_message, true );

            // メモリ解放
            unset( $batch_files );

            if ( is_wp_error( $result ) ) {
                $this->logger->error( "バッチ {$batch_num} の処理に失敗しました: " . $result->get_error_message() );
                return $result;
            }

            // メモリ解放を強制
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }
        }

        // 全体の処理完了時刻を記録
        $total_elapsed = microtime( true ) - $start_time;
        $end_timestamp = date( 'Y-m-d H:i:s' );
        $this->logger->debug( sprintf(
            "GitHubプッシュ完了: %s (合計処理時間: %.2f秒 / %.2f分)",
            $end_timestamp,
            $total_elapsed,
            $total_elapsed / 60
        ) );

        return true;
    }

    /**
     * ファイルをバッチでプッシュ（500ファイルごとに分割コミット）
     *
     * @param array $files ファイルの配列（パス => 内容）
     * @param string $commit_message コミットメッセージ
     * @param int $batch_size バッチサイズ（デフォルト500）
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function push_files_batch( $files, $commit_message, $batch_size = 500 ) {
        // ファイルをバッチに分割
        $file_batches = array_chunk( $files, $batch_size, true );
        $total_batches = count( $file_batches );

        $this->logger->debug( "合計 {$total_batches} バッチでコミットします（各バッチ最大{$batch_size}ファイル）" );

        foreach ( $file_batches as $batch_index => $batch_files ) {
            $batch_num = $batch_index + 1;
            $file_count = count( $batch_files );

            // バッチごとのコミットメッセージ
            $batch_message = $commit_message;
            if ( $total_batches > 1 ) {
                $batch_message .= " (batch {$batch_num}/{$total_batches})";
            }

            $this->logger->debug( "バッチ {$batch_num}/{$total_batches} を処理中 ({$file_count}ファイル)..." );

            // Git Trees APIを使用してバッチコミット
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

    /**
     * Git Trees APIを使用してファイルをプッシュ（並列処理 + 差分検出対応）
     *
     * @param array $files ファイルの配列
     * @param string $commit_message コミットメッセージ
     * @param bool $force_full_push 差分検出をスキップして全ファイルプッシュ（デフォルト: false）
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    private function push_files_via_tree( $files, $commit_message, $force_full_push = false ) {
        // 現在のブランチの最新コミットを取得
        $latest_commit = $this->get_latest_commit();

        if ( is_wp_error( $latest_commit ) ) {
            // 空のリポジトリの場合は従来の方法で最初のファイルを作成
            if ( $latest_commit->get_error_code() === 'branch_not_found' ) {
                $this->logger->debug( "空のリポジトリ: 初回コミットを作成します" );
                return $this->create_initial_file( $files, $commit_message );
            }
            return $latest_commit;
        }

        // ベースツリーを取得
        $base_tree = $latest_commit['tree']['sha'];

        // 差分検出（強制プッシュでない場合）
        $files_to_push = $files;
        if ( ! $force_full_push ) {
            $files_to_push = $this->get_changed_files( $files, $base_tree );

            // 変更がない場合はスキップ
            if ( empty( $files_to_push ) ) {
                $this->logger->debug( '変更なし: GitHubへのプッシュをスキップしました' );
                return true;
            }
        } else {
            $this->logger->debug( "バッチプッシュ: " . count( $files ) . "個のファイルをプッシュします（差分検出済み）" );
        }

        // 並列でBlobを作成
        $this->logger->debug( "Blob並列作成開始: " . count( $files_to_push ) . "個のファイル" );
        $tree_items = $this->create_blobs_parallel( $files_to_push, 10 );

        if ( is_wp_error( $tree_items ) ) {
            return $tree_items;
        }

        $this->logger->debug( "すべてのBlob作成完了 (" . count( $tree_items ) . "個)" );

        // ツリーを作成
        $this->logger->debug( "Tree作成中..." );
        $tree_sha = $this->create_tree( $tree_items, $base_tree );
        if ( is_wp_error( $tree_sha ) ) {
            $this->logger->error( "Tree作成エラー: " . $tree_sha->get_error_message() );
            return $tree_sha;
        }
        $this->logger->debug( "Tree作成完了" );

        // コミットを作成
        $this->logger->debug( "コミット作成中..." );
        $commit_sha = $this->create_commit( $commit_message, $tree_sha, array( $latest_commit['sha'] ) );
        if ( is_wp_error( $commit_sha ) ) {
            $this->logger->error( "コミット作成エラー: " . $commit_sha->get_error_message() );
            return $commit_sha;
        }
        $this->logger->debug( "コミット作成完了" );

        // ブランチを更新
        $this->logger->debug( "ブランチ更新中..." );
        $result = $this->update_branch_ref( $commit_sha );
        if ( is_wp_error( $result ) ) {
            $this->logger->error( "ブランチ更新エラー: " . $result->get_error_message() );
            return $result;
        }
        $this->logger->debug( "ブランチ更新完了" );

        return true;
    }

    /**
     * 最新コミットを取得
     *
     * @return array|WP_Error コミット情報、エラーならWP_Error
     */
    private function get_latest_commit() {
        $response = $this->api_request( "repos/{$this->repo}/git/refs/heads/{$this->branch}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        // 404: ブランチが存在しない、409: リポジトリが空
        if ( $status_code === 404 || $status_code === 409 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = isset( $body['message'] ) ? $body['message'] : 'ブランチが見つかりません';
            $this->logger->debug( '空のリポジトリまたはブランチ未作成: ' . $error_message );
            return new WP_Error( 'branch_not_found', $error_message );
        }

        if ( $status_code !== 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = isset( $body['message'] ) ? $body['message'] : 'コミット情報の取得に失敗しました';
            $this->logger->error( 'GitHub API エラー (get ref): ' . $error_message . ' (Status: ' . $status_code . ')' );
            return new WP_Error( 'get_commit_failed', $error_message );
        }

        $ref_data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! isset( $ref_data['object']['sha'] ) ) {
            $this->logger->error( 'GitHub API レスポンスが不正です: ' . wp_json_encode( $ref_data ) );
            return new WP_Error( 'invalid_response', 'GitHub APIのレスポンスが不正です' );
        }

        $commit_sha = $ref_data['object']['sha'];

        // コミットの詳細を取得
        $response = $this->api_request( "repos/{$this->repo}/git/commits/{$commit_sha}", 'GET' );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code !== 200 ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = isset( $body['message'] ) ? $body['message'] : 'コミット詳細の取得に失敗しました';
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

    /**
     * Blobを作成
     *
     * @param string $content ファイル内容
     * @return string|WP_Error Blob SHA、エラーならWP_Error
     */
    private function create_blob( $content ) {
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
            $error_message = isset( $response_body['message'] ) ? $response_body['message'] : 'Blobの作成に失敗しました';

            // 詳細なエラー情報を含める
            $error_details = "Status: {$status_code}, Message: {$error_message}";
            if ( isset( $response_body['errors'] ) ) {
                $error_details .= ', Errors: ' . wp_json_encode( $response_body['errors'] );
            }

            return new WP_Error( 'create_blob_failed', $error_details );
        }

        $blob_data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $blob_data['sha'];
    }

    /**
     * Blobを並列で作成（高速化版）
     *
     * @param array $files ファイルの配列（パス => 内容）
     * @param int $concurrency 並列度（デフォルト10）
     * @return array|WP_Error ツリーアイテムの配列、エラーならWP_Error
     */
    private function create_blobs_parallel( $files, $concurrency = 10 ) {
        // Requestsライブラリが利用可能かチェック
        if ( ! class_exists( 'Requests' ) && ! class_exists( 'WpOrg\Requests\Requests' ) ) {
            // フォールバック: 通常の順次処理
            $this->logger->debug( 'Requestsライブラリが利用できないため、順次処理を使用します' );
            return $this->create_blobs_sequential( $files );
        }

        $tree_items = array();
        $failed_files = array(); // リトライ対象のファイル
        $file_paths = array_keys( $files );
        $file_contents = array_values( $files );
        $chunks = array_chunk( $file_paths, $concurrency, true );

        $total_chunks = count( $chunks );
        $processed = 0;

        foreach ( $chunks as $chunk_index => $chunk_paths ) {
            $requests = array();
            $path_map = array(); // リクエストインデックスとパスのマッピング

            // 並列リクエストを準備
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

            // 並列リクエストを実行
            try {
                // WordPress 6.2+ uses WpOrg\Requests\Requests
                if ( class_exists( 'WpOrg\Requests\Requests' ) ) {
                    $responses = \WpOrg\Requests\Requests::request_multiple( $requests );
                } else {
                    $responses = Requests::request_multiple( $requests );
                }

                // レスポンスを処理
                foreach ( $responses as $request_index => $response ) {
                    $path = $path_map[ $request_index ];

                    if ( is_a( $response, 'Requests_Response' ) || is_a( $response, 'WpOrg\Requests\Response' ) ) {
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
                            // レート制限チェック
                            if ( isset( $response->headers['x-ratelimit-remaining'] ) ) {
                                $remaining = intval( $response->headers['x-ratelimit-remaining'] );
                                if ( $remaining < 100 ) {
                                    $this->logger->warning( "APIレート制限警告: 残り {$remaining} リクエスト" );
                                }
                            }

                            $error_body = json_decode( $response->body, true );
                            $error_message = isset( $error_body['message'] ) ? $error_body['message'] : 'Blob作成失敗';

                            // 403エラー（セカンダリレート制限）の場合は待機して全体をリトライ
                            if ( $response->status_code === 403 && strpos( $error_message, 'secondary rate limit' ) !== false ) {
                                $this->logger->warning( "セカンダリレート制限検出: 60秒待機してリトライします..." );
                                sleep( 60 );
                                return $this->create_blobs_sequential( $files ); // フォールバック
                            }

                            $this->logger->error( "Blob作成エラー: {$path} - {$error_message} (Status: {$response->status_code})" );
                            return new WP_Error( 'create_blob_failed', "Blob作成に失敗: {$path} - {$error_message}" );
                        }
                    } else {
                        // 詳細な診断情報を収集
                        $response_type = gettype( $response );
                        $error_details = "不正なレスポンス (型: {$response_type})";

                        // cURL例外かどうかを判定
                        $is_network_exception = false;
                        if ( is_object( $response ) ) {
                            $class_name = get_class( $response );
                            $error_details .= ', クラス: ' . $class_name;

                            // cURL/Transport例外の場合はリトライ対象
                            if ( strpos( $class_name, 'Exception' ) !== false ) {
                                $is_network_exception = true;
                            }

                            // WP_Errorの場合
                            if ( is_wp_error( $response ) ) {
                                $error_details .= ', エラー: ' . $response->get_error_message();
                            }

                            // その他のオブジェクトの場合、プロパティを確認
                            $properties = get_object_vars( $response );
                            if ( ! empty( $properties ) ) {
                                $error_details .= ', プロパティ: ' . implode( ', ', array_keys( $properties ) );
                            }
                        } elseif ( is_array( $response ) ) {
                            $error_details .= ', キー: ' . implode( ', ', array_keys( $response ) );
                        } elseif ( is_string( $response ) ) {
                            $error_details .= ', 値: ' . substr( $response, 0, 100 );
                        }

                        // ネットワーク例外の場合はリトライ対象として記録して続行
                        if ( $is_network_exception ) {
                            $failed_files[ $path ] = $files[ $path ];
                            $this->logger->debug( "ネットワークエラー: {$path} - リトライ対象に追加" );
                            continue; // 次のファイルへ
                        }

                        // その他のエラーは即座に終了
                        $this->logger->error( "Blob作成エラー: {$path} - {$error_details}" );
                        return new WP_Error( 'create_blob_failed', "Blob作成に失敗: {$path} - {$error_details}" );
                    }
                }

            } catch ( Exception $e ) {
                // 例外発生時もリトライ対象に追加して続行
                $this->logger->debug( '並列リクエスト例外: ' . $e->getMessage() );
                foreach ( $chunk_paths as $chunk_path ) {
                    if ( ! isset( $failed_files[ $chunk_path ] ) && isset( $files[ $chunk_path ] ) ) {
                        $failed_files[ $chunk_path ] = $files[ $chunk_path ];
                    }
                }
            }

            // 進捗ログ
            if ( $total_chunks > 1 ) {
                $chunk_num = $chunk_index + 1;
                $this->logger->debug( "並列Blob作成: {$processed}/" . count( $files ) . "個完了 ({$chunk_num}/{$total_chunks})" );
            }

            // チャンク間で待機（セカンダリレート制限回避）
            if ( $chunk_index < $total_chunks - 1 ) {
                usleep( 500000 ); // 0.5秒（1.5秒→0.5秒に短縮）
            }
        }

        // 失敗ファイルのリトライ処理
        if ( ! empty( $failed_files ) ) {
            $this->logger->debug( "リトライ処理開始: " . count( $failed_files ) . "個のファイル" );

            for ( $retry = 1; $retry <= 3; $retry++ ) {
                $wait_time = pow( 2, $retry ) * 5; // 10, 20, 40秒
                $this->logger->debug( "リトライ {$retry}/3: {$wait_time}秒待機中..." );
                sleep( $wait_time );

                $retry_result = $this->create_blobs_sequential( $failed_files );

                if ( ! is_wp_error( $retry_result ) ) {
                    // 成功したらツリーアイテムにマージ
                    $tree_items = array_merge( $tree_items, $retry_result );
                    $failed_files = array();
                    $this->logger->debug( "リトライ成功: 全ファイルのBlob作成完了" );
                    break;
                }
            }

            // 3回リトライしても失敗した場合はエラーを返す
            if ( ! empty( $failed_files ) ) {
                $failed_count = count( $failed_files );
                $this->logger->error( "{$failed_count}個のファイルのBlob作成に失敗しました" );
                return new WP_Error( 'create_blob_failed', "{$failed_count}個のファイルのBlob作成に失敗しました（3回リトライ後）" );
            }
        }

        return $tree_items;
    }

    /**
     * Blobを順次作成（フォールバック用・リトライ付き）
     *
     * @param array $files ファイルの配列（パス => 内容）
     * @return array|WP_Error ツリーアイテムの配列、エラーならWP_Error
     */
    private function create_blobs_sequential( $files ) {
        $tree_items = array();
        $max_retries = 3;

        foreach ( $files as $path => $content ) {
            $success = false;
            $last_error = null;

            for ( $retry = 0; $retry <= $max_retries; $retry++ ) {
                if ( $retry > 0 ) {
                    $wait = $retry * 5; // 5, 10, 15秒
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

    /**
     * 変更されたファイルのみ抽出（差分検出）
     *
     * @param array $files ファイルの配列（パス => 内容）
     * @param string $base_tree_sha ベースツリーのSHA
     * @return array 変更されたファイルの配列
     */
    private function get_changed_files( $files, $base_tree_sha ) {
        // ベースツリーの内容を取得
        $response = $this->api_request( "repos/{$this->repo}/git/trees/{$base_tree_sha}", 'GET', null, array( 'recursive' => '1' ) );

        if ( is_wp_error( $response ) ) {
            // ツリー取得に失敗した場合は全ファイルを返す（安全側）
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

        // 既存ファイルのSHAマップを作成
        $existing_files = array();
        foreach ( $tree_data['tree'] as $item ) {
            if ( $item['type'] === 'blob' ) {
                $existing_files[ $item['path'] ] = $item['sha'];
            }
        }

        // 変更されたファイルを検出
        $changed_files = array();
        foreach ( $files as $path => $content ) {
            // Git Blob SHA を計算（git hash-object アルゴリズム）
            $blob_content = 'blob ' . strlen( $content ) . "\0" . $content;
            $new_sha = sha1( $blob_content );

            // ファイルが存在しないか、SHAが異なる場合は変更あり
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

    /**
     * 変更されたファイルパスのみ抽出（ディスクから読み込み、差分検出）
     *
     * @param array $file_paths ファイルパスの配列（相対パス）
     * @param string $base_dir ベースディレクトリ
     * @return array|WP_Error 変更されたファイルパスの配列、エラーならWP_Error
     */
    private function get_changed_file_paths_from_disk( $file_paths, $base_dir ) {
        // 現在のブランチの最新コミットを取得
        $latest_commit = $this->get_latest_commit();

        if ( is_wp_error( $latest_commit ) ) {
            // 空のリポジトリの場合は全ファイルを返す
            if ( $latest_commit->get_error_code() === 'branch_not_found' ) {
                return $file_paths;
            }
            return $latest_commit;
        }

        // ベースツリーを取得
        $base_tree = $latest_commit['tree']['sha'];

        // ベースツリーの内容を取得
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

        // 既存ファイルのSHAマップを作成
        $existing_files = array();
        foreach ( $tree_data['tree'] as $item ) {
            if ( $item['type'] === 'blob' ) {
                $existing_files[ $item['path'] ] = $item['sha'];
            }
        }

        // 変更されたファイルパスを検出
        $changed_file_paths = array();
        foreach ( $file_paths as $relative_path ) {
            // Windowsのパスセパレータを正規化
            $relative_path = str_replace( '\\', '/', $relative_path );
            $full_path = trailingslashit( $base_dir ) . $relative_path;

            if ( ! is_readable( $full_path ) ) {
                continue; // ファイル読み込み不可時はスキップ
            }
            $content = file_get_contents( $full_path );
            if ( $content === false ) {
                continue; // ファイル読み込み失敗時はスキップ
            }

            // Git Blob SHA を計算（git hash-object アルゴリズム）
            $blob_content = 'blob ' . strlen( $content ) . "\0" . $content;
            $new_sha = sha1( $blob_content );

            // ファイルが存在しないか、SHAが異なる場合は変更あり
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

    /**
     * ツリーを作成
     *
     * @param array $tree_items ツリーアイテムの配列
     * @param string $base_tree ベースツリーのSHA
     * @return string|WP_Error ツリーSHA、エラーならWP_Error
     */
    private function create_tree( $tree_items, $base_tree = null ) {
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
            $error_message = isset( $response_body['message'] ) ? $response_body['message'] : 'ツリーの作成に失敗しました';

            // 詳細なエラー情報をログに記録
            $this->logger->error( 'GitHub API エラー (create tree): ' . $error_message . ' (Status: ' . $status_code . ')' );

            // エラーの詳細があれば記録
            if ( isset( $response_body['errors'] ) ) {
                $this->logger->error( 'エラー詳細: ' . wp_json_encode( $response_body['errors'] ) );
            }

            // ツリーアイテム数を記録
            $this->logger->error( 'ツリーアイテム数: ' . count( $tree_items ) );

            return new WP_Error( 'create_tree_failed', $error_message );
        }

        $tree_data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $tree_data['sha'];
    }

    /**
     * コミットを作成
     *
     * @param string $message コミットメッセージ
     * @param string $tree ツリーSHA
     * @param array $parents 親コミットSHAの配列
     * @return string|WP_Error コミットSHA、エラーならWP_Error
     */
    private function create_commit( $message, $tree, $parents = array() ) {
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

    /**
     * ブランチ参照を作成
     *
     * @param string $commit_sha コミットSHA
     * @return bool|WP_Error 成功ならtrue、エラーならWP_Error
     */
    private function create_branch_ref( $commit_sha ) {
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
            $error_message = isset( $body['message'] ) ? $body['message'] : 'ブランチ参照の作成に失敗しました';
            $this->logger->error( 'GitHub API エラー (create ref): ' . $error_message . ' (Status: ' . $status_code . ')' );
            return new WP_Error( 'create_ref_failed', $error_message );
        }

        return true;
    }

    /**
     * ブランチ参照を更新
     *
     * @param string $commit_sha コミットSHA
     * @return bool|WP_Error 成功ならtrue、エラーならWP_Error
     */
    private function update_branch_ref( $commit_sha ) {
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
            // 詳細なエラー情報をログに記録
            $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $error_message = isset( $response_body['message'] ) ? $response_body['message'] : 'ブランチ参照の更新に失敗しました';
            $this->logger->error( "GitHub API エラー (update ref): {$error_message} (Status: {$status_code})" );

            // レスポンス詳細をログに記録
            if ( isset( $response_body['documentation_url'] ) ) {
                $this->logger->error( 'ドキュメント: ' . $response_body['documentation_url'] );
            }

            return new WP_Error( 'update_ref_failed', $error_message );
        }

        return true;
    }

    /**
     * GitHub APIリクエストを送信
     *
     * @param string $endpoint APIエンドポイント
     * @param string $method HTTPメソッド
     * @param array $body リクエストボディ
     * @param array $query クエリパラメータ
     * @return array|WP_Error レスポンス配列、エラーならWP_Error
     */
    private function api_request( $endpoint, $method = 'GET', $body = null, $query = null ) {
        $url = "https://api.github.com/{$endpoint}";

        // クエリパラメータを追加
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
            'timeout' => 300, // 大量のファイル処理に対応（5分）
        );

        if ( $body ) {
            $args['body'] = wp_json_encode( $body );
            $args['headers']['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // レート制限チェック
        $remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
        if ( $remaining !== null && intval( $remaining ) === 0 ) {
            return new WP_Error( 'rate_limit', 'GitHubのAPIレート制限に達しました。しばらく待ってから再実行してください。' );
        }

        return $response;
    }
}
