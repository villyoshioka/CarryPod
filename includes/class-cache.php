<?php
/**
 * Cache management class
 */

// Mati plugin stub for static analysis (never executed).
if ( false ) {
    class Mati_Settings {
        public static function get_instance(): self { return new self(); }
        /** @return array<string, mixed> */
        public function get_settings(): array { return []; }
        /** @param array<string, mixed> $settings @param array<string, mixed> $options */
        public function save_settings( array $settings, array $options = [] ): void {}
    }
}

class CP_Cache {

    private static ?self $instance = null;
    private string $cache_dir;
    private array $current_dependent_posts = array();

    public static function get_instance(): static {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->cache_dir = WP_CONTENT_DIR . '/.cp-cache';
        $this->ensure_cache_dir();
    }

    private function ensure_cache_dir(): void {
        // Remove the legacy cache folder (cp-cache) if it still exists
        $old_cache_dir = WP_CONTENT_DIR . '/cp-cache';
        if ( is_dir( $old_cache_dir ) ) {
            $this->remove_directory( $old_cache_dir );
        }

        if ( ! is_dir( $this->cache_dir ) ) {
            mkdir( $this->cache_dir, 0700, true );

            $htaccess_file = $this->cache_dir . '/.htaccess';
            if ( ! file_exists( $htaccess_file ) ) {
                $htaccess_content = "# Protect cache directory\n";
                $htaccess_content .= "Options -Indexes\n";
                $htaccess_content .= "<FilesMatch \".*\">\n";
                $htaccess_content .= "    Require all denied\n";
                $htaccess_content .= "</FilesMatch>\n";
                file_put_contents( $htaccess_file, $htaccess_content );
            }

            $index_file = $this->cache_dir . '/index.php';
            if ( ! file_exists( $index_file ) ) {
                file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
            }
        }
    }

    private function get_cache_key( string $url ): string {
        return md5( $url );
    }

    private function get_cache_file_path( string $cache_key ): string {
        return $this->cache_dir . '/' . $cache_key . '.html';
    }

    private function get_meta_file_path( string $cache_key ): string {
        return $this->cache_dir . '/' . $cache_key . '.meta';
    }

    /**
     * Check whether the cache is valid
     *
     * @param string $url URL
     * @param int|null $post_id Post ID (optional)
     * @return bool True if valid
     */
    public function is_valid( string $url, ?int $post_id = null ): bool {
        $cache_key = $this->get_cache_key( $url );
        $cache_file = $this->get_cache_file_path( $cache_key );
        $meta_file = $this->get_meta_file_path( $cache_key );

        if ( ! file_exists( $cache_file ) || ! file_exists( $meta_file ) ) {
            return false;
        }

        $meta = json_decode( file_get_contents( $meta_file ), true );
        if ( ! $meta ) {
            return false;
        }

        $cache_time = $meta['timestamp'];

        $last_post_change = get_option( 'cp_last_post_change', 0 );
        if ( $last_post_change > $cache_time ) {
            return false;
        }

        // Check the dependent posts list (backward compatibility: skip if missing)
        if ( isset( $meta['dependent_posts'] ) && is_array( $meta['dependent_posts'] ) ) {
            foreach ( $meta['dependent_posts'] as $dependent_post_id ) {
                $post = get_post( $dependent_post_id );
                if ( ! $post ) {
                    return false;
                }

                if ( $post->post_status !== 'publish' ) {
                    return false;
                }

                // Compare in UTC to avoid timezone issues
                $post_modified = (float) strtotime( $post->post_modified_gmt . ' UTC' );
                if ( $post_modified > $cache_time ) {
                    return false;
                }

                if ( isset( $meta['dependent_posts_urls'] ) && is_array( $meta['dependent_posts_urls'] ) ) {
                    $cached_url = $meta['dependent_posts_urls'][ $dependent_post_id ] ?? '';
                    $current_url = get_permalink( $dependent_post_id );
                    if ( $cached_url && $current_url !== $cached_url ) {
                        return false;
                    }
                }
            }
        }

        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return false;
            }

            $post_modified = (float) strtotime( $post->post_modified_gmt . ' UTC' );
            if ( $post_modified > $cache_time ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get cache
     *
     * @param string $url URL
     * @return string|false Cached HTML, or false if not found
     */
    public function get( string $url ): string|false {
        $cache_key = $this->get_cache_key( $url );
        $cache_file = $this->get_cache_file_path( $cache_key );

        if ( ! file_exists( $cache_file ) ) {
            return false;
        }

        return file_get_contents( $cache_file );
    }

    /**
     * Save cache
     *
     * @param string $url URL
     * @param string $html HTML
     * @param int|null $post_id Post ID (optional)
     * @return bool True on success
     */
    public function set( string $url, string $html, ?int $post_id = null ): bool {
        $cache_key = $this->get_cache_key( $url );
        $cache_file = $this->get_cache_file_path( $cache_key );
        $meta_file = $this->get_meta_file_path( $cache_key );

        if ( false === file_put_contents( $cache_file, $html ) ) {
            return false;
        }

        // Always put this page's own ID first so it is not dropped when truncated to the limit
        $dependent_posts = array_unique( $this->current_dependent_posts );
        if ( $post_id ) {
            $dependent_posts = array_unique( array_merge( array( $post_id ), $dependent_posts ) );
        }
        $dependent_posts = array_slice( $dependent_posts, 0, 100 );

        $dependent_posts_urls = array();
        foreach ( $dependent_posts as $dep_post_id ) {
            $dependent_posts_urls[ $dep_post_id ] = get_permalink( $dep_post_id );
        }

        $meta = array(
            'url' => $url,
            'post_id' => $post_id,
            'dependent_posts' => $dependent_posts,
            'dependent_posts_urls' => $dependent_posts_urls,
            'timestamp' => microtime( true ),
        );

        if ( false === file_put_contents( $meta_file, json_encode( $meta ) ) ) {
            return false;
        }

        $this->current_dependent_posts = array();

        return true;
    }

    /**
     * Delete the cache for a specific URL
     *
     * @param string $url URL
     * @return bool True on success
     */
    public function delete( string $url ): bool {
        $cache_key = $this->get_cache_key( $url );
        $cache_file = $this->get_cache_file_path( $cache_key );
        $meta_file = $this->get_meta_file_path( $cache_key );

        $success = true;

        if ( file_exists( $cache_file ) ) {
            $success = unlink( $cache_file ) && $success;
        }

        if ( file_exists( $meta_file ) ) {
            $success = unlink( $meta_file ) && $success;
        }

        return $success;
    }

    /**
     * Delete caches related to a post
     *
     * @param int $post_id Post ID
     * @return int Number of caches deleted
     */
    public function delete_by_post_id( int $post_id ): int {
        $deleted = 0;

        $meta_files = glob( $this->cache_dir . '/*.meta' );
        foreach ( $meta_files as $meta_file ) {
            $meta = json_decode( file_get_contents( $meta_file ), true );
            if ( $meta && isset( $meta['post_id'] ) && $meta['post_id'] == $post_id ) {
                $cache_key = basename( $meta_file, '.meta' );
                $cache_file = $this->get_cache_file_path( $cache_key );

                if ( file_exists( $cache_file ) ) {
                    unlink( $cache_file );
                }
                unlink( $meta_file );
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Clear all caches
     *
     * @return int Number of files deleted
     */
    public function clear_all(): int {
        $deleted = 0;

        $files = glob( $this->cache_dir . '/*' );
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file );
                $deleted++;
            }
        }

        // Mati integration: regenerate the seed (unless skipped by the filter)
        $this->trigger_mati_seed_regeneration();

        return $deleted;
    }

    private function remove_directory( string $dir ): void {
        $items = glob( $dir . '/{,.}[!.,!..]*', GLOB_BRACE );
        foreach ( $items as $item ) {
            if ( is_dir( $item ) ) {
                $this->remove_directory( $item );
            } else {
                unlink( $item );
            }
        }
        rmdir( $dir );
    }

    /**
     * Mati integration: regenerate the obfuscation seed
     *
     * Regenerating Mati's obfuscation seed when the cache is cleared
     * changes the obfuscation pattern of the generated static files
     */
    private function trigger_mati_seed_regeneration(): void {
        // Do nothing if skipped via the filter (prevents infinite loops)
        if ( apply_filters( 'cp_skip_mati_seed_regen', false ) ) {
            return;
        }

        if ( ! defined( 'MATI_VERSION' ) ) {
            return;
        }

        if ( ! class_exists( 'Mati_Settings' ) ) {
            return;
        }

        try {
            if ( ! method_exists( 'Mati_Settings', 'get_instance' ) ) {
                return;
            }

            $mati_settings = Mati_Settings::get_instance();

            if ( ! method_exists( $mati_settings, 'save_settings' ) ) {
                return;
            }

            if ( ! method_exists( $mati_settings, 'get_settings' ) ) {
                return;
            }

            $current_settings = $mati_settings->get_settings();

            // Regenerate the seed (skip_cp_clear option prevents infinite loops)
            $mati_settings->save_settings( $current_settings, array( 'skip_cp_clear' => true ) );

        } catch ( Exception $e ) {
            // Let the cache clear succeed even if an error occurs
        }
    }

    public function add_dependent_post( int $post_id ): void {
        if ( $post_id > 0 && ! in_array( $post_id, $this->current_dependent_posts ) ) {
            $this->current_dependent_posts[] = $post_id;
        }
    }

    public function get_dependent_posts(): array {
        return $this->current_dependent_posts;
    }

    public function clear_dependent_posts(): void {
        $this->current_dependent_posts = array();
    }

    /**
     * Clear caches related to a specific post
     *
     * @param int $post_id Post ID
     * @return int Number of cache files deleted
     */
    public function clear_by_post( int $post_id ): int {
        $deleted = 0;

        $meta_files = glob( $this->cache_dir . '/*.meta' );
        foreach ( $meta_files as $meta_file ) {
            $meta = json_decode( file_get_contents( $meta_file ), true );
            if ( ! is_array( $meta ) ) {
                continue;
            }

            if ( isset( $meta['dependent_posts'] ) && is_array( $meta['dependent_posts'] ) ) {
                if ( in_array( $post_id, $meta['dependent_posts'] ) ) {
                    unlink( $meta_file );
                    $deleted++;

                    $html_file = str_replace( '.meta', '.html', $meta_file );
                    if ( file_exists( $html_file ) ) {
                        unlink( $html_file );
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Clear caches of the previous/next posts when a post status changes
     *
     * @param int $post_id Post ID
     * @param string $old_status Status before the change
     * @param string $new_status Status after the change
     * @return int Number of cache files deleted
     */
    public function clear_adjacent_posts_cache( int $post_id, string $old_status, string $new_status ): int {
        $deleted = 0;

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'post' ) {
            return 0;
        }

        if ( $old_status === 'publish' && $new_status !== 'publish' ) {
            $meta_files = glob( $this->cache_dir . '/*.meta' );
            foreach ( $meta_files as $meta_file ) {
                $meta = json_decode( file_get_contents( $meta_file ), true );

                if ( ! is_array( $meta ) ) {
                    continue;
                }

                if ( isset( $meta['dependent_posts'] ) && is_array( $meta['dependent_posts'] ) ) {
                    if ( in_array( $post_id, $meta['dependent_posts'] ) ) {
                        unlink( $meta_file );
                        $deleted++;

                        $html_file = str_replace( '.meta', '.html', $meta_file );
                        if ( file_exists( $html_file ) ) {
                            unlink( $html_file );
                        }
                    }
                }
            }

            global $post;
            $backup_post = $post;
            $post = get_post( $post_id );
            setup_postdata( $post );
            $prev_post = get_adjacent_post( false, '', true, 'category' );
            if ( $prev_post ) {
                $deleted += $this->clear_by_post( $prev_post->ID );
            }
            $next_post = get_adjacent_post( false, '', false, 'category' );
            if ( $next_post ) {
                $deleted += $this->clear_by_post( $next_post->ID );
            }
            wp_reset_postdata();
            $post = $backup_post;
        }

        if ( $old_status !== 'publish' && $new_status === 'publish' ) {
            global $post;
            $backup_post = $post;
            $post = get_post( $post_id );
            setup_postdata( $post );
            $prev_post = get_adjacent_post( false, '', true, 'category' );
            if ( $prev_post ) {
                $deleted += $this->clear_by_post( $prev_post->ID );
            }
            $next_post = get_adjacent_post( false, '', false, 'category' );
            if ( $next_post ) {
                $deleted += $this->clear_by_post( $next_post->ID );
            }
            wp_reset_postdata();
            $post = $backup_post;
        }

        return $deleted;
    }

    /**
     * Get cache statistics
     *
     * @return array Cache statistics
     */
    public function get_stats(): array {
        $cache_files = glob( $this->cache_dir . '/*.html' );
        $total_size = 0;

        foreach ( $cache_files as $file ) {
            $total_size += filesize( $file );
        }

        return array(
            'count' => count( $cache_files ),
            'size' => $total_size,
            'size_formatted' => size_format( $total_size ),
        );
    }
}
