<?php
/**
 * Git Provider インターフェース
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface CP_Git_Provider_Interface {

    public function check_repo_exists(): bool|\WP_Error;
    public function create_repo(): bool|\WP_Error;
    public function check_branch_exists(): bool|\WP_Error;
    public function get_default_branch(): string|\WP_Error;
    public function push_files_batch_from_disk( array $file_paths, string $base_dir, string $commit_message, int $batch_size = 300 ): bool|\WP_Error;
}
