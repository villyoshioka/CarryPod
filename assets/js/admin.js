/**
 * Carry Pod 管理画面JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';

    let progressPollInterval = null;

    // HTMLエスケープ関数（XSS対策）
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 通知表示用のヘルパー関数
    function showNotice(message, type = 'success') {
        // 既存の通知を削除
        $('.sge-notice').remove();

        // 通知タイプのクラス名を決定
        const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';

        // メッセージをエスケープしてXSS対策
        const safeMessage = escapeHtml(message);

        // 通知HTMLを作成
        const $notice = $('<div class="notice sge-notice ' + noticeClass + ' is-dismissible">' +
            '<p>' + safeMessage + '</p>' +
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">この通知を無視</span>' +
            '</button>' +
            '</div>');

        // 通知を追加
        if ($('.wrap h1').length > 0) {
            $('.wrap h1').first().after($notice);
        } else {
            $('.sge-admin-wrap').prepend($notice);
        }

        // 自動的に5秒後にフェードアウト
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);

        // 閉じるボタンのイベント
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });

        // ページ上部にスクロール
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    // 確認ダイアログ用の関数
    function showConfirm(message, onConfirm, onCancel) {
        // 既存の確認ダイアログを削除
        $('.sge-confirm-dialog').remove();

        // ダイアログHTMLを作成
        const $dialog = $('<div class="sge-confirm-dialog">' +
            '<div class="sge-confirm-overlay"></div>' +
            '<div class="sge-confirm-box">' +
            '<h3>確認</h3>' +
            '<p>' + message + '</p>' +
            '<div class="sge-confirm-buttons">' +
            '<button class="button button-primary sge-confirm-yes">はい</button>' +
            '<button class="button sge-confirm-no">いいえ</button>' +
            '</div>' +
            '</div>' +
            '</div>');

        // ダイアログを追加
        $('body').append($dialog);

        // はいボタンのイベント
        $dialog.find('.sge-confirm-yes').on('click', function() {
            $dialog.remove();
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        });

        // いいえボタンのイベント
        $dialog.find('.sge-confirm-no, .sge-confirm-overlay').on('click', function() {
            $dialog.remove();
            if (typeof onCancel === 'function') {
                onCancel();
            }
        });
    }

    // チェックボックスの連動
    $('#sge-github-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#sge-github-settings').slideDown();
        } else {
            $('#sge-github-settings').slideUp();
        }
        updateExecuteButton();
    });

    $('#sge-git-local-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#sge-git-local-settings').slideDown();
        } else {
            $('#sge-git-local-settings').slideUp();
        }
        updateExecuteButton();
    });

    $('#sge-local-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#sge-local-settings').slideDown();
        } else {
            $('#sge-local-settings').slideUp();
        }
        updateExecuteButton();
    });

    // ZIP出力チェックボックスの連動
    $('#sge-zip-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#sge-zip-settings').slideDown();
        } else {
            $('#sge-zip-settings').slideUp();
        }
        updateExecuteButton();
    });

    // Cloudflare出力チェックボックスの連動
    $('#sge-cloudflare-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#sge-cloudflare-settings').slideDown();
        } else {
            $('#sge-cloudflare-settings').slideUp();
        }
        updateExecuteButton();
    });

    // GitLab出力チェックボックスの連動
    $('#sge-gitlab-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#sge-gitlab-settings').slideDown();
        } else {
            $('#sge-gitlab-settings').slideUp();
        }
        updateExecuteButton();
    });

    // Netlify出力チェックボックスの連動
    $('#sge-netlify-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#sge-netlify-settings').slideDown();
        } else {
            $('#sge-netlify-settings').slideUp();
        }
        updateExecuteButton();
    });

    // ブランチモードのラジオボタン連動（GitHub）
    const $branchModeRadio = $('input[name="github_branch_mode"]');
    if ($branchModeRadio.length > 0) {
        $branchModeRadio.on('change', function() {
            const mode = $(this).val();
            if (mode === 'existing') {
                $('#sge-github-existing-branch').prop('disabled', false);
                $('#sge-github-new-branch, #sge-github-base-branch').prop('disabled', true);
            } else {
                $('#sge-github-existing-branch').prop('disabled', true);
                $('#sge-github-new-branch, #sge-github-base-branch').prop('disabled', false);
            }
        });
    }

    // ブランチモードのラジオボタン連動（GitLab）
    const $gitlabBranchModeRadio = $('input[name="gitlab_branch_mode"]');
    if ($gitlabBranchModeRadio.length > 0) {
        $gitlabBranchModeRadio.on('change', function() {
            const mode = $(this).val();
            if (mode === 'existing') {
                $('#sge-gitlab-existing-branch').prop('disabled', false);
                $('#sge-gitlab-new-branch, #sge-gitlab-base-branch').prop('disabled', true);
            } else {
                $('#sge-gitlab-existing-branch').prop('disabled', true);
                $('#sge-gitlab-new-branch, #sge-gitlab-base-branch').prop('disabled', false);
            }
        });
    }

    // 実行ボタンの有効/無効を更新
    function updateExecuteButton() {
        const $githubCheckbox = $('#sge-github-enabled');
        const $gitLocalCheckbox = $('#sge-git-local-enabled');
        const $localCheckbox = $('#sge-local-enabled');
        const $gitlabCheckbox = $('#sge-gitlab-enabled');
        const $executeButton = $('#sge-execute-button');
        const $commitSection = $('.sge-commit-section');
        const $commitMessage = $('#sge-commit-message');

        // チェックボックスが存在しない場合（実行画面）は初期状態を維持
        if ($githubCheckbox.length === 0 && $gitLocalCheckbox.length === 0 && $localCheckbox.length === 0) {
            // 実行画面では、PHPで設定された初期状態（activeクラス）を維持
            // コミットメッセージの有無だけチェック
            if ($commitSection.hasClass('active')) {
                if ($commitMessage.val() && $commitMessage.val().trim() !== '') {
                    $executeButton.addClass('has-commit-message');
                } else {
                    $executeButton.removeClass('has-commit-message');
                }
            }
            return;
        }

        // 設定画面での動作
        const githubEnabled = $githubCheckbox.is(':checked');
        const gitLocalEnabled = $gitLocalCheckbox.is(':checked');
        const localEnabled = $localCheckbox.is(':checked');
        const gitlabEnabled = $gitlabCheckbox.is(':checked');

        // 出力先が選択されていなくても静的化は実行可能（デフォルトで有効）

        // GitHub出力またはローカルGit出力またはGitLab出力が有効な場合はコミットメッセージセクションを表示
        if (githubEnabled || gitLocalEnabled || gitlabEnabled) {
            $commitSection.addClass('active');
            $executeButton.addClass('commit-required');

            // コミットメッセージの有無をクラスで管理（ボタンは常に有効）
            if ($commitMessage.val() && $commitMessage.val().trim() !== '') {
                $executeButton.addClass('has-commit-message');
            } else {
                $executeButton.removeClass('has-commit-message');
            }
            // ボタンは常に有効
            $executeButton.prop('disabled', false);
        } else {
            $commitSection.removeClass('active');
            $executeButton.removeClass('commit-required');
            $executeButton.prop('disabled', false);
        }
    }

    // コミットメッセージ入力欄の監視
    $('#sge-commit-message').on('input change', function() {
        const $executeButton = $('#sge-execute-button');
        const $githubCheckbox = $('#sge-github-enabled');
        const $commitSection = $('.sge-commit-section');

        // 実行画面の場合（チェックボックスが存在しない）
        if ($githubCheckbox.length === 0) {
            // コミットセクションがactiveの場合のみ処理
            if ($commitSection.hasClass('active')) {
                if ($(this).val() && $(this).val().trim() !== '') {
                    $executeButton.addClass('has-commit-message');
                } else {
                    $executeButton.removeClass('has-commit-message');
                }
            }
            return;
        }

        // 設定画面の場合
        const githubEnabled = $githubCheckbox.is(':checked');
        if (githubEnabled) {
            if ($(this).val() && $(this).val().trim() !== '') {
                $executeButton.addClass('has-commit-message');
            } else {
                $executeButton.removeClass('has-commit-message');
            }
            // GitHub有効時でも、コミットメッセージが空でも実行可能にする（サーバー側でデフォルト値を設定）
            $executeButton.prop('disabled', false);
        }
    });

    // コミットメッセージのリセット
    $('#sge-reset-commit-message').on('click', function() {
        const $commitMessage = $('#sge-commit-message');
        if ($commitMessage.length === 0) {
            return;
        }
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');
        const defaultMessage = 'update:' + year + month + day + '_' + hours + minutes + seconds;
        $commitMessage.val(defaultMessage);

        // ボタンの状態を更新
        updateExecuteButton();
    });

    // 静的化を中止
    $('#sge-cancel-button').on('click', function(e) {
        e.preventDefault();

        const $button = $(this);

        // 確認ダイアログを表示
        showConfirm('静的化の実行を中止しますか？', function() {
            $button.prop('disabled', true).text('中止中...');

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sge_cancel_generation',
                    nonce: sgeData.nonce
                },
                success: function(response) {
                    console.log('キャンセル成功:', response);
                    if (response.success) {
                        showNotice(response.data.message, 'success');

                        // ボタンを元に戻す
                        $('#sge-execute-button').prop('disabled', false).text('静的化を実行');
                        $('#sge-cancel-button').prop('disabled', true).hide();
                        $('#sge-download-log').prop('disabled', false);

                        // 進捗をリセット
                        $('#sge-progress-bar').css('width', '0%');
                        $('#sge-progress-percentage').text('0%');
                        $('#sge-progress-status').text('待機中...');

                        // ポーリングを停止
                        stopProgressPolling();

                        updateExecuteButton();
                    } else {
                        console.error('サーバーエラー:', response.data);
                        showNotice(response.data.message || '実行の中止に失敗しました。', 'error');
                        $button.prop('disabled', false).text('実行中止');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX エラー:', {xhr: xhr, status: status, error: error});
                    showNotice('エラーが発生しました: ' + error, 'error');
                    $button.prop('disabled', false).text('実行中止');
                }
            });
        }, function() {
            // キャンセル時は何もしない
        });
    });

    // 静的化を実行
    $('#sge-execute-button').on('click', function(e) {
        e.preventDefault();

        const $button = $(this);

        // 既に無効化されている場合は何もしない（二重実行防止）
        if ($button.prop('disabled')) {
            console.log('ボタンが既に無効化されているため、処理をスキップします');
            return false;
        }

        // sgeDataが定義されているかチェック
        if (typeof sgeData === 'undefined') {
            console.error('sgeDataが未定義です。JavaScriptの読み込みに問題がある可能性があります。');
            showNotice('JavaScriptの読み込みエラーが発生しました。ページを再読み込みしてください。', 'error');
            return false;
        }

        const $commitMessage = $('#sge-commit-message');
        const githubEnabled = $('#sge-github-enabled').is(':checked');
        let commitMessage = '';

        // GitHub出力が有効な場合のみコミットメッセージを取得（空でもOK、サーバー側でデフォルト値を設定）
        if (githubEnabled && $commitMessage.length > 0) {
            commitMessage = $commitMessage.val();
            // 空の場合でもエラーにせず、サーバー側でデフォルト値が自動設定される
        }

        // 進捗セクションは常に表示されているため、activeクラスの追加は不要

        $button.prop('disabled', true).text('静的化中...');

        console.log('静的化を実行します', {
            url: sgeData.ajaxurl,
            nonce: sgeData.nonce,
            commit_message: commitMessage
        });

        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sge_execute_generation',
                nonce: sgeData.nonce,
                commit_message: commitMessage
            },
            success: function(response) {
                console.log('AJAX成功:', response);
                if (response.success) {
                    // 停止ボタンを表示
                    $('#sge-cancel-button').prop('disabled', false).show();

                    // 進捗のポーリングを開始
                    startProgressPolling();
                    // Action Schedulerのタスクが開始されるまで少し待機してから進捗チェック
                    setTimeout(function() {
                        loadProgress();
                    }, 500);
                } else {
                    console.error('サーバーエラー:', response.data);
                    showNotice(response.data.message || '静的化の実行に失敗しました。', 'error');
                    $('#sge-execute-button').prop('disabled', false).text('静的化を実行');
                    updateExecuteButton();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX エラー:', {xhr: xhr, status: status, error: error});
                showNotice('エラーが発生しました: ' + error, 'error');
                $('#sge-execute-button').prop('disabled', false).text('静的化を実行');
                updateExecuteButton();
            }
        });
    });

    // 進捗を読み込み
    function loadProgress() {
        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sge_get_progress',
                nonce: sgeData.nonce
            },
            success: function(response) {
                console.log('進捗情報を取得:', response);
                if (response.success) {
                    const progress = response.data.progress;
                    const isRunning = response.data.is_running;
                    const $progressSection = $('.sge-progress-section');

                    console.log('進捗状態:', {
                        isRunning: isRunning,
                        progress: progress,
                        percentage: progress.percentage,
                        current: progress.current,
                        total: progress.total
                    });

                    // 進捗セクションを表示
                    if (isRunning || progress.total > 0) {
                        $progressSection.addClass('active');
                    }

                    // プログレスバーを更新
                    $('#sge-progress-bar').css('width', progress.percentage + '%');
                    $('#sge-progress-percentage').text(progress.percentage + '%');

                    // 実行中でなければボタンを有効化
                    if (!isRunning) {
                        console.log('実行中ではないため、ボタンを有効化します');
                        $('#sge-execute-button').prop('disabled', false).text('静的化を実行');
                        $('#sge-cancel-button').prop('disabled', true).hide();
                        $('#sge-download-log').prop('disabled', false);
                        stopProgressPolling();

                        // ボタンの状態を更新（GitHub有効時のコミットメッセージチェック含む）
                        updateExecuteButton();

                        // 完了時は100%に設定
                        if (progress.percentage === 100 && progress.current === progress.total && progress.total > 0) {
                            // エラー通知があるか確認
                            $.ajax({
                                url: sgeData.ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'sge_check_error_notification',
                                    nonce: sgeData.nonce
                                },
                                success: function(response) {
                                    if (response.success && response.data.has_error) {
                                        $('#sge-progress-status')
                                            .text('エラーが発生しました')
                                            .css('color', '#d63638');
                                        $('#sge-progress-bar').css('background-color', '#d63638');
                                    } else {
                                        $('#sge-progress-status').text('完了しました！');
                                    }
                                }
                            });
                        } else {
                            $('#sge-progress-status').text('待機中...');
                        }
                    } else {
                        // 実行中の場合は進捗セクションを表示
                        $progressSection.addClass('active');

                        // 実行中の場合はステータスメッセージと進捗を表示
                        let statusMessage = progress.status || '処理中...';
                        if (progress.current > 0 && progress.total > 0) {
                            statusMessage += ' (' + progress.current + ' / ' + progress.total + ' 完了)';
                        }
                        $('#sge-progress-status').text(statusMessage);
                        $('#sge-download-log').prop('disabled', true);
                    }
                }
            }
        });
    }

    // 進捗のポーリングを開始
    function startProgressPolling() {
        if (progressPollInterval) {
            return;
        }
        progressPollInterval = setInterval(loadProgress, 1000); // 1秒間隔
    }

    // 進捗のポーリングを停止
    function stopProgressPolling() {
        if (progressPollInterval) {
            clearInterval(progressPollInterval);
            progressPollInterval = null;
        }
    }

    // 設定を保存
    $('#sge-settings-form').on('submit', function(e) {
        e.preventDefault();

        const formData = $(this).serialize();

        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: formData + '&action=sge_save_settings&nonce=' + sgeData.nonce,
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotice(response.data.message, 'error');
                }
            },
            error: function() {
                showNotice('エラーが発生しました。', 'error');
            }
        });
    });

    // 設定をリセット
    $('#sge-reset-settings').on('click', function() {
        showConfirm('設定をリセットしますか？', function() {
            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sge_reset_settings',
                    nonce: sgeData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    showNotice('エラーが発生しました。', 'error');
                }
            });
        });
    });

    // キャッシュをクリア
    $('#sge-clear-cache').on('click', function() {
        const $button = $(this);
        showConfirm('キャッシュをクリアしますか？', function() {
            $button.prop('disabled', true);

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sge_clear_cache',
                    nonce: sgeData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                    } else {
                        showNotice(response.data.message, 'error');
                    }
                    $button.prop('disabled', false);
                },
                error: function() {
                    showNotice('エラーが発生しました。', 'error');
                    $button.prop('disabled', false);
                }
            });
        });
    });

    // ログをクリア
    $('#sge-clear-logs').on('click', function() {
        const $button = $(this);
        showConfirm('ログをクリアしますか？', function() {
            $button.prop('disabled', true);

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sge_clear_logs',
                    nonce: sgeData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                    } else {
                        showNotice(response.data.message, 'error');
                    }
                    $button.prop('disabled', false);
                },
                error: function() {
                    showNotice('エラーが発生しました。', 'error');
                    $button.prop('disabled', false);
                }
            });
        });
    });

    // Scheduled Actionsをリセット
    $('#sge-reset-scheduler').on('click', function() {
        const $button = $(this);
        showConfirm('Scheduled Actionsをリセットしますか？すべてのスケジュールされたタスクが削除されます。', function() {
            $button.prop('disabled', true).text('リセット中...');

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sge_reset_scheduler',
                    nonce: sgeData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                    } else {
                        showNotice(response.data.message, 'error');
                    }
                    $button.prop('disabled', false).text('Scheduled Actionsをリセット');
                },
                error: function() {
                    showNotice('エラーが発生しました。', 'error');
                    $button.prop('disabled', false).text('Scheduled Actionsをリセット');
                }
            });
        }, function() {
            // キャンセル時は何もしない
        });
    });

    // ログをダウンロード
    $('#sge-download-log').on('click', function() {
        console.log('ログダウンロードボタンがクリックされました');

        const $button = $(this);
        $button.prop('disabled', true).text('ダウンロード中...');

        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sge_download_log',
                nonce: sgeData.nonce
            },
            success: function(response) {
                console.log('AJAX成功:', response);

                if (response.success) {
                    try {
                        // テキストファイルとしてダウンロード
                        const blob = new Blob([response.data.log], { type: 'text/plain; charset=utf-8' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        console.log('ログのダウンロードが完了しました');
                    } catch (error) {
                        console.error('ダウンロード処理でエラー:', error);
                        showNotice('ダウンロード処理でエラーが発生しました: ' + error.message, 'error');
                    }
                } else {
                    console.error('サーバーエラー:', response.data.message);
                    showNotice(response.data.message || 'ログの取得に失敗しました。', 'error');
                }
                $button.prop('disabled', false).text('最新のログをダウンロード');
            },
            error: function(xhr, status, error) {
                console.error('AJAX エラー:', {xhr: xhr, status: status, error: error});
                showNotice('エラーが発生しました: ' + error + ' (ステータス: ' + status + ')', 'error');
                $button.prop('disabled', false).text('最新のログをダウンロード');
            }
        });
    });

    // 設定をエクスポート
    $('#sge-export-settings').on('click', function() {
        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sge_export_settings',
                nonce: sgeData.nonce
            },
            success: function(response) {
                if (response.success) {
                    const blob = new Blob([response.data.data], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'sge-settings.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert('エラーが発生しました。');
            }
        });
    });

    // 設定をインポート
    $('#sge-import-settings').on('click', function() {
        $('#sge-import-file').click();
    });

    $('#sge-import-file').on('change', function(e) {
        const file = e.target.files[0];
        if (!file) {
            return;
        }

        const reader = new FileReader();
        reader.onload = function(event) {
            const data = event.target.result;

            if (!confirm('設定をインポートしますか？現在の設定は上書きされます。')) {
                return;
            }

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'sge_import_settings',
                    nonce: sgeData.nonce,
                    data: data
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('エラーが発生しました。');
                }
            });
        };
        reader.readAsText(file);

        // ファイル選択をリセット
        $(this).val('');
    });

    // 初期表示時に進捗を読み込み（実行ページの場合）
    if ($('#sge-progress-bar').length > 0) {
        loadProgress();
        startProgressPolling();
    }

    // 初期化時に実行ボタンの状態を更新
    updateExecuteButton();

    // ページ読み込み時にGitHub設定が有効な場合、コミットメッセージにデフォルト値を設定
    if ($('#sge-github-enabled').is(':checked') && $('#sge-commit-message').length > 0) {
        const $commitMessage = $('#sge-commit-message');
        if (!$commitMessage.val()) {
            // デフォルトメッセージを設定
            $('#sge-reset-commit-message').trigger('click');
        }
    }

    // v2.0.0通知の無視ボタン処理（v1.4.2専用、v2.0.0で削除予定）
    $(document).on('click', '.sge-v2-notification .notice-dismiss', function(e) {
        e.preventDefault();

        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'sge_dismiss_v2_notification',
                nonce: sgeData.nonce
            },
            success: function(response) {
                // WordPress標準の処理で通知は既に非表示
            }
        });
    });
});
