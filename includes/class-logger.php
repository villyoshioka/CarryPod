<?php
/**
 * ログ管理クラス
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SGE_Logger {

    /**
     * シングルトンインスタンス
     */
    private static $instance = null;

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
     * ログを追加
     *
     * @param string $message ログメッセージ
     * @param bool $is_error エラーログかどうか
     */
    public function add_log( $message, $is_error = false ) {
        $logs = get_option( 'sge_logs', array() );

        // タイムゾーンに従った日時を取得
        $timestamp = current_time( 'Y-m-d H:i:s' );

        // エラーの場合はプレフィックスを付ける
        if ( $is_error ) {
            $message = 'エラー：' . $message;
        }

        // 新しいログを追加
        $logs[] = array(
            'timestamp' => $timestamp,
            'message' => $message,
            'is_error' => $is_error,
        );

        // セッション内のログ数の安全制限（メモリ保護のため1000件まで）
        if ( count( $logs ) > 1000 ) {
            $logs = array_slice( $logs, -1000 );
        }

        // ログを保存
        update_option( 'sge_logs', $logs );
    }

    /**
     * すべてのログを取得
     *
     * @return array ログの配列
     */
    public function get_logs() {
        return get_option( 'sge_logs', array() );
    }

    /**
     * 完了したログをクリア（実行中でないことを確認）
     */
    public function clear_logs() {
        // 実行中フラグをチェック
        if ( get_transient( 'sge_manual_running' ) || get_transient( 'sge_auto_running' ) ) {
            return false; // 実行中の場合はクリアしない
        }

        // ログをクリア
        update_option( 'sge_logs', array() );
        return true;
    }

    /**
     * 最新のログを取得（Ajax polling用）
     *
     * @param int $offset 取得開始位置
     * @return array ログの配列
     */
    public function get_logs_from_offset( $offset = 0 ) {
        $logs = $this->get_logs();
        if ( $offset >= count( $logs ) ) {
            return array();
        }
        return array_slice( $logs, $offset );
    }

    /**
     * ログ件数を取得
     *
     * @return int ログ件数
     */
    public function get_log_count() {
        $logs = $this->get_logs();
        return count( $logs );
    }

    /**
     * 実行中かどうかをチェック
     *
     * @return bool 実行中ならtrue
     */
    public function is_running() {
        return get_transient( 'sge_manual_running' ) || get_transient( 'sge_auto_running' );
    }

    /**
     * 進捗情報を更新
     *
     * @param int $current 現在のステップ
     * @param int $total 総ステップ数
     * @param string $status 現在のステータスメッセージ
     */
    public function update_progress( $current, $total, $status = '' ) {
        $progress = array(
            'current' => $current,
            'total' => $total,
            'status' => $status,
            'percentage' => $total > 0 ? round( ( $current / $total ) * 100 ) : 0,
        );
        set_transient( 'sge_progress', $progress, 3600 );
    }

    /**
     * 進捗情報を取得
     *
     * @return array 進捗情報
     */
    public function get_progress() {
        $progress = get_transient( 'sge_progress' );
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

    /**
     * 進捗情報をクリア
     */
    public function clear_progress() {
        delete_transient( 'sge_progress' );
    }
}
