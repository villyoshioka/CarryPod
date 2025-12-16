<?php
/**
 * GitHub からの自動更新クラス
 *
 * GitHub Releases API を使用してプラグインの更新をチェックし、
 * WordPress の更新システムに統合します。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SGE_Updater {

    /**
     * GitHub リポジトリのオーナー
     */
    private $github_owner = 'villyoshioka';

    /**
     * GitHub リポジトリ名
     */
    private $github_repo = 'CarryPod';

    /**
     * プラグインのベースネーム
     */
    private $plugin_basename;

    /**
     * プラグインのスラッグ
     */
    private $plugin_slug;

    /**
     * 現在のバージョン
     */
    private $current_version;

    /**
     * キャッシュキー
     */
    private $cache_key = 'sge_github_release_cache';

    /**
     * キャッシュ有効期間（秒）
     */
    private $cache_expiry = 43200; // 12時間

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->plugin_basename = plugin_basename( SGE_PLUGIN_DIR . 'static-generation-engine.php' );
        $this->plugin_slug = dirname( $this->plugin_basename );
        $this->current_version = SGE_VERSION;

        // フックを登録
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( $this, 'on_upgrade_complete' ), 10, 2 );
    }

    /**
     * プラグイン更新完了時にキャッシュをクリア
     *
     * @param WP_Upgrader $upgrader アップグレーダーインスタンス
     * @param array       $options  更新オプション
     */
    public function on_upgrade_complete( $upgrader, $options ) {
        // プラグイン更新の場合のみ処理
        if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
            return;
        }

        // このプラグインが更新対象に含まれているかチェック
        $plugins = isset( $options['plugins'] ) ? $options['plugins'] : array();
        if ( ! is_array( $plugins ) ) {
            $plugins = array( $plugins );
        }

        if ( in_array( $this->plugin_basename, $plugins, true ) ) {
            // GitHubリリースキャッシュをクリア（通常キャッシュとベータ用キャッシュ両方）
            delete_transient( $this->cache_key );
            delete_transient( $this->cache_key . '_beta' );

            // WordPressの更新トランジェントからこのプラグインを削除
            $update_plugins = get_site_transient( 'update_plugins' );
            if ( $update_plugins && isset( $update_plugins->response[ $this->plugin_basename ] ) ) {
                unset( $update_plugins->response[ $this->plugin_basename ] );
                set_site_transient( 'update_plugins', $update_plugins );
            }
        }
    }

    /**
     * 更新をチェック
     *
     * @param object $transient 更新トランジェント
     * @return object 更新されたトランジェント
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        // WordPressが認識している実際のインストール済みバージョンを使用
        $current_version = isset( $transient->checked[ $this->plugin_basename ] )
            ? $transient->checked[ $this->plugin_basename ]
            : $this->current_version;

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $transient;
        }

        $latest_version = ltrim( $release['tag_name'], 'v' );

        // メジャーバージョンチェック（メジャーバージョンが異なる場合は自動更新を提供しない）
        $current_parts = explode( '.', $current_version );
        $latest_parts = explode( '.', $latest_version );
        $current_major = isset( $current_parts[0] ) ? (int) $current_parts[0] : 0;
        $latest_major = isset( $latest_parts[0] ) ? (int) $latest_parts[0] : 0;

        if ( $current_major !== $latest_major ) {
            // メジャーバージョンが異なる場合は自動更新を提供しない
            // （v2.0.0通知機能が別途表示される）
            return $transient;
        }

        if ( version_compare( $current_version, $latest_version, '<' ) ) {
            $download_url = $this->get_download_url( $release );

            if ( $download_url ) {
                $transient->response[ $this->plugin_basename ] = (object) array(
                    'slug'        => $this->plugin_slug,
                    'plugin'      => $this->plugin_basename,
                    'new_version' => $latest_version,
                    'url'         => $release['html_url'],
                    'package'     => $download_url,
                    'icons'       => array(),
                    'banners'     => array(),
                    'tested'      => '',
                    'requires_php' => '7.4',
                );
            }
        } else {
            // 更新不要の場合、responseから削除してno_updateに移動
            if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
                unset( $transient->response[ $this->plugin_basename ] );
            }
            // no_updateに登録（最新版であることを明示）
            if ( ! isset( $transient->no_update[ $this->plugin_basename ] ) ) {
                $transient->no_update[ $this->plugin_basename ] = (object) array(
                    'slug'        => $this->plugin_slug,
                    'plugin'      => $this->plugin_basename,
                    'new_version' => $current_version,
                    'url'         => '',
                    'package'     => '',
                );
            }
        }

        return $transient;
    }

    /**
     * プラグイン情報を取得（詳細ポップアップ用）
     *
     * @param false|object|array $result 結果
     * @param string $action アクション
     * @param object $args 引数
     * @return false|object 結果
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $result;
        }

        $latest_version = ltrim( $release['tag_name'], 'v' );
        $download_url = $this->get_download_url( $release );

        return (object) array(
            'name'              => 'Carry Pod',
            'slug'              => $this->plugin_slug,
            'version'           => $latest_version,
            'author'            => '<a href="https://github.com/villyoshioka">villyoshioka</a>',
            'author_profile'    => 'https://github.com/villyoshioka',
            'homepage'          => 'https://github.com/villyoshioka/CarryPod',
            'short_description' => 'WordPress サイトを静的 HTML に変換するプラグイン',
            'sections'          => array(
                'description'  => $this->get_readme_description(),
                'changelog'    => $this->format_changelog( $release['body'] ),
            ),
            'download_link'     => $download_url,
            'requires'          => '6.0',
            'tested'            => '',
            'requires_php'      => '7.4',
            'last_updated'      => $release['published_at'],
        );
    }

    /**
     * GitHub から最新リリース情報を取得
     *
     * @return array|false リリース情報または失敗時false
     */
    private function get_latest_release() {
        // ベータチャンネルが有効かどうかを確認
        $include_prerelease = $this->is_beta_channel_enabled();

        // キャッシュキーを分ける（ベータとノーマル）
        $cache_key = $include_prerelease ? $this->cache_key . '_beta' : $this->cache_key;

        // キャッシュをチェック
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        // ベータチャンネルの場合は全リリースを取得、通常は最新のみ
        if ( $include_prerelease ) {
            $url = sprintf(
                'https://api.github.com/repos/%s/%s/releases',
                $this->github_owner,
                $this->github_repo
            );
        } else {
            $url = sprintf(
                'https://api.github.com/repos/%s/%s/releases/latest',
                $this->github_owner,
                $this->github_repo
            );
        }

        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // JSONデコードエラーチェック
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return false;
        }

        // ベータチャンネルの場合は配列から最新を選択
        if ( $include_prerelease ) {
            $body = $this->get_latest_from_releases( $body );
        }

        // 必須フィールドの検証
        if ( empty( $body ) || ! is_array( $body ) ) {
            return false;
        }

        $required_fields = array( 'tag_name', 'html_url', 'zipball_url' );
        foreach ( $required_fields as $field ) {
            if ( ! isset( $body[ $field ] ) || ! is_string( $body[ $field ] ) ) {
                return false;
            }
        }

        // tag_name の形式を検証（vX.X.X または X.X.X）
        if ( ! preg_match( '/^v?\d+\.\d+(\.\d+)?(-[a-zA-Z0-9.]+)?$/', $body['tag_name'] ) ) {
            return false;
        }

        // キャッシュに保存
        set_transient( $cache_key, $body, $this->cache_expiry );

        return $body;
    }

    /**
     * v2.0.0リリース通知チェック（v1.4.2専用、v2.0.0で削除予定）
     *
     * v1.x系列のユーザーに対してv2.0.0リリースを通知する。
     * 自動更新は行わず、手動ダウンロードを案内する。
     *
     * @return array|false v2.0.0以降のリリース情報、または見つからない場合はfalse
     */
    public function check_v2_release() {
        // テスト用：強制的にv2.0.0が存在するように見せかける
        // URLに &sge_test_v2=on を追加してテストモード有効化
        // 無効化は &sge_test_v2=off
        if ( get_transient( 'sge_test_v2_mode' ) ) {
            return array(
                'tag_name' => 'v2.0.0',
                'html_url' => 'https://github.com/villyoshioka/CarryPod/releases/tag/v2.0.0',
            );
        }

        // 既存のキャッシュをチェック（通常の更新チェックと同じタイミング）
        $cached = get_transient( 'sge_v2_release_check' );
        if ( $cached !== false ) {
            return $cached;
        }

        // 全リリースを取得
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases',
            $this->github_owner,
            $this->github_repo
        );

        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return false;
        }

        $releases = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $releases ) ) {
            return false;
        }

        // v2.0.0以降のリリースを探す
        foreach ( $releases as $release ) {
            if ( ! isset( $release['tag_name'] ) ) {
                continue;
            }

            $version = ltrim( $release['tag_name'], 'v' );

            // v2.0.0以降かチェック（メジャーバージョンが2以上）
            if ( version_compare( $version, '2.0.0', '>=' ) ) {
                // キャッシュに保存（12時間）
                set_transient( 'sge_v2_release_check', $release, $this->cache_expiry );
                return $release;
            }
        }

        // v2.0.0が見つからない場合はfalseをキャッシュ
        set_transient( 'sge_v2_release_check', false, $this->cache_expiry );
        return false;
    }

    /**
     * ベータチャンネルが有効かどうかを確認
     *
     * @return bool 有効ならtrue
     */
    private function is_beta_channel_enabled() {
        return (bool) get_transient( 'sge_beta_channel' );
    }

    /**
     * リリース一覧から最新のリリースを取得（プレリリース含む）
     *
     * @param array $releases リリース一覧
     * @return array|false 最新のリリース情報
     */
    private function get_latest_from_releases( $releases ) {
        if ( empty( $releases ) || ! is_array( $releases ) ) {
            return false;
        }

        // リリースは公開日順（降順）で返されるので、最初の要素が最新
        // プレリリースも含めて最初のものを返す
        foreach ( $releases as $release ) {
            if ( is_array( $release ) && isset( $release['tag_name'] ) ) {
                return $release;
            }
        }

        return false;
    }

    /**
     * ダウンロードURLを取得
     *
     * @param array $release リリース情報
     * @return string|false ダウンロードURL
     */
    private function get_download_url( $release ) {
        // まずリリースアセットからZIPファイルを探す（正しいフォルダ構造）
        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( isset( $asset['name'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
                    if ( isset( $asset['browser_download_url'] ) ) {
                        // SSRF対策: URLがGitHubのドメインであることを検証
                        if ( $this->is_valid_github_url( $asset['browser_download_url'] ) ) {
                            return $asset['browser_download_url'];
                        }
                    }
                }
            }
        }

        // アセットがない場合はzipball_urlにフォールバック
        if ( ! empty( $release['zipball_url'] ) ) {
            // SSRF対策: URLがGitHubのドメインであることを検証
            if ( ! $this->is_valid_github_url( $release['zipball_url'] ) ) {
                return false;
            }
            return $release['zipball_url'];
        }

        return false;
    }

    /**
     * URLが正当なGitHub URLかどうかを検証
     *
     * @param string $url 検証するURL
     * @return bool 正当なGitHub URLならtrue
     */
    private function is_valid_github_url( $url ) {
        if ( empty( $url ) || ! is_string( $url ) ) {
            return false;
        }

        $parsed = wp_parse_url( $url );

        // スキームがhttpsであることを確認
        if ( ! isset( $parsed['scheme'] ) || $parsed['scheme'] !== 'https' ) {
            return false;
        }

        // ホストがGitHubのドメインであることを確認
        if ( ! isset( $parsed['host'] ) ) {
            return false;
        }

        $allowed_hosts = array(
            'api.github.com',
            'github.com',
            'codeload.github.com',
            'objects.githubusercontent.com', // リリースアセットのダウンロード先
        );

        if ( ! in_array( $parsed['host'], $allowed_hosts, true ) ) {
            return false;
        }

        // パスに期待するリポジトリ情報が含まれているか確認
        if ( ! isset( $parsed['path'] ) ) {
            return false;
        }

        // objects.githubusercontent.com はリダイレクト先なのでパス検証をスキップ
        if ( $parsed['host'] === 'objects.githubusercontent.com' ) {
            return true;
        }

        // リポジトリのowner/repoがパスに含まれていることを確認
        $expected_path_part = '/' . $this->github_owner . '/' . $this->github_repo;
        if ( strpos( $parsed['path'], $expected_path_part ) === false ) {
            return false;
        }

        return true;
    }

    /**
     * ソースディレクトリ名を修正
     *
     * GitHub の zipball は「owner-repo-hash」形式のディレクトリ名になるため、
     * 正しいプラグインディレクトリ名に修正する
     *
     * @param string $source ソースパス
     * @param string $remote_source リモートソース
     * @param WP_Upgrader $upgrader アップグレーダー
     * @param array $hook_extra 追加情報
     * @return string|WP_Error 修正されたソースパス
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
        global $wp_filesystem;

        // このプラグインの更新かどうかを確認
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
            return $source;
        }

        // 既に正しいディレクトリ名の場合はそのまま返す（リリースアセットからのインストール）
        $source_dirname = basename( untrailingslashit( $source ) );
        if ( $source_dirname === $this->plugin_slug ) {
            return $source;
        }

        // GitHubのzipball形式（owner-repo-hash）かどうかを確認
        $github_pattern = '/^' . preg_quote( $this->github_owner, '/' ) . '-' . preg_quote( $this->github_repo, '/' ) . '-[a-f0-9]+$/i';
        if ( ! preg_match( $github_pattern, $source_dirname ) ) {
            // 予期しない形式の場合はそのまま返す
            return $source;
        }

        // パストラバーサル対策: ソースパスの検証
        $real_source = realpath( $source );
        $real_remote = realpath( $remote_source );

        if ( $real_source === false || $real_remote === false ) {
            return new WP_Error( 'invalid_path', '無効なパスが検出されました。' );
        }

        // ソースがリモートソース内にあることを確認
        if ( strpos( $real_source, $real_remote ) !== 0 ) {
            return new WP_Error( 'path_traversal', 'パストラバーサルが検出されました。' );
        }

        // 正しいディレクトリ名
        $correct_dir = trailingslashit( $remote_source ) . $this->plugin_slug;

        // Null バイトチェック
        if ( strpos( $correct_dir, "\0" ) !== false ) {
            return new WP_Error( 'null_byte', '無効な文字が含まれています。' );
        }

        // 既に正しい名前のディレクトリが存在する場合は削除
        if ( $wp_filesystem->exists( $correct_dir ) ) {
            $wp_filesystem->delete( $correct_dir, true );
        }

        // ディレクトリ名を変更
        if ( $wp_filesystem->move( $source, $correct_dir ) ) {
            return trailingslashit( $correct_dir );
        }

        return new WP_Error( 'rename_failed', 'プラグインディレクトリ名の変更に失敗しました。' );
    }

    /**
     * README から説明を取得
     *
     * @return string 説明文
     */
    private function get_readme_description() {
        return 'Carry Pod は WordPress サイトを静的 HTML ファイルに変換するプラグインです。' .
               'GitHub、GitLab、Cloudflare Workers、ローカルディレクトリなど複数の出力先に対応しています。';
    }

    /**
     * 変更履歴をフォーマット
     *
     * @param string $body リリースノート
     * @return string フォーマットされた変更履歴
     */
    private function format_changelog( $body ) {
        if ( empty( $body ) ) {
            return '<p>変更履歴はありません。</p>';
        }

        // Markdown を簡易的に HTML に変換
        $html = esc_html( $body );
        $html = nl2br( $html );

        // リスト項目を変換
        $html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/(<li>.+<\/li>\s*)+/', '<ul>$0</ul>', $html );

        return $html;
    }

    /**
     * キャッシュをクリア
     *
     * @return bool 成功ならtrue、権限がなければfalse
     */
    public function clear_cache() {
        // 認可チェック: 管理者のみ実行可能
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        delete_transient( $this->cache_key );
        return true;
    }
}
