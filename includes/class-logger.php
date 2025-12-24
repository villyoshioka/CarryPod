<?php
/**
 * ログ管理クラス
 *
 * Ver1.2: サマリー形式ログ、経過時間表示、ログレベル対応
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CP_Logger {

    /**
     * シングルトンインスタンス
     */
    private static $instance = null;

    /**
     * ログレベル定数
     */
    const LEVEL_ERROR   = 'error';
    const LEVEL_WARNING = 'warning';
    const LEVEL_INFO    = 'info';
    const LEVEL_DEBUG   = 'debug';

    /**
     * 処理開始時刻
     */
    private $start_time = null;

    /**
     * バッチログバッファ
     */
    private $log_buffer = array();

    /**
     * バッチ書き込みの閾値
     */
    private $batch_threshold = 10;

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
     * デバッグモードキャッシュ（リクエスト内で1回だけ判定）
     */
    private $debug_mode_cache = null;

    /**
     * デバッグモードが有効かどうかをチェック
     *
     * URLパラメータ &debugmode=on で有効化（管理者のみ）
     * 有効化するとトランジェントに保存され、非同期処理でも維持される
     *
     * @return bool デバッグモードが有効ならtrue
     */
    public function is_debug_mode() {
        // キャッシュがある場合はそれを返す
        if ( $this->debug_mode_cache !== null ) {
            return $this->debug_mode_cache;
        }

        // まずトランジェントをチェック（非同期処理用・権限チェック不要）
        if ( get_transient( 'cp_debug_mode' ) ) {
            $this->debug_mode_cache = true;
            return true;
        }

        $this->debug_mode_cache = false;
        return false;
    }

    /**
     * デバッグモードを有効化
     * 管理画面からのみ呼び出し可能
     */
    public function enable_debug_mode() {
        set_transient( 'cp_debug_mode', true, HOUR_IN_SECONDS );
        $this->debug_mode_cache = true;
    }

    /**
     * デバッグモードを無効化
     */
    public function disable_debug_mode() {
        delete_transient( 'cp_debug_mode' );
        $this->debug_mode_cache = false;
    }

    /**
     * 処理開始時刻をセット
     */
    public function start_timer() {
        $this->start_time = microtime( true );
    }

    /**
     * 経過時間を取得（秒）
     *
     * @return float 経過秒数
     */
    private function get_elapsed_time() {
        if ( $this->start_time === null ) {
            return 0.0;
        }
        return microtime( true ) - $this->start_time;
    }

    /**
     * 経過時間をフォーマット
     *
     * @return string フォーマット済み経過時間 例: "+12.5s"
     */
    private function format_elapsed_time() {
        $elapsed = $this->get_elapsed_time();
        return sprintf( '+%.1fs', $elapsed );
    }

    /**
     * ログを追加
     *
     * @param string $message ログメッセージ
     * @param string $level ログレベル (error, warning, info, debug)
     */
    public function add_log( $message, $level = self::LEVEL_INFO ) {
        // 後方互換性: 第2引数がboolの場合はerrorレベルとして扱う
        if ( is_bool( $level ) ) {
            $level = $level ? self::LEVEL_ERROR : self::LEVEL_INFO;
        }

        // デバッグモードでない場合、debugレベルのログは無視
        if ( $level === self::LEVEL_DEBUG && ! $this->is_debug_mode() ) {
            return;
        }

        // タイムゾーンに従った日時を取得
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $elapsed = $this->format_elapsed_time();

        // レベルに応じたプレフィックス
        $prefix = '';
        $is_error = false;
        switch ( $level ) {
            case self::LEVEL_ERROR:
                $prefix = 'エラー: ';
                $is_error = true;
                break;
            case self::LEVEL_WARNING:
                $prefix = '警告: ';
                $is_error = true;
                break;
            case self::LEVEL_DEBUG:
                $prefix = '[DEBUG] ';
                break;
        }

        // ログエントリを作成
        $log_entry = array(
            'timestamp' => $timestamp,
            'elapsed'   => $elapsed,
            'message'   => $prefix . $message,
            'level'     => $level,
            'is_error'  => $is_error,
        );

        // バッファに追加
        $this->log_buffer[] = $log_entry;

        // エラー/警告は即座に書き込み、それ以外はバッチ処理
        if ( $is_error || count( $this->log_buffer ) >= $this->batch_threshold ) {
            $this->flush_logs();
        }
    }

    /**
     * バッファ内のログをDBに書き込み
     */
    public function flush_logs() {
        if ( empty( $this->log_buffer ) ) {
            return;
        }

        $logs = get_option( 'cp_logs', array() );
        $logs = array_merge( $logs, $this->log_buffer );

        // セッション内のログ数の安全制限（メモリ保護のため1000件まで）
        if ( count( $logs ) > 1000 ) {
            $logs = array_slice( $logs, -1000 );
        }

        // ログを保存
        update_option( 'cp_logs', $logs );

        // バッファをクリア
        $this->log_buffer = array();
    }

    /**
     * エラーログを追加（ショートカット）
     *
     * @param string $message ログメッセージ
     */
    public function error( $message ) {
        $this->add_log( $message, self::LEVEL_ERROR );
    }

    /**
     * 警告ログを追加（ショートカット）
     *
     * @param string $message ログメッセージ
     */
    public function warning( $message ) {
        $this->add_log( $message, self::LEVEL_WARNING );
    }

    /**
     * 情報ログを追加（ショートカット）
     *
     * @param string $message ログメッセージ
     */
    public function info( $message ) {
        $this->add_log( $message, self::LEVEL_INFO );
    }

    /**
     * デバッグログを追加（ショートカット）
     *
     * @param string $message ログメッセージ
     */
    public function debug( $message ) {
        $this->add_log( $message, self::LEVEL_DEBUG );
    }

    /**
     * すべてのログを取得
     *
     * @return array ログの配列
     */
    public function get_logs() {
        // まずバッファをフラッシュ
        $this->flush_logs();
        return get_option( 'cp_logs', array() );
    }

    /**
     * 完了したログをクリア（実行中でないことを確認）
     */
    public function clear_logs() {
        // 実行中フラグをチェック
        if ( get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' ) ) {
            return false; // 実行中の場合はクリアしない
        }

        // バッファもクリア
        $this->log_buffer = array();

        // ログをクリア
        update_option( 'cp_logs', array() );

        // タイマーもリセット
        $this->start_time = null;

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
        return get_transient( 'cp_manual_running' ) || get_transient( 'cp_auto_running' );
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
        set_transient( 'cp_progress', $progress, 3600 );
    }

    /**
     * 進捗情報を取得
     *
     * @return array 進捗情報
     */
    public function get_progress() {
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

    /**
     * 進捗情報をクリア
     */
    public function clear_progress() {
        delete_transient( 'cp_progress' );
    }

    /**
     * デストラクタ - 残りのログをフラッシュ
     */
    public function __destruct() {
        $this->flush_logs();
    }
}
