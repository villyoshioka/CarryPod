/**
 * Carry Pod 管理画面JavaScript
 * Updated: 2025-12-22 - Footer positioning fix
 */

jQuery(document).ready(function($) {
    'use strict';

    let progressPollInterval = null;
    // 未保存の変更フラグ
    let hasUnsavedChanges = false;

    // HTMLエスケープ関数（XSS対策）
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // 通知表示用のヘルパー関数
    function showNotice(message, type = 'success') {
        // 既存の通知を削除
        $('.cp-notice').remove();

        // 通知タイプのクラス名を決定
        const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';

        // メッセージをエスケープしてXSS対策
        const safeMessage = escapeHtml(message);

        // 通知HTMLを作成
        const $notice = $('<div class="notice cp-notice ' + noticeClass + ' is-dismissible">' +
            '<p>' + safeMessage + '</p>' +
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">この通知を無視</span>' +
            '</button>' +
            '</div>');

        // 通知を追加
        if ($('.wrap h1').length > 0) {
            $('.wrap h1').first().after($notice);
        } else {
            $('.cp-admin-wrap').prepend($notice);
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
        $('.cp-confirm-dialog').remove();

        // ダイアログHTMLを作成
        const $dialog = $('<div class="cp-confirm-dialog">' +
            '<div class="cp-confirm-overlay"></div>' +
            '<div class="cp-confirm-box">' +
            '<h3>確認</h3>' +
            '<p>' + message + '</p>' +
            '<div class="cp-confirm-buttons">' +
            '<button class="button button-primary cp-confirm-yes">はい</button>' +
            '<button class="button cp-confirm-no">いいえ</button>' +
            '</div>' +
            '</div>' +
            '</div>');

        // ダイアログを追加
        $('body').append($dialog);

        // はいボタンのイベント
        $dialog.find('.cp-confirm-yes').on('click', function() {
            $dialog.remove();
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        });

        // いいえボタンのイベント
        $dialog.find('.cp-confirm-no, .cp-confirm-overlay').on('click', function() {
            $dialog.remove();
            if (typeof onCancel === 'function') {
                onCancel();
            }
        });
    }

    // チェックボックスの連動
    $('#cp-github-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-github-settings').slideDown();
        } else {
            $('#cp-github-settings').slideUp();
        }
        updateExecuteButton();
    });

    $('#cp-git-local-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-git-local-settings').slideDown();
        } else {
            $('#cp-git-local-settings').slideUp();
        }
        updateExecuteButton();
    });

    $('#cp-local-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-local-settings').slideDown();
        } else {
            $('#cp-local-settings').slideUp();
        }
        updateExecuteButton();
    });

    // ZIP出力チェックボックスの連動
    $('#cp-zip-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-zip-settings').slideDown();
        } else {
            $('#cp-zip-settings').slideUp();
        }
        updateExecuteButton();
    });

    // Cloudflare出力チェックボックスの連動
    $('#cp-cloudflare-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-cloudflare-settings').slideDown();
        } else {
            $('#cp-cloudflare-settings').slideUp();
        }
        updateExecuteButton();
    });

    // GitLab出力チェックボックスの連動
    $('#cp-gitlab-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-gitlab-settings').slideDown();
        } else {
            $('#cp-gitlab-settings').slideUp();
        }
        updateExecuteButton();
    });

    // Netlify出力チェックボックスの連動
    $('#cp-netlify-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-netlify-settings').slideDown();
        } else {
            $('#cp-netlify-settings').slideUp();
        }
        updateExecuteButton();
    });

    // ブランチモードのラジオボタン連動（GitHub）
    const $branchModeRadio = $('input[name="github_branch_mode"]');
    if ($branchModeRadio.length > 0) {
        $branchModeRadio.on('change', function() {
            const mode = $(this).val();
            if (mode === 'existing') {
                $('#cp-github-existing-branch').prop('disabled', false);
                $('#cp-github-new-branch, #cp-github-base-branch').prop('disabled', true);
            } else {
                $('#cp-github-existing-branch').prop('disabled', true);
                $('#cp-github-new-branch, #cp-github-base-branch').prop('disabled', false);
            }
        });
    }

    // ブランチモードのラジオボタン連動（GitLab）
    const $gitlabBranchModeRadio = $('input[name="gitlab_branch_mode"]');
    if ($gitlabBranchModeRadio.length > 0) {
        $gitlabBranchModeRadio.on('change', function() {
            const mode = $(this).val();
            if (mode === 'existing') {
                $('#cp-gitlab-existing-branch').prop('disabled', false);
                $('#cp-gitlab-new-branch, #cp-gitlab-base-branch').prop('disabled', true);
            } else {
                $('#cp-gitlab-existing-branch').prop('disabled', true);
                $('#cp-gitlab-new-branch, #cp-gitlab-base-branch').prop('disabled', false);
            }
        });
    }

    // 実行ボタンの有効/無効を更新
    function updateExecuteButton() {
        const $githubCheckbox = $('#cp-github-enabled');
        const $gitLocalCheckbox = $('#cp-git-local-enabled');
        const $localCheckbox = $('#cp-local-enabled');
        const $gitlabCheckbox = $('#cp-gitlab-enabled');
        const $executeButton = $('#cp-execute-button');
        const $commitSection = $('.cp-commit-section');
        const $commitMessage = $('#cp-commit-message');

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

    /**
     * アコーディオン機能の初期化
     */
    function initAccordions() {
        const accordions = document.querySelectorAll('.cp-accordion-section');

        accordions.forEach(function(accordion) {
            const header = accordion.querySelector('.cp-accordion-header');
            const content = accordion.querySelector('.cp-accordion-content');
            const sectionId = accordion.dataset.section;

            if (!header || !content) return;

            // LocalStorageから状態を取得
            const savedState = getAccordionState(sectionId);
            const isExpanded = savedState !== null ? savedState : getDefaultState(sectionId);

            // 初期状態を設定（アニメーションなし）
            // トランジションを一時的に無効化
            content.classList.add('cp-no-transition');
            setAccordionState(header, content, isExpanded, true);

            // 次のフレームでトランジションを再有効化
            requestAnimationFrame(function() {
                content.classList.remove('cp-no-transition');
            });

            // クリックイベント
            header.addEventListener('click', function() {
                const currentState = header.getAttribute('aria-expanded') === 'true';
                const newState = !currentState;

                setAccordionState(header, content, newState);
                // LocalStorageへの保存はフォーム保存時のみ行う
            });

            // キーボード操作（Enter/Space）
            header.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    header.click();
                }
            });
        });
    }

    /**
     * アコーディオンの状態を設定
     * @param {boolean} noTransition - trueの場合、トランジションなしで即座に状態を変更
     */
    function setAccordionState(header, content, isExpanded, noTransition) {
        header.setAttribute('aria-expanded', isExpanded);
        content.setAttribute('aria-hidden', !isExpanded);

        if (noTransition) {
            // 初期表示時: トランジションなしで即座に状態を設定
            if (isExpanded) {
                content.style.display = 'block';
            } else {
                content.style.display = 'none';
            }
        } else {
            // ユーザー操作時: トランジションあり
            if (isExpanded) {
                content.style.display = 'block';
            } else {
                setTimeout(function() {
                    if (content.getAttribute('aria-hidden') === 'true') {
                        content.style.display = 'none';
                    }
                }, 200); // トランジション時間と合わせる
            }
        }
    }

    /**
     * デフォルトの開閉状態を取得
     */
    function getDefaultState(sectionId) {
        const defaultExpanded = ['output-destinations'];
        return defaultExpanded.includes(sectionId);
    }

    /**
     * LocalStorageから状態を取得
     */
    function getAccordionState(sectionId) {
        try {
            const states = localStorage.getItem('cp_accordion_states');
            if (!states) return null;

            const parsed = JSON.parse(states);
            return parsed[sectionId] !== undefined ? parsed[sectionId] : null;
        } catch (e) {
            console.error('LocalStorage読み込みエラー:', e);
            return null;
        }
    }

    /**
     * LocalStorageに状態を保存
     */
    function saveAccordionState(sectionId, isExpanded) {
        try {
            let states = {};
            const existing = localStorage.getItem('cp_accordion_states');

            if (existing) {
                states = JSON.parse(existing);
            }

            states[sectionId] = isExpanded;
            localStorage.setItem('cp_accordion_states', JSON.stringify(states));
        } catch (e) {
            console.error('LocalStorage保存エラー:', e);
        }
    }

    /**
     * すべてのアコーディオンの現在の状態をLocalStorageに保存
     */
    function saveAllAccordionStates() {
        try {
            const states = {};
            $('.cp-accordion-header').each(function() {
                // data-section属性は親の.cp-accordion-sectionにある
                const sectionId = $(this).closest('.cp-accordion-section').data('section');
                const isExpanded = $(this).attr('aria-expanded') === 'true';
                states[sectionId] = isExpanded;
            });
            localStorage.setItem('cp_accordion_states', JSON.stringify(states));
        } catch (e) {
            console.error('LocalStorage一括保存エラー:', e);
        }
    }

    // コミットメッセージ入力欄の監視
    $('#cp-commit-message').on('input change', function() {
        const $executeButton = $('#cp-execute-button');
        const $githubCheckbox = $('#cp-github-enabled');
        const $commitSection = $('.cp-commit-section');

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
    $('#cp-reset-commit-message').on('click', function() {
        const $commitMessage = $('#cp-commit-message');
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
    $('#cp-cancel-button').on('click', function(e) {
        e.preventDefault();

        const $button = $(this);

        // 確認ダイアログを表示
        showConfirm('静的化の実行を中止しますか？', function() {
            $button.prop('disabled', true).text('中止中...');

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'cp_cancel_generation',
                    nonce: sgeData.nonce
                },
                success: function(response) {
                    console.log('キャンセル成功:', response);
                    if (response.success) {
                        showNotice(response.data.message, 'success');

                        // ボタンを元に戻す
                        $('#cp-execute-button').prop('disabled', false).text('静的化を実行');
                        $('#cp-cancel-button').prop('disabled', true).hide();
                        $('#cp-download-log').prop('disabled', false);

                        // 進捗をリセット
                        $('#cp-progress-bar').css('width', '0%');
                        $('#cp-progress-percentage').text('0%');
                        $('#cp-progress-status').text('待機中...');

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
    $('#cp-execute-button').on('click', function(e) {
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

        const $commitMessage = $('#cp-commit-message');
        const githubEnabled = $('#cp-github-enabled').is(':checked');
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
                action: 'cp_execute_generation',
                nonce: sgeData.nonce,
                commit_message: commitMessage
            },
            success: function(response) {
                console.log('AJAX成功:', response);
                if (response.success) {
                    // 停止ボタンを表示
                    $('#cp-cancel-button').prop('disabled', false).show();

                    // 進捗のポーリングを開始
                    startProgressPolling();
                    // Action Schedulerのタスクが開始されるまで少し待機してから進捗チェック
                    setTimeout(function() {
                        loadProgress();
                    }, 500);
                } else {
                    console.error('サーバーエラー:', response.data);
                    showNotice(response.data.message || '静的化の実行に失敗しました。', 'error');
                    $('#cp-execute-button').prop('disabled', false).text('静的化を実行');
                    updateExecuteButton();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX エラー:', {xhr: xhr, status: status, error: error});
                showNotice('エラーが発生しました: ' + error, 'error');
                $('#cp-execute-button').prop('disabled', false).text('静的化を実行');
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
                action: 'cp_get_progress',
                nonce: sgeData.nonce
            },
            success: function(response) {
                console.log('進捗情報を取得:', response);
                if (response.success) {
                    const progress = response.data.progress;
                    const isRunning = response.data.is_running;
                    const $progressSection = $('.cp-progress-section');

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
                    $('#cp-progress-bar').css('width', progress.percentage + '%');
                    $('#cp-progress-percentage').text(progress.percentage + '%');

                    // 実行中でなければボタンを有効化
                    if (!isRunning) {
                        console.log('実行中ではないため、ボタンを有効化します');
                        $('#cp-execute-button').prop('disabled', false).text('静的化を実行');
                        $('#cp-cancel-button').prop('disabled', true).hide();
                        $('#cp-download-log').prop('disabled', false);
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
                                    action: 'cp_check_error_notification',
                                    nonce: sgeData.nonce
                                },
                                success: function(response) {
                                    if (response.success && response.data.has_error) {
                                        $('#cp-progress-status')
                                            .text('エラーが発生しました')
                                            .css('color', '#d63638');
                                        $('#cp-progress-bar').css('background-color', '#d63638');
                                    } else {
                                        $('#cp-progress-status').text('完了しました！');
                                    }
                                }
                            });
                        } else {
                            $('#cp-progress-status').text('待機中...');
                        }
                    } else {
                        // 実行中の場合は進捗セクションを表示
                        $progressSection.addClass('active');

                        // 実行中の場合はステータスメッセージと進捗を表示
                        let statusMessage = progress.status || '処理中...';
                        if (progress.current > 0 && progress.total > 0) {
                            statusMessage += ' (' + progress.current + ' / ' + progress.total + ' 完了)';
                        }
                        $('#cp-progress-status').text(statusMessage);
                        $('#cp-download-log').prop('disabled', true);
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
    $('#cp-settings-form').on('submit', function(e) {
        e.preventDefault();

        const formData = $(this).serialize();

        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: formData + '&action=cp_save_settings&nonce=' + sgeData.nonce,
            success: function(response) {
                if (response.success) {
                    // 保存成功時は未保存フラグをクリア
                    hasUnsavedChanges = false;
                    // 設定保存成功時にアコーディオンの状態をLocalStorageに保存
                    saveAllAccordionStates();

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
    $('#cp-reset-settings').on('click', function() {
        showConfirm('設定をリセットしますか？', function() {
            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'cp_reset_settings',
                    nonce: sgeData.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // リセット成功時は未保存フラグをクリア（リロード前に）
                        hasUnsavedChanges = false;
                        // 設定リセット時にアコーディオンの状態もリセット
                        localStorage.removeItem('cp_accordion_states');

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
    $('#cp-clear-cache').on('click', function() {
        const $button = $(this);
        showConfirm('キャッシュをクリアしますか？', function() {
            $button.prop('disabled', true);

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'cp_clear_cache',
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
    $('#cp-clear-logs').on('click', function() {
        const $button = $(this);
        showConfirm('ログをクリアしますか？', function() {
            $button.prop('disabled', true);

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'cp_clear_logs',
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
    $('#cp-reset-scheduler').on('click', function() {
        const $button = $(this);
        showConfirm('Scheduled Actionsをリセットしますか？すべてのスケジュールされたタスクが削除されます。', function() {
            $button.prop('disabled', true).text('リセット中...');

            $.ajax({
                url: sgeData.ajaxurl,
                type: 'POST',
                data: {
                    action: 'cp_reset_scheduler',
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
    $('#cp-download-log').on('click', function() {
        console.log('ログダウンロードボタンがクリックされました');

        const $button = $(this);
        $button.prop('disabled', true).text('ダウンロード中...');

        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_download_log',
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
    $('#cp-export-settings').on('click', function() {
        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_export_settings',
                nonce: sgeData.nonce
            },
            success: function(response) {
                if (response.success) {
                    const blob = new Blob([response.data.data], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'cp-settings.json';
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
    $('#cp-import-settings').on('click', function() {
        $('#cp-import-file').click();
    });

    $('#cp-import-file').on('change', function(e) {
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
                    action: 'cp_import_settings',
                    nonce: sgeData.nonce,
                    data: data
                },
                success: function(response) {
                    if (response.success) {
                        // インポート成功時は未保存フラグをクリア（リロード前に）
                        hasUnsavedChanges = false;
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
    if ($('#cp-progress-bar').length > 0) {
        loadProgress();
        startProgressPolling();
    }

    // アコーディオンを初期化
    initAccordions();

    // ========================================
    // 設定フォームの変更を監視（離脱確認用）
    // ========================================
    $('#cp-settings-form').on('change', 'input, textarea, select', function() {
        hasUnsavedChanges = true;
    });

    // ページ離脱時の確認ダイアログ
    $(window).on('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            const message = '変更が保存されていません。このページを離れますか？';
            e.returnValue = message;
            return message;
        }
    });

    // 初期化時に実行ボタンの状態を更新
    updateExecuteButton();

    // ページ読み込み時にGitHub設定が有効な場合、コミットメッセージにデフォルト値を設定
    if ($('#cp-github-enabled').is(':checked') && $('#cp-commit-message').length > 0) {
        const $commitMessage = $('#cp-commit-message');
        if (!$commitMessage.val()) {
            // デフォルトメッセージを設定
            $('#cp-reset-commit-message').trigger('click');
        }
    }

    // ========================================
    // ベースURL入力欄の表示/非表示制御
    // ========================================

    function toggleBaseUrlField() {
        const urlMode = $('input[name="url_mode"]:checked').val();
        const $baseUrlField = $('.cp-base-url-field');

        if (urlMode === 'absolute') {
            $baseUrlField.slideDown(200);
        } else {
            $baseUrlField.slideUp(200);
        }
    }

    // 初期表示時の制御
    toggleBaseUrlField();

    // URL形式の変更を監視
    $('input[name="url_mode"]').on('change', function() {
        toggleBaseUrlField();
    });
});
