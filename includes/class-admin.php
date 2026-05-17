<?php
/**
 * 管理画面クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Mati plugin stubs for static analysis (never executed).
if ( false ) {
    class Mati_Settings {
        public static function get_instance(): self { return new self(); }
        /** @return array<string, mixed> */
        public function get_settings(): array { return []; }
    }
    define( 'MATI_VERSION', '' );
}

class CP_Admin {

    private static ?self $instance = null;

    public static function get_instance(): static {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_notices', array( $this, 'show_notices' ) );
        add_action( 'admin_init', array( $this, 'add_security_headers' ) );

        add_action( 'wp_ajax_cp_execute_generation', array( $this, 'ajax_execute_generation' ) );
        add_action( 'wp_ajax_cp_get_logs', array( $this, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_cp_get_progress', array( $this, 'ajax_get_progress' ) );
        add_action( 'wp_ajax_cp_clear_logs', array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_cp_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_cp_reset_settings', array( $this, 'ajax_reset_settings' ) );
        add_action( 'wp_ajax_cp_export_settings', array( $this, 'ajax_export_settings' ) );
        add_action( 'wp_ajax_cp_import_settings', array( $this, 'ajax_import_settings' ) );
        add_action( 'wp_ajax_cp_clear_cache', array( $this, 'ajax_clear_cache' ) );
        add_action( 'wp_ajax_cp_download_log', array( $this, 'ajax_download_log' ) );
        add_action( 'wp_ajax_cp_cancel_generation', array( $this, 'ajax_cancel_generation' ) );
        add_action( 'wp_ajax_cp_reset_scheduler', array( $this, 'ajax_reset_scheduler' ) );
        add_action( 'wp_ajax_cp_check_error_notification', array( $this, 'ajax_check_error_notification' ) );
        add_action( 'wp_ajax_cp_is_running', array( $this, 'ajax_is_running' ) );
        add_action( 'wp_ajax_cp_download_zip', array( $this, 'ajax_download_zip' ) );
    }

    public function add_admin_menu(): void {
        $menu_title = 'Carry Pod';
        $error_notification = get_option( 'cp_error_notification', false );
        if ( $error_notification && ! empty( $error_notification['count'] ) ) {
            $menu_title .= ' <span class="awaiting-mod">1</span>';
        }

        add_menu_page(
            'CarryPod',
            $menu_title,
            'cp_execute',
            'carry-pod',
            array( $this, 'render_execute_page' ),
            'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0iY3VycmVudENvbG9yIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xMS40OSwyLjg0bC0uNDMtMS41NmgtLjU3Yy0uMDgtLjE3LS4yNy0uMjktLjQ4LS4yOXMtLjQuMTItLjQ4LjI5aC0uNTdsLS40MywxLjU2QzQuMjUsMy40OSwxLDYuODMsMSwxMC44NmMwLDQuNDksNC4wMyw4LjE0LDksOC4xNHM5LTMuNjQsOS04LjE0YzAtNC4wMy0zLjI1LTcuMzctNy41MS04LjAyWk0xMCwxNy41NmMtNC4wOSwwLTcuNDEtMy03LjQxLTYuN3MzLjMyLTYuNyw3LjQxLTYuNyw3LjQxLDMsNy40MSw2LjctMy4zMiw2LjctNy40MSw2LjdaIi8+PHBhdGggZmlsbC1ydWxlPSJldmVub2RkIiBkPSJNMTAsNS42Yy0zLjIyLDAtNS44MiwyLjM2LTUuODIsNS4yN3MyLjYxLDUuMjcsNS44Miw1LjI3LDUuODItMi4zNiw1LjgyLTUuMjctMi42MS01LjI3LTUuODItNS4yN1pNMTIuOCw3Ljc2bC0xLjgxLDEuNjRjLS4yOS0uMTYtLjYyLS4yNi0uOTgtLjI2LS4zNywwLS43LjA5LS45OS4yNWwtMS44Ni0xLjZjLjc3LS41OCwxLjc1LS45NSwyLjg0LS45NXMyLjA0LjM1LDIuOC45MlpNMTAuMDEsMTBoMGMuNTMsMCwuOTUuMzkuOTUuODdzLS40My44Ni0uOTYuODVoMGMtLjUzLDAtLjk1LS4zOS0uOTUtLjg3cy40My0uODYuOTYtLjg1Wk02LjUxLDguNGwxLjg1LDEuNmMtLjE2LjI1LS4yNi41NC0uMjcuODUsMCwuMzIuMS42Mi4yNy44OGwtMS44NiwxLjU5Yy0uNTktLjY4LS45NS0xLjUzLS45NS0yLjQ2cy4zNy0xLjc4Ljk1LTIuNDZaTTcuMTUsMTMuOTNsMS44Ni0xLjZjLjI4LjE2LjYxLjI1Ljk3LjI1LjM3LDAsLjctLjA5Ljk5LS4yNWwxLjc4LDEuNjZjLS43Ni41NS0xLjcxLjg5LTIuNzYuODlzLTIuMDctLjM3LTIuODUtLjk2Wk0xMy40LDEzLjQzbC0xLjc4LTEuNjdjLjE4LS4yNi4yOS0uNTYuMjktLjg5LDAtLjM0LS4xMS0uNjUtLjI5LS45MWwxLjgxLTEuNjNjLjYzLjY5LDEuMDIsMS41NywxLjAyLDIuNTNzLS40LDEuODctMS4wNSwyLjU3WiIvPjwvc3ZnPgo=',
            4
        );

        add_submenu_page(
            'carry-pod',
            'CarryPod',
            '実行',
            'cp_execute',
            'carry-pod',
            array( $this, 'render_execute_page' )
        );

        add_submenu_page(
            'carry-pod',
            'CarryPod 設定',
            '設定',
            'cp_manage_settings',
            'carry-pod-settings',
            array( $this, 'render_settings_page' )
        );

        $this->cleanup_temp_zips();
    }

    private function cleanup_temp_zips(): void {
        $upload_dir = wp_upload_dir();
        $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'carry-pod-tmp/';
        if ( ! is_dir( $tmp_dir ) ) {
            return;
        }
        $files = glob( $tmp_dir . '*.zip' );
        if ( ! $files ) {
            return;
        }
        $expiry = time() - 3600;
        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $expiry ) {
                unlink( $file );
            }
        }
    }

    public function enqueue_scripts( string $hook ): void {
        $wp_lock_hooks = array(
            'post.php',
            'post-new.php',
            'themes.php',
            'customize.php',
            'nav-menus.php',
            'widgets.php',
            'options-permalink.php',
            'options-general.php',
        );

        if ( in_array( $hook, $wp_lock_hooks, true ) ) {
            $logger = CP_Logger::get_instance();
            $is_running = $logger->is_running();

            $deps = array( 'jquery' );
            if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
                $deps[] = 'wp-data';
                $deps[] = 'wp-dom-ready';
            }

            wp_enqueue_script(
                'cp-wp-lock',
                CP_PLUGIN_URL . 'assets/js/wp-lock.js',
                $deps,
                CP_VERSION . '.' . filemtime( CP_PLUGIN_DIR . 'assets/js/wp-lock.js' ),
                true
            );
            wp_localize_script( 'cp-wp-lock', 'cpLockData', array(
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'hook'       => $hook,
                'isRunning'  => $is_running,
            ) );
            return;
        }

        if ( $hook !== 'toplevel_page_carry-pod'
             && $hook !== 'carry-pod_page_carry-pod-settings'
             && $hook !== 'carry-pod-1_page_carry-pod-settings' ) {
            return;
        }

        wp_enqueue_style( 'nau-admin-fw', CP_PLUGIN_URL . 'assets/css/admin-fw.css', array(), CP_VERSION . '.' . filemtime( CP_PLUGIN_DIR . 'assets/css/admin-fw.css' ) );
        wp_enqueue_style( 'cp-admin-css', CP_PLUGIN_URL . 'assets/css/admin.css', array( 'nau-admin-fw' ), CP_VERSION . '.' . filemtime( CP_PLUGIN_DIR . 'assets/css/admin.css' ) );
        wp_enqueue_script( 'cp-admin-js', CP_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), CP_VERSION . '.' . filemtime( CP_PLUGIN_DIR . 'assets/js/admin.js' ), true );

        $js_data = array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'cp_nonce' ),
        );

        if ( $hook === 'carry-pod_page_carry-pod-settings' || $hook === 'carry-pod-1_page_carry-pod-settings' ) {
            $js_data['wranglerInfo'] = $this->detect_wrangler();
            $logger = CP_Logger::get_instance();
            $js_data['isRunning'] = $logger->is_running();
        }

        if ( $hook === 'toplevel_page_carry-pod' || $hook === 'toplevel_page_carry-pod-1' ) {
            $settings_manager = CP_Settings::get_instance();
            $validation = $settings_manager->validate_settings( $settings_manager->get_settings() );
            if ( is_wp_error( $validation ) ) {
                $js_data['settingsError'] = true;
            }
        }

        wp_localize_script( 'cp-admin-js', 'sgeData', $js_data );
    }

    public function show_notices(): void {
        if ( get_transient( 'cp_plugin_conflict_warning' ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>WP2staticまたはSimply Staticと競合する可能性があります。</p></div>';
            delete_transient( 'cp_plugin_conflict_warning' );
        }

        if ( get_transient( 'cp_git_warning' ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p>Gitコマンドが見つかりません。ローカルGit経由でのGitHub出力を使用する場合はGitをインストールしてください。</p></div>';
            delete_transient( 'cp_git_warning' );
        }
    }

    public function render_execute_page(): void {
        if ( ! current_user_can( 'cp_execute' ) ) {
            wp_die( '静的化実行の権限がありません。' );
        }

        $settings_manager = CP_Settings::get_instance();
        $settings = $settings_manager->get_settings();
        $settings_validation = $settings_manager->validate_settings( $settings );
        $settings_error = is_wp_error( $settings_validation ) ? $settings_validation->get_error_message() : '';

        if ( empty( $settings['commit_message'] ) ) {
            $settings['commit_message'] = 'update:' . current_time( 'Ymd_His' );
        }

        $logger = CP_Logger::get_instance();
        $is_running = $logger->is_running();

        if ( ! $is_running ) {
            $logger->clear_progress();
        }

        if ( isset( $_GET['debugmode'] ) ) {
            $debug_param = sanitize_text_field( wp_unslash( $_GET['debugmode'] ) );
            if ( $debug_param === 'on' ) {
                $logger->enable_debug_mode();
            } elseif ( $debug_param === 'off' ) {
                $logger->disable_debug_mode();
            }
        }
        $is_debug_mode = $logger->is_debug_mode();

        $is_beta_mode = $settings_manager->is_beta_mode_enabled();

        ?>
        <div class="wrap nau-admin-wrap">
            <h1>静的化の実行</h1>

            <?php
            $error_notification = get_option( 'cp_error_notification', false );
            if ( $error_notification && ! empty( $error_notification['count'] ) ) {
                $count = intval( $error_notification['count'] );
                echo '<div class="notice notice-error is-dismissible cp-error-notification">';
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
                <p><strong>ベータモード</strong> - プレリリース版のアップデートが有効です。無効にするには <code>&cp_beta=off</code> を追加してください。</p>
            </div>
            <?php endif; ?>

            <?php $this->check_mati_compatibility(); ?>
            <?php $this->check_screw_compatibility(); ?>

            <?php if ( $settings_error && ! $is_running ) : ?>
            <div class="notice notice-warning">
                <p>初期設定が完了していません。<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=carry-pod-settings' ) ); ?>">設定ページ</a></strong>で<strong>出力先</strong>と<strong>サイトURL</strong>を設定してください。</p>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $settings['auto_generate'] ) ) : ?>
            <div class="notice notice-success">
                <p><span class="dashicons dashicons-yes-alt" style="color: #00a32a; margin-right: 4px;"></span>静的化の自動実行が有効です。</p>
            </div>
            <?php endif; ?>

            <div class="cp-dynamic-sections">
                <div class="cp-progress-section">
                    <h3>進捗状況</h3>
                    <div class="cp-progress-container">
                        <div class="cp-progress-header">
                            <div class="cp-progress-bar-wrapper">
                                <div id="cp-progress-bar" class="cp-progress-bar" style="width: 0%"></div>
                            </div>
                            <span id="cp-progress-percentage" class="cp-progress-percentage">0%</span>
                        </div>
                        <div id="cp-progress-status" class="cp-progress-status">待機中...</div>
                    </div>
                </div>

                <?php
                $git_enabled = ! empty( $settings['github_enabled'] ) || ! empty( $settings['git_local_enabled'] ) || ! empty( $settings['gitlab_enabled'] );
                $show_commit = $git_enabled && empty( $settings['auto_generate'] );
                ?>
                <div class="cp-commit-section <?php echo $show_commit ? 'active' : ''; ?>">
                    <h3>
                        コミットメッセージ
                        <?php echo $this->render_tooltip( 'デフォルト: update:YYYYMMDD_HHMMSS' ); ?>
                    </h3>
                    <div class="cp-section-content">
                        <div class="nau-form-group">
                            <div class="cp-commit-container">
                                <input type="text" id="cp-commit-message" class="regular-text" value="<?php echo esc_attr( ! empty( $settings['commit_message'] ) ? $settings['commit_message'] : 'update:' . current_time( 'Ymd_His' ) ); ?>" placeholder="コミットメッセージを入力">
                                <button type="button" id="cp-reset-commit-message" class="button" <?php echo $is_running ? 'disabled' : ''; ?>>リセット</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="cp-execute-section">
                    <button type="button" id="cp-execute-button" class="button button-primary" <?php echo ( $is_running || $settings_error ) ? 'disabled' : ''; ?>>
                        <?php echo $is_running ? '静的化中...' : '静的化を実行'; ?>
                    </button>
                    <button type="button" id="cp-cancel-button" class="button button-caution" <?php echo ! $is_running ? 'disabled' : ''; ?>>
                        実行中止
                    </button>
                    <button type="button" id="cp-download-log" class="button" <?php echo $is_running ? 'disabled' : ''; ?>>最新のログをダウンロード</button>
                </div>
            </div>

            <div class="nau-version-info">
                Carry Pod <a href="https://github.com/villyoshioka/CarryPod/releases/tag/v<?php echo esc_attr( CP_VERSION ); ?>" target="_blank" rel="noopener noreferrer">v<?php echo esc_html( CP_VERSION ); ?></a>
            </div>
        </div>
        <?php
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'cp_manage_settings' ) ) {
            wp_die( '設定変更の権限がありません。' );
        }

        $settings_manager = CP_Settings::get_instance();
        $settings = $settings_manager->get_settings();

        if ( empty( $settings['commit_message'] ) ) {
            $settings['commit_message'] = 'update:' . current_time( 'Ymd_His' );
        }

        $logger = CP_Logger::get_instance();
        $is_running = $logger->is_running();

        if ( isset( $_GET['debugmode'] ) ) {
            $debug_param = sanitize_text_field( wp_unslash( $_GET['debugmode'] ) );
            if ( $debug_param === 'on' ) {
                $logger->enable_debug_mode();
            } elseif ( $debug_param === 'off' ) {
                $logger->disable_debug_mode();
            }
        }
        $is_debug_mode = $logger->is_debug_mode();
        if ( isset( $_GET['cp_test_v2'] ) ) {
            $test_param = sanitize_text_field( wp_unslash( $_GET['cp_test_v2'] ) );
            if ( $test_param === 'on' ) {
                set_transient( 'cp_test_v2_mode', true, HOUR_IN_SECONDS );
            } elseif ( $test_param === 'off' ) {
                delete_transient( 'cp_test_v2_mode' );
            }
        }

        $beta_message = '';
        if ( isset( $_GET['cp_beta'] ) ) {
            $beta_param = sanitize_text_field( wp_unslash( $_GET['cp_beta'] ) );
            if ( $beta_param === 'on' ) {
                if ( $settings_manager->is_beta_mode_enabled() ) {
                    // 既に有効
                } elseif ( isset( $_POST['cp_beta_password'] ) && isset( $_POST['cp_beta_nonce'] ) ) {
                    if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cp_beta_nonce'] ) ), 'cp_beta_auth' ) ) {
                        $password = sanitize_text_field( wp_unslash( $_POST['cp_beta_password'] ) );
                        $result = $settings_manager->enable_beta_mode( $password );
                        if ( is_wp_error( $result ) ) {
                            $beta_message = 'rate_limit';
                        } elseif ( $result === true ) {
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
        <div class="wrap nau-admin-wrap">
            <h1>CarryPod 設定</h1>

            <?php if ( $is_debug_mode ) : ?>
            <div class="notice notice-warning">
                <p><strong>デバッグモード</strong> - 詳細なログが出力されます。無効にするには <code>&debugmode=off</code> を追加してください。</p>
            </div>
            <?php endif; ?>

            <?php if ( $is_beta_mode ) : ?>
            <div class="notice notice-info">
                <p><strong>ベータモード</strong> - プレリリース版のアップデートが有効です。無効にするには <code>&cp_beta=off</code> を追加してください。</p>
            </div>
            <?php endif; ?>

            <?php $this->check_mati_compatibility(); ?>
            <?php $this->check_screw_compatibility(); ?>

            <?php if ( $beta_message === 'need_password' ) : ?>
            <div class="notice notice-warning">
                <p><strong>ベータモード認証</strong></p>
                <form method="post" style="margin: 10px 0;">
                    <?php wp_nonce_field( 'cp_beta_auth', 'cp_beta_nonce' ); ?>
                    <input type="password" name="cp_beta_password" placeholder="パスワードを入力" style="width: 200px;" />
                    <input type="submit" class="button" value="認証" />
                </form>
            </div>
            <?php elseif ( $beta_message === 'rate_limit' ) : ?>
            <div class="notice notice-error">
                <p>ログイン試行回数が超過しました。10分後に再試行してください。</p>
            </div>
            <?php elseif ( $beta_message === 'wrong_password' ) : ?>
            <div class="notice notice-error">
                <p>パスワードが正しくありません。</p>
            </div>
            <div class="notice notice-warning">
                <p><strong>ベータモード認証</strong></p>
                <form method="post" style="margin: 10px 0;">
                    <?php wp_nonce_field( 'cp_beta_auth', 'cp_beta_nonce' ); ?>
                    <input type="password" name="cp_beta_password" placeholder="パスワードを入力" style="width: 200px;" />
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

            <form id="cp-settings-form" class="nau-settings-form">
                <?php wp_nonce_field( 'cp_save_settings', 'cp_settings_nonce' ); ?>

                    <div class="nau-accordion-section" data-section="output-destinations">
                        <button type="button" class="nau-accordion-header"
                                id="header-output-destinations"
                                aria-expanded="true"
                                aria-controls="accordion-output-destinations">
                            <span class="nau-accordion-title">出力先設定</span>
                            <span class="nau-accordion-icon" aria-hidden="true"></span>
                        </button>
                        <div id="accordion-output-destinations"
                             class="nau-accordion-content"
                             role="region"
                             aria-labelledby="header-output-destinations"
                             aria-hidden="false">

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" id="cp-cloudflare-enabled" name="cloudflare_enabled" value="1" <?php checked( ! empty( $settings['cloudflare_enabled'] ) ); ?>>
                            Cloudflare Workersに出力
                        </label>
                    </div>

                    <div id="cp-cloudflare-settings" class="nau-subsection" <?php echo empty( $settings['cloudflare_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="nau-form-group">
                            <label for="cp-cloudflare-use-wrangler">
                                Wrangler
                                <?php echo $this->render_tooltip( 'Cloudflare公式CLIでデプロイします。レスポンスヘッダー変換ルールの設定が不要になります。<br>必要環境: Node.js + Wrangler CLI（v4以上）' ); ?>
                            </label>
                            <select id="cp-cloudflare-use-wrangler" name="cloudflare_use_wrangler">
                                <option value="0" <?php selected( empty( $settings['cloudflare_use_wrangler'] ) ); ?>>使用しない</option>
                                <option value="1" <?php selected( ! empty( $settings['cloudflare_use_wrangler'] ) ); ?>>使用する</option>
                            </select>
                            <div id="cp-wrangler-guide"></div>
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-cloudflare-api-token">Cloudflare API Token <span class="required">*</span></label>
                            <?php
                            $has_cf_token = ! empty( $settings['cloudflare_api_token'] );
                            $cf_placeholder = $has_cf_token ? '設定済み（変更する場合は新しいトークンを入力）' : 'APIトークンを入力してください';
                            ?>
                            <input type="password" id="cp-cloudflare-api-token" name="cloudflare_api_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $cf_placeholder ); ?>">
                            <?php if ( $has_cf_token ) : ?>
                                <span class="cp-token-status cp-token-set">✓ トークン設定済み</span>
                            <?php endif; ?>
                            <p class="description">
                                <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer">API Tokenを作成</a><br>
                                必要権限: Account > Workers Scripts > Edit
                            </p>
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-cloudflare-account-id">
                                Account ID <span class="required">*</span>
                                <?php echo $this->render_tooltip( 'Cloudflareダッシュボード > Workers & Pages > サイドバーで確認できます' ); ?>
                            </label>
                            <input type="text" id="cp-cloudflare-account-id" name="cloudflare_account_id" class="regular-text" value="<?php echo esc_attr( $settings['cloudflare_account_id'] ?? '' ); ?>" placeholder="例: 1234567890abcdef1234567890abcdef">
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-cloudflare-script-name">
                                Worker名 <span class="required">*</span>
                                <?php echo $this->render_tooltip( '存在しない場合は自動作成されます。使用可能文字: 英数字、ハイフン、アンダースコア' ); ?>
                            </label>
                            <input type="text" id="cp-cloudflare-script-name" name="cloudflare_script_name" class="regular-text" value="<?php echo esc_attr( $settings['cloudflare_script_name'] ?? '' ); ?>" placeholder="例: my-static-site">
                        </div>

                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" id="cp-netlify-enabled" name="netlify_enabled" value="1" <?php checked( ! empty( $settings['netlify_enabled'] ) ); ?>>
                            Netlifyに出力
                        </label>
                    </div>

                    <div id="cp-netlify-settings" class="nau-subsection" <?php echo empty( $settings['netlify_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="nau-form-group">
                            <label for="cp-netlify-api-token">Netlify Personal Access Token <span class="required">*</span></label>
                            <?php
                            $has_netlify_token = ! empty( $settings['netlify_api_token'] );
                            $netlify_placeholder = $has_netlify_token ? '設定済み（変更する場合は新しいトークンを入力）' : 'APIトークンを入力してください';
                            ?>
                            <input type="password" id="cp-netlify-api-token" name="netlify_api_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $netlify_placeholder ); ?>">
                            <?php if ( $has_netlify_token ) : ?>
                                <span class="cp-token-status cp-token-set">✓ トークン設定済み</span>
                            <?php endif; ?>
                            <p class="description">
                                <a href="https://app.netlify.com/user/applications#personal-access-tokens" target="_blank" rel="noopener noreferrer">Personal Access Tokenを作成</a>
                            </p>
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-netlify-site-id">
                                Netlify Project ID <span class="required">*</span>
                                <?php echo $this->render_tooltip( 'Site configuration → General → Site detailsで確認できます' ); ?>
                            </label>
                            <input type="text" id="cp-netlify-site-id" name="netlify_site_id" class="regular-text" value="<?php echo esc_attr( $settings['netlify_site_id'] ?? '' ); ?>" placeholder="例: 12345678-1234-1234-1234-123456789012">
                        </div>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" id="cp-github-enabled" name="github_enabled" value="1" <?php checked( ! empty( $settings['github_enabled'] ) ); ?>>
                            GitHubに出力
                        </label>
                    </div>

                    <div id="cp-github-settings" class="nau-subsection" <?php echo empty( $settings['github_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="nau-form-group">
                            <label for="cp-github-token">GitHub Personal Access Token <span class="required">*</span></label>
                            <?php
                            $has_token = ! empty( $settings['github_token'] );
                            $placeholder = $has_token ? '設定済み（変更する場合は新しいトークンを入力）' : 'トークンを入力してください';
                            ?>
                            <input type="password" id="cp-github-token" name="github_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>">
                            <?php if ( $has_token ) : ?>
                                <span class="cp-token-status cp-token-set">✓ トークン設定済み</span>
                            <?php endif; ?>
                            <p class="description">
                                <a href="https://github.com/settings/tokens/new" target="_blank" rel="noopener noreferrer">Personal Access Tokenを作成</a><br>
                                必要権限: repo
                            </p>
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-github-repo">
                                リポジトリ名 <span class="required">*</span>
                                <?php echo $this->render_tooltip( '形式: owner/repo（例: username/my-website）' ); ?>
                            </label>
                            <input type="text" id="cp-github-repo" name="github_repo" class="regular-text" value="<?php echo esc_attr( $settings['github_repo'] ?? '' ); ?>" placeholder="owner/repo">
                        </div>

                        <div class="nau-form-group cp-branch-settings">
                            <label>ブランチ設定</label>
                            <div class="cp-branch-option">
                                <label class="cp-radio-label">
                                    <input type="radio" name="github_branch_mode" value="existing" <?php checked( $settings['github_branch_mode'] ?? 'existing', 'existing' ); ?>>
                                    既存ブランチを使用
                                </label>
                                <div class="cp-branch-input">
                                    <input type="text" id="cp-github-existing-branch" name="github_existing_branch" class="regular-text" value="<?php echo esc_attr( $settings['github_existing_branch'] ?? '' ); ?>" placeholder="main" <?php echo ( $settings['github_branch_mode'] ?? 'existing' ) !== 'existing' ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                            <div class="cp-branch-option" style="margin-top: 10px;">
                                <label class="cp-radio-label">
                                    <input type="radio" name="github_branch_mode" value="new" <?php checked( $settings['github_branch_mode'] ?? 'existing', 'new' ); ?>>
                                    新規ブランチを作成
                                </label>
                                <div class="cp-branch-input cp-branch-nested">
                                    <span class="cp-field-label">分岐元ブランチ名</span>
                                    <input type="text" id="cp-github-base-branch" name="github_base_branch" class="regular-text" value="<?php echo esc_attr( $settings['github_base_branch'] ?? '' ); ?>" placeholder="main" <?php echo ( $settings['github_branch_mode'] ?? 'existing' ) !== 'new' ? 'disabled' : ''; ?>>
                                    <span class="cp-field-label">新規ブランチ名</span>
                                    <input type="text" id="cp-github-new-branch" name="github_new_branch" class="regular-text" value="<?php echo esc_attr( $settings['github_new_branch'] ?? '' ); ?>" placeholder="static-site" <?php echo ( $settings['github_branch_mode'] ?? 'existing' ) !== 'new' ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" id="cp-gitlab-enabled" name="gitlab_enabled" value="1" <?php checked( ! empty( $settings['gitlab_enabled'] ) ); ?>>
                            GitLabに出力
                        </label>
                    </div>

                    <div id="cp-gitlab-settings" class="nau-subsection" <?php echo empty( $settings['gitlab_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="nau-form-group">
                            <label for="cp-gitlab-token">GitLab Personal Access Token <span class="required">*</span></label>
                            <?php
                            $has_gl_token = ! empty( $settings['gitlab_token'] );
                            $gl_placeholder = $has_gl_token ? '設定済み（変更する場合は新しいトークンを入力）' : 'トークンを入力してください';
                            ?>
                            <input type="password" id="cp-gitlab-token" name="gitlab_token" class="regular-text" value="" placeholder="<?php echo esc_attr( $gl_placeholder ); ?>">
                            <?php if ( $has_gl_token ) : ?>
                                <span class="cp-token-status cp-token-set">✓ トークン設定済み</span>
                            <?php endif; ?>
                            <p class="description">
                                <a href="https://gitlab.com/-/user_settings/personal_access_tokens" target="_blank" rel="noopener noreferrer">Personal Access Tokenを作成</a><br>
                                必要権限: api または write_repository
                            </p>
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-gitlab-project">
                                プロジェクトパス <span class="required">*</span>
                                <?php echo $this->render_tooltip( '形式: username/project（例: myname/my-website）' ); ?>
                            </label>
                            <input type="text" id="cp-gitlab-project" name="gitlab_project" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_project'] ?? '' ); ?>" placeholder="username/project">
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-gitlab-api-url">
                                GitLab API URL
                                <?php echo $this->render_tooltip( 'セルフホスト環境の場合のみ変更してください' ); ?>
                            </label>
                            <input type="text" id="cp-gitlab-api-url" name="gitlab_api_url" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_api_url'] ?? 'https://gitlab.com/api/v4' ); ?>" placeholder="https://gitlab.com/api/v4">
                        </div>

                        <div class="nau-form-group cp-branch-settings">
                            <label>ブランチ設定</label>
                            <div class="cp-branch-option">
                                <label class="cp-radio-label">
                                    <input type="radio" name="gitlab_branch_mode" value="existing" <?php checked( $settings['gitlab_branch_mode'] ?? 'existing', 'existing' ); ?>>
                                    既存ブランチを使用
                                </label>
                                <div class="cp-branch-input">
                                    <input type="text" id="cp-gitlab-existing-branch" name="gitlab_existing_branch" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_existing_branch'] ?? '' ); ?>" placeholder="main" <?php echo ( $settings['gitlab_branch_mode'] ?? 'existing' ) !== 'existing' ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                            <div class="cp-branch-option" style="margin-top: 10px;">
                                <label class="cp-radio-label">
                                    <input type="radio" name="gitlab_branch_mode" value="new" <?php checked( $settings['gitlab_branch_mode'] ?? 'existing', 'new' ); ?>>
                                    新規ブランチを作成
                                </label>
                                <div class="cp-branch-input cp-branch-nested">
                                    <span class="cp-field-label">分岐元ブランチ名</span>
                                    <input type="text" id="cp-gitlab-base-branch" name="gitlab_base_branch" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_base_branch'] ?? '' ); ?>" placeholder="main" <?php echo ( $settings['gitlab_branch_mode'] ?? 'existing' ) !== 'new' ? 'disabled' : ''; ?>>
                                    <span class="cp-field-label">新規ブランチ名</span>
                                    <input type="text" id="cp-gitlab-new-branch" name="gitlab_new_branch" class="regular-text" value="<?php echo esc_attr( $settings['gitlab_new_branch'] ?? '' ); ?>" placeholder="static-site" <?php echo ( $settings['gitlab_branch_mode'] ?? 'existing' ) !== 'new' ? 'disabled' : ''; ?>>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" id="cp-git-local-enabled" name="git_local_enabled" value="1" <?php checked( ! empty( $settings['git_local_enabled'] ) ); ?>>
                            ローカルGitに出力
                        </label>
                    </div>

                    <div id="cp-git-local-settings" class="nau-subsection" <?php echo empty( $settings['git_local_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="nau-form-group">
                            <label for="cp-git-local-work-dir">
                                Git作業ディレクトリ <span class="required">*</span>
                                <?php echo $this->render_tooltip( '絶対パスで指定してください（例: /home/username/my-repo）' ); ?>
                            </label>
                            <input type="text" id="cp-git-local-work-dir" name="git_local_work_dir" class="regular-text" value="<?php echo esc_attr( $settings['git_local_work_dir'] ?? '' ); ?>" placeholder="/path/to/git/repo">
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-git-local-branch">
                                ブランチ名 <span class="required">*</span>
                                <?php echo $this->render_tooltip( '静的ファイルをコミットするブランチ名を指定（例: main、master、gh-pages など）' ); ?>
                            </label>
                            <input type="text" id="cp-git-local-branch" name="git_local_branch" class="regular-text" value="<?php echo esc_attr( $settings['git_local_branch'] ?? 'main' ); ?>" placeholder="main">
                        </div>

                        <div class="nau-form-group">
                            <label for="cp-git-local-remote-url">
                                リモートリポジトリURL
                                <?php echo $this->render_tooltip( '空欄の場合はリモートにプッシュしません' ); ?>
                            </label>
                            <input type="text" id="cp-git-local-remote-url" name="git_local_remote_url" class="regular-text" value="<?php echo esc_attr( $settings['git_local_remote_url'] ?? '' ); ?>" placeholder="https://git.example.com/user/repo.git">
                        </div>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" id="cp-local-enabled" name="local_enabled" value="1" <?php checked( ! empty( $settings['local_enabled'] ) ); ?>>
                            ローカルディレクトリに出力
                        </label>
                    </div>

                    <div id="cp-local-settings" class="nau-subsection" <?php echo empty( $settings['local_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="nau-form-group">
                            <label for="cp-local-output-path">
                                静的ファイル出力先パス <span class="required">*</span>
                                <?php echo $this->render_tooltip( '絶対パスで指定してください' ); ?>
                            </label>
                            <input type="text" id="cp-local-output-path" name="local_output_path" class="regular-text" value="<?php echo esc_attr( $settings['local_output_path'] ?? '' ); ?>" placeholder="<?php echo esc_attr( ( PHP_OS === 'WINNT' ? 'C:/output' : '/Users/username/output' ) ); ?>">
                        </div>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" id="cp-zip-enabled" name="zip_enabled" value="1" <?php checked( ! empty( $settings['zip_enabled'] ) ); ?>>
                            ZIPファイルとして出力
                            <?php echo $this->render_tooltip( 'ファイル名は {サイト名}-YYYYMMDD_HHMMSS.zip になります' ); ?>
                        </label>
                    </div>

                    <div id="cp-zip-settings" class="nau-subsection" <?php echo empty( $settings['zip_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <div class="nau-form-group">
                            <label for="cp-zip-mode">
                                出力方法
                            </label>
                            <select id="cp-zip-mode" name="zip_mode">
                                <option value="download" <?php selected( ( $settings['zip_mode'] ?? 'download' ), 'download' ); ?>>ダウンロード</option>
                                <option value="local" <?php selected( ( $settings['zip_mode'] ?? 'download' ), 'local' ); ?>>ローカル出力</option>
                            </select>
                        </div>
                        <div class="nau-form-group" id="cp-zip-local-settings" <?php echo ( $settings['zip_mode'] ?? 'download' ) !== 'local' ? 'style="display:none;"' : ''; ?>>
                            <label for="cp-zip-output-path">
                                出力先パス <span class="required">*</span>
                                <?php echo $this->render_tooltip( '絶対パスで指定してください' ); ?>
                            </label>
                            <input type="text" id="cp-zip-output-path" name="zip_output_path" class="regular-text" value="<?php echo esc_attr( $settings['zip_output_path'] ?? '' ); ?>">
                        </div>
                    </div>

                    <div class="nau-form-group">
                        <label for="cp-url-mode">
                            URL形式
                            <?php echo $this->render_tooltip( '相対パス: どのドメインでも動作します（推奨）<br>絶対パス: 特定のドメインに固定します' ); ?>
                        </label>
                        <select id="cp-url-mode" name="url_mode" class="regular-text">
                            <option value="relative" <?php selected( $settings['url_mode'] ?? 'relative', 'relative' ); ?>>相対パス</option>
                            <option value="absolute" <?php selected( $settings['url_mode'] ?? 'relative', 'absolute' ); ?>>絶対パス</option>
                        </select>
                    </div>

                    <div class="nau-form-group cp-base-url-field">
                        <label for="cp-base-url">
                            サイトURL <span class="required">*</span>
                            <?php echo $this->render_tooltip( 'サイトマップやrobots.txtで使用されます。末尾の / は不要です' ); ?>
                        </label>
                        <input type="url" id="cp-base-url" name="base_url" class="regular-text" value="<?php echo esc_attr( $settings['base_url'] ?? '' ); ?>" placeholder="https://example.com">
                    </div>

                        </div><!-- .nau-accordion-content -->
                    </div><!-- .nau-accordion-section -->

                    <div class="nau-accordion-section" data-section="file-settings">
                        <button type="button" class="nau-accordion-header"
                                id="header-file-settings"
                                aria-expanded="false"
                                aria-controls="accordion-file-settings">
                            <span class="nau-accordion-title">追加/除外ファイル設定</span>
                            <span class="nau-accordion-icon" aria-hidden="true"></span>
                        </button>
                        <div id="accordion-file-settings"
                             class="nau-accordion-content"
                             role="region"
                             aria-labelledby="header-file-settings"
                             aria-hidden="true">

                    <div class="nau-form-group">
                        <label for="cp-include-paths">
                            追加したいファイル/フォルダのパス指定
                            <?php echo $this->render_tooltip( 'WordPress外のファイルを追加する場合に絶対パスで指定します。1行につき1パスです' ); ?>
                        </label>
                        <textarea id="cp-include-paths" name="include_paths" class="large-text" rows="5"><?php echo esc_textarea( $settings['include_paths'] ?? '' ); ?></textarea>
                    </div>

                    <div class="nau-form-group">
                        <label for="cp-exclude-patterns">
                            除外したいファイル/フォルダのパス指定
                            <?php echo $this->render_tooltip( 'ワイルドカード（*）が使えます。1行につき1パスです。<br>未参照ファイルは自動で除外されます' ); ?>
                        </label>
                        <textarea id="cp-exclude-patterns" name="exclude_patterns" class="large-text" rows="5"><?php echo esc_textarea( $settings['exclude_patterns'] ?? '' ); ?></textarea>
                    </div>

                        </div><!-- .nau-accordion-content -->
                    </div><!-- .nau-accordion-section -->

                    <div class="nau-accordion-section" data-section="output-settings">
                        <button type="button" class="nau-accordion-header"
                                id="header-output-settings"
                                aria-expanded="true"
                                aria-controls="accordion-output-settings">
                            <span class="nau-accordion-title">コンテンツ設定</span>
                            <span class="nau-accordion-icon" aria-hidden="true"></span>
                        </button>
                        <div id="accordion-output-settings"
                             class="nau-accordion-content"
                             role="region"
                             aria-labelledby="header-output-settings"
                             aria-hidden="false">

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="enable_tag_archive" value="1" <?php checked( $settings['enable_tag_archive'] ?? false ); ?>>
                            タグアーカイブを出力
                            <?php echo $this->render_tooltip( 'タグごとの記事一覧ページです（例: /tag/wordpress/）' ); ?>
                        </label>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="enable_date_archive" value="1" <?php checked( $settings['enable_date_archive'] ?? false ); ?>>
                            日付アーカイブを出力
                            <?php echo $this->render_tooltip( '年月日ごとの記事一覧ページです（例: /2024/12/）' ); ?>
                        </label>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="enable_author_archive" value="1" <?php checked( $settings['enable_author_archive'] ?? false ); ?>>
                            著者アーカイブを出力
                            <?php echo $this->render_tooltip( '著者ごとの記事一覧ページです（例: /author/john/）' ); ?>
                        </label>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="enable_post_format_archive" value="1" <?php checked( $settings['enable_post_format_archive'] ?? false ); ?>>
                            投稿フォーマットアーカイブを出力
                            <?php echo $this->render_tooltip( '投稿タイプごとの一覧ページです。通常のブログでは使いません' ); ?>
                        </label>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="enable_sitemap" value="1" <?php checked( $settings['enable_sitemap'] ?? true ); ?>>
                            サイトマップを出力
                            <?php echo $this->render_tooltip( '検索エンジンがサイトを見つけやすくなります' ); ?>
                        </label>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="enable_robots_txt" value="1" <?php checked( $settings['enable_robots_txt'] ?? true ); ?>>
                            robots.txtを出力
                            <?php echo $this->render_tooltip( '検索エンジンのクロール制御ファイルです。AIクローラーをブロックする設定で生成されます' ); ?>
                        </label>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="enable_llms_txt" value="1" <?php checked( $settings['enable_llms_txt'] ?? true ); ?>>
                            llms.txtを出力
                            <?php echo $this->render_tooltip( 'LLM向けのアクセスポリシーファイルです。デフォルトではAI学習利用を禁止します' ); ?>
                        </label>
                    </div>

                    <?php
                    $mati_available = defined( 'MATI_VERSION' ) && class_exists( 'Mati_Settings' );
                    if ( $mati_available ) :
                    ?>
                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" id="cp-mati-headers" name="generate_mati_headers" value="1" <?php checked( $settings['generate_mati_headers'] ?? true ); ?>>
                            _headersファイルを自動生成する
                            <?php echo $this->render_tooltip( 'Matiのセキュリティヘッダーを静的サイトに適用します（Cloudflare Workers / Netlify対応）' ); ?>
                        </label>
                    </div>
                    <?php endif; ?>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="enable_rss" value="1" <?php checked( $settings['enable_rss'] ?? true ); ?>>
                            RSSフィードを出力
                            <?php echo $this->render_tooltip( '読者がRSSリーダーでサイトの更新を購読できるようになります' ); ?>
                        </label>
                    </div>

                        </div><!-- .nau-accordion-content -->
                    </div><!-- .nau-accordion-section -->

                    <?php if ( $mati_available ) : ?>
                    <div id="cp-cf-transform-guide" class="nau-accordion-section nau-accordion-section--warning" data-section="transform-guide" <?php echo empty( $settings['cloudflare_enabled'] ) ? 'style="display:none;"' : ''; ?>>
                        <button type="button" class="nau-accordion-header"
                                id="header-transform-guide"
                                aria-expanded="false"
                                aria-controls="accordion-transform-guide">
                            <span class="nau-accordion-title">レスポンス ヘッダー変換ルールの設定</span>
                            <span class="nau-accordion-icon" aria-hidden="true"></span>
                        </button>
                        <div id="accordion-transform-guide"
                             class="nau-accordion-content"
                             role="region"
                             aria-labelledby="header-transform-guide"
                             aria-hidden="true">
                            <div class="cp-guide-content">
                                <p>Cloudflareの管理画面で以下の<?php echo $this->has_mati_bluesky_did() ? '5' : '4'; ?>つのレスポンス ヘッダー変換ルールを設定してください。</p>

                                <h4>ルール1: セキュリティヘッダー</h4>
                                <ul>
                                    <li>条件: <strong>すべての受信リクエスト</strong></li>
                                    <li>操作: <strong>静的を追加</strong></li>
                                </ul>
                                <table class="cp-guide-headers-table">
                                    <thead><tr><th>ヘッダー名</th><th>値</th></tr></thead>
                                    <tbody>
                                        <tr><td><code>Content-Security-Policy</code></td><td><code><?php echo esc_html( $this->get_mati_frame_ancestors_value() ); ?></code></td></tr>
                                        <tr><td><code>X-Robots-Tag</code></td><td><code><?php echo esc_html( $this->get_mati_xrobots_value() ); ?></code></td></tr>
                                    </tbody>
                                </table>

                                <h4>ルール2: HTMLコンテンツタイプ</h4>
                                <ul>
                                    <li>条件: <strong>カスタムフィルタ式</strong></li>
                                    <li>式: <code>not http.request.uri.path contains "."</code></li>
                                    <li>操作: <strong>静的を追加</strong></li>
                                </ul>
                                <table class="cp-guide-headers-table">
                                    <thead><tr><th>ヘッダー名</th><th>値</th></tr></thead>
                                    <tbody>
                                        <tr><td><code>Content-Type</code></td><td><code>text/html; charset=utf-8</code></td></tr>
                                    </tbody>
                                </table>

                                <h4>ルール3: CSSコンテンツタイプ</h4>
                                <ul>
                                    <li>条件: <strong>カスタムフィルタ式</strong></li>
                                    <li>式: <code>http.request.uri.path.extension eq "css"</code></li>
                                    <li>操作: <strong>静的を追加</strong></li>
                                </ul>
                                <table class="cp-guide-headers-table">
                                    <thead><tr><th>ヘッダー名</th><th>値</th></tr></thead>
                                    <tbody>
                                        <tr><td><code>Content-Type</code></td><td><code>text/css; charset=utf-8</code></td></tr>
                                    </tbody>
                                </table>

                                <h4>ルール4: JSコンテンツタイプ</h4>
                                <ul>
                                    <li>条件: <strong>カスタムフィルタ式</strong></li>
                                    <li>式: <code>http.request.uri.path.extension eq "js"</code></li>
                                    <li>操作: <strong>静的を追加</strong></li>
                                </ul>
                                <table class="cp-guide-headers-table">
                                    <thead><tr><th>ヘッダー名</th><th>値</th></tr></thead>
                                    <tbody>
                                        <tr><td><code>Content-Type</code></td><td><code>application/javascript; charset=utf-8</code></td></tr>
                                    </tbody>
                                </table>

                                <?php if ( $this->has_mati_bluesky_did() ) : ?>
                                <h4>ルール5: Blueskyドメイン認証</h4>
                                <ul>
                                    <li>条件: <strong>カスタムフィルタ式</strong></li>
                                    <li>式: <code>http.request.uri.path eq "/.well-known/atproto-did"</code></li>
                                    <li>操作: <strong>静的を追加</strong></li>
                                </ul>
                                <table class="cp-guide-headers-table">
                                    <thead><tr><th>ヘッダー名</th><th>値</th></tr></thead>
                                    <tbody>
                                        <tr><td><code>Content-Type</code></td><td><code>text/plain; charset=utf-8</code></td></tr>
                                        <tr><td><code>Cache-Control</code></td><td><code>no-cache, no-store, must-revalidate</code></td></tr>
                                        <tr><td><code>Pragma</code></td><td><code>no-cache</code></td></tr>
                                        <tr><td><code>Expires</code></td><td><code>0</code></td></tr>
                                        <tr><td><code>Content-Disposition</code></td><td><code>inline</code></td></tr>
                                    </tbody>
                                </table>
                                <?php endif; ?>

                                <p class="description">※ Matiの設定を変更した場合は、ルール1のX-Robots-Tagの値も更新してください。</p>
                                <p class="description"><a href="https://developers.cloudflare.com/rules/transform/response-header-modification/" target="_blank" rel="noopener noreferrer">Cloudflare Transform Rules ドキュメント →</a></p>
                            </div><!-- .cp-guide-content -->
                        </div><!-- .nau-accordion-content -->
                    </div><!-- .nau-accordion-section (transform-guide) -->
                    <?php endif; ?>

                    <div class="nau-accordion-section" data-section="other-settings">
                        <button type="button" class="nau-accordion-header"
                                id="header-other-settings"
                                aria-expanded="false"
                                aria-controls="accordion-other-settings">
                            <span class="nau-accordion-title">その他の設定</span>
                            <span class="nau-accordion-icon" aria-hidden="true"></span>
                        </button>
                        <div id="accordion-other-settings"
                             class="nau-accordion-content"
                             role="region"
                             aria-labelledby="header-other-settings"
                             aria-hidden="true">

                    <div class="nau-form-group">
                        <label>
                            フォルダ名のカスタマイズ
                            <?php echo $this->render_tooltip( 'wp-includes や wp-content を別の名前に変更できます。空欄の場合は元の名前のまま生成されます' ); ?>
                        </label>
                        <div style="margin-bottom: 20px;">
                            <label for="cp-custom-wp-includes" style="display: block; margin-bottom: 4px;">wp-includes フォルダ名</label>
                            <input type="text" id="cp-custom-wp-includes" name="custom_wp_includes" class="regular-text" value="<?php echo esc_attr( $settings['custom_wp_includes'] ?? '' ); ?>" placeholder="wp-includes" maxlength="50">
                        </div>
                        <div>
                            <label for="cp-custom-wp-content" style="display: block; margin-bottom: 4px;">wp-content フォルダ名</label>
                            <input type="text" id="cp-custom-wp-content" name="custom_wp_content" class="regular-text" value="<?php echo esc_attr( $settings['custom_wp_content'] ?? '' ); ?>" placeholder="wp-content" maxlength="50">
                        </div>
                    </div>

                    <div class="nau-form-group">
                        <label for="cp-timeout">
                            タイムアウト時間（秒）
                            <?php echo $this->render_tooltip( 'デフォルト: 300秒です。ページ数が多い場合は長めに設定してください（60〜18000秒）' ); ?>
                        </label>
                        <input type="number" id="cp-timeout" name="timeout" class="small-text" value="<?php echo esc_attr( $settings['timeout'] ?? 300 ); ?>" min="60" max="18000">
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="minify_html" value="1" <?php checked( ! empty( $settings['minify_html'] ) ); ?>>
                            HTML圧縮の有効化
                            <?php echo $this->render_tooltip( '改行・スペース・コメントを削除してファイルサイズを削減します' ); ?>
                        </label>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="minify_css" value="1" <?php checked( ! empty( $settings['minify_css'] ) ); ?>>
                            インラインCSS圧縮の有効化
                            <?php echo $this->render_tooltip( 'インラインCSSから不要なスペース・コメントを削除します' ); ?>
                        </label>
                    </div>

                    <div class="nau-form-group">
                        <label>
                            <input type="checkbox" name="auto_generate" value="1" <?php checked( ! empty( $settings['auto_generate'] ) ); ?>>
                            自動で静的化を実行する
                            <?php echo $this->render_tooltip( '記事の公開・更新時に自動で静的化を実行します' ); ?>
                        </label>
                    </div>


                        </div><!-- .nau-accordion-content -->
                    </div><!-- .nau-accordion-section -->

                    <div class="nau-form-actions">
                        <button type="submit" class="button button-primary" id="cp-save-settings" <?php echo $is_running ? 'disabled' : ''; ?>>設定を保存</button>
                        <button type="button" class="button button-danger" id="cp-reset-settings" <?php echo $is_running ? 'disabled' : ''; ?>>設定をリセット</button>
                        <button type="button" class="button" id="cp-clear-cache" <?php echo $is_running ? 'disabled' : ''; ?>>キャッシュをクリア</button>
                        <button type="button" class="button" id="cp-clear-logs" <?php echo $is_running ? 'disabled' : ''; ?>>ログをクリア</button>
                        <button type="button" class="button" id="cp-export-settings">設定をエクスポート</button>
                        <button type="button" class="button" id="cp-import-settings" <?php echo $is_running ? 'disabled' : ''; ?>>設定をインポート</button>
                        <button type="button" class="button button-caution" id="cp-reset-scheduler">Scheduled Actionsをリセット</button>
                        <input type="file" id="cp-import-file" accept=".json" style="display:none;">
                    </div>
                </form>

                <div class="nau-version-info">
                    Carry Pod <a href="https://github.com/villyoshioka/CarryPod/releases/tag/v<?php echo esc_attr( CP_VERSION ); ?>" target="_blank" rel="noopener noreferrer">v<?php echo esc_html( CP_VERSION ); ?></a>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_execute_generation(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! $this->check_rate_limit( 'execute_generation', 3, 60 ) ) {
            wp_send_json_error( array( 'message' => 'リクエストが多すぎます。しばらく待ってから再試行してください。' ) );
        }

        if ( ! current_user_can( 'cp_execute' ) ) {
            wp_send_json_error( array( 'message' => '静的化実行の権限がありません。' ) );
        }

        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            wp_send_json_error( array( 'message' => 'Action Schedulerが読み込まれていません。プラグインを再度有効化してください。' ) );
        }

        $logger = CP_Logger::get_instance();

        $settings_instance = CP_Settings::get_instance();
        $settings = $settings_instance->get_settings();
        $validation = $settings_instance->validate_settings( $settings );
        if ( is_wp_error( $validation ) ) {
            wp_send_json_error( array( 'message' => $validation->get_error_message() ) );
        }

        if ( isset( $_POST['commit_message'] ) && ! empty( trim( $_POST['commit_message'] ) ) ) {
            $settings['commit_message'] = sanitize_text_field( $_POST['commit_message'] );
            update_option( 'cp_settings', $settings );
        }

        /** アトミックなロック取得で並行実行を防止 */
        $lock_key = 'cp_execution_lock';
        $lock_timeout = 3600;
        $lock_value = wp_generate_uuid4();

        $lock_acquired = add_option( $lock_key, array( 'value' => $lock_value, 'time' => time() ), '', 'no' );

        if ( ! $lock_acquired ) {
            $existing_lock = get_option( $lock_key );

            if ( is_array( $existing_lock ) && isset( $existing_lock['time'] ) ) {
                $lock_age = time() - $existing_lock['time'];

                if ( $lock_age < $lock_timeout ) {
                    wp_send_json_error( array( 'message' => '既に実行中です。しばらくお待ちください。' ) );
                }

                // 条件付き削除でアトミックにロック再取得
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
                    $lock_acquired = add_option( $lock_key, array( 'value' => $lock_value, 'time' => time() ), '', 'no' );
                }
            } else {
                delete_option( $lock_key );
                $lock_acquired = add_option( $lock_key, array( 'value' => $lock_value, 'time' => time() ), '', 'no' );
            }

            if ( ! $lock_acquired ) {
                wp_send_json_error( array( 'message' => '実行の開始に失敗しました。もう一度お試しください。' ) );
            }
        }

        as_unschedule_all_actions( 'cp_static_generation', array(), 'sge' );

        delete_transient( 'cp_manual_running' );
        delete_transient( 'cp_auto_running' );

        $logger->clear_progress();
        update_option( 'cp_logs', array() );
        $logger->add_log( '新しい実行を開始します...' );

        set_transient( 'cp_manual_running', true, 3600 );
        as_enqueue_async_action( 'cp_static_generation', array(), 'sge' );
        delete_option( $lock_key );

        $logger->update_progress( 0, 1, 'バックグラウンド処理を待機中...' );

        wp_send_json_success( array( 'message' => '静的化を開始しました。' ) );
    }

    public function ajax_get_logs(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $logger = CP_Logger::get_instance();
        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

        $logs = $logger->get_logs_from_offset( $offset );
        $is_running = $logger->is_running();

        wp_send_json_success( array(
            'logs' => $logs,
            'total_count' => $logger->get_log_count(),
            'is_running' => $is_running,
        ) );
    }

    public function ajax_get_progress(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $logger = CP_Logger::get_instance();
        $progress = $logger->get_progress();
        $is_running = $logger->is_running();

        $zip_download_available = ! $is_running && (bool) get_transient( 'cp_zip_download_path' );

        wp_send_json_success( array(
            'progress' => $progress,
            'is_running' => $is_running,
            'zip_download_available' => $zip_download_available,
        ) );
    }

    public function ajax_download_zip(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $zip_path = get_transient( 'cp_zip_download_path' );
        if ( ! $zip_path || ! file_exists( $zip_path ) ) {
            wp_send_json_error( array( 'message' => 'ダウンロード可能なZIPファイルが見つかりません。' ) );
        }

        // パスが carry-pod-tmp/ 内であることを検証
        $upload_dir   = wp_upload_dir();
        $expected_dir = trailingslashit( $upload_dir['basedir'] ) . 'carry-pod-tmp' . DIRECTORY_SEPARATOR;
        $real_path    = realpath( $zip_path );
        $real_dir     = realpath( dirname( $zip_path ) );
        if ( ! $real_path || ! $real_dir || ! str_starts_with( $real_path, rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
            wp_send_json_error( array( 'message' => '不正なファイルパスです。' ) );
        }

        $filename = basename( $zip_path );

        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $zip_path ) );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        set_time_limit( 0 );
        readfile( $zip_path );

        unlink( $zip_path );
        delete_transient( 'cp_zip_download_path' );

        exit;
    }

    public function ajax_is_running(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $logger = CP_Logger::get_instance();
        wp_send_json_success( array( 'is_running' => $logger->is_running() ) );
    }

    public function ajax_clear_logs(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        $logger = CP_Logger::get_instance();
        if ( $logger->is_running() ) {
            wp_send_json_error( array( 'message' => '静的化実行中はログをクリアできません。' ) );
        }

        update_option( 'cp_logs', array() );
        delete_option( 'cp_error_notification' );

        wp_send_json_success( array( 'message' => 'ログをクリアしました。' ) );
    }

    public function ajax_save_settings(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! $this->check_rate_limit( 'save_settings', 10, 60 ) ) {
            wp_send_json_error( array( 'message' => '設定の保存を連続で行いすぎています。1分間お待ちいただいてから再度お試しください。' ) );
        }

        if ( isset( $_POST['cp_settings_nonce'] ) && ! wp_verify_nonce( $_POST['cp_settings_nonce'], 'cp_save_settings' ) ) {
            wp_send_json_error( array( 'message' => 'セキュリティチェックに失敗しました。ページをリロード（F5キー）してから再度お試しください。' ) );
        }

        if ( ! current_user_can( 'cp_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定を変更する権限がありません。管理者権限を持つユーザーアカウントでログインしてください。' ) );
        }

        $allowed_fields = array(
            'github_enabled' => 'boolean',
            'git_local_enabled' => 'boolean',
            'local_enabled' => 'boolean',
            'zip_enabled' => 'boolean',
            'zip_mode' => 'text',
            'auto_generate' => 'boolean',
            'enable_tag_archive' => 'boolean',
            'enable_date_archive' => 'boolean',
            'enable_author_archive' => 'boolean',
            'enable_post_format_archive' => 'boolean',
            'enable_sitemap' => 'boolean',
            'enable_robots_txt' => 'boolean',
            'enable_llms_txt' => 'boolean',
            'generate_mati_headers' => 'boolean',
            'enable_rss' => 'boolean',
            'minify_html' => 'boolean',
            'minify_css' => 'boolean',
            'cloudflare_enabled' => 'boolean',
            'cloudflare_use_wrangler' => 'boolean_select',
            'gitlab_enabled' => 'boolean',
            'netlify_enabled' => 'boolean',
            'github_token' => 'text',
            'github_repo' => 'text',
            'github_branch_mode' => 'text',
            'github_existing_branch' => 'text',
            'github_new_branch' => 'text',
            'github_base_branch' => 'text',
            'git_local_work_dir' => 'text',
            'git_local_branch' => 'text',
            'git_local_remote_url' => 'text',
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
            'include_paths' => 'textarea',
            'exclude_patterns' => 'textarea',
            'base_url' => 'url',
            'custom_wp_includes' => 'folder_name',
            'custom_wp_content' => 'folder_name',
            'timeout' => 'integer',
        );

        $settings = array();
        foreach ( $allowed_fields as $field => $type ) {
            if ( $type === 'boolean' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? 1 : 0;
            } elseif ( $type === 'boolean_select' ) {
                $settings[ $field ] = ! empty( $_POST[ $field ] ) ? 1 : 0;
            } elseif ( $type === 'text' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
            } elseif ( $type === 'textarea' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '';
            } elseif ( $type === 'url' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? esc_url_raw( wp_unslash( $_POST[ $field ] ) ) : '';
            } elseif ( $type === 'folder_name' ) {
                $value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
                if ( empty( $value ) ) {
                    $settings[ $field ] = '';
                } else {
                    $reserved_names = array(
                        'wp-admin', 'wp-includes', 'wp-content',
                        'system', 'windows', 'winnt', 'etc', 'bin', 'usr', 'var', 'tmp',
                        'program files', 'programdata',
                    );
                    $value_lower = strtolower( $value );

                    if ( in_array( $value_lower, $reserved_names, true ) ) {
                        $settings[ $field ] = '';
                    } elseif ( preg_match( '/^[a-zA-Z0-9_-]{1,50}$/', $value ) && ! str_contains( $value, '..' ) ) {
                        $settings[ $field ] = $value;
                    } else {
                        $settings[ $field ] = '';
                    }
                }
            } elseif ( $type === 'integer' ) {
                $settings[ $field ] = isset( $_POST[ $field ] ) ? intval( $_POST[ $field ] ) : 0;
            }
        }

        if ( ! in_array( $settings['zip_mode'] ?? '', array( 'download', 'local' ), true ) ) {
            $settings['zip_mode'] = 'download';
        }

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
            $settings['timeout'] = 300;
        }
        if ( empty( $settings['gitlab_branch_mode'] ) ) {
            $settings['gitlab_branch_mode'] = 'existing';
        }
        if ( empty( $settings['gitlab_api_url'] ) ) {
            $settings['gitlab_api_url'] = 'https://gitlab.com/api/v4';
        }

        if ( ! empty( $settings['base_url'] ) ) {
            $settings['base_url'] = rtrim( $settings['base_url'], '/' );

            $parsed_url = wp_parse_url( $settings['base_url'] );
            if ( empty( $parsed_url['scheme'] ) || ! in_array( $parsed_url['scheme'], array( 'http', 'https' ), true ) ) {
                wp_send_json_error( array( 'message' => 'URLの形式が正しくありません。「https://example.com」のように、http:// または https:// から始まる完全なURLを入力してください。' ) );
            }
        }

        $settings_manager = CP_Settings::get_instance();
        $old_settings = $settings_manager->get_settings();

        $should_clear_cache = false;
        $cache_clear_fields = array( 'custom_wp_includes', 'custom_wp_content', 'url_mode', 'base_url', 'minify_html', 'minify_css' );

        foreach ( $cache_clear_fields as $field ) {
            $old_value = $old_settings[ $field ] ?? '';
            $new_value = $settings[ $field ] ?? '';

            if ( $old_value !== $new_value ) {
                $should_clear_cache = true;
                break;
            }
        }

        $result = $settings_manager->save_settings( $settings );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message'  => $result->get_error_message(),
                'messages' => $result->get_error_messages(),
            ) );
        }

        if ( $should_clear_cache ) {
            $cache = CP_Cache::get_instance();
            $cache->clear_all();
        }

        wp_send_json_success( array( 'message' => '設定を保存しました。' ) );
    }

    public function ajax_reset_settings(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        $settings_manager = CP_Settings::get_instance();
        $settings_manager->reset_settings();

        wp_send_json_success( array( 'message' => '設定をリセットしました。' ) );
    }

    public function ajax_export_settings(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        $settings_manager = CP_Settings::get_instance();
        $json = $settings_manager->export_settings();

        wp_send_json_success( array( 'data' => $json ) );
    }

    public function ajax_import_settings(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        if ( ! isset( $_POST['data'] ) ) {
            wp_send_json_error( array( 'message' => 'データが送信されていません。' ) );
        }

        $import_data = wp_unslash( $_POST['data'] );

        $settings_manager = CP_Settings::get_instance();
        $result = $settings_manager->import_settings( $import_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => '設定をインポートしました。トークンを再入力してください。' ) );
    }

    public function ajax_clear_cache(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        $cache = CP_Cache::get_instance();
        $stats = $cache->get_stats();
        $deleted = $cache->clear_all();

        wp_send_json_success( array(
            'message' => sprintf( '%d 個のキャッシュファイル（%s）を削除しました。', $deleted, $stats['size_formatted'] ),
            'deleted' => $deleted,
        ) );
    }

    public function ajax_download_log(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        try {
            $logger = CP_Logger::get_instance();
            $logs = $logger->get_logs();

            if ( empty( $logs ) ) {
                wp_send_json_error( array(
                    'message' => 'ログがありません。まず静的化を実行してください。',
                ) );
            }

            $first_log = reset( $logs );
            $timestamp = $first_log['timestamp'];

            $has_error = false;
            foreach ( $logs as $log_entry ) {
                if ( ! empty( $log_entry['is_error'] ) ) {
                    $has_error = true;
                    break;
                }
            }

            $settings_manager = CP_Settings::get_instance();
            $settings = $settings_manager->get_settings();

            $cache = CP_Cache::get_instance();
            $cache_stats = $cache->get_stats();

            $log_text = "=====================================\n";
            $log_text .= "Carry Pod - 生成ログ\n";
            $log_text .= "=====================================\n\n";

            $log_text .= "【基本情報】\n";
            $log_text .= "生成日時: " . $timestamp . "\n";
            $log_text .= "ステータス: " . ( $has_error ? 'エラー' : '成功' ) . "\n";
            $log_text .= "プラグインバージョン: " . CP_VERSION . "\n";
            $log_text .= "WordPress バージョン: " . get_bloginfo( 'version' ) . "\n";
            $log_text .= "PHP バージョン: " . PHP_VERSION . "\n";
            $log_text .= "\n";

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
                $log_text .= "    - Project ID: " . ( $settings['netlify_site_id'] ?? 'なし' ) . "\n";
            }
            $log_text .= "  - ZIP出力: " . ( ! empty( $settings['zip_enabled'] ) ? '有効' : '無効' ) . "\n";
            if ( ! empty( $settings['zip_enabled'] ) ) {
                $zip_mode_label = ( $settings['zip_mode'] ?? 'download' ) === 'local' ? 'ローカル保存' : 'ブラウザダウンロード';
                $log_text .= "    - 出力方法: " . $zip_mode_label . "\n";
            }
            $log_text .= "\n";

            $log_text .= "その他の設定:\n";
            $log_text .= "  - URL形式: " . ( $settings['url_mode'] === 'relative' ? '相対パス' : '絶対パス' ) . "\n";
            if ( ! empty( $settings['base_url'] ) ) {
                $log_text .= "  - URL: " . $settings['base_url'] . "\n";
            }
            $log_text .= "  - タイムアウト: " . ( $settings['timeout'] ?? 300 ) . " 秒\n";
            $log_text .= "  - 自動静的化: " . ( ! empty( $settings['auto_generate'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "  - キャッシュファイル数: " . $cache_stats['count'] . " 個\n";
            $log_text .= "  - キャッシュサイズ: " . $cache_stats['size_formatted'] . "\n";
            $log_text .= "  - HTML圧縮: " . ( ! empty( $settings['minify_html'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "  - インラインCSS圧縮: " . ( ! empty( $settings['minify_css'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "  - 出力対象:\n";
            $log_text .= "    - タグアーカイブ: " . ( ! empty( $settings['enable_tag_archive'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - 日付アーカイブ: " . ( ! empty( $settings['enable_date_archive'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - 著者アーカイブ: " . ( ! empty( $settings['enable_author_archive'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - サイトマップ: " . ( ! empty( $settings['enable_sitemap'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - robots.txt: " . ( ! empty( $settings['enable_robots_txt'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "    - llms.txt: " . ( ! empty( $settings['enable_llms_txt'] ) ? '有効' : '無効' ) . "\n";
            if ( defined( 'MATI_VERSION' ) && class_exists( 'Mati_Settings' ) ) {
                $headers_enabled = ! empty( $settings['generate_mati_headers'] );
                // Cloudflare Workersのみ有効で他の出力先がない場合は無効扱い
                if ( $headers_enabled && ! empty( $settings['cloudflare_enabled'] ) ) {
                    $other_destinations = array( 'github_enabled', 'gitlab_enabled', 'netlify_enabled', 'git_local_enabled', 'local_enabled', 'zip_enabled' );
                    $has_other          = false;
                    foreach ( $other_destinations as $key ) {
                        if ( ! empty( $settings[ $key ] ) ) {
                            $has_other = true;
                            break;
                        }
                    }
                    if ( ! $has_other ) {
                        $headers_enabled = false;
                    }
                }
                $log_text .= "    - _headers: " . ( $headers_enabled ? '有効' : '無効' ) . "\n";
            }
            $log_text .= "    - RSS: " . ( ! empty( $settings['enable_rss'] ) ? '有効' : '無効' ) . "\n";
            $log_text .= "\n";

            $log_text .= "【処理ログ】\n";
            $log_text .= "-------------------------------------\n";

            $message_count = count( $logs );
            $error_count = 0;
            $cache_hit_count = 0;

            foreach ( $logs as $index => $log_entry ) {
                $message = $log_entry['message'];
                $log_text .= sprintf( "[%d/%d] %s: %s\n", $index + 1, $message_count, $log_entry['timestamp'], $message );

                if ( ! empty( $log_entry['is_error'] ) || str_contains( $message, 'エラー' ) || str_contains( $message, '失敗' ) ) {
                    $error_count++;
                }
                if ( str_contains( $message, 'キャッシュを使用' ) ) {
                    $cache_hit_count++;
                }
            }

            $log_text .= "-------------------------------------\n\n";

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

            delete_option( 'cp_error_notification' );

            wp_send_json_success( array(
                'log' => $log_text,
                'filename' => 'cp-log-' . date( 'Ymd-His', strtotime( $timestamp ) ) . '.txt',
            ) );

        } catch ( Exception $e ) {
            error_log( 'SGE Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString() );

            wp_send_json_error( array(
                'message' => 'エラーが発生しました。詳細はサーバーログをご確認ください。',
            ) );
        }
    }

    public function ajax_cancel_generation(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_execute' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
        }

        try {
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'cp_static_generation', array(), 'sge' );
            }

            delete_transient( 'cp_manual_running' );
            delete_transient( 'cp_auto_running' );
            update_option( 'cp_logs', array() );
            delete_option( 'cp_progress' );

            wp_send_json_success( array( 'message' => '実行を中止しました。' ) );

        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => 'エラーが発生しました: ' . $e->getMessage() ) );
        }
    }

    public function ajax_reset_scheduler(): void {
        check_ajax_referer( 'cp_nonce', 'nonce' );

        if ( ! current_user_can( 'cp_manage_settings' ) ) {
            wp_send_json_error( array( 'message' => '設定変更の権限がありません。' ) );
        }

        try {
            global $wpdb;

            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'cp_static_generation', array(), 'sge' );
            }

            delete_transient( 'cp_manual_running' );
            delete_transient( 'cp_auto_running' );
            delete_option( 'cp_progress' );

            $tables = array(
                $wpdb->prefix . 'actionscheduler_actions',
                $wpdb->prefix . 'actionscheduler_claims',
                $wpdb->prefix . 'actionscheduler_groups',
                $wpdb->prefix . 'actionscheduler_logs',
            );

            $deleted_total = 0;

            foreach ( $tables as $table ) {
                $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

                if ( $table_exists ) {
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
                        $deleted = $this->safe_delete_records(
                            $table,
                            "DELETE FROM {$table} WHERE action_id IN (SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s)",
                            array( 'cp_static_generation' ),
                            60
                        );
                    }

                    if ( ! is_wp_error( $deleted ) ) {
                        $deleted_total += $deleted;
                    } else {
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

    public function ajax_check_error_notification(): void {
        $error_notification = get_option( 'cp_error_notification', false );
        $has_error = $error_notification && ! empty( $error_notification['count'] );

        wp_send_json_success( array(
            'has_error' => $has_error,
            'count' => $has_error ? intval( $error_notification['count'] ) : 0,
        ) );
    }

    public function get_wrangler_info(): array {
        return $this->detect_wrangler();
    }

    private function detect_wrangler(): array {
        $extended_path = $this->get_extended_path();
        $is_windows = PHP_OS_FAMILY === 'Windows';
        $separator = $is_windows ? ';' : ':';
        $dirs = explode( $separator, $extended_path );

        $executables = array( 'wrangler' );
        if ( $is_windows ) {
            $executables = array( 'wrangler.cmd', 'wrangler.exe', 'wrangler.ps1' );
        }

        $full_path = '';
        foreach ( $dirs as $dir ) {
            $dir = rtrim( $dir, '/\\' );
            if ( empty( $dir ) || ! is_dir( $dir ) ) {
                continue;
            }
            foreach ( $executables as $exe ) {
                $candidate = $dir . DIRECTORY_SEPARATOR . $exe;
                if ( ! is_file( $candidate ) ) {
                    continue;
                }
                if ( $is_windows || is_executable( $candidate ) ) {
                    $full_path = $candidate;
                    break 2;
                }
            }
        }

        if ( empty( $full_path ) ) {
            return array(
                'found'        => false,
                'path'         => '',
                'version'      => '',
                'needs_update' => false,
            );
        }

        $descriptors = array(
            0 => array( 'pipe', 'r' ),
            1 => array( 'pipe', 'w' ),
            2 => array( 'pipe', 'w' ),
        );

        $process = proc_open( escapeshellarg( $full_path ) . ' --version', $descriptors, $pipes );
        if ( ! is_resource( $process ) ) {
            return array(
                'found'        => true,
                'path'         => $full_path,
                'version'      => '',
                'needs_update' => false,
            );
        }

        fclose( $pipes[0] );
        $version_output = trim( stream_get_contents( $pipes[1] ) );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $process );

        if ( preg_match( '/(\d+\.\d+\.\d+)/', $version_output, $matches ) ) {
            $version = $matches[1];
            $major = intval( explode( '.', $version )[0] );

            return array(
                'found'        => true,
                'path'         => $full_path,
                'version'      => $version,
                'needs_update' => $major < 4,
            );
        }

        return array(
            'found'        => true,
            'path'         => $full_path,
            'version'      => '',
            'needs_update' => false,
        );
    }

    /** Node.js/npm/pnpmの一般的なインストールパスを補完したPATH */
    public function get_extended_path(): string {
        $path = getenv( 'PATH' ) ?: '';
        $is_windows = PHP_OS_FAMILY === 'Windows';

        if ( ! $is_windows ) {
            $home = getenv( 'HOME' ) ?: ( $_SERVER['HOME'] ?? '' );

            // nvmのNode.jsをシステムNodeより優先
            $priority_paths = array();
            if ( ! empty( $home ) ) {
                $nvm_version = $this->detect_node_version( $home );
                if ( ! empty( $nvm_version ) ) {
                    $priority_paths[] = $home . '/.nvm/versions/node/' . $nvm_version . '/bin';
                }
                $priority_paths[] = $home . '/Library/pnpm';
                $priority_paths[] = $home . '/.local/share/pnpm';
                $priority_paths[] = $home . '/.npm-global/bin';
                $priority_paths[] = $home . '/.yarn/bin';
            }
            foreach ( array_reverse( $priority_paths ) as $extra ) {
                if ( is_dir( $extra ) && ! str_contains( $path, $extra ) ) {
                    $path = $extra . ':' . $path;
                }
            }

            $system_paths = array(
                '/usr/bin',
                '/usr/local/bin',
                '/opt/homebrew/bin',
            );
            foreach ( $system_paths as $extra ) {
                if ( is_dir( $extra ) && ! str_contains( $path, $extra ) ) {
                    $path .= ':' . $extra;
                }
            }
        }

        return $path;
    }

    public function get_extended_path_env(): array {
        $env = array( 'PATH' => $this->get_extended_path() );
        $home = getenv( 'HOME' );
        if ( $home !== false ) {
            $env['HOME'] = $home;
        }
        return $env;
    }

    private function detect_node_version( string $home ): string {
        $nvm_dir = $home . '/.nvm/versions/node';
        if ( ! is_dir( $nvm_dir ) ) {
            return '';
        }
        $versions = @scandir( $nvm_dir );
        if ( $versions === false ) {
            return '';
        }
        $versions = array_filter( $versions, function( $v ) { return $v[0] === 'v'; } );
        if ( empty( $versions ) ) {
            return '';
        }
        usort( $versions, 'version_compare' );
        return end( $versions );
    }

    private function check_rate_limit( string $action, int $limit = 10, int $period = 60 ): bool {
        $user_id = get_current_user_id();
        $key = 'cp_rate_limit_' . $action . '_' . $user_id;
        $attempts = get_transient( $key );

        if ( $attempts === false ) {
            set_transient( $key, 1, $period );
            return true;
        }

        if ( $attempts >= $limit ) {
            $this->log_security_event( 'rate_limit_exceeded', array(
                'action' => $action,
                'user_id' => $user_id,
                'attempts' => $attempts,
            ) );
            return false;
        }

        set_transient( $key, $attempts + 1, $period );
        return true;
    }

    public function add_security_headers(): void {
        $screen = get_current_screen();
        if ( $screen && str_contains( $screen->id, 'sge' ) ) {
            header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;" );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'X-Frame-Options: SAMEORIGIN' );
            header( 'Referrer-Policy: strict-origin-when-cross-origin' );
        }
    }

    private function log_security_event( string $event_type, array $context = array() ): void {
        $log_entry = array(
            'timestamp' => current_time( 'mysql' ),
            'event_type' => $event_type,
            'user_id' => get_current_user_id(),
            'ip_address' => $this->get_client_ip(),
            'context' => $context,
        );

        $security_logs = get_option( 'cp_security_logs', array() );
        array_unshift( $security_logs, $log_entry );
        $security_logs = array_slice( $security_logs, 0, 100 );
        update_option( 'cp_security_logs', $security_logs, false );

        if ( in_array( $event_type, array( 'rate_limit_exceeded', 'auth_failed', 'invalid_nonce' ) ) ) {
            error_log( 'SGE Security Event: ' . $event_type . ' - User: ' . $log_entry['user_id'] . ' - IP: ' . $log_entry['ip_address'] );
        }
    }

    /** REMOTE_ADDRのみ信頼（HTTP_X_FORWARDED_FOR等はスプーフィング可能） */
    private function get_client_ip(): string {
        $ip = '';
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                $ip = '';
            }
        }
        return $ip;
    }

    private function safe_delete_records( string $table, string $query, array $params = array(), int $timeout = 60 ): int|\WP_Error {
        global $wpdb;

        $start_time = time();
        $batch_size = 100;
        $deleted_total = 0;

        $count_query = str_replace( 'DELETE FROM', 'SELECT COUNT(*) FROM', $query );
        $total_count = $wpdb->get_var( $wpdb->prepare( $count_query, $params ) );

        if ( $total_count === null || $total_count == 0 ) {
            return 0;
        }

        if ( $total_count <= $batch_size ) {
            $result = $wpdb->query( $wpdb->prepare( $query, $params ) );
            return $result !== false ? $result : 0;
        }

        while ( true ) {
            if ( ( time() - $start_time ) >= $timeout ) {
                $error = new WP_Error(
                    'db_timeout',
                    sprintf( '%d件削除後にタイムアウトしました（残り約%d件）', $deleted_total, $total_count - $deleted_total )
                );
                $error->error_data = array( 'partial_delete' => $deleted_total );
                return $error;
            }

            $batch_query = $query . ' LIMIT ' . intval( $batch_size );
            $deleted = $wpdb->query( $wpdb->prepare( $batch_query, $params ) );

            if ( $deleted === false || $deleted === 0 ) {
                break;
            }

            $deleted_total += $deleted;

            usleep( 10000 );
        }

        return $deleted_total;
    }

    private function render_tooltip( string $text ): string {
        return sprintf(
            '<span class="nau-tooltip-wrapper">' .
            '<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>' .
            '<span class="nau-tooltip-content" role="tooltip">%s</span>' .
            '</span>',
            wp_kses( $text, array( 'br' => array() ) )
        );
    }

    private function get_mati_xrobots_value(): string {
        if ( ! defined( 'MATI_VERSION' ) || ! class_exists( 'Mati_Settings' ) ) {
            return '';
        }

        try {
            if ( ! method_exists( 'Mati_Settings', 'get_instance' ) ) {
                return '';
            }

            $mati_settings_instance = Mati_Settings::get_instance();

            if ( ! method_exists( $mati_settings_instance, 'get_settings' ) ) {
                return '';
            }

            $mati_settings = $mati_settings_instance->get_settings();
            $robots_tags   = array();

            if ( ! empty( $mati_settings['add_noindex_meta'] ) ) {
                $robots_tags[] = 'noindex';
            }
            if ( ! empty( $mati_settings['add_noarchive_meta'] ) ) {
                $robots_tags[] = 'noarchive';
            }
            if ( ! empty( $mati_settings['add_noimageindex_meta'] ) ) {
                $robots_tags[] = 'noimageindex';
            }
            if ( ! empty( $mati_settings['add_noai_meta'] ) ) {
                $robots_tags[] = 'noai';
                $robots_tags[] = 'noimageai';
            }

            return ! empty( $robots_tags ) ? implode( ', ', $robots_tags ) : '（Matiで設定されていません）';
        } catch ( Exception $e ) {
            return '';
        }
    }

    private function has_mati_bluesky_did(): bool {
        if ( ! defined( 'MATI_VERSION' ) || ! class_exists( 'Mati_Settings' ) ) {
            return false;
        }

        try {
            if ( ! method_exists( 'Mati_Settings', 'get_instance' ) ) {
                return false;
            }

            $mati_settings_instance = Mati_Settings::get_instance();

            if ( ! method_exists( $mati_settings_instance, 'get_settings' ) ) {
                return false;
            }

            $mati_settings = $mati_settings_instance->get_settings();
            return ! empty( $mati_settings['bluesky_did'] );
        } catch ( Exception $e ) {
            return false;
        }
    }

    private function get_mati_frame_ancestors_value(): string {
        if ( ! defined( 'MATI_VERSION' ) || ! class_exists( 'Mati_Settings' ) ) {
            return "frame-ancestors 'self'";
        }

        try {
            if ( ! method_exists( 'Mati_Settings', 'get_instance' ) ) {
                return "frame-ancestors 'self'";
            }

            $mati_settings_instance = Mati_Settings::get_instance();

            if ( ! method_exists( $mati_settings_instance, 'get_settings' ) ) {
                return "frame-ancestors 'self'";
            }

            $mati_settings   = $mati_settings_instance->get_settings();
            $frame_ancestors = "'self'";
            $custom_domains  = $mati_settings['frame_ancestors_domains'] ?? '';

            if ( ! empty( $custom_domains ) ) {
                $domains = array_filter( array_map( 'trim', explode( "\n", $custom_domains ) ) );
                if ( ! empty( $domains ) ) {
                    $frame_ancestors .= ' ' . implode( ' ', $domains );
                }
            }

            return 'frame-ancestors ' . $frame_ancestors;
        } catch ( Exception $e ) {
            return "frame-ancestors 'self'";
        }
    }

    public function check_mati_compatibility(): void {
        if ( ! defined( 'MATI_VERSION' ) ) {
            return;
        }

        $mati_version = MATI_VERSION;

        if ( version_compare( $mati_version, '2.0.0', '>=' ) ) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong>⚠️ Mati連携</strong><br>
                Mati 2.0.0以降にアップデートすると、すべての連携機能が有効になります。<br>
                <small>現在: Mati <?php echo esc_html( $mati_version ); ?> → 推奨: Mati 2.0.0+</small>
            </p>
        </div>
        <?php
    }

    public function check_screw_compatibility(): void {
        if ( ! defined( 'SC_VERSION' ) ) {
            return;
        }

        $sc_version = SC_VERSION;

        if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9\-]+)?$/', $sc_version ) ) {
            return;
        }

        if ( version_compare( $sc_version, '2.0.0', '>=' ) ) {
            return;
        }

        ?>
        <div class="notice notice-warning">
            <p>
                <strong>⚠️ Screw連携</strong><br>
                Screw 2.0.0以降にアップデートすると、双方向連携機能が有効になります。<br>
                <small>現在: Screw <?php echo esc_html( $sc_version ); ?> → 推奨: Screw 2.0.0+</small>
            </p>
        </div>
        <?php
    }
}
