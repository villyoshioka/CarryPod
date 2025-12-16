<?php
/**
 * 管理画面クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SGE_Admin {

    /**
     * シングルトンインスタンス
     */
    private static $instance = null;

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
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_notices', array( $this, 'show_notices' ) );
        add_action( 'admin_init', array( $this, 'add_security_headers' ) );

        // Ajax処理
        add_action( 'wp_ajax_sge_execute_generation', array( $this, 'ajax_execute_generation' ) );
        add_action( 'wp_ajax_sge_get_logs', array( $this, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_sge_get_progress', array( $this, 'ajax_get_progress' ) );
        add_action( 'wp_ajax_sge_clear_logs', array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_sge_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_sge_reset_settings', array( $this, 'ajax_reset_settings' ) );
        add_action( 'wp_ajax_sge_export_settings', array( $this, 'ajax_export_settings' ) );
        add_action( 'wp_ajax_sge_import_settings', array( $this, 'ajax_import_settings' ) );
        add_action( 'wp_ajax_sge_clear_cache', array( $this, 'ajax_clear_cache' ) );
        add_action( 'wp_ajax_sge_download_log', array( $this, 'ajax_download_log' ) );
        add_action( 'wp_ajax_sge_cancel_generation', array( $this, 'ajax_cancel_generation' ) );
        add_action( 'wp_ajax_sge_reset_scheduler', array( $this, 'ajax_reset_scheduler' ) );
        add_action( 'wp_ajax_sge_check_error_notification', array( $this, 'ajax_check_error_notification' ) );
        add_action( 'wp_ajax_sge_dismiss_v2_notification', array( $this, 'ajax_dismiss_v2_notification' ) );
    }

    /**
     * 管理メニューを追加
     */
    public function add_admin_menu() {
        // エラー通知バッジ
        $menu_title = 'Carry Pod';
        $error_notification = get_option( 'sge_error_notification', false );
        if ( $error_notification && ! empty( $error_notification['count'] ) ) {
            $menu_title .= ' <span class="awaiting-mod">1</span>';
        }

        // 実行権限があるユーザーにメインメニューを表示
        add_menu_page(
            'Carry Pod',
            $menu_title,
            'sge_execute',
            'static-generation-engine',
            array( $this, 'render_execute_page' ),
            'dashicons-download',
            4
        );

        add_submenu_page(
            'static-generation-engine',
            '実行',
            '実行',
            'sge_execute',
            'static-generation-engine',
            array( $this, 'render_execute_page' )
        );

        // 設定ページは管理権限が必要
        add_submenu_page(
            'static-generation-engine',
            '設定',
            '設定',
            'sge_manage_settings',
            'static-generation-engine-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * スクリプトとスタイルを読み込み
     */
    public function enqueue_scripts( $hook ) {
        // 実行ページまたは設定ページでのみ読み込む
        // 設定ページのフック名は環境によって変動するため、両方に対応
        if ( $hook !== 'toplevel_page_static-generation-engine'
             && $hook !== 'carry-pod_page_static-generation-engine-settings'
             && $hook !== 'carry-pod-1_page_static-generation-engine-settings' ) {
            return;
        }

        wp_enqueue_style( 'sge-admin-css', SGE_PLUGIN_URL . 'assets/css/admin.css', array(), SGE_VERSION . '.' . filemtime( SGE_PLUGIN_DIR . 'assets/css/admin.css' ) );
        wp_enqueue_script( 'sge-admin-js', SGE_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), SGE_VERSION . '.' . filemtime( SGE_PLUGIN_DIR . 'assets/js/admin.js' ), true );

        // ユーザーのカラースキームを取得
        $admin_color = get_user_option( 'admin_color' );
        if ( ! $admin_color ) {
            $admin_color = 'fresh'; // デフォルト
        }

        // カラースキームごとのテーマカラーを定義
        $color_schemes = array(
            'fresh'      => '#2271b1',
            'light'      => '#0085ba',
            'blue'       => '#096484',
            'coffee'     => '#59524c',
            'ectoplasm'  => '#a3b745',
            'midnight'   => '#e14d43',
            'ocean'      => '#627c83',
            'sunrise'    => '#dd823b',
            'modern'     => '#3858e9',
        );

        // 選択されたカラースキームの色を取得
        $theme_color = isset( $color_schemes[ $admin_color ] ) ? $color_schemes[ $admin_color ] : $color_schemes['fresh'];

        // CSS変数を定義するインラインスタイルを追加
        $custom_css = "
            .sge-admin-wrap {
                --wp-admin-theme-color: {$theme_color};
            }
        ";
        wp_add_inline_style( 'sge-admin-css', $custom_css );

        // JavaScriptに渡すデータ
        wp_localize_script( 'sge-admin-js', 'sgeData', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'sge_nonce' ),
        ) );
    }

    /**
     * 通知を表示
     */
    public function show_notices() {
        // プラグイン競合警告
        if ( get_transient( 'sge_plugin_conflict_warning' ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>WP2staticまたはSimply Staticと競合する可能性があります。</p></div>';
            delete_transient( 'sge_plugin_conflict_warning' );
        }

        // Git警告
        if ( get_transient( 'sge_git_warning' ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Gitコマンドが見つかりません。ローカルGit経由でのGitHub出力を使用する場合はGitをインストールしてください。</p></div>';
            delete_transient( 'sge_git_warning' );
        }
    }

    /**
     * 実行画面を表示
     */
    public function render_execute_page() {
        if ( ! current_user_can( 'sge_execute' ) ) {
            wp_die( '静的化実行の権限がありません。' );
        }

        $settings_manager = SGE_Settings::get_instance();
        $settings = $settings_manager->get_settings();

        // コミットメッセージが空の場合は現在時刻で初期化
        if ( empty( $settings['commit_message'] ) ) {
            $settings['commit_message'] = 'update:' . current_time( 'Ymd_His' );
        }

        $logger = SGE_Logger::get_instance();
        $is_running = $logger->is_running();

        // 実行中でない場合は進捗をリセット
        if ( ! $is_running ) {
            $logger->clear_progress();
        }

        // デバッグモードのURLパラメータ処理（サニタイズ後に厳密比較）
        if ( isset( $_GET['debugmode'] ) ) {
            $debug_param = sanitize_text_field( wp_unslash( $_GET['debugmode'] ) );
            if ( $debug_param === 'on' ) {
                $logger->enable_debug_mode();
            } elseif ( $debug_param === 'off' ) {
                $logger->disable_debug_mode();
            }
        }
        $is_debug_mode = $logger->is_debug_mode();

        // ベータモードの状態を取得
        $is_beta_mode = $settings_manager->is_beta_mode_enabled();

        ?>
        <div class="wrap sge-admin-wrap">
            <h1>静的化の実行</h1>

            <?php
            // エラー通知を表示
            $error_notification = get_option( 'sge_error_notification', false );
            if ( $error_notification && ! empty( $error_notification['count'] ) ) {
                $count = intval( $error_notification['count'] );
                echo '<div class="notice notice-error is-dismissible sge-error-notification">';
                echo '<p><strong>静的化中に' . $count . '件エラーが発生しました。最新のログをダウンロードして確認してください。</strong></p>';
                echo '</div>';
            }
            ?>

            <?php if ( $is_debug_mode ) : ?>
            <div class="notice notice-warning">
                <p><strong>デバッグモード</strong> - 詳細なログが出力されます。無効にするには <code>&debugmode=off</code> を追加してください。</p>
            </div>
            <?php endif; ?>

            <?php if ( $is_beta_mode ) : ?>
            <div class="notice notice-info">
                <p><strong>ベータモード</strong> - プレリリース版のアップデートが有効です。無効にするには <code>&sge_beta=off</code> を追加してください。</p>
            </div>
            <?php endif; ?>

            <?php
            // v2.0.0リリース通知（v1.4.2専用機能、v2.0.0で削除予定）
            // 管理者のみ表示
            if ( current_user_can( 'manage_options' ) ) {
                $user_id = get_current_user_id();
                $dismissed = get_transient( 'sge_v2_notification_dismissed_' . $user_id );
                if ( ! $dismissed ) {
                    require_once SGE_PLUGIN_DIR . 'includes/class-updater.php';
                    $updater = new SGE_Updater();
                    $v2_release = $updater->check_v2_release();
                    if ( $v2_release ) {
                        $version = ltrim( $v2_release['tag_name'], 'v' );
                        $release_url = esc_url( $v2_release['html_url'] );
                        ?>
                        <div class="notice notice-warning is-dismissible sge-v2-notification">
                            <p>
                                <strong>新しいメジャーバージョン v<?php echo esc_html( $version ); ?> がリリースされています。</strong>
                                <a href="<?php echo $release_url; ?>" target="_blank" rel="noopener noreferrer">ダウンロードはこちら</a>
                                <br>
                                <span style="font-size: 0.9em; color: #646970;">
                                    ※ 新バージョンへは自動更新できません。設定をエクスポート後、旧バージョンをアンインストールして新バージョンをインストールし、設定をインポートしてください。<br>
                                    　 （注: セキュリティ上、APIトークンはインポートされません。再入力が必要です）
                                </span>
                            </p>
                        </div>
                        <?php
                    }
                }
            }
            ?>

            <div class="sge-dynamic-sections">
                <div class="sge-execute-section">
                    <button type="button" id="sge-execute-button" class="button button-primary" <?php echo $is_running ? 'disabled' : ''; ?>>
                        <?php echo $is_running ? '静的化中...' : '静的化を実行'; ?>
                    </button>
                    <button type="button" id="sge-cancel-button" class="button" <?php echo ! $is_running ? 'disabled' : ''; ?> style="<?php echo ! $is_running ? 'display:none;' : ''; ?>">
                        実行中止
                    </button>
                </div>

                <div class="sge-commit-section <?php echo ( ! empty( $settings['github_enabled'] ) || ! empty( $settings['git_local_enabled'] ) ) ? 'active' : ''; ?>">
                    <h3>コミットメッセージ</h3>
                    <div class="sge-section-content">
                        <div class="sge-form-group">
                            <div class="sge-commit-container">
                                <input type="text" id="sge-commit-message" class="regular-text" value="<?php echo esc_attr( ! empty( $settings['commit_message'] ) ? $settings['commit_message'] : 'update:' . current_time( 'Ymd_His' ) ); ?>" placeholder="コミットメッセージを入力">
                                <button type="button" id="sge-reset-commit-message" class="button">リセット</button>
                            </div>
                            <p class="description">デフォルト形式: update:YYYYMMDD_HHMMSS</p>
                        </div>
                    </div>
                </div>

                <div class="sge-progress-section">
                    <h3>進捗状況</h3>
                    <div class="sge-progress-container">
                        <div class="sge-progress-header">
                            <div class="sge-progress-bar-wrapper">
                                <div id="sge-progress-bar" class="sge-progress-bar" style="width: 0%"></div>
                            </div>
                            <span id="sge-progress-percentage" class="sge-progress-percentage">0%</span>
                        </div>
                        <div id="sge-progress-status" class="sge-progress-status">待機中...</div>
                        <div style="margin-top: 15px;">
                            <button type="button" id="sge-download-log" class="button" <?php echo $is_running ? 'disabled' : ''; ?>>最新のログをダウンロード</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sge-version-info">
                Carry Pod <a href="https://github.com/villyoshioka/CarryPod/releases/tag/v<?php echo esc_attr( SGE_VERSION ); ?>" target="_blank" rel="noopener noreferrer">v<?php echo esc_html( SGE_VERSION ); ?></a>
            </div>
        </div>
        <?php
    }

    /**
     * 設定画面を表示
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'sge_manage_settings' ) ) {
            wp_die( '設定変更の権限がありません。' );
        }

        $settings_manager = SGE_Settings::get_instance();
        $settings = $settings_manager->get_settings();

        // コミットメッセージが空の場合は現在時刻で初期化（表示用のみ、保存はしない）
        if ( empty( $settings['commit_message'] ) ) {
            $settings['commit_message'] = 'update:' . current_time( 'Ymd_His' );
        }

        $logger = SGE_Logger::get_instance();
        $is_running = $logger->is_running();

        // デバッグモードのURLパラメータ処理（サニタイズ後に厳密比較）
        if ( isset( $_GET['debugmode'] ) ) {
            $debug_param = sanitize_text_field( wp_unslash( $_GET['debugmode'] ) );
            if ( $debug_param === 'on' ) {
                $logger->enable_debug_mode();
            } elseif ( $debug_param === 'off' ) {
                $logger->disable_debug_mode();
            }
        }
        $is_debug_mode = $logger->is_debug_mode();

        // v2テストモードのURLパラメータ処理（v1.4.2専用、v2.0.0で削除予定）
        if ( isset( $_GET['sge_test_v2'] ) ) {
            $test_param = sanitize_text_field( wp_unslash( $_GET['sge_test_v2'] ) );
            if ( $test_param === 'on' ) {
                set_transient( 'sge_test_v2_mode', true, HOUR_IN_SECONDS );
            } elseif ( $test_param === 'off' ) {
                delete_transient( 'sge_test_v2_mode' );
            }
        }

        // ベータモードのURLパラメータ処理
        $beta_message = '';
        if ( isset( $_GET['sge_beta'] ) ) {
            $beta_param = sanitize_text_field( wp_unslash( $_GET['sge_beta'] ) );
            if ( $beta_param === 'on' ) {
                // 既にベータモードが有効な場合はスキップ
                if ( $settings_manager->is_beta_mode_enabled() ) {
                    // 既に有効、何もしない
                } elseif ( isset( $_POST['sge_beta_password'] ) && isset( $_POST['sge_beta_nonce'] ) ) {
                    // パスワード認証処理
                    if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sge_beta_nonce'] ) ), 'sge_beta_auth' ) ) {
                        $password = sanitize_text_field( wp_unslash( $_POST['sge_beta_password'] ) );
                        if ( $settings_manager->enable_beta_mode( $password ) ) {
                            $beta_message = 'activated';
                        } else {
                            $beta_message = 'wrong_password';
                        }
                    }
                } else {
                    $beta_message = 'need_password';
                }
            } elseif ( $beta_param === 'off' ) {
                $settings_manager->disable_beta_mode();
                $beta_message = 'deactivated';
            }
        }
        $is_beta_mode = $settings_manager->is_beta_mode_enabled();

        ?>
        <div class="wrap sge-admin-wrap">
            <h1>設定</h1>

            <?php if ( $is_debug_mode ) : ?>
            <div class="notice notice-warning">
                <p><strong>デバッグモード</strong> - 詳細なログが出力されます。無効にするには <code>&debugmode=off</code> を追加してください。</p>
            </div>
            <?php endif; ?>

            <?php if ( $is_beta_mode ) : ?>
            <div class="notice notice-info">
                <p><strong>ベータモード</strong> - プレリリース版のアップデートが有効です。無効にするには <code>&sge_beta=off</code> を追加してください。</p>
            </div>
            <?php endif; ?>

            <?php
            // v2.0.0リリース通知（v1.4.2専用機能、v2.0.0で削除予定）
            // 管理者のみ表示
            if ( current_user_can( 'manage_options' ) ) {
                $user_id = get_current_user_id();
                $dismissed = get_transient( 'sge_v2_notification_dismissed_' . $user_id );
                if ( ! $dismissed ) {
                    require_once SGE_PLUGIN_DIR . 'includes/class-updater.php';
                    $updater = new SGE_Updater();
                    $v2_release = $updater->check_v2_release();
                    if ( $v2_release ) {
                        $version = ltrim( $v2_release['tag_name'], 'v' );
                        $release_url = esc_url( $v2_release['html_url'] );
                        ?>
                        <div class="notice notice-warning is-dismissible sge-v2-notification">
                            <p>
                                <strong>新しいメジャーバージョン v<?php echo esc_html( $version ); ?> がリリースされています。</strong>
                                <a href="<?php echo $release_url; ?>" target="_blank" rel="noopener noreferrer">ダウンロードはこちら</a>
                                <br>
                                <span style="font-size: 0.9em; color: #646970;">
                                    ※ 新バージョンへは自動更新できません。設定をエクスポート後、旧バージョンをアンインストールして新バージョンをインストールし、設定をインポートしてください。<br>
                                    　 （注: セキュリティ上、APIトークンはインポートされません。再入力が必要です）
                                </span>
                            </p>
                        </div>
                        <?php
                    }
                }
            }
            ?>

            <?php if ( $beta_message === 'need_password' ) : ?>
            <div class="notice notice-warning">
                <p><strong>ベータモード認証</strong></p>
                <form method="post" style="margin: 10px 0;">
                    <?php wp_nonce_field( 'sge_beta_auth', 'sge_beta_nonce' ); ?>
                    <input type="password" name="sge_beta_password" placeholder="パスワードを入力" style="width: 200px;" />
                    <input type="submit" class="button" value="認証" />
                </form>
            </div>
            <?php elseif ( $beta_message === 'wrong_password' ) : ?>
            <div class="notice notice-error">
                <p>パスワードが正しくありません。</p>
            </div>
            <div class="notice notice-warning">
                <p><strong>ベータモード認証</strong></p>
                <form method="post" style="margin: 10px 0;">
                    <?php wp_nonce_field( 'sge_beta_auth', 'sge_beta_nonce' ); ?>
                    <input type="password" name="sge_beta_password" placeholder="パスワードを入力" style="width: 200px;" />
                    <input type="submit" class="button" value="認証" />
                </form>
            </div>
            <?php elseif ( $beta_message === 'activated' ) : ?>
            <div class="notice notice-success">
                <p>ベータモードを有効化しました。</p>
            </div>
            <?php elseif ( $beta_message === 'deactivated' ) : ?>
            <div class="notice notice-info">
                <p>ベータモードを無効化しました。</p>
            </div>
            <?php endif; ?>

            <form id="sge-settings-form">
                <?php wp_nonce_field( 'sge_save_settings', 'sge_settings_nonce' ); ?>

                    <h3>出力先設定</h3>

                    <!-- 1. Cloudflare Workers -->
                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" id="sge-cloudflare-enabled" name="cloudflare_enabled" value="1" <?php checked( ! empty( $settings['cloudflare_enabled'] ) ); ?>>
                            Cloudflare Workersに出力
                        </label>
                    </div>

                    <div id="sge-cloudflare-settings" class="sge-subsection" <?php echo empty( $settings['cloudflare_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="sge-form-group">
                            <label for="sge-cloudflare-api-token">Cloudflare API Token <span class="required">*</span></label>
                            <?php
                            $has_cf_token = ! empty( $settings['cloudflare_api_token'] );
                            $cf_placeholder = $has_cf_token ? '設定済み（変更する場合は新しいトークンを入力）' : 'APIトークンを入力してください';
                            ?>
                            <input type="password" id="sge-cloudflare-api-token" name="cloudflare_api_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $cf_placeholder ); ?>">
                            <?php if ( $has_cf_token ) : ?>
                                <span class="sge-token-status sge-token-set">✓ トークン設定済み</span>
                            <?php endif; ?>
                            <p class="description">必要権限: Account > Workers Scripts > Edit</p>
                        </div>

                        <div class="sge-form-group">
                            <label for="sge-cloudflare-account-id">Account ID <span class="required">*</span></label>
                            <input type="text" id="sge-cloudflare-account-id" name="cloudflare_account_id" class="regular-text" value="<?php echo esc_attr( $settings['cloudflare_account_id'] ?? '' ); ?>" placeholder="例: 1234567890abcdef1234567890abcdef">
                            <p class="description">Cloudflareダッシュボード > Workers & Pages > 右側のサイドバーで確認できます</p>
                        </div>

                        <div class="sge-form-group">
                            <label for="sge-cloudflare-script-name">Worker名 <span class="required">*</span></label>
                            <input type="text" id="sge-cloudflare-script-name" name="cloudflare_script_name" class="regular-text" value="<?php echo esc_attr( $settings['cloudflare_script_name'] ?? '' ); ?>" placeholder="例: my-static-site">
                            <p class="description">デプロイ先のWorker名（存在しない場合は自動作成されます）</p>
                        </div>

                    </div>

                    <!-- 2. Netlify -->
                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" id="sge-netlify-enabled" name="netlify_enabled" value="1" <?php checked( ! empty( $settings['netlify_enabled'] ) ); ?>>
                            Netlifyに出力
                        </label>
                    </div>

                    <div id="sge-netlify-settings" class="sge-subsection" <?php echo empty( $settings['netlify_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="sge-form-group">
                            <label for="sge-netlify-api-token">Netlify Personal Access Token <span class="required">*</span></label>
                            <?php
                            $has_netlify_token = ! empty( $settings['netlify_api_token'] );
                            $netlify_placeholder = $has_netlify_token ? '設定済み（変更する場合は新しいトークンを入力）' : 'APIトークンを入力してください';
                            ?>
                            <input type="password" id="sge-netlify-api-token" name="netlify_api_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $netlify_placeholder ); ?>">
                            <?php if ( $has_netlify_token ) : ?>
                                <span class="sge-token-status sge-token-set">✓ トークン設定済み</span>
                            <?php endif; ?>
                            <p class="description">
                                <a href="https://app.netlify.com/user/applications#personal-access-tokens" target="_blank" rel="noopener noreferrer">Personal Access Tokenを作成</a>
                            </p>
                        </div>

                        <div class="sge-form-group">
                            <label for="sge-netlify-site-id">Netlify Site ID <span class="required">*</span></label>
                            <input type="text" id="sge-netlify-site-id" name="netlify_site_id" class="regular-text" value="<?php echo esc_attr( $settings['netlify_site_id'] ?? '' ); ?>" placeholder="例: 12345678-1234-1234-1234-123456789012">
                            <p class="description">Site settings → General → Site details → Site information から確認できます</p>
                        </div>
                    </div>

                    <!-- 3. GitHub -->
                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" id="sge-github-enabled" name="github_enabled" value="1" <?php checked( ! empty( $settings['github_enabled'] ) ); ?>>
                            GitHubに出力
                        </label>
                    </div>

                    <div id="sge-github-settings" class="sge-subsection" <?php echo empty( $settings['github_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="sge-form-group">
                            <label for="sge-github-token">GitHub Personal Access Token <span class="required">*</span></label>
                            <?php
                            $has_token = ! empty( $settings['github_token'] );
                            $placeholder = $has_token ? '設定済み（変更する場合は新しいトークンを入力）' : 'トークンを入力してください';
                            ?>
                            <input type="password" id="sge-github-token" name="github_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>">
                            <?php if ( $has_token ) : ?>
                                <span class="sge-token-status sge-token-set">✓ トークン設定済み</span>
                            <?php endif; ?>
                            <p class="description">必要権限: repo（フルアクセス）<br>※ wp-config.phpの認証用定数変更時は再入力が必要です</p>
                        </div>

                        <div class="sge-form-group">
                            <label for="sge-github-repo">リポジトリ名 <span class="required">*</span></label>
                            <input type="text" id="sge-github-repo" name="github_repo" class="regular-text" value="<?php echo esc_attr( $settings['github_repo'] ?? '' ); ?>" placeholder="owner/repo">
                            <p class="description">形式: owner/repo</p>
                        </div>

                        <div class="sge-form-group sge-branch-settings">
                            <label>ブランチ設定</label>
                            <div class="sge-branch-option">
                                <label class="sge-radio-label">
                                    <input type="radio" name="github_branch_mode" value="existing" <?php checked( $settings['github_branch_mode'] ?? 'existing', 'existing' ); ?>>
                                    既存ブランチを使用
                                </label>
                                <div class="sge-branch-input">
                                    <input type="text" id="sge-github-existing-branch" name="github_existing_branch" class="regular-text" value="<?php echo esc_attr( $settings['github_existing_branch'] ?? '' ); ?>" placeholder="main" <?php echo ( $settings['github_branch_mode'] ?? 'existing' ) !== 'existing' ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                            <div class="sge-branch-option" style="margin-top: 10px;">
                                <label class="sge-radio-label">
                                    <input type="radio" name="github_branch_mode" value="new" <?php checked( $settings['github_branch_mode'] ?? 'existing', 'new' ); ?>>
                                    新規ブランチを作成
                                </label>
                                <div class="sge-branch-input sge-branch-nested">
                                    <label>分岐元ブランチ名</label>
                                    <input type="text" id="sge-github-base-branch" name="github_base_branch" class="regular-text" value="<?php echo esc_attr( $settings['github_base_branch'] ?? '' ); ?>" placeholder="main" <?php echo ( $settings['github_branch_mode'] ?? 'existing' ) !== 'new' ? 'disabled' : ''; ?>>
                                    <label>新規ブランチ名</label>
                                    <input type="text" id="sge-github-new-branch" name="github_new_branch" class="regular-text" value="<?php echo esc_attr( $settings['github_new_branch'] ?? '' ); ?>" placeholder="static-site" <?php echo ( $settings['github_branch_mode'] ?? 'existing' ) !== 'new' ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- 3. GitLab -->
                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" id="sge-gitlab-enabled" name="gitlab_enabled" value="1" <?php checked( ! empty( $settings['gitlab_enabled'] ) ); ?>>
                            GitLabに出力
                        </label>
                    </div>

                    <div id="sge-gitlab-settings" class="sge-subsection" <?php echo empty( $settings['gitlab_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="sge-form-group">
                            <label for="sge-gitlab-token">GitLab Personal Access Token <span class="required">*</span></label>
                            <?php
                            $has_gl_token = ! empty( $settings['gitlab_token'] );
                            $gl_placeholder = $has_gl_token ? '設定済み（変更する場合は新しいトークンを入力）' : 'トークンを入力してください';
                            ?>
                            <input type="password" id="sge-gitlab-token" name="gitlab_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $gl_placeholder ); ?>">
                            <?php if ( $has_gl_token ) : ?>
                                <span class="sge-token-status sge-token-set">✓ トークン設定済み</span>
                            <?php endif; ?>
                            <p class="description">必要権限: api（フルアクセス）または write_repository<br>※ wp-config.phpの認証用定数変更時は再入力が必要です</p>
                        </div>

                        <div class="sge-form-group">
                            <label for="sge-gitlab-project">プロジェクトパス <span class="required">*</span></label>
                            <input type="text" id="sge-gitlab-project" name="gitlab_project" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_project'] ?? '' ); ?>" placeholder="username/project">
                            <p class="description">形式: username/project または group/subgroup/project</p>
                        </div>

                        <div class="sge-form-group sge-branch-settings">
                            <label>ブランチ設定</label>
                            <div class="sge-branch-option">
                                <label class="sge-radio-label">
                                    <input type="radio" name="gitlab_branch_mode" value="existing" <?php checked( $settings['gitlab_branch_mode'] ?? 'existing', 'existing' ); ?>>
                                    既存ブランチを使用
                                </label>
                                <div class="sge-branch-input">
                                    <input type="text" id="sge-gitlab-existing-branch" name="gitlab_existing_branch" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_existing_branch'] ?? '' ); ?>" placeholder="main" <?php echo ( $settings['gitlab_branch_mode'] ?? 'existing' ) !== 'existing' ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                            <div class="sge-branch-option" style="margin-top: 10px;">
                                <label class="sge-radio-label">
                                    <input type="radio" name="gitlab_branch_mode" value="new" <?php checked( $settings['gitlab_branch_mode'] ?? 'existing', 'new' ); ?>>
                                    新規ブランチを作成
                                </label>
                                <div class="sge-branch-input sge-branch-nested">
                                    <label>分岐元ブランチ名</label>
                                    <input type="text" id="sge-gitlab-base-branch" name="gitlab_base_branch" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_base_branch'] ?? '' ); ?>" placeholder="main" <?php echo ( $settings['gitlab_branch_mode'] ?? 'existing' ) !== 'new' ? 'disabled' : ''; ?>>
                                    <label>新規ブランチ名</label>
                                    <input type="text" id="sge-gitlab-new-branch" name="gitlab_new_branch" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_new_branch'] ?? '' ); ?>" placeholder="static-site" <?php echo ( $settings['gitlab_branch_mode'] ?? 'existing' ) !== 'new' ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                        </div>

                        <div class="sge-form-group">
                            <label for="sge-gitlab-api-url">GitLab API URL</label>
                            <input type="text" id="sge-gitlab-api-url" name="gitlab_api_url" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_api_url'] ?? 'https://gitlab.com/api/v4' ); ?>" placeholder="https://gitlab.com/api/v4">
                            <p class="description">セルフホストのGitLabを使用する場合は変更してください<br>例: https://gitlab.example.com/api/v4</p>
                        </div>
                    </div>

                    <!-- 4. ローカルGit -->
                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" id="sge-git-local-enabled" name="git_local_enabled" value="1" <?php checked( ! empty( $settings['git_local_enabled'] ) ); ?>>
                            ローカルGitに出力
                        </label>
                    </div>

                    <div id="sge-git-local-settings" class="sge-subsection" <?php echo empty( $settings['git_local_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="sge-form-group">
                            <label for="sge-git-local-work-dir">Git作業ディレクトリ <span class="required">*</span></label>
                            <input type="text" id="sge-git-local-work-dir" name="git_local_work_dir" class="regular-text" value="<?php echo esc_attr( $settings['git_local_work_dir'] ?? '' ); ?>" placeholder="/path/to/git/repo">
                            <p class="description">ローカルGitリポジトリのパス（絶対パス）</p>
                        </div>

                        <div class="sge-form-group">
                            <label for="sge-git-local-branch">ブランチ名 <span class="required">*</span></label>
                            <input type="text" id="sge-git-local-branch" name="git_local_branch" class="regular-text" value="<?php echo esc_attr( $settings['git_local_branch'] ?? 'main' ); ?>" placeholder="main">
                            <p class="description">コミット先のブランチ名</p>
                        </div>

                        <div class="sge-form-group">
                            <label>
                                <input type="checkbox" id="sge-git-local-push-remote" name="git_local_push_remote" value="1" <?php checked( ! empty( $settings['git_local_push_remote'] ) ); ?>>
                                リモートにプッシュする
                            </label>
                            <p class="description">チェックを入れると、コミット後にリモート（origin）にプッシュします</p>
                        </div>
                    </div>

                    <!-- 5. ローカルディレクトリ -->
                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" id="sge-local-enabled" name="local_enabled" value="1" <?php checked( ! empty( $settings['local_enabled'] ) ); ?>>
                            ローカルディレクトリに出力
                        </label>
                    </div>

                    <div id="sge-local-settings" class="sge-subsection" <?php echo empty( $settings['local_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="sge-form-group">
                            <label for="sge-local-output-path">静的ファイル出力先パス <span class="required">*</span></label>
                            <input type="text" id="sge-local-output-path" name="local_output_path" class="regular-text" value="<?php echo esc_attr( $settings['local_output_path'] ?? '' ); ?>" placeholder="<?php echo esc_attr( ( PHP_OS === 'WINNT' ? 'C:/output' : '/Users/username/output' ) ); ?>">
                            <p class="description">
                                例：<br>
                                Windows: C:\output または C:/output<br>
                                Mac/Linux: /Users/username/output
                            </p>
                        </div>
                    </div>

                    <!-- 6. ZIP -->
                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" id="sge-zip-enabled" name="zip_enabled" value="1" <?php checked( ! empty( $settings['zip_enabled'] ) ); ?>>
                            ZIPファイルとして出力
                        </label>
                    </div>

                    <div id="sge-zip-settings" class="sge-subsection" <?php echo empty( $settings['zip_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="sge-form-group">
                            <label for="sge-zip-output-path">ZIP出力先パス <span class="required">*</span></label>
                            <input type="text" id="sge-zip-output-path" name="zip_output_path" class="regular-text" value="<?php echo esc_attr( $settings['zip_output_path'] ?? '' ); ?>">
                            <p class="description">
                                ZIPファイルを保存するディレクトリを指定します。<br>
                                ファイル名: static-output-YYYYMMDD_HHMMSS.zip
                            </p>
                        </div>
                    </div>

                    <h3>ファイル設定</h3>

                    <div class="sge-form-group">
                        <label for="sge-include-paths">追加したいファイル/フォルダのパス指定</label>
                        <textarea id="sge-include-paths" name="include_paths" class="large-text" rows="5"><?php echo esc_textarea( $settings['include_paths'] ?? '' ); ?></textarea>
                        <p class="description">
                            記載例：<br>
                            /home/username/Desktop/logo.png<br>
                            /Users/username/Documents/manual.pdf
                        </p>
                    </div>

                    <div class="sge-form-group">
                        <label for="sge-exclude-patterns">除外したいファイル/フォルダのパス指定</label>
                        <textarea id="sge-exclude-patterns" name="exclude_patterns" class="large-text" rows="5"><?php echo esc_textarea( $settings['exclude_patterns'] ?? '' ); ?></textarea>
                        <p class="description">
                            記載例：<br>
                            *.log<br>
                            wp-content/cache/*
                        </p>
                    </div>

                    <h3>出力設定</h3>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="enable_tag_archive" value="1" <?php checked( $settings['enable_tag_archive'] ?? false ); ?>>
                            タグアーカイブを出力
                        </label>
                        <p class="description">無効にするとタグアーカイブページを出力せず、投稿ページ内のタグも非表示になります</p>
                    </div>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="enable_date_archive" value="1" <?php checked( $settings['enable_date_archive'] ?? false ); ?>>
                            日付アーカイブを出力
                        </label>
                        <p class="description">無効にすると日付アーカイブページを出力せず、投稿ページ内の日付はリンクなしで表示されます</p>
                    </div>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="enable_author_archive" value="1" <?php checked( $settings['enable_author_archive'] ?? false ); ?>>
                            著者アーカイブを出力
                        </label>
                        <p class="description">無効にすると著者アーカイブページを出力せず、投稿ページ内の著者名はリンクなしで表示されます</p>
                    </div>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="enable_post_format_archive" value="1" <?php checked( $settings['enable_post_format_archive'] ?? false ); ?>>
                            投稿フォーマットアーカイブを出力
                        </label>
                        <p class="description">無効にすると投稿フォーマットアーカイブ（/type/image/、/type/video/など）を出力しません</p>
                    </div>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="enable_sitemap" value="1" <?php checked( $settings['enable_sitemap'] ?? true ); ?>>
                            サイトマップ（sitemap.xml）を出力
                        </label>
                        <p class="description">無効にするとサイトマップファイルを出力しません</p>
                    </div>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="enable_robots_txt" value="1" <?php checked( $settings['enable_robots_txt'] ?? false ); ?>>
                            robots.txtを出力
                        </label>
                        <p class="description">無効にするとrobots.txtファイルを出力しません</p>
                    </div>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="enable_rss" value="1" <?php checked( $settings['enable_rss'] ?? true ); ?>>
                            RSSフィードを出力
                        </label>
                        <p class="description">無効にするとRSSフィードファイルを出力しません</p>
                    </div>

                    <h3>その他設定</h3>

                    <div class="sge-form-group">
                        <label>URL形式</label>
                        <div>
                            <label>
                                <input type="radio" name="url_mode" value="relative" <?php checked( $settings['url_mode'] ?? 'relative', 'relative' ); ?>>
                                相対パス
                            </label>
                        </div>
                        <div>
                            <label>
                                <input type="radio" name="url_mode" value="absolute" <?php checked( $settings['url_mode'] ?? 'relative', 'absolute' ); ?>>
                                絶対パス
                            </label>
                        </div>
                    </div>

                    <div class="sge-form-group">
                        <label for="sge-timeout">タイムアウト時間（秒）</label>
                        <input type="number" id="sge-timeout" name="timeout" class="small-text" value="<?php echo esc_attr( $settings['timeout'] ?? 600 ); ?>" min="60" max="18000">
                        <p class="description">60〜18000秒の範囲で入力してください（デフォルト: 600秒）</p>
                    </div>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="auto_generate" value="1" <?php checked( ! empty( $settings['auto_generate'] ) ); ?>>
                            記事公開時に自動で静的化を実行する
                        </label>
                    </div>

                    <div class="sge-form-group">
                        <label>
                            <input type="checkbox" name="cache_enabled" value="1" <?php checked( ! empty( $settings['cache_enabled'] ) ); ?>>
                            キャッシュを有効化（生成を高速化）
                        </label>
                        <p class="description">変更がないページはキャッシュから取得してスキップします</p>
                    </div>

                    <div class="sge-form-actions">
                        <button type="submit" class="button button-primary" id="sge-save-settings" <?php echo $is_running ? 'disabled' : ''; ?>>設定を保存</button>
                        <button type="button" class="button" id="sge-reset-settings" <?php echo $is_running ? 'disabled' : ''; ?>>設定をリセット</button>
                        <button type="button" class="button" id="sge-clear-cache" <?php echo $is_running ? 'disabled' : ''; ?>>キャッシュをクリア</button>
                        <button type="button" class="button" id="sge-clear-logs" <?php echo $is_running ? 'disabled' : ''; ?>>ログをクリア</button>
                        <button type="button" class="button" id="sge-export-settings">設定をエクスポート</button>
                        <button type="button" class="button" id="sge-import-settings">設定をインポート</button>
                        <button type="button" class="button" id="sge-reset-scheduler">Scheduled Actionsをリセット</button>
                        <input type="file" id="sge-import-file" accept=".json" style="display:none;">
                    </div>
                </form>

                <div class="sge-version-info">
                    Carry Pod <a href="https://github.com/villyoshioka/CarryPod/releases/tag/v<?php echo esc_attr( SGE_VERSION ); ?>" target="_blank" rel="noopener noreferrer">v<?php echo esc_html( SGE_VERSION ); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Ajax: 静的化を実行
     */
    public function ajax_execute_generation() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        // レート制限チェック（1分間に3回まで）
        if ( ! $this->check_rate_limit( 'execute_generation', 3, 60 ) ) {
            wp_send_json_error( array( 'message' => 'リクエストが多すぎます。しばらく待ってから再試行してください。' ) );
        }

        if ( ! current_user_can( 'sge_execute' ) ) {
            wp_send_json_error( array( 'message' => '静的化実行の権限がありません。' ) );
        }

        // Action Schedulerが利用可能かチェック
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            wp_send_json_error( array( 'message' => 'Action Schedulerが読み込まれていません。プラグインを再度有効化してください。' ) );
        }

        $logger = SGE_Logger::get_instance();

        // コミットメッセージを保存（実行時に動的生成するため、ここでは保存しない）
        $settings = get_option( 'sge_settings', array() );
        if ( isset( $_POST['commit_message'] ) && ! empty( trim( $_POST['commit_message'] ) ) ) {
            $settings['commit_message'] = sanitize_text_field( $_POST['commit_message'] );
            update_option( 'sge_settings', $settings );
        } elseif ( empty( $settings['commit_message'] ) ) {
            // コミットメッセージが空の場合は実行時に動的生成するため、ここでは何もしない
            // 実行時に最新のタイムスタンプでコミットメッセージが生成される
        }

        // アトミックに実行中フラグをチェックして設定（競合状態を防ぐ）
        $lock_key = 'sge_execution_lock';
        $lock_timeout = 3600; // 1時間
        $lock_value = wp_generate_uuid4(); // ユニークなロック識別子

        // ロック取得を試行
        $lock_acquired = add_option( $lock_key, array( 'value' => $lock_value, 'time' => time() ), '', 'no' );

        if ( ! $lock_acquired ) {
            // 既にロックが存在する場合、タイムアウトをチェック
            $existing_lock = get_option( $lock_key );

            if ( is_array( $existing_lock ) && isset( $existing_lock['time'] ) ) {
                $lock_age = time() - $existing_lock['time'];

                if ( $lock_age < $lock_timeout ) {
                    // ロックが有効 - 実行中
                    wp_send_json_error( array( 'message' => '既に実行中です。しばらくお待ちください。' ) );
                }

                // タイムアウトしたロックを削除して再取得を試行（アトミックに）
                // delete + add の間に別プロセスが入らないよう、条件付き削除
                global $wpdb;
                $deleted = $wpdb->delete(
                    $wpdb->options,
                    array(
                        'option_name' => $lock_key,
                        'option_value' => maybe_serialize( $existing_lock ),
                    ),
                    array( '%s', '%s' )
                );

                if ( $deleted ) {
                    // 削除成功 - 新しいロックを取得
                    $lock_acquired = add_option( $lock_key, array( 'value' => $lock_value, 'time' => time() ), '', 'no' );
                }
            } else {
                // 不正な形式のロック - 削除して再取得
                delete_option( $lock_key );
                $lock_acquired = add_option( $lock_key, array( 'value' => $lock_value, 'time' => time() ), '', 'no' );
            }

            if ( ! $lock_acquired ) {
                wp_send_json_error( array( 'message' => '実行の開始に失敗しました。もう一度お試しください。' ) );
            }
        }

        // 既存の保留中タスクをキャンセル
        as_unschedule_all_actions( 'sge_static_generation', array(), 'sge' );

        // 実行中フラグをクリア（前回のクラッシュで残っている場合に備えて）
        delete_transient( 'sge_manual_running' );
        delete_transient( 'sge_auto_running' );

        // 進捗情報をクリア
        $logger->clear_progress();

        // ログを強制的にクリア（実行中フラグをチェックせずに直接クリア）
        update_option( 'sge_logs', array() );
        // クリア後に初期ログを記録（ログがクリアされたことを確認するため）
        $logger->add_log( '新しい実行を開始します...' );

        // 実行中フラグをセット（タスクをキューに入れる前にセット）
        set_transient( 'sge_manual_running', true, 3600 );

        // Action Schedulerで非同期実行
        as_enqueue_async_action( 'sge_static_generation', array(), 'sge' );

        // ロックを解除（タスクがキューに入ったら）
        delete_option( $lock_key );

        // 初期進捗状態をセット
        $logger->update_progress( 0, 1, 'バックグラウンド処理を待機中...' );

        wp_send_json_success( array( 'message' => '静的化を開始しました。' ) );
    }

    /**
     * Ajax: ログを取得
     */
    public function ajax_get_logs() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $logger = SGE_Logger::get_instance();
        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

        $logs = $logger->get_logs_from_offset( $offset );
        $is_running = $logger->is_running();

        wp_send_json_success( array(
            'logs' => $logs,
            'total_count' => $logger->get_log_count(),
            'is_running' => $is_running,
        ) );
    }

    /**
     * Ajax: 進捗を取得
     */
    public function ajax_get_progress() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $logger = SGE_Logger::get_instance();
        $progress = $logger->get_progress();
        $is_running = $logger->is_running();

        wp_send_json_success( array(
            'progress' => $progress,
            'is_running' => $is_running,
        ) );
    }

    /**
     * Ajax: ログをクリア
     */
    public function ajax_clear_logs() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        // 実行中かチェック
        $logger = SGE_Logger::get_instance();
        if ( $logger->is_running() ) {
            wp_send_json_error( array( 'message' => '静的化実行中はログをクリアできません。' ) );
        }

        // ログをクリア
        update_option( 'sge_logs', array() );

        // エラー通知をクリア
        delete_option( 'sge_error_notification' );

        wp_send_json_success( array( 'message' => 'ログをクリアしました。' ) );
    }

    /**
     * Ajax: 設定を保存
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        // レート制限チェック（1分間に10回まで）
        if ( ! $this->check_rate_limit( 'save_settings', 10, 60 ) ) {
            wp_send_json_error( array( 'message' => 'リクエストが多すぎます。しばらく待ってから再試行してください。' ) );
        }

        // フォームのnonceも検証
        if ( isset( $_POST['sge_settings_nonce'] ) && ! wp_verify_nonce( $_POST['sge_settings_nonce'], 'sge_save_settings' ) ) {
            wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。' ) );
        }

        if ( ! current_user_can( 'sge_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        // ホワイトリスト: 許可されたフィールドのみ処理
        $allowed_fields = array(
            // Boolean fields
            'github_enabled' => 'boolean',
            'git_local_enabled' => 'boolean',
            'git_local_push_remote' => 'boolean',
            'local_enabled' => 'boolean',
            'zip_enabled' => 'boolean',
            'auto_generate' => 'boolean',
            'cache_enabled' => 'boolean',
            'enable_tag_archive' => 'boolean',
            'enable_date_archive' => 'boolean',
            'enable_author_archive' => 'boolean',
            'enable_post_format_archive' => 'boolean',
            'enable_sitemap' => 'boolean',
            'enable_robots_txt' => 'boolean',
            'enable_rss' => 'boolean',
            'cloudflare_enabled' => 'boolean',
            'gitlab_enabled' => 'boolean',
            'netlify_enabled' => 'boolean',
            // Text fields
            'github_token' => 'text',
            'github_repo' => 'text',
            'github_branch_mode' => 'text',
            'github_existing_branch' => 'text',
            'github_new_branch' => 'text',
            'github_base_branch' => 'text',
            'git_local_work_dir' => 'text',
            'git_local_branch' => 'text',
            'local_output_path' => 'text',
            'zip_output_path' => 'text',
            'url_mode' => 'text',
            'commit_message' => 'text',
            'cloudflare_api_token' => 'text',
            'cloudflare_account_id' => 'text',
            'cloudflare_script_name' => 'text',
            'gitlab_token' => 'text',
            'gitlab_project' => 'text',
            'gitlab_branch_mode' => 'text',
            'gitlab_existing_branch' => 'text',
            'gitlab_new_branch' => 'text',
            'gitlab_base_branch' => 'text',
            'gitlab_api_url' => 'text',
            'netlify_api_token' => 'text',
            'netlify_site_id' => 'text',
            // Textarea fields
            'include_paths' => 'textarea',
            'exclude_patterns' => 'textarea',
            // Integer fields
            'timeout' => 'integer',
        );

        $settings = array();
        foreach ( $allowed_fields as $field => $type ) {
            if ( $type === 'boolean' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? 1 : 0;
            } elseif ( $type === 'text' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
            } elseif ( $type === 'textarea' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '';
            } elseif ( $type === 'integer' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? intval( $_POST[ $field ] ) : 0;
            }
        }

        // デフォルト値の設定
        if ( empty( $settings['github_branch_mode'] ) ) {
            $settings['github_branch_mode'] = 'existing';
        }
        if ( empty( $settings['git_local_branch'] ) ) {
            $settings['git_local_branch'] = 'main';
        }
        if ( empty( $settings['url_mode'] ) ) {
            $settings['url_mode'] = 'relative';
        }
        if ( empty( $settings['timeout'] ) || $settings['timeout'] < 60 ) {
            $settings['timeout'] = 600;
        }
        if ( empty( $settings['gitlab_branch_mode'] ) ) {
            $settings['gitlab_branch_mode'] = 'existing';
        }
        if ( empty( $settings['gitlab_api_url'] ) ) {
            $settings['gitlab_api_url'] = 'https://gitlab.com/api/v4';
        }

        // 設定を保存
        $settings_manager = SGE_Settings::get_instance();
        $result = $settings_manager->save_settings( $settings );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => '設定を保存しました。' ) );
    }

    /**
     * Ajax: 設定をリセット
     */
    public function ajax_reset_settings() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        $settings_manager = SGE_Settings::get_instance();
        $settings_manager->reset_settings();

        wp_send_json_success( array( 'message' => '設定をリセットしました。' ) );
    }

    /**
     * Ajax: 設定をエクスポート
     */
    public function ajax_export_settings() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        $settings_manager = SGE_Settings::get_instance();
        $json = $settings_manager->export_settings();

        wp_send_json_success( array( 'data' => $json ) );
    }

    /**
     * Ajax: 設定をインポート
     */
    public function ajax_import_settings() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        if ( ! isset( $_POST['data'] ) ) {
            wp_send_json_error( array( 'message' => 'データが送信されていません。' ) );
        }

        // インポートデータをサニタイズ（JSON文字列としてエスケープ処理）
        $import_data = wp_unslash( $_POST['data'] );

        $settings_manager = SGE_Settings::get_instance();
        $result = $settings_manager->import_settings( $import_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => '設定をインポートしました。トークンを再入力してください。' ) );
    }

    /**
     * Ajax: キャッシュをクリア
     */
    public function ajax_clear_cache() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        $cache = SGE_Cache::get_instance();
        $stats = $cache->get_stats();
        $deleted = $cache->clear_all();

        wp_send_json_success( array(
            'message' => sprintf( '%d 個のキャッシュファイル（%s）を削除しました。', $deleted, $stats['size_formatted'] ),
            'deleted' => $deleted,
        ) );
    }

    /**
     * Ajax: 最新のログをダウンロード
     */
    public function ajax_download_log() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        try {
            $logger = SGE_Logger::get_instance();
            $logs = $logger->get_logs();

            if ( empty( $logs ) ) {
                wp_send_json_error( array(
                    'message' => 'ログがありません。まず静的化を実行してください。',
                ) );
            }

            // ログは個別のエントリの配列なので、すべてのログを使用
            // 最初のログエントリからタイムスタンプを取得
            $first_log = reset( $logs );
            $timestamp = $first_log['timestamp'];

            // エラーがあるかチェック
            $has_error = false;
            foreach ( $logs as $log_entry ) {
                if ( ! empty( $log_entry['is_error'] ) ) {
                    $has_error = true;
                    break;
                }
            }

            // 設定情報を取得
            $settings_manager = SGE_Settings::get_instance();
            $settings = $settings_manager->get_settings();

            // キャッシュ情報を取得
            $cache = SGE_Cache::get_instance();
            $cache_stats = $cache->get_stats();

            // ログをテキスト形式に変換
            $log_text = "=====================================\n";
            $log_text .= "Carry Pod - 生成ログ\n";
            $log_text .= "=====================================\n\n";

            // 基本情報
            $log_text .= "【基本情報】\n";
            $log_text .= "生成日時: " . $timestamp . "\n";
            $log_text .= "ステータス: " . ( $has_error ? 'エラー' : '成功' ) . "\n";
            $log_text .= "プラグインバージョン: " . SGE_VERSION . "\n";
            $log_text .= "WordPress バージョン: " . get_bloginfo( 'version' ) . "\n";
            $log_text .= "PHP バージョン: " . PHP_VERSION . "\n";
            $log_text .= "\n";

            // 設定情報
            $log_text .= "【設定情報】\n";
            $log_text .= "出力先:\n";
            $log_text .= "  - GitHub出力: " . ( ! empty( $settings['github_enabled'] ) ? '有効' : '無効' ) . "\n";
            if ( ! empty( $settings['github_enabled'] ) ) {
                $log_text .= "    - リポジトリ: " . ( $settings['github_repo'] ?? 'なし' ) . "\n";
                $log_text .= "    - ブランチモード: " . ( $settings['github_branch_mode'] === 'existing' ? '既存ブランチ' : '新規ブランチ' ) . "\n";
                if ( $settings['github_branch_mode'] === 'existing' ) {
                    $log_text .= "    - ブランチ名: " . ( $settings['github_existing_branch'] ?? 'なし' ) . "\n";
                } else {
                    $log_text .= "    - 新規ブランチ名: " . ( $settings['github_new_branch'] ?? 'なし' ) . "\n";
                    $log_text .= "    - 分岐元ブランチ: " . ( $settings['github_base_branch'] ?? 'なし' ) . "\n";
                }
            }
            $log_text .= "  - ローカルGit出力: " . ( ! empty( $settings['git_local_enabled'] ) ? '有効' : '無効' ) . "\n";
            if ( ! empty( $settings['git_local_enabled'] ) ) {
                $log_text .= "    - 作業ディレクトリ: " . ( $settings['git_local_work_dir'] ?? 'なし' ) . "\n";
                $log_text .= "    - ブランチ名: " . ( $settings['git_local_branch'] ?? 'main' ) . "\n";
                $log_text .= "    - リモートプッシュ: " . ( ! empty( $settings['git_local_push_remote'] ) ? '有効' : '無効' ) . "\n";
            }
            $log_text .= "  - ローカルディレクトリ出力: " . ( ! empty( $settings['local_enabled'] ) ? '有効' : '無効' ) . "\n";
            if ( ! empty( $settings['local_enabled'] ) ) {
                $log_text .= "    - 出力先: " . ( $settings['local_output_path'] ?? 'なし' ) . "\n";
            }
            $log_text .= "  - GitLab出力: " . ( ! empty( $settings['gitlab_enabled'] ) ? '有効' : '無効' ) . "\n";
            if ( ! empty( $settings['gitlab_enabled'] ) ) {
                $log_text .= "    - プロジェクト: " . ( $settings['gitlab_project'] ?? 'なし' ) . "\n";
                $log_text .= "    - ブランチモード: " . ( ( $settings['gitlab_branch_mode'] ?? 'existing' ) === 'existing' ? '既存ブランチ' : '新規ブランチ' ) . "\n";
                if ( ( $settings['gitlab_branch_mode'] ?? 'existing' ) === 'existing' ) {
                    $log_text .= "    - ブランチ名: " . ( $settings['gitlab_existing_branch'] ?? 'main' ) . "\n";
                } else {
                    $log_text .= "    - 新規ブランチ名: " . ( $settings['gitlab_new_branch'] ?? 'なし' ) . "\n";
                    $log_text .= "    - 分岐元ブランチ: " . ( $settings['gitlab_base_branch'] ?? 'main' ) . "\n";
                }
                $log_text .= "    - API URL: " . ( $settings['gitlab_api_url'] ?? 'https://gitlab.com' ) . "\n";
            }
            $log_text .= "  - Cloudflare Workers出力: " . ( ! empty( $settings['cloudflare_enabled'] ) ? '有効' : '無効' ) . "\n";
            if ( ! empty( $settings['cloudflare_enabled'] ) ) {
                $log_text .= "    - Worker名: " . ( $settings['cloudflare_script_name'] ?? 'なし' ) . "\n";
            }
            $log_text .= "  - Netlify出力: " . ( ! empty( $settings['netlify_enabled'] ) ? '有効' : '無効' ) . "\n";
            if ( ! empty( $settings['netlify_enabled'] ) ) {
                $log_text .= "    - Site ID: " . ( $settings['netlify_site_id'] ?? 'なし' ) . "\n";
            }
            $log_text .= "  - ZIP出力: " . ( ! empty( $settings['zip_enabled'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "\n";

            $log_text .= "その他の設定:\n";
            $log_text .= "  - URL形式: " . ( $settings['url_mode'] === 'relative' ? '相対パス' : '絶対パス' ) . "\n";
            $log_text .= "  - タイムアウト: " . ( $settings['timeout'] ?? 600 ) . " 秒\n";
            $log_text .= "  - 自動静的化: " . ( ! empty( $settings['auto_generate'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "  - キャッシュ: " . ( ! empty( $settings['cache_enabled'] ) ? '有効' : '無効' ) . "\n";
            if ( ! empty( $settings['cache_enabled'] ) ) {
                $log_text .= "    - キャッシュファイル数: " . $cache_stats['count'] . " 個\n";
                $log_text .= "    - キャッシュサイズ: " . $cache_stats['size_formatted'] . "\n";
            }
            $log_text .= "  - 出力対象:\n";
            $log_text .= "    - タグアーカイブ: " . ( ! empty( $settings['enable_tag_archive'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - 日付アーカイブ: " . ( ! empty( $settings['enable_date_archive'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - 著者アーカイブ: " . ( ! empty( $settings['enable_author_archive'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - サイトマップ: " . ( ! empty( $settings['enable_sitemap'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - robots.txt: " . ( ! empty( $settings['enable_robots_txt'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - RSS: " . ( ! empty( $settings['enable_rss'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "\n";

            // ログメッセージ
            $log_text .= "【処理ログ】\n";
            $log_text .= "-------------------------------------\n";

            $message_count = count( $logs );
            $error_count = 0;
            $cache_hit_count = 0;

            foreach ( $logs as $index => $log_entry ) {
                $message = $log_entry['message'];
                $log_text .= sprintf( "[%d/%d] %s: %s\n", $index + 1, $message_count, $log_entry['timestamp'], $message );

                // 統計情報を収集
                if ( ! empty( $log_entry['is_error'] ) || strpos( $message, 'エラー' ) !== false || strpos( $message, '失敗' ) !== false ) {
                    $error_count++;
                }
                if ( strpos( $message, 'キャッシュを使用' ) !== false ) {
                    $cache_hit_count++;
                }
            }

            $log_text .= "-------------------------------------\n\n";

            // 統計情報
            $log_text .= "【統計情報】\n";
            $log_text .= "総メッセージ数: " . $message_count . " 件\n";
            if ( $error_count > 0 ) {
                $log_text .= "エラー数: " . $error_count . " 件\n";
            }
            if ( $cache_hit_count > 0 ) {
                $log_text .= "キャッシュヒット数: " . $cache_hit_count . " 件\n";
            }
            $log_text .= "\n";

            $log_text .= "=====================================\n";
            $log_text .= "Generated with Carry Pod\n";
            $log_text .= "=====================================\n";

            // エラー通知をクリア
            delete_option( 'sge_error_notification' );

            wp_send_json_success( array(
                'log' => $log_text,
                'filename' => 'sge-log-' . date( 'Ymd-His', strtotime( $timestamp ) ) . '.txt',
            ) );

        } catch ( Exception $e ) {
            // 詳細なエラー情報はログに記録
            error_log( 'SGE Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString() );

            // ユーザーには一般的なメッセージのみ返す
            wp_send_json_error( array(
                'message' => 'エラーが発生しました。詳細はサーバーログをご確認ください。',
            ) );
        }
    }

    /**
     * Ajax: 静的化を中止
     */
    public function ajax_cancel_generation() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        try {
            // Action Schedulerのタスクをキャンセル
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'sge_static_generation', array(), 'sge' );
            }

            // 実行中フラグをクリア
            delete_transient( 'sge_manual_running' );
            delete_transient( 'sge_auto_running' );

            // ログと進捗情報をクリア
            update_option( 'sge_logs', array() );
            delete_option( 'sge_progress' );

            wp_send_json_success( array( 'message' => '実行を中止しました。' ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => 'エラーが発生しました: ' . $e->getMessage() ) );
        }
    }

    /**
     * Ajax: Scheduled Actionsをリセット
     */
    public function ajax_reset_scheduler() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        try {
            global $wpdb;

            // Action Schedulerのすべてのタスクをキャンセル
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'sge_static_generation', array(), 'sge' );
            }

            // 実行中フラグをクリア
            delete_transient( 'sge_manual_running' );
            delete_transient( 'sge_auto_running' );

            // 進捗情報をクリア
            delete_option( 'sge_progress' );

            // Action Schedulerのテーブルから直接削除
            $tables = array(
                $wpdb->prefix . 'actionscheduler_actions',
                $wpdb->prefix . 'actionscheduler_claims',
                $wpdb->prefix . 'actionscheduler_groups',
                $wpdb->prefix . 'actionscheduler_logs',
            );

            $deleted_total = 0;

            foreach ( $tables as $table ) {
                // テーブルが存在するか確認
                $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

                if ( $table_exists ) {
                    // sgeグループに関連するレコードを削除（タイムアウト付き）
                    if ( $table === $wpdb->prefix . 'actionscheduler_actions' ) {
                        $deleted = $this->safe_delete_records(
                            $table,
                            "DELETE FROM {$table} WHERE group_id IN (SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s)",
                            array( 'sge' ),
                            60
                        );
                    } elseif ( $table === $wpdb->prefix . 'actionscheduler_groups' ) {
                        $deleted = $this->safe_delete_records(
                            $table,
                            "DELETE FROM {$table} WHERE slug = %s",
                            array( 'sge' ),
                            60
                        );
                    } else {
                        // claimsとlogsは直接削除（sgeアクションに関連するものを削除）
                        $deleted = $this->safe_delete_records(
                            $table,
                            "DELETE FROM {$table} WHERE action_id IN (SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s)",
                            array( 'sge_static_generation' ),
                            60
                        );
                    }

                    if ( ! is_wp_error( $deleted ) ) {
                        $deleted_total += $deleted;
                    } else {
                        // タイムアウトエラーの場合も部分的な削除数を加算
                        if ( isset( $deleted->error_data['partial_delete'] ) ) {
                            $deleted_total += $deleted->error_data['partial_delete'];
                        }
                    }
                }
            }

            wp_send_json_success( array(
                'message' => sprintf( 'Scheduled Actionsをリセットしました（%d件のレコードを削除）', $deleted_total ),
                'deleted' => $deleted_total,
            ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => 'エラーが発生しました: ' . $e->getMessage() ) );
        }
    }

    /**
     * Ajax: エラー通知チェック
     */
    public function ajax_check_error_notification() {
        $error_notification = get_option( 'sge_error_notification', false );
        $has_error = $error_notification && ! empty( $error_notification['count'] );

        wp_send_json_success( array(
            'has_error' => $has_error,
            'count' => $has_error ? intval( $error_notification['count'] ) : 0,
        ) );
    }

    /**
     * レート制限をチェック
     *
     * @param string $action アクション名
     * @param int $limit 制限回数
     * @param int $period 期間（秒）
     * @return bool 制限内ならtrue
     */
    private function check_rate_limit( $action, $limit = 10, $period = 60 ) {
        $user_id = get_current_user_id();
        $key = 'sge_rate_limit_' . $action . '_' . $user_id;
        $attempts = get_transient( $key );

        if ( $attempts === false ) {
            // 初回アクセス
            set_transient( $key, 1, $period );
            return true;
        }

        if ( $attempts >= $limit ) {
            // 制限超過 - セキュリティイベントをログ
            $this->log_security_event( 'rate_limit_exceeded', array(
                'action' => $action,
                'user_id' => $user_id,
                'attempts' => $attempts,
            ) );
            return false;
        }

        // カウントを増やす
        set_transient( $key, $attempts + 1, $period );
        return true;
    }

    /**
     * セキュリティヘッダーを追加
     */
    public function add_security_headers() {
        // 自分の管理画面にのみCSPヘッダーを追加
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'sge' ) !== false ) {
            header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;" );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Frame-Options: SAMEORIGIN' );
            header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        }
    }

    /**
     * セキュリティイベントをログに記録
     *
     * @param string $event_type イベントタイプ
     * @param array $context コンテキスト情報
     */
    private function log_security_event( $event_type, $context = array() ) {
        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'event_type' => $event_type,
            'user_id' => get_current_user_id(),
            'ip_address' => $this->get_client_ip(),
            'context' => $context,
        );

        // セキュリティログをオプションに保存（最大100件）
        $security_logs = get_option( 'sge_security_logs', array() );
        array_unshift( $security_logs, $log_entry );
        $security_logs = array_slice( $security_logs, 0, 100 );
        update_option( 'sge_security_logs', $security_logs, false );

        // 重要なイベントはerror_logにも記録
        if ( in_array( $event_type, array( 'rate_limit_exceeded', 'auth_failed', 'invalid_nonce' ) ) ) {
            error_log( 'SGE Security Event: ' . $event_type . ' - User: ' . $log_entry['user_id'] . ' - IP: ' . $log_entry['ip_address'] );
        }
    }

    /**
     * クライアントIPアドレスを取得
     *
     * セキュリティ注意: HTTP_CLIENT_IP や HTTP_X_FORWARDED_FOR はスプーフィング可能。
     * REMOTE_ADDR のみを信頼し、プロキシ環境では信頼できるプロキシ設定が必要。
     *
     * @return string IPアドレス
     */
    private function get_client_ip() {
        // REMOTE_ADDR のみを信頼（スプーフィング対策）
        // プロキシ環境の場合は、サーバー側で信頼できるプロキシからのヘッダーのみを
        // REMOTE_ADDR に設定するよう構成する必要がある
        $ip = '';
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            // 有効なIPアドレス形式かチェック
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                $ip = '';
            }
        }
        return $ip;
    }

    /**
     * タイムアウト付き安全なレコード削除
     *
     * @param string $table テーブル名
     * @param string $query DELETEクエリ（LIMIT句なし）
     * @param array $params プリペアドステートメントのパラメータ
     * @param int $timeout タイムアウト秒数（デフォルト60秒）
     * @return int|WP_Error 削除件数またはエラー
     */
    private function safe_delete_records( $table, $query, $params = array(), $timeout = 60 ) {
        global $wpdb;

        $start_time = time();
        $batch_size = 100;
        $deleted_total = 0;

        // まず件数を確認
        $count_query = str_replace( 'DELETE FROM', 'SELECT COUNT(*) FROM', $query );
        $total_count = $wpdb->get_var( $wpdb->prepare( $count_query, $params ) );

        if ( $total_count === null || $total_count == 0 ) {
            return 0;
        }

        // 少数の場合は一括削除
        if ( $total_count <= $batch_size ) {
            $result = $wpdb->query( $wpdb->prepare( $query, $params ) );
            return $result !== false ? $result : 0;
        }

        // 大量データの場合はバッチ削除
        while ( true ) {
            // タイムアウトチェック
            if ( ( time() - $start_time ) >= $timeout ) {
                $error = new WP_Error(
                    'db_timeout',
                    sprintf( '%d件削除後にタイムアウトしました（残り約%d件）', $deleted_total, $total_count - $deleted_total )
                );
                $error->error_data = array( 'partial_delete' => $deleted_total );
                return $error;
            }

            // LIMIT付きクエリを作成（intvalで確実に整数化）
            $batch_query = $query . ' LIMIT ' . intval( $batch_size );
            $deleted = $wpdb->query( $wpdb->prepare( $batch_query, $params ) );

            if ( $deleted === false || $deleted === 0 ) {
                break; // 削除完了または失敗
            }

            $deleted_total += $deleted;

            // 負荷軽減のため少し待機
            usleep( 10000 ); // 10ms
        }

        return $deleted_total;
    }

    /**
     * Ajax: v2.0.0通知を無視
     *
     * v1.4.2専用機能、v2.0.0で削除予定
     */
    public function ajax_dismiss_v2_notification() {
        check_ajax_referer( 'sge_nonce', 'nonce' );

        if ( ! current_user_can( 'sge_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $user_id = get_current_user_id();
        // 3日間非表示
        set_transient( 'sge_v2_notification_dismissed_' . $user_id, true, 3 * DAY_IN_SECONDS );

        wp_send_json_success( array( 'message' => '通知を非表示にしました。' ) );
    }
}
