<?php
/**
 * Settings management class
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Stub for optional wp-config.php constant (static analysis only).
if ( false ) {
    define( 'CP_ENCRYPTION_KEY', '' );
}

class CP_Settings {

    private static ?self $instance = null;
    private ?string $encryption_key = null;

    /** Store only the SHA-256 hash (the plaintext password is not included in the source code) */
    private string $beta_password_hash = 'a6301e803f92a28d1342b9248ecaf0fa01a6c59f8a1e8fb1fdcebb21a97de804';

    public static function get_instance(): static {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function is_beta_mode_enabled(): bool {
        return (bool) get_transient( 'cp_beta_channel' );
    }

    /**
     * Determine whether this is a WordPress Playground (php-wasm) environment
     *
     * WordPress Studio uses Playground internally, and in this environment
     * exec / proc_open fail without raising an error, so CLI-dependent features
     * (local Git, Wrangler CLI) must be disabled.
     * SERVER_SOFTWARE is set to 'PHP.wasm'.
     */
    public static function is_playground(): bool {
        $server_software = $_SERVER['SERVER_SOFTWARE'] ?? '';
        return false !== stripos( $server_software, 'php.wasm' );
    }

    /**
     * Mati integration: headers for pages and for media (X-Robots-Tag / TDMRep)
     *
     * On Mati 2.3.1 or later, use the result assembled by Mati.
     * On earlier Mati versions, keep the legacy behavior of applying X-Robots-Tag site-wide.
     *
     * @return array{page: array<string, string>, media: array<string, string>}
     */
    public function get_mati_header_sets(): array {
        $sets = array( 'page' => array(), 'media' => array() );

        if ( ! defined( 'MATI_VERSION' ) || ! class_exists( 'Mati_Settings' ) || ! method_exists( 'Mati_Settings', 'get_instance' ) ) {
            return $sets;
        }

        try {
            $mati = Mati_Settings::get_instance();

            if ( method_exists( $mati, 'get_header_sets' ) ) {
                $sets = $mati->get_header_sets();
            } elseif ( method_exists( $mati, 'get_settings' ) ) {
                $mati_settings = $mati->get_settings();
                $robots_tags   = array();

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
                    $sets['page']['X-Robots-Tag']  = implode( ', ', $robots_tags );
                    $sets['media']['X-Robots-Tag'] = $sets['page']['X-Robots-Tag'];
                }
            }
        } catch ( Throwable ) {
            return array( 'page' => array(), 'media' => array() );
        }

        // Keep only safe values that fit on one line, since they are output as-is to _headers and notices
        foreach ( array( 'page', 'media' ) as $key ) {
            $sets[ $key ] = array_filter(
                (array) ( $sets[ $key ] ?? array() ),
                fn( $value, $name ) => is_string( $name ) && is_string( $value )
                    && 1 === preg_match( '/^[A-Za-z0-9-]+$/', $name )
                    && 1 === preg_match( '/^[\x20-\x7E]+$/', $value ),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return $sets;
    }

    /**
     * Path of the uploads folder on the static site (e.g. /wp-content/uploads/)
     */
    public function get_static_uploads_path(): string {
        $settings        = $this->get_settings();
        $content_dirname = ! empty( $settings['custom_wp_content'] ) ? $settings['custom_wp_content'] : 'wp-content';

        return '/' . trim( $content_dirname, '/' ) . '/uploads/';
    }

    /**
     * Enable beta mode (with password verification)
     * Timing-safe comparison + rate limiting (10-minute lock after 5 failures)
     */
    public function enable_beta_mode( string $password ): bool|\WP_Error {
        $user_id = get_current_user_id();
        $attempts_key = 'cp_beta_attempts_' . $user_id;

        $attempts = get_transient( $attempts_key );
        if ( $attempts >= 5 ) {
            return new WP_Error( 'rate_limit', 'ログイン試行回数が超過しました。10分後に再試行してください。' );
        }

        if ( hash_equals( $this->beta_password_hash, hash( 'sha256', $password ) ) ) {
            delete_transient( $attempts_key );
            set_transient( 'cp_beta_channel', true, DAY_IN_SECONDS );
            return true;
        }

        $new_attempts = $attempts ? $attempts + 1 : 1;
        set_transient( $attempts_key, $new_attempts, 10 * MINUTE_IN_SECONDS );

        return false;
    }

    public function disable_beta_mode(): void {
        delete_transient( 'cp_beta_channel' );
        delete_transient( 'cp_github_release_cache_beta' );
    }

    /**
     * Encryption key for stored tokens
     *
     * Derived from CP_ENCRYPTION_KEY or the wp-config.php salts so that a database dump alone
     * cannot decrypt the tokens. Resolved lazily because wp_salt() is pluggable and not
     * available while plugins are still loading.
     */
    private function encryption_key(): string {
        if ( $this->encryption_key !== null ) {
            return $this->encryption_key;
        }

        $new_key = defined( 'CP_ENCRYPTION_KEY' ) && ! empty( CP_ENCRYPTION_KEY )
            ? hash( 'sha256', CP_ENCRYPTION_KEY )
            : hash( 'sha256', wp_salt( 'secure_auth' ) . wp_salt( 'auth' ) );

        $legacy_key = get_option( 'cp_encryption_key' );
        if ( ! empty( $legacy_key ) ) {
            $this->reencrypt_stored_tokens( $legacy_key, $new_key );
            delete_option( 'cp_encryption_key' );
        }

        $this->encryption_key = $new_key;
        return $new_key;
    }

    /**
     * Re-encrypt stored secrets (tokens and the Basic auth password) that were encrypted
     * with the key formerly kept in the database
     */
    private function reencrypt_stored_tokens( string $legacy_key, string $new_key ): void {
        // Returns the value re-encrypted with the new key, or null when it needs no change or cannot be read
        $reencrypt = function ( string $value ) use ( $legacy_key, $new_key ): ?string {
            // Already readable with the new key (e.g. CP_ENCRYPTION_KEY was set later): nothing to do.
            // Only trusted for v2 (GCM is authenticated); a legacy CBC value can "decrypt" to garbage
            // under the wrong key, and CBC values were only ever written with the legacy key.
            $this->encryption_key = $new_key;
            if ( str_starts_with( $value, 'v2:' ) && ! is_wp_error( $this->decrypt_token( $value ) ) ) {
                return null;
            }

            $this->encryption_key = $legacy_key;
            $plain = $this->decrypt_token( $value );
            $this->encryption_key = $new_key;
            if ( is_wp_error( $plain ) ) {
                return null;
            }

            $encrypted = $this->encrypt_token( $plain );
            return $encrypted === false ? null : $encrypted;
        };

        $raw = get_option( 'cp_settings', array() );
        if ( is_array( $raw ) ) {
            $changed = false;
            foreach ( array( 'github_token', 'cloudflare_api_token', 'gitlab_token', 'netlify_api_token' ) as $field ) {
                if ( empty( $raw[ $field ] ) ) {
                    continue;
                }
                $encrypted = $reencrypt( $raw[ $field ] );
                if ( $encrypted !== null ) {
                    $raw[ $field ] = $encrypted;
                    $changed = true;
                }
            }
            if ( $changed ) {
                update_option( 'cp_settings', $raw );
            }
        }

        $basic_auth_pass = get_option( 'cp_basic_auth_pass' );
        if ( is_string( $basic_auth_pass ) && $basic_auth_pass !== '' ) {
            $encrypted = $reencrypt( $basic_auth_pass );
            if ( $encrypted !== null ) {
                update_option( 'cp_basic_auth_pass', $encrypted, false );
            }
        }
    }

    public function is_encryption_key_in_config(): bool {
        return defined( 'CP_ENCRYPTION_KEY' ) && ! empty( CP_ENCRYPTION_KEY );
    }

    public function get_settings(): array {
        $this->encryption_key(); // migrate legacy-key tokens before reading them
        $settings = get_option( 'cp_settings', array() );

        $defaults = array(
            'version' => CP_VERSION,
            'cloudflare_enabled' => false,
            'cloudflare_use_wrangler' => false,
            'cloudflare_api_token' => '',
            'cloudflare_account_id' => '',
            'cloudflare_script_name' => '',
            'netlify_enabled' => false,
            'netlify_api_token' => '',
            'netlify_site_id' => '',
            'github_enabled' => false,
            'github_token' => '',
            'github_repo' => '',
            'github_branch_mode' => 'existing',
            'github_existing_branch' => '',
            'github_new_branch' => '',
            'github_base_branch' => '',
            'github_method' => 'api',
            'git_work_dir' => '',
            'gitlab_enabled' => false,
            'gitlab_token' => '',
            'gitlab_project' => '',
            'gitlab_branch_mode' => 'existing',
            'gitlab_existing_branch' => '',
            'gitlab_new_branch' => '',
            'gitlab_base_branch' => '',
            'gitlab_api_url' => 'https://gitlab.com/api/v4',
            'git_local_enabled' => false,
            'git_local_work_dir' => '',
            'git_local_branch' => 'main',
            'git_local_remote_url' => '',
            'local_enabled' => false,
            'local_output_path' => '',
            'zip_enabled' => false,
            'zip_mode' => 'download',
            'zip_output_path' => '',
            'url_mode' => 'relative',
            'base_url' => '',
            'custom_wp_includes' => '',
            'custom_wp_content' => '',
            'include_paths' => '',
            'exclude_patterns' => '',
            'enable_tag_archive' => false,
            'enable_date_archive' => false,
            'enable_author_archive' => false,
            'enable_post_format_archive' => false,
            'enable_sitemap' => true,
            'enable_robots_txt' => true,
            'enable_llms_txt' => true,
            'enable_rss' => true,
            'generate_mati_headers' => true,
            'timeout' => 300,
            'minify_html' => false,
            'minify_css' => false,
            'auto_generate' => false,
            'commit_message' => '',
        );

        $settings = array_merge( $defaults, $settings );

        if ( ! empty( $settings['github_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['github_token'] );
            $settings['github_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
        }

        if ( ! empty( $settings['cloudflare_api_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['cloudflare_api_token'] );
            $settings['cloudflare_api_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
        }

        if ( ! empty( $settings['gitlab_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['gitlab_token'] );
            $settings['gitlab_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
        }

        if ( ! empty( $settings['netlify_api_token'] ) ) {
            $decrypted = $this->decrypt_token( $settings['netlify_api_token'] );
            $settings['netlify_api_token'] = is_wp_error( $decrypted ) ? '' : $decrypted;
        }

        return $settings;
    }

    public function save_settings( array $settings ): bool|\WP_Error {
        $this->encryption_key(); // migrate legacy-key tokens before reading them
        $current_raw = get_option( 'cp_settings', array() );

        // Force-disable CLI-dependent features on Playground (WordPress Studio) since the CLI cannot be run there
        if ( self::is_playground() ) {
            $settings['git_local_enabled'] = false;
            $settings['cloudflare_use_wrangler'] = false;
        }

        // Keep the existing value when the token is empty
        if ( empty( $settings['github_token'] ) && ! empty( $current_raw['github_token'] ) ) {
            $settings['github_token'] = $current_raw['github_token'];
            $decrypted = $this->decrypt_token( $current_raw['github_token'] );
            $settings['_has_existing_token'] = ! is_wp_error( $decrypted );
        }

        if ( empty( $settings['cloudflare_api_token'] ) && ! empty( $current_raw['cloudflare_api_token'] ) ) {
            $settings['cloudflare_api_token'] = $current_raw['cloudflare_api_token'];
            $decrypted = $this->decrypt_token( $current_raw['cloudflare_api_token'] );
            $settings['_has_existing_cf_token'] = ! is_wp_error( $decrypted );
        }

        if ( empty( $settings['gitlab_token'] ) && ! empty( $current_raw['gitlab_token'] ) ) {
            $settings['gitlab_token'] = $current_raw['gitlab_token'];
            $decrypted = $this->decrypt_token( $current_raw['gitlab_token'] );
            $settings['_has_existing_gl_token'] = ! is_wp_error( $decrypted );
        }

        if ( empty( $settings['netlify_api_token'] ) && ! empty( $current_raw['netlify_api_token'] ) ) {
            $settings['netlify_api_token'] = $current_raw['netlify_api_token'];
            $decrypted = $this->decrypt_token( $current_raw['netlify_api_token'] );
            $settings['_has_existing_netlify_token'] = ! is_wp_error( $decrypted );
        }

        $validation = $this->validate_settings( $settings );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        unset( $settings['_has_existing_token'] );
        unset( $settings['_has_existing_cf_token'] );
        unset( $settings['_has_existing_gl_token'] );
        unset( $settings['_has_existing_netlify_token'] );

        if ( ! empty( $settings['github_token'] ) && ! str_starts_with( $settings['github_token'], 'v2:' ) && ! str_contains( $settings['github_token'], '::' ) ) {
            $settings['github_token'] = $this->encrypt_token( $settings['github_token'] );
        }

        if ( ! empty( $settings['cloudflare_api_token'] ) && ! str_starts_with( $settings['cloudflare_api_token'], 'v2:' ) && ! str_contains( $settings['cloudflare_api_token'], '::' ) ) {
            $settings['cloudflare_api_token'] = $this->encrypt_token( $settings['cloudflare_api_token'] );
        }

        if ( ! empty( $settings['gitlab_token'] ) && ! str_starts_with( $settings['gitlab_token'], 'v2:' ) && ! str_contains( $settings['gitlab_token'], '::' ) ) {
            $settings['gitlab_token'] = $this->encrypt_token( $settings['gitlab_token'] );
        }

        if ( ! empty( $settings['netlify_api_token'] ) && ! str_starts_with( $settings['netlify_api_token'], 'v2:' ) && ! str_contains( $settings['netlify_api_token'], '::' ) ) {
            $settings['netlify_api_token'] = $this->encrypt_token( $settings['netlify_api_token'] );
        }

        $settings['version'] = CP_VERSION;

        update_option( 'cp_settings', $settings );

        return true;
    }

    public function validate_settings( array $settings ): true|\WP_Error {
        if ( empty( $settings['github_enabled'] ) && empty( $settings['git_local_enabled'] ) && empty( $settings['local_enabled'] ) && empty( $settings['zip_enabled'] ) && empty( $settings['cloudflare_enabled'] ) && empty( $settings['gitlab_enabled'] ) && empty( $settings['netlify_enabled'] ) ) {
            return new WP_Error( 'no_output', '出力先を最低1つ選択してください。' );
        }

        $errors = array();

        if ( ! empty( $settings['github_enabled'] ) ) {
            $has_token = ! empty( $settings['github_token'] ) || ! empty( $settings['_has_existing_token'] );
            if ( ! $has_token ) {
                $errors[] = 'GitHubアクセストークンを入力してください。';
            } elseif ( ! empty( $settings['github_token'] ) && empty( $settings['_has_existing_token'] ) ) {
                if ( ! $this->is_valid_github_token_format( $settings['github_token'] ) ) {
                    $errors[] = 'GitHubトークンの形式が正しくありません。';
                }
            }

            if ( empty( $settings['github_repo'] ) ) {
                $errors[] = 'リポジトリ名を入力してください。';
            } elseif ( ! preg_match( '/^[a-zA-Z0-9_-]{1,100}\/[a-zA-Z0-9_.-]{1,100}$/', $settings['github_repo'] ) ) {
                $errors[] = 'リポジトリ名の形式が正しくありません（例：owner/repo）';
            } elseif ( strlen( $settings['github_repo'] ) > 200 ) {
                $errors[] = 'リポジトリ名が長すぎます。';
            }

            if ( $settings['github_branch_mode'] === 'existing' ) {
                if ( empty( $settings['github_existing_branch'] ) ) {
                    $errors[] = '既存ブランチ名を入力してください。';
                } elseif ( ! preg_match( '/^[a-zA-Z0-9\/_-]+$/', $settings['github_existing_branch'] ) ) {
                    $errors[] = 'ブランチ名に使用できない文字が含まれています。';
                }
            } elseif ( $settings['github_branch_mode'] === 'new' ) {
                if ( empty( $settings['github_new_branch'] ) ) {
                    $errors[] = '新規ブランチ名を入力してください。';
                } elseif ( ! preg_match( '/^[a-zA-Z0-9\/_-]+$/', $settings['github_new_branch'] ) ) {
                    $errors[] = '新規ブランチ名に使用できない文字が含まれています。';
                }
            }
        }

        if ( ! empty( $settings['git_local_enabled'] ) ) {
            if ( empty( $settings['git_local_work_dir'] ) ) {
                $errors[] = 'Git作業ディレクトリパスを入力してください。';
            } elseif ( ! $this->is_absolute_path( $settings['git_local_work_dir'] ) ) {
                $errors[] = 'Git作業ディレクトリは絶対パスで指定してください。';
            } elseif ( str_contains( $settings['git_local_work_dir'], '..' ) ) {
                $errors[] = 'Git作業ディレクトリのパスに".."を含めることはできません。';
            } elseif ( $this->overlaps_wordpress_install( $settings['git_local_work_dir'] ) ) {
                $errors[] = 'Git作業ディレクトリにWordPressのフォルダ（またはその中・上位のフォルダ）は指定できません。';
            } else {
                $validated_path = $this->validate_safe_path( $settings['git_local_work_dir'] );
                if ( is_wp_error( $validated_path ) ) {
                    $errors[] = $validated_path->get_error_message();
                } else {
                    $real_path = realpath( $settings['git_local_work_dir'] );
                    if ( $real_path === false ) {
                        $errors[] = 'Git作業ディレクトリが存在しないか、アクセスできません。';
                    } else {
                        $settings['git_local_work_dir'] = $real_path;
                    }
                }
            }

            if ( empty( $settings['git_local_branch'] ) ) {
                $errors[] = 'ローカルGitのブランチ名を入力してください。';
            } elseif ( ! preg_match( '/^[a-zA-Z0-9\/_-]+$/', $settings['git_local_branch'] ) ) {
                $errors[] = 'ローカルGitのブランチ名に使用できない文字が含まれています。';
            }

            if ( ! empty( $settings['git_local_remote_url'] ) ) {
                if ( ! preg_match( '#^(https?://|git@|ssh://)\S+$#', $settings['git_local_remote_url'] ) ) {
                    $errors[] = 'リモートURLはhttps://、git@、またはssh://で始まる必要があります。';
                }
            }
        }

        if ( ! empty( $settings['local_enabled'] ) ) {
            if ( empty( $settings['local_output_path'] ) ) {
                $errors[] = 'ローカル出力先パスを入力してください。';
            } elseif ( ! $this->is_absolute_path( $settings['local_output_path'] ) ) {
                $errors[] = 'ローカル出力先パスは絶対パスで指定してください。';
            } elseif ( str_contains( $settings['local_output_path'], '..' ) ) {
                $errors[] = 'ローカル出力先パスに".."を含めることはできません。';
            } elseif ( $this->is_dangerous_path( $settings['local_output_path'] ) ) {
                $errors[] = 'ローカル出力先に指定されたパスは使用できません。';
            } elseif ( $this->overlaps_wordpress_install( $settings['local_output_path'] ) ) {
                $errors[] = 'ローカル出力先にWordPressのフォルダ（またはその中・上位のフォルダ）は指定できません。';
            } else {
                $validated_path = $this->validate_safe_path( $settings['local_output_path'] );
                if ( is_wp_error( $validated_path ) ) {
                    $errors[] = $validated_path->get_error_message();
                }
            }
        }

        if ( ! empty( $settings['zip_enabled'] ) && ( $settings['zip_mode'] ?? 'download' ) === 'local' ) {
            if ( empty( $settings['zip_output_path'] ) ) {
                $errors[] = 'ZIP出力先パスを入力してください。';
            } elseif ( ! $this->is_absolute_path( $settings['zip_output_path'] ) ) {
                $errors[] = 'ZIP出力先パスは絶対パスで指定してください。';
            } elseif ( str_contains( $settings['zip_output_path'], '..' ) ) {
                $errors[] = 'ZIP出力先パスに".."を含めることはできません。';
            } elseif ( $this->is_dangerous_path( $settings['zip_output_path'] ) ) {
                $errors[] = 'ZIP出力先に指定されたパスは使用できません。';
            } else {
                $validated_path = $this->validate_safe_path( $settings['zip_output_path'] );
                if ( is_wp_error( $validated_path ) ) {
                    $errors[] = $validated_path->get_error_message();
                }
            }
        }

        if ( empty( $settings['base_url'] ) ) {
            $errors[] = 'ベースURLを入力してください。';
        }

        if ( ! empty( $settings['cloudflare_enabled'] ) ) {
            $has_cf_token = ! empty( $settings['cloudflare_api_token'] ) || ! empty( $settings['_has_existing_cf_token'] );
            if ( ! $has_cf_token ) {
                $errors[] = 'Cloudflare APIトークンを入力してください。';
            }

            if ( empty( $settings['cloudflare_account_id'] ) ) {
                $errors[] = 'Cloudflare Account IDを入力してください。';
            } elseif ( ! preg_match( '/^[a-f0-9]{32}$/', $settings['cloudflare_account_id'] ) ) {
                $errors[] = 'Cloudflare Account IDの形式が正しくありません（32文字の16進数）';
            }

            if ( empty( $settings['cloudflare_script_name'] ) ) {
                $errors[] = 'Worker名を入力してください。';
            } elseif ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,62}$/', $settings['cloudflare_script_name'] ) ) {
                $errors[] = 'Worker名は英小文字・数字で始まり、英小文字・数字・ハイフン・アンダースコアのみ使用できます（最大63文字）';
            }
        }

        if ( ! empty( $settings['netlify_enabled'] ) ) {
            $has_netlify_token = ! empty( $settings['netlify_api_token'] )
                              || ! empty( $settings['_has_existing_netlify_token'] );
            if ( ! $has_netlify_token ) {
                $errors[] = 'Netlify APIトークンを入力してください。';
            }

            if ( empty( $settings['netlify_site_id'] ) ) {
                $errors[] = 'Netlify Site IDを入力してください。';
            } elseif ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
                               $settings['netlify_site_id'] ) ) {
                $errors[] = 'Netlify Site IDの形式が正しくありません（UUID形式: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx）';
            }
        }

        if ( ! empty( $settings['gitlab_enabled'] ) ) {
            $has_gl_token = ! empty( $settings['gitlab_token'] ) || ! empty( $settings['_has_existing_gl_token'] );
            if ( ! $has_gl_token ) {
                $errors[] = 'GitLabアクセストークンを入力してください。';
            } elseif ( ! empty( $settings['gitlab_token'] ) && empty( $settings['_has_existing_gl_token'] ) ) {
                if ( ! $this->is_valid_gitlab_token_format( $settings['gitlab_token'] ) ) {
                    $errors[] = 'GitLabトークンの形式が正しくありません。';
                }
            }

            if ( empty( $settings['gitlab_project'] ) ) {
                $errors[] = 'GitLabプロジェクトパスを入力してください（例: username/project）';
            } elseif ( ! preg_match( '/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/', $settings['gitlab_project'] ) ) {
                $errors[] = 'GitLabプロジェクトパスの形式が正しくありません（例: username/project）';
            }

            if ( $settings['gitlab_branch_mode'] === 'existing' ) {
                if ( empty( $settings['gitlab_existing_branch'] ) ) {
                    $errors[] = 'GitLabブランチ名を入力してください。';
                }
            } elseif ( $settings['gitlab_branch_mode'] === 'new' ) {
                if ( empty( $settings['gitlab_new_branch'] ) ) {
                    $errors[] = 'GitLab新規ブランチ名を入力してください。';
                }
            }

            if ( ! empty( $settings['gitlab_api_url'] ) ) {
                if ( ! filter_var( $settings['gitlab_api_url'], FILTER_VALIDATE_URL ) ) {
                    $errors[] = 'GitLab API URLの形式が正しくありません。';
                }
            }
        }

        $timeout = intval( $settings['timeout'] );
        if ( $timeout < 60 || $timeout > 18000 ) {
            $errors[] = 'タイムアウト時間は60〜18000秒の範囲で入力してください。';
        }

        if ( ! empty( $settings['include_paths'] ) ) {
            $paths = explode( "\n", $settings['include_paths'] );
            $include_error_added = false;
            foreach ( $paths as $path ) {
                $path = trim( $path );
                if ( empty( $path ) || $include_error_added ) {
                    continue;
                }
                if ( str_contains( $path, '..' ) ) {
                    $errors[] = 'インクルードパスに".."を含めることはできません。';
                    $include_error_added = true;
                } elseif ( preg_match( '/[<>"|?*]/', $path ) ) {
                    $errors[] = 'インクルードパスに使用できない文字が含まれています。';
                    $include_error_added = true;
                }
            }
        }

        if ( ! empty( $settings['exclude_patterns'] ) ) {
            $patterns = explode( "\n", $settings['exclude_patterns'] );
            $exclude_error_added = false;
            foreach ( $patterns as $pattern ) {
                $pattern = trim( $pattern );
                if ( empty( $pattern ) || $exclude_error_added ) {
                    continue;
                }
                if ( str_contains( $pattern, '..' ) ) {
                    $errors[] = '除外パターンに".."を含めることはできません。';
                    $exclude_error_added = true;
                } elseif ( ! preg_match( '/^[a-zA-Z0-9_\-.*\/]+$/', $pattern ) ) {
                    $errors[] = '除外パターンに使用できない文字が含まれています。';
                    $exclude_error_added = true;
                } elseif ( substr_count( $pattern, '*' ) > 3 ) {
                    $errors[] = '除外パターンのワイルドカード(*)は3つまでです。';
                    $exclude_error_added = true;
                } elseif ( substr_count( $pattern, '/' ) > 10 ) {
                    $errors[] = '除外パターンのパス階層が深すぎます（最大10階層）。';
                    $exclude_error_added = true;
                } elseif ( strlen( $pattern ) > 200 ) {
                    $errors[] = '除外パターンが長すぎます（最大200文字）。';
                    $exclude_error_added = true;
                }
            }
        }

        if ( ! empty( $errors ) ) {
            $wp_error = new WP_Error( 'validation_errors', $errors[0] );
            for ( $i = 1, $count = count( $errors ); $i < $count; $i++ ) {
                $wp_error->add( 'validation_errors', $errors[ $i ] );
            }
            return $wp_error;
        }

        return true;
    }

    /** AES-256-GCM encryption. Format: v2:base64(iv + tag + encrypted) */
    private function encrypt_token( string $token ): string|false {
        $cipher = 'AES-256-GCM';
        $iv_length = openssl_cipher_iv_length( $cipher );
        $iv = random_bytes( $iv_length );
        $tag = '';

        $encrypted = openssl_encrypt(
            $token,
            $cipher,
            $this->encryption_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ( $encrypted === false ) {
            return false;
        }

        return 'v2:' . base64_encode( $iv . $tag . $encrypted );
    }

    public function encrypt_basic_auth( string $password ): string|false {
        return $this->encrypt_token( $password );
    }

    /**
     * Basic auth credentials used when crawling the site
     *
     * @return array{0: string, 1: string}|null [user, password], or null when unset or undecryptable
     */
    public function get_basic_auth_credentials(): ?array {
        $user = get_option( 'cp_basic_auth_user' );
        if ( ! $user ) {
            return null;
        }

        $this->encryption_key(); // migrate legacy-key secrets before reading them
        $pass = $this->decrypt_token( (string) get_option( 'cp_basic_auth_pass' ) );

        return is_wp_error( $pass ) ? null : array( (string) $user, $pass );
    }

    private function decrypt_token( string $encrypted_token ): string|\WP_Error {
        if ( str_starts_with( $encrypted_token, 'v2:' ) ) {
            return $this->decrypt_token_v2( substr( $encrypted_token, 3 ) );
        }

        // Legacy format (AES-256-CBC) - backward compatibility
        return $this->decrypt_token_legacy( $encrypted_token );
    }

    private function decrypt_token_v2( string $encrypted_token ): string|\WP_Error {
        $cipher = 'AES-256-GCM';
        $decoded = base64_decode( $encrypted_token, true );

        if ( $decoded === false ) {
            return new WP_Error( 'decrypt_failed', 'トークンの復号化に失敗しました。再設定が必要です。' );
        }

        $iv_length = openssl_cipher_iv_length( $cipher );
        $tag_length = 16;
        $min_length = $iv_length + $tag_length;

        if ( strlen( $decoded ) < $min_length ) {
            return new WP_Error( 'decrypt_failed', 'トークンの形式が不正です。再設定が必要です。' );
        }

        $iv = substr( $decoded, 0, $iv_length );
        $tag = substr( $decoded, $iv_length, $tag_length );
        $encrypted_data = substr( $decoded, $iv_length + $tag_length );

        $decrypted = openssl_decrypt(
            $encrypted_data,
            $cipher,
            $this->encryption_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ( $decrypted === false ) {
            return new WP_Error( 'decrypt_failed', 'トークンの復号化に失敗しました（認証エラー）。再設定が必要です。' );
        }

        return $decrypted;
    }

    private function decrypt_token_legacy( string $encrypted_token ): string|\WP_Error {
        $cipher = 'AES-256-CBC';
        $decoded = base64_decode( $encrypted_token );
        $parts = explode( '::', $decoded, 2 );

        if ( count( $parts ) !== 2 ) {
            return new WP_Error( 'decrypt_failed', 'トークンの復号化に失敗しました。再設定が必要です。' );
        }

        list( $encrypted_data, $iv ) = $parts;
        $decrypted = openssl_decrypt( $encrypted_data, $cipher, $this->encryption_key(), 0, $iv );

        if ( $decrypted === false ) {
            return new WP_Error( 'decrypt_failed', 'トークンの復号化に失敗しました。再設定が必要です。' );
        }

        return $decrypted;
    }

    private function is_absolute_path( string $path ): bool {
        if ( preg_match( '/^[a-zA-Z]:[\/\\\\]/', $path ) ) {
            return true;
        }
        if ( str_starts_with( $path, '/' ) ) {
            return true;
        }
        return false;
    }

    private function is_dangerous_path( string $path ): bool {
        $dangerous_paths = array(
            '/etc',
            '/System',
            '/bin',
            '/sbin',
            '/usr/bin',
            '/usr/sbin',
            'C:\\Windows',
            'C:/Windows',
            'C:\\System32',
            'C:/System32',
        );

        foreach ( $dangerous_paths as $dangerous ) {
            if ( stripos( $path, $dangerous ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the path is the WordPress install, inside it, or one of its parent folders
     *
     * Outputs that empty their target folder before writing must never point here.
     */
    private function overlaps_wordpress_install( string $path ): bool {
        $normalize = function ( string $p ): string {
            // Resolve the deepest existing ancestor so symlinks are followed even for folders not created yet
            $missing = '';
            while ( realpath( $p ) === false && dirname( $p ) !== $p ) {
                $missing = '/' . basename( $p ) . $missing;
                $p       = dirname( $p );
            }
            $p = untrailingslashit( wp_normalize_path( ( realpath( $p ) ?: $p ) . $missing ) );
            return in_array( PHP_OS_FAMILY, array( 'Darwin', 'Windows' ), true ) ? strtolower( $p ) : $p;
        };
        $wp     = $normalize( ABSPATH );
        $target = $normalize( $path );

        return $target === $wp || str_starts_with( $target, $wp . '/' ) || str_starts_with( $wp, $target . '/' );
    }

    private function validate_safe_path( string $path ): string|\WP_Error {
        $parts = explode( DIRECTORY_SEPARATOR, $path );
        $test_path = '';
        $real_base = '';

        for ( $i = 0; $i < count( $parts ); $i++ ) {
            $test_path .= $parts[ $i ] . DIRECTORY_SEPARATOR;
            if ( is_dir( $test_path ) ) {
                $real = realpath( $test_path );
                if ( $real !== false ) {
                    $real_base = $real;
                }
            }
        }

        if ( ! empty( $real_base ) ) {
            $normalized_input = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $path );
            $normalized_real = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $real_base );

            if ( ! str_starts_with( $normalized_input, $normalized_real ) ) {
                return new WP_Error( 'path_mismatch', 'パスにシンボリックリンクが含まれている可能性があります。' );
            }
        }

        return $path;
    }

    public function reset_settings(): void {
        $default_settings = array(
            'version' => CP_VERSION,
            'cloudflare_enabled' => false,
            'cloudflare_use_wrangler' => false,
            'cloudflare_api_token' => '',
            'cloudflare_account_id' => '',
            'cloudflare_script_name' => '',
            'netlify_enabled' => false,
            'netlify_api_token' => '',
            'netlify_site_id' => '',
            'github_enabled' => false,
            'github_token' => '',
            'github_repo' => '',
            'github_branch_mode' => 'existing',
            'github_existing_branch' => '',
            'github_new_branch' => '',
            'github_base_branch' => '',
            'github_method' => 'api',
            'git_work_dir' => '',
            'gitlab_enabled' => false,
            'gitlab_token' => '',
            'gitlab_project' => '',
            'gitlab_branch_mode' => 'existing',
            'gitlab_existing_branch' => '',
            'gitlab_new_branch' => '',
            'gitlab_base_branch' => '',
            'gitlab_api_url' => 'https://gitlab.com/api/v4',
            'git_local_enabled' => false,
            'git_local_work_dir' => '',
            'git_local_branch' => 'main',
            'git_local_remote_url' => '',
            'local_enabled' => false,
            'local_output_path' => '',
            'zip_enabled' => false,
            'zip_mode' => 'download',
            'zip_output_path' => '',
            'url_mode' => 'relative',
            'base_url' => '',
            'custom_wp_includes' => '',
            'custom_wp_content' => '',
            'include_paths' => '',
            'exclude_patterns' => '',
            'enable_tag_archive' => false,
            'enable_date_archive' => false,
            'enable_author_archive' => false,
            'enable_post_format_archive' => false,
            'enable_sitemap' => true,
            'enable_robots_txt' => true,
            'enable_llms_txt' => true,
            'enable_rss' => true,
            'generate_mati_headers' => true,
            'timeout' => 300,
            'minify_html' => false,
            'minify_css' => false,
            'auto_generate' => false,
            'commit_message' => '',
        );

        update_option( 'cp_settings', $default_settings );

        CP_Cache::get_instance()->clear_all();
    }

    public function export_settings(): string {
        $settings = $this->get_settings();

        $ordered_keys = array(
            'version',
            'cloudflare_enabled', 'cloudflare_use_wrangler', 'cloudflare_account_id', 'cloudflare_script_name',
            'netlify_enabled', 'netlify_site_id',
            'github_enabled', 'github_repo',
            'github_branch_mode', 'github_existing_branch', 'github_new_branch', 'github_base_branch',
            'github_method',
            'gitlab_enabled', 'gitlab_project',
            'gitlab_branch_mode', 'gitlab_existing_branch', 'gitlab_new_branch', 'gitlab_base_branch',
            'gitlab_api_url',
            'git_local_enabled', 'git_local_branch', 'git_local_remote_url',
            'local_enabled',
            'zip_enabled', 'zip_mode',
            'url_mode', 'base_url', 'custom_wp_includes', 'custom_wp_content',
            'exclude_patterns',
            'enable_tag_archive', 'enable_date_archive', 'enable_author_archive', 'enable_post_format_archive',
            'enable_sitemap', 'enable_robots_txt', 'enable_llms_txt',
            'generate_mati_headers', 'enable_rss',
            'timeout', 'minify_html', 'minify_css', 'auto_generate',
            'commit_message',
        );

        $ordered = array();
        foreach ( $ordered_keys as $key ) {
            if ( array_key_exists( $key, $settings ) ) {
                $ordered[ $key ] = $settings[ $key ];
            }
        }

        unset( $ordered['github_token'] );
        unset( $ordered['cloudflare_api_token'] );
        unset( $ordered['gitlab_token'] );
        unset( $ordered['netlify_api_token'] );

        return wp_json_encode( $ordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    public function import_settings( string $json ): bool|\WP_Error {
        if ( strlen( $json ) > 100000 ) {
            return new WP_Error( 'json_too_large', 'JSONデータが大きすぎます（最大100KB）。' );
        }

        if ( ! json_validate( $json ) ) {
            return new WP_Error( 'invalid_json', 'JSONの形式が正しくありません。' );
        }

        $imported = json_decode( $json, true );

        if ( ! is_array( $imported ) ) {
            return new WP_Error( 'invalid_format', '設定データの形式が正しくありません。' );
        }

        $current = $this->get_settings();

        unset( $imported['github_token'] );
        unset( $imported['cloudflare_api_token'] );
        unset( $imported['gitlab_token'] );
        unset( $imported['netlify_api_token'] );

        $key_migration = array(
            'local_dir' => 'local_output_path',
            'github_branch' => 'github_existing_branch',
            'zip_filename' => 'zip_output_path',
            'exclude_paths' => 'exclude_patterns',
        );

        foreach ( $key_migration as $old_key => $new_key ) {
            if ( isset( $imported[ $old_key ] ) && ! isset( $imported[ $new_key ] ) ) {
                $imported[ $new_key ] = $imported[ $old_key ];
                unset( $imported[ $old_key ] );
            }
        }

        // Legacy format import: set the default value when zip_mode is missing
        if ( ! isset( $imported['zip_mode'] ) ) {
            $imported['zip_mode'] = 'download';
        }

        if ( isset( $imported['github_existing_branch'] ) && ! isset( $imported['github_branch_mode'] ) ) {
            $imported['github_branch_mode'] = 'existing';
        }

        $allowed_keys = array(
            'local_enabled',
            'github_enabled', 'github_repo',
            'github_branch_mode', 'github_existing_branch', 'github_new_branch', 'github_base_branch',
            'github_method',
            'git_local_enabled', 'git_local_branch', 'git_local_remote_url',
            'zip_enabled', 'zip_mode',
            'cloudflare_enabled', 'cloudflare_use_wrangler', 'cloudflare_account_id', 'cloudflare_script_name',
            'gitlab_enabled', 'gitlab_project',
            'gitlab_branch_mode', 'gitlab_existing_branch', 'gitlab_new_branch', 'gitlab_base_branch',
            'gitlab_api_url',
            'netlify_enabled', 'netlify_site_id',
            'url_mode', 'base_url', 'custom_wp_includes', 'custom_wp_content', 'exclude_patterns',
            'timeout',
            'auto_generate',
            'commit_message',
            'enable_tag_archive', 'enable_date_archive', 'enable_author_archive',
            'enable_post_format_archive', 'enable_sitemap', 'enable_robots_txt', 'enable_llms_txt', 'enable_rss',
            'generate_mati_headers',
            'minify_html', 'minify_css',
        );

        $sanitized = array();
        foreach ( $allowed_keys as $key ) {
            if ( isset( $imported[ $key ] ) ) {
                $boolean_keys = array(
                    'local_enabled', 'github_enabled', 'git_local_enabled', 'zip_enabled',
                    'auto_generate',
                    'cloudflare_enabled', 'cloudflare_use_wrangler', 'gitlab_enabled', 'netlify_enabled',
                    'enable_tag_archive', 'enable_date_archive', 'enable_author_archive',
                    'enable_post_format_archive', 'enable_sitemap', 'enable_robots_txt', 'enable_llms_txt', 'enable_rss',
                    'generate_mati_headers',
                );
                if ( is_bool( $current[ $key ] ?? false ) || in_array( $key, $boolean_keys, true ) ) {
                    $sanitized[ $key ] = (bool) $imported[ $key ];
                } elseif ( is_int( $current[ $key ] ?? 0 ) || $key === 'timeout' ) {
                    $sanitized[ $key ] = absint( $imported[ $key ] );
                } else {
                    $sanitized[ $key ] = sanitize_text_field( $imported[ $key ] );
                }
            }
        }

        $textarea_keys = array( 'exclude_patterns' );
        foreach ( $textarea_keys as $key ) {
            if ( isset( $imported[ $key ] ) ) {
                $sanitized[ $key ] = sanitize_textarea_field( $imported[ $key ] );
            }
        }

        if ( isset( $sanitized['zip_mode'] ) && ! in_array( $sanitized['zip_mode'], array( 'download', 'local' ), true ) ) {
            $sanitized['zip_mode'] = 'download';
        }

        $merged = array_merge( $current, $sanitized );
        $merged['version'] = CP_VERSION;

        // Filesystem locations are never imported, so turn off outputs that would have no location set
        if ( empty( $merged['local_output_path'] ) ) {
            $merged['local_enabled'] = false;
        }
        if ( empty( $merged['git_local_work_dir'] ) ) {
            $merged['git_local_enabled'] = false;
        }
        if ( ( $merged['zip_mode'] ?? 'download' ) === 'local' && empty( $merged['zip_output_path'] ) ) {
            $merged['zip_enabled'] = false;
        }

        // Force-disable CLI-dependent features on Playground (WordPress Studio) since the CLI cannot be run there
        if ( self::is_playground() ) {
            $merged['git_local_enabled'] = false;
            $merged['cloudflare_use_wrangler'] = false;
        }

        // Disable when using the Workers Direct Upload API alone, since _headers does not work
        if ( ! empty( $merged['cloudflare_enabled'] ) && empty( $merged['cloudflare_use_wrangler'] ) ) {
            $other_destinations = array( 'github_enabled', 'gitlab_enabled', 'netlify_enabled', 'git_local_enabled', 'local_enabled', 'zip_enabled' );
            $has_other = false;
            foreach ( $other_destinations as $key ) {
                if ( ! empty( $merged[ $key ] ) ) {
                    $has_other = true;
                    break;
                }
            }
            if ( ! $has_other ) {
                $merged['generate_mati_headers'] = false;
            }
        }

        $validation = $this->validate_settings( $merged );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        update_option( 'cp_settings', $merged );

        CP_Cache::get_instance()->clear_all();

        return true;
    }

    private function is_valid_github_token_format( string $token ): bool {
        if ( str_starts_with( $token, 'v2:' ) || str_contains( $token, '::' ) ) {
            return true;
        }

        $len = strlen( $token );
        if ( $len < 20 || $len > 255 ) {
            return false;
        }

        if ( preg_match( '/^(ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9_]{36,251}$/', $token ) ) {
            return true;
        }

        if ( preg_match( '/^[a-f0-9]{40}$/', $token ) ) {
            return true;
        }

        if ( preg_match( '/^github_pat_[A-Za-z0-9_]{22,}$/', $token ) ) {
            return true;
        }

        return false;
    }

    private function is_valid_gitlab_token_format( string $token ): bool {
        if ( str_starts_with( $token, 'v2:' ) || str_contains( $token, '::' ) ) {
            return true;
        }

        $len = strlen( $token );
        if ( $len < 10 || $len > 255 ) {
            return false;
        }

        if ( preg_match( '/^glpat-[A-Za-z0-9_.-]+$/', $token ) ) {
            return true;
        }

        if ( preg_match( '/^gl[a-z]+-[A-Za-z0-9_.-]+$/', $token ) ) {
            return true;
        }

        if ( preg_match( '/^[A-Za-z0-9_.-]+$/', $token ) ) {
            return true;
        }

        return false;
    }
}
