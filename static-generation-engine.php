<?php
/**
 * Plugin Name: Static Generation Engine
 * Description: WordPressサイトを静的化してGitHubまたはローカルに出力するプラグイン
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Vill Yoshioka
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: static-generation-engine
 */

// 直接アクセスを防止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// プラグインの定数を定義
define( 'SGE_VERSION', '1.1.0' );
define( 'SGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SGE_PLUGIN_FILE', __FILE__ );

// Action Schedulerを先に読み込み（他のプラグインより前に読み込む必要がある）
if ( file_exists( SGE_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php' ) ) {
    require_once SGE_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';
}

/**
 * メインプラグインクラス
 */
class Static_Generation_Engine {

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
    }

    /**
     * 依存ファイルを読み込み
     */
    private function load_dependencies() {
        // クラスファイルを読み込み
        require_once SGE_PLUGIN_DIR . 'includes/class-logger.php';
        require_once SGE_PLUGIN_DIR . 'includes/class-settings.php';
        require_once SGE_PLUGIN_DIR . 'includes/class-cache.php';
        require_once SGE_PLUGIN_DIR . 'includes/class-github-api.php';
        require_once SGE_PLUGIN_DIR . 'includes/class-parallel-crawler.php';
        require_once SGE_PLUGIN_DIR . 'includes/class-asset-detector.php';
        require_once SGE_PLUGIN_DIR . 'includes/class-generator.php';
        require_once SGE_PLUGIN_DIR . 'includes/class-admin.php';
    }

    /**
     * フックを初期化
     */
    private function init_hooks() {
        // 有効化/無効化フック
        register_activation_hook( SGE_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( SGE_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // プラグイン削除時のフック
        register_uninstall_hook( SGE_PLUGIN_FILE, array( 'Static_Generation_Engine', 'uninstall' ) );

        // 管理画面を初期化
        if ( is_admin() ) {
            SGE_Admin::get_instance();
        }

        // 自動静的化フック
        add_action( 'transition_post_status', array( $this, 'auto_generate_on_post_change' ), 10, 3 );

        // Action Schedulerアクション
        add_action( 'sge_static_generation', array( $this, 'process_static_generation' ) );

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
            deactivate_plugins( plugin_basename( SGE_PLUGIN_FILE ) );
            wp_die( 'このプラグインにはWordPress 6.0以上が必要です。' );
        }

        // PHPバージョンチェック
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( plugin_basename( SGE_PLUGIN_FILE ) );
            wp_die( 'このプラグインにはPHP 7.4以上が必要です。' );
        }

        // WP2static / Simply Static競合チェック（警告のみ）
        if ( is_plugin_active( 'wp2static/wp2static.php' ) || is_plugin_active( 'simply-static/simply-static.php' ) ) {
            set_transient( 'sge_plugin_conflict_warning', true, 30 );
        }

        // Gitコマンドチェック（警告のみ）
        exec( 'git --version 2>&1', $output, $return_var );
        if ( $return_var !== 0 ) {
            set_transient( 'sge_git_warning', true, 30 );
        }

        // デフォルト設定を保存
        $default_settings = array(
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
            // 出力設定
            'enable_tag_archive' => false,
            'enable_date_archive' => false,
            'enable_author_archive' => false,
            'enable_post_format_archive' => false,
            'enable_sitemap' => true,
            'enable_robots_txt' => false,
            'enable_rss' => true,
        );

        if ( ! get_option( 'sge_settings' ) ) {
            add_option( 'sge_settings', $default_settings );
        }

        // ログテーブル用オプションを初期化
        if ( ! get_option( 'sge_logs' ) ) {
            add_option( 'sge_logs', array() );
        }
    }

    /**
     * プラグイン無効化時の処理
     */
    public function deactivate() {
        // 実行中のタスクは続行（Action Schedulerがバックグラウンドで処理）
    }

    /**
     * プラグイン削除時の処理
     */
    public static function uninstall() {
        // 設定データ削除
        delete_option( 'sge_settings' );
        delete_option( 'sge_logs' );

        // Action Schedulerのタスク削除
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'sge_static_generation', array(), 'sge' );
        }
    }

    /**
     * 投稿変更時の自動静的化
     */
    public function auto_generate_on_post_change( $new_status, $old_status, $post ) {
        // 自動実行が無効なら何もしない
        $settings = get_option( 'sge_settings', array() );
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
            if ( get_transient( 'sge_manual_running' ) ) {
                return; // 手動実行中は自動実行しない
            }

            // 既存の未実行/実行中タスクをキャンセル
            as_unschedule_all_actions( 'sge_static_generation', array(), 'sge' );

            // ログと進捗情報をクリア
            update_option( 'sge_logs', array() );
            delete_option( 'sge_progress' );

            // 新しいタスクをスケジュール
            as_enqueue_async_action( 'sge_static_generation', array(), 'sge' );
        }
    }

    /**
     * 静的化処理の実行
     */
    public function process_static_generation() {
        // 静的化処理を実行
        $generator = new SGE_Generator();
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
}

// プラグインを初期化
function sge_init() {
    return Static_Generation_Engine::get_instance();
}

// プラグインを起動
add_action( 'plugins_loaded', 'sge_init' );
