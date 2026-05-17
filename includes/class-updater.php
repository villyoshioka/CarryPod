<?php
/**
 * GitHub Releases APIを使用した自動更新クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_Updater {

    private string $github_owner = 'villyoshioka';
    private string $github_repo = 'CarryPod';
    private string $plugin_basename;
    private string $plugin_slug;
    private string $current_version;
    private string $cache_key = 'cp_github_release_cache';
    private int $cache_expiry = 43200; // 12時間

    public function __construct() {
        $this->plugin_basename = plugin_basename( CP_PLUGIN_DIR . 'carry-pod.php' );
        $this->plugin_slug = dirname( $this->plugin_basename );
        $this->current_version = CP_VERSION;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( $this, 'on_upgrade_complete' ), 10, 2 );
    }

    public function on_upgrade_complete( $upgrader, $options ): void {
        if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
            return;
        }

        $plugins = $options['plugins'] ?? array();
        if ( ! is_array( $plugins ) ) {
            $plugins = array( $plugins );
        }

        if ( in_array( $this->plugin_basename, $plugins, true ) ) {
            delete_transient( $this->cache_key );
            delete_transient( $this->cache_key . '_beta' );

            $update_plugins = get_site_transient( 'update_plugins' );
            if ( $update_plugins && isset( $update_plugins->response[ $this->plugin_basename ] ) ) {
                unset( $update_plugins->response[ $this->plugin_basename ] );
                set_site_transient( 'update_plugins', $update_plugins );
            }
        }
    }

    public function check_for_update( $transient ): object {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $current_version = $transient->checked[ $this->plugin_basename ]
            ?? $this->current_version;

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $transient;
        }

        $latest_version = ltrim( $release['tag_name'], 'v' );

        // メジャーバージョンが異なる場合は自動更新を提供しない
        $current_parts = explode( '.', $current_version );
        $latest_parts = explode( '.', $latest_version );
        $current_major = (int) ( $current_parts[0] ?? 0 );
        $latest_major = (int) ( $latest_parts[0] ?? 0 );

        if ( $current_major !== $latest_major ) {
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
                    'requires_php' => '8.3',
                );
            }
        } else {
            if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
                unset( $transient->response[ $this->plugin_basename ] );
            }
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

    public function plugin_info( $result, $action, $args ): false|object {
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
            'requires'          => '6.8',
            'tested'            => '',
            'requires_php'      => '8.2',
            'last_updated'      => $release['published_at'],
        );
    }

    private function get_latest_release(): array|false {
        $include_prerelease = $this->is_beta_channel_enabled();
        $cache_key = $include_prerelease ? $this->cache_key . '_beta' : $this->cache_key;

        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

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

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return false;
        }

        if ( $include_prerelease ) {
            $body = $this->get_latest_from_releases( $body );
        }

        if ( empty( $body ) || ! is_array( $body ) ) {
            return false;
        }

        $required_fields = array( 'tag_name', 'html_url', 'zipball_url' );
        foreach ( $required_fields as $field ) {
            if ( ! isset( $body[ $field ] ) || ! is_string( $body[ $field ] ) ) {
                return false;
            }
        }

        if ( ! preg_match( '/^v?\d+\.\d+(\.\d+)?(-[a-zA-Z0-9.]+)?$/', $body['tag_name'] ) ) {
            return false;
        }

        set_transient( $cache_key, $body, $this->cache_expiry );

        return $body;
    }

    private function is_beta_channel_enabled(): bool {
        return (bool) get_transient( 'cp_beta_channel' );
    }

    private function get_latest_from_releases( array $releases ): array|false {
        if ( empty( $releases ) || ! is_array( $releases ) ) {
            return false;
        }

        foreach ( $releases as $release ) {
            if ( is_array( $release ) && isset( $release['tag_name'] ) ) {
                return $release;
            }
        }

        return false;
    }

    private function get_download_url( array $release ): string|false {
        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( isset( $asset['name'] ) && $asset['name'] === 'carry-pod.zip' ) {
                    if ( isset( $asset['browser_download_url'] ) ) {
                        if ( $this->is_valid_github_url( $asset['browser_download_url'] ) ) {
                            return $asset['browser_download_url'];
                        }
                    }
                }
            }
        }

        return false;
    }

    /** SSRF対策: GitHubドメインのみ許可 */
    private function is_valid_github_url( string $url ): bool {
        if ( empty( $url ) || ! is_string( $url ) ) {
            return false;
        }

        $parsed = wp_parse_url( $url );

        if ( ! isset( $parsed['scheme'] ) || $parsed['scheme'] !== 'https' ) {
            return false;
        }

        if ( ! isset( $parsed['host'] ) ) {
            return false;
        }

        $allowed_hosts = array(
            'api.github.com',
            'github.com',
            'codeload.github.com',
            'objects.githubusercontent.com',
        );

        if ( ! in_array( $parsed['host'], $allowed_hosts, true ) ) {
            return false;
        }

        if ( ! isset( $parsed['path'] ) ) {
            return false;
        }

        // objects.githubusercontent.com はリダイレクト先なのでパス検証をスキップ
        if ( $parsed['host'] === 'objects.githubusercontent.com' ) {
            return true;
        }

        $expected_path_part = '/' . $this->github_owner . '/' . $this->github_repo;
        if ( ! str_contains( $parsed['path'], $expected_path_part ) ) {
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
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ): string|\WP_Error {
        global $wp_filesystem;

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
            return $source;
        }

        // パストラバーサル対策: ソースパスの検証
        $real_source = realpath( $source );
        $real_remote = realpath( $remote_source );

        if ( $real_source === false || $real_remote === false ) {
            return new WP_Error( 'invalid_path', '無効なパスが検出されました。' );
        }

        // ソースがリモートソース内にあることを確認
        if ( ! str_starts_with( $real_source, $real_remote ) ) {
            return new WP_Error( 'path_traversal', 'パストラバーサルが検出されました。' );
        }

        $correct_dir = trailingslashit( $remote_source ) . $this->plugin_slug;

        // Null バイトチェック
        if ( str_contains( $correct_dir, "\0" ) ) {
            return new WP_Error( 'null_byte', '無効な文字が含まれています。' );
        }

        if ( $wp_filesystem->exists( $correct_dir ) ) {
            $wp_filesystem->delete( $correct_dir, true );
        }

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
    private function get_readme_description(): string {
        return 'Carry Pod は WordPress サイトを静的 HTML ファイルに変換するプラグインです。' .
               'GitHub、GitLab、Cloudflare Workers、ローカルディレクトリなど複数の出力先に対応しています。';
    }

    /**
     * 変更履歴をフォーマット
     *
     * @param string $body リリースノート
     * @return string フォーマットされた変更履歴
     */
    private function format_changelog( ?string $body ): string {
        if ( empty( $body ) ) {
            return '<p>変更履歴はありません。</p>';
        }

        $html = esc_html( $body );
        $html = nl2br( $html );

        $html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/(<li>.+<\/li>\s*)+/', '<ul>$0</ul>', $html );

        return $html;
    }

    /**
     * キャッシュをクリア
     *
     * @return bool 成功ならtrue、権限がなければfalse
     */
    public function clear_cache(): bool {
        // 認可チェック: 管理者のみ実行可能
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        delete_transient( $this->cache_key );
        return true;
    }
}
