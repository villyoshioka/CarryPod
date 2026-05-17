<?php
/**
 * ログ管理クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_Logger {

    private static ?self $instance = null;

    const string LEVEL_ERROR   = 'error';
    const string LEVEL_WARNING = 'warning';
    const string LEVEL_INFO    = 'info';
    const string LEVEL_DEBUG   = 'debug';

    private ?float $start_time = null;
    private array $log_buffer = array();
    private int $batch_threshold = 10;
    private ?bool $debug_mode_cache = null;

    public static function get_instance(): static {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * デバッグモードが有効かどうかをチェック
     *
     * URLパラメータ &debugmode=on で有効化（管理者のみ）
     * 有効化するとトランジェントに保存され、非同期処理でも維持される
     */
    public function is_debug_mode(): bool {
        if ( $this->debug_mode_cache !== null ) {
            return $this->debug_mode_cache;
        }

        if ( get_transient( 'cp_debug_mode' ) ) {
            $this->debug_mode_cache = true;
            return true;
        }

        $this->debug_mode_cache = false;
        return false;
    }

    public function enable_debug_mode(): void {
        set_transient( 'cp_debug_mode', true, HOUR_IN_SECONDS );
        $this->debug_mode_cache = true;
    }

    public function disable_debug_mode(): void {
        delete_transient( 'cp_debug_mode' );
        $this->debug_mode_cache = false;
    }

    public function start_timer(): void {
        $this->start_time = microtime( true );
    }

    private function get_elapsed_time(): float {
        if ( $this->start_time === null ) {
            return 0.0;
        }
        return microtime( true ) - $this->start_time;
    }

    private function format_elapsed_time(): string {
        return sprintf( '+%.1fs', $this->get_elapsed_time() );
    }

    public function add_log( string $message, string|bool $level = self::LEVEL_INFO ): void {
        // 後方互換性: 第2引数がboolの場合はerrorレベルとして扱う
        if ( is_bool( $level ) ) {
            $level = $level ? self::LEVEL_ERROR : self::LEVEL_INFO;
        }

        if ( $level === self::LEVEL_DEBUG && ! $this->is_debug_mode() ) {
            return;
        }

        $timestamp = current_time( 'Y-m-d H:i:s' );
        $elapsed = $this->format_elapsed_time();

        [$prefix, $is_error] = match ( $level ) {
            self::LEVEL_ERROR   => ['エラー: ', true],
            self::LEVEL_WARNING => ['警告: ', true],
            self::LEVEL_DEBUG   => ['[DEBUG] ', false],
            default             => ['', false],
        };

        $this->log_buffer[] = array(
            'timestamp' => $timestamp,
            'elapsed'   => $elapsed,
            'message'   => $prefix . $message,
            'level'     => $level,
            'is_error'  => $is_error,
        );

        if ( $is_error || count( $this->log_buffer ) >= $this->batch_threshold ) {
            $this->flush_logs();
        }
    }

    public function flush_logs(): void {
        if ( empty( $this->log_buffer ) ) {
            return;
        }

        $logs = get_option( 'cp_logs', array() );
        $logs = array_merge( $logs, $this->log_buffer );

        // メモリ保護のため1000件まで
        if ( count( $logs ) > 1000 ) {
            $logs = array_slice( $logs, -1000 );
        }

        update_option( 'cp_logs', $logs );
        $this->log_buffer = array();
    }

    public function error( string $message ): void {
        $this->add_log( $message, self::LEVEL_ERROR );
    }

    public function warning( string $message ): void {
        $this->add_log( $message, self::LEVEL_WARNING );
    }

    public function info( string $message ): void {
        $this->add_log( $message, self::LEVEL_INFO );
    }

    public function debug( string $message ): void {
        $this->add_log( $message, self::LEVEL_DEBUG );
    }

    public function get_logs(): array {
        $this->flush_logs();
        return get_option( 'cp_logs', array() );
    }

    public function clear_logs(): bool {
        if ( get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' ) ) {
            return false;
        }

        $this->log_buffer = array();
        update_option( 'cp_logs', array() );
        $this->start_time = null;

        return true;
    }

    public function get_logs_from_offset( int $offset = 0 ): array {
        $logs = $this->get_logs();
        if ( $offset >= count( $logs ) ) {
            return array();
        }
        return array_slice( $logs, $offset );
    }

    public function get_log_count(): int {
        return count( $this->get_logs() );
    }

    public function is_running(): bool {
        return (bool) ( get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' ) );
    }

    public function update_progress( int $current, int $total, string $status = '' ): void {
        set_transient( 'cp_progress', array(
            'current' => $current,
            'total' => $total,
            'status' => $status,
            'percentage' => $total > 0 ? round( ( $current / $total ) * 100 ) : 0,
        ), 3600 );
    }

    public function get_progress(): array {
        $progress = get_transient( 'cp_progress' );
        if ( ! $progress ) {
            return array(
                'current' => 0,
                'total' => 0,
                'status' => '',
                'percentage' => 0,
            );
        }
        return $progress;
    }

    public function clear_progress(): void {
        delete_transient( 'cp_progress' );
    }

    public function __destruct() {
        $this->flush_logs();
    }
}
