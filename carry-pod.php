<?php
/**
 * Plugin Name: Carry Pod
 * Version: 2.0.0
 * Description: WordPressサイトを静的化してデプロイするプラグイン
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Vill Yoshioka
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: carry-pod
 */

// 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// プラグインの定数を定義
define( 'CP_VERSION', '2.0.0' );
define( 'CP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CP_PLUGIN_FILE', __FILE__ );

// Action Schedulerを先に読み込み（他のプラグインより前に読み込む必要がある）
if ( file_exists( CP_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php' ) ) {
    require_once CP_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';
}

/**
 * メインプラグインクラス
 */
class Carry_Pod {

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
        $this->load_dependencies();
        $this->init_hooks();
        add_action( 'plugins_loaded', array( $this, 'on_plugin_loaded' ) );
    }

    /**
     * 依存ファイルを読み込み
     */
    private function load_dependencies() {
        // クラスファイルを読み込み
        require_once CP_PLUGIN_DIR . 'includes/class-logger.php';
        require_once CP_PLUGIN_DIR . 'includes/class-settings.php';
        require_once CP_PLUGIN_DIR . 'includes/class-cache.php';
        require_once CP_PLUGIN_DIR . 'includes/interface-git-provider.php';
        require_once CP_PLUGIN_DIR . 'includes/class-github-api.php';
        require_once CP_PLUGIN_DIR . 'includes/class-gitlab-api.php';
        require_once CP_PLUGIN_DIR . 'includes/class-parallel-crawler.php';
        require_once CP_PLUGIN_DIR . 'includes/class-asset-detector.php';
        require_once CP_PLUGIN_DIR . 'includes/class-generator.php';
        require_once CP_PLUGIN_DIR . 'includes/class-cloudflare-workers.php';
        require_once CP_PLUGIN_DIR . 'includes/class-netlify-api.php';
        require_once CP_PLUGIN_DIR . 'includes/class-admin.php';
        require_once CP_PLUGIN_DIR . 'includes/class-updater.php';
    }

    /**
     * フックを初期化
     */
    private function init_hooks() {
        // 有効化/無効化フック
        register_activation_hook( CP_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( CP_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // プラグイン削除時のフック
        register_uninstall_hook( CP_PLUGIN_FILE, array( 'Carry_Pod', 'uninstall' ) );

        // 管理画面を初期化
        if ( is_admin() ) {
            // 権限が未登録の場合は登録
            add_action( 'admin_init', array( $this, 'ensure_capabilities' ) );
            CP_Admin::get_instance();
        }

        // 自動更新を初期化
        new CP_Updater();

        // Action Schedulerが初期化された後にフック登録
        add_action( 'action_scheduler_init', array( $this, 'init_action_scheduler_hooks' ) );
    }

    /**
     * Action Scheduler関連のフックを初期化
     * Action Schedulerが完全に初期化された後に呼ばれる
     */
    public function init_action_scheduler_hooks() {
        // 自動静的化フック
        add_action( 'transition_post_status', array( $this, 'auto_generate_on_post_change' ), 10, 3 );

        // Action Schedulerアクション
        add_action( 'cp_static_generation', array( $this, 'process_static_generation' ) );

        // Action Scheduler時間制限の延長（タイムアウト対策）
        add_filter( 'action_scheduler_queue_runner_time_limit', array( $this, 'extend_action_scheduler_time_limit' ) );
        add_filter( 'action_scheduler_failure_period', array( $this, 'extend_action_scheduler_failure_period' ) );
        add_filter( 'action_scheduler_timeout_period', array( $this, 'extend_action_scheduler_timeout_period' ) );
    }

    /**
     * プラグイン有効化時の処理
     */
    public function activate() {
        // WordPressバージョンチェック
        global $wp_version;
        if ( version_compare( $wp_version, '6.0', '<' ) ) {
            deactivate_plugins( plugin_basename( CP_PLUGIN_FILE ) );
            wp_die( 'このプラグインにはWordPress 6.0以上が必要です。' );
        }

        // PHPバージョンチェック
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( plugin_basename( CP_PLUGIN_FILE ) );
            wp_die( 'このプラグインにはPHP 7.4以上が必要です。' );
        }

        // WP2static / Simply Static競合チェック（警告のみ）
        if ( is_plugin_active( 'wp2static/wp2static.php' ) || is_plugin_active( 'simply-static/simply-static.php' ) ) {
            set_transient( 'cp_plugin_conflict_warning', true, 30 );
        }

        // Gitコマンドチェック（警告のみ）
        // ランタイムと同じホワイトリスト方式で検出
        require_once CP_PLUGIN_DIR . 'includes/class-generator.php';
        $git_path = CP_Generator::find_git_command();
        if ( $git_path === false ) {
            set_transient( 'cp_git_warning', true, 30 );
        }

        // カスタム権限を登録
        $this->register_capabilities();

        // デフォルト設定を保存
        $default_settings = array(
            'version' => CP_VERSION,
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
            // 出力設定
            'enable_tag_archive' => false,
            'enable_date_archive' => false,
            'enable_author_archive' => false,
            'enable_post_format_archive' => false,
            'enable_sitemap' => true,
            'enable_robots_txt' => false,
            'enable_rss' => true,
        );

        if ( ! get_option( 'cp_settings' ) ) {
            add_option( 'cp_settings', $default_settings );
        }

        // ログテーブル用オプションを初期化
        if ( ! get_option( 'cp_logs' ) ) {
            add_option( 'cp_logs', array() );
        }
    }

    /**
     * カスタム権限を登録
     */
    private function register_capabilities() {
        // 管理者: 両方の権限
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'cp_execute' );
            $admin->add_cap( 'cp_manage_settings' );
        }

        // 編集者: 実行権限のみ
        $editor = get_role( 'editor' );
        if ( $editor ) {
            $editor->add_cap( 'cp_execute' );
        }
    }

    /**
     * 権限が未登録の場合に登録（既存インストール対応）
     */
    public function ensure_capabilities() {
        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( 'cp_execute' ) ) {
            $this->register_capabilities();
        }
    }

    /**
     * カスタム権限を削除
     */
    private function remove_capabilities() {
        $roles = array( 'administrator', 'editor' );
        $caps = array( 'cp_execute', 'cp_manage_settings' );

        foreach ( $roles as $role_name ) {
            $role = get_role( $role_name );
            if ( $role ) {
                foreach ( $caps as $cap ) {
                    $role->remove_cap( $cap );
                }
            }
        }
    }

    /**
     * プラグイン無効化時の処理
     */
    public function deactivate() {
        // 実行中のタスクは続行（Action Schedulerがバックグラウンドで処理）
        // カスタム権限を削除
        $this->remove_capabilities();
    }

    /**
     * プラグイン削除時の処理
     */
    public static function uninstall() {
        // 設定データ削除
        delete_option( 'cp_settings' );
        delete_option( 'cp_logs' );

        // カスタム権限を削除
        $roles = array( 'administrator', 'editor' );
        $caps = array( 'cp_execute', 'cp_manage_settings' );
        foreach ( $roles as $role_name ) {
            $role = get_role( $role_name );
            if ( $role ) {
                foreach ( $caps as $cap ) {
                    $role->remove_cap( $cap );
                }
            }
        }

        // Action Schedulerのタスク削除
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'cp_static_generation', array(), 'cp' );
        }
    }

    /**
     * 投稿変更時の自動静的化
     */
    public function auto_generate_on_post_change( $new_status, $old_status, $post ) {
        // 自動実行が無効なら何もしない
        $settings = get_option( 'cp_settings', array() );
        if ( empty( $settings['auto_generate'] ) ) {
            return;
        }

        // 投稿タイプチェック（投稿、固定ページ、カスタム投稿タイプ）
        $post_types = array_merge( array( 'post', 'page' ), get_post_types( array( 'public' => true, '_builtin' => false ) ) );
        if ( ! in_array( $post->post_type, $post_types ) ) {
            return;
        }

        // 公開状態の変更をチェック
        $trigger_statuses = array( 'publish', 'trash', 'draft', 'private' );
        if ( in_array( $new_status, $trigger_statuses ) || in_array( $old_status, $trigger_statuses ) ) {
            // 手動実行中フラグをチェック
            if ( get_transient( 'cp_manual_running' ) ) {
                return; // 手動実行中は自動実行しない
            }

            // 既存の未実行/実行中タスクをキャンセル
            as_unschedule_all_actions( 'cp_static_generation', array(), 'cp' );

            // ログと進捗情報をクリア
            update_option( 'cp_logs', array() );
            delete_option( 'cp_progress' );

            // 実行中フラグをセット
            set_transient( 'cp_auto_running', true, 3600 );

            // 初期ログを記録
            $logger = CP_Logger::get_instance();
            $logger->add_log( '自動生成を開始します...' );
            $logger->update_progress( 0, 1, 'バックグラウンド処理を待機中...' );

            // 新しいタスクをスケジュール
            as_enqueue_async_action( 'cp_static_generation', array(), 'cp' );
        }
    }

    /**
     * 静的化処理の実行
     */
    public function process_static_generation() {
        // 静的化処理を実行
        $generator = new CP_Generator();
        $generator->generate();
    }

    /**
     * Action Schedulerの実行時間制限を延長
     * デフォルト30秒 → 3600秒（1時間）に延長
     * 大規模サイト（数千〜数万ファイル）に対応
     *
     * @param int $time_limit 時間制限（秒）
     * @return int 延長された時間制限
     */
    public function extend_action_scheduler_time_limit( $time_limit ) {
        return 3600; // 1時間
    }

    /**
     * Action Schedulerの失敗判定時間を延長
     * デフォルト300秒（5分） → 3600秒（1時間）に延長
     * 大規模サイト（数千〜数万ファイル）に対応
     *
     * @param int $time_limit 時間制限（秒）
     * @return int 延長された時間制限
     */
    public function extend_action_scheduler_failure_period( $time_limit ) {
        return 3600; // 1時間
    }

    /**
     * Action Schedulerのタイムアウト判定時間を延長
     * デフォルト300秒（5分） → 3600秒（1時間）に延長
     * 大規模サイト（数千〜数万ファイル）に対応
     *
     * @param int $time_limit 時間制限（秒）
     * @return int 延長された時間制限
     */
    public function extend_action_scheduler_timeout_period( $time_limit ) {
        return 3600; // 1時間
    }

    /**
     * プラグイン更新時の処理
     * v2.x系同士のマイナーアップデート対応用
     */
    public function on_plugin_loaded() {
        $current_version = get_option( 'cp_version', '0.0.0' );

        // 将来のバージョンアップ時に必要な処理をここに追加
        // 例: if ( version_compare( $current_version, '2.1.0', '<' ) ) { ... }

        // 現在のバージョンを記録
        if ( version_compare( $current_version, CP_VERSION, '<' ) ) {
            update_option( 'cp_version', CP_VERSION );
        }
    }
}

// プラグインを初期化（Action Scheduler読み込み直後に実行）
Carry_Pod::get_instance();
