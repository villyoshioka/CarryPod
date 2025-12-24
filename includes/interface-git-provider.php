<?php
/**
 * Git Provider インターフェース
 *
 * GitHubとGitLabの共通インターフェースを定義します。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface CP_Git_Provider_Interface {

    /**
     * リポジトリが存在するかチェック
     *
     * @return bool|WP_Error 存在すればtrue、存在しなければfalse、エラーならWP_Error
     */
    public function check_repo_exists();

    /**
     * リポジトリを作成
     *
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function create_repo();

    /**
     * ブランチが存在するかチェック
     *
     * @return bool|WP_Error 存在すればtrue、存在しなければfalse、エラーならWP_Error
     */
    public function check_branch_exists();

    /**
     * デフォルトブランチを取得
     *
     * @return string|WP_Error デフォルトブランチ名、エラーならWP_Error
     */
    public function get_default_branch();

    /**
     * ファイルをバッチ処理でプッシュ（ディスクから直接読み込み）
     *
     * @param array  $file_paths ファイルパスの配列
     * @param string $base_dir ベースディレクトリ
     * @param string $commit_message コミットメッセージ
     * @param int    $batch_size バッチサイズ
     * @return bool|WP_Error 成功ならtrue、失敗ならWP_Error
     */
    public function push_files_batch_from_disk( $file_paths, $base_dir, $commit_message, $batch_size = 300 );
}
