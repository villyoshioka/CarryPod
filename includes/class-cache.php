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

        // 投稿IDが指定されている場合は更新日時をチェック
        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return false;
            }

            $post_modified = strtotime( $post->post_modified );
            $cache_time = $meta['timestamp'];

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

        // メタデータを保存
        $meta = array(
            'url' => $url,
            'post_id' => $post_id,
            'timestamp' => time(),
        );

        if ( false === file_put_contents( $meta_file, json_encode( $meta ) ) ) {
            return false;
        }

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

        return $deleted;
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
