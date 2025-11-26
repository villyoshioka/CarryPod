<?php
/**
 * 静的化生成クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SGE_Generator {

    /**
     * ロガーインスタンス
     */
    private $logger;

    /**
     * 設定
     */
    private $settings;

    /**
     * キャッシュインスタンス
     */
    private $cache;

    /**
     * 一時ディレクトリ
     */
    private $temp_dir;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->logger = SGE_Logger::get_instance();
        $settings_manager = SGE_Settings::get_instance();
        $this->settings = $settings_manager->get_settings();
        $this->cache = SGE_Cache::get_instance();
        $this->temp_dir = sys_get_temp_dir() . '/sge-' . wp_generate_password( 12, false );

        // コミットメッセージが空の場合は実行時に動的生成
        if ( empty( $this->settings['commit_message'] ) ) {
            $this->settings['commit_message'] = 'update:' . current_time( 'Ymd_His' );
        }
    }

    /**
     * 静的化を実行
     */
    public function generate() {
        try {
            // PHP実行時間制限を無制限に設定（長時間処理対応）
            if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
                @set_time_limit( 0 );
            }

            // 実行中フラグをセット
            set_transient( 'sge_manual_running', true, 3600 );

            // 実行開始を明示的にログに記録
            $this->logger->add_log( '========================================' );
            $this->logger->add_log( '静的化を開始しました - ' . current_time( 'Y-m-d H:i:s' ) );
            $this->logger->add_log( '========================================' );
            $this->logger->clear_progress();

            // 一時ディレクトリが既に存在する場合は削除
            if ( is_dir( $this->temp_dir ) ) {
                $this->remove_directory( $this->temp_dir );
            }

            // 一時ディレクトリを作成
            if ( ! mkdir( $this->temp_dir, 0755, true ) ) {
                $this->logger->add_log( '一時ディレクトリの作成に失敗しました', true );
                delete_transient( 'sge_manual_running' );
                return;
            }

            // URLリストを取得
            $urls = $this->get_urls_to_generate();
            $total_urls = count( $urls );
            $this->logger->add_log( $total_urls . '個のページを生成します' );

            // 総ステップ数を計算
            // ページ生成(0-80%) + アセット等(80-90%) + 出力処理(90-100%)
            $total_steps = 100; // パーセンテージベースで管理
            $current_step = 0;
            $page_step_ratio = 80.0 / max( 1, $total_urls ); // ページ生成は80%まで

            // 各URLをHTMLに変換
            $generated_files = array();
            $cache_enabled = ! empty( $this->settings['cache_enabled'] );
            $cache_used_count = 0;
            $generated_count = 0;

            // 並列クローリングを使用するかどうか
            $use_parallel = ! empty( $this->settings['use_parallel_crawling'] );

            if ( $use_parallel && class_exists( 'SGE_Parallel_Crawler' ) ) {
                // 並列クローラーを使用（WP2Staticスタイル）
                $this->logger->add_log( '並列クローリングモードで処理を開始' );

                $parallel_crawler = new SGE_Parallel_Crawler();
                $parallel_crawler->set_concurrency( 5 ); // 同時に5つのURLを処理
                $parallel_crawler->set_timeout( 30 );

                // バッチ処理でURLをクロール
                $batch_size = 10; // 10URLずつ処理
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

                    // 並列クローリング実行
                    $results = $cache_enabled ?
                        $parallel_crawler->crawl_with_cache( $batch_urls ) :
                        $parallel_crawler->crawl_urls( $batch_urls );

                    // 結果を処理
                    foreach ( $results as $url => $result ) {
                        if ( $result['cached'] ) {
                            $cache_used_count++;
                        } else {
                            $generated_count++;
                        }

                        if ( $result['status_code'] == 200 && ! empty( $result['content'] ) ) {
                            $html = $result['content'];

                            // URL形式を変換
                            if ( $this->settings['url_mode'] === 'relative' ) {
                                $html = $this->convert_to_relative_urls( $html );
                            }

                            // WordPress動的要素を削除/置換
                            $html = $this->sanitize_static_html( $html );

                            // アーカイブリンクを除去・変更
                            $html = $this->remove_archive_links( $html );

                            // ファイルパスを生成
                            $path = $this->url_to_path( $url );

                            // ファイルを保存
                            $file_path = $this->temp_dir . '/' . $path;
                            $dir = dirname( $file_path );
                            if ( ! is_dir( $dir ) ) {
                                mkdir( $dir, 0755, true );
                            }
                            file_put_contents( $file_path, $html );

                            $generated_files[ $path ] = $html;
                        }
                    }
                }
            } else {
                // 従来の逐次処理
                foreach ( $urls as $index => $url ) {
                    $current_step = (int) ( ( $index + 1 ) * $page_step_ratio );
                    $this->logger->update_progress( $current_step, $total_steps, 'ページを生成中: ' . ( $index + 1 ) . ' / ' . $total_urls );

                    // URLから投稿IDを取得（存在する場合）
                    $post_id = url_to_postid( $url );

                    // キャッシュが有効で、かつキャッシュが有効な場合
                    if ( $cache_enabled && $this->cache->is_valid( $url, $post_id ) ) {
                        // キャッシュから取得
                        $html = $this->cache->get( $url );
                        if ( $html !== false ) {
                            $path = $this->url_to_path( $url );

                            // ファイルを保存
                            $file_path = $this->temp_dir . '/' . $path;
                            $dir = dirname( $file_path );
                            if ( ! is_dir( $dir ) ) {
                                mkdir( $dir, 0755, true );
                            }
                            file_put_contents( $file_path, $html );

                            $generated_files[ $path ] = $html;
                            $cache_used_count++;
                            continue;
                        }
                    }

                    // キャッシュがない、または無効な場合は新規生成
                    $result = $this->generate_html( $url );
                    if ( ! is_wp_error( $result ) ) {
                        $generated_files[ $result['path'] ] = $result['content'];
                        $generated_count++;

                        // キャッシュが有効な場合は保存
                        if ( $cache_enabled ) {
                            $this->cache->set( $url, $result['content'], $post_id ? $post_id : null );
                        }
                    }
                }
            }

            // ページ生成のサマリーをログに追加
            if ( $cache_used_count > 0 ) {
                $this->logger->add_log( $cache_used_count . '個のページをキャッシュから取得しました' );
            }
            if ( $generated_count > 0 ) {
                $this->logger->add_log( $generated_count . '個のページを新規生成しました' );
            }

            // アセットファイルをコピー (80-83%)
            $this->logger->update_progress( 81, $total_steps, 'アセットファイルをコピー中...' );
            $this->copy_assets();

            // 巻き込みファイルをコピー (83-86%)
            $this->logger->update_progress( 84, $total_steps, '巻き込みファイルをコピー中...' );
            $this->copy_included_files();

            // 除外ファイルを削除 (86-90%)
            $this->logger->update_progress( 87, $total_steps, '除外ファイルを削除中...' );
            $this->remove_excluded_files();

            // 出力処理 (90-100%)
            // 有効な出力方法の数を数える
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
            $output_step = 0;
            $output_progress_per_step = $output_count > 0 ? 10.0 / $output_count : 10;

            // ローカル出力が有効な場合
            if ( ! empty( $this->settings['local_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->update_progress( $progress, $total_steps, 'ローカルディレクトリに出力中...' );
                $this->output_to_local();
            }

            // GitHub出力が有効な場合
            if ( ! empty( $this->settings['github_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->update_progress( $progress, $total_steps, 'GitHubに出力中...' );
                $this->output_to_github_api();
            }

            // ローカルGit出力が有効な場合
            if ( ! empty( $this->settings['git_local_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->update_progress( $progress, $total_steps, 'ローカルGitに出力中...' );
                $this->output_to_git_local();
            }

            // ZIP出力が有効な場合
            if ( ! empty( $this->settings['zip_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->update_progress( $progress, $total_steps, 'ZIPファイルを作成中...' );
                $this->output_to_zip();
            }

            // 一時ディレクトリを削除
            $this->remove_directory( $this->temp_dir );

            // 完了
            $this->logger->update_progress( $total_steps, $total_steps, '静的化が完了しました！' );
            $this->logger->add_log( '静的化が完了しました' );

        } catch ( Exception $e ) {
            // エラーが発生した場合
            $this->logger->add_log( 'エラーが発生しました: ' . $e->getMessage(), true );
            $this->logger->update_progress( 0, 0, 'エラーが発生しました' );
        } finally {
            // 一時ディレクトリを削除（エラー時も必ず削除）
            if ( is_dir( $this->temp_dir ) ) {
                $this->remove_directory( $this->temp_dir );
            }
            // 必ず実行中フラグを削除
            delete_transient( 'sge_manual_running' );
        }
    }

    /**
     * 静的化するURLリストを取得
     *
     * @return array URLの配列
     */
    private function get_urls_to_generate() {
        $urls = array();

        // トップページ
        $urls[] = home_url( '/' );

        // トップページのページネーション
        $posts_per_page = get_option( 'posts_per_page' );
        $post_count = wp_count_posts( 'post' )->publish;
        $max_pages = ceil( $post_count / $posts_per_page );
        for ( $i = 2; $i <= $max_pages; $i++ ) {
            $urls[] = home_url( '/page/' . $i . '/' );
        }

        // 投稿ページ
        $posts = get_posts( array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
        ) );
        foreach ( $posts as $post ) {
            $urls[] = get_permalink( $post->ID );
        }

        // 固定ページ
        $pages = get_posts( array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
        ) );
        foreach ( $pages as $page ) {
            $urls[] = get_permalink( $page->ID );
        }

        // カスタム投稿タイプ
        $post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
        foreach ( $post_types as $post_type ) {
            $custom_posts = get_posts( array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'numberposts' => -1,
            ) );
            foreach ( $custom_posts as $custom_post ) {
                $urls[] = get_permalink( $custom_post->ID );
            }

            // アーカイブページ（has_archive => trueのもの）
            $post_type_obj = get_post_type_object( $post_type );
            if ( $post_type_obj->has_archive ) {
                $urls[] = get_post_type_archive_link( $post_type );
            }
        }

        // カテゴリアーカイブ
        $categories = get_categories( array( 'hide_empty' => true ) );
        foreach ( $categories as $category ) {
            $urls[] = get_category_link( $category->term_id );

            // ページネーション
            $posts_per_page = get_option( 'posts_per_page' );
            $post_count = $category->count;
            $max_pages = ceil( $post_count / $posts_per_page );
            for ( $i = 2; $i <= $max_pages; $i++ ) {
                $urls[] = get_category_link( $category->term_id ) . 'page/' . $i . '/';
            }
        }

        // タグアーカイブ
        if ( ! empty( $this->settings['enable_tag_archive'] ) ) {
            $tags = get_tags( array( 'hide_empty' => true ) );
            foreach ( $tags as $tag ) {
                $urls[] = get_tag_link( $tag->term_id );

                // ページネーション
                $posts_per_page = get_option( 'posts_per_page' );
                $post_count = $tag->count;
                $max_pages = ceil( $post_count / $posts_per_page );
                for ( $i = 2; $i <= $max_pages; $i++ ) {
                    $urls[] = get_tag_link( $tag->term_id ) . 'page/' . $i . '/';
                }
            }
        }

        // 日付アーカイブ
        if ( ! empty( $this->settings['enable_date_archive'] ) ) {
            $years = $this->get_archive_years();
            foreach ( $years as $year ) {
                $urls[] = get_year_link( $year );

                $months = $this->get_archive_months( $year );
                foreach ( $months as $month ) {
                    $urls[] = get_month_link( $year, $month );

                    $days = $this->get_archive_days( $year, $month );
                    foreach ( $days as $day ) {
                        $urls[] = get_day_link( $year, $month, $day );
                    }
                }
            }
        }

        // カスタムタクソノミーアーカイブ
        $taxonomies = get_taxonomies( array( 'public' => true, '_builtin' => false ) );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
            foreach ( $terms as $term ) {
                $urls[] = get_term_link( $term );
            }
        }

        // 投稿フォーマットアーカイブ（/type/image/ など）
        if ( ! empty( $this->settings['enable_post_format_archive'] ) ) {
            $post_formats = get_terms( array(
                'taxonomy' => 'post_format',
                'hide_empty' => true,
            ) );
            if ( ! is_wp_error( $post_formats ) && ! empty( $post_formats ) ) {
                foreach ( $post_formats as $format ) {
                    $format_link = get_term_link( $format );
                    if ( ! is_wp_error( $format_link ) ) {
                        $urls[] = $format_link;

                        // ページネーション
                        $posts_per_page = get_option( 'posts_per_page' );
                        $post_count = $format->count;
                        $max_pages = ceil( $post_count / $posts_per_page );
                        for ( $i = 2; $i <= $max_pages; $i++ ) {
                            $urls[] = $format_link . 'page/' . $i . '/';
                        }
                    }
                }
            }
        }

        // 著者アーカイブ
        if ( ! empty( $this->settings['enable_author_archive'] ) ) {
            $users = get_users( array( 'who' => 'authors' ) );
            foreach ( $users as $user ) {
                $urls[] = get_author_posts_url( $user->ID );

                // ページネーション
                $posts_per_page = get_option( 'posts_per_page' );
                $post_count = count_user_posts( $user->ID );
                $max_pages = ceil( $post_count / $posts_per_page );
                for ( $i = 2; $i <= $max_pages; $i++ ) {
                    $urls[] = get_author_posts_url( $user->ID ) . 'page/' . $i . '/';
                }
            }
        }

        // RSSフィード
        if ( ! empty( $this->settings['enable_rss'] ) ) {
            $urls[] = home_url( '/feed/' );
            $urls[] = home_url( '/feed/rss/' );
            $urls[] = home_url( '/feed/rss2/' );
            $urls[] = home_url( '/feed/atom/' );
            $urls[] = home_url( '/comments/feed/' );
        }

        // サイトマップ（WordPress 5.5以降のネイティブサイトマップ）
        if ( ! empty( $this->settings['enable_sitemap'] ) ) {
            $urls[] = home_url( '/sitemap.xml' );
            $urls[] = home_url( '/wp-sitemap.xml' );
            $urls[] = home_url( '/wp-sitemap-posts-post-1.xml' );
            $urls[] = home_url( '/wp-sitemap-posts-page-1.xml' );
            $urls[] = home_url( '/wp-sitemap-taxonomies-category-1.xml' );
            $urls[] = home_url( '/wp-sitemap-taxonomies-post_tag-1.xml' );
            $urls[] = home_url( '/wp-sitemap-taxonomies-post_format-1.xml' );
            $urls[] = home_url( '/wp-sitemap-users-1.xml' );
        }

        return array_unique( $urls );
    }

    /**
     * アーカイブがある年を取得
     *
     * @return array 年の配列
     */
    private function get_archive_years() {
        global $wpdb;
        $years = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT YEAR(post_date) FROM {$wpdb->posts} WHERE post_status = %s AND post_type = %s ORDER BY post_date DESC",
                'publish',
                'post'
            )
        );
        return $years;
    }

    /**
     * 指定された年のアーカイブがある月を取得
     *
     * @param int $year 年
     * @return array 月の配列
     */
    private function get_archive_months( $year ) {
        global $wpdb;
        $months = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT MONTH(post_date) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' AND YEAR(post_date) = %d ORDER BY post_date DESC", $year ) );
        return $months;
    }

    /**
     * 指定された年月のアーカイブがある日を取得
     *
     * @param int $year 年
     * @param int $month 月
     * @return array 日の配列
     */
    private function get_archive_days( $year, $month ) {
        global $wpdb;
        $days = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT DAY(post_date) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' AND YEAR(post_date) = %d AND MONTH(post_date) = %d ORDER BY post_date DESC", $year, $month ) );
        return $days;
    }

    /**
     * URLをHTMLに変換
     *
     * @param string $url URL
     * @return array|WP_Error 成功なら配列（path, content）、失敗ならWP_Error
     */
    private function generate_html( $url ) {
        // URLからHTMLを取得
        // localhostの場合のみSSL検証を無効化（開発環境対応）
        $parsed_url = parse_url( $url );
        $is_localhost = in_array( $parsed_url['host'], array( 'localhost', '127.0.0.1', '::1' ) );

        $response = wp_remote_get( $url, array(
            'timeout' => isset( $this->settings['timeout'] ) ? $this->settings['timeout'] : 600,
            'sslverify' => ! $is_localhost,
            'headers' => array(
                'User-Agent' => 'Static Generation Engine/1.0',
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

        // HTMLが空でないか確認
        if ( empty( $html ) ) {
            $this->logger->add_log( "空のレスポンス: {$url}", true );
            return new WP_Error( 'empty_response', '空のレスポンス' );
        }

        // コンテンツのサイズを記録（デバッグ用）
        $original_size = strlen( $html );
        $this->logger->add_log( "取得したコンテンツ: {$url} - サイズ: " . number_format( $original_size ) . " バイト" );

        // XMLファイル（フィード、サイトマップ）かどうかを判定
        $is_xml = ( strpos( $url, '/feed' ) !== false ||
                   strpos( $url, '/rss' ) !== false ||
                   strpos( $url, '/atom' ) !== false ||
                   strpos( $url, '.xml' ) !== false ||
                   strpos( $html, '<?xml' ) === 0 );

        // XMLファイルでない場合のみHTML処理を実行
        if ( ! $is_xml ) {
            // URL形式を変換
            if ( $this->settings['url_mode'] === 'relative' ) {
                $html = $this->convert_to_relative_urls( $html );
            }

            // WordPress動的要素を削除/置換（最小限の処理に変更済み）
            $html = $this->sanitize_static_html( $html );

            // アーカイブリンクを除去・変更
            $html = $this->remove_archive_links( $html );

            // CSS自動修正は削除 - WordPressが生成したHTMLをそのまま使用

            // 処理後のサイズを記録（デバッグ用）
            $processed_size = strlen( $html );
            if ( $processed_size < $original_size * 0.1 ) {
                // 90%以上削減された場合は警告
                $this->logger->add_log(
                    "警告: HTMLサイズが大幅に減少: {$url} - " .
                    number_format( $original_size ) . " → " .
                    number_format( $processed_size ) . " バイト",
                    true
                );
            }

            // HTMLの基本的な検証
            if ( strpos( $html, '</html>' ) === false || strpos( $html, '<body' ) === false ) {
                $this->logger->add_log( "警告: 不完全なHTML構造: {$url}", true );
            }

            // CSS構文チェックは削除 - WordPressが生成したHTMLをそのまま使用
        } else {
            // XMLファイルの場合はそのまま出力
            $this->logger->add_log( "XMLファイルを取得: {$url}" );

            // XMLの場合、絶対URLを相対URLに変換（サイトマップのリンクなど）
            if ( $this->settings['url_mode'] === 'relative' ) {
                $site_url = trailingslashit( get_site_url() );
                $home_url = trailingslashit( get_home_url() );

                // http:// と https:// の両方に対応
                $site_url_http = str_replace( 'https://', 'http://', $site_url );
                $site_url_https = str_replace( 'http://', 'https://', $site_url );
                $home_url_http = str_replace( 'https://', 'http://', $home_url );
                $home_url_https = str_replace( 'http://', 'https://', $home_url );

                // XML内のURLを相対URLに変換
                $html = str_replace( array( $site_url_https, $site_url_http, $home_url_https, $home_url_http ), '/', $html );
            }
        }

        // ファイルパスを生成
        $path = $this->url_to_path( $url );

        // ファイルを保存
        $file_path = $this->temp_dir . '/' . $path;
        $dir = dirname( $file_path );
        if ( ! is_dir( $dir ) ) {
            mkdir( $dir, 0755, true );
        }
        file_put_contents( $file_path, $html );

        // 個別ページ生成のログは詳細すぎるので出力しない（ログ上限を超えて重要なログが押し出されるのを防ぐ）
        // $this->logger->add_log( "ページを生成しました: {$url}" );

        return array( 'path' => $path, 'content' => $html );
    }

    /**
     * URLをファイルパスに変換
     *
     * @param string $url URL
     * @return string ファイルパス
     */
    private function url_to_path( $url ) {
        $parsed = parse_url( $url );
        $path = isset( $parsed['path'] ) ? $parsed['path'] : '/';

        // トレイリングスラッシュを削除
        $path = rtrim( $path, '/' );

        // 空の場合はindex.htmlに
        if ( empty( $path ) || $path === '/' ) {
            return 'index.html';
        }

        // フィードの場合は専用の拡張子を付与
        if ( preg_match( '#/feed(/.*)?$#', $path ) || preg_match( '#/rss(/.*)?$#', $path ) || preg_match( '#/atom(/.*)?$#', $path ) ) {
            // /feed/ -> /feed/index.xml
            // /feed/rss/ -> /feed/rss/index.xml
            // /comments/feed/ -> /comments/feed/index.xml
            $path .= '/index.xml';
        } elseif ( ! preg_match( '/\.[a-z0-9]+$/i', $path ) ) {
            // 拡張子がない場合は/index.htmlを追加
            $path .= '/index.html';
        }

        // 先頭のスラッシュを削除
        $path = ltrim( $path, '/' );

        return $path;
    }

    /**
     * 絶対URLを相対URLに変換
     *
     * @param string $html HTML
     * @return string 変換後のHTML
     */
    private function convert_to_relative_urls( $html ) {
        $site_url = trailingslashit( get_site_url() );
        $home_url = trailingslashit( get_home_url() );

        // http:// と https:// の両方に対応
        $site_url_http = str_replace( 'https://', 'http://', $site_url );
        $site_url_https = str_replace( 'http://', 'https://', $site_url );
        $home_url_http = str_replace( 'https://', 'http://', $home_url );
        $home_url_https = str_replace( 'http://', 'https://', $home_url );

        // <style>タグ内のCSSを安全に処理
        $html = preg_replace_callback(
            '/<style([^>]*)>(.*?)<\/style>/is',
            function( $matches ) use ( $site_url_https, $site_url_http, $home_url_https, $home_url_http ) {
                $attributes = $matches[1];
                $css = $matches[2];

                // CSS内のurl()を慎重に処理（括弧の整合性を保つ）
                $css = preg_replace_callback(
                    '/url\s*\(\s*([\'"]?)([^\'"\)]+)\1\s*\)/i',
                    function( $url_matches ) use ( $site_url_https, $site_url_http, $home_url_https, $home_url_http ) {
                        $quote = $url_matches[1];
                        $url = $url_matches[2];

                        // dataスキームやhttpスキーム以外はそのまま
                        if ( strpos( $url, 'data:' ) === 0 || strpos( $url, '#' ) === 0 ) {
                            return $url_matches[0];
                        }

                        // 絶対URLを相対URLに変換
                        $url = str_replace( array( $site_url_https, $site_url_http, $home_url_https, $home_url_http ), '', $url );

                        // 先頭にスラッシュがない場合は追加
                        if ( strpos( $url, '/' ) !== 0 && strpos( $url, 'http' ) !== 0 ) {
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

        // HTML属性内のURLを変換（src, href, action, data-* など）
        $html = str_replace( $site_url_https, '/', $html );
        $html = str_replace( $site_url_http, '/', $html );
        $html = str_replace( $home_url_https, '/', $html );
        $html = str_replace( $home_url_http, '/', $html );

        // スラッシュが2つ続く場合は1つに（ただしhttp://やhttps://は除く）
        $html = preg_replace( '#(?<!:)//+#', '/', $html );

        return $html;
    }

    /**
     * HTML内の動的要素を静的化用に処理
     *
     * @param string $html HTML内容
     * @return string 処理後のHTML
     */
    private function sanitize_static_html( $html ) {
        // 一時的に最小限の処理のみ行う（デバッグ用）
        // 問題の原因を特定するため、まずは何も削除しない

        // wp_nonce フィールドを削除（これは安全）
        $html = preg_replace( '/<input[^>]*name=[\'"]_wpnonce[\'"][^>]*>/i', '', $html );
        $html = preg_replace( '/<input[^>]*name=[\'"]_wp_http_referer[\'"][^>]*>/i', '', $html );

        // REST API リンクを削除（headタグ内のメタ情報のみ）
        $html = preg_replace( '/<link[^>]*rel=[\'"]https:\/\/api\.w\.org[\'"][^>]*>/i', '', $html );

        // oEmbed リンクを削除（headタグ内のメタ情報のみ）
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
    private function remove_archive_links( $html ) {
        // タグリンクを完全に除去
        if ( empty( $this->settings['enable_tag_archive'] ) ) {
            // タグリンクを含む要素全体を削除
            // 一般的なパターン: <a href="/tag/..." rel="tag">タグ名</a>
            $html = preg_replace( '/<a\s+[^>]*?rel=[\'"]tag[\'"][^>]*?>.*?<\/a>/is', '', $html );
            // タグリストを含むdiv/span/ul要素を削除
            $html = preg_replace( '/<(div|span|ul)[^>]*?class=[\'"][^"\']*\b(tags?|post-tags?|entry-tags?)\b[^"\']*[\'"][^>]*?>.*?<\/\1>/is', '', $html );
        }

        // 日付リンクをテキストに変更
        if ( empty( $this->settings['enable_date_archive'] ) ) {
            // <a href="/2024/01/15/">2024年1月15日</a> → <span>2024年1月15日</span>
            $html = preg_replace_callback(
                '/<a\s+([^>]*?)href=[\'"]([^"\']*?\d{4}\/\d{2}(?:\/\d{2})?\/)[\'"]([^>]*?)>(.*?)<\/a>/is',
                function( $matches ) {
                    $link_text = $matches[4];
                    return '<span>' . $link_text . '</span>';
                },
                $html
            );
        }

        // 著者リンクをテキストに変更
        if ( empty( $this->settings['enable_author_archive'] ) ) {
            // <a href="/author/..." class="author">著者名</a> → <span>著者名</span>
            $html = preg_replace_callback(
                '/<a\s+([^>]*?)href=[\'"]([^"\']*?\/author\/[^"\']+)[\'"]([^>]*?)>(.*?)<\/a>/is',
                function( $matches ) {
                    $link_text = $matches[4];
                    return '<span>' . $link_text . '</span>';
                },
                $html
            );
            // rel="author"パターンも対応
            $html = preg_replace_callback(
                '/<a\s+([^>]*?)rel=[\'"]author[\'"]([^>]*?)>(.*?)<\/a>/is',
                function( $matches ) {
                    $link_text = $matches[3];
                    return '<span>' . $link_text . '</span>';
                },
                $html
            );
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
    private function convert_asset_urls( $content, $type ) {
        $site_url = trailingslashit( get_site_url() );
        $home_url = trailingslashit( get_home_url() );

        // http:// と https:// の両方に対応
        $site_url_http = str_replace( 'https://', 'http://', $site_url );
        $site_url_https = str_replace( 'http://', 'https://', $site_url );
        $home_url_http = str_replace( 'https://', 'http://', $home_url );
        $home_url_https = str_replace( 'http://', 'https://', $home_url );

        if ( $type === 'css' ) {
            // CSSファイル内のurl()を処理
            $content = preg_replace_callback(
                '/url\s*\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i',
                function( $matches ) use ( $site_url_https, $site_url_http, $home_url_https, $home_url_http ) {
                    $url = $matches[1];

                    // 絶対URLを相対URLに変換
                    $url = str_replace( $site_url_https, '/', $url );
                    $url = str_replace( $site_url_http, '/', $url );
                    $url = str_replace( $home_url_https, '/', $url );
                    $url = str_replace( $home_url_http, '/', $url );

                    return 'url(' . $url . ')';
                },
                $content
            );

            // @import文も処理
            $content = preg_replace_callback(
                '/@import\s+[\'"]([^\'"\)]+)[\'"]/i',
                function( $matches ) use ( $site_url_https, $site_url_http, $home_url_https, $home_url_http ) {
                    $url = $matches[1];

                    // 絶対URLを相対URLに変換
                    $url = str_replace( $site_url_https, '/', $url );
                    $url = str_replace( $site_url_http, '/', $url );
                    $url = str_replace( $home_url_https, '/', $url );
                    $url = str_replace( $home_url_http, '/', $url );

                    return '@import "' . $url . '"';
                },
                $content
            );
        }

        // JSファイルとCSSファイル両方で、通常の文字列内のURLも変換
        $content = str_replace( $site_url_https, '/', $content );
        $content = str_replace( $site_url_http, '/', $content );
        $content = str_replace( $home_url_https, '/', $content );
        $content = str_replace( $home_url_http, '/', $content );

        // WordPress特有のAjax URLを静的化用に変換（JSファイルの場合）
        if ( $type === 'js' ) {
            // admin-ajax.phpへの参照を無効化またはダミーに置換
            $content = str_replace( '/wp-admin/admin-ajax.php', '#', $content );

            // wp-json API エンドポイントも同様に処理
            $content = preg_replace( '/\/wp-json\/[^\'"\s]*/i', '#', $content );
        }

        return $content;
    }

    /**
     * アセットファイルをコピー
     */
    private function copy_assets() {
        $success = true;

        // wp-content ディレクトリ全体をコピー（より包括的なアプローチ）
        $wp_content_dir = WP_CONTENT_DIR;
        if ( is_dir( $wp_content_dir ) ) {
            $this->logger->add_log( 'wp-content ディレクトリをコピー開始: ' . $wp_content_dir );
            $result = $this->copy_directory_recursive( $wp_content_dir, $this->temp_dir . '/wp-content', true );
            if ( $result ) {
                $this->logger->add_log( 'wp-content ディレクトリのコピーが完了しました' );
            } else {
                $this->logger->add_log( 'wp-content ディレクトリのコピーで一部エラーが発生しました', true );
                $success = false;
            }
        } else {
            $this->logger->add_log( 'wp-content ディレクトリが見つかりません: ' . $wp_content_dir, true );
        }

        // wp-includes ディレクトリをコピー
        $wp_includes_dir = ABSPATH . 'wp-includes';
        if ( is_dir( $wp_includes_dir ) ) {
            $this->logger->add_log( 'wp-includes ディレクトリをコピー開始: ' . $wp_includes_dir );
            $result = $this->copy_directory_recursive( $wp_includes_dir, $this->temp_dir . '/wp-includes', true );
            if ( $result ) {
                $this->logger->add_log( 'wp-includes ディレクトリのコピーが完了しました' );
            } else {
                $this->logger->add_log( 'wp-includes ディレクトリのコピーで一部エラーが発生しました', true );
                $success = false;
            }
        } else {
            $this->logger->add_log( 'wp-includes ディレクトリが見つかりません: ' . $wp_includes_dir, true );
        }

        // type ディレクトリをコピー（WordPressルートディレクトリに存在する場合）
        $type_dir = ABSPATH . 'type';
        if ( is_dir( $type_dir ) ) {
            $this->logger->add_log( 'type ディレクトリをコピー開始: ' . $type_dir );
            $result = $this->copy_directory_recursive( $type_dir, $this->temp_dir . '/type', true );
            if ( $result ) {
                $this->logger->add_log( 'type ディレクトリのコピーが完了しました' );
            } else {
                $this->logger->add_log( 'type ディレクトリのコピーで一部エラーが発生しました', true );
                $success = false;
            }
        }
        // 存在しない場合はログを出力しない（通常の状態）

        // その他のカスタム投稿タイプ用ディレクトリをチェック（例: /news/, /products/ など）
        $custom_post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
        foreach ( $custom_post_types as $post_type ) {
            $custom_dir = ABSPATH . $post_type;
            if ( is_dir( $custom_dir ) ) {
                $this->logger->add_log( 'カスタム投稿タイプディレクトリをコピー開始: ' . $custom_dir );
                $result = $this->copy_directory_recursive( $custom_dir, $this->temp_dir . '/' . $post_type, true );
                if ( $result ) {
                    $this->logger->add_log( $post_type . ' ディレクトリのコピーが完了しました' );
                } else {
                    $this->logger->add_log( $post_type . ' ディレクトリのコピーで一部エラーが発生しました', true );
                    $success = false;
                }
            }
        }

        // ファビコン（サイトアイコン）をコピー
        $this->copy_favicon();

        // robots.txtを生成
        if ( ! empty( $this->settings['enable_robots_txt'] ) ) {
            $this->generate_robots_txt();
        }

        if ( $success ) {
            $this->logger->add_log( 'すべてのアセットファイルのコピーが完了しました' );
        } else {
            $this->logger->add_log( 'アセットファイルのコピーで一部エラーが発生しました', true );
        }

        return $success;
    }

    /**
     * 巻き込みファイルをコピー
     */
    private function copy_included_files() {
        if ( empty( $this->settings['include_paths'] ) ) {
            return;
        }

        $paths = explode( "\n", $this->settings['include_paths'] );
        foreach ( $paths as $path ) {
            $path = trim( $path );
            if ( empty( $path ) ) {
                continue;
            }

            // セキュリティ: realpath でシンボリックリンク攻撃を防止
            $real_path = realpath( $path );
            if ( $real_path === false ) {
                $this->logger->add_log( "警告: パスが存在しません: {$path}", true );
                continue;
            }

            // セキュリティ: パストラバーサル攻撃を検出
            // WordPress インストールディレクトリ外へのアクセスを禁止
            $wp_root = realpath( ABSPATH );
            $wp_content = realpath( WP_CONTENT_DIR );

            $is_in_wp_root = strpos( $real_path, $wp_root ) === 0;
            $is_in_wp_content = strpos( $real_path, $wp_content ) === 0;

            if ( ! $is_in_wp_root && ! $is_in_wp_content ) {
                $this->logger->add_log( "警告: WordPressディレクトリ外のパスはスキップされました: {$path}", true );
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
                if ( $dangerous_dir && strpos( $real_path, $dangerous_dir ) === 0 ) {
                    $this->logger->add_log( "警告: 保護されたディレクトリへのアクセスはスキップされました: {$path}", true );
                    continue 2;
                }
            }

            if ( is_file( $real_path ) ) {
                $dest = $this->temp_dir . '/' . basename( $real_path );
                if ( ! copy( $real_path, $dest ) ) {
                    $this->logger->add_log( "警告: ファイルのコピーに失敗しました: {$real_path}", true );
                }
            } elseif ( is_dir( $real_path ) ) {
                $dest = $this->temp_dir . '/' . basename( $real_path );
                $this->copy_directory_recursive( $real_path, $dest );
            }
        }

        $this->logger->add_log( '巻き込みファイルをコピーしました' );
    }

    /**
     * 除外ファイルを削除
     */
    private function remove_excluded_files() {
        // 強制除外パターンを取得
        $force_patterns = $this->get_force_exclude_patterns();

        // ユーザー設定の除外パターンを取得
        $user_patterns = array();
        if ( ! empty( $this->settings['exclude_patterns'] ) ) {
            $user_patterns = explode( "\n", $this->settings['exclude_patterns'] );
        }

        // 両方のパターンをマージ
        $all_patterns = array_merge( $force_patterns, $user_patterns );

        if ( empty( $all_patterns ) ) {
            return;
        }

        foreach ( $all_patterns as $pattern ) {
            $pattern = trim( $pattern );
            if ( empty( $pattern ) ) {
                continue;
            }

            // ワイルドカードを使用してファイルを検索
            $files = glob( $this->temp_dir . '/' . $pattern );
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    unlink( $file );
                } elseif ( is_dir( $file ) ) {
                    $this->remove_directory( $file );
                }
            }
        }

        $this->logger->add_log( '除外ファイルを削除しました' );
    }

    /**
     * 強制除外パターンを取得
     *
     * @return array 除外パターンの配列
     */
    private function get_force_exclude_patterns() {
        return array(
            // プラグイン内部キャッシュ
            'wp-content/sge-cache',
            'wp-content/sge-cache/*',
            // wp2staticの出力ファイル
            'wp-content/uploads/wp2static-*',
            // このプラグイン自身
            'wp-content/plugins/static-generation-engine',
            'wp-content/plugins/static-generation-engine/*',
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
    private function output_to_local() {
        $output_path = $this->settings['local_output_path'];

        // ディレクトリが存在しない場合は作成
        if ( ! is_dir( $output_path ) ) {
            if ( ! mkdir( $output_path, 0755, true ) ) {
                $this->logger->add_log( 'ディレクトリの作成に失敗しました', true );
                return;
            }
        }

        // 既存ファイルを削除
        $this->remove_directory_contents( $output_path );

        // ファイルをコピー
        $this->copy_directory_recursive( $this->temp_dir, $output_path );

        $this->logger->add_log( 'ローカルディレクトリに出力しました' );
    }

    /**
     * ZIP出力
     */
    private function output_to_zip() {
        $output_path = $this->settings['zip_output_path'];

        // ディレクトリが存在しない場合は作成
        if ( ! is_dir( $output_path ) ) {
            if ( ! mkdir( $output_path, 0755, true ) ) {
                $this->logger->add_log( 'ZIP出力先ディレクトリの作成に失敗しました', true );
                return;
            }
        }

        // ZIPアーカイブを作成
        $this->create_zip_archive( $output_path );
    }

    /**
     * ZIPアーカイブを作成
     *
     * @param string $output_path ZIP出力先パス
     */
    private function create_zip_archive( $output_path ) {
        $timestamp = current_time( 'Ymd_His' );
        $zip_filename = 'static-output-' . $timestamp . '.zip';
        $zip_path = trailingslashit( $output_path ) . $zip_filename;

        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->logger->add_log( 'ZipArchiveクラスが利用できないため、ZIP作成をスキップしました', true );
            return;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            $this->logger->add_log( 'ZIPファイルの作成に失敗しました: ' . $zip_path, true );
            return;
        }

        // 一時ディレクトリ内のファイルを再帰的にZIPに追加
        $this->add_directory_to_zip( $zip, $this->temp_dir, $this->temp_dir );

        $zip->close();

        // ZIPファイルのサイズを記録
        $zip_size = file_exists( $zip_path ) ? filesize( $zip_path ) : 0;
        $zip_size_mb = round( $zip_size / 1024 / 1024, 2 );

        $this->logger->add_log( 'ZIPアーカイブを作成しました: ' . $zip_filename . ' (' . $zip_size_mb . ' MB)' );
    }

    /**
     * ディレクトリをZIPに追加（再帰的）
     *
     * @param ZipArchive $zip ZipArchiveオブジェクト
     * @param string $dir 追加するディレクトリ
     * @param string $base_dir ベースディレクトリ（相対パス計算用）
     */
    private function add_directory_to_zip( $zip, $dir, $base_dir ) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        // ベースディレクトリを正規化（シンボリックリンクを解決）
        $normalized_base = str_replace( '\\', '/', trailingslashit( realpath( $base_dir ) ) );

        foreach ( $files as $file ) {
            $file_path = $file->getRealPath();

            // ZIPファイル自体は除外
            if ( strpos( $file_path, '.zip' ) !== false ) {
                continue;
            }

            // ベースディレクトリからの相対パスを取得（パス区切り文字を正規化）
            $normalized_file = str_replace( '\\', '/', $file_path );
            $relative_path = str_replace( $normalized_base, '', $normalized_file );

            // 先頭のスラッシュを除去
            $relative_path = ltrim( $relative_path, '/' );

            if ( $file->isDir() ) {
                // 空のディレクトリはZIPに追加しない
                if ( ! $this->is_directory_empty_recursive( $file_path ) ) {
                    $zip->addEmptyDir( $relative_path );
                }
            } else {
                $zip->addFile( $file_path, $relative_path );
            }
        }
    }

    /**
     * GitHub API経由で出力
     */
    private function output_to_github_api() {
        $branch = $this->settings['github_branch_mode'] === 'existing'
            ? $this->settings['github_existing_branch']
            : $this->settings['github_new_branch'];

        $github_api = new SGE_GitHub_API(
            $this->settings['github_token'],
            $this->settings['github_repo'],
            $branch
        );

        // リポジトリ存在チェック
        $repo_exists = $github_api->check_repo_exists();
        if ( is_wp_error( $repo_exists ) ) {
            $this->logger->add_log( $repo_exists->get_error_message(), true );
            return;
        }

        if ( ! $repo_exists ) {
            // リポジトリを作成
            $result = $github_api->create_repo();
            if ( is_wp_error( $result ) ) {
                $this->logger->add_log( $result->get_error_message(), true );
                return;
            }
        }

        // 一時ディレクトリの全ファイルパスを取得
        $file_paths = $this->get_directory_file_paths( $this->temp_dir );

        if ( empty( $file_paths ) ) {
            $this->logger->add_log( '出力するファイルが見つかりませんでした', true );
            return;
        }

        $this->logger->add_log( '合計 ' . count( $file_paths ) . ' ファイルをGitHubにプッシュします' );

        // バッチ処理でGitHubにプッシュ（300ファイルごと）
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

        $this->logger->add_log( 'GitHubに出力しました' );
    }

    /**
     * ローカルGitに出力
     */
    private function output_to_git_local() {
        $work_dir = $this->settings['git_local_work_dir'];
        $branch = $this->settings['git_local_branch'];
        $push_remote = ! empty( $this->settings['git_local_push_remote'] );

        $this->logger->add_log( 'ローカルGitリポジトリに出力します' );

        // gitコマンドのパスを検出
        $git_cmd = $this->find_git_command();
        if ( ! $git_cmd ) {
            $this->logger->add_log( 'エラー: gitコマンドが見つかりません', true );
            return;
        }

        // ディレクトリの存在確認
        if ( ! is_dir( $work_dir ) ) {
            $this->logger->add_log( 'エラー: Git作業ディレクトリが存在しません', true );
            return;
        }

        // .gitディレクトリの存在確認
        if ( ! is_dir( $work_dir . '/.git' ) ) {
            $this->logger->add_log( 'エラー: 指定されたディレクトリはGitリポジトリではありません', true );
            return;
        }

        // 既存ファイルを削除（.gitディレクトリを除く）
        $this->logger->add_log( '既存ファイルをクリーンアップ中...' );
        $this->remove_directory_contents( $work_dir, array( '.git' ) );

        // 静的ファイルをコピー
        $this->logger->add_log( '静的ファイルをコピー中...' );
        $this->copy_directory_recursive( $this->temp_dir, $work_dir );

        // Gitコマンドを実行
        $this->logger->add_log( 'Git操作を実行中...' );

        // 作業ディレクトリに移動
        $old_dir = getcwd();
        chdir( $work_dir );

        // 現在のブランチを取得
        $current_branch = trim( shell_exec( $git_cmd . ' branch --show-current 2>&1' ) );

        // ブランチが存在するか確認
        $branch_exists = false;
        $output = array();
        exec( $git_cmd . ' rev-parse --verify ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
        if ( $return_code === 0 ) {
            $branch_exists = true;
        }

        // コミットが存在するか確認（空のリポジトリかどうか）
        $output = array();
        exec( $git_cmd . ' rev-parse HEAD 2>&1', $output, $return_code );
        $has_commits = ( $return_code === 0 );

        // ブランチに切り替え
        if ( $branch_exists && $current_branch !== $branch ) {
            $this->logger->add_log( "ブランチ '{$branch}' に切り替え中..." );
            $output = array();
            exec( $git_cmd . ' checkout ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            if ( $return_code !== 0 ) {
                $error_msg = implode( ' ', $output );
                $this->logger->add_log( 'エラー: ブランチの切り替えに失敗しました: ' . $error_msg, true );
                chdir( $old_dir );
                return;
            }
        } elseif ( ! $branch_exists ) {
            $this->logger->add_log( "新しいブランチ '{$branch}' を作成中..." );
            $output = array();
            if ( $has_commits ) {
                // コミットがある場合は通常のブランチ作成
                exec( $git_cmd . ' checkout -b ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            } else {
                // 空のリポジトリの場合はorphanブランチを作成
                exec( $git_cmd . ' checkout --orphan ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            }
            if ( $return_code !== 0 ) {
                $error_msg = implode( ' ', $output );
                $this->logger->add_log( 'エラー: ブランチの作成に失敗しました: ' . $error_msg, true );
                chdir( $old_dir );
                return;
            }
        }

        // 全ファイルをステージング
        $output = array();
        exec( $git_cmd . ' add -A 2>&1', $output, $return_code );
        if ( $return_code !== 0 ) {
            $error_msg = implode( ' ', $output );
            $this->logger->add_log( 'エラー: ファイルのステージングに失敗しました: ' . $error_msg, true );
            chdir( $old_dir );
            return;
        }

        // コミット
        $commit_message = ! empty( $this->settings['commit_message'] )
            ? $this->settings['commit_message']
            : 'Static site update: ' . current_time( 'Y-m-d H:i:s' );

        $output = array();
        exec( $git_cmd . ' commit -m ' . escapeshellarg( $commit_message ) . ' 2>&1', $output, $return_code );
        if ( $return_code !== 0 ) {
            // コミットがない場合（変更がない場合）
            if ( strpos( implode( "\n", $output ), 'nothing to commit' ) !== false ) {
                $this->logger->add_log( '変更がないため、コミットは作成されませんでした' );
            } else {
                $error_msg = implode( ' ', $output );
                $this->logger->add_log( 'エラー: コミットに失敗しました: ' . $error_msg, true );
                chdir( $old_dir );
                return;
            }
        } else {
            $this->logger->add_log( 'コミットを作成しました' );
        }

        // リモートにプッシュ
        if ( $push_remote ) {
            $this->logger->add_log( 'リモートにプッシュ中...' );
            $output = array();
            exec( $git_cmd . ' push origin ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            if ( $return_code !== 0 ) {
                $sanitized_error = $this->sanitize_git_error( $output );
                $this->logger->add_log( '警告: リモートへのプッシュに失敗しました' . ( $sanitized_error ? ': ' . $sanitized_error : '' ), true );
            } else {
                $this->logger->add_log( 'リモートへのプッシュが完了しました' );
            }
        }

        chdir( $old_dir );
        $this->logger->add_log( 'ローカルGitへの出力が完了しました' );
    }

    /**
     * ディレクトリが空かどうかをチェック（再帰的）
     *
     * @param string $dir チェックするディレクトリ
     * @return bool 空の場合true、ファイルがある場合false
     */
    private function is_directory_empty_recursive( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return true;
        }

        $files = @scandir( $dir );
        if ( $files === false ) {
            return true;
        }

        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }

            $path = $dir . '/' . $file;

            // サブディレクトリの場合、再帰的にチェック
            if ( is_dir( $path ) ) {
                if ( ! $this->is_directory_empty_recursive( $path ) ) {
                    return false; // サブディレクトリに内容がある
                }
            } else {
                // ファイルがある場合、除外対象かチェック
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
                // 隠しファイル（.htaccess以外）と除外拡張子以外のファイルがあればfalse
                if ( ! ( strpos( $filename, '.' ) === 0 && $filename !== '.htaccess' ) &&
                     ! in_array( $extension, $excluded_extensions ) ) {
                    return false; // コピー対象のファイルがある
                }
            }
        }

        return true; // 空またはコピー対象のファイルがない
    }

    /**
     * ディレクトリを再帰的にコピー
     *
     * @param string $src コピー元ディレクトリ
     * @param string $dst コピー先ディレクトリ
     */
    private function copy_directory_recursive( $src, $dst, $log_progress = false ) {
        if ( ! is_dir( $src ) ) {
            $this->logger->add_log( 'ソースディレクトリが存在しません: ' . $src, true );
            return false;
        }

        // 空のディレクトリはスキップ
        if ( $this->is_directory_empty_recursive( $src ) ) {
            return true; // エラーではないのでtrueを返す
        }

        if ( ! is_dir( $dst ) ) {
            if ( ! mkdir( $dst, 0755, true ) ) {
                $this->logger->add_log( 'ディレクトリの作成に失敗しました: ' . $dst, true );
                return false;
            }
        }

        $files = @scandir( $src );
        if ( $files === false ) {
            $this->logger->add_log( 'ディレクトリの読み込みに失敗しました: ' . $src, true );
            return false;
        }

        $file_count = 0;
        $error_count = 0;

        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }

            $src_path = $src . '/' . $file;
            $dst_path = $dst . '/' . $file;

            if ( is_dir( $src_path ) ) {
                // サブディレクトリが空でない場合のみコピー
                if ( ! $this->is_directory_empty_recursive( $src_path ) ) {
                    if ( ! $this->copy_directory_recursive( $src_path, $dst_path ) ) {
                        $error_count++;
                    }
                }
                // 空のディレクトリはスキップ（エラーとしてカウントしない）
            } else {
                // ファイルの拡張子を確認
                $extension = strtolower( pathinfo( $src_path, PATHINFO_EXTENSION ) );

                // PHPファイルと危険なファイルタイプを除外
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

                // ファイル名での除外（.で始まる隠しファイル）
                $filename = basename( $src_path );
                if ( strpos( $filename, '.' ) === 0 && $filename !== '.htaccess' ) {
                    continue;
                }

                // 拡張子チェック
                if ( in_array( $extension, $excluded_extensions ) ) {
                    // 危険なファイルはスキップ（静的サイトには不要）
                    continue;
                }

                // CSS/JSファイルの場合はURL変換を行う
                if ( in_array( $extension, array( 'css', 'js' ) ) && $this->settings['url_mode'] === 'relative' ) {
                    $content = @file_get_contents( $src_path );
                    if ( $content !== false ) {
                        // URL変換を実行
                        $content = $this->convert_asset_urls( $content, $extension );
                        if ( @file_put_contents( $dst_path, $content ) === false ) {
                            $this->logger->add_log( 'ファイルの書き込みに失敗しました: ' . $dst_path, true );
                            $error_count++;
                        } else {
                            $file_count++;
                        }
                    } else {
                        // 読み込み失敗時は通常のコピー
                        if ( ! @copy( $src_path, $dst_path ) ) {
                            $this->logger->add_log( 'ファイルのコピーに失敗しました: ' . $src_path, true );
                            $error_count++;
                        } else {
                            $file_count++;
                        }
                    }
                } else {
                    // その他のファイルは通常のコピー
                    if ( ! @copy( $src_path, $dst_path ) ) {
                        $this->logger->add_log( 'ファイルのコピーに失敗しました: ' . $src_path, true );
                        $error_count++;
                    } else {
                        $file_count++;
                    }
                }

                // 100ファイルごとに進捗を記録
                if ( $log_progress && $file_count % 100 === 0 ) {
                    $this->logger->add_log( $file_count . ' ファイルをコピーしました (' . basename( $src ) . ')' );
                }
            }
        }

        if ( $error_count > 0 ) {
            $this->logger->add_log( 'コピー中にエラーが発生しました: ' . $error_count . ' 個のファイル/ディレクトリ', true );
        }

        return $error_count === 0;
    }

    /**
     * ディレクトリの内容を削除
     *
     * @param string $dir ディレクトリパス
     */
    private function remove_directory_contents( $dir, $exclude = array() ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        $files = scandir( $dir );
        foreach ( $files as $file ) {
            if ( $file === '.' || $file === '..' ) {
                continue;
            }

            // 除外リストに含まれている場合はスキップ
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
    private function sanitize_git_error( $output ) {
        if ( empty( $output ) ) {
            return '';
        }

        $message = implode( "\n", $output );

        // 絶対パスを削除
        $message = preg_replace( '#/[^\s:]+#', '[path]', $message );

        // Windowsパスを削除
        $message = preg_replace( '#[A-Z]:\\\\[^\s:]+#i', '[path]', $message );

        // URLに含まれる可能性のある認証情報を削除
        $message = preg_replace( '#https?://[^@\s]+@[^\s]+#', 'https://[credentials]@[remote]', $message );

        // IPアドレスを削除
        $message = preg_replace( '#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#', '[ip]', $message );

        return $message;
    }

    /**
     * gitコマンドのパスを検出（セキュリティ強化版）
     *
     * @return string|false gitコマンドのフルパス、見つからない場合はfalse
     */
    private function find_git_command() {
        // ホワイトリスト: 許可されたgitコマンドのパス
        $allowed_paths = array(
            '/usr/bin/git',
            '/usr/local/bin/git',
            '/opt/homebrew/bin/git',
            '/opt/local/bin/git',
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

            // ファイル名が 'git' であることを確認
            if ( basename( $real_path ) !== 'git' ) {
                continue;
            }

            // 許可されたディレクトリ内にあることを確認
            $allowed_dirs = array( '/usr/bin', '/usr/local/bin', '/opt/homebrew/bin', '/opt/local/bin' );
            $real_dir = dirname( $real_path );
            $is_allowed = false;
            foreach ( $allowed_dirs as $allowed_dir ) {
                if ( strpos( $real_dir, $allowed_dir ) === 0 ) {
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
    private function copy_favicon() {
        // WordPressのサイトアイコンを取得
        $site_icon_id = get_option( 'site_icon' );

        if ( $site_icon_id ) {
            // サイトアイコンが設定されている場合
            $icon_url = wp_get_attachment_image_url( $site_icon_id, 'full' );
            if ( $icon_url ) {
                $this->logger->add_log( 'サイトアイコンをコピー: ' . $icon_url );

                // アイコンのパスを取得
                $icon_path = get_attached_file( $site_icon_id );

                if ( $icon_path && file_exists( $icon_path ) ) {
                    // 拡張子を取得
                    $extension = pathinfo( $icon_path, PATHINFO_EXTENSION );

                    // favicon.ico としてコピー
                    $favicon_dest = $this->temp_dir . '/favicon.ico';
                    if ( copy( $icon_path, $favicon_dest ) ) {
                        $this->logger->add_log( 'ファビコンをコピーしました: favicon.ico' );
                    } else {
                        $this->logger->add_log( 'ファビコンのコピーに失敗しました', true );
                    }

                    // 元の拡張子でもコピー（.png, .webp など）
                    if ( $extension !== 'ico' ) {
                        $icon_filename = 'favicon.' . $extension;
                        $icon_dest = $this->temp_dir . '/' . $icon_filename;
                        if ( copy( $icon_path, $icon_dest ) ) {
                            $this->logger->add_log( 'サイトアイコンをコピーしました: ' . $icon_filename );
                        }
                    }
                } else {
                    $this->logger->add_log( 'サイトアイコンファイルが見つかりません: ' . $icon_path, true );
                }
            }
        } else {
            // サイトアイコンが設定されていない場合、ルートディレクトリのfavicon.icoを探す
            $favicon_path = ABSPATH . 'favicon.ico';
            if ( file_exists( $favicon_path ) ) {
                $favicon_dest = $this->temp_dir . '/favicon.ico';
                if ( copy( $favicon_path, $favicon_dest ) ) {
                    $this->logger->add_log( 'ルートディレクトリのfavicon.icoをコピーしました' );
                } else {
                    $this->logger->add_log( 'favicon.icoのコピーに失敗しました', true );
                }
            } else {
                $this->logger->add_log( 'ファビコンが見つかりません（サイトアイコン未設定）' );
            }
        }

        // 追加のアイコンファイル（apple-touch-icon.png など）もコピー
        $additional_icons = array(
            'apple-touch-icon.png',
            'apple-touch-icon-precomposed.png',
            'browserconfig.xml',
            'manifest.json',
            'site.webmanifest',
        );

        foreach ( $additional_icons as $icon_file ) {
            $icon_path = ABSPATH . $icon_file;
            if ( file_exists( $icon_path ) ) {
                $icon_dest = $this->temp_dir . '/' . $icon_file;
                if ( copy( $icon_path, $icon_dest ) ) {
                    $this->logger->add_log( $icon_file . ' をコピーしました' );
                }
            }
        }
    }

    /**
     * robots.txtを生成
     */
    private function generate_robots_txt() {
        $robots_txt_path = $this->temp_dir . '/robots.txt';

        // 空のrobots.txtを生成
        $robots_content = '';

        // robots.txtを書き込み
        if ( file_put_contents( $robots_txt_path, $robots_content ) !== false ) {
            $this->logger->add_log( '空のrobots.txtを生成しました' );
        } else {
            $this->logger->add_log( 'robots.txtの生成に失敗しました', true );
        }
    }

    /**
     * ディレクトリを再帰的に削除
     *
     * @param string $dir ディレクトリパス
     */
    private function remove_directory( $dir ) {
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
    private function get_directory_file_paths( $dir ) {
        $file_paths = array();

        if ( ! is_dir( $dir ) ) {
            return $file_paths;
        }

        // ディレクトリパスを正規化（getRealPath()と同じ形式にする）
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
    private function read_directory_files( $dir ) {
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

                // ファイルサイズチェック（メモリ保護: 10MBまで）
                $file_size = $file->getSize();
                if ( $file_size > 10 * 1024 * 1024 ) {
                    $this->logger->add_log( "スキップ: ファイルが大きすぎます（{$relative_path}: " . size_format( $file_size ) . '）' );
                    continue;
                }

                // MIME type validation
                $extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
                $allowed_extensions = array( 'html', 'css', 'js', 'json', 'xml', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'pdf' );

                if ( ! in_array( $extension, $allowed_extensions ) ) {
                    $this->logger->add_log( "スキップ: 許可されていない拡張子（{$relative_path}）" );
                    continue;
                }

                // ファイル内容を読み込み
                $content = @file_get_contents( $file_path );
                if ( $content !== false ) {
                    $files[ $relative_path ] = $content;
                }
            }
        }

        return $files;
    }
}
