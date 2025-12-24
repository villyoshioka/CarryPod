<?php
/**
 * 設定管理クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_Settings {

    /**
     * シングルトンインスタンス
     */
    private static $instance = null;

    /**
     * 暗号化キー
     */
    private $encryption_key;

    /**
     * ベータモードパスワードハッシュ（SHA-256）
     * セキュリティ: ハッシュ値のみを保存し、平文パスワードはソースコードに含めない
     */
    private $beta_password_hash = 'a6301e803f92a28d1342b9248ecaf0fa01a6c59f8a1e8fb1fdcebb21a97de804';

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
     * ベータモードが有効かどうかを確認
     *
     * @return bool 有効ならtrue
     */
    public function is_beta_mode_enabled() {
        return (bool) get_transient( 'cp_beta_channel' );
    }

    /**
     * ベータモードを有効化（パスワード検証付き）
     *
     * セキュリティ:
     * - タイミングセーフな比較（hash_equals）でハッシュ値を検証
     * - パスワードは平文保存せず、SHA-256ハッシュ値のみ保存
     * - ブルートフォース攻撃対策: レート制限実装済み（5回失敗で10分間ロック）
     *
     * @param string $password 入力されたパスワード
     * @return bool|WP_Error 認証成功ならtrue、レート制限超過ならWP_Error
     */
    public function enable_beta_mode( $password ) {
        $user_id = get_current_user_id();
        $attempts_key = 'cp_beta_attempts_' . $user_id;

        // レート制限チェック（5回失敗で10分間ロック）
        $attempts = get_transient( $attempts_key );
        if ( $attempts >= 5 ) {
            return new WP_Error( 'rate_limit', 'ログイン試行回数が超過しました。10分後に再試行してください。' );
        }

        // タイミングセーフなハッシュ比較
        if ( hash_equals( $this->beta_password_hash, hash( 'sha256', $password ) ) ) {
            // 成功時は試行回数をクリア
            delete_transient( $attempts_key );
            set_transient( 'cp_beta_channel', true, DAY_IN_SECONDS );
            return true;
        }

        // 失敗回数をインクリメント（10分間保持）
        $new_attempts = $attempts ? $attempts + 1 : 1;
        set_transient( $attempts_key, $new_attempts, 10 * MINUTE_IN_SECONDS );

        return false;
    }

    /**
     * ベータモードを無効化
     */
    public function disable_beta_mode() {
        delete_transient( 'cp_beta_channel' );
        // ベータ用キャッシュもクリア
        delete_transient( 'cp_github_release_cache_beta' );
    }

    /**
     * 暗号化キーを取得または生成
     *
     * @return string 暗号化キー
     */
    private function get_or_create_encryption_key() {
        // wp-config.php で定義された専用キーがあればそれを使用
        if ( defined( 'CP_ENCRYPTION_KEY' ) && ! empty( CP_ENCRYPTION_KEY ) ) {
            return hash( 'sha256', CP_ENCRYPTION_KEY );
        }

        // データベースに保存された専用キーを取得
        $stored_key = get_option( 'cp_encryption_key' );
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
        update_option( 'cp_encryption_key', $new_key, false );

        return $new_key;
    }

    /**
     * 暗号化キーがwp-config.phpで定義されているかチェック
     *
     * @return bool wp-config.phpで定義されていればtrue
     */
    public function is_encryption_key_in_config() {
        return defined( 'CP_ENCRYPTION_KEY' ) && ! empty( CP_ENCRYPTION_KEY );
    }

    /**
     * 設定を取得
     *
     * @return array 設定の配列
     */
    public function get_settings() {
        $settings = get_option( 'cp_settings', array() );

        // デフォルト値を定義
        $defaults = array(
            'version' => CP_VERSION,
            'github_enabled' => false,
            'local_enabled' => false,
            'zip_enabled' => true, // Ver1.2: デフォルト有効
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
            'base_url' => '',
            'custom_wp_includes' => '',
            'custom_wp_content' => '',
            'timeout' => 300,
            'auto_generate' => false,
            'cache_enabled' => true,
            'commit_message' => '',
            'enable_tag_archive' => false,
            'enable_date_archive' => false,
            'enable_author_archive' => false,
            'enable_post_format_archive' => false,
            'enable_sitemap' => true,
            'enable_robots_txt' => true,
            'enable_rss' => true,
            // Cloudflare Workers設定
            'cloudflare_enabled' => false,
            'cloudflare_api_token' => '',
            'cloudflare_account_id' => '',
            'cloudflare_script_name' => '',
            // GitLab設定
            'gitlab_enabled' => false,
            'gitlab_token' => '',
            'gitlab_project' => '',
            'gitlab_branch_mode' => 'existing',
            'gitlab_existing_branch' => '',
            'gitlab_new_branch' => '',
            'gitlab_base_branch' => '',
            'gitlab_api_url' => 'https://gitlab.com/api/v4',
            // ローカルGit設定
            'git_local_enabled' => false,
            'git_local_work_dir' => '',
            'git_local_branch' => 'main',
            'git_local_push_remote' => false,
            // Netlify設定
            'netlify_enabled' => false,
            'netlify_api_token' => '',
            'netlify_site_id' => '',
            // ZIP出力パス
            'zip_output_path' => '',
        );

        // デフォルト値とマージ（既存の設定を優先）
        $settings = array_merge( $defaults, $settings );

        // トークンを復号化
        if ( ! empty( $settings['github_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['github_token'] );
            $settings['github_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
        }

        // Cloudflareトークンを復号化
        if ( ! empty( $settings['cloudflare_api_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['cloudflare_api_token'] );
            $settings['cloudflare_api_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
        }

        // GitLabトークンを復号化
        if ( ! empty( $settings['gitlab_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['gitlab_token'] );
            $settings['gitlab_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
        }

        // Netlifyトークンを復号化
        if ( ! empty( $settings['netlify_api_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['netlify_api_token'] );
            $settings['netlify_api_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
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
        $current_raw = get_option( 'cp_settings', array() );

        // GitHubトークンが空の場合、既存のトークンを保持
        if ( empty( $settings['github_token'] ) && ! empty( $current_raw['github_token'] ) ) {
            $settings['github_token'] = $current_raw['github_token'];
            // バリデーション用に復号化した値を一時的に設定
            $decrypted = $this->decrypt_token( $current_raw['github_token'] );
            $settings['_has_existing_token'] = ! is_wp_error( $decrypted );
        }

        // Cloudflareトークンが空の場合、既存のトークンを保持
        if ( empty( $settings['cloudflare_api_token'] ) && ! empty( $current_raw['cloudflare_api_token'] ) ) {
            $settings['cloudflare_api_token'] = $current_raw['cloudflare_api_token'];
            // バリデーション用に復号化した値を一時的に設定
            $decrypted = $this->decrypt_token( $current_raw['cloudflare_api_token'] );
            $settings['_has_existing_cf_token'] = ! is_wp_error( $decrypted );
        }

        // GitLabトークンが空の場合、既存のトークンを保持
        if ( empty( $settings['gitlab_token'] ) && ! empty( $current_raw['gitlab_token'] ) ) {
            $settings['gitlab_token'] = $current_raw['gitlab_token'];
            // バリデーション用に復号化した値を一時的に設定
            $decrypted = $this->decrypt_token( $current_raw['gitlab_token'] );
            $settings['_has_existing_gl_token'] = ! is_wp_error( $decrypted );
        }

        // Netlifyトークンが空の場合、既存のトークンを保持
        if ( empty( $settings['netlify_api_token'] ) && ! empty( $current_raw['netlify_api_token'] ) ) {
            $settings['netlify_api_token'] = $current_raw['netlify_api_token'];
            // バリデーション用に復号化した値を一時的に設定
            $decrypted = $this->decrypt_token( $current_raw['netlify_api_token'] );
            $settings['_has_existing_netlify_token'] = ! is_wp_error( $decrypted );
        }

        // バリデーション
        $validation = $this->validate_settings( $settings );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // 一時フラグを削除
        unset( $settings['_has_existing_token'] );
        unset( $settings['_has_existing_cf_token'] );
        unset( $settings['_has_existing_gl_token'] );
        unset( $settings['_has_existing_netlify_token'] );

        // GitHubトークン: 新しいトークンが入力された場合のみ暗号化
        if ( ! empty( $settings['github_token'] ) && strpos( $settings['github_token'], 'v2:' ) !== 0 && strpos( $settings['github_token'], '::' ) === false ) {
            // 暗号化されていない新しいトークン
            $settings['github_token'] = $this->encrypt_token( $settings['github_token'] );
        }

        // Cloudflareトークン: 新しいトークンが入力された場合のみ暗号化
        if ( ! empty( $settings['cloudflare_api_token'] ) && strpos( $settings['cloudflare_api_token'], 'v2:' ) !== 0 && strpos( $settings['cloudflare_api_token'], '::' ) === false ) {
            // 暗号化されていない新しいトークン
            $settings['cloudflare_api_token'] = $this->encrypt_token( $settings['cloudflare_api_token'] );
        }

        // GitLabトークン: 新しいトークンが入力された場合のみ暗号化
        if ( ! empty( $settings['gitlab_token'] ) && strpos( $settings['gitlab_token'], 'v2:' ) !== 0 && strpos( $settings['gitlab_token'], '::' ) === false ) {
            // 暗号化されていない新しいトークン
            $settings['gitlab_token'] = $this->encrypt_token( $settings['gitlab_token'] );
        }

        // Netlifyトークン: 新しいトークンが入力された場合のみ暗号化
        if ( ! empty( $settings['netlify_api_token'] ) && strpos( $settings['netlify_api_token'], 'v2:' ) !== 0 && strpos( $settings['netlify_api_token'], '::' ) === false ) {
            // 暗号化されていない新しいトークン
            $settings['netlify_api_token'] = $this->encrypt_token( $settings['netlify_api_token'] );
        }

        // バージョン情報を追加
        $settings['version'] = CP_VERSION;

        // 設定を保存
        update_option( 'cp_settings', $settings );

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
        if ( empty( $settings['github_enabled'] ) && empty( $settings['git_local_enabled'] ) && empty( $settings['local_enabled'] ) && empty( $settings['zip_enabled'] ) && empty( $settings['cloudflare_enabled'] ) && empty( $settings['gitlab_enabled'] ) && empty( $settings['netlify_enabled'] ) ) {
            return new WP_Error( 'no_output', '出力先を最低1つ選択してください。' );
        }

        // GitHub出力が有効な場合
        if ( ! empty( $settings['github_enabled'] ) ) {
            // トークンが必須（既存トークンがある場合はスキップ）
            $has_token = ! empty( $settings['github_token'] ) || ! empty( $settings['_has_existing_token'] );
            if ( ! $has_token ) {
                return new WP_Error( 'token_required', 'GitHubアクセストークンを入力してください。' );
            }

            // 新しいトークンの形式検証
            if ( ! empty( $settings['github_token'] ) && empty( $settings['_has_existing_token'] ) ) {
                if ( ! $this->is_valid_github_token_format( $settings['github_token'] ) ) {
                    return new WP_Error( 'invalid_token_format', 'GitHubトークンの形式が正しくありません。' );
                }
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

        // ZIP出力が有効な場合
        if ( ! empty( $settings['zip_enabled'] ) ) {
            if ( empty( $settings['zip_output_path'] ) ) {
                return new WP_Error( 'zip_path_required', 'ZIP出力先パスを入力してください。' );
            }

            // 絶対パスチェック
            if ( ! $this->is_absolute_path( $settings['zip_output_path'] ) ) {
                return new WP_Error( 'zip_path_absolute', 'ZIP出力先パスは絶対パスで指定してください。' );
            }

            // パストラバーサルチェック（..を含む場合は拒否）
            if ( strpos( $settings['zip_output_path'], '..' ) !== false ) {
                return new WP_Error( 'path_traversal', 'パスに".."を含めることはできません。' );
            }

            // 危険なパスチェック
            if ( $this->is_dangerous_path( $settings['zip_output_path'] ) ) {
                return new WP_Error( 'dangerous_path', '指定されたパスは使用できません。' );
            }

            // パスを正規化して検証
            $validated_path = $this->validate_safe_path( $settings['zip_output_path'] );
            if ( is_wp_error( $validated_path ) ) {
                return $validated_path;
            }
        }

        // Cloudflare Workers出力が有効な場合
        if ( ! empty( $settings['cloudflare_enabled'] ) ) {
            // APIトークンが必須（既存トークンがある場合はスキップ）
            $has_cf_token = ! empty( $settings['cloudflare_api_token'] ) || ! empty( $settings['_has_existing_cf_token'] );
            if ( ! $has_cf_token ) {
                return new WP_Error( 'cloudflare_token_required', 'Cloudflare APIトークンを入力してください。' );
            }

            // Account IDが必須
            if ( empty( $settings['cloudflare_account_id'] ) ) {
                return new WP_Error( 'cloudflare_account_id_required', 'Cloudflare Account IDを入力してください。' );
            }

            // Account IDの形式チェック（32文字の16進数）
            if ( ! preg_match( '/^[a-f0-9]{32}$/', $settings['cloudflare_account_id'] ) ) {
                return new WP_Error( 'invalid_cloudflare_account_id', 'Cloudflare Account IDの形式が正しくありません（32文字の16進数）' );
            }

            // Worker名が必須
            if ( empty( $settings['cloudflare_script_name'] ) ) {
                return new WP_Error( 'cloudflare_script_name_required', 'Worker名を入力してください。' );
            }

            // Worker名の形式チェック（英数字、ハイフン、アンダースコアのみ）
            if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,62}$/', $settings['cloudflare_script_name'] ) ) {
                return new WP_Error( 'invalid_cloudflare_script_name', 'Worker名は英小文字・数字で始まり、英小文字・数字・ハイフン・アンダースコアのみ使用できます（最大63文字）' );
            }
        }

        // Netlify出力が有効な場合
        if ( ! empty( $settings['netlify_enabled'] ) ) {
            // APIトークン必須
            $has_netlify_token = ! empty( $settings['netlify_api_token'] )
                              || ! empty( $settings['_has_existing_netlify_token'] );
            if ( ! $has_netlify_token ) {
                return new WP_Error( 'netlify_token_required',
                    'Netlify APIトークンを入力してください。' );
            }

            // Site ID必須・UUID形式チェック
            if ( empty( $settings['netlify_site_id'] ) ) {
                return new WP_Error( 'netlify_site_id_required',
                    'Netlify Site IDを入力してください。' );
            }

            // UUIDフォーマット（8-4-4-4-12）
            if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
                               $settings['netlify_site_id'] ) ) {
                return new WP_Error( 'invalid_netlify_site_id',
                    'Netlify Site IDの形式が正しくありません（UUID形式: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx）' );
            }
        }

        // GitLab出力が有効な場合
        if ( ! empty( $settings['gitlab_enabled'] ) ) {
            // トークンが必須（既存トークンがある場合はスキップ）
            $has_gl_token = ! empty( $settings['gitlab_token'] ) || ! empty( $settings['_has_existing_gl_token'] );
            if ( ! $has_gl_token ) {
                return new WP_Error( 'gitlab_token_required', 'GitLabアクセストークンを入力してください。' );
            }

            // 新しいトークンの形式検証
            if ( ! empty( $settings['gitlab_token'] ) && empty( $settings['_has_existing_gl_token'] ) ) {
                if ( ! $this->is_valid_gitlab_token_format( $settings['gitlab_token'] ) ) {
                    return new WP_Error( 'invalid_gitlab_token_format', 'GitLabトークンの形式が正しくありません。' );
                }
            }

            // プロジェクトパスが必須
            if ( empty( $settings['gitlab_project'] ) ) {
                return new WP_Error( 'gitlab_project_required', 'GitLabプロジェクトパスを入力してください（例: username/project）' );
            }

            // プロジェクトパスの形式チェック
            if ( ! preg_match( '/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/', $settings['gitlab_project'] ) ) {
                return new WP_Error( 'invalid_gitlab_project', 'GitLabプロジェクトパスの形式が正しくありません（例: username/project）' );
            }

            // ブランチモードに応じたバリデーション
            if ( $settings['gitlab_branch_mode'] === 'existing' ) {
                if ( empty( $settings['gitlab_existing_branch'] ) ) {
                    return new WP_Error( 'gitlab_branch_required', 'GitLabブランチ名を入力してください。' );
                }
            } elseif ( $settings['gitlab_branch_mode'] === 'new' ) {
                if ( empty( $settings['gitlab_new_branch'] ) ) {
                    return new WP_Error( 'gitlab_new_branch_required', 'GitLab新規ブランチ名を入力してください。' );
                }
            }

            // API URLの形式チェック（オプション）
            if ( ! empty( $settings['gitlab_api_url'] ) ) {
                if ( ! filter_var( $settings['gitlab_api_url'], FILTER_VALIDATE_URL ) ) {
                    return new WP_Error( 'invalid_gitlab_api_url', 'GitLab API URLの形式が正しくありません。' );
                }
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
                // ワイルドカードの数を制限（DoS対策）
                $wildcard_count = substr_count( $pattern, '*' );
                if ( $wildcard_count > 3 ) {
                    return new WP_Error( 'too_many_wildcards', '除外パターンのワイルドカード(*)は3つまでです。' );
                }
                // パスの深さを制限
                $depth = substr_count( $pattern, '/' );
                if ( $depth > 10 ) {
                    return new WP_Error( 'pattern_too_deep', '除外パターンのパス階層が深すぎます（最大10階層）。' );
                }
                // パターン長の制限
                if ( strlen( $pattern ) > 200 ) {
                    return new WP_Error( 'pattern_too_long', '除外パターンが長すぎます（最大200文字）。' );
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
        $default_settings = array(
            'version' => CP_VERSION,
            'github_enabled' => false,
            'local_enabled' => false,
            'zip_enabled' => false, // リセット時は無効（パスが空のため）
            'zip_output_path' => '',
            'github_token' => '', // トークンもクリア
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
            'base_url' => '',
            'custom_wp_includes' => '',
            'custom_wp_content' => '',
            'timeout' => 300,
            'auto_generate' => false,
            'cache_enabled' => true,
            'commit_message' => '',
            // 出力設定
            'enable_tag_archive' => false,
            'enable_date_archive' => false,
            'enable_author_archive' => false,
            'enable_post_format_archive' => false,
            'enable_sitemap' => true,
            'enable_robots_txt' => true,
            'enable_rss' => true,
            // Cloudflare Workers設定
            'cloudflare_enabled' => false,
            'cloudflare_api_token' => '', // トークンもクリア
            'cloudflare_account_id' => '',
            'cloudflare_script_name' => '',
            // GitLab設定
            'gitlab_enabled' => false,
            'gitlab_token' => '', // トークンもクリア
            'gitlab_project' => '',
            'gitlab_branch_mode' => 'existing',
            'gitlab_existing_branch' => '',
            'gitlab_new_branch' => '',
            'gitlab_base_branch' => '',
            'gitlab_api_url' => 'https://gitlab.com/api/v4',
        );

        update_option( 'cp_settings', $default_settings );
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
        // JSONサイズチェック（100KB制限）
        if ( strlen( $json ) > 100000 ) {
            return new WP_Error( 'json_too_large', 'JSONデータが大きすぎます（最大100KB）。' );
        }

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
        unset( $imported['cloudflare_api_token'] );
        unset( $imported['gitlab_token'] );
        unset( $imported['netlify_api_token'] );

        // v1形式からv2形式へのキー名マッピング
        $key_migration = array(
            'local_dir' => 'local_output_path',
            'github_branch' => 'github_existing_branch',
            'zip_filename' => 'zip_output_path',
            'exclude_paths' => 'exclude_patterns',
        );

        // v1形式のキーをv2形式に変換
        foreach ( $key_migration as $old_key => $new_key ) {
            if ( isset( $imported[ $old_key ] ) && ! isset( $imported[ $new_key ] ) ) {
                $imported[ $new_key ] = $imported[ $old_key ];
                unset( $imported[ $old_key ] );
            }
        }

        // v1形式でgithub_branchが設定されていた場合、branch_modeを'existing'に設定
        if ( isset( $imported['github_existing_branch'] ) && ! isset( $imported['github_branch_mode'] ) ) {
            $imported['github_branch_mode'] = 'existing';
        }

        // 許可されたキーのみをインポート（ホワイトリスト方式）
        $allowed_keys = array(
            // v2形式のキー
            'local_enabled', 'local_output_path',
            'github_enabled', 'github_repo',
            'github_branch_mode', 'github_existing_branch', 'github_new_branch', 'github_base_branch',
            'github_method',
            'git_local_enabled', 'git_local_work_dir', 'git_local_branch', 'git_local_push_remote',
            'git_work_dir',
            'zip_enabled', 'zip_output_path',
            'cloudflare_enabled', 'cloudflare_account_id', 'cloudflare_script_name',
            'gitlab_enabled', 'gitlab_project',
            'gitlab_branch_mode', 'gitlab_existing_branch', 'gitlab_new_branch', 'gitlab_base_branch',
            'gitlab_api_url',
            'netlify_enabled', 'netlify_site_id',
            'url_mode', 'base_url', 'custom_wp_includes', 'custom_wp_content', 'include_paths', 'exclude_patterns',
            'cache_enabled', 'timeout',
            'auto_generate',
            'commit_message',
            'enable_tag_archive', 'enable_date_archive', 'enable_author_archive',
            'enable_post_format_archive', 'enable_sitemap', 'enable_robots_txt', 'enable_rss',
        );

        $sanitized = array();
        foreach ( $allowed_keys as $key ) {
            if ( isset( $imported[ $key ] ) ) {
                // 型に応じてサニタイズ
                $boolean_keys = array(
                    'local_enabled', 'github_enabled', 'git_local_enabled', 'zip_enabled',
                    'git_local_push_remote', 'cache_enabled', 'auto_generate',
                    'cloudflare_enabled', 'gitlab_enabled', 'netlify_enabled',
                    'enable_tag_archive', 'enable_date_archive', 'enable_author_archive',
                    'enable_post_format_archive', 'enable_sitemap', 'enable_robots_txt', 'enable_rss',
                );
                if ( is_bool( $current[ $key ] ?? false ) || in_array( $key, $boolean_keys, true ) ) {
                    $sanitized[ $key ] = (bool) $imported[ $key ];
                } elseif ( is_int( $current[ $key ] ?? 0 ) || $key === 'timeout' ) {
                    $sanitized[ $key ] = absint( $imported[ $key ] );
                } else {
                    $sanitized[ $key ] = sanitize_text_field( $imported[ $key ] );
                }
            }
        }

        // テキストエリアフィールドは別途サニタイズ
        $textarea_keys = array( 'include_paths', 'exclude_patterns' );
        foreach ( $textarea_keys as $key ) {
            if ( isset( $imported[ $key ] ) ) {
                $sanitized[ $key ] = sanitize_textarea_field( $imported[ $key ] );
            }
        }

        // 可能な範囲で設定を適用
        $merged = array_merge( $current, $sanitized );

        // バージョン情報を更新
        $merged['version'] = CP_VERSION;

        // バリデーションを実行
        $validation = $this->validate_settings( $merged );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        update_option( 'cp_settings', $merged );

        return true;
    }

    /**
     * GitHubトークンの形式が有効かチェック
     *
     * @param string $token トークン
     * @return bool 有効な形式ならtrue
     */
    private function is_valid_github_token_format( $token ) {
        // 暗号化済みトークンはスキップ
        if ( strpos( $token, 'v2:' ) === 0 || strpos( $token, '::' ) !== false ) {
            return true;
        }

        // 長さチェック（最小20文字、最大255文字）
        $len = strlen( $token );
        if ( $len < 20 || $len > 255 ) {
            return false;
        }

        // GitHub PAT形式: ghp_, gho_, ghu_, ghs_, ghr_ で始まる
        // または従来の40文字の16進数形式
        if ( preg_match( '/^(ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9_]{36,251}$/', $token ) ) {
            return true;
        }

        // 従来の40文字16進数形式
        if ( preg_match( '/^[a-f0-9]{40}$/', $token ) ) {
            return true;
        }

        // Fine-grained PAT形式
        if ( preg_match( '/^github_pat_[A-Za-z0-9_]{22,}$/', $token ) ) {
            return true;
        }

        return false;
    }

    /**
     * GitLabトークンの形式が有効かチェック
     *
     * @param string $token トークン
     * @return bool 有効な形式ならtrue
     */
    private function is_valid_gitlab_token_format( $token ) {
        // 暗号化済みトークンはスキップ
        if ( strpos( $token, 'v2:' ) === 0 || strpos( $token, '::' ) !== false ) {
            return true;
        }

        // 長さチェック（最小10文字、最大255文字）
        $len = strlen( $token );
        if ( $len < 10 || $len > 255 ) {
            return false;
        }

        // GitLab PAT形式: glpat- で始まる（ドット区切りセクションも許可）
        if ( preg_match( '/^glpat-[A-Za-z0-9_.-]+$/', $token ) ) {
            return true;
        }

        // Project/Group Access Token形式: gl***- で始まる
        if ( preg_match( '/^gl[a-z]+-[A-Za-z0-9_.-]+$/', $token ) ) {
            return true;
        }

        // 従来の形式（英数字、アンダースコア、ハイフン、ドット）
        if ( preg_match( '/^[A-Za-z0-9_.-]+$/', $token ) ) {
            return true;
        }

        return false;
    }
}
