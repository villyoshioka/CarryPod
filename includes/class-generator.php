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
     * デバッグモード
     */
    private $debug_mode = false;

    /**
     * URL→投稿IDマップ（高速化用）
     */
    private $url_to_post_id_map = array();

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
            // デバッグモード設定を読み込み
            $this->debug_mode = ! empty( $_GET['debugmode'] ) && $_GET['debugmode'] === 'on';

            // PHP実行時間制限を無制限に設定（長時間処理対応）
            if ( function_exists( 'set_time_limit' ) && ! ini_get( 'safe_mode' ) ) {
                @set_time_limit( 0 );
            }

            // 実行中フラグをセット
            set_transient( 'sge_manual_running', true, 3600 );

            // エラー通知をクリア（新規実行開始時）
            delete_option( 'sge_error_notification' );

            // タイマー開始とログ記録
            $this->logger->start_timer();
            $this->logger->add_log( '静的化を開始します' );
            $this->logger->clear_progress();

            // 一時ディレクトリが既に存在する場合は削除
            if ( is_dir( $this->temp_dir ) ) {
                $this->remove_directory( $this->temp_dir );
            }

            // 一時ディレクトリを作成
            if ( ! mkdir( $this->temp_dir, 0700, true ) ) {
                $this->logger->add_log( '一時ディレクトリの作成に失敗しました', true );
                delete_transient( 'sge_manual_running' );
                return;
            }

            // URLリストを取得
            $urls = $this->get_urls_to_generate();
            $total_urls = count( $urls );
            $this->logger->add_log( 'URL取得完了: ' . $total_urls . '件' );

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
                // 従来の逐次処理（高速化: url_to_postid()を事前構築したマップで置き換え）
                // 進捗更新の頻度を減らす（10件ごと、または最後の1件）
                $progress_interval = max( 1, (int) ( $total_urls / 20 ) ); // 5%ごとに更新

                foreach ( $urls as $index => $url ) {
                    // 進捗更新（頻度を減らして高速化）
                    if ( $index % $progress_interval === 0 || $index === $total_urls - 1 ) {
                        $current_step = (int) ( ( $index + 1 ) * $page_step_ratio );
                        $this->logger->update_progress( $current_step, $total_steps, 'ページを生成中: ' . ( $index + 1 ) . ' / ' . $total_urls );
                    }

                    // URLから投稿IDを取得（マップから高速に取得、なければ0）
                    $post_id = isset( $this->url_to_post_id_map[ $url ] ) ? $this->url_to_post_id_map[ $url ] : 0;

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
            $this->logger->add_log( 'ページ生成完了: ' . $total_urls . '件（キャッシュ: ' . $cache_used_count . '件、新規: ' . $generated_count . '件）' );

            // アセットファイルをコピー (80-83%)
            $this->logger->add_log( 'アセットファイルをコピー中...' );
            $this->logger->update_progress( 81, $total_steps, 'アセットファイルをコピー中...' );
            $this->copy_assets();

            // 追加ファイルをコピー (83-86%)
            $this->logger->add_log( '追加ファイルをコピー中...' );
            $this->logger->update_progress( 84, $total_steps, '追加ファイルをコピー中...' );
            $this->copy_included_files();

            // 除外ファイルを削除 (86-90%)
            $this->logger->add_log( '除外パターンを処理中...' );
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
            if ( ! empty( $this->settings['cloudflare_enabled'] ) ) {
                $output_count++;
            }
            if ( ! empty( $this->settings['gitlab_enabled'] ) ) {
                $output_count++;
            }
            $output_step = 0;
            $output_progress_per_step = $output_count > 0 ? 10.0 / $output_count : 10;

            // ローカル出力が有効な場合
            if ( ! empty( $this->settings['local_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'ローカルディレクトリに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'ローカルディレクトリに出力中...' );
                $this->output_to_local();
            }

            // GitHub出力が有効な場合
            if ( ! empty( $this->settings['github_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'GitHubに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'GitHubに出力中...' );
                $this->output_to_github_api();
            }

            // ローカルGit出力が有効な場合
            if ( ! empty( $this->settings['git_local_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'ローカルGitに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'ローカルGitに出力中...' );
                $this->output_to_git_local();
            }

            // ZIP出力が有効な場合
            if ( ! empty( $this->settings['zip_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'ZIPファイルを作成中...' );
                $this->logger->update_progress( $progress, $total_steps, 'ZIPファイルを作成中...' );
                $this->output_to_zip();
            }

            // Cloudflare Workers出力が有効な場合
            if ( ! empty( $this->settings['cloudflare_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'Cloudflare Workersにデプロイ中...' );
                $this->logger->update_progress( $progress, $total_steps, 'Cloudflare Workersにデプロイ中...' );
                $this->output_to_cloudflare_workers();
            }

            // GitLab出力が有効な場合
            if ( ! empty( $this->settings['gitlab_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'GitLabに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'GitLabに出力中...' );
                $this->output_to_gitlab_api();
            }

            // Netlify出力が有効な場合
            if ( ! empty( $this->settings['netlify_enabled'] ) ) {
                $output_step++;
                $progress = 90 + (int) ( $output_step * $output_progress_per_step ) - (int) $output_progress_per_step;
                $this->logger->add_log( 'Netlifyに出力中...' );
                $this->logger->update_progress( $progress, $total_steps, 'Netlifyに出力中...' );
                $this->output_to_netlify();
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

            // エラーがある場合は通知を保存（DBから直接カウント）
            $logs = get_option( 'sge_logs', array() );
            $error_count = 0;
            foreach ( $logs as $log ) {
                if ( ! empty( $log['is_error'] ) ) {
                    $error_count++;
                }
            }

            if ( $error_count > 0 ) {
                update_option( 'sge_error_notification', array(
                    'count' => $error_count,
                    'timestamp' => current_time( 'mysql' ),
                ), false );
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

        // 共通設定を1回だけ取得
        $posts_per_page = (int) get_option( 'posts_per_page' );
        $home_url = home_url( '/' );

        // 進捗更新: 開始
        $this->logger->update_progress( 0, 100, 'URLを収集中: 投稿を取得中...' );

        // トップページ
        $urls[] = $home_url;

        // トップページのページネーション
        $post_count = wp_count_posts( 'post' )->publish;
        $max_pages = ceil( $post_count / $posts_per_page );
        for ( $i = 2; $i <= $max_pages; $i++ ) {
            $urls[] = $home_url . 'page/' . $i . '/';
        }

        // 全投稿タイプを1回のクエリで取得（post, page, カスタム投稿タイプ）
        $public_post_types = get_post_types( array( 'public' => true ) );
        $all_posts = get_posts( array(
            'post_type'      => array_values( $public_post_types ),
            'post_status'    => 'publish',
            'numberposts'    => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'cache_results'  => true,
            'no_found_rows'  => true,  // ページネーション不要なのでカウントクエリを省略
            'suppress_filters' => true, // フィルター処理をスキップ
        ) );

        // パーマリンク計算用のキャッシュを事前ロード + URL→投稿IDマップを構築
        if ( ! empty( $all_posts ) ) {
            $post_ids = wp_list_pluck( $all_posts, 'ID' );
            _prime_post_caches( $post_ids, true, true );

            // 各投稿のパーマリンクを取得（同時にURL→投稿IDマップも構築）
            foreach ( $all_posts as $post ) {
                $permalink = get_permalink( $post->ID );
                $urls[] = $permalink;
                // URL→投稿IDマップを構築（後でurl_to_postid()を呼ばなくて済む）
                $this->url_to_post_id_map[ $permalink ] = $post->ID;
            }
        }

        // カスタム投稿タイプのアーカイブページ
        $custom_post_types = get_post_types( array( 'public' => true, '_builtin' => false ) );
        foreach ( $custom_post_types as $post_type ) {
            $post_type_obj = get_post_type_object( $post_type );
            if ( $post_type_obj->has_archive ) {
                $urls[] = get_post_type_archive_link( $post_type );
            }
        }

        // 進捗更新: カテゴリ取得
        $this->logger->update_progress( 0, 100, 'URLを収集中: カテゴリを取得中...' );

        // カテゴリアーカイブ（タームリンクキャッシュを活用）
        $categories = get_categories( array( 'hide_empty' => true ) );
        if ( ! empty( $categories ) ) {
            // タームキャッシュを事前ロード
            $category_ids = wp_list_pluck( $categories, 'term_id' );
            _prime_term_caches( $category_ids, false );

            foreach ( $categories as $category ) {
                $category_link = get_category_link( $category->term_id );
                $urls[] = $category_link;

                // ページネーション
                $max_pages = ceil( $category->count / $posts_per_page );
                for ( $i = 2; $i <= $max_pages; $i++ ) {
                    $urls[] = $category_link . 'page/' . $i . '/';
                }
            }
        }

        // 進捗更新: タグ取得
        $this->logger->update_progress( 0, 100, 'URLを収集中: タグを取得中...' );

        // タグアーカイブ
        if ( ! empty( $this->settings['enable_tag_archive'] ) ) {
            $tags = get_tags( array( 'hide_empty' => true ) );
            if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
                // タームキャッシュを事前ロード
                $tag_ids = wp_list_pluck( $tags, 'term_id' );
                _prime_term_caches( $tag_ids, false );

                foreach ( $tags as $tag ) {
                    $tag_link = get_tag_link( $tag->term_id );
                    $urls[] = $tag_link;

                    // ページネーション
                    $max_pages = ceil( $tag->count / $posts_per_page );
                    for ( $i = 2; $i <= $max_pages; $i++ ) {
                        $urls[] = $tag_link . 'page/' . $i . '/';
                    }
                }
            }
        }

        // 進捗更新: アーカイブ取得
        $this->logger->update_progress( 0, 100, 'URLを収集中: アーカイブを取得中...' );

        // 日付アーカイブ（1回のクエリで全日付を取得）
        if ( ! empty( $this->settings['enable_date_archive'] ) ) {
            $archive_dates = $this->get_all_archive_dates();
            foreach ( $archive_dates as $date ) {
                $urls[] = get_year_link( $date['year'] );
                $urls[] = get_month_link( $date['year'], $date['month'] );
                $urls[] = get_day_link( $date['year'], $date['month'], $date['day'] );
            }
        }

        // カスタムタクソノミーアーカイブ
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

        // 投稿フォーマットアーカイブ（/type/image/ など）
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

                        // ページネーション
                        $max_pages = ceil( $format->count / $posts_per_page );
                        for ( $i = 2; $i <= $max_pages; $i++ ) {
                            $urls[] = $format_link . 'page/' . $i . '/';
                        }
                    }
                }
            }
        }

        // 著者アーカイブ
        if ( ! empty( $this->settings['enable_author_archive'] ) ) {
            $users = get_users( array( 'has_published_posts' => true ) );
            foreach ( $users as $user ) {
                $author_link = get_author_posts_url( $user->ID );
                $urls[] = $author_link;

                // ページネーション
                $user_post_count = count_user_posts( $user->ID );
                $max_pages = ceil( $user_post_count / $posts_per_page );
                for ( $i = 2; $i <= $max_pages; $i++ ) {
                    $urls[] = $author_link . 'page/' . $i . '/';
                }
            }
        }

        // 進捗更新: その他
        $this->logger->update_progress( 0, 100, 'URLを収集中: フィード・サイトマップを取得中...' );

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

            // カテゴリーサイトマップ（カテゴリーアーカイブは常に有効）
            $urls[] = home_url( '/wp-sitemap-taxonomies-category-1.xml' );

            // タグサイトマップ（タグアーカイブが有効な場合のみ）
            if ( ! empty( $this->settings['enable_tag_archive'] ) ) {
                $urls[] = home_url( '/wp-sitemap-taxonomies-post_tag-1.xml' );
            }

            // 投稿フォーマットサイトマップ（投稿フォーマットアーカイブが有効な場合のみ）
            if ( ! empty( $this->settings['enable_post_format_archive'] ) ) {
                $urls[] = home_url( '/wp-sitemap-taxonomies-post_format-1.xml' );
            }

            // ユーザーサイトマップ（著者アーカイブが有効な場合のみ）
            if ( ! empty( $this->settings['enable_author_archive'] ) ) {
                $urls[] = home_url( '/wp-sitemap-users-1.xml' );
            }
        }

        return array_unique( $urls );
    }

    /**
     * 全日付アーカイブを1回のクエリで取得
     *
     * @return array 日付の配列（year, month, day）
     */
    private function get_all_archive_dates() {
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

        // HTMLが空でないか確認
        if ( empty( $html ) ) {
            $this->logger->add_log( "空のレスポンス: {$url}", true );
            return new WP_Error( 'empty_response', '空のレスポンス' );
        }

        // コンテンツのサイズを記録（デバッグ用）
        $original_size = strlen( $html );
        $this->logger->debug( "取得: {$url} - " . number_format( $original_size ) . " bytes" );

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
            $this->logger->debug( "XMLファイル取得: {$url}" );

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
        // 末尾スラッシュあり・なし両方のURLを取得
        $site_url_with_slash = trailingslashit( get_site_url() );
        $site_url_no_slash = untrailingslashit( get_site_url() );
        $home_url_with_slash = trailingslashit( get_home_url() );
        $home_url_no_slash = untrailingslashit( get_home_url() );

        // http:// と https:// の両方に対応（末尾スラッシュあり）
        $site_url_https_slash = str_replace( 'http://', 'https://', $site_url_with_slash );
        $site_url_http_slash = str_replace( 'https://', 'http://', $site_url_with_slash );
        $home_url_https_slash = str_replace( 'http://', 'https://', $home_url_with_slash );
        $home_url_http_slash = str_replace( 'https://', 'http://', $home_url_with_slash );

        // http:// と https:// の両方に対応（末尾スラッシュなし）
        $site_url_https_no_slash = str_replace( 'http://', 'https://', $site_url_no_slash );
        $site_url_http_no_slash = str_replace( 'https://', 'http://', $site_url_no_slash );
        $home_url_https_no_slash = str_replace( 'http://', 'https://', $home_url_no_slash );
        $home_url_http_no_slash = str_replace( 'https://', 'http://', $home_url_no_slash );

        // エスケープされたURL（JSON内など）も対応
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

        // エスケープされたURLを先に置換（JSON内のURLなど）
        foreach ( $escaped_urls as $escaped_url => $replacement ) {
            $html = str_replace( $escaped_url, $replacement, $html );
        }

        // <style>タグ内のCSSを安全に処理
        $html = preg_replace_callback(
            '/<style([^>]*)>(.*?)<\/style>/is',
            function( $matches ) use ( $site_url_https_slash, $site_url_http_slash, $home_url_https_slash, $home_url_http_slash, $site_url_https_no_slash, $site_url_http_no_slash, $home_url_https_no_slash, $home_url_http_no_slash ) {
                $attributes = $matches[1];
                $css = $matches[2];

                // CSS内のurl()を慎重に処理（括弧の整合性を保つ）
                $css = preg_replace_callback(
                    '/url\s*\(\s*([\'"]?)([^\'"\)]+)\1\s*\)/i',
                    function( $url_matches ) use ( $site_url_https_slash, $site_url_http_slash, $home_url_https_slash, $home_url_http_slash, $site_url_https_no_slash, $site_url_http_no_slash, $home_url_https_no_slash, $home_url_http_no_slash ) {
                        $quote = $url_matches[1];
                        $url = $url_matches[2];

                        // dataスキームやhttpスキーム以外はそのまま
                        if ( strpos( $url, 'data:' ) === 0 || strpos( $url, '#' ) === 0 ) {
                            return $url_matches[0];
                        }

                        // 絶対URLを相対URLに変換（スラッシュありを先に処理）
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

        // HTML属性内のURLを変換
        // 末尾スラッシュありの場合は '/' に置換
        $html = str_replace( $site_url_https_slash, '/', $html );
        $html = str_replace( $site_url_http_slash, '/', $html );
        $html = str_replace( $home_url_https_slash, '/', $html );
        $html = str_replace( $home_url_http_slash, '/', $html );

        // 末尾スラッシュなしの場合も '/' に置換（空文字列ではなく）
        // これにより https://example.com/path が /path に正しく変換される
        $html = str_replace( $site_url_https_no_slash . '/', '/', $html );
        $html = str_replace( $site_url_http_no_slash . '/', '/', $html );
        $html = str_replace( $home_url_https_no_slash . '/', '/', $html );
        $html = str_replace( $home_url_http_no_slash . '/', '/', $html );

        // スラッシュが2つ続く場合は1つに（ただしhttp://やhttps://、JavaScriptコメント等は除く）
        $result = preg_replace( '#(?<!:)(?<!["\'])//+(?![/#\s])#', '/', $html );
        if ( $result !== null ) {
            $html = $result;
        }

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

        $copied_dirs = array();
        $error_dirs = array();

        // wp-content 内の必要なディレクトリのみをコピー
        $wp_content_dir = WP_CONTENT_DIR;
        $wp_content_dest = $this->temp_dir . '/wp-content';

        if ( ! is_dir( $wp_content_dir ) ) {
            $this->logger->add_log( 'wp-content ディレクトリが見つかりません', true );
            return false;
        }

        // wp-content ディレクトリを作成
        if ( ! is_dir( $wp_content_dest ) ) {
            mkdir( $wp_content_dest, 0755, true );
        }

        // 1. 有効なテーマのみコピー
        $themes_copied = $this->copy_active_themes( $wp_content_dest );
        if ( $themes_copied ) {
            $copied_dirs[] = 'themes';
        } else {
            $error_dirs[] = 'themes';
        }

        // 2. 参照されているメディアのみコピー
        $uploads_copied = $this->copy_referenced_uploads( $wp_content_dest );
        if ( $uploads_copied ) {
            $copied_dirs[] = 'uploads';
        } else {
            $error_dirs[] = 'uploads';
        }

        // 3. 有効なプラグインの静的アセットのみコピー
        $plugins_copied = $this->copy_active_plugin_assets( $wp_content_dest );
        if ( $plugins_copied ) {
            $copied_dirs[] = 'plugins';
        } else {
            $error_dirs[] = 'plugins';
        }

        // 4. cache, fonts などその他必要なディレクトリ
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

        // wp-includes ディレクトリから参照されているファイルのみをコピー
        $wp_includes_dir = ABSPATH . 'wp-includes';
        if ( is_dir( $wp_includes_dir ) ) {
            $result = $this->copy_referenced_wp_includes( $wp_includes_dir, $this->temp_dir . '/wp-includes' );
            if ( $result ) {
                $copied_dirs[] = 'wp-includes (参照ファイルのみ)';
            } else {
                $error_dirs[] = 'wp-includes';
                $success = false;
            }
        } else {
            $this->logger->add_log( 'wp-includes ディレクトリが見つかりません', true );
        }

        // type ディレクトリをコピー（WordPressルートディレクトリに存在する場合）
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

        // その他のカスタム投稿タイプ用ディレクトリをチェック（例: /news/, /products/ など）
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

        // ファビコン（サイトアイコン）をコピー
        $this->copy_favicon();

        // robots.txtを生成
        if ( ! empty( $this->settings['enable_robots_txt'] ) ) {
            $this->generate_robots_txt();
        }

        // サマリーログを出力
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
    private function copy_referenced_wp_includes( $source_dir, $dest_dir ) {
        // 生成済みHTMLファイルからwp-includesへの参照を収集
        $referenced_files = $this->collect_wp_includes_references();

        if ( empty( $referenced_files ) ) {
            $this->logger->debug( 'wp-includes への参照が見つかりませんでした' );
            return true;
        }

        $this->logger->debug( 'wp-includes 参照ファイル: ' . count( $referenced_files ) . '件検出' );

        // 参照ファイルをコピー
        $copied_count = 0;
        $error_count = 0;

        foreach ( $referenced_files as $relative_path ) {
            $source_file = $source_dir . '/' . $relative_path;
            $dest_file = $dest_dir . '/' . $relative_path;

            // ソースファイルが存在するか確認
            if ( ! file_exists( $source_file ) ) {
                continue;
            }

            // ディレクトリを作成
            $dest_dir_path = dirname( $dest_file );
            if ( ! is_dir( $dest_dir_path ) ) {
                if ( ! wp_mkdir_p( $dest_dir_path ) ) {
                    $error_count++;
                    continue;
                }
            }

            // ファイルをコピー
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
    private function collect_wp_includes_references() {
        $references = array();

        // 1. 生成済みHTMLファイルをスキャン
        $html_files = $this->find_files_recursive( $this->temp_dir, array( 'html', 'htm' ) );
        foreach ( $html_files as $html_file ) {
            $content = file_get_contents( $html_file );
            if ( $content === false ) {
                continue;
            }
            $refs = $this->extract_wp_includes_refs_from_html( $content );
            $references = array_merge( $references, $refs );
        }

        // 2. 検出したJS/CSSファイルから依存ファイルを再帰的に収集
        $processed = array();
        $to_process = $references;

        while ( ! empty( $to_process ) ) {
            $current = array_shift( $to_process );

            if ( isset( $processed[ $current ] ) ) {
                continue;
            }
            $processed[ $current ] = true;

            // JS/CSSファイルの場合は内部の参照も解析
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

        // 重複を除去してソート
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
    private function extract_wp_includes_refs_from_html( $content ) {
        $refs = array();

        // script src を抽出
        if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/', $content, $matches ) ) {
            foreach ( $matches[1] as $src ) {
                $ref = $this->parse_wp_includes_path( $src );
                if ( $ref ) {
                    $refs[] = $ref;
                }
            }
        }

        // link href を抽出 (CSS)
        if ( preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\']/', $content, $matches ) ) {
            foreach ( $matches[1] as $href ) {
                $ref = $this->parse_wp_includes_path( $href );
                if ( $ref ) {
                    $refs[] = $ref;
                }
            }
        }

        // img src を抽出
        if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches ) ) {
            foreach ( $matches[1] as $src ) {
                $ref = $this->parse_wp_includes_path( $src );
                if ( $ref ) {
                    $refs[] = $ref;
                }
            }
        }

        return $refs;
    }

    /**
     * URLからwp-includes内の相対パスを抽出
     *
     * @param string $url URL または相対パス
     * @return string|false wp-includes内の相対パス、またはfalse
     */
    private function parse_wp_includes_path( $url ) {
        // クエリ文字列を除去
        $url = strtok( $url, '?' );

        // wp-includes を含むかチェック
        if ( strpos( $url, 'wp-includes/' ) === false ) {
            return false;
        }

        // wp-includes/ 以降を抽出
        if ( preg_match( '#wp-includes/(.+)$#', $url, $matches ) ) {
            $path = $matches[1];
            // セキュリティ: ディレクトリトラバーサルを防止
            if ( strpos( $path, '..' ) !== false ) {
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
    private function extract_deps_from_asset( $content, $current_path, $ext ) {
        $deps = array();
        $current_dir = dirname( $current_path );

        if ( $ext === 'css' ) {
            // url() を抽出
            if ( preg_match_all( '/url\s*\(\s*["\']?([^"\')]+)["\']?\s*\)/', $content, $matches ) ) {
                foreach ( $matches[1] as $url ) {
                    $dep = $this->resolve_relative_path( $url, $current_dir );
                    if ( $dep ) {
                        $deps[] = $dep;
                    }
                }
            }

            // @import を抽出
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
    private function resolve_relative_path( $url, $current_dir ) {
        // クエリ文字列を除去
        $url = strtok( $url, '?' );
        $url = strtok( $url, '#' );

        // data: URL はスキップ
        if ( strpos( $url, 'data:' ) === 0 ) {
            return false;
        }

        // 絶対URLはスキップ
        if ( preg_match( '#^https?://#', $url ) ) {
            // wp-includes を含む場合は解析
            return $this->parse_wp_includes_path( $url );
        }

        // ルート相対パス
        if ( strpos( $url, '/wp-includes/' ) === 0 ) {
            return substr( $url, strlen( '/wp-includes/' ) );
        }

        // 相対パスを解決
        if ( strpos( $url, '/' ) === 0 ) {
            // 他の絶対パス（wp-includes以外）はスキップ
            return false;
        }

        // 相対パスを解決
        $path = $current_dir . '/' . $url;

        // パスを正規化 (../ を解決)
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
        if ( strpos( $result, '..' ) !== false ) {
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
    private function find_files_recursive( $dir, $extensions ) {
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
    private function copy_included_files() {
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

            // セキュリティ: realpath でシンボリックリンク攻撃を防止
            $real_path = realpath( $path );
            if ( $real_path === false ) {
                $this->logger->add_log( "パスが存在しません: {$path}", true );
                $error_count++;
                continue;
            }

            // セキュリティ: パストラバーサル攻撃を検出
            // WordPress インストールディレクトリ外へのアクセスを禁止
            $wp_root = realpath( ABSPATH );
            $wp_content = realpath( WP_CONTENT_DIR );

            $is_in_wp_root = strpos( $real_path, $wp_root ) === 0;
            $is_in_wp_content = strpos( $real_path, $wp_content ) === 0;

            if ( ! $is_in_wp_root && ! $is_in_wp_content ) {
                $this->logger->add_log( "WordPressディレクトリ外のパスはスキップ: {$path}", true );
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
                if ( $dangerous_dir && strpos( $real_path, $dangerous_dir ) === 0 ) {
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

        $removed_count = 0;
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
                    $removed_count++;
                } elseif ( is_dir( $file ) ) {
                    $this->remove_directory( $file );
                    $removed_count++;
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

        $this->logger->add_log( 'ローカル出力完了: ' . $output_path );
    }

    /**
     * Cloudflare Workers出力
     */
    private function output_to_cloudflare_workers() {
        $cloudflare = new SGE_Cloudflare_Workers(
            $this->settings['cloudflare_api_token'],
            $this->settings['cloudflare_account_id'],
            $this->settings['cloudflare_script_name']
        );

        // 一時ディレクトリの全ファイル数をカウント
        $file_paths = $this->get_directory_file_paths( $this->temp_dir );
        $file_count = count( $file_paths );

        if ( $file_count === 0 ) {
            $this->logger->add_log( 'Cloudflare Workers: 出力するファイルが見つかりませんでした', true );
            return;
        }

        $this->logger->add_log( '合計 ' . $file_count . ' ファイルをCloudflare Workersにデプロイします' );

        // デプロイ実行
        $result = $cloudflare->deploy( $this->temp_dir );

        if ( is_wp_error( $result ) ) {
            $this->logger->add_log( 'Cloudflare Workers: ' . $result->get_error_message(), true );
            return;
        }

        $this->logger->add_log( 'Cloudflare Workers出力完了: ' . $this->settings['cloudflare_script_name'] );
    }

    /**
     * Netlify出力
     */
    private function output_to_netlify() {
        // 一時ディレクトリの全ファイルパスを取得（絶対パス）
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

        // Phase 1: デプロイ作成
        $file_digests = array();
        foreach ( $file_paths as $file_path ) {
            $relative_path = str_replace( trailingslashit( $real_dir ), '', $file_path );
            $file_digests[ $relative_path ] = sha1_file( $file_path );
        }

        // トークン確認
        $token = isset( $this->settings['netlify_api_token'] ) ? $this->settings['netlify_api_token'] : '';
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
        $required_files = isset( $deploy_data['required'] ) ? $deploy_data['required'] : array();

        $this->logger->add_log( 'Netlifyデプロイ作成完了: ' . $deploy_id );
        $this->logger->add_log( 'アップロード必要ファイル数: ' . count( $required_files ) );

        // Phase 2: ファイルアップロード（進捗更新付き）
        $uploaded = 0;
        $total_required = count( $required_files );

        foreach ( $file_paths as $file_path ) {
            $relative_path = str_replace( trailingslashit( $real_dir ), '', $file_path );
            $file_hash = sha1_file( $file_path );

            // 既にアップロード済みのファイルはスキップ
            if ( ! in_array( $file_hash, $required_files, true ) ) {
                continue;
            }

            $file_content = file_get_contents( $file_path );

            $upload_response = wp_remote_request(
                'https://api.netlify.com/api/v1/deploys/' . $deploy_id . '/files/' . $relative_path,
                array(
                    'method'  => 'PUT',
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $this->settings['netlify_api_token'],
                        'Content-Type'  => 'application/octet-stream',
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

            // 進捗更新（10ファイルごと、または最終ファイル）
            if ( $uploaded % 10 === 0 || $uploaded === $total_required ) {
                $this->logger->add_log( sprintf( 'Netlify: %d / %d ファイルアップロード済み', $uploaded, $total_required ) );
            }
        }

        $this->logger->add_log( 'Netlify出力完了: ' . $this->settings['netlify_site_id'] );
    }

    /**
     * GitLab API経由で出力
     */
    private function output_to_gitlab_api() {
        $branch = $this->settings['gitlab_branch_mode'] === 'existing'
            ? $this->settings['gitlab_existing_branch']
            : $this->settings['gitlab_new_branch'];

        $gitlab_api = new SGE_GitLab_API(
            $this->settings['gitlab_token'],
            $this->settings['gitlab_project'],
            $branch,
            $this->settings['gitlab_api_url']
        );

        // プロジェクト存在チェック
        $repo_exists = $gitlab_api->check_repo_exists();
        if ( is_wp_error( $repo_exists ) ) {
            $this->logger->add_log( $repo_exists->get_error_message(), true );
            return;
        }

        if ( ! $repo_exists ) {
            // プロジェクトを作成
            $result = $gitlab_api->create_repo();
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

        $this->logger->add_log( '合計 ' . count( $file_paths ) . ' ファイルをGitLabにプッシュします' );

        // バッチ処理でGitLabにプッシュ（300ファイルごと）
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

        $this->logger->add_log( 'ZIP出力完了: ' . $zip_filename . ' (' . $zip_size_mb . 'MB)' );
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
                // 最高圧縮率（レベル9）を設定
                $zip->setCompressionName( $relative_path, ZipArchive::CM_DEFLATE, 9 );
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

        $this->logger->add_log( 'GitHub出力完了: ' . $this->settings['github_repo'] );
    }

    /**
     * ローカルGitに出力
     */
    private function output_to_git_local() {
        $work_dir = $this->settings['git_local_work_dir'];
        $branch = $this->settings['git_local_branch'];
        $push_remote = ! empty( $this->settings['git_local_push_remote'] );

        // ブランチ名の検証（セキュリティ対策）
        if ( ! $this->is_valid_git_branch_name( $branch ) ) {
            $this->logger->add_log( 'エラー: 無効なブランチ名です', true );
            return;
        }

        // gitコマンドのパスを検出
        $git_cmd = self::find_git_command();
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
        $this->remove_directory_contents( $work_dir, array( '.git' ) );

        // 静的ファイルをコピー
        $this->copy_directory_recursive( $this->temp_dir, $work_dir );

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
                // コミットがある場合は通常のブランチ作成
                exec( $git_cmd . ' checkout -b ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            } else {
                // 空のリポジトリの場合はorphanブランチを作成
                exec( $git_cmd . ' checkout --orphan ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            }
            if ( $return_code !== 0 ) {
                $error_msg = implode( ' ', $output );
                $this->logger->add_log( 'ブランチの作成に失敗: ' . $error_msg, true );
                chdir( $old_dir );
                return;
            }
        }

        // 全ファイルをステージング
        $output = array();
        exec( $git_cmd . ' add -A 2>&1', $output, $return_code );
        if ( $return_code !== 0 ) {
            $error_msg = implode( ' ', $output );
            $this->logger->add_log( 'ファイルのステージングに失敗: ' . $error_msg, true );
            chdir( $old_dir );
            return;
        }

        // コミット
        $commit_message = ! empty( $this->settings['commit_message'] )
            ? $this->settings['commit_message']
            : 'Static site update: ' . current_time( 'Y-m-d H:i:s' );

        $output = array();
        exec( $git_cmd . ' commit -m ' . escapeshellarg( $commit_message ) . ' 2>&1', $output, $return_code );
        $commit_created = false;
        if ( $return_code !== 0 ) {
            // コミットがない場合（変更がない場合）
            if ( strpos( implode( "\n", $output ), 'nothing to commit' ) === false ) {
                $error_msg = implode( ' ', $output );
                $this->logger->add_log( 'コミットに失敗: ' . $error_msg, true );
                chdir( $old_dir );
                return;
            }
        } else {
            $commit_created = true;
        }

        // リモートにプッシュ
        $push_result = '';
        if ( $push_remote ) {
            $output = array();
            exec( $git_cmd . ' push origin ' . escapeshellarg( $branch ) . ' 2>&1', $output, $return_code );
            if ( $return_code !== 0 ) {
                $sanitized_error = $this->sanitize_git_error( $output );
                $this->logger->add_log( 'リモートへのプッシュに失敗' . ( $sanitized_error ? ': ' . $sanitized_error : '' ), true );
            } else {
                $push_result = ' (push済)';
            }
        }

        chdir( $old_dir );

        // サマリーログ
        if ( $commit_created ) {
            $this->logger->add_log( 'ローカルGit出力完了: ' . $branch . $push_result );
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
    private function is_directory_empty_recursive( $dir ) {
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

        // パストラバーサル対策: realpath で正規化して検証
        $real_src = realpath( $src );
        if ( $real_src === false ) {
            $this->logger->add_log( 'ソースパスの解決に失敗しました: ' . $src, true );
            return false;
        }

        // 空のディレクトリはスキップ
        if ( $this->is_directory_empty_recursive( $real_src ) ) {
            return true; // エラーではないのでtrueを返す
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
            if ( strpos( $file, "\0" ) !== false ) {
                continue;
            }

            $src_path = $real_src . '/' . $file;
            $dst_path = $dst . '/' . $file;

            // パストラバーサル対策: src_pathがreal_src内にあることを確認
            $real_src_path = realpath( $src_path );
            if ( $real_src_path === false || strpos( $real_src_path, $real_src ) !== 0 ) {
                $this->logger->add_log( 'パストラバーサルを検出: ' . $file, true );
                continue;
            }

            if ( is_dir( $real_src_path ) ) {
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
                    $content = file_get_contents( $real_src_path );
                    if ( $content !== false ) {
                        // URL変換を実行
                        $content = $this->convert_asset_urls( $content, $extension );
                        if ( file_put_contents( $dst_path, $content ) === false ) {
                            $this->logger->add_log( 'ファイルの書き込みに失敗しました: ' . $dst_path, true );
                            $error_count++;
                        } else {
                            $file_count++;
                        }
                    } else {
                        // 読み込み失敗時は通常のコピー
                        if ( ! copy( $real_src_path, $dst_path ) ) {
                            $this->logger->add_log( 'ファイルのコピーに失敗しました: ' . $real_src_path, true );
                            $error_count++;
                        } else {
                            $file_count++;
                        }
                    }
                } else {
                    // その他のファイルは通常のコピー
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
    public static function find_git_command() {
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
                // アイコンのパスを取得
                $icon_path = get_attached_file( $site_icon_id );

                if ( $icon_path && file_exists( $icon_path ) ) {
                    // 拡張子を取得
                    $extension = pathinfo( $icon_path, PATHINFO_EXTENSION );

                    // favicon.ico としてコピー
                    $favicon_dest = $this->temp_dir . '/favicon.ico';
                    if ( ! copy( $icon_path, $favicon_dest ) ) {
                        $this->logger->add_log( 'ファビコンのコピーに失敗', true );
                    }

                    // 元の拡張子でもコピー（.png, .webp など）
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
            // サイトアイコンが設定されていない場合、ルートディレクトリのfavicon.icoを探す
            $favicon_path = ABSPATH . 'favicon.ico';
            if ( file_exists( $favicon_path ) ) {
                $favicon_dest = $this->temp_dir . '/favicon.ico';
                if ( ! copy( $favicon_path, $favicon_dest ) ) {
                    $this->logger->add_log( 'favicon.icoのコピーに失敗', true );
                }
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
            if ( file_exists( $icon_path ) && is_readable( $icon_path ) ) {
                $icon_dest = $this->temp_dir . '/' . $icon_file;
                copy( $icon_path, $icon_dest );
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
        if ( file_put_contents( $robots_txt_path, $robots_content ) === false ) {
            $this->logger->add_log( 'robots.txtの生成に失敗', true );
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
    private function copy_active_themes( $wp_content_dest ) {
        $themes_dir = get_theme_root();
        $themes_dest = $wp_content_dest . '/themes';

        if ( ! is_dir( $themes_dir ) ) {
            return false;
        }

        if ( ! is_dir( $themes_dest ) ) {
            mkdir( $themes_dest, 0755, true );
        }

        // 現在のテーマを取得
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
    private function copy_referenced_uploads( $wp_content_dest ) {
        $uploads_dir = wp_upload_dir();
        $uploads_base = $uploads_dir['basedir'];
        $uploads_dest = $wp_content_dest . '/uploads';

        if ( ! is_dir( $uploads_base ) ) {
            return false;
        }

        if ( ! is_dir( $uploads_dest ) ) {
            mkdir( $uploads_dest, 0755, true );
        }

        // 使用されているメディアIDを収集
        $referenced_ids = $this->get_referenced_attachment_ids();

        if ( empty( $referenced_ids ) ) {
            $this->logger->debug( "参照メディアなし: uploadsをスキップ" );
            return true;
        }

        $copied_count = 0;
        $total_size = 0;

        foreach ( $referenced_ids as $attachment_id ) {
            $file_path = get_attached_file( $attachment_id );
            if ( ! $file_path || ! file_exists( $file_path ) ) {
                continue;
            }

            // メインファイルをコピー
            $relative_path = str_replace( trailingslashit( $uploads_base ), '', $file_path );
            $dest_path = $uploads_dest . '/' . $relative_path;

            // ディレクトリを作成
            $dest_dir = dirname( $dest_path );
            if ( ! is_dir( $dest_dir ) ) {
                mkdir( $dest_dir, 0755, true );
            }

            if ( copy( $file_path, $dest_path ) ) {
                $copied_count++;
                $total_size += filesize( $file_path );
            }

            // サムネイル・中間サイズもコピー
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

        $size_mb = round( $total_size / 1024 / 1024, 2 );
        $this->logger->debug( "メディアコピー: {$copied_count}ファイル ({$size_mb}MB)" );

        // HTMLから参照されているuploads内の非メディアファイル（プラグイン生成CSSなど）もコピー
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
    private function copy_referenced_upload_files( $uploads_base, $uploads_dest ) {
        // 一時ディレクトリ内の全HTMLファイルをスキャン
        $html_files = $this->get_all_html_files( $this->temp_dir );
        $referenced_paths = array();

        foreach ( $html_files as $html_file ) {
            $content = file_get_contents( $html_file );

            // link href（CSS）
            preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\']/', $content, $css_matches );

            // script src
            preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/', $content, $js_matches );

            // style内のurl()
            preg_match_all( '/url\(["\']?([^"\')\s]+)["\']?\)/', $content, $url_matches );

            $all_paths = array_merge( $css_matches[1], $js_matches[1], $url_matches[1] );

            foreach ( $all_paths as $path ) {
                // wp-content/uploads/ を含むパスのみ（ただしメディアライブラリ形式以外）
                if ( strpos( $path, 'wp-content/uploads/' ) !== false ) {
                    // 年/月形式のディレクトリ（2025/09/など）以外を対象
                    // プラグイン生成ファイル（loftloader-pro/, wp2static/ など）
                    $normalized = ltrim( $path, '/' );
                    $normalized = preg_replace( '/\?.*$/', '', $normalized ); // クエリ文字列を除去

                    // uploads/以降のパスを取得
                    if ( preg_match( '/wp-content\/uploads\/(.+)/', $normalized, $matches ) ) {
                        $upload_relative = $matches[1];
                        // 年月形式（YYYY/MM/）以外のパス
                        if ( ! preg_match( '/^\d{4}\/\d{2}\//', $upload_relative ) ) {
                            $referenced_paths[] = $upload_relative;
                        }
                    }
                }
            }
        }

        $referenced_paths = array_unique( $referenced_paths );
        $copied_count = 0;

        foreach ( $referenced_paths as $relative_path ) {
            $src_path = $uploads_base . '/' . $relative_path;
            $dest_path = $uploads_dest . '/' . $relative_path;

            if ( ! file_exists( $src_path ) ) {
                continue;
            }

            // ディレクトリを作成
            $dest_dir = dirname( $dest_path );
            if ( ! is_dir( $dest_dir ) ) {
                mkdir( $dest_dir, 0755, true );
            }

            if ( copy( $src_path, $dest_path ) ) {
                $copied_count++;
            }
        }

        if ( $copied_count > 0 ) {
            $this->logger->debug( "プラグイン生成ファイルコピー（uploads）: {$copied_count}ファイル" );
        }
    }

    /**
     * 参照されているメディアIDを取得
     *
     * @return array 添付ファイルIDの配列
     */
    private function get_referenced_attachment_ids() {
        global $wpdb;

        $attachment_ids = array();

        // 1. アイキャッチ画像
        $thumbnail_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value > 0"
        );
        $attachment_ids = array_merge( $attachment_ids, $thumbnail_ids );

        // 2. 投稿本文内の画像（wp-image-XXX クラスから抽出）
        $content_ids = $wpdb->get_col(
            "SELECT DISTINCT CAST(
                SUBSTRING(post_content, LOCATE('wp-image-', post_content) + 9, 10) AS UNSIGNED
            ) as attachment_id
            FROM {$wpdb->posts}
            WHERE post_status = 'publish'
            AND post_content LIKE '%wp-image-%'"
        );
        $attachment_ids = array_merge( $attachment_ids, array_filter( $content_ids ) );

        // 3. ギャラリーショートコード内の画像
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

        // 4. サイトアイコン
        $site_icon_id = get_option( 'site_icon' );
        if ( $site_icon_id ) {
            $attachment_ids[] = $site_icon_id;
        }

        // 5. カスタマイザーで設定されたロゴ・ヘッダー画像
        $customizer_images = array(
            'custom_logo',
            'header_image_data',
            'background_image',
        );
        foreach ( $customizer_images as $option ) {
            $value = get_theme_mod( $option );
            if ( is_numeric( $value ) ) {
                $attachment_ids[] = intval( $value );
            } elseif ( is_array( $value ) && isset( $value['attachment_id'] ) ) {
                $attachment_ids[] = intval( $value['attachment_id'] );
            }
        }

        // 重複を除去して整数配列として返す
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
    private function copy_active_plugin_assets( $wp_content_dest ) {
        $plugins_dir = WP_PLUGIN_DIR;
        $plugins_dest = $wp_content_dest . '/plugins';

        if ( ! is_dir( $plugins_dir ) ) {
            return false;
        }

        // HTMLから参照されているプラグインアセットを取得
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
            // wp-content/plugins/ 以降のパスを取得
            $plugin_relative = str_replace( 'wp-content/plugins/', '', $relative_path );
            $src_path = $plugins_dir . '/' . $plugin_relative;
            $dest_path = $plugins_dest . '/' . $plugin_relative;

            if ( ! file_exists( $src_path ) ) {
                continue;
            }

            // コピー先ディレクトリを作成
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
    private function copy_plugin_assets_only( $src, $dest ) {
        if ( ! is_dir( $src ) ) {
            return false;
        }

        // 静的アセットの拡張子
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
    private function get_referenced_plugin_assets() {
        $referenced_paths = array();

        // 一時ディレクトリ内の全HTMLファイルを再帰的に取得
        $html_files = $this->get_all_html_files( $this->temp_dir );

        foreach ( $html_files as $html_file ) {
            $content = file_get_contents( $html_file );

            // CSS: <link href="...">
            preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\']/', $content, $css_matches );

            // JS: <script src="...">
            preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/', $content, $js_matches );

            // 画像: <img src="...">
            preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $img_matches );

            // srcset属性
            preg_match_all( '/srcset=["\']([^"\']+)["\']/', $content, $srcset_matches );

            // style属性内のurl()
            preg_match_all( '/style=["\'][^"\']*url\(["\']?([^"\')\s]+)["\']?\)/', $content, $style_url_matches );

            $all_paths = array_merge(
                $css_matches[1],
                $js_matches[1],
                $img_matches[1],
                $style_url_matches[1]
            );

            // srcsetの処理（複数の画像パスを含む）
            foreach ( $srcset_matches[1] as $srcset ) {
                $srcset_parts = explode( ',', $srcset );
                foreach ( $srcset_parts as $part ) {
                    $part = trim( $part );
                    $path = preg_replace( '/\s+\d+[wx]$/', '', $part );
                    $all_paths[] = trim( $path );
                }
            }

            foreach ( $all_paths as $path ) {
                // wp-content/plugins/ を含むパスのみ抽出
                if ( strpos( $path, 'wp-content/plugins/' ) !== false ) {
                    $normalized = $this->normalize_plugin_asset_path( $path );
                    if ( ! empty( $normalized ) ) {
                        $referenced_paths[] = $normalized;
                    }
                }
            }
        }

        // CSSファイルからの参照も収集
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
    private function get_all_html_files( $dir ) {
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
    private function normalize_plugin_asset_path( $path ) {
        // クエリ文字列を除去
        $path = preg_replace( '/\?.*$/', '', $path );

        // フラグメントを除去
        $path = preg_replace( '/#.*$/', '', $path );

        // 先頭のスラッシュを除去（/wp-content/... → wp-content/...）
        $path = ltrim( $path, '/' );

        // wp-content/plugins/ 以降のパスを抽出
        if ( preg_match( '/(wp-content\/plugins\/[^\s"\']+)/', $path, $matches ) ) {
            return $matches[1];
        }

        return '';
    }

    /**
     * CSSファイル内から参照されているプラグインアセットを収集
     *
     * @param array $html_referenced HTMLから参照されているパス
     * @return array CSS内で参照されているアセットパス
     */
    private function get_css_referenced_plugin_assets( $html_referenced ) {
        $css_referenced = array();
        $processed_css = array();

        // HTMLから参照されているCSSファイルを処理
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
    private function extract_css_references( $css_relative_path, &$processed ) {
        // 無限ループ防止
        if ( in_array( $css_relative_path, $processed ) ) {
            return array();
        }
        $processed[] = $css_relative_path;

        $referenced = array();

        // WordPressのルートディレクトリからCSSファイルのフルパスを取得
        $css_full_path = ABSPATH . $css_relative_path;

        if ( ! file_exists( $css_full_path ) ) {
            return array();
        }

        $content = file_get_contents( $css_full_path );
        $css_dir = dirname( $css_relative_path );

        // @import
        preg_match_all( '/@import\s+["\']([^"\']+)["\']/', $content, $import_matches );
        preg_match_all( '/@import\s+url\(["\']?([^"\')\s]+)["\']?\)/', $content, $import_url_matches );

        // url()
        preg_match_all( '/url\(["\']?([^"\')\s]+)["\']?\)/', $content, $url_matches );

        $all_paths = array_merge(
            $import_matches[1],
            $import_url_matches[1],
            $url_matches[1]
        );

        foreach ( $all_paths as $path ) {
            // data: URI, http/https外部URLは除外
            if ( preg_match( '/^(data:|https?:\/\/|\/\/)/', $path ) ) {
                continue;
            }

            // 相対パスを解決
            $resolved = $this->resolve_css_relative_path( $css_dir, $path );

            // wp-content/plugins/ のパスのみ対象
            if ( strpos( $resolved, 'wp-content/plugins/' ) !== false ) {
                $referenced[] = $resolved;

                // @importされたCSSは再帰的に処理
                if ( preg_match( '/\.css$/i', $resolved ) ) {
                    $nested = $this->extract_css_references( $resolved, $processed );
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
    private function resolve_css_relative_path( $css_dir, $path ) {
        // クエリ文字列を除去
        $path = preg_replace( '/\?.*$/', '', $path );

        // フラグメントを除去
        $path = preg_replace( '/#.*$/', '', $path );

        // 絶対パス（/で始まる）の場合
        if ( strpos( $path, '/' ) === 0 ) {
            return ltrim( $path, '/' );
        }

        // 相対パスを解決
        $full_path = $css_dir . '/' . $path;

        // ../や./を正規化
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
    private function is_valid_git_branch_name( $branch ) {
        // 空チェック
        if ( empty( $branch ) ) {
            return false;
        }

        // 長さ制限（255文字以下）
        if ( strlen( $branch ) > 255 ) {
            return false;
        }

        // 許可された文字のみ（英数字、ハイフン、アンダースコア、スラッシュ、ドット）
        // ただし、先頭・末尾のドット、連続ドット、先頭のハイフンは禁止
        if ( ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_\-\/]*[a-zA-Z0-9]$|^[a-zA-Z0-9]$/', $branch ) ) {
            return false;
        }

        // 危険なパターンを禁止
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
            if ( strpos( $branch, $pattern ) !== false ) {
                return false;
            }
        }

        // .lockで終わるブランチ名は禁止
        if ( substr( $branch, -5 ) === '.lock' ) {
            return false;
        }

        return true;
    }
}
