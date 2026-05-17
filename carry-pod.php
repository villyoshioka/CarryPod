<?php
/**
 * Plugin Name: Carry Pod
 * Version: 3.0.0
 * Description: WordPressサイトを静的化してデプロイするプラグイン
 * Requires at least: 6.8
 * Tested up to: 7.0
 * Requires PHP: 8.3
 * Author: Vill Yoshioka
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: carry-pod
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CP_VERSION', '3.0.0' );
define( 'CP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CP_PLUGIN_FILE', __FILE__ );

if ( file_exists( CP_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php' ) ) {
    require_once CP_PLUGIN_DIR . 'lib/action-scheduler/action-scheduler.php';
}

class Carry_Pod {

    private static ?self $instance = null;

    public static function get_instance(): static {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
        add_action( 'plugins_loaded', array( $this, 'on_plugin_loaded' ) );
    }

    private function load_dependencies(): void {
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

    private function init_hooks(): void {
        register_activation_hook( CP_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( CP_PLUGIN_FILE, array( $this, 'deactivate' ) );
        register_uninstall_hook( CP_PLUGIN_FILE, array( 'Carry_Pod', 'uninstall' ) );

        if ( is_admin() ) {
            add_action( 'admin_init', array( $this, 'ensure_capabilities' ) );
            CP_Admin::get_instance();
        }

        new CP_Updater();
        add_action( 'action_scheduler_init', array( $this, 'init_action_scheduler_hooks' ) );
    }

    public function init_action_scheduler_hooks(): void {
        add_action( 'transition_post_status', array( $this, 'auto_generate_on_post_change' ), 10, 3 );
        add_action( 'cp_static_generation', array( $this, 'process_static_generation' ) );
        add_filter( 'action_scheduler_queue_runner_time_limit', array( $this, 'extend_action_scheduler_time_limit' ) );
        add_filter( 'action_scheduler_failure_period', array( $this, 'extend_action_scheduler_failure_period' ) );
        add_filter( 'action_scheduler_timeout_period', array( $this, 'extend_action_scheduler_timeout_period' ) );
    }

    public function activate(): void {
        global $wp_version;
        if ( version_compare( $wp_version, '6.8', '<' ) ) {
            deactivate_plugins( plugin_basename( CP_PLUGIN_FILE ) );
            wp_die( 'このプラグインにはWordPress 6.8以上が必要です。' );
        }

        if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
            deactivate_plugins( plugin_basename( CP_PLUGIN_FILE ) );
            wp_die( 'このプラグインにはPHP 8.3以上が必要です。' );
        }

        if ( is_plugin_active( 'wp2static/wp2static.php' ) || is_plugin_active( 'simply-static/simply-static.php' ) ) {
            set_transient( 'cp_plugin_conflict_warning', true, 30 );
        }

        require_once CP_PLUGIN_DIR . 'includes/class-generator.php';
        $git_path = CP_Generator::find_git_command();
        if ( $git_path === false ) {
            set_transient( 'cp_git_warning', true, 30 );
        }

        $this->register_capabilities();

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

        if ( ! get_option( 'cp_settings' ) ) {
            add_option( 'cp_settings', $default_settings );
        }

        if ( ! get_option( 'cp_logs' ) ) {
            add_option( 'cp_logs', array() );
        }
    }

    private function register_capabilities(): void {
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'cp_execute' );
            $admin->add_cap( 'cp_manage_settings' );
        }

        $editor = get_role( 'editor' );
        if ( $editor ) {
            $editor->add_cap( 'cp_execute' );
        }
    }

    /** 権限が未登録の場合に登録（既存インストール対応） */
    public function ensure_capabilities(): void {
        $admin = get_role( 'administrator' );
        if ( $admin && ! $admin->has_cap( 'cp_execute' ) ) {
            $this->register_capabilities();
        }
    }

    private function remove_capabilities(): void {
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

    public function deactivate(): void {
        $this->remove_capabilities();
    }

    public static function uninstall(): void {
        delete_option( 'cp_settings' );
        delete_option( 'cp_logs' );

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

        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'cp_static_generation', array(), 'cp' );
        }
    }

    public function auto_generate_on_post_change( $new_status, $old_status, $post ): void {
        $post_types = array_merge( array( 'post', 'page' ), get_post_types( array( 'public' => true, '_builtin' => false ) ) );
        if ( ! in_array( $post->post_type, $post_types ) ) {
            return;
        }

        // 新規公開時のみグローバルタイムスタンプ更新（既存投稿の変更は依存投稿チェックで対応）
        if ( $old_status !== 'publish' && $new_status === 'publish' ) {
            update_option( 'cp_last_post_change', microtime( true ) );
        }

        $settings = get_option( 'cp_settings', array() );
        if ( empty( $settings['auto_generate'] ) ) {
            return;
        }

        $trigger_statuses = array( 'publish' );

        if ( in_array( $new_status, $trigger_statuses ) || in_array( $old_status, $trigger_statuses ) ) {
            if ( get_transient( 'cp_manual_running' ) ) {
                return;
            }

            as_unschedule_all_actions( 'cp_static_generation', array(), 'cp' );

            update_option( 'cp_logs', array() );
            delete_option( 'cp_progress' );

            $cache = CP_Cache::get_instance();
            $cache->clear_by_post( $post->ID );
            $cache->clear_adjacent_posts_cache( $post->ID, $old_status, $new_status );

            set_transient( 'cp_auto_running', true, 3600 );

            $logger = CP_Logger::get_instance();
            $logger->add_log( '自動生成を開始します...' );
            $logger->update_progress( 0, 1, 'バックグラウンド処理を待機中...' );

            as_enqueue_async_action( 'cp_static_generation', array(), 'cp' );
        }
    }

    public function process_static_generation(): void {
        $generator = new CP_Generator();
        $generator->generate();
    }

    /** Action Scheduler の時間制限を1時間に延長（大規模サイト対応） */
    public function extend_action_scheduler_time_limit( int $time_limit ): int {
        return 3600;
    }

    public function extend_action_scheduler_failure_period( int $time_limit ): int {
        return 3600;
    }

    public function extend_action_scheduler_timeout_period( int $time_limit ): int {
        return 3600;
    }

    public function on_plugin_loaded(): void {
        $current_version = get_option( 'cp_version', '0.0.0' );

        if ( version_compare( $current_version, CP_VERSION, '<' ) ) {
            update_option( 'cp_version', CP_VERSION );
        }
    }
}

Carry_Pod::get_instance();
