<?php
/**
 * アセット検出クラス（WP2Staticを参考に実装）
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_Asset_Detector {

    private CP_Logger $logger;

    private array $exclude_extensions = array(
        'php',
        'php5',
        'php7',
        'phtml',
        'phps',
        'md',
        'txt',
        'log',
    );

    private array $include_extensions = array(
        'css',
        'js',
        'json',
        'jpg',
        'jpeg',
        'png',
        'gif',
        'svg',
        'webp',
        'ico',
        'woff',
        'woff2',
        'ttf',
        'otf',
        'eot',
        'mp4',
        'webm',
        'mp3',
        'wav',
        'pdf',
        'xml',
    );

    public function __construct() {
        $this->logger = CP_Logger::get_instance();
    }

    public function detect_wp_includes(): array {
        $files = array();
        $wp_includes_dir = ABSPATH . 'wp-includes';

        if ( ! is_dir( $wp_includes_dir ) ) {
            $this->logger->add_log( 'wp-includesディレクトリが見つかりません', true );
            return $files;
        }

        $important_dirs = array(
            'css',
            'js',
            'fonts',
            'images',
            'blocks',
        );

        foreach ( $important_dirs as $dir ) {
            $dir_path = $wp_includes_dir . '/' . $dir;

            if ( is_dir( $dir_path ) ) {
                $dir_files = $this->scan_directory( $dir_path );
                $files = array_merge( $files, $dir_files );
            }
        }

        $this->logger->add_log( 'wp-includesから ' . count( $files ) . ' 個のアセットを検出しました' );

        return $files;
    }

    public function detect_theme_assets( string $type = 'child' ): array {
        $files = array();

        if ( $type === 'parent' ) {
            $theme_dir = get_template_directory();
        } else {
            $theme_dir = get_stylesheet_directory();
        }

        if ( ! is_dir( $theme_dir ) ) {
            return $files;
        }

        $files = $this->scan_directory( $theme_dir );

        $this->logger->add_log( $type . ' テーマから ' . count( $files ) . ' 個のアセットを検出しました' );

        return $files;
    }

    public function detect_plugin_assets(): array {
        $files = array();
        $plugins_dir = WP_PLUGIN_DIR;

        if ( ! is_dir( $plugins_dir ) ) {
            $this->logger->add_log( 'プラグインディレクトリが見つかりません', true );
            return $files;
        }

        $active_plugins = get_option( 'active_plugins', array() );

        if ( is_multisite() ) {
            $network_plugins = get_site_option( 'active_sitewide_plugins', array() );
            $active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
        }

        foreach ( $active_plugins as $plugin ) {
            $plugin_dir = dirname( $plugins_dir . '/' . $plugin );

            if ( is_dir( $plugin_dir ) ) {
                if ( str_contains( $plugin_dir, 'carry-pod' ) ) {
                    continue;
                }

                $plugin_files = $this->scan_directory( $plugin_dir );
                $files = array_merge( $files, $plugin_files );
            }
        }

        $this->logger->add_log( 'プラグインから ' . count( $files ) . ' 個のアセットを検出しました' );

        return $files;
    }

    public function detect_uploads(): array {
        $files = array();
        $upload_dir = wp_upload_dir();
        $uploads_path = $upload_dir['basedir'];

        if ( ! is_dir( $uploads_path ) ) {
            $this->logger->add_log( 'アップロードディレクトリが見つかりません', true );
            return $files;
        }

        $files = $this->scan_directory( $uploads_path );

        $this->logger->add_log( 'アップロードから ' . count( $files ) . ' 個のファイルを検出しました' );

        return $files;
    }

    private function scan_directory( string $dir ): array {
        $files = array();

        if ( ! is_dir( $dir ) ) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $dir,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $filepath = $file->getPathname();
                $extension = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );

                if ( in_array( $extension, $this->exclude_extensions ) ) {
                    continue;
                }

                if ( ! empty( $this->include_extensions ) &&
                     ! in_array( $extension, $this->include_extensions ) ) {
                    continue;
                }

                $filename = basename( $filepath );
                if ( str_starts_with( $filename, '.' ) ) {
                    continue;
                }

                $relative_path = str_replace( ABSPATH, '/', $filepath );
                $relative_path = str_replace( '//', '/', $relative_path );

                $files[] = $relative_path;
            }
        }

        return $files;
    }

    public function detect_all_assets(): array {
        $all_files = array();

        $all_files = array_merge( $all_files, $this->detect_wp_includes() );

        if ( is_child_theme() ) {
            $all_files = array_merge( $all_files, $this->detect_theme_assets( 'parent' ) );
        }
        $all_files = array_merge( $all_files, $this->detect_theme_assets( 'child' ) );

        $all_files = array_merge( $all_files, $this->detect_plugin_assets() );
        $all_files = array_merge( $all_files, $this->detect_uploads() );
        $all_files = array_unique( $all_files );

        $this->logger->add_log( '合計 ' . count( $all_files ) . ' 個のアセットを検出しました' );

        return $all_files;
    }
}
