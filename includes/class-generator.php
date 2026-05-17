<?php
/**
 * 静的化生成クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_Generator {

    private CP_Logger $logger;
    private array $settings;
    private CP_Cache $cache;
    private string $temp_dir;
    private bool $debug_mode = false;

    /** URL→投稿IDマップ（高速化用） */
    private array $url_to_post_id_map = array();

    /** URL→依存投稿IDリストマップ（アーカイブページ用） */
    private array $url_to_dependent_posts_map = array();

    /** サイトマップ生成用 */
    private array $generated_html_pages = array();
    public function __construct() {
        $this->logger = CP_Logger::get_instance();
        $settings_manager = CP_Settings::get_instance();
        $this->settings = $settings_manager->get_settings();
        $this->cache = CP_Cache::get_instance();
        $this->temp_dir = sys_get_temp_dir() . '/sge-' . wp_generate_password( 12, false );

        if ( empty( $this->settings['commit_message'] ) ) {
            $this->settings['commit_message'] = 'update:' . current_time( 'Ymd_His' );
        }
    }

    public function generate(): void {
        try {
            $this->debug_mode = ! empty( $_GET['debugmode'] ) && $_GET['debugmode'] === 'on';

            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 0 );
            }

            set_transient( 'cp_manual_running', true, 3600 );
            delete_option( 'cp_error_notification' );

            $this->logger->start_timer();
            $this->logger->add_log( '静的化を開始します' );
            $this->logger->clear_progress();

            if ( is_dir( $this->temp_dir ) ) {
                $this->remove_directory( $this->temp_dir );
            }

            if ( ! mkdir( $this->temp_dir, 0700, true ) ) {
                $this->logger->add_log( '一時ディレクトリの作成に失敗しました', true );
                delete_transient( 'cp_manual_running' );
                delete_transient( 'cp_auto_running' );
                return;
            }

            $urls = $this->get_urls_to_generate();
            $total_urls = count( $urls );
            $this->logger->add_log( 'URL取得完了: ' . $total_urls . '件' );

            // ページ生成(0-80%) + アセット等(80-90%) + 出力処理(90-100%)
            $total_steps = 100;
            $current_step = 0;
            $page_step_ratio = 80.0 / max( 1, $total_urls );

            $generated_files = array();
            $cache_enabled = true;
            $cache_used_count = 0;
            $generated_count = 0;
            $use_parallel = ! empty( $this->settings['use_parallel_crawling'] );

            if ( $use_parallel && class_exists( 'CP_Parallel_Crawler' ) ) {
                $this->logger->add_log( '並列クローリングモードで処理を開始' );

                $parallel_crawler = new CP_Parallel_Crawler();
                $parallel_crawler->set_concurrency( 5 );
                $parallel_crawler->set_timeout( 30 );
                $parallel_crawler->set_url_to_dependent_posts_map( $this->url_to_dependent_posts_map );

                $batch_size = 10;
                $url_batches = array_chunk( $urls, $batch_size );

                $processed_urls = 0;
                foreach ( $url_batches as $batch_index => $batch_urls ) {
                    $batch_num = $batch_index + 1;
                    $total_batches = count( $url_batches );
                    $processed_urls += count( $batch_urls );
                    $current_step = (int) ( $processed_urls * $page_step_ratio );

                    $this->logger->update_progress(
                        $current_step,
                        $total_steps,
                        'ページを生成中: ' . $processed_urls . ' / ' . $total_urls
                    );

                    $results = $cache_enabled ?
                        $parallel_crawler->crawl_with_cache( $batch_urls ) :
                        $parallel_crawler->crawl_urls( $batch_urls );

                    foreach ( $results as $url => $result ) {
                        if ( $result['cached'] ) {
                            $cache_used_count++;
                        } else {
                            $generated_count++;
                        }

                        if ( $result['status_code'] == 200 && ! empty( $result['content'] ) ) {
                            $html = $result['content'];

                            $is_xml = ( str_contains( $url, '/feed' ) ||
                                       str_contains( $url, '/rss' ) ||
                                       str_contains( $url, '/atom' ) ||
                                       str_contains( $url, '.xml' ) ||
                                       str_starts_with( $html, '<?xml' ) );

                            if ( ! $is_xml ) {
                                if ( $this->settings['url_mode'] === 'absolute' ) {
                                    $html = $this->convert_to_absolute_urls( $html );
                                } else {
                                    $html = $this->convert_to_relative_urls( $html );
                                }

                                $html = $this->replace_custom_folder_names( $html );
                                $html = $this->sanitize_static_html( $html );
                                $html = $this->remove_archive_links( $html );
                            } else {
                                $html = $this->convert_xml_to_absolute_urls( $html );
                            }

                            $path = $this->url_to_path( $url );
                            $file_path = $this->temp_dir . '/' . $path;
                            $dir = dirname( $file_path );
                            if ( ! is_dir( $dir ) ) {
                                mkdir( $dir, 0755, true );
                            }
                            file_put_contents( $file_path, $html );

                            $generated_files[ $path ] = $html;

                            $parsed_url = wp_parse_url( $url );
                            $url_path = $parsed_url['path'] ?? '/';
                            $this->generated_html_pages[] = array(
                                'url' => $url_path,
                                'path' => $path,
                            );
                        }
                    }
                }
            } else {
                $progress_interval = max( 1, (int) ( $total_urls / 20 ) );

                foreach ( $urls as $index => $url ) {
                    if ( $index % $progress_interval === 0 || $index === $total_urls - 1 ) {
                        $current_step = (int) ( ( $index + 1 ) * $page_step_ratio );
                        $this->logger->update_progress( $current_step, $total_steps, 'ページを生成中: ' . ( $index + 1 ) . ' / ' . $total_urls );
                    }

                    $post_id = $this->url_to_post_id_map[ $url ] ?? 0;

                    if ( $post_id > 0 ) {
                        $this->cache->add_dependent_post( $post_id );
                    }

                    if ( isset( $this->url_to_dependent_posts_map[ $url ] ) ) {
                        foreach ( $this->url_to_dependent_posts_map[ $url ] as $dependent_id ) {
                            $this->cache->add_dependent_post( $dependent_id );
                        }
                    }

                    if ( $cache_enabled && $this->cache->is_valid( $url, $post_id ) ) {
                        $html = $this->cache->get( $url );
                        if ( $html !== false ) {
                            $path = $this->url_to_path( $url );
                            $file_path = $this->temp_dir . '/' . $path;
                            $dir = dirname( $file_path );
                            if ( ! is_dir( $dir ) ) {
                                mkdir( $dir, 0755, true );
                            }
                            file_put_contents( $file_path, $html );

                            $generated_files[ $path ] = $html;

                            $parsed_url = wp_parse_url( $url );
                            $url_path = $parsed_url['path'] ?? '/';
                            $this->generated_html_pages[] = array(
                                'url' => $url_path,
                                'path' => $path,
                            );
                            $cache_used_count++;
                            continue;
                        }
                    }

                    $result = $this->generate_html( $url );
                    if ( ! is_wp_error( $result ) ) {
                        $generated_files[ $result['path'] ] = $result['content'];
                        $generated_count++;

                        if ( $cache_enabled ) {
                            $this->cache->set( $url, $result['content'], $post_id ? $post_id : null );
                        }
                    }
                }
            }

            $this->logger->add_log( 'ページ生成完了: ' . $total_urls . '件（キャッシュ: ' . $cache_used_count . '件、新規: ' . $generated_count . '件）' );

            // アセット・追加ファイル・除外処理・サイトマップ (80-90%)
            $this->logger->add_log( 'アセットファイルをコピー中...' );
            $this->logger->update_progress( 81, $total_steps, 'アセットファイルをコピー中...' );
            $this->copy_assets();

            $this->logger->add_log( '追加ファイルをコピー中...' );
            $this->logger->update_progress( 84, $total_steps, '追加ファイルをコピー中...' );
            $this->copy_included_files();

            $this->logger->add_log( '除外パターンを処理中...' );
            $this->logger->update_progress( 87, $total_steps, '除外ファイルを削除中...' );
            $this->remove_excluded_files();

            $this->logger->add_log( 'サイトマップを生成中...' );
            $this->logger->update_progress( 89, $total_steps, 'サイトマップを生成中...' );
            $this->generate_sitemap();

            // 出力処理(90-100%)
            $output_count = 0;
            if ( ! empty( $this->settings['local_enabled'] ) ) {
                $output_count++;
            }
            if ( ! empty( $this->settings['github_enabled'] ) ) {
                $output_count++;
            }
            if ( ! empty( $this->settings['git_local_enabled'] ) ) {
                $output_count++;
            }
            if ( ! empty( $this->settings['zip_enabled'] ) ) {
                $output_count++;
            }
            if ( ! empty( $this->settings['cloudflare_enabled'] ) ) {
                $output_count++;
            }
            if ( ! empty( $this->settings['gitlab_enabled'] ) ) {
                $output_count++;
            }
            $output_step = 0;
            $output_progress_per_step = $output_count > 0 ? 10.0 / $output_count : 10;

            if ( ! empty( $this->settings['local_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'ローカルディレクトリに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'ローカルディレクトリに出力中...' );
                $this->output_to_local();
            }

            if ( ! empty( $this->settings['github_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'GitHubに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'GitHubに出力中...' );
                $this->output_to_github_api();
            }

            if ( ! empty( $this->settings['git_local_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'ローカルGitに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'ローカルGitに出力中...' );
                $this->output_to_git_local();
            }

            if ( ! empty( $this->settings['zip_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'ZIPファイルを作成中...' );
                $this->logger->update_progress( $progress, $total_steps, 'ZIPファイルを作成中...' );
                $this->output_to_zip();
            }

            if ( ! empty( $this->settings['cloudflare_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'Cloudflare Workersにデプロイ中...' );
                $this->logger->update_progress( $progress, $total_steps, 'Cloudflare Workersにデプロイ中...' );
                $this->output_to_cloudflare_workers();
            }

            if ( ! empty( $this->settings['gitlab_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'GitLabに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'GitLabに出力中...' );
                $this->output_to_gitlab_api();
            }

            if ( ! empty( $this->settings['netlify_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'Netlifyに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'Netlifyに出力中...' );
                $this->output_to_netlify();
            }

            $this->remove_directory( $this->temp_dir );

            $this->logger->update_progress( $total_steps, $total_steps, '静的化が完了しました！' );
            $this->logger->add_log( '静的化が完了しました' );

        } catch ( Exception $e ) {
            $this->logger->add_log( 'エラーが発生しました: ' . $e->getMessage(), true );
            $this->logger->update_progress( 0, 0, 'エラーが発生しました' );
        } finally {
            if ( is_dir( $this->temp_dir ) ) {
                $this->remove_directory( $this->temp_dir );
            }

            $logs = get_option( 'cp_logs', array() );
            $error_count = 0;
            foreach ( $logs as $log ) {
                if ( ! empty( $log['is_error'] ) ) {
                    $error_count++;
                }
            }

            if ( $error_count > 0 ) {
                update_option( 'cp_error_notification', array(
                    'count' => $error_count,
                    'timestamp' => current_time( 'mysql' ),
                ), false );
            }

            delete_transient( 'cp_manual_running' );
            delete_transient( 'cp_auto_running' );
        }
    }

    private function get_urls_to_generate(): array {
        $urls = array();
        $posts_per_page = (int) get_option( 'posts_per_page' );
        $home_url = home_url( '/' );

        $this->logger->update_progress( 0, 100, 'URLを収集中: 投稿を取得中...' );

        $urls[] = $home_url;
        $post_count = wp_count_posts( 'post' )->publish;
        $max_pages = ceil( $post_count / $posts_per_page );

        for ( $page = 1; $page <= $max_pages; $page++ ) {
            $page_url = ( $page === 1 ) ? $home_url : $home_url . 'page/' . $page . '/';
            if ( $page > 1 ) {
                $urls[] = $page_url;
            }

            $page_posts = get_posts( array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => $posts_per_page,
                'paged'          => $page,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'suppress_filters' => true,
            ) );

            if ( ! empty( $page_posts ) ) {
                $this->url_to_dependent_posts_map[ $page_url ] = $page_posts;
            }
        }

        $public_post_types = get_post_types( array( 'public' => true ) );
        $all_posts = get_posts( array(
            'post_type'      => array_values( $public_post_types ),
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'cache_results'  => true,
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ) );

        if ( ! empty( $all_posts ) ) {
            $post_ids = wp_list_pluck( $all_posts, 'ID' );
            _prime_post_caches( $post_ids, true, true );

            foreach ( $all_posts as $post ) {
                $permalink = get_permalink( $post->ID );
                $urls[] = $permalink;
                $this->url_to_post_id_map[ $permalink ] = $post->ID;

                if ( $post->post_type === 'post' ) {
                    $dependent_ids = array( $post->ID );

                    setup_postdata( $post );
                    $prev_post = get_adjacent_post( false, '', true, 'category' );
                    if ( $prev_post ) {
                        $dependent_ids[] = $prev_post->ID;
                    }
                    $next_post = get_adjacent_post( false, '', false, 'category' );
                    if ( $next_post ) {
                        $dependent_ids[] = $next_post->ID;
                    }

                    $this->url_to_dependent_posts_map[ $permalink ] = $dependent_ids;
                }
            }
            wp_reset_postdata();
        }

        $custom_post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
        foreach ( $custom_post_types as $post_type ) {
            $post_type_obj = get_post_type_object( $post_type );
            if ( $post_type_obj->has_archive ) {
                $urls[] = get_post_type_archive_link( $post_type );
            }
        }

        $this->logger->update_progress( 0, 100, 'URLを収集中: カテゴリを取得中...' );

        $categories = get_categories( array( 'hide_empty' => true ) );
        if ( ! empty( $categories ) ) {
            $category_ids = wp_list_pluck( $categories, 'term_id' );
            _prime_term_caches( $category_ids, false );

            foreach ( $categories as $category ) {
                $category_link = get_category_link( $category->term_id );
                $urls[] = $category_link;

                $max_pages = ceil( $category->count / $posts_per_page );
                for ( $page = 1; $page <= $max_pages; $page++ ) {
                    $page_url = ( $page === 1 ) ? $category_link : $category_link . 'page/' . $page . '/';
                    if ( $page > 1 ) {
                        $urls[] = $page_url;
                    }

                    $category_posts = get_posts( array(
                        'category'       => $category->term_id,
                        'post_status'    => 'publish',
                        'posts_per_page' => $posts_per_page,
                        'paged'          => $page,
                        'fields'         => 'ids',
                        'no_found_rows'  => true,
                        'suppress_filters' => true,
                    ) );

                    if ( ! empty( $category_posts ) ) {
                        $this->url_to_dependent_posts_map[ $page_url ] = $category_posts;
                    }
                }
            }
        }

        $this->logger->update_progress( 0, 100, 'URLを収集中: タグを取得中...' );

        if ( ! empty( $this->settings['enable_tag_archive'] ) ) {
            $tags = get_tags( array( 'hide_empty' => true ) );
            if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
                $tag_ids = wp_list_pluck( $tags, 'term_id' );
                _prime_term_caches( $tag_ids, false );

                foreach ( $tags as $tag ) {
                    $tag_link = get_tag_link( $tag->term_id );
                    $urls[] = $tag_link;

                    $max_pages = ceil( $tag->count / $posts_per_page );
                    for ( $i = 2; $i <= $max_pages; $i++ ) {
                        $urls[] = $tag_link . 'page/' . $i . '/';
                    }
                }
            }
        }

        $this->logger->update_progress( 0, 100, 'URLを収集中: アーカイブを取得中...' );

        if ( ! empty( $this->settings['enable_date_archive'] ) ) {
            $archive_dates = $this->get_all_archive_dates();
            foreach ( $archive_dates as $date ) {
                $urls[] = get_year_link( $date['year'] );
                $urls[] = get_month_link( $date['year'], $date['month'] );
                $urls[] = get_day_link( $date['year'], $date['month'], $date['day'] );
            }
        }

        $taxonomies = get_taxonomies( array( 'public' => true, '_builtin' => false ) );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $term_link = get_term_link( $term );
                    if ( ! is_wp_error( $term_link ) ) {
                        $urls[] = $term_link;
                    }
                }
            }
        }

        if ( ! empty( $this->settings['enable_post_format_archive'] ) ) {
            $post_formats = get_terms( array(
                'taxonomy'   => 'post_format',
                'hide_empty' => true,
            ) );
            if ( ! is_wp_error( $post_formats ) && ! empty( $post_formats ) ) {
                foreach ( $post_formats as $format ) {
                    $format_link = get_term_link( $format );
                    if ( ! is_wp_error( $format_link ) ) {
                        $urls[] = $format_link;

                        $max_pages = ceil( $format->count / $posts_per_page );
                        for ( $i = 2; $i <= $max_pages; $i++ ) {
                            $urls[] = $format_link . 'page/' . $i . '/';
                        }
                    }
                }
            }
        }

        if ( ! empty( $this->settings['enable_author_archive'] ) ) {
            $users = get_users( array( 'has_published_posts' => true ) );
            foreach ( $users as $user ) {
                $author_link = get_author_posts_url( $user->ID );
                $urls[] = $author_link;

                $user_post_count = count_user_posts( $user->ID );
                $max_pages = ceil( $user_post_count / $posts_per_page );
                for ( $i = 2; $i <= $max_pages; $i++ ) {
                    $urls[] = $author_link . 'page/' . $i . '/';
                }
            }
        }

        $this->logger->update_progress( 0, 100, 'URLを収集中: フィード・サイトマップを取得中...' );

        if ( ! empty( $this->settings['enable_rss'] ) ) {
            $urls[] = home_url( '/feed/' );
            $urls[] = home_url( '/feed/rss/' );
            $urls[] = home_url( '/feed/rss2/' );
            $urls[] = home_url( '/feed/atom/' );
            $urls[] = home_url( '/comments/feed/' );
        }

        return array_unique( $urls );
    }

    /**
     * 全日付アーカイブを1回のクエリで取得
     *
     * @return array 日付の配列（year, month, day）
     */
    private function get_all_archive_dates(): array {
        global $wpdb;
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT
                    YEAR(post_date) as year,
                    MONTH(post_date) as month,
                    DAY(post_date) as day
                FROM {$wpdb->posts}
                WHERE post_status = %s AND post_type = %s
                ORDER BY post_date DESC",
                'publish',
                'post'
            ),
            ARRAY_A
        );
        return $results ? $results : array();
    }

    private function generate_html( string $url ): array|\WP_Error {
        // localhostの場合のみSSL検証を無効化（開発環境対応）
        $parsed_url = parse_url( $url );
        $is_localhost = in_array( $parsed_url['host'], array( 'localhost', '127.0.0.1', '::1' ) );

        $response = wp_remote_get( $url, array(
            'timeout' => $this->settings['timeout'] ?? 300,
            'sslverify' => ! $is_localhost,
            'headers' => array(
                'User-Agent' => 'Carry Pod/1.0',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->logger->add_log( "ページの生成に失敗しました: {$url} - " . $response->get_error_message(), true );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            $this->logger->add_log( "HTTPエラー {$status_code}: {$url}", true );
            return new WP_Error( 'http_error', "HTTP {$status_code}" );
        }

        $html = wp_remote_retrieve_body( $response );

        if ( empty( $html ) ) {
            $this->logger->add_log( "空のレスポンス: {$url}", true );
            return new WP_Error( 'empty_response', '空のレスポンス' );
        }

        $original_size = strlen( $html );
        $this->logger->debug( "取得: {$url} - " . number_format( $original_size ) . " bytes" );

        $is_xml = ( str_contains( $url, '/feed' ) ||
                   str_contains( $url, '/rss' ) ||
                   str_contains( $url, '/atom' ) ||
                   str_contains( $url, '.xml' ) ||
                   str_starts_with( $html, '<?xml' ) );

        if ( ! $is_xml ) {
            if ( $this->settings['url_mode'] === 'absolute' ) {
                $html = $this->convert_to_absolute_urls( $html );
            } else {
                $html = $this->convert_to_relative_urls( $html );
            }

            $html = $this->replace_custom_folder_names( $html );
            $html = $this->sanitize_static_html( $html );
            $html = $this->remove_archive_links( $html );

            // 90%以上削減された場合は警告
            $processed_size = strlen( $html );
            if ( $processed_size < $original_size * 0.1 ) {
                $this->logger->add_log(
                    "警告: HTMLサイズが大幅に減少: {$url} - " .
                    number_format( $original_size ) . " → " .
                    number_format( $processed_size ) . " バイト",
                    true
                );
            }

            if ( ! str_contains( $html, '</html>' ) || ! str_contains( $html, '<body' ) ) {
                $this->logger->add_log( "警告: 不完全なHTML構造: {$url}", true );
            }

        } else {
            $this->logger->debug( "XMLファイル取得: {$url}" );
            $html = $this->convert_xml_to_absolute_urls( $html );
        }

        $html = $this->minify_html( $html );

        $html = $this->minify_inline_css( $html );

        $path = $this->url_to_path( $url );
        $file_path = $this->temp_dir . '/' . $path;
        $dir = dirname( $file_path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        file_put_contents( $file_path, $html );

        $parsed_url = wp_parse_url( $url );
        $url_path = $parsed_url['path'] ?? '/';
        $this->generated_html_pages[] = array(
            'url' => $url_path,
            'path' => $path,
        );

        return array( 'path' => $path, 'content' => $html );
    }

    private function url_to_path( string $url ): string {
        $parsed = parse_url( $url );
        $path = rtrim( $parsed['path'] ?? '/', '/' );

        if ( empty( $path ) || $path === '/' ) {
            return 'index.html';
        }

        if ( preg_match( '#/feed(/.*)?$#', $path ) || preg_match( '#/rss(/.*)?$#', $path ) || preg_match( '#/atom(/.*)?$#', $path ) ) {
            $path .= '/index.xml';
        } elseif ( ! preg_match( '/\.[a-z0-9]+$/i', $path ) ) {
            $path .= '/index.html';
        }

        $path = ltrim( $path, '/' );

        return $path;
    }

    private function convert_to_relative_urls( string $html ): string {
        $site_url_with_slash = trailingslashit( get_site_url() );
        $site_url_no_slash = untrailingslashit( get_site_url() );
        $home_url_with_slash = trailingslashit( get_home_url() );
        $home_url_no_slash = untrailingslashit( get_home_url() );

        $site_url_https_slash = str_replace( 'http://', 'https://', $site_url_with_slash );
        $site_url_http_slash = str_replace( 'https://', 'http://', $site_url_with_slash );
        $home_url_https_slash = str_replace( 'http://', 'https://', $home_url_with_slash );
        $home_url_http_slash = str_replace( 'https://', 'http://', $home_url_with_slash );

        $site_url_https_no_slash = str_replace( 'http://', 'https://', $site_url_no_slash );
        $site_url_http_no_slash = str_replace( 'https://', 'http://', $site_url_no_slash );
        $home_url_https_no_slash = str_replace( 'http://', 'https://', $home_url_no_slash );
        $home_url_http_no_slash = str_replace( 'https://', 'http://', $home_url_no_slash );

        $escaped_urls = array(
            str_replace( '/', '\\/', $site_url_https_slash ) => '\\/',
            str_replace( '/', '\\/', $site_url_http_slash ) => '\\/',
            str_replace( '/', '\\/', $home_url_https_slash ) => '\\/',
            str_replace( '/', '\\/', $home_url_http_slash ) => '\\/',
            str_replace( '/', '\\/', $site_url_https_no_slash ) => '',
            str_replace( '/', '\\/', $site_url_http_no_slash ) => '',
            str_replace( '/', '\\/', $home_url_https_no_slash ) => '',
            str_replace( '/', '\\/', $home_url_http_no_slash ) => '',
        );

        foreach ( $escaped_urls as $escaped_url => $replacement ) {
            $html = str_replace( $escaped_url, $replacement, $html );
        }

        $html = preg_replace_callback(
            '/<style([^>]*)>(.*?)<\/style>/is',
            function( $matches ) use ( $site_url_https_slash, $site_url_http_slash, $home_url_https_slash, $home_url_http_slash, $site_url_https_no_slash, $site_url_http_no_slash, $home_url_https_no_slash, $home_url_http_no_slash ) {
                $attributes = $matches[1];
                $css = $matches[2];

                $css = preg_replace_callback(
                    '/url\s*\(\s*([\'"]?)([^\'"\)]+)\1\s*\)/i',
                    function( $url_matches ) use ( $site_url_https_slash, $site_url_http_slash, $home_url_https_slash, $home_url_http_slash, $site_url_https_no_slash, $site_url_http_no_slash, $home_url_https_no_slash, $home_url_http_no_slash ) {
                        $quote = $url_matches[1];
                        $url = $url_matches[2];

                        if ( str_starts_with( $url, 'data:' ) || str_starts_with( $url, '#' ) ) {
                            return $url_matches[0];
                        }

                        $url = str_replace(
                            array( $site_url_https_slash, $site_url_http_slash, $home_url_https_slash, $home_url_http_slash ),
                            '/',
                            $url
                        );
                        $url = str_replace(
                            array( $site_url_https_no_slash, $site_url_http_no_slash, $home_url_https_no_slash, $home_url_http_no_slash ),
                            '',
                            $url
                        );

                        if ( ! str_starts_with( $url, '/' ) && ! str_starts_with( $url, 'http' ) ) {
                            $url = '/' . $url;
                        }

                        return 'url(' . $quote . $url . $quote . ')';
                    },
                    $css
                );

                return '<style' . $attributes . '>' . $css . '</style>';
            },
            $html
        );

        $html = str_replace( $site_url_https_slash, '/', $html );
        $html = str_replace( $site_url_http_slash, '/', $html );
        $html = str_replace( $home_url_https_slash, '/', $html );
        $html = str_replace( $home_url_http_slash, '/', $html );

        $html = str_replace( $site_url_https_no_slash . '/', '/', $html );
        $html = str_replace( $site_url_http_no_slash . '/', '/', $html );
        $html = str_replace( $home_url_https_no_slash . '/', '/', $html );
        $html = str_replace( $home_url_http_no_slash . '/', '/', $html );

        // 連続スラッシュを1つに（http://やhttps://等は除く）
        $result = preg_replace( '#(?<!:)(?<!["\'])//+(?![/#\s])#', '/', $html );
        if ( $result !== null ) {
            $html = $result;
        }

        return $html;
    }

    /**
     * HTML内の相対URLを絶対URLに変換
     *
     * @param string $html HTML内容
     * @return string 変換後のHTML
     */
    private function convert_to_absolute_urls( string $html ): string {
        $base_url = ! empty( $this->settings['base_url'] ) ? $this->settings['base_url'] : untrailingslashit( get_site_url() );

        $html = $this->convert_to_relative_urls( $html );

        $html = preg_replace(
            '/(href|src|srcset|data-src|data-srcset|poster|content)=(["\'])\/([^"\']*)\2/i',
            '$1=$2' . $base_url . '/$3$2',
            $html
        );

        $html = preg_replace_callback(
            '/<style([^>]*)>(.*?)<\/style>/is',
            function( $matches ) use ( $base_url ) {
                $attributes = $matches[1];
                $css = $matches[2];

                $css = preg_replace(
                    '/url\s*\(\s*([\'"]?)\/([^\'"\)]+)\1\s*\)/i',
                    'url($1' . $base_url . '/$2$1)',
                    $css
                );

                return '<style' . $attributes . '>' . $css . '</style>';
            },
            $html
        );

        $html = str_replace( '\\/"', '\\"' . $base_url . '/"', $html );
        $html = str_replace( '\\"/', '\\"' . $base_url . '/', $html );

        return $html;
    }

    /**
     * XML内のURLを絶対URLに変換
     *
     * @param string $xml XML内容
     * @return string 変換後のXML
     */
    private function convert_xml_to_absolute_urls( string $xml ): string {
        $base_url = ! empty( $this->settings['base_url'] ) ? $this->settings['base_url'] : untrailingslashit( get_site_url() );

        $site_url = trailingslashit( get_site_url() );
        $home_url = trailingslashit( get_home_url() );

        $site_url_http = str_replace( 'https://', 'http://', $site_url );
        $site_url_https = str_replace( 'http://', 'https://', $site_url );
        $home_url_http = str_replace( 'https://', 'http://', $home_url );
        $home_url_https = str_replace( 'http://', 'https://', $home_url );

        $xml = str_replace(
            array( $site_url_https, $site_url_http, $home_url_https, $home_url_http ),
            trailingslashit( $base_url ),
            $xml
        );

        $xml = preg_replace(
            '/(<link>|<url>|<loc>|<guid[^>]*>|href=["\'])\/([^<"\']+)/i',
            '$1' . $base_url . '/$2',
            $xml
        );

        return $xml;
    }

    /**
     * カスタムフォルダ名に置換
     *
     * @param string $html HTML
     * @return string 置換後のHTML
     */
    private function replace_custom_folder_names( string $html ): string {
        if ( ! empty( $this->settings['custom_wp_includes'] ) ) {
            $custom_includes = $this->settings['custom_wp_includes'];
            $html = preg_replace(
                '#/wp-includes/#i',
                '/' . $custom_includes . '/',
                $html
            );
        }

        if ( ! empty( $this->settings['custom_wp_content'] ) ) {
            $custom_content = $this->settings['custom_wp_content'];
            $html = preg_replace(
                '#/wp-content/#i',
                '/' . $custom_content . '/',
                $html
            );
        }

        return $html;
    }

    /**
     * HTML内の動的要素を静的化用に処理
     *
     * @param string $html HTML内容
     * @return string 処理後のHTML
     */
    private function sanitize_static_html( string $html ): string {
        $html = preg_replace( '/<input[^>]*name=[\'"]_wpnonce[\'"][^>]*>/i', '', $html );
        $html = preg_replace( '/<input[^>]*name=[\'"]_wp_http_referer[\'"][^>]*>/i', '', $html );

        $html = preg_replace( '/<link[^>]*rel=[\'"]https:\/\/api\.w\.org[\'"][^>]*>/i', '', $html );

        $html = preg_replace( '/<link[^>]*type=[\'"]application\/json\+oembed[\'"][^>]*>/i', '', $html );
        $html = preg_replace( '/<link[^>]*type=[\'"]text\/xml\+oembed[\'"][^>]*>/i', '', $html );

        return $html;
    }

    /**
     * アーカイブリンクを除去・変更
     *
     * @param string $html HTML内容
     * @return string 変換後のHTML
     */
    private function remove_archive_links( string $html ): string {
        if ( empty( $this->settings['enable_tag_archive'] ) ) {
            $html = preg_replace( '/<a\s+[^>]*?rel=[\'"]tag[\'"][^>]*?>.*?<\/a>/is', '', $html );
            $html = preg_replace( '/<(div|span|ul)[^>]*?class=[\'"][^"\']*\b(tags?|post-tags?|entry-tags?)\b[^"\']*[\'"][^>]*?>.*?<\/\1>/is', '', $html );
        }

        if ( empty( $this->settings['enable_date_archive'] ) ) {
            $html = preg_replace_callback(
                '/<a\s+([^>]*?)href=[\'"]([^"\']*?\d{4}\/\d{2}(?:\/\d{2})?\/)[\'"]([^>]*?)>(.*?)<\/a>/is',
                function( $matches ) {
                    return '<span>' . $matches[4] . '</span>';
                },
                $html
            );
            $html = preg_replace_callback(
                '/(<(li|div|span|time)[^>]*?class=[\'"][^"\']*\b(post-date|posted-on|entry-date)\b[^"\']*[\'"][^>]*?>)(.*?)(<\/\2>)/is',
                function( $matches ) {
                    $inner = preg_replace( '/<a\s+[^>]*?>(.*?)<\/a>/is', '<span>$1</span>', $matches[4] );
                    return $matches[1] . $inner . $matches[5];
                },
                $html
            );
        }

        if ( empty( $this->settings['enable_author_archive'] ) ) {
            $html = preg_replace_callback(
                '/<a\s+([^>]*?)href=[\'"]([^"\']*?\/author\/[^"\']+)[\'"]([^>]*?)>(.*?)<\/a>/is',
                function( $matches ) {
                    return '<span>' . $matches[4] . '</span>';
                },
                $html
            );
            $html = preg_replace_callback(
                '/<a\s+([^>]*?)rel=[\'"]author[\'"]([^>]*?)>(.*?)<\/a>/is',
                function( $matches ) {
                    return '<span>' . $matches[3] . '</span>';
                },
                $html
            );
            $html = preg_replace_callback(
                '/(<(li|div|span)[^>]*?class=[\'"][^"\']*\b(post-author|byline|entry-author|posted-by|vcard)\b[^"\']*[\'"][^>]*?>)(.*?)(<\/\2>)/is',
                function( $matches ) {
                    $inner = preg_replace( '/<a\s+[^>]*?>(.*?)<\/a>/is', '<span>$1</span>', $matches[4] );
                    return $matches[1] . $inner . $matches[5];
                },
                $html
            );
        }

        if ( empty( $this->settings['enable_post_format_archive'] ) ) {
            $html = preg_replace( '/<a\s+[^>]*?href=[\'"][^"\']*?\/type\/[^"\']*[\'"][^>]*?>.*?<\/a>/is', '', $html );
        }

        return $html;
    }

    /**
     * CSS/JSファイル内のURLを変換
     *
     * @param string $content ファイル内容
     * @param string $type ファイルタイプ (css または js)
     * @return string 変換後の内容
     */
    private function convert_asset_urls( string $content, string $type ): string {
        $site_url = trailingslashit( get_site_url() );
        $home_url = trailingslashit( get_home_url() );

        $site_url_http = str_replace( 'https://', 'http://', $site_url );
        $site_url_https = str_replace( 'http://', 'https://', $site_url );
        $home_url_http = str_replace( 'https://', 'http://', $home_url );
        $home_url_https = str_replace( 'http://', 'https://', $home_url );

        if ( $type === 'css' ) {
            $content = preg_replace_callback(
                '/url\s*\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i',
                function( $matches ) use ( $site_url_https, $site_url_http, $home_url_https, $home_url_http ) {
                    $url = $matches[1];

                    $url = str_replace( $site_url_https, '/', $url );
                    $url = str_replace( $site_url_http, '/', $url );
                    $url = str_replace( $home_url_https, '/', $url );
                    $url = str_replace( $home_url_http, '/', $url );

                    return 'url(' . $url . ')';
                },
                $content
            );

            $content = preg_replace_callback(
                '/@import\s+[\'"]([^\'"\)]+)[\'"]/i',
                function( $matches ) use ( $site_url_https, $site_url_http, $home_url_https, $home_url_http ) {
                    $url = $matches[1];

                    $url = str_replace( $site_url_https, '/', $url );
                    $url = str_replace( $site_url_http, '/', $url );
                    $url = str_replace( $home_url_https, '/', $url );
                    $url = str_replace( $home_url_http, '/', $url );

                    return '@import "' . $url . '"';
                },
                $content
            );
        }

        $content = str_replace( $site_url_https, '/', $content );
        $content = str_replace( $site_url_http, '/', $content );
        $content = str_replace( $home_url_https, '/', $content );
        $content = str_replace( $home_url_http, '/', $content );

        if ( $type === 'js' ) {
            $content = str_replace( '/wp-admin/admin-ajax.php', '#', $content );
            $content = preg_replace( '/\/wp-json\/[^\'"\s]*/i', '#', $content );
        }

        return $content;
    }

    /**
     * アセットファイルをコピー
     */
    private function copy_assets(): bool {
        $success = true;

        $copied_dirs = array();
        $error_dirs = array();

        $wp_content_dir = WP_CONTENT_DIR;
        $content_dirname = ! empty( $this->settings['custom_wp_content'] ) ? $this->settings['custom_wp_content'] : 'wp-content';
        $wp_content_dest = $this->temp_dir . '/' . $content_dirname;

        if ( ! is_dir( $wp_content_dir ) ) {
            $this->logger->add_log( 'wp-content ディレクトリが見つかりません', true );
            return false;
        }

        if ( ! is_dir( $wp_content_dest ) ) {
            mkdir( $wp_content_dest, 0755, true );
        }

        $themes_copied = $this->copy_active_themes( $wp_content_dest );
        if ( $themes_copied ) {
            $copied_dirs[] = 'themes';
        } else {
            $error_dirs[] = 'themes';
        }

        $uploads_copied = $this->copy_referenced_uploads( $wp_content_dest );
        if ( $uploads_copied ) {
            $copied_dirs[] = 'uploads';
        } else {
            $error_dirs[] = 'uploads';
        }

        $plugins_copied = $this->copy_active_plugin_assets( $wp_content_dest );
        if ( $plugins_copied ) {
            $copied_dirs[] = 'plugins';
        } else {
            $error_dirs[] = 'plugins';
        }

        $other_dirs = array( 'cache', 'fonts', 'w3tc-config' );
        foreach ( $other_dirs as $dir ) {
            $src = $wp_content_dir . '/' . $dir;
            if ( is_dir( $src ) ) {
                $result = $this->copy_directory_recursive( $src, $wp_content_dest . '/' . $dir, false );
                if ( $result ) {
                    $copied_dirs[] = $dir;
                }
            }
        }

        $wp_includes_dir = ABSPATH . 'wp-includes';
        if ( is_dir( $wp_includes_dir ) ) {
            $includes_dirname = ! empty( $this->settings['custom_wp_includes'] ) ? $this->settings['custom_wp_includes'] : 'wp-includes';
            $result = $this->copy_referenced_wp_includes( $wp_includes_dir, $this->temp_dir . '/' . $includes_dirname );
            if ( $result ) {
                $copied_dirs[] = $includes_dirname . ' (参照ファイルのみ)';
            } else {
                $error_dirs[] = $includes_dirname;
                $success = false;
            }
        } else {
            $this->logger->add_log( 'wp-includes ディレクトリが見つかりません', true );
        }

        $type_dir = ABSPATH . 'type';
        if ( is_dir( $type_dir ) ) {
            $result = $this->copy_directory_recursive( $type_dir, $this->temp_dir . '/type', false );
            if ( $result ) {
                $copied_dirs[] = 'type';
            } else {
                $error_dirs[] = 'type';
                $success = false;
            }
        }

        $custom_post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
        foreach ( $custom_post_types as $post_type ) {
            $custom_dir = ABSPATH . $post_type;
            if ( is_dir( $custom_dir ) ) {
                $result = $this->copy_directory_recursive( $custom_dir, $this->temp_dir . '/' . $post_type, false );
                if ( $result ) {
                    $copied_dirs[] = $post_type;
                } else {
                    $error_dirs[] = $post_type;
                    $success = false;
                }
            }
        }

        $this->copy_favicon();

        if ( ! empty( $this->settings['enable_robots_txt'] ) ) {
            $this->generate_robots_txt();
        }

        if ( ! empty( $this->settings['enable_llms_txt'] ) ) {
            $this->generate_llms_txt();
        }

        // _headersファイルを生成（Mati連携）
        if ( ! empty( $this->settings['generate_mati_headers'] ) ) {
            $this->generate_mati_headers();
        }

        // .well-known/atproto-did を生成（Mati連携）
        $this->generate_atproto_did();

        if ( ! empty( $copied_dirs ) ) {
            $this->logger->add_log( 'アセットコピー完了: ' . implode( ', ', $copied_dirs ) );
        }
        if ( ! empty( $error_dirs ) ) {
            $this->logger->add_log( 'アセットコピーでエラー: ' . implode( ', ', $error_dirs ), true );
        }

        return $success;
    }

    /**
     * wp-includes から参照されているファイルのみをコピー
     *
     * @param string $source_dir ソースディレクトリ (ABSPATH/wp-includes)
     * @param string $dest_dir   コピー先ディレクトリ
     * @return bool 成功したかどうか
     */
    private function copy_referenced_wp_includes( string $source_dir, string $dest_dir ): bool {
        $referenced_files = $this->collect_wp_includes_references();

        if ( empty( $referenced_files ) ) {
            $this->logger->debug( 'wp-includes への参照が見つかりませんでした' );
            return true;
        }

        $this->logger->debug( 'wp-includes 参照ファイル: ' . count( $referenced_files ) . '件検出' );

        $copied_count = 0;
        $error_count = 0;

        foreach ( $referenced_files as $relative_path ) {
            $source_file = $source_dir . '/' . $relative_path;
            $dest_file = $dest_dir . '/' . $relative_path;

            if ( ! file_exists( $source_file ) ) {
                continue;
            }

            $dest_dir_path = dirname( $dest_file );
            if ( ! is_dir( $dest_dir_path ) ) {
                if ( ! wp_mkdir_p( $dest_dir_path ) ) {
                    $error_count++;
                    continue;
                }
            }

            if ( copy( $source_file, $dest_file ) ) {
                $copied_count++;
            } else {
                $error_count++;
            }
        }

        $this->logger->debug( 'wp-includes コピー完了: ' . $copied_count . '件' . ( $error_count > 0 ? '、エラー: ' . $error_count . '件' : '' ) );

        return $error_count === 0;
    }

    /**
     * 生成済みHTMLとアセットからwp-includesへの参照を収集
     *
     * @return array wp-includes内の相対パスの配列
     */
    private function collect_wp_includes_references(): array {
        $references = array();

        $html_files = $this->find_files_recursive( $this->temp_dir, array( 'html', 'htm' ) );
        foreach ( $html_files as $html_file ) {
            $content = file_get_contents( $html_file );
            if ( $content === false ) {
                continue;
            }
            $refs = $this->extract_wp_includes_refs_from_html( $content );
            $references = array_merge( $references, $refs );
        }

        $processed = array();
        $to_process = $references;

        while ( ! empty( $to_process ) ) {
            $current = array_shift( $to_process );

            if ( isset( $processed[ $current ] ) ) {
                continue;
            }
            $processed[ $current ] = true;

            $ext = strtolower( pathinfo( $current, PATHINFO_EXTENSION ) );
            if ( in_array( $ext, array( 'js', 'css' ), true ) ) {
                $file_path = ABSPATH . 'wp-includes/' . $current;
                if ( file_exists( $file_path ) ) {
                    $content = file_get_contents( $file_path );
                    if ( $content !== false ) {
                        $deps = $this->extract_deps_from_asset( $content, $current, $ext );
                        foreach ( $deps as $dep ) {
                            if ( ! isset( $processed[ $dep ] ) ) {
                                $to_process[] = $dep;
                                $references[] = $dep;
                            }
                        }
                    }
                }
            }
        }

        $references = array_unique( $references );
        sort( $references );

        return $references;
    }

    /**
     * HTMLコンテンツからwp-includesへの参照を抽出
     *
     * @param string $content HTMLコンテンツ
     * @return array wp-includes内の相対パスの配列
     */
    private function extract_wp_includes_refs_from_html( string $content ): array {
        $refs = array();

        if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/', $content, $matches ) ) {
            foreach ( $matches[1] as $src ) {
                $ref = $this->parse_wp_includes_path( $src );
                if ( $ref ) {
                    $refs[] = $ref;
                }
            }
        }

        if ( preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\']/', $content, $matches ) ) {
            foreach ( $matches[1] as $href ) {
                $ref = $this->parse_wp_includes_path( $href );
                if ( $ref ) {
                    $refs[] = $ref;
                }
            }
        }

        if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches ) ) {
            foreach ( $matches[1] as $src ) {
                $ref = $this->parse_wp_includes_path( $src );
                if ( $ref ) {
                    $refs[] = $ref;
                }
            }
        }

        if ( preg_match_all( '/<script[^>]*type=["\']application\/json["\'][^>]*>(.*?)<\/script>/s', $content, $json_matches ) ) {
            foreach ( $json_matches[1] as $json_content ) {
                $decoded = json_decode( $json_content, true );
                if ( $decoded ) {
                    $this->extract_wp_includes_from_array( $decoded, $refs );
                }
            }
        }

        return $refs;
    }

    /**
     * 配列から再帰的にwp-includesパスを抽出
     *
     * @param array $data 検索対象の配列
     * @param array &$refs 参照の格納先（参照渡し）
     */
    private function extract_wp_includes_from_array( array $data, array &$refs ): void {
        foreach ( $data as $value ) {
            if ( is_string( $value ) ) {
                $ref = $this->parse_wp_includes_path( $value );
                if ( $ref ) {
                    $refs[] = $ref;
                }
            } elseif ( is_array( $value ) ) {
                $this->extract_wp_includes_from_array( $value, $refs );
            }
        }
    }

    /**
     * URLからwp-includes内の相対パスを抽出
     *
     * @param string $url URL または相対パス
     * @return string|false wp-includes内の相対パス、またはfalse
     */
    private function parse_wp_includes_path( string $url ): string|false {
        $url = strtok( $url, '?' );

        $includes_dirname = ! empty( $this->settings['custom_wp_includes'] ) ? $this->settings['custom_wp_includes'] : 'wp-includes';

        $folder_pattern = preg_quote( $includes_dirname, '#' );
        if ( ! str_contains( $url, $includes_dirname . '/' ) && ! str_contains( $url, 'wp-includes/' ) ) {
            return false;
        }

        if ( preg_match( '#(?:' . $folder_pattern . '|wp-includes)/(.+)$#', $url, $matches ) ) {
            $path = $matches[1];
            // セキュリティ: ディレクトリトラバーサルを防止
            if ( str_contains( $path, '..' ) ) {
                return false;
            }
            return $path;
        }

        return false;
    }

    /**
     * JS/CSSファイルから依存ファイルを抽出
     *
     * @param string $content ファイル内容
     * @param string $current_path 現在のファイルの相対パス (wp-includes内)
     * @param string $ext ファイル拡張子 (js/css)
     * @return array wp-includes内の相対パスの配列
     */
    private function extract_deps_from_asset( string $content, string $current_path, string $ext ): array {
        $deps = array();
        $current_dir = dirname( $current_path );

        if ( $ext === 'css' ) {
            if ( preg_match_all( '/url\s*\(\s*["\']?([^"\')]+)["\']?\s*\)/', $content, $matches ) ) {
                foreach ( $matches[1] as $url ) {
                    $dep = $this->resolve_relative_path( $url, $current_dir );
                    if ( $dep ) {
                        $deps[] = $dep;
                    }
                }
            }

            if ( preg_match_all( '/@import\s+(?:url\s*\(\s*)?["\']?([^"\');]+)["\']?\s*\)?/', $content, $matches ) ) {
                foreach ( $matches[1] as $url ) {
                    $dep = $this->resolve_relative_path( $url, $current_dir );
                    if ( $dep ) {
                        $deps[] = $dep;
                    }
                }
            }
        } elseif ( $ext === 'js' ) {
            // 動的インポートは複雑なため、よく使われるWordPressパターンのみ対応
            // 例: wp.i18n, wp.components などの依存関係
            // ただし、これらは通常HTMLで直接読み込まれるため、ここでは軽量な処理に留める
        }

        return $deps;
    }

    /**
     * 相対パスを解決してwp-includes内のパスに変換
     *
     * @param string $url 相対URL
     * @param string $current_dir 現在のディレクトリ (wp-includes内)
     * @return string|false 解決されたパス、またはfalse
     */
    private function resolve_relative_path( string $url, string $current_dir ): string|false {
        $url = strtok( $url, '?' );
        $url = strtok( $url, '#' );

        if ( str_starts_with( $url, 'data:' ) ) {
            return false;
        }

        if ( preg_match( '#^https?://#', $url ) ) {
            return $this->parse_wp_includes_path( $url );
        }

        $includes_dirname = ! empty( $this->settings['custom_wp_includes'] ) ? $this->settings['custom_wp_includes'] : 'wp-includes';

        if ( str_starts_with( $url, '/wp-includes/' ) ) {
            return substr( $url, strlen( '/wp-includes/' ) );
        } elseif ( str_starts_with( $url, '/' . $includes_dirname . '/' ) ) {
            return substr( $url, strlen( '/' . $includes_dirname . '/' ) );
        }

        if ( str_starts_with( $url, '/' ) ) {
            return false;
        }

        $path = $current_dir . '/' . $url;

        $parts = explode( '/', $path );
        $normalized = array();
        foreach ( $parts as $part ) {
            if ( $part === '..' ) {
                array_pop( $normalized );
            } elseif ( $part !== '.' && $part !== '' ) {
                $normalized[] = $part;
            }
        }

        $result = implode( '/', $normalized );

        // セキュリティ: wp-includes外への参照を防止
        if ( str_contains( $result, '..' ) ) {
            return false;
        }

        return $result;
    }

    /**
     * 指定ディレクトリ内のファイルを再帰的に検索
     *
     * @param string $dir ディレクトリパス
     * @param array  $extensions 拡張子の配列
     * @return array ファイルパスの配列
     */
    private function find_files_recursive( string $dir, array $extensions ): array {
        $files = array();

        if ( ! is_dir( $dir ) ) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $ext = strtolower( $file->getExtension() );
                if ( in_array( $ext, $extensions, true ) ) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * 追加ファイルをコピー
     */
    private function copy_included_files(): void {
        if ( empty( $this->settings['include_paths'] ) ) {
            return;
        }

        $paths = explode( "\n", $this->settings['include_paths'] );
        $copied_count = 0;
        $error_count = 0;

        foreach ( $paths as $path ) {
            $path = trim( $path );
            if ( empty( $path ) ) {
                continue;
            }

            $path = trim( $path, '\'"' );

            // セキュリティ: realpath でシンボリックリンク攻撃を防止
            $real_path = realpath( $path );
            if ( $real_path === false ) {
                $this->logger->add_log( "パスが存在しません: {$path}", true );
                $error_count++;
                continue;
            }

            // セキュリティ: 危険なディレクトリへのアクセスを禁止
            $dangerous_dirs = array(
                realpath( ABSPATH . 'wp-admin' ),
                realpath( ABSPATH . 'wp-includes' ),
                realpath( WP_CONTENT_DIR . '/plugins' ),
                realpath( WP_CONTENT_DIR . '/mu-plugins' ),
            );
            foreach ( $dangerous_dirs as $dangerous_dir ) {
                if ( $dangerous_dir && str_starts_with( $real_path, $dangerous_dir ) ) {
                    $this->logger->add_log( "保護されたディレクトリはスキップ: {$path}", true );
                    $error_count++;
                    continue 2;
                }
            }

            if ( is_file( $real_path ) ) {
                $dest = $this->temp_dir . '/' . basename( $real_path );
                if ( copy( $real_path, $dest ) ) {
                    $copied_count++;
                } else {
                    $this->logger->add_log( "ファイルのコピーに失敗: {$real_path}", true );
                    $error_count++;
                }
            } elseif ( is_dir( $real_path ) ) {
                $dest = $this->temp_dir . '/' . basename( $real_path );
                if ( $this->copy_directory_recursive( $real_path, $dest ) ) {
                    $copied_count++;
                } else {
                    $error_count++;
                }
            }
        }

        if ( $copied_count > 0 || $error_count > 0 ) {
            $this->logger->debug( '追加ファイル: ' . $copied_count . '件コピー' . ( $error_count > 0 ? '、' . $error_count . '件エラー' : '' ) );
        }
    }

    /**
     * 除外ファイルを削除
     */
    private function remove_excluded_files(): void {
        $force_patterns = $this->get_force_exclude_patterns();

        $user_patterns = array();
        if ( ! empty( $this->settings['exclude_patterns'] ) ) {
            $user_patterns = explode( "\n", $this->settings['exclude_patterns'] );
        }

        $all_patterns = array_merge( $force_patterns, $user_patterns );

        if ( empty( $all_patterns ) ) {
            return;
        }

        $removed_count = 0;
        foreach ( $all_patterns as $pattern ) {
            $pattern = trim( $pattern );
            if ( empty( $pattern ) ) {
                continue;
            }

            if ( str_contains( $pattern, '/' ) ) {
                $files = glob( $this->temp_dir . '/' . $pattern );
                foreach ( $files as $file ) {
                    if ( is_file( $file ) ) {
                        unlink( $file );
                        $removed_count++;
                    } elseif ( is_dir( $file ) ) {
                        $this->remove_directory( $file );
                        $removed_count++;
                    }
                }
            } else {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ( $iterator as $file ) {
                    if ( fnmatch( $pattern, $file->getFilename() ) ) {
                        if ( $file->isFile() ) {
                            unlink( $file->getPathname() );
                            $removed_count++;
                        } elseif ( $file->isDir() ) {
                            $this->remove_directory( $file->getPathname() );
                            $removed_count++;
                        }
                    }
                }
            }
        }

        if ( $removed_count > 0 ) {
            $this->logger->add_log( '除外ファイル削除: ' . $removed_count . '件' );
        }
    }

    /**
     * 強制除外パターンを取得
     *
     * @return array 除外パターンの配列
     */
    private function get_force_exclude_patterns(): array {
        return array(
            // プラグイン内部キャッシュ
            'wp-content/.cp-cache',
            'wp-content/.cp-cache/*',
            // wp2staticの出力ファイル
            'wp-content/uploads/wp2static-*',
            // このプラグイン自身
            'wp-content/plugins/carry-pod',
            'wp-content/plugins/carry-pod/*',
            // wp2static本体
            'wp-content/plugins/wp2static',
            'wp-content/plugins/wp2static/*',
            // wp2staticアドオン
            'wp-content/plugins/wp2static-addon-*',
            'wp-content/plugins/wp2static-addon-*/*',
            // 翻訳ソースファイル
            'wp-content/languages',
            'wp-content/languages/*',
        );
    }

    /**
     * ローカルディレクトリに出力
     */
    private function output_to_local(): void {
        $output_path = $this->settings['local_output_path'];

        if ( ! is_dir( $output_path ) ) {
            if ( ! mkdir( $output_path, 0755, true ) ) {
                $this->logger->add_log( 'ディレクトリの作成に失敗しました', true );
                return;
            }
        }

        $this->remove_directory_contents( $output_path );
        $this->copy_directory_recursive( $this->temp_dir, $output_path );

        $this->logger->add_log( 'ローカル出力完了: ' . $output_path );
    }

    /**
     * Cloudflare Workers出力
     */
    private function output_to_cloudflare_workers(): void {
        if ( ! empty( $this->settings['cloudflare_use_wrangler'] ) ) {
            $this->output_to_cloudflare_wrangler();
            return;
        }

        $cloudflare = new CP_Cloudflare_Workers(
            $this->settings['cloudflare_api_token'],
            $this->settings['cloudflare_account_id'],
            $this->settings['cloudflare_script_name']
        );

        $file_paths = $this->get_directory_file_paths( $this->temp_dir );
        $file_count = count( $file_paths );

        if ( $file_count === 0 ) {
            $this->logger->add_log( 'Cloudflare Workers: 出力するファイルが見つかりませんでした', true );
            return;
        }

        $this->logger->add_log( '合計 ' . $file_count . ' ファイルをCloudflare Workersにデプロイします' );

        $result = $cloudflare->deploy( $this->temp_dir );

        if ( is_wp_error( $result ) ) {
            $this->logger->add_log( 'Cloudflare Workers: ' . $result->get_error_message(), true );
            return;
        }

        $this->logger->add_log( 'Cloudflare Workers出力完了: ' . $this->settings['cloudflare_script_name'] );
    }

    /**
     * Cloudflare Workers出力（Wrangler CLI方式）
     */
    private function output_to_cloudflare_wrangler(): void {
        $start_time = microtime( true );
        $this->logger->info( 'Cloudflare Workers（Wrangler）へのデプロイを開始' );

        $wrangler_info = CP_Admin::get_instance()->get_wrangler_info();

        if ( ! $wrangler_info['found'] ) {
            $this->logger->add_log( 'Cloudflare Workers: Wrangler CLIが見つかりません。インストールしてください。', true );
            return;
        }

        if ( $wrangler_info['needs_update'] ) {
            $this->logger->add_log( 'Cloudflare Workers: Wrangler v4以上が必要です（現在: v' . $wrangler_info['version'] . '）', true );
            return;
        }

        $this->logger->debug( 'Wrangler検出: ' . $wrangler_info['path'] . ' (v' . $wrangler_info['version'] . ')' );

        $wrangler_dir = $this->temp_dir . '_wrangler';
        $public_dir = $wrangler_dir . '/public';

        if ( ! wp_mkdir_p( $public_dir ) ) {
            $this->logger->add_log( 'Cloudflare Workers: Wrangler用一時ディレクトリの作成に失敗', true );
            return;
        }

        try {
            $this->copy_directory_recursive( $this->temp_dir, $public_dir );

            $wrangler_toml = sprintf(
                "name = %s\naccount_id = %s\ncompatibility_date = \"%s\"\nmain = \"worker.js\"\nsend_metrics = false\nworkers_dev = false\npreview_urls = false\n\n[assets]\ndirectory = \"./public\"\nbinding = \"ASSETS\"\nnot_found_handling = \"404-page\"\n",
                '"' . addcslashes( $this->settings['cloudflare_script_name'], '"\\' ) . '"',
                '"' . addcslashes( $this->settings['cloudflare_account_id'], '"\\' ) . '"',
                date( 'Y-m-d' )
            );

            if ( file_put_contents( $wrangler_dir . '/wrangler.toml', $wrangler_toml ) === false ) {
                $this->logger->add_log( 'Cloudflare Workers: wrangler.tomlの生成に失敗', true );
                $this->remove_directory( $wrangler_dir );
                return;
            }

            $worker_js = <<<'JS'
export default {
    async fetch(request, env) {
        if (!env.ASSETS) {
            return new Response('Service configuration error.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
        }
        const response = await env.ASSETS.fetch(request);
        if (response.status === 404) {
            try {
                const url = new URL(request.url);
                const notFoundResponse = await env.ASSETS.fetch(url.origin + '/404.html');
                if (notFoundResponse.status === 200) {
                    return new Response(notFoundResponse.body, { status: 404, statusText: 'Not Found', headers: notFoundResponse.headers });
                }
            } catch (e) {}
        }
        return response;
    }
};
JS;

            if ( file_put_contents( $wrangler_dir . '/worker.js', $worker_js ) === false ) {
                $this->logger->add_log( 'Cloudflare Workers: worker.jsの生成に失敗', true );
                $this->remove_directory( $wrangler_dir );
                return;
            }

            $api_token = $this->settings['cloudflare_api_token'];

            $env_vars = array(
                'CLOUDFLARE_API_TOKEN'   => $api_token,
                'WRANGLER_SEND_METRICS'  => 'false',
            );

            $descriptors = array(
                0 => array( 'pipe', 'r' ),
                1 => array( 'pipe', 'w' ),
                2 => array( 'pipe', 'w' ),
            );

            $cmd = escapeshellarg( $wrangler_info['path'] ) . ' deploy';
            $this->logger->debug( 'Wranglerコマンド: ' . $cmd );

            $process = proc_open(
                $cmd,
                $descriptors,
                $pipes,
                $wrangler_dir,
                array_merge( $this->get_env_for_proc(), $env_vars )
            );

            if ( ! is_resource( $process ) ) {
                $this->logger->add_log( 'Cloudflare Workers: Wranglerプロセスの起動に失敗', true );
                $this->remove_directory( $wrangler_dir );
                return;
            }

            fclose( $pipes[0] );

            stream_set_blocking( $pipes[1], false );
            stream_set_blocking( $pipes[2], false );

            $stdout = '';
            $stderr = '';
            $timeout = 300; // 5分
            $start = time();

            while ( true ) {
                $status = proc_get_status( $process );
                if ( ! $status['running'] ) {
                    $stdout .= stream_get_contents( $pipes[1] );
                    $stderr .= stream_get_contents( $pipes[2] );
                    break;
                }

                if ( ( time() - $start ) > $timeout ) {
                    proc_terminate( $process );
                    proc_close( $process );
                    fclose( $pipes[1] );
                    fclose( $pipes[2] );
                    $this->remove_directory( $wrangler_dir );
                    $this->logger->add_log( 'Cloudflare Workers: デプロイがタイムアウトしました', true );
                    return;
                }

                $stdout .= fread( $pipes[1], 8192 );
                $stderr .= fread( $pipes[2], 8192 );
                usleep( 100000 ); // 0.1秒
            }

            fclose( $pipes[1] );
            fclose( $pipes[2] );
            $return_code = proc_close( $process );

            if ( ! empty( $stdout ) ) {
                $this->logger->debug( 'Wrangler stdout: ' . $stdout );
            }
            if ( ! empty( $stderr ) ) {
                $lines = explode( "\n", $stderr );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( ! empty( $line ) && stripos( $line, 'warn' ) !== false ) {
                        $this->logger->debug( 'Wrangler warning: ' . $line );
                    }
                }
                $this->logger->debug( 'Wrangler stderr: ' . $stderr );
            }

            if ( $return_code !== 0 ) {
                $error_msg = ! empty( $stderr ) ? $stderr : 'デプロイに失敗しました（exit code: ' . $return_code . '）';
                $this->logger->add_log( 'Cloudflare Workers: ' . $error_msg, true );
                $this->remove_directory( $wrangler_dir );
                return;
            }

            $elapsed = microtime( true ) - $start_time;
            $this->logger->info( sprintf( 'Cloudflare Workers（Wrangler）へのデプロイ完了 (%.1f秒)', $elapsed ) );
            $this->logger->add_log( 'Cloudflare Workers出力完了: ' . $this->settings['cloudflare_script_name'] );

        } finally {
            $this->remove_directory( $wrangler_dir );
        }
    }

    /**
     * proc_open用の環境変数を取得
     *
     * @return array 環境変数の配列
     */
    private function get_env_for_proc(): array {
        $env = CP_Admin::get_instance()->get_extended_path_env();

        $extra_keys = array( 'LANG', 'LC_ALL', 'TMPDIR', 'TMP', 'TEMP', 'NODE_PATH', 'NVM_DIR' );
        foreach ( $extra_keys as $key ) {
            $val = getenv( $key );
            if ( $val !== false ) {
                $env[ $key ] = $val;
            }
        }
        return $env;
    }

    /**
     * Netlify出力
     */
    private function output_to_netlify(): void {
        if ( ! is_dir( $this->temp_dir ) ) {
            $this->logger->add_log( 'Netlify: 一時ディレクトリが見つかりません', true );
            return;
        }

        $real_dir = realpath( $this->temp_dir );
        if ( $real_dir === false ) {
            $this->logger->add_log( 'Netlify: 一時ディレクトリのパスが不正です', true );
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $real_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $file_paths = array();
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $file_paths[] = $file->getRealPath();
            }
        }

        $file_count = count( $file_paths );

        if ( $file_count === 0 ) {
            $this->logger->add_log( 'Netlify: 出力するファイルが見つかりませんでした', true );
            return;
        }

        $this->logger->add_log( '合計 ' . $file_count . ' ファイルをNetlifyにデプロイします' );

        $file_digests = array();
        foreach ( $file_paths as $file_path ) {
            $relative_path = str_replace( trailingslashit( $real_dir ), '', $file_path );
            $file_digests[ $relative_path ] = sha1_file( $file_path );
        }

        $token = $this->settings['netlify_api_token'] ?? '';
        if ( empty( $token ) ) {
            $this->logger->add_log( 'Netlify: APIトークンが設定されていません', true );
            return;
        }

        $deploy_response = wp_remote_post(
            'https://api.netlify.com/api/v1/sites/' . $this->settings['netlify_site_id'] . '/deploys',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->settings['netlify_api_token'],
                    'Content-Type'  => 'application/json',
                ),
                'body' => wp_json_encode( array( 'files' => $file_digests ) ),
                'timeout' => 60,
            )
        );

        if ( is_wp_error( $deploy_response ) ) {
            $this->logger->add_log( 'Netlify: デプロイ作成に失敗しました - ' . $deploy_response->get_error_message(), true );
            return;
        }

        $status_code = wp_remote_retrieve_response_code( $deploy_response );

        if ( $status_code !== 200 && $status_code !== 201 ) {
            $this->logger->add_log( 'Netlify: デプロイ作成失敗 - HTTP ' . $status_code, true );
            return;
        }

        $deploy_data = json_decode( wp_remote_retrieve_body( $deploy_response ), true );
        if ( empty( $deploy_data['id'] ) ) {
            $this->logger->add_log( 'Netlify: デプロイIDの取得に失敗しました', true );
            return;
        }

        $deploy_id = $deploy_data['id'];
        $required_files = $deploy_data['required'] ?? array();

        $this->logger->add_log( 'Netlifyデプロイ作成完了: ' . $deploy_id );
        $this->logger->add_log( 'アップロード必要ファイル数: ' . count( $required_files ) );

        $uploaded = 0;
        $total_required = count( $required_files );

        foreach ( $file_paths as $file_path ) {
            $relative_path = str_replace( trailingslashit( $real_dir ), '', $file_path );
            $file_hash = sha1_file( $file_path );

            if ( ! in_array( $file_hash, $required_files, true ) ) {
                continue;
            }

            $file_content = file_get_contents( $file_path );

            $content_type = $this->get_content_type( $relative_path );

            $upload_response = wp_remote_request(
                'https://api.netlify.com/api/v1/deploys/' . $deploy_id . '/files/' . $relative_path,
                array(
                    'method'  => 'PUT',
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $this->settings['netlify_api_token'],
                        'Content-Type'  => $content_type,
                    ),
                    'body' => $file_content,
                    'timeout' => 120,
                )
            );

            if ( is_wp_error( $upload_response ) ) {
                // デバッグモード時のみ詳細エラーをPHPエラーログに記録
                if ( $this->debug_mode ) {
                    error_log( 'Netlify file upload error (' . $relative_path . '): ' . $upload_response->get_error_message() );
                }
                $this->logger->add_log( 'Netlify: ' . $relative_path . ' のアップロードに失敗しました', true );
                continue;
            }

            $upload_status = wp_remote_retrieve_response_code( $upload_response );
            if ( $upload_status !== 200 ) {
                $this->logger->add_log( 'Netlify: ' . $relative_path . ' アップロード失敗 - HTTP ' . $upload_status, true );
                continue;
            }

            $uploaded++;

            if ( $uploaded % 10 === 0 || $uploaded === $total_required ) {
                $this->logger->add_log( sprintf( 'Netlify: %d / %d ファイルアップロード済み', $uploaded, $total_required ) );
            }
        }

        $this->logger->add_log( 'Netlify出力完了: ' . $this->settings['netlify_site_id'] );
    }

    /**
     * GitLab API経由で出力
     */
    private function output_to_gitlab_api(): void {
        $branch = $this->settings['gitlab_branch_mode'] === 'existing'
            ? $this->settings['gitlab_existing_branch']
            : $this->settings['gitlab_new_branch'];

        $gitlab_api = new CP_GitLab_API(
            $this->settings['gitlab_token'],
            $this->settings['gitlab_project'],
            $branch,
            $this->settings['gitlab_api_url']
        );

        $repo_exists = $gitlab_api->check_repo_exists();
        if ( is_wp_error( $repo_exists ) ) {
            $this->logger->add_log( $repo_exists->get_error_message(), true );
            return;
        }

        if ( ! $repo_exists ) {
            $result = $gitlab_api->create_repo();
            if ( is_wp_error( $result ) ) {
                $this->logger->add_log( $result->get_error_message(), true );
                return;
            }
        }

        $file_paths = $this->get_directory_file_paths( $this->temp_dir );

        if ( empty( $file_paths ) ) {
            $this->logger->add_log( '出力するファイルが見つかりませんでした', true );
            return;
        }

        $this->logger->add_log( '合計 ' . count( $file_paths ) . ' ファイルをGitLabにプッシュします' );

        $result = $gitlab_api->push_files_batch_from_disk(
            $file_paths,
            $this->temp_dir,
            $this->settings['commit_message'],
            300
        );

        if ( is_wp_error( $result ) ) {
            $this->logger->add_log( $result->get_error_message(), true );
            return;
        }

        $this->logger->add_log( 'GitLab出力完了: ' . $this->settings['gitlab_project'] );
    }

    /**
     * ZIP出力
     */
    private function output_to_zip(): void {
        $zip_mode = $this->settings['zip_mode'] ?? 'download';

        if ( $zip_mode === 'local' ) {
            $output_path = $this->settings['zip_output_path'];
        } else {
            $upload_dir  = wp_upload_dir();
            $output_path = trailingslashit( $upload_dir['basedir'] ) . 'carry-pod-tmp';
        }

        if ( ! is_dir( $output_path ) ) {
            if ( ! mkdir( $output_path, 0755, true ) ) {
                $this->logger->add_log( 'ZIP出力先ディレクトリの作成に失敗しました', true );
                return;
            }
        }

        if ( $zip_mode === 'download' ) {
            $htaccess_path = trailingslashit( $output_path ) . '.htaccess';
            if ( ! file_exists( $htaccess_path ) ) {
                file_put_contents( $htaccess_path, "Deny from all\n" );
            }
        }

        $zip_path = $this->create_zip_archive( $output_path );

        if ( $zip_mode === 'download' && $zip_path ) {
            set_transient( 'cp_zip_download_path', $zip_path, 3600 );
        }
    }

    /**
     * ZIPアーカイブを作成
     *
     * @param string $output_path ZIP出力先パス
     * @return string|null 作成したZIPファイルのフルパス（失敗時はnull）
     */
    private function create_zip_archive( string $output_path ): ?string {
        $site_name = sanitize_title( get_bloginfo( 'name' ) );
        if ( empty( $site_name ) ) {
            $site_name = 'static-output';
        }
        $timestamp    = current_time( 'Ymd_His' );
        $zip_filename = $site_name . '-' . $timestamp . '.zip';
        $zip_path     = trailingslashit( $output_path ) . $zip_filename;

        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->logger->add_log( 'ZipArchiveクラスが利用できないため、ZIP作成をスキップしました', true );
            return null;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            $this->logger->add_log( 'ZIPファイルの作成に失敗しました: ' . $zip_path, true );
            return null;
        }

        $this->add_directory_to_zip( $zip, $this->temp_dir, $this->temp_dir );

        $zip->close();

        $zip_size = file_exists( $zip_path ) ? filesize( $zip_path ) : 0;
        $zip_size_mb = round( $zip_size / 1024 / 1024, 2 );

        $this->logger->add_log( 'ZIP出力完了: ' . $zip_filename . ' (' . $zip_size_mb . 'MB)' );

        return $zip_path;
    }

    /**
     * ディレクトリをZIPに追加（再帰的）
     *
     * @param ZipArchive $zip ZipArchiveオブジェクト
     * @param string $dir 追加するディレクトリ
     * @param string $base_dir ベースディレクトリ（相対パス計算用）
     */
    private function add_directory_to_zip( \ZipArchive $zip, string $dir, string $base_dir ): void {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $normalized_base = str_replace( '\\', '/', trailingslashit( realpath( $base_dir ) ) );

        foreach ( $files as $file ) {
            $file_path = $file->getRealPath();

            if ( str_contains( $file_path, '.zip' ) ) {
                continue;
            }

            $normalized_file = str_replace( '\\', '/', $file_path );
            $relative_path = str_replace( $normalized_base, '', $normalized_file );
            $relative_path = ltrim( $relative_path, '/' );

            if ( $file->isDir() ) {
                if ( ! $this->is_directory_empty_recursive( $file_path ) ) {
                    $zip->addEmptyDir( $relative_path );
                }
            } else {
                $zip->addFile( $file_path, $relative_path );
                $zip->setCompressionName( $relative_path, ZipArchive::CM_DEFLATE, 9 );
            }
        }
    }

    /**
     * GitHub API経由で出力
     */
    private function output_to_github_api(): void {
        $branch = $this->settings['github_branch_mode'] === 'existing'
            ? $this->settings['github_existing_branch']
            : $this->settings['github_new_branch'];

        $github_api = new CP_GitHub_API(
            $this->settings['github_token'],
            $this->settings['github_repo'],
            $branch
        );

        $repo_exists = $github_api->check_repo_exists();
        if ( is_wp_error( $repo_exists ) ) {
            $this->logger->add_log( $repo_exists->get_error_message(), true );
            return;
        }

        if ( ! $repo_exists ) {
            $result = $github_api->create_repo();
            if ( is_wp_error( $result ) ) {
                $this->logger->add_log( $result->get_error_message(), true );
                return;
            }
        }

        $file_paths = $this->get_directory_file_paths( $this->temp_dir );

        if ( empty( $file_paths ) ) {
            $this->logger->add_log( '出力するファイルが見つかりませんでした', true );
            return;
        }

        $this->logger->add_log( '合計 ' . count( $file_paths ) . ' ファイルをGitHubにプッシュします' );

        $result = $github_api->push_files_batch_from_disk(
            $file_paths,
            $this->temp_dir,
            $this->settings['commit_message'],
            300
        );

        if ( is_wp_error( $result ) ) {
            $this->logger->add_log( $result->get_error_message(), true );
            return;
        }

        $this->logger->add_log( 'GitHub出力完了: ' . $this->settings['github_repo'] );
    }

    /**
     * ローカルGitに出力
     */
    private function output_to_git_local(): void {
        $work_dir = $this->settings['git_local_work_dir'];
        $branch = $this->settings['git_local_branch'];

        if ( ! $this->is_valid_git_branch_name( $branch ) ) {
            $this->logger->add_log( 'エラー: 無効なブランチ名です', true );
            return;
        }

        $git_cmd = self::find_git_command();
        if ( ! $git_cmd ) {
            $this->logger->add_log( 'エラー: gitコマンドが見つかりません', true );
            return;
        }

        if ( ! is_dir( $work_dir ) ) {
            $this->logger->add_log( 'エラー: Git作業ディレクトリが存在しません', true );
            return;
        }

        if ( ! is_dir( $work_dir . '/.git' ) ) {
            $this->logger->add_log( 'エラー: 指定されたディレクトリはGitリポジトリではありません', true );
            return;
        }

        $this->remove_directory_contents( $work_dir, array( '.git' ) );
        $this->copy_directory_recursive( $this->temp_dir, $work_dir );

        $old_dir = getcwd();
        chdir( $work_dir );

        $branch_output = array();
        exec( $git_cmd . ' branch --show-current 2>&1', $branch_output, $branch_return );
        $current_branch = $branch_return === 0 ? trim( implode( "\n", $branch_output ) ) : '';

        $branch_exists = false;
        $output = array();
        exec( $git_cmd . ' rev-parse --verify ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
        if ( $return_code === 0 ) {
            $branch_exists = true;
        }

        $output = array();
        exec( $git_cmd . ' rev-parse HEAD 2>&1', $output, $return_code );
        $has_commits = ( $return_code === 0 );

        if ( $branch_exists && $current_branch !== $branch ) {
            $output = array();
            exec( $git_cmd . ' checkout ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            if ( $return_code !== 0 ) {
                $error_msg = implode( ' ', $output );
                $this->logger->add_log( 'ブランチの切り替えに失敗: ' . $error_msg, true );
                chdir( $old_dir );
                return;
            }
        } elseif ( ! $branch_exists ) {
            $output = array();
            if ( $has_commits ) {
                exec( $git_cmd . ' checkout -b ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            } else {
                exec( $git_cmd . ' checkout --orphan ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            }
            if ( $return_code !== 0 ) {
                $error_msg = implode( ' ', $output );
                $this->logger->add_log( 'ブランチの作成に失敗: ' . $error_msg, true );
                chdir( $old_dir );
                return;
            }
        }

        $output = array();
        exec( $git_cmd . ' add -A 2>&1', $output, $return_code );
        if ( $return_code !== 0 ) {
            $error_msg = implode( ' ', $output );
            $this->logger->add_log( 'ファイルのステージングに失敗: ' . $error_msg, true );
            chdir( $old_dir );
            return;
        }

        $commit_message = ! empty( $this->settings['commit_message'] )
            ? $this->settings['commit_message']
            : 'Static site update: ' . current_time( 'Y-m-d H:i:s' );

        $output = array();
        exec( $git_cmd . ' commit -m ' . escapeshellarg( $commit_message ) . ' 2>&1', $output, $return_code );
        $commit_created = false;
        if ( $return_code !== 0 ) {
            if ( ! str_contains( implode( "\n", $output ), 'nothing to commit' ) ) {
                $error_msg = implode( ' ', $output );
                $this->logger->add_log( 'コミットに失敗: ' . $error_msg, true );
                chdir( $old_dir );
                return;
            }
        } else {
            $commit_created = true;
        }

        $push_success = false;
        $remote_url = $this->settings['git_local_remote_url'] ?? '';
        if ( $commit_created && ! empty( $remote_url ) ) {
            $this->logger->add_log( 'リモートへプッシュ中...' );
            $output = array();
            exec(
                $git_cmd . ' push ' . escapeshellarg( $remote_url ) . ' ' . escapeshellarg( $branch ) . ' 2>&1',
                $output,
                $return_code
            );
            if ( $return_code !== 0 ) {
                $error_msg = $this->sanitize_git_error( $output );
                $this->logger->add_log( 'リモートへのプッシュに失敗: ' . $error_msg, true );
            } else {
                $push_success = true;
            }
        }

        chdir( $old_dir );

        if ( $commit_created ) {
            if ( ! empty( $remote_url ) ) {
                if ( $push_success ) {
                    $this->logger->add_log( 'ローカルGit出力完了（プッシュ成功）: ' . $branch );
                } else {
                    $this->logger->add_log( 'ローカルGit出力完了（プッシュ失敗）: ' . $branch );
                }
            } else {
                $this->logger->add_log( 'ローカルGit出力完了: ' . $branch );
            }
        } else {
            $this->logger->add_log( 'ローカルGit: 変更なし' );
        }
    }

    /**
     * ディレクトリが空かどうかをチェック（再帰的）
     *
     * @param string $dir チェックするディレクトリ
     * @return bool 空の場合true、ファイルがある場合false
     */
    private function is_directory_empty_recursive( string $dir ): bool {
        if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
            return true;
        }

        $files = scandir( $dir );
        if ( $files === false ) {
            return true;
        }

        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }

            $path = $dir . '/' . $file;

            if ( is_dir( $path ) ) {
                if ( ! $this->is_directory_empty_recursive( $path ) ) {
                    return false;
                }
            } else {
                $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
                $excluded_extensions = array(
                    'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phps',
                    'exe', 'bat', 'sh', 'command', 'com',
                    'htpasswd', 'ini', 'conf', 'config',
                    'sql', 'sqlite', 'db',
                    'git', 'gitignore', 'gitmodules', 'svn',
                    'log', 'bak', 'backup', 'tmp', 'temp'
                );

                $filename = basename( $path );
                if ( ! ( str_starts_with( $filename, '.' ) && $filename !== '.htaccess' ) &&
                     ! in_array( $extension, $excluded_extensions ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * ディレクトリを再帰的にコピー
     *
     * @param string $src コピー元ディレクトリ
     * @param string $dst コピー先ディレクトリ
     */
    private function copy_directory_recursive( string $src, string $dst, bool $log_progress = false ): bool {
        if ( ! is_dir( $src ) ) {
            $this->logger->add_log( 'ソースディレクトリが存在しません: ' . $src, true );
            return false;
        }

        // パストラバーサル対策: realpath で正規化して検証
        $real_src = realpath( $src );
        if ( $real_src === false ) {
            $this->logger->add_log( 'ソースパスの解決に失敗しました: ' . $src, true );
            return false;
        }

        if ( $this->is_directory_empty_recursive( $real_src ) ) {
            return true;
        }

        if ( ! is_dir( $dst ) ) {
            if ( ! mkdir( $dst, 0755, true ) ) {
                $this->logger->add_log( 'ディレクトリの作成に失敗しました: ' . $dst, true );
                return false;
            }
        }

        if ( ! is_readable( $real_src ) ) {
            $this->logger->add_log( 'ディレクトリの読み取り権限がありません: ' . $real_src, true );
            return false;
        }
        $files = scandir( $real_src );
        if ( $files === false ) {
            $this->logger->add_log( 'ディレクトリの読み込みに失敗しました: ' . $real_src, true );
            return false;
        }

        $file_count = 0;
        $error_count = 0;

        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }

            // ヌルバイト攻撃対策
            if ( str_contains( $file, "\0" ) ) {
                continue;
            }

            $src_path = $real_src . '/' . $file;
            $dst_path = $dst . '/' . $file;

            // パストラバーサル対策: src_pathがreal_src内にあることを確認
            $real_src_path = realpath( $src_path );
            if ( $real_src_path === false || ! str_starts_with( $real_src_path, $real_src ) ) {
                $this->logger->add_log( 'パストラバーサルを検出: ' . $file, true );
                continue;
            }

            if ( is_dir( $real_src_path ) ) {
                if ( ! $this->is_directory_empty_recursive( $src_path ) ) {
                    if ( ! $this->copy_directory_recursive( $src_path, $dst_path ) ) {
                        $error_count++;
                    }
                }
            } else {
                $extension = strtolower( pathinfo( $src_path, PATHINFO_EXTENSION ) );

                $excluded_extensions = array(
                    // PHP関連
                    'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phps',
                    // 実行ファイル
                    'exe', 'bat', 'sh', 'command', 'com',
                    // 設定ファイル（.htaccessは除く - 静的サイトで必要な場合がある）
                    'htpasswd', 'ini', 'conf', 'config',
                    // データベース
                    'sql', 'sqlite', 'db',
                    // 開発ファイル
                    'git', 'gitignore', 'gitmodules', 'svn',
                    // その他
                    'log', 'bak', 'backup', 'tmp', 'temp'
                );

                $filename = basename( $src_path );
                if ( str_starts_with( $filename, '.' ) && $filename !== '.htaccess' ) {
                    continue;
                }

                if ( in_array( $extension, $excluded_extensions ) ) {
                    continue;
                }

                if ( in_array( $extension, array( 'css', 'js' ) ) && $this->settings['url_mode'] === 'relative' ) {
                    $content = file_get_contents( $real_src_path );
                    if ( $content !== false ) {
                        $content = $this->convert_asset_urls( $content, $extension );
                        if ( file_put_contents( $dst_path, $content ) === false ) {
                            $this->logger->add_log( 'ファイルの書き込みに失敗しました: ' . $dst_path, true );
                            $error_count++;
                        } else {
                            $file_count++;
                        }
                    } else {
                        if ( ! copy( $real_src_path, $dst_path ) ) {
                            $this->logger->add_log( 'ファイルのコピーに失敗しました: ' . $real_src_path, true );
                            $error_count++;
                        } else {
                            $file_count++;
                        }
                    }
                } else {
                    if ( ! copy( $real_src_path, $dst_path ) ) {
                        $this->logger->add_log( 'ファイルのコピーに失敗しました: ' . $real_src_path, true );
                        $error_count++;
                    } else {
                        $file_count++;
                    }
                }
            }
        }

        if ( $error_count > 0 ) {
            $this->logger->add_log( 'コピー中にエラー: ' . $error_count . '件', true );
        }

        return $error_count === 0;
    }

    /**
     * ディレクトリの内容を削除
     *
     * @param string $dir ディレクトリパス
     */
    private function remove_directory_contents( string $dir, array $exclude = array() ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $files = scandir( $dir );
        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }

            if ( in_array( $file, $exclude ) ) {
                continue;
            }

            $path = $dir . '/' . $file;
            if ( is_dir( $path ) ) {
                $this->remove_directory( $path );
            } else {
                unlink( $path );
            }
        }
    }

    /**
     * Gitコマンドのエラー出力をサニタイズ
     *
     * @param array $output コマンドの出力配列
     * @return string サニタイズされたエラーメッセージ
     */
    private function sanitize_git_error( array $output ): string {
        if ( empty( $output ) ) {
            return '';
        }

        $message = implode( "\n", $output );

        $message = preg_replace( '#/[^\s:]+#', '[path]', $message );
        $message = preg_replace( '#[A-Z]:\\\\[^\s:]+#i', '[path]', $message );
        $message = preg_replace( '#https?://[^@\s]+@[^\s]+#', 'https://[credentials]@[remote]', $message );
        $message = preg_replace( '#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#', '[ip]', $message );

        return $message;
    }

    /**
     * gitコマンドのパスを検出（セキュリティ強化版）
     *
     * @return string|false gitコマンドのフルパス、見つからない場合はfalse
     */
    public static function find_git_command(): string|false {
        $allowed_paths = array(
            '/usr/bin/git',
            '/usr/local/bin/git',
            '/opt/homebrew/bin/git',
            '/opt/local/bin/git',
            'C:/Program Files/Git/bin/git.exe',
            'C:/Program Files (x86)/Git/bin/git.exe',
            'C:\\Program Files\\Git\\bin\\git.exe',
            'C:\\Program Files (x86)\\Git\\bin\\git.exe',
        );

        foreach ( $allowed_paths as $path ) {
            if ( ! is_executable( $path ) ) {
                continue;
            }

            // シンボリックリンク攻撃対策: realpath で実際のパスを検証
            $real_path = realpath( $path );
            if ( $real_path === false ) {
                continue;
            }

            // ファイル名が 'git' または 'git.exe' であることを確認
            $basename = basename( $real_path );
            if ( $basename !== 'git' && $basename !== 'git.exe' ) {
                continue;
            }

            // 許可されたディレクトリ内にあることを確認
            $allowed_dirs = array(
                '/usr/bin',
                '/usr/local/bin',
                '/opt/homebrew/bin',
                '/opt/local/bin',
                'C:/Program Files/Git/bin',
                'C:/Program Files (x86)/Git/bin',
                'C:\\Program Files\\Git\\bin',
                'C:\\Program Files (x86)\\Git\\bin',
            );
            $real_dir = dirname( $real_path );

            $real_dir_normalized = str_replace( '\\', '/', $real_dir );

            $is_allowed = false;
            foreach ( $allowed_dirs as $allowed_dir ) {
                $allowed_dir_normalized = str_replace( '\\', '/', $allowed_dir );
                if ( str_starts_with( $real_dir_normalized, $allowed_dir_normalized ) ) {
                    $is_allowed = true;
                    break;
                }
            }

            if ( $is_allowed ) {
                return $real_path;
            }
        }

        return false;
    }

    /**
     * ファビコン（サイトアイコン）をコピー
     */
    private function copy_favicon(): void {
        $site_icon_id = get_option( 'site_icon' );

        if ( $site_icon_id ) {
            $icon_url = wp_get_attachment_image_url( $site_icon_id, 'full' );
            if ( $icon_url ) {
                $icon_path = get_attached_file( $site_icon_id );

                if ( $icon_path && file_exists( $icon_path ) ) {
                    $extension = pathinfo( $icon_path, PATHINFO_EXTENSION );

                    $favicon_dest = $this->temp_dir . '/favicon.ico';
                    if ( ! copy( $icon_path, $favicon_dest ) ) {
                        $this->logger->add_log( 'ファビコンのコピーに失敗', true );
                    }

                    if ( $extension !== 'ico' && is_readable( $icon_path ) ) {
                        $icon_filename = 'favicon.' . $extension;
                        $icon_dest = $this->temp_dir . '/' . $icon_filename;
                        copy( $icon_path, $icon_dest );
                    }
                } else {
                    $this->logger->add_log( 'サイトアイコンファイルが見つかりません', true );
                }
            }
        } else {
            $favicon_path = ABSPATH . 'favicon.ico';
            if ( file_exists( $favicon_path ) ) {
                $favicon_dest = $this->temp_dir . '/favicon.ico';
                if ( ! copy( $favicon_path, $favicon_dest ) ) {
                    $this->logger->add_log( 'favicon.icoのコピーに失敗', true );
                }
            }
        }

        $additional_icons = array(
            'apple-touch-icon.png',
            'apple-touch-icon-precomposed.png',
            'browserconfig.xml',
            'manifest.json',
            'site.webmanifest',
        );

        foreach ( $additional_icons as $icon_file ) {
            $icon_path = ABSPATH . $icon_file;
            if ( file_exists( $icon_path ) && is_readable( $icon_path ) ) {
                $icon_dest = $this->temp_dir . '/' . $icon_file;
                copy( $icon_path, $icon_dest );
            }
        }
    }

    /**
     * robots.txtを生成
     */
    private function generate_robots_txt(): void {
        $robots_txt_path = $this->temp_dir . '/robots.txt';

        $robots_content = "User-agent: Googlebot\n";
        $robots_content .= "User-agent: Bingbot\n";
        $robots_content .= "User-agent: DuckDuckBot\n";
        $robots_content .= "Allow: /\n\n";
        $robots_content .= "User-agent: *\n";
        $robots_content .= "Disallow: /\n";

        if ( ! empty( $this->settings['enable_sitemap'] ) ) {
            if ( $this->settings['url_mode'] === 'absolute' ) {
                $base_url = ! empty( $this->settings['base_url'] ) ? untrailingslashit( $this->settings['base_url'] ) : untrailingslashit( get_site_url() );
                $robots_content .= "\nSitemap: {$base_url}/sitemap.xml\n";
            } else {
                $robots_content .= "\nSitemap: /sitemap.xml\n";
            }
        }

        if ( file_put_contents( $robots_txt_path, $robots_content ) === false ) {
            $this->logger->add_log( 'robots.txtの生成に失敗', true );
        }
    }

    /**
     * llms.txtを生成
     */
    private function generate_llms_txt(): void {
        $llms_txt_path = $this->temp_dir . '/llms.txt';

        $site_name = get_bloginfo( 'name' );
        $site_name_escaped = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $site_name );

        $base_url = ! empty( $this->settings['base_url'] ) ? untrailingslashit( $this->settings['base_url'] ) : untrailingslashit( get_site_url() );

        $llms_content = "user-agent: *\n\n";
        $llms_content .= "# License\n";
        $llms_content .= 'x-content-license: "(c) ' . $site_name_escaped . '. All rights reserved."' . "\n";
        $llms_content .= 'x-ai-training-policy: "disallowed"' . "\n\n";
        $llms_content .= "# Rate Limits\n";
        $llms_content .= "crawl-delay: 2\n";
        $llms_content .= "x-rate-limit: 30\n";
        $llms_content .= "x-rate-limit-window: 60\n\n";
        $llms_content .= "# Error Handling and Retry Policy\n";
        $llms_content .= 'x-error-retry-policy: "no-retry"' . "\n";
        $llms_content .= 'x-error-retry-policy-description: "Do not retry on any errors. Move on to the next request."' . "\n";
        $llms_content .= "x-max-retries: 0\n";
        $llms_content .= "x-no-retry-status-codes:\n";
        $llms_content .= "  - 403\n";
        $llms_content .= "  - 404\n";
        $llms_content .= "  - 429\n";
        $llms_content .= "  - 500\n";
        $llms_content .= "  - 502\n";
        $llms_content .= "  - 503\n";
        $llms_content .= "  - 504\n\n";
        $llms_content .= "# Canonical URL\n";
        $llms_content .= 'x-canonical-url-policy: "strict"' . "\n";
        $llms_content .= 'x-canonical-url: "' . $base_url . '"' . "\n";
        $llms_content .= 'x-canonical-url-description: "Access via other FQDNs or IP addresses is invalid. Use only the canonical URL as the base URL."' . "\n\n";
        $llms_content .= "# Disallow\n";
        $llms_content .= "disallow: /\n";

        if ( file_put_contents( $llms_txt_path, $llms_content ) === false ) {
            $this->logger->add_log( 'llms.txtの生成に失敗', true );
        }
    }

    /**
     * _headersファイルを生成（Mati連携）
     */
    private function generate_mati_headers(): void {
        if ( ! defined( 'MATI_VERSION' ) || ! class_exists( 'Mati_Settings' ) ) {
            return;
        }

        // Cloudflare Workers（Direct Upload API）のみ有効で他の出力先がない場合は_headersを生成しない
        // Wrangler使用時は_headersが機能するためスキップしない
        $settings = CP_Settings::get_instance()->get_settings();
        if ( ! empty( $settings['cloudflare_enabled'] ) && empty( $settings['cloudflare_use_wrangler'] ) ) {
            $other_destinations = array( 'github_enabled', 'gitlab_enabled', 'netlify_enabled', 'git_local_enabled', 'local_enabled', 'zip_enabled' );
            $has_other          = false;
            foreach ( $other_destinations as $key ) {
                if ( ! empty( $settings[ $key ] ) ) {
                    $has_other = true;
                    break;
                }
            }
            if ( ! $has_other ) {
                $this->logger->add_log( '_headersファイル生成をスキップ（Cloudflare Workersでは機能しないため）', false );
                return;
            }
        }

        try {
            if ( ! method_exists( 'Mati_Settings', 'get_instance' ) ) {
                $this->logger->add_log( '_headersファイル生成エラー: Mati_Settings::get_instanceメソッドが存在しません', true );
                return;
            }

            $mati_settings_instance = Mati_Settings::get_instance();

            if ( ! method_exists( $mati_settings_instance, 'get_settings' ) ) {
                $this->logger->add_log( '_headersファイル生成エラー: get_settingsメソッドが存在しません', true );
                return;
            }

            $mati_settings = $mati_settings_instance->get_settings();

            $headers_content = "/*\n";

            $frame_ancestors = "'self'";
            $custom_domains  = $mati_settings['frame_ancestors_domains'] ?? '';
            if ( ! empty( $custom_domains ) ) {
                $domains = array_filter( array_map( 'trim', explode( "\n", $custom_domains ) ) );
                if ( ! empty( $domains ) ) {
                    $frame_ancestors .= ' ' . implode( ' ', $domains );
                }
            }
            $headers_content .= "  Content-Security-Policy: frame-ancestors $frame_ancestors\n";
            $headers_content .= "  X-Content-Type-Options: nosniff\n";

            $robots_tags = array();

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

            if ( ! empty( $robots_tags ) ) {
                $headers_content .= '  X-Robots-Tag: ' . implode( ', ', $robots_tags ) . "\n";
            }

            $bluesky_did = $mati_settings['bluesky_did'] ?? '';
            if ( ! empty( $bluesky_did ) ) {
                $headers_content .= "/.well-known/atproto-did\n";
                $headers_content .= "  Content-Type: text/plain; charset=utf-8\n";
                $headers_content .= "  Cache-Control: no-cache, no-store, must-revalidate\n";
                $headers_content .= "  Pragma: no-cache\n";
                $headers_content .= "  Expires: 0\n";
                $headers_content .= "  Content-Disposition: inline\n";
            }

            $headers_path = $this->temp_dir . '/_headers';

            if ( file_put_contents( $headers_path, $headers_content ) === false ) {
                $this->logger->add_log( '_headersファイルの生成に失敗', true );
            } else {
                $this->logger->add_log( '_headersファイルを生成しました', false );
            }
        } catch ( Exception $e ) {
            $this->logger->add_log( '_headersファイル生成エラー: ' . $e->getMessage(), true );
        }
    }

    /**
     * .well-known/atproto-did ファイルを生成（Mati連携）
     */
    private function generate_atproto_did(): void {
        if ( ! defined( 'MATI_VERSION' ) || ! class_exists( 'Mati_Settings' ) ) {
            return;
        }

        try {
            if ( ! method_exists( 'Mati_Settings', 'get_instance' ) ) {
                return;
            }

            $mati_settings_instance = Mati_Settings::get_instance();

            if ( ! method_exists( $mati_settings_instance, 'get_settings' ) ) {
                return;
            }

            $mati_settings = $mati_settings_instance->get_settings();
            $bluesky_did   = $mati_settings['bluesky_did'] ?? '';

            if ( empty( $bluesky_did ) ) {
                return;
            }

            $well_known_dir = $this->temp_dir . '/.well-known';
            if ( ! is_dir( $well_known_dir ) ) {
                mkdir( $well_known_dir, 0755, true );
            }

            $did_path = $well_known_dir . '/atproto-did';
            if ( file_put_contents( $did_path, $bluesky_did ) === false ) {
                $this->logger->add_log( '.well-known/atproto-did の生成に失敗', true );
            } else {
                $this->logger->add_log( '.well-known/atproto-did を生成しました', false );
            }
        } catch ( Exception $e ) {
            $this->logger->add_log( '.well-known/atproto-did 生成エラー: ' . $e->getMessage(), true );
        }
    }

    /**
     * ディレクトリを再帰的に削除
     *
     * @param string $dir ディレクトリパス
     */
    private function remove_directory( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $this->remove_directory_contents( $dir );
        rmdir( $dir );
    }

    /**
     * ディレクトリ内の全ファイルパスを取得（内容は読み込まない）
     *
     * @param string $dir ディレクトリパス
     * @return array ファイルパスの配列（相対パス）
     */
    private function get_directory_file_paths( string $dir ): array {
        $file_paths = array();

        if ( ! is_dir( $dir ) ) {
            return $file_paths;
        }

        $real_dir = realpath( $dir );
        if ( $real_dir === false ) {
            return $file_paths;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $real_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $file_path = $file->getRealPath();
                $relative_path = str_replace( trailingslashit( $real_dir ), '', $file_path );
                $file_paths[] = $relative_path;
            }
        }

        return $file_paths;
    }

    /**
     * ディレクトリ内の全ファイルを読み込み（後方互換性のため残す）
     *
     * @param string $dir ディレクトリパス
     * @return array ファイルの配列（相対パス => 内容）
     */
    private function read_directory_files( string $dir ): array {
        $files = array();

        if ( ! is_dir( $dir ) ) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $file_path = $file->getRealPath();
                $relative_path = str_replace( trailingslashit( $dir ), '', $file_path );

                $file_size = $file->getSize();
                if ( $file_size > 10 * 1024 * 1024 ) {
                    $this->logger->add_log( "スキップ: ファイルが大きすぎます（{$relative_path}: " . size_format( $file_size ) . '）' );
                    continue;
                }

                $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
                $allowed_extensions = array( 'html', 'css', 'js', 'json', 'xml', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'pdf' );

                if ( ! in_array( $extension, $allowed_extensions ) ) {
                    $this->logger->add_log( "スキップ: 許可されていない拡張子（{$relative_path}）" );
                    continue;
                }

                if ( is_readable( $file_path ) ) {
                    $content = file_get_contents( $file_path );
                    if ( $content !== false ) {
                        $files[ $relative_path ] = $content;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * 有効なテーマのみコピー
     *
     * @param string $wp_content_dest コピー先のwp-contentディレクトリ
     * @return bool 成功ならtrue
     */
    private function copy_active_themes( string $wp_content_dest ): bool {
        $themes_dir = get_theme_root();
        $themes_dest = $wp_content_dest . '/themes';

        if ( ! is_dir( $themes_dir ) ) {
            return false;
        }

        if ( ! is_dir( $themes_dest ) ) {
            mkdir( $themes_dest, 0755, true );
        }

        $current_theme = get_stylesheet();
        $parent_theme = get_template();

        $themes_to_copy = array( $current_theme );
        if ( $current_theme !== $parent_theme ) {
            $themes_to_copy[] = $parent_theme;
        }

        $copied_count = 0;
        foreach ( $themes_to_copy as $theme_slug ) {
            $src = $themes_dir . '/' . $theme_slug;
            $dest = $themes_dest . '/' . $theme_slug;

            if ( is_dir( $src ) ) {
                if ( $this->copy_directory_recursive( $src, $dest, false ) ) {
                    $copied_count++;
                }
            }
        }

        $this->logger->debug( "テーマコピー: " . implode( ', ', $themes_to_copy ) );
        return $copied_count > 0;
    }

    /**
     * 参照されているメディアのみコピー
     *
     * @param string $wp_content_dest コピー先のwp-contentディレクトリ
     * @return bool 成功ならtrue
     */
    private function copy_referenced_uploads( string $wp_content_dest ): bool {
        $uploads_dir = wp_upload_dir();
        $uploads_base = $uploads_dir['basedir'];
        $uploads_dest = $wp_content_dest . '/uploads';

        if ( ! is_dir( $uploads_base ) ) {
            return false;
        }

        if ( ! is_dir( $uploads_dest ) ) {
            mkdir( $uploads_dest, 0755, true );
        }

        $referenced_ids = $this->get_referenced_attachment_ids();

        $copied_count = 0;
        $total_size = 0;

        if ( ! empty( $referenced_ids ) ) {
            foreach ( $referenced_ids as $attachment_id ) {
            $file_path = get_attached_file( $attachment_id );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                continue;
            }

            $relative_path = str_replace( trailingslashit( $uploads_base ), '', $file_path );
            $dest_path = $uploads_dest . '/' . $relative_path;

            $dest_dir = dirname( $dest_path );
            if ( ! is_dir( $dest_dir ) ) {
                mkdir( $dest_dir, 0755, true );
            }

            if ( copy( $file_path, $dest_path ) ) {
                $copied_count++;
                $total_size += filesize( $file_path );
            }

            $metadata = wp_get_attachment_metadata( $attachment_id );
            if ( ! empty( $metadata['sizes'] ) ) {
                $file_dir = dirname( $file_path );
                foreach ( $metadata['sizes'] as $size => $size_data ) {
                    $size_file = $file_dir . '/' . $size_data['file'];
                    if ( file_exists( $size_file ) ) {
                        $size_relative = dirname( $relative_path ) . '/' . $size_data['file'];
                        $size_dest = $uploads_dest . '/' . $size_relative;
                        if ( copy( $size_file, $size_dest ) ) {
                            $copied_count++;
                            $total_size += filesize( $size_file );
                        }
                    }
                }
            }
            }
        }

        if ( $copied_count > 0 ) {
            $size_mb = round( $total_size / 1024 / 1024, 2 );
            $this->logger->debug( "メディアコピー: {$copied_count}ファイル ({$size_mb}MB)" );
        }

        $this->copy_referenced_upload_files( $uploads_base, $uploads_dest );

        return true;
    }

    /**
     * HTMLから参照されているuploads内の非メディアファイルをコピー
     *
     * プラグインがuploadsディレクトリに生成するCSS等のファイルを対象とする
     *
     * @param string $uploads_base uploadsのベースディレクトリ
     * @param string $uploads_dest コピー先のuploadsディレクトリ
     */
    private function copy_referenced_upload_files( string $uploads_base, string $uploads_dest ): void {
        $html_files = $this->get_all_html_files( $this->temp_dir );
        $referenced_paths = array();

        foreach ( $html_files as $html_file ) {
            $content = file_get_contents( $html_file );

            preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\']/', $content, $css_matches );
            preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/', $content, $js_matches );
            preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $img_matches );
            preg_match_all( '/url\(["\']?([^"\')\s]+)["\']?\)/', $content, $url_matches );
            preg_match_all( '/data-src=["\']([^"\']+)["\']/', $content, $data_src_matches );

            $all_paths = array_merge( $css_matches[1], $js_matches[1], $img_matches[1], $url_matches[1], $data_src_matches[1] );

            foreach ( $all_paths as $path ) {
                $content_dirname = ! empty( $this->settings['custom_wp_content'] ) ? $this->settings['custom_wp_content'] : 'wp-content';

                $is_uploads_path = str_contains( $path, 'wp-content/uploads/' ) ||
                                   str_contains( $path, $content_dirname . '/uploads/' );

                if ( $is_uploads_path ) {
                    $normalized = ltrim( $path, '/' );
                    $normalized = preg_replace( '/\?.*$/', '', $normalized ); // クエリ文字列を除去

                    $pattern = '/(?:wp-content|' . preg_quote( $content_dirname, '/' ) . ')\/uploads\/(.+)/';
                    if ( preg_match( $pattern, $normalized, $matches ) ) {
                        $upload_relative = $matches[1];

                        if ( str_contains( $upload_relative, '..' ) ) {
                            continue;
                        }

                        $referenced_paths[] = $upload_relative;
                    }
                }
            }
        }

        $referenced_paths = array_unique( $referenced_paths );
        $copied_count = 0;

        foreach ( $referenced_paths as $relative_path ) {
            $src_path = $uploads_base . '/' . $relative_path;
            $dest_path = $uploads_dest . '/' . $relative_path;

            $real_src_path = realpath( $src_path );
            $real_uploads_base = realpath( $uploads_base );

            if ( $real_src_path === false || $real_uploads_base === false ) {
                continue;
            }

            if ( ! str_starts_with( $real_src_path, $real_uploads_base ) ) {
                continue;
            }

            if ( ! file_exists( $real_src_path ) ) {
                continue;
            }

            $dest_dir = dirname( $dest_path );
            if ( ! is_dir( $dest_dir ) ) {
                mkdir( $dest_dir, 0755, true );
            }

            if ( copy( $real_src_path, $dest_path ) ) {
                $copied_count++;
            }
        }

        if ( $copied_count > 0 ) {
            $this->logger->debug( "プラグイン生成ファイルコピー（uploads）: {$copied_count}ファイル" );
        }
    }

    /**
     * 公開済み投稿・固定ページに添付されているメディアIDを取得
     *
     * @return array attachment IDの配列
     */
    private function get_attached_media_ids(): array {
        global $wpdb;

        $attachment_ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
             WHERE p.post_type = 'attachment'
             AND parent.post_status = 'publish'
             AND parent.post_type IN ('post', 'page')"
        );

        return array_map( 'intval', $attachment_ids );
    }

    /**
     * カスタマイザー設定から全てのattachment IDを動的に取得
     *
     * @return array attachment IDの配列
     */
    private function get_all_theme_mod_attachment_ids(): array {
        $attachment_ids = array();

        $standard_mods = array(
            'custom_logo',
            'header_image',         // 追加
            'header_image_data',
            'background_image',
        );

        foreach ( $standard_mods as $mod_name ) {
            $value = get_theme_mod( $mod_name );

            if ( is_numeric( $value ) && $value > 0 ) {
                $attachment_ids[] = intval( $value );
            } elseif ( is_array( $value ) && isset( $value['attachment_id'] ) ) {
                $attachment_ids[] = intval( $value['attachment_id'] );
            } elseif ( is_string( $value ) && ! empty( $value ) ) {
                $id = attachment_url_to_postid( $value );
                if ( $id ) {
                    $attachment_ids[] = $id;
                }
            }
        }

        $theme_slug = get_option( 'stylesheet' );
        $theme_mods = get_option( "theme_mods_{$theme_slug}", array() );

        if ( is_array( $theme_mods ) ) {
            foreach ( $theme_mods as $key => $value ) {
                if ( in_array( $key, $standard_mods, true ) ) {
                    continue;
                }

                if ( is_numeric( $value ) && $value > 0 ) {
                    $attachment_ids[] = intval( $value );
                }
                elseif ( is_array( $value ) && isset( $value['attachment_id'] ) ) {
                    $attachment_ids[] = intval( $value['attachment_id'] );
                }
                elseif ( is_string( $value ) && str_contains( $value, '/wp-content/uploads/' ) ) {
                    $id = attachment_url_to_postid( $value );
                    if ( $id ) {
                        $attachment_ids[] = $id;
                    }
                }
            }
        }

        return array_unique( array_filter( $attachment_ids ) );
    }

    /**
     * 参照されているメディアIDを取得
     *
     * @return array 添付ファイルIDの配列
     */
    private function get_referenced_attachment_ids(): array {
        global $wpdb;

        $attachment_ids = array();

        $thumbnail_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value > 0"
        );
        $attachment_ids = array_merge( $attachment_ids, $thumbnail_ids );

        $content_ids = $wpdb->get_col(
            "SELECT DISTINCT CAST(
                SUBSTRING(post_content, LOCATE('wp-image-', post_content) + 9, 10) AS UNSIGNED
            ) as attachment_id
            FROM {$wpdb->posts}
            WHERE post_status = 'publish'
            AND post_content LIKE '%wp-image-%'"
        );
        $attachment_ids = array_merge( $attachment_ids, array_filter( $content_ids ) );

        $gallery_posts = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_content LIKE '%[gallery%'"
        );
        foreach ( $gallery_posts as $content ) {
            if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\']/', $content, $matches ) ) {
                foreach ( $matches[1] as $ids_string ) {
                    $ids = array_map( 'intval', explode( ',', $ids_string ) );
                    $attachment_ids = array_merge( $attachment_ids, $ids );
                }
            }
        }

        $site_icon_id = get_option( 'site_icon' );
        if ( $site_icon_id ) {
            $attachment_ids[] = $site_icon_id;
        }

        $customizer_ids = $this->get_all_theme_mod_attachment_ids();
        $attachment_ids = array_merge( $attachment_ids, $customizer_ids );

        $attached_ids = $this->get_attached_media_ids();
        $attachment_ids = array_merge( $attachment_ids, $attached_ids );

        return array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) );
    }

    /**
     * 有効なプラグインの静的アセットのみコピー（参照ベース）
     *
     * HTMLから参照されているアセットのみをコピーし、
     * 参照されていないプラグインアセットは除外する
     *
     * @param string $wp_content_dest コピー先のwp-contentディレクトリ
     * @return bool 成功ならtrue
     */
    private function copy_active_plugin_assets( string $wp_content_dest ): bool {
        $plugins_dir = WP_PLUGIN_DIR;
        $plugins_dest = $wp_content_dest . '/plugins';

        if ( ! is_dir( $plugins_dir ) ) {
            return false;
        }

        $referenced_assets = $this->get_referenced_plugin_assets();

        if ( empty( $referenced_assets ) ) {
            $this->logger->debug( 'プラグインアセット参照なし: スキップ' );
            return true;
        }

        if ( ! is_dir( $plugins_dest ) ) {
            mkdir( $plugins_dest, 0755, true );
        }

        $copied_count = 0;
        $total_size = 0;

        foreach ( $referenced_assets as $relative_path ) {
            $plugin_relative = str_replace( 'wp-content/plugins/', '', $relative_path );
            $src_path = $plugins_dir . '/' . $plugin_relative;
            $dest_path = $plugins_dest . '/' . $plugin_relative;

            if ( ! file_exists( $src_path ) ) {
                continue;
            }

            $dest_dir = dirname( $dest_path );
            if ( ! is_dir( $dest_dir ) ) {
                mkdir( $dest_dir, 0755, true );
            }

            if ( copy( $src_path, $dest_path ) ) {
                $copied_count++;
                $total_size += filesize( $src_path );
            }
        }

        $size_mb = round( $total_size / 1024 / 1024, 2 );
        $this->logger->debug( "プラグインアセットコピー（参照ベース）: {$copied_count}ファイル ({$size_mb}MB)" );
        return true;
    }

    /**
     * プラグインディレクトリから静的アセットのみコピー
     *
     * @param string $src ソースディレクトリ
     * @param string $dest コピー先ディレクトリ
     * @return bool 成功ならtrue
     */
    private function copy_plugin_assets_only( string $src, string $dest ): bool {
        if ( ! is_dir( $src ) ) {
            return false;
        }

        $asset_extensions = array( 'css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'json', 'map' );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $copied = false;
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );

                if ( in_array( $extension, $asset_extensions ) ) {
                    $relative_path = str_replace( trailingslashit( $src ), '', $file->getRealPath() );
                    $dest_path = $dest . '/' . $relative_path;
                    $dest_dir = dirname( $dest_path );

                    if ( ! is_dir( $dest_dir ) ) {
                        mkdir( $dest_dir, 0755, true );
                    }

                    if ( copy( $file->getRealPath(), $dest_path ) ) {
                        $copied = true;
                    }
                }
            }
        }

        return $copied;
    }

    /**
     * HTMLから参照されているプラグインアセットのパスを収集
     *
     * @return array 参照されているアセットパスの配列
     */
    private function get_referenced_plugin_assets(): array {
        $referenced_paths = array();

        $html_files = $this->get_all_html_files( $this->temp_dir );

        foreach ( $html_files as $html_file ) {
            $content = file_get_contents( $html_file );

            preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\']/', $content, $css_matches );
            preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/', $content, $js_matches );
            preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $img_matches );
            preg_match_all( '/srcset=["\']([^"\']+)["\']/', $content, $srcset_matches );
            preg_match_all( '/style=["\'][^"\']*url\(["\']?([^"\')\s]+)["\']?\)/', $content, $style_url_matches );

            $all_paths = array_merge(
                $css_matches[1],
                $js_matches[1],
                $img_matches[1],
                $style_url_matches[1]
            );

            foreach ( $srcset_matches[1] as $srcset ) {
                $srcset_parts = explode( ',', $srcset );
                foreach ( $srcset_parts as $part ) {
                    $part = trim( $part );
                    $path = preg_replace( '/\s+\d+[wx]$/', '', $part );
                    $all_paths[] = trim( $path );
                }
            }

            foreach ( $all_paths as $path ) {
                $content_dirname = ! empty( $this->settings['custom_wp_content'] ) ? $this->settings['custom_wp_content'] : 'wp-content';

                $is_plugin_path = str_contains( $path, 'wp-content/plugins/' ) ||
                                  str_contains( $path, $content_dirname . '/plugins/' );

                if ( $is_plugin_path ) {
                    $normalized = $this->normalize_plugin_asset_path( $path );
                    if ( ! empty( $normalized ) ) {
                        $referenced_paths[] = $normalized;
                    }
                }
            }
        }

        $css_referenced = $this->get_css_referenced_plugin_assets( $referenced_paths );
        $referenced_paths = array_merge( $referenced_paths, $css_referenced );

        return array_unique( $referenced_paths );
    }

    /**
     * 一時ディレクトリ内の全HTMLファイルを再帰的に取得
     *
     * @param string $dir ディレクトリパス
     * @return array HTMLファイルパスの配列
     */
    private function get_all_html_files( string $dir ): array {
        $html_files = array();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && strtolower( $file->getExtension() ) === 'html' ) {
                $html_files[] = $file->getRealPath();
            }
        }

        return $html_files;
    }

    /**
     * プラグインアセットパスを正規化
     *
     * @param string $path アセットパス
     * @return string 正規化されたパス（wp-content/plugins/... 形式）
     */
    private function normalize_plugin_asset_path( string $path ): string {
        $path = preg_replace( '/\?.*$/', '', $path );
        $path = preg_replace( '/#.*$/', '', $path );
        $path = ltrim( $path, '/' );

        if ( preg_match( '/(wp-content\/plugins\/[^\s"\']+)/', $path, $matches ) ) {
            return $matches[1];
        }

        $content_dirname = ! empty( $this->settings['custom_wp_content'] ) ? $this->settings['custom_wp_content'] : 'wp-content';
        if ( $content_dirname !== 'wp-content' ) {
            $folder_pattern = preg_quote( $content_dirname, '/' );
            if ( preg_match( '/(' . $folder_pattern . '\/plugins\/[^\s"\']+)/', $path, $matches ) ) {
                return str_replace( $content_dirname . '/plugins/', 'wp-content/plugins/', $matches[1] );
            }
        }

        return '';
    }

    /**
     * CSSファイル内から参照されているプラグインアセットを収集
     *
     * @param array $html_referenced HTMLから参照されているパス
     * @return array CSS内で参照されているアセットパス
     */
    private function get_css_referenced_plugin_assets( array $html_referenced ): array {
        $css_referenced = array();
        $processed_css = array();

        foreach ( $html_referenced as $path ) {
            if ( preg_match( '/\.css$/i', $path ) ) {
                $css_paths = $this->extract_css_references( $path, $processed_css );
                $css_referenced = array_merge( $css_referenced, $css_paths );
            }
        }

        return array_unique( $css_referenced );
    }

    /**
     * CSSファイルから参照を抽出（再帰的）
     *
     * @param string $css_relative_path CSSファイルの相対パス（wp-content/plugins/...形式）
     * @param array &$processed 処理済みCSSファイルの配列（参照渡し）
     * @return array 参照されているアセットパス
     */
    private function extract_css_references( string $css_relative_path, array &$processed ): array {
        if ( in_array( $css_relative_path, $processed ) ) {
            return array();
        }
        $processed[] = $css_relative_path;

        $referenced = array();

        $css_full_path = ABSPATH . $css_relative_path;

        if ( ! file_exists( $css_full_path ) ) {
            return array();
        }

        $content = file_get_contents( $css_full_path );
        $css_dir = dirname( $css_relative_path );

        preg_match_all( '/@import\s+["\']([^"\']+)["\']/', $content, $import_matches );
        preg_match_all( '/@import\s+url\(["\']?([^"\')\s]+)["\']?\)/', $content, $import_url_matches );
        preg_match_all( '/url\(["\']?([^"\')\s]+)["\']?\)/', $content, $url_matches );

        $all_paths = array_merge(
            $import_matches[1],
            $import_url_matches[1],
            $url_matches[1]
        );

        foreach ( $all_paths as $path ) {
            if ( preg_match( '/^(data:|https?:\/\/|\/\/)/', $path ) ) {
                continue;
            }

            $resolved = $this->resolve_css_relative_path( $css_dir, $path );

            $content_dirname = ! empty( $this->settings['custom_wp_content'] ) ? $this->settings['custom_wp_content'] : 'wp-content';
            $is_plugin_path = str_contains( $resolved, 'wp-content/plugins/' ) ||
                              str_contains( $resolved, $content_dirname . '/plugins/' );

            if ( $is_plugin_path ) {
                $normalized = str_replace( $content_dirname . '/plugins/', 'wp-content/plugins/', $resolved );
                $referenced[] = $normalized;

                if ( preg_match( '/\.css$/i', $normalized ) ) {
                    $nested = $this->extract_css_references( $normalized, $processed );
                    $referenced = array_merge( $referenced, $nested );
                }
            }
        }

        return $referenced;
    }

    /**
     * CSS内の相対パスを解決
     *
     * @param string $css_dir CSSファイルのディレクトリ（wp-content/plugins/...形式）
     * @param string $path 相対パス
     * @return string 解決されたパス
     */
    private function resolve_css_relative_path( string $css_dir, string $path ): string {
        $path = preg_replace( '/\?.*$/', '', $path );
        $path = preg_replace( '/#.*$/', '', $path );

        if ( str_starts_with( $path, '/' ) ) {
            return ltrim( $path, '/' );
        }

        $full_path = $css_dir . '/' . $path;

        $parts = explode( '/', $full_path );
        $resolved = array();
        foreach ( $parts as $part ) {
            if ( $part === '..' ) {
                array_pop( $resolved );
            } elseif ( $part !== '.' && $part !== '' ) {
                $resolved[] = $part;
            }
        }

        return implode( '/', $resolved );
    }

    /**
     * Gitブランチ名が有効かどうかを検証（セキュリティ対策）
     *
     * @param string $branch ブランチ名
     * @return bool 有効ならtrue
     */
    private function is_valid_git_branch_name( string $branch ): bool {
        if ( empty( $branch ) ) {
            return false;
        }

        if ( strlen( $branch ) > 255 ) {
            return false;
        }

        if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_\-\/]*[a-zA-Z0-9]$|^[a-zA-Z0-9]$/', $branch ) ) {
            return false;
        }

        $forbidden_patterns = array(
            '..',           // パストラバーサル
            '//',           // 連続スラッシュ
            '@{',           // Git reflog構文
            '\\',           // バックスラッシュ
            ' ',            // スペース
            '~',            // チルダ
            '^',            // キャレット
            ':',            // コロン
            '?',            // クエスチョン
            '*',            // アスタリスク
            '[',            // ブラケット
            '\x00',         // ヌルバイト
        );

        foreach ( $forbidden_patterns as $pattern ) {
            if ( str_contains( $branch, $pattern ) ) {
                return false;
            }
        }

        if ( substr( $branch, -5 ) === '.lock' ) {
            return false;
        }

        return true;
    }

    /**
     * カスタムサイトマップを生成
     */
    private function generate_sitemap(): void {
        if ( empty( $this->settings['enable_sitemap'] ) ) {
            return;
        }

        $this->logger->debug( 'サイトマップ生成開始' );

        $base_url = ! empty( $this->settings['base_url'] )
            ? untrailingslashit( $this->settings['base_url'] )
            : untrailingslashit( get_site_url() );

        $sitemap_xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $sitemap_xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ( $this->generated_html_pages as $page ) {
            $url = $base_url . $page['url'];

            if ( stripos( $url, '/wp-admin' ) !== false ) {
                continue;
            }

            $priority = ( $page['url'] === '/' || $page['url'] === '' ) ? '1.0' : '0.8';

            $sitemap_xml .= '  <url>' . "\n";
            $sitemap_xml .= '    <loc>' . esc_url( $url ) . '</loc>' . "\n";
            $sitemap_xml .= '    <changefreq>weekly</changefreq>' . "\n";
            $sitemap_xml .= '    <priority>' . $priority . '</priority>' . "\n";
            $sitemap_xml .= '  </url>' . "\n";
        }

        $sitemap_xml .= '</urlset>' . "\n";

        $sitemap_path = $this->temp_dir . '/sitemap.xml';
        file_put_contents( $sitemap_path, $sitemap_xml );

        $page_count = count( $this->generated_html_pages );
        $this->logger->debug( "サイトマップ生成完了: {$page_count}ページ" );
    }

    /**
     * HTMLを圧縮
     *
     * @param string $html HTML内容
     * @return string 圧縮後のHTML
     */
    private function minify_html( string $html ): string {
        if ( empty( $this->settings['minify_html'] ) ) {
            return $html;
        }

        // 保護対象タグ内を退避
        $protected = array();
        $html = preg_replace_callback(
            '/<(script|style|noscript|pre|textarea)(\s[^>]*)?>(.*?)<\/\1>/isu',
            function( $matches ) use ( &$protected ) {
                $placeholder = '___PROTECTED_' . count( $protected ) . '___';
                $protected[ $placeholder ] = $matches[0];
                return $placeholder;
            },
            $html
        );

        $html = preg_replace('/(<!DOCTYPE[^>]+>)\s+/i', '$1' . "\n", $html);

        // HTMLコメント削除（条件付きコメント<!--[if]>は保持）
        $html = preg_replace('/<!--(?!\[if\s)(?!<!)[^\[].*?-->/s', '', $html);

        $html = preg_replace('/\s+/u', ' ', $html);
        $html = preg_replace('/>\s+</u', '><', $html);

        foreach ( $protected as $placeholder => $content ) {
            $html = str_replace( $placeholder, $content, $html );
        }

        return trim( $html );
    }

    /**
     * インラインCSSを圧縮
     *
     * @param string $html HTML内容
     * @return string 圧縮後のHTML
     */
    private function minify_inline_css( string $html ): string {
        if ( empty( $this->settings['minify_css'] ) ) {
            return $html;
        }

        $html = preg_replace_callback(
            '/<style([^>]*)>(.*?)<\/style>/isu',
            function( $matches ) {
                $css = $matches[2];
                $css = $this->minify_css_content( $css );
                return '<style' . $matches[1] . '>' . $css . '</style>';
            },
            $html
        );

        $html = preg_replace_callback(
            '/\sstyle=(["\'])(.*?)\1/iu',
            function( $matches ) {
                $css = $matches[2];
                $css = $this->minify_css_content( $css );
                return ' style=' . $matches[1] . $css . $matches[1];
            },
            $html
        );

        return $html;
    }

    /**
     * CSS内容を圧縮
     *
     * @param string $css CSS内容
     * @return string 圧縮後のCSS
     */
    private function minify_css_content( string $css ): string {
        $css = preg_replace_callback(
            '/calc\s*\([^)]+\)/iu',
            function( $matches ) {
                return str_replace( ' ', '___SPACE___', $matches[0] );
            },
            $css
        );

        $css = preg_replace('/\/\*.*?\*\//su', '', $css);
        $css = preg_replace('/\s+/u', ' ', $css);
        $css = preg_replace('/\s*:\s*/u', ':', $css);
        $css = preg_replace('/\s*;\s*/u', ';', $css);
        $css = preg_replace('/;\s*$/u', '', $css);

        $css = str_replace( '___SPACE___', ' ', $css );

        return trim( $css );
    }

    /**
     * ファイルパスから適切なContent-Typeを取得
     *
     * @param string $file_path ファイルパス
     * @return string Content-Type
     */
    private function get_content_type( string $file_path ): string {
        $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        $mime_types = array(
            'xml'  => 'application/xml',
            'html' => 'text/html',
            'htm'  => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'  => 'font/ttf',
            'eot'  => 'application/vnd.ms-fontobject',
        );

        return $mime_types[ $extension ] ?? 'application/octet-stream';
    }
}
