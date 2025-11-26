<?php
/**
 * 設定管理クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SGE_Settings {

    /**
     * シングルトンインスタンス
     */
    private static $instance = null;

    /**
     * 暗号化キー
     */
    private $encryption_key;

    /**
     * シングルトンインスタンスを取得
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        // 暗号化キーを取得または生成
        $this->encryption_key = $this->get_or_create_encryption_key();
    }

    /**
     * 暗号化キーを取得または生成
     *
     * @return string 暗号化キー
     */
    private function get_or_create_encryption_key() {
        // wp-config.php で定義された専用キーがあればそれを使用
        if ( defined( 'SGE_ENCRYPTION_KEY' ) && ! empty( SGE_ENCRYPTION_KEY ) ) {
            return hash( 'sha256', SGE_ENCRYPTION_KEY );
        }

        // データベースに保存された専用キーを取得
        $stored_key = get_option( 'sge_encryption_key' );
        if ( ! empty( $stored_key ) ) {
            return $stored_key;
        }

        // 新しいセキュアなキーを生成
        try {
            $random_bytes = random_bytes( 32 );
            $new_key = hash( 'sha256', $random_bytes . wp_salt( 'secure_auth' ) . wp_salt( 'auth' ) );
        } catch ( Exception $e ) {
            // random_bytes が使えない場合のフォールバック
            $new_key = hash( 'sha256', wp_generate_password( 64, true, true ) . wp_salt( 'secure_auth' ) . wp_salt( 'auth' ) );
        }

        // キーをデータベースに保存（後で使用するため）
        update_option( 'sge_encryption_key', $new_key, false );

        return $new_key;
    }

    /**
     * 設定を取得
     *
     * @return array 設定の配列
     */
    public function get_settings() {
        $settings = get_option( 'sge_settings', array() );

        // デフォルト値を定義
        $defaults = array(
            'version' => SGE_VERSION,
            'github_enabled' => false,
            'local_enabled' => false,
            'github_token' => '',
            'github_repo' => '',
            'github_branch_mode' => 'existing',
            'github_existing_branch' => '',
            'github_new_branch' => '',
            'github_base_branch' => '',
            'github_method' => 'api',
            'git_work_dir' => '',
            'local_output_path' => '',
            'include_paths' => '',
            'exclude_patterns' => '',
            'url_mode' => 'relative',
            'timeout' => 600,
            'auto_generate' => false,
            'cache_enabled' => true,
            'use_parallel_crawling' => true,
            'commit_message' => '',
            'enable_tag_archive' => false,
            'enable_date_archive' => false,
            'enable_author_archive' => false,
            'enable_post_format_archive' => false,
            'enable_sitemap' => true,
            'enable_robots_txt' => false,
            'enable_rss' => true,
        );

        // デフォルト値とマージ（既存の設定を優先）
        $settings = array_merge( $defaults, $settings );

        // トークンを復号化
        if ( ! empty( $settings['github_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['github_token'] );
            $settings['github_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
        }

        return $settings;
    }

    /**
     * 設定を保存
     *
     * @param array $settings 設定の配列
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function save_settings( $settings ) {
        // 現在の設定を取得（暗号化されたトークンを取得するためraw_settingsを使用）
        $current_raw = get_option( 'sge_settings', array() );

        // トークンが空の場合、既存のトークンを保持
        if ( empty( $settings['github_token'] ) && ! empty( $current_raw['github_token'] ) ) {
            $settings['github_token'] = $current_raw['github_token'];
            // バリデーション用に復号化した値を一時的に設定
            $decrypted = $this->decrypt_token( $current_raw['github_token'] );
            $settings['_has_existing_token'] = ! is_wp_error( $decrypted );
        }

        // バリデーション
        $validation = $this->validate_settings( $settings );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // 一時フラグを削除
        unset( $settings['_has_existing_token'] );

        // 新しいトークンが入力された場合のみ暗号化
        if ( ! empty( $settings['github_token'] ) && strpos( $settings['github_token'], 'v2:' ) !== 0 && strpos( $settings['github_token'], '::' ) === false ) {
            // 暗号化されていない新しいトークン
            $settings['github_token'] = $this->encrypt_token( $settings['github_token'] );
        }

        // バージョン情報を追加
        $settings['version'] = SGE_VERSION;

        // 設定を保存
        update_option( 'sge_settings', $settings );

        return true;
    }

    /**
     * 設定をバリデーション
     *
     * @param array $settings 設定の配列
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function validate_settings( $settings ) {
        // 出力先が最低1つ選択されているかチェック
        if ( empty( $settings['github_enabled'] ) && empty( $settings['git_local_enabled'] ) && empty( $settings['local_enabled'] ) && empty( $settings['zip_enabled'] ) ) {
            return new WP_Error( 'no_output', '出力先を最低1つ選択してください。' );
        }

        // GitHub出力が有効な場合
        if ( ! empty( $settings['github_enabled'] ) ) {
            // トークンが必須（既存トークンがある場合はスキップ）
            $has_token = ! empty( $settings['github_token'] ) || ! empty( $settings['_has_existing_token'] );
            if ( ! $has_token ) {
                return new WP_Error( 'token_required', 'GitHubアクセストークンを入力してください。' );
            }

            // リポジトリ名が必須
            if ( empty( $settings['github_repo'] ) ) {
                return new WP_Error( 'repo_required', 'リポジトリ名を入力してください。' );
            }

            // リポジトリ名の形式チェック（長さ制限も追加）
            if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,100}\/[a-zA-Z0-9_.-]{1,100}$/', $settings['github_repo'] ) ) {
                return new WP_Error( 'invalid_repo', 'リポジトリ名の形式が正しくありません（例：owner/repo）' );
            }

            // リポジトリ名の長さチェック
            if ( strlen( $settings['github_repo'] ) > 200 ) {
                return new WP_Error( 'repo_too_long', 'リポジトリ名が長すぎます。' );
            }

            // ブランチ設定のチェック
            if ( $settings['github_branch_mode'] === 'existing' ) {
                if ( empty( $settings['github_existing_branch'] ) ) {
                    return new WP_Error( 'branch_required', '既存ブランチ名を入力してください。' );
                }
                if ( ! preg_match( '/^[a-zA-Z0-9\/_-]+$/', $settings['github_existing_branch'] ) ) {
                    return new WP_Error( 'invalid_branch', 'ブランチ名に使用できない文字が含まれています。' );
                }
            } elseif ( $settings['github_branch_mode'] === 'new' ) {
                if ( empty( $settings['github_new_branch'] ) ) {
                    return new WP_Error( 'new_branch_required', '新規ブランチ名を入力してください。' );
                }
                if ( ! preg_match( '/^[a-zA-Z0-9\/_-]+$/', $settings['github_new_branch'] ) ) {
                    return new WP_Error( 'invalid_new_branch', '新規ブランチ名に使用できない文字が含まれています。' );
                }
            }

        }

        // ローカルGit出力が有効な場合
        if ( ! empty( $settings['git_local_enabled'] ) ) {
            if ( empty( $settings['git_local_work_dir'] ) ) {
                return new WP_Error( 'git_local_work_dir_required', 'Git作業ディレクトリパスを入力してください。' );
            }

            // 絶対パスチェック
            if ( ! $this->is_absolute_path( $settings['git_local_work_dir'] ) ) {
                return new WP_Error( 'git_local_work_dir_absolute', 'Git作業ディレクトリは絶対パスで指定してください。' );
            }

            // パストラバーサルチェック（..を含む場合は拒否）
            if ( strpos( $settings['git_local_work_dir'], '..' ) !== false ) {
                return new WP_Error( 'path_traversal', 'パスに".."を含めることはできません。' );
            }

            // パスを正規化して検証
            $validated_path = $this->validate_safe_path( $settings['git_local_work_dir'] );
            if ( is_wp_error( $validated_path ) ) {
                return $validated_path;
            }

            // realpath()で正規化してシンボリックリンク攻撃を防止
            $real_path = realpath( $settings['git_local_work_dir'] );
            if ( $real_path === false ) {
                return new WP_Error( 'invalid_path', 'Git作業ディレクトリが存在しないか、アクセスできません。' );
            }
            $settings['git_local_work_dir'] = $real_path;

            // ブランチ名が必須
            if ( empty( $settings['git_local_branch'] ) ) {
                return new WP_Error( 'git_local_branch_required', 'ブランチ名を入力してください。' );
            }

            // ブランチ名の形式チェック
            if ( ! preg_match( '/^[a-zA-Z0-9\/_-]+$/', $settings['git_local_branch'] ) ) {
                return new WP_Error( 'invalid_git_local_branch', 'ブランチ名に使用できない文字が含まれています。' );
            }
        }

        // ローカルディレクトリ出力が有効な場合
        if ( ! empty( $settings['local_enabled'] ) ) {
            if ( empty( $settings['local_output_path'] ) ) {
                return new WP_Error( 'local_path_required', '出力先パスを入力してください。' );
            }

            // 絶対パスチェック
            if ( ! $this->is_absolute_path( $settings['local_output_path'] ) ) {
                return new WP_Error( 'local_path_absolute', '出力先パスは絶対パスで指定してください。' );
            }

            // パストラバーサルチェック（..を含む場合は拒否）
            if ( strpos( $settings['local_output_path'], '..' ) !== false ) {
                return new WP_Error( 'path_traversal', 'パスに".."を含めることはできません。' );
            }

            // 危険なパスチェック
            if ( $this->is_dangerous_path( $settings['local_output_path'] ) ) {
                return new WP_Error( 'dangerous_path', '指定されたパスは使用できません。' );
            }

            // パスを正規化して検証
            $validated_path = $this->validate_safe_path( $settings['local_output_path'] );
            if ( is_wp_error( $validated_path ) ) {
                return $validated_path;
            }
        }

        // タイムアウト時間のバリデーション
        $timeout = intval( $settings['timeout'] );
        if ( $timeout < 60 || $timeout > 18000 ) {
            return new WP_Error( 'invalid_timeout', 'タイムアウト時間は60〜18000秒の範囲で入力してください。' );
        }

        // include_pathsの検証
        if ( ! empty( $settings['include_paths'] ) ) {
            $paths = explode( "\n", $settings['include_paths'] );
            foreach ( $paths as $path ) {
                $path = trim( $path );
                if ( empty( $path ) ) {
                    continue;
                }
                // パストラバーサルチェック
                if ( strpos( $path, '..' ) !== false ) {
                    return new WP_Error( 'invalid_include_path', 'インクルードパスに".."を含めることはできません。' );
                }
                // 危険な文字のチェック
                if ( preg_match( '/[<>"|?*]/', $path ) ) {
                    return new WP_Error( 'invalid_include_path', 'インクルードパスに使用できない文字が含まれています。' );
                }
            }
        }

        // exclude_patternsの検証（安全なパターンのみ許可）
        if ( ! empty( $settings['exclude_patterns'] ) ) {
            $patterns = explode( "\n", $settings['exclude_patterns'] );
            foreach ( $patterns as $pattern ) {
                $pattern = trim( $pattern );
                if ( empty( $pattern ) ) {
                    continue;
                }
                // パストラバーサルチェック
                if ( strpos( $pattern, '..' ) !== false ) {
                    return new WP_Error( 'invalid_exclude_pattern', '除外パターンに".."を含めることはできません。' );
                }
                // 危険な文字のチェック（glob用の安全な文字のみ許可）
                if ( ! preg_match( '/^[a-zA-Z0-9_\-.*\/]+$/', $pattern ) ) {
                    return new WP_Error( 'invalid_exclude_pattern', '除外パターンに使用できない文字が含まれています。' );
                }
            }
        }

        // コミットメッセージが空の場合はデフォルト値を設定（エラーにはしない）
        // GitHubが有効で、かつコミットメッセージが空の場合は自動でデフォルト値が使用される

        return true;
    }

    /**
     * トークンを暗号化（AES-256-GCM + 認証タグ）
     *
     * @param string $token トークン
     * @return string|false 暗号化されたトークン、失敗時はfalse
     */
    private function encrypt_token( $token ) {
        // AES-256-GCMを使用（認証付き暗号化）
        $cipher = 'AES-256-GCM';
        $iv_length = openssl_cipher_iv_length( $cipher );
        $iv = random_bytes( $iv_length );
        $tag = '';

        $encrypted = openssl_encrypt(
            $token,
            $cipher,
            $this->encryption_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16 // タグ長
        );

        if ( $encrypted === false ) {
            return false;
        }

        // フォーマット: base64(iv + tag + encrypted)
        // バージョンプレフィックス 'v2:' を追加して旧形式と区別
        return 'v2:' . base64_encode( $iv . $tag . $encrypted );
    }

    /**
     * Basic認証パスワードを暗号化（公開メソッド）
     *
     * @param string $password パスワード
     * @return string 暗号化されたパスワード
     */
    public function encrypt_basic_auth( $password ) {
        return $this->encrypt_token( $password );
    }

    /**
     * Basic認証パスワードを復号化（公開メソッド）
     *
     * @param string $encrypted_password 暗号化されたパスワード
     * @return string|WP_Error 復号化されたパスワード
     */
    public function decrypt_basic_auth( $encrypted_password ) {
        return $this->decrypt_token( $encrypted_password );
    }

    /**
     * トークンを復号化（v2形式と旧形式の両方に対応）
     *
     * @param string $encrypted_token 暗号化されたトークン
     * @return string|WP_Error 復号化されたトークン、失敗ならWP_Error
     */
    private function decrypt_token( $encrypted_token ) {
        // v2形式（AES-256-GCM）の場合
        if ( strpos( $encrypted_token, 'v2:' ) === 0 ) {
            return $this->decrypt_token_v2( substr( $encrypted_token, 3 ) );
        }

        // 旧形式（AES-256-CBC）の場合 - 後方互換性のため維持
        return $this->decrypt_token_legacy( $encrypted_token );
    }

    /**
     * v2形式のトークンを復号化（AES-256-GCM）
     *
     * @param string $encrypted_token 暗号化されたトークン（v2:プレフィックスなし）
     * @return string|WP_Error 復号化されたトークン
     */
    private function decrypt_token_v2( $encrypted_token ) {
        $cipher = 'AES-256-GCM';
        $decoded = base64_decode( $encrypted_token, true );

        if ( $decoded === false ) {
            return new WP_Error( 'decrypt_failed', 'トークンの復号化に失敗しました。再設定が必要です。' );
        }

        $iv_length = openssl_cipher_iv_length( $cipher );
        $tag_length = 16;
        $min_length = $iv_length + $tag_length;

        if ( strlen( $decoded ) < $min_length ) {
            return new WP_Error( 'decrypt_failed', 'トークンの形式が不正です。再設定が必要です。' );
        }

        $iv = substr( $decoded, 0, $iv_length );
        $tag = substr( $decoded, $iv_length, $tag_length );
        $encrypted_data = substr( $decoded, $iv_length + $tag_length );

        $decrypted = openssl_decrypt(
            $encrypted_data,
            $cipher,
            $this->encryption_key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ( $decrypted === false ) {
            return new WP_Error( 'decrypt_failed', 'トークンの復号化に失敗しました（認証エラー）。再設定が必要です。' );
        }

        return $decrypted;
    }

    /**
     * 旧形式のトークンを復号化（AES-256-CBC）- 後方互換性用
     *
     * @param string $encrypted_token 暗号化されたトークン
     * @return string|WP_Error 復号化されたトークン
     */
    private function decrypt_token_legacy( $encrypted_token ) {
        $cipher = 'AES-256-CBC';
        $decoded = base64_decode( $encrypted_token );
        $parts = explode( '::', $decoded, 2 );

        if ( count( $parts ) !== 2 ) {
            return new WP_Error( 'decrypt_failed', 'トークンの復号化に失敗しました。再設定が必要です。' );
        }

        list( $encrypted_data, $iv ) = $parts;
        $decrypted = openssl_decrypt( $encrypted_data, $cipher, $this->encryption_key, 0, $iv );

        if ( $decrypted === false ) {
            return new WP_Error( 'decrypt_failed', 'トークンの復号化に失敗しました。再設定が必要です。' );
        }

        return $decrypted;
    }

    /**
     * 絶対パスかどうかをチェック
     *
     * @param string $path パス
     * @return bool 絶対パスならtrue
     */
    private function is_absolute_path( $path ) {
        // Windowsの絶対パス（C:\... または C:/...）
        if ( preg_match( '/^[a-zA-Z]:[\/\\\\]/', $path ) ) {
            return true;
        }
        // Unix系の絶対パス（/...）
        if ( substr( $path, 0, 1 ) === '/' ) {
            return true;
        }
        return false;
    }

    /**
     * 危険なパスかどうかをチェック
     *
     * @param string $path パス
     * @return bool 危険なパスならtrue
     */
    private function is_dangerous_path( $path ) {
        $dangerous_paths = array(
            '/etc',
            '/System',
            '/bin',
            '/sbin',
            '/usr/bin',
            '/usr/sbin',
            'C:\\Windows',
            'C:/Windows',
            'C:\\System32',
            'C:/System32',
        );

        foreach ( $dangerous_paths as $dangerous ) {
            if ( stripos( $path, $dangerous ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * パスを正規化して安全性を検証
     *
     * @param string $path パス
     * @return string|WP_Error 正規化されたパス、失敗ならWP_Error
     */
    private function validate_safe_path( $path ) {
        // パスを正規化（シンボリックリンクなども解決）
        // ディレクトリが存在しない場合でも親ディレクトリまで検証
        $parts = explode( DIRECTORY_SEPARATOR, $path );
        $test_path = '';
        $real_base = '';

        // 既存の親ディレクトリを見つける
        for ( $i = 0; $i < count( $parts ); $i++ ) {
            $test_path .= $parts[ $i ] . DIRECTORY_SEPARATOR;
            if ( is_dir( $test_path ) ) {
                $real = realpath( $test_path );
                if ( $real !== false ) {
                    $real_base = $real;
                }
            }
        }

        // 正規化されたベースパスに危険な文字列が含まれていないか確認
        if ( ! empty( $real_base ) ) {
            // 正規化後も元のパスの一部であることを確認
            $normalized_input = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $path );
            $normalized_real = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $real_base );

            // 正規化後のパスが元のパスと大きく異なる場合（シンボリックリンクなど）は警告
            if ( strpos( $normalized_input, $normalized_real ) !== 0 ) {
                return new WP_Error( 'path_mismatch', 'パスにシンボリックリンクが含まれている可能性があります。' );
            }
        }

        return $path;
    }

    /**
     * 設定をリセット
     */
    public function reset_settings() {
        $current = get_option( 'sge_settings', array() );

        $default_settings = array(
            'version' => SGE_VERSION,
            'github_enabled' => false,
            'local_enabled' => false,
            'github_token' => isset( $current['github_token'] ) ? $current['github_token'] : '', // トークンは保持
            'github_repo' => '',
            'github_branch_mode' => 'existing',
            'github_existing_branch' => '',
            'github_new_branch' => '',
            'github_base_branch' => '',
            'github_method' => 'api',
            'git_work_dir' => '',
            'local_output_path' => '',
            'include_paths' => '',
            'exclude_patterns' => '',
            'url_mode' => 'relative',
            'timeout' => 600,
            'auto_generate' => false,
            'cache_enabled' => true,
            'use_parallel_crawling' => true,
            'commit_message' => '',
            // 出力設定
            'enable_tag_archive' => false,
            'enable_date_archive' => false,
            'enable_author_archive' => false,
            'enable_post_format_archive' => false,
            'enable_sitemap' => true,
            'enable_robots_txt' => false,
            'enable_rss' => true,
        );

        update_option( 'sge_settings', $default_settings );
    }

    /**
     * 設定をエクスポート
     *
     * @return string JSON形式の設定データ（トークン除外）
     */
    public function export_settings() {
        $settings = $this->get_settings();

        // トークンを除外
        unset( $settings['github_token'] );

        return wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    /**
     * 設定をインポート
     *
     * @param string $json JSON形式の設定データ
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function import_settings( $json ) {
        $imported = json_decode( $json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', 'JSONの形式が正しくありません。' );
        }

        if ( ! is_array( $imported ) ) {
            return new WP_Error( 'invalid_format', '設定データの形式が正しくありません。' );
        }

        // 現在の設定を取得
        $current = $this->get_settings();

        // トークンは現在の値を保持（インポートからは除外）
        unset( $imported['github_token'] );

        // 許可されたキーのみをインポート（ホワイトリスト方式）
        $allowed_keys = array(
            'local_enabled', 'local_dir',
            'github_enabled', 'github_repo', 'github_branch',
            'git_local_enabled', 'git_local_work_dir', 'git_local_branch', 'git_local_push_remote',
            'zip_enabled', 'zip_filename',
            'url_mode', 'include_paths', 'exclude_paths',
            'use_parallel_crawling', 'cache_enabled', 'timeout',
            'schedule_enabled', 'schedule_frequency',
            'commit_message',
        );

        $sanitized = array();
        foreach ( $allowed_keys as $key ) {
            if ( isset( $imported[ $key ] ) ) {
                // 型に応じてサニタイズ
                if ( is_bool( $current[ $key ] ?? false ) || in_array( $key, array( 'local_enabled', 'github_enabled', 'git_local_enabled', 'zip_enabled', 'git_local_push_remote', 'use_parallel_crawling', 'cache_enabled', 'schedule_enabled' ), true ) ) {
                    $sanitized[ $key ] = (bool) $imported[ $key ];
                } elseif ( is_int( $current[ $key ] ?? 0 ) || $key === 'timeout' ) {
                    $sanitized[ $key ] = absint( $imported[ $key ] );
                } else {
                    $sanitized[ $key ] = sanitize_text_field( $imported[ $key ] );
                }
            }
        }

        // テキストエリアフィールドは別途サニタイズ
        $textarea_keys = array( 'include_paths', 'exclude_paths' );
        foreach ( $textarea_keys as $key ) {
            if ( isset( $imported[ $key ] ) ) {
                $sanitized[ $key ] = sanitize_textarea_field( $imported[ $key ] );
            }
        }

        // 可能な範囲で設定を適用
        $merged = array_merge( $current, $sanitized );

        // バージョン情報を更新
        $merged['version'] = SGE_VERSION;

        // バリデーションを実行
        $validation = $this->validate_settings( $merged );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        update_option( 'sge_settings', $merged );

        return true;
    }
}
