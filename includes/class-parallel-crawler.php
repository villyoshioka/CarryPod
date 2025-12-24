<?php
/**
 * 並列クローラークラス（WP2Staticを参考に実装）
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_Parallel_Crawler {

    /**
     * 並列処理の同時実行数
     */
    private $concurrency = 5;

    /**
     * タイムアウト（秒）
     */
    private $timeout = 30;

    /**
     * ユーザーエージェント
     */
    private $user_agent = 'Carry Pod/1.0';

    /**
     * ロガーインスタンス
     */
    private $logger;

    /**
     * キャッシュインスタンス
     */
    private $cache;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->logger = CP_Logger::get_instance();
        $this->cache = CP_Cache::get_instance();
    }

    /**
     * URLリストを並列でクロール
     *
     * @param array $urls URLの配列
     * @return array 結果の配列
     */
    public function crawl_urls( $urls ) {
        $results = array();
        $chunks = array_chunk( $urls, $this->concurrency );

        foreach ( $chunks as $chunk ) {
            $multi_handle = curl_multi_init();
            $curl_handles = array();

            // 各URLに対してcURLハンドルを作成
            foreach ( $chunk as $index => $url ) {
                $ch = curl_init();
                curl_setopt( $ch, CURLOPT_URL, $url );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
                curl_setopt( $ch, CURLOPT_MAXREDIRS, 3 );
                curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
                curl_setopt( $ch, CURLOPT_USERAGENT, $this->user_agent );

                // ローカルホスト判定
                $parsed_url = parse_url( $url );
                $is_localhost = in_array(
                    $parsed_url['host'] ?? '',
                    array( 'localhost', '127.0.0.1', '::1' ),
                    true
                );

                // ローカルホスト以外ではSSL検証を有効化
                curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, ! $is_localhost );
                curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, $is_localhost ? 0 : 2 );

                // Basic認証が設定されている場合
                $auth_user = get_option( 'cp_basic_auth_user' );
                if ( $auth_user ) {
                    $encrypted_pass = get_option( 'cp_basic_auth_pass' );
                    // 暗号化されたパスワードを復号化
                    $settings_manager = CP_Settings::get_instance();
                    $auth_pass = $settings_manager->decrypt_basic_auth( $encrypted_pass );
                    if ( ! is_wp_error( $auth_pass ) ) {
                        curl_setopt( $ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC );
                        curl_setopt( $ch, CURLOPT_USERPWD, $auth_user . ':' . $auth_pass );
                    }
                }

                curl_multi_add_handle( $multi_handle, $ch );
                $curl_handles[ $index ] = $ch;
            }

            // 並列実行
            $running = null;
            do {
                $mrc = curl_multi_exec( $multi_handle, $running );
            } while ( $mrc == CURLM_CALL_MULTI_PERFORM );

            while ( $running && $mrc == CURLM_OK ) {
                if ( curl_multi_select( $multi_handle ) == -1 ) {
                    usleep( 100 );
                }

                do {
                    $mrc = curl_multi_exec( $multi_handle, $running );
                } while ( $mrc == CURLM_CALL_MULTI_PERFORM );
            }

            // 結果を取得
            foreach ( $curl_handles as $index => $ch ) {
                $info = curl_getinfo( $ch );
                $content = curl_multi_getcontent( $ch );
                $error = curl_error( $ch );

                $results[ $chunk[ $index ] ] = array(
                    'content' => $content,
                    'status_code' => $info['http_code'],
                    'effective_url' => $info['url'],
                    'error' => $error,
                    'cached' => false,
                );

                curl_multi_remove_handle( $multi_handle, $ch );
                curl_close( $ch );
            }

            curl_multi_close( $multi_handle );
        }

        return $results;
    }

    /**
     * キャッシュを使用した並列クロール
     *
     * @param array $urls URLの配列
     * @return array 結果の配列
     */
    public function crawl_with_cache( $urls ) {
        $results = array();
        $urls_to_crawl = array();

        // キャッシュチェック
        foreach ( $urls as $url ) {
            $post_id = url_to_postid( $url );

            if ( $this->cache->is_valid( $url, $post_id ) ) {
                $cached_content = $this->cache->get( $url );
                if ( $cached_content !== false ) {
                    $results[ $url ] = array(
                        'content' => $cached_content,
                        'status_code' => 200,
                        'effective_url' => $url,
                        'error' => '',
                        'cached' => true,
                    );
                    $this->logger->add_log( 'キャッシュから取得: ' . $url );
                } else {
                    $urls_to_crawl[] = $url;
                }
            } else {
                $urls_to_crawl[] = $url;
            }
        }

        // キャッシュにないURLをクロール
        if ( ! empty( $urls_to_crawl ) ) {
            $crawl_results = $this->crawl_urls( $urls_to_crawl );

            // キャッシュに保存
            foreach ( $crawl_results as $url => $result ) {
                if ( $result['status_code'] == 200 && ! empty( $result['content'] ) ) {
                    $post_id = url_to_postid( $url );
                    $this->cache->set( $url, $result['content'], $post_id );
                }

                $results[ $url ] = $result;
            }
        }

        return $results;
    }

    /**
     * 同時実行数を設定
     *
     * @param int $concurrency 同時実行数
     */
    public function set_concurrency( $concurrency ) {
        $this->concurrency = max( 1, min( 10, intval( $concurrency ) ) );
    }

    /**
     * タイムアウトを設定
     *
     * @param int $timeout タイムアウト（秒）
     */
    public function set_timeout( $timeout ) {
        $this->timeout = max( 10, intval( $timeout ) );
    }
}