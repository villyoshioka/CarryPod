<?php
/**
 * キャッシュ管理クラス
 */

class CP_Cache {

    /**
     * シングルトンインスタンス
     */
    private static $instance = null;

    /**
     * キャッシュディレクトリ
     */
    private $cache_dir;

    /**
     * 現在のページで使用された投稿IDリスト
     */
    private $current_dependent_posts = array();

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
        $this->cache_dir = WP_CONTENT_DIR . '/cp-cache';
        $this->ensure_cache_dir();
    }

    /**
     * キャッシュディレクトリを作成
     */
    private function ensure_cache_dir() {
        if ( ! is_dir( $this->cache_dir ) ) {
            mkdir( $this->cache_dir, 0700, true );

            // .htaccessを作成してディレクトリリスティングを防ぐ
            $htaccess_file = $this->cache_dir . '/.htaccess';
            if ( ! file_exists( $htaccess_file ) ) {
                $htaccess_content = "# Protect cache directory\n";
                $htaccess_content .= "Options -Indexes\n";
                $htaccess_content .= "<FilesMatch \".*\">\n";
                $htaccess_content .= "    Require all denied\n";
                $htaccess_content .= "</FilesMatch>\n";
                file_put_contents( $htaccess_file, $htaccess_content );
            }

            // index.phpを作成
            $index_file = $this->cache_dir . '/index.php';
            if ( ! file_exists( $index_file ) ) {
                file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
            }
        }
    }

    /**
     * URLのキャッシュキーを生成
     *
     * @param string $url URL
     * @return string キャッシュキー
     */
    private function get_cache_key( $url ) {
        return md5( $url );
    }

    /**
     * キャッシュファイルのパスを取得
     *
     * @param string $cache_key キャッシュキー
     * @return string ファイルパス
     */
    private function get_cache_file_path( $cache_key ) {
        return $this->cache_dir . '/' . $cache_key . '.html';
    }

    /**
     * メタデータファイルのパスを取得
     *
     * @param string $cache_key キャッシュキー
     * @return string ファイルパス
     */
    private function get_meta_file_path( $cache_key ) {
        return $this->cache_dir . '/' . $cache_key . '.meta';
    }

    /**
     * キャッシュが有効かチェック
     *
     * @param string $url URL
     * @param int|null $post_id 投稿ID（オプション）
     * @return bool 有効ならtrue
     */
    public function is_valid( $url, $post_id = null ) {
        $cache_key = $this->get_cache_key( $url );
        $cache_file = $this->get_cache_file_path( $cache_key );
        $meta_file = $this->get_meta_file_path( $cache_key );

        // キャッシュファイルが存在しない
        if ( ! file_exists( $cache_file ) || ! file_exists( $meta_file ) ) {
            return false;
        }

        // メタデータを読み込み
        $meta = json_decode( file_get_contents( $meta_file ), true );
        if ( ! $meta ) {
            return false;
        }

        $cache_time = $meta['timestamp'];

        // グローバルな最終投稿変更時刻をチェック
        $last_post_change = get_option( 'cp_last_post_change', 0 );
        if ( $last_post_change > $cache_time ) {
            return false;
        }

        // 依存投稿リストをチェック（後方互換性: 存在しない場合はスキップ）
        if ( isset( $meta['dependent_posts'] ) && is_array( $meta['dependent_posts'] ) ) {
            foreach ( $meta['dependent_posts'] as $dependent_post_id ) {
                $post = get_post( $dependent_post_id );
                if ( ! $post ) {
                    // 投稿が削除された場合、キャッシュ無効
                    return false;
                }

                // 投稿が非公開になった場合、キャッシュ無効
                if ( $post->post_status !== 'publish' ) {
                    return false;
                }

                // post_modified_gmt（UTC）を使用してタイムゾーン問題を回避
                $post_modified = (float) strtotime( $post->post_modified_gmt . ' UTC' );
                if ( $post_modified > $cache_time ) {
                    return false;
                }

                // 依存投稿のURLが変更されていないかチェック（スラッグ変更対応）
                if ( isset( $meta['dependent_posts_urls'] ) && is_array( $meta['dependent_posts_urls'] ) ) {
                    $cached_url = isset( $meta['dependent_posts_urls'][ $dependent_post_id ] ) ? $meta['dependent_posts_urls'][ $dependent_post_id ] : '';
                    $current_url = get_permalink( $dependent_post_id );
                    if ( $cached_url && $current_url !== $cached_url ) {
                        // URLが変更されている場合、キャッシュ無効
                        return false;
                    }
                }
            }
        }

        // 投稿IDが指定されている場合は更新日時をチェック
        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return false;
            }

            // post_modified_gmt（UTC）を使用してタイムゾーン問題を回避
            $post_modified = (float) strtotime( $post->post_modified_gmt . ' UTC' );

            // 投稿が更新されていればキャッシュは無効
            if ( $post_modified > $cache_time ) {
                return false;
            }
        }

        return true;
    }

    /**
     * キャッシュを取得
     *
     * @param string $url URL
     * @return string|false キャッシュされたHTML、存在しなければfalse
     */
    public function get( $url ) {
        $cache_key = $this->get_cache_key( $url );
        $cache_file = $this->get_cache_file_path( $cache_key );

        if ( ! file_exists( $cache_file ) ) {
            return false;
        }

        return file_get_contents( $cache_file );
    }

    /**
     * キャッシュを保存
     *
     * @param string $url URL
     * @param string $html HTML
     * @param int|null $post_id 投稿ID（オプション）
     * @return bool 成功ならtrue
     */
    public function set( $url, $html, $post_id = null ) {
        $cache_key = $this->get_cache_key( $url );
        $cache_file = $this->get_cache_file_path( $cache_key );
        $meta_file = $this->get_meta_file_path( $cache_key );

        // HTMLを保存
        if ( false === file_put_contents( $cache_file, $html ) ) {
            return false;
        }

        // 依存投稿リストを取得（最大100件に制限）
        $dependent_posts = array_slice( array_unique( $this->current_dependent_posts ), 0, 100 );

        // 依存投稿のURLマップを作成（スラッグ変更検出用）
        $dependent_posts_urls = array();
        foreach ( $dependent_posts as $dep_post_id ) {
            $dependent_posts_urls[ $dep_post_id ] = get_permalink( $dep_post_id );
        }

        // メタデータを保存
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

        // 依存投稿リストをクリア
        $this->current_dependent_posts = array();

        return true;
    }

    /**
     * 特定のURLのキャッシュを削除
     *
     * @param string $url URL
     * @return bool 成功ならtrue
     */
    public function delete( $url ) {
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
     * 投稿に関連するキャッシュを削除
     *
     * @param int $post_id 投稿ID
     * @return int 削除したキャッシュの数
     */
    public function delete_by_post_id( $post_id ) {
        $deleted = 0;

        // メタファイルを検索
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
     * すべてのキャッシュをクリア
     *
     * @return int 削除したファイル数
     */
    public function clear_all() {
        $deleted = 0;

        $files = glob( $this->cache_dir . '/*' );
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file );
                $deleted++;
            }
        }

        // Mati連携: seedを再生成（フィルターでスキップされない場合）
        $this->trigger_mati_seed_regeneration();

        return $deleted;
    }

    /**
     * Mati連携: obfuscation seedを再生成
     *
     * キャッシュクリア時にMatiのobfuscation seedを再生成することで、
     * 静的化ファイルの難読化パターンを変更する
     */
    private function trigger_mati_seed_regeneration() {
        // フィルターでスキップ指定されている場合は処理しない（無限ループ防止）
        if ( apply_filters( 'cp_skip_mati_seed_regen', false ) ) {
            return;
        }

        // Matiがインストールされていない場合は処理しない
        if ( ! defined( 'MATI_VERSION' ) ) {
            return;
        }

        // Mati_Settingsクラスが存在することを確認
        if ( ! class_exists( 'Mati_Settings' ) ) {
            return;
        }

        try {
            // Mati_Settingsインスタンスを取得
            if ( ! method_exists( 'Mati_Settings', 'get_instance' ) ) {
                error_log( 'CarryPod: Mati連携エラー - get_instanceメソッドが存在しません' );
                return;
            }

            $mati_settings = Mati_Settings::get_instance();

            // save_settingsメソッドが存在することを確認
            if ( ! method_exists( $mati_settings, 'save_settings' ) ) {
                error_log( 'CarryPod: Mati連携エラー - save_settingsメソッドが存在しません' );
                return;
            }

            // get_settingsメソッドが存在することを確認
            if ( ! method_exists( $mati_settings, 'get_settings' ) ) {
                error_log( 'CarryPod: Mati連携エラー - get_settingsメソッドが存在しません' );
                return;
            }

            // 既存の設定を取得
            $current_settings = $mati_settings->get_settings();

            // seedを再生成（skip_cp_clearオプションで無限ループ防止）
            $mati_settings->save_settings( $current_settings, array( 'skip_cp_clear' => true ) );

        } catch ( Exception $e ) {
            // エラーが発生してもキャッシュクリア処理は成功させる
            error_log( 'CarryPod: Mati連携エラー - ' . $e->getMessage() );
        }
    }

    /**
     * 依存投稿IDを記録
     *
     * @param int $post_id 投稿ID
     */
    public function add_dependent_post( $post_id ) {
        if ( $post_id > 0 && ! in_array( $post_id, $this->current_dependent_posts ) ) {
            $this->current_dependent_posts[] = $post_id;
        }
    }

    /**
     * 記録された依存投稿IDを取得
     *
     * @return array 投稿IDの配列
     */
    public function get_dependent_posts() {
        return $this->current_dependent_posts;
    }

    /**
     * 依存投稿IDリストをクリア
     */
    public function clear_dependent_posts() {
        $this->current_dependent_posts = array();
    }

    /**
     * キャッシュ統計を取得
     *
     * @return array キャッシュ統計
     */
    public function get_stats() {
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
