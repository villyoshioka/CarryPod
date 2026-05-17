/**
 * Carry Pod 管理画面JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';

    let progressPollInterval = null;
    let hasUnsavedChanges = false;
    var motionDuration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 120;
    var motionDurationLong = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 200;

    // HTMLエスケープ関数（XSS対策）
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showNotice(message, type = 'success') {
        $('.cp-notice').remove();

        var noticeClass = type === 'error' ? 'notice-error' : 'notice-success';

        var contentHtml;
        if (Array.isArray(message) && message.length > 1) {
            contentHtml = '<ul class="cp-notice-list">';
            message.forEach(function(msg) {
                contentHtml += '<li>' + escapeHtml(msg) + '</li>';
            });
            contentHtml += '</ul>';
        } else {
            var singleMsg = Array.isArray(message) ? message[0] : message;
            contentHtml = '<p>' + escapeHtml(singleMsg) + '</p>';
        }

        var $notice = $('<div class="notice cp-notice ' + noticeClass + ' is-dismissible">' +
            contentHtml +
            '<button type="button" class="notice-dismiss">' +
            '<span class="screen-reader-text">この通知を無視</span>' +
            '</button>' +
            '</div>');

        if ($('.wrap h1').length > 0) {
            $('.wrap h1').first().after($notice);
        } else {
            $('.nau-admin-wrap').prepend($notice);
        }

        var duration = type === 'error' ? 10000 : 5000;
        setTimeout(function() {
            $notice.fadeOut(motionDurationLong, function() {
                $(this).remove();
            });
        }, duration);

        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(motionDurationLong, function() {
                $(this).remove();
            });
        });

        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    function showConfirm(message, onConfirm, onCancel) {
        $('.nau-confirm-dialog').remove();

        const $dialog = $('<div class="nau-confirm-dialog">' +
            '<div class="nau-confirm-overlay"></div>' +
            '<div class="nau-confirm-box">' +
            '<h3>確認</h3>' +
            '<p>' + message + '</p>' +
            '<div class="nau-confirm-buttons">' +
            '<button class="button button-primary nau-confirm-yes">はい</button>' +
            '<button class="button nau-confirm-no">いいえ</button>' +
            '</div>' +
            '</div>' +
            '</div>');

        $('body').append($dialog);

        $dialog.find('.nau-confirm-yes').on('click', function() {
            $dialog.remove();
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        });

        $dialog.find('.nau-confirm-no, .nau-confirm-overlay').on('click', function() {
            $dialog.remove();
            if (typeof onCancel === 'function') {
                onCancel();
            }
        });
    }

    $('#cp-github-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-github-settings').slideDown(motionDurationLong);
        } else {
            $('#cp-github-settings').slideUp(motionDurationLong);
        }
        updateExecuteButton();
    });

    $('#cp-git-local-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-git-local-settings').slideDown(motionDurationLong);
        } else {
            $('#cp-git-local-settings').slideUp(motionDurationLong);
        }
        updateExecuteButton();
    });

    $('#cp-local-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-local-settings').slideDown(motionDurationLong);
        } else {
            $('#cp-local-settings').slideUp(motionDurationLong);
        }
        updateExecuteButton();
    });

    $('#cp-zip-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-zip-settings').slideDown(motionDurationLong);
        } else {
            $('#cp-zip-settings').slideUp(motionDurationLong);
        }
        updateExecuteButton();
    });

    $('#cp-zip-mode').on('change', function() {
        if ($(this).val() === 'local') {
            $('#cp-zip-local-settings').slideDown(motionDurationLong);
        } else {
            $('#cp-zip-local-settings').slideUp(motionDurationLong);
        }
    });

    $('#cp-cloudflare-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-cloudflare-settings').slideDown(motionDurationLong);
        } else {
            $('#cp-cloudflare-settings').slideUp(motionDurationLong);
        }
        updateExecuteButton();
    });

    $('input[name="auto_generate"]').on('change', function() {
        updateExecuteButton();
    });

    function updateCfGuide(noTransition) {
        var cfEnabled = $('#cp-cloudflare-enabled').is(':checked');
        var useWrangler = $('#cp-cloudflare-use-wrangler').val() === '1';
        var otherEnabled = $('#cp-github-enabled, #cp-gitlab-enabled, #cp-netlify-enabled, #cp-git-local-enabled, #cp-local-enabled, #cp-zip-enabled')
            .filter(':checked').length > 0;

        var $transformGuide = $('#cp-cf-transform-guide');

        if (cfEnabled && !useWrangler) {
            if (noTransition) { $transformGuide.show(); } else { $transformGuide.slideDown(motionDurationLong); }
        } else {
            if (noTransition) { $transformGuide.hide(); } else { $transformGuide.slideUp(motionDurationLong); }
        }

        // _headersチェックボックスグレーアウト: Workers（Direct Upload API）のみ有効時
        var $headersCheckbox = $('#cp-mati-headers');
        if ($headersCheckbox.length) {
            var workersOnlyDirectApi = cfEnabled && !useWrangler && !otherEnabled;
            if (workersOnlyDirectApi) {
                $headersCheckbox.prop('disabled', true).prop('checked', false);
            } else {
                $headersCheckbox.prop('disabled', false);
            }
            $headersCheckbox.closest('label').find('.nau-tooltip-trigger').toggleClass('disabled', workersOnlyDirectApi);
        }

        var $guide = $('#cp-wrangler-guide');
        if (cfEnabled && useWrangler && sgeData.wranglerInfo) {
            var data = sgeData.wranglerInfo;
            var html = '';

            if (!data.found) {
                html = '<details class="cp-guide-details"' + (getAccordionState('cf-wrangler-guide') ? ' open' : '') + '>' +
                    '<summary>Wranglerが見つかりません</summary>' +
                    '<div class="cp-guide-content">' +
                    '<p>サーバーにWrangler CLIが入っていないようです。</p>' +
                    '<p>以下のコマンドでインストールできます。</p>' +
                    '<pre><code>npm install -g wrangler</code></pre>' +
                    '<p class="description">インストールできない環境では「使用しない」に切り替えてください。</p>' +
                    '<p class="description"><a href="https://developers.cloudflare.com/workers/wrangler/install-and-update/" target="_blank" rel="noopener noreferrer">Wrangler公式ドキュメント →</a></p>' +
                    '</div></details>';
            } else if (data.needs_update) {
                html = '<details class="cp-guide-details"' + (getAccordionState('cf-wrangler-guide') ? ' open' : '') + '>' +
                    '<summary>Wranglerのアップデートが必要です</summary>' +
                    '<div class="cp-guide-content">' +
                    '<p>現在のバージョンは v' + escapeHtml(data.version) + ' です。</p>' +
                    '<p>v4以上へ更新してください。</p>' +
                    '<pre><code>npm install -g wrangler@latest</code></pre>' +
                    '</div></details>';
            }

            if (html) {
                $guide.html(html);
                syncWranglerGuideWidth();
                $guide.find('.cp-guide-details').on('toggle', function() {
                    saveAllAccordionStates();
                });
                $('#cp-cloudflare-use-wrangler').css({
                    'border-bottom-left-radius': '0',
                    'border-bottom-right-radius': '0'
                });
            } else {
                $guide.empty();
                $('#cp-cloudflare-use-wrangler').css({
                    'border-bottom-left-radius': '',
                    'border-bottom-right-radius': ''
                });
            }
        } else {
            $guide.empty();
            $('#cp-cloudflare-use-wrangler').css({
                'border-bottom-left-radius': '',
                'border-bottom-right-radius': ''
            });
        }
    }

    function syncWranglerGuideWidth() {
        var $select = $('#cp-cloudflare-use-wrangler');
        var $details = $('#cp-wrangler-guide .cp-guide-details');
        if ($details.length && $select.length) {
            $details.css('width', $select.outerWidth() + 'px');
        }
    }

    $(window).on('resize', syncWranglerGuideWidth);

    $('#cp-cloudflare-use-wrangler').on('change', function() {
        updateCfGuide(false);
    });

    $('#cp-cloudflare-enabled, #cp-github-enabled, #cp-gitlab-enabled, #cp-netlify-enabled, #cp-git-local-enabled, #cp-local-enabled, #cp-zip-enabled')
        .on('change', function() { updateCfGuide(false); });

    updateCfGuide(true);


    $('#cp-gitlab-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-gitlab-settings').slideDown(motionDurationLong);
        } else {
            $('#cp-gitlab-settings').slideUp(motionDurationLong);
        }
        updateExecuteButton();
    });

    $('#cp-netlify-enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#cp-netlify-settings').slideDown(motionDurationLong);
        } else {
            $('#cp-netlify-settings').slideUp(motionDurationLong);
        }
        updateExecuteButton();
    });

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

    function updateExecuteButton() {
        const $githubCheckbox = $('#cp-github-enabled');
        const $gitLocalCheckbox = $('#cp-git-local-enabled');
        const $localCheckbox = $('#cp-local-enabled');
        const $gitlabCheckbox = $('#cp-gitlab-enabled');
        const $executeButton = $('#cp-execute-button');
        const $commitSection = $('.cp-commit-section');
        const $commitMessage = $('#cp-commit-message');

        if ($githubCheckbox.length === 0 && $gitLocalCheckbox.length === 0 && $localCheckbox.length === 0) {
            if (sgeData.settingsError) {
                $executeButton.prop('disabled', true);
                return;
            }
            // 実行画面ではPHPで設定された初期状態を維持し、コミットメッセージの有無だけチェック
            if ($commitSection.hasClass('active')) {
                if ($commitMessage.val() && $commitMessage.val().trim() !== '') {
                    $executeButton.addClass('has-commit-message');
                } else {
                    $executeButton.removeClass('has-commit-message');
                }
            }
            return;
        }

        const githubEnabled = $githubCheckbox.is(':checked');
        const gitLocalEnabled = $gitLocalCheckbox.is(':checked');
        const localEnabled = $localCheckbox.is(':checked');
        const gitlabEnabled = $gitlabCheckbox.is(':checked');

        const autoGenerate = $('input[name="auto_generate"]').is(':checked');

        // Git出力が有効かつ自動実行が無効な場合はコミットメッセージセクションを表示
        if ((githubEnabled || gitLocalEnabled || gitlabEnabled) && !autoGenerate) {
            $commitSection.addClass('active');
            $executeButton.addClass('commit-required');

            if ($commitMessage.val() && $commitMessage.val().trim() !== '') {
                $executeButton.addClass('has-commit-message');
            } else {
                $executeButton.removeClass('has-commit-message');
            }
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
        const accordions = document.querySelectorAll('.nau-accordion-section');

        accordions.forEach(function(accordion) {
            const header = accordion.querySelector('.nau-accordion-header');
            const content = accordion.querySelector('.nau-accordion-content');
            const sectionId = accordion.dataset.section;

            if (!header || !content) return;

            const savedState = getAccordionState(sectionId);
            const isExpanded = savedState !== null ? savedState : getDefaultState(sectionId);

            // トランジションを一時的に無効化
            content.classList.add('nau-no-transition');
            setAccordionState(header, content, isExpanded, true);

            requestAnimationFrame(function() {
                content.classList.remove('nau-no-transition');
            });

            header.addEventListener('click', function() {
                const currentState = header.getAttribute('aria-expanded') === 'true';
                const newState = !currentState;

                setAccordionState(header, content, newState);
                // LocalStorageへの保存はフォーム保存時のみ行う
            });

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
        const $header = $(header);
        const $content = $(content);

        $header.attr('aria-expanded', isExpanded);
        $content.attr('aria-hidden', !isExpanded);

        if (noTransition) {
            if (isExpanded) {
                $content.show();
            } else {
                $content.hide();
            }
        } else {
            if (isExpanded) {
                $content.slideDown(motionDuration);
            } else {
                $content.slideUp(motionDuration);
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
            $('.nau-accordion-header').each(function() {
                const sectionId = $(this).closest('.nau-accordion-section').data('section');
                const isExpanded = $(this).attr('aria-expanded') === 'true';
                states[sectionId] = isExpanded;
            });
            // Wranglerガイドの<details>開閉状態
            var $wranglerGuideDetails = $('#cp-wrangler-guide .cp-guide-details');
            if ($wranglerGuideDetails.length) {
                states['cf-wrangler-guide'] = $wranglerGuideDetails.prop('open');
            }
            localStorage.setItem('cp_accordion_states', JSON.stringify(states));
        } catch (e) {
            console.error('LocalStorage一括保存エラー:', e);
        }
    }

    $('#cp-commit-message').on('input change', function() {
        const $executeButton = $('#cp-execute-button');
        const $githubCheckbox = $('#cp-github-enabled');
        const $commitSection = $('.cp-commit-section');

        if ($githubCheckbox.length === 0) {
            if ($commitSection.hasClass('active')) {
                if ($(this).val() && $(this).val().trim() !== '') {
                    $executeButton.addClass('has-commit-message');
                } else {
                    $executeButton.removeClass('has-commit-message');
                }
            }
            return;
        }

        const githubEnabled = $githubCheckbox.is(':checked');
        if (githubEnabled) {
            if ($(this).val() && $(this).val().trim() !== '') {
                $executeButton.addClass('has-commit-message');
            } else {
                $executeButton.removeClass('has-commit-message');
            }
            $executeButton.prop('disabled', false);
        }
    });

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

        updateExecuteButton();
    });

    $('#cp-cancel-button').on('click', function(e) {
        e.preventDefault();

        const $button = $(this);

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
                    if (response.success) {
                        showNotice(response.data.message, 'success');

                        $('#cp-execute-button').prop('disabled', false).text('静的化を実行');
                        $('#cp-cancel-button').prop('disabled', true).text('実行中止');
                        $('#cp-download-log').prop('disabled', false);

                        $('#cp-progress-bar').css('width', '0%');
                        $('#cp-progress-percentage').text('0%');
                        $('#cp-progress-status').text('待機中...');

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
        });
    });

    $('#cp-execute-button').on('click', function(e) {
        e.preventDefault();

        const $button = $(this);

        if ($button.prop('disabled')) {
            return false;
        }

        if (typeof sgeData === 'undefined') {
            console.error('sgeDataが未定義です。JavaScriptの読み込みに問題がある可能性があります。');
            showNotice('JavaScriptの読み込みエラーが発生しました。ページを再読み込みしてください。', 'error');
            return false;
        }

        const $commitMessage = $('#cp-commit-message');
        const githubEnabled = $('#cp-github-enabled').is(':checked');
        let commitMessage = '';

        // コミットメッセージが空でもサーバー側でデフォルト値が自動設定される
        if (githubEnabled && $commitMessage.length > 0) {
            commitMessage = $commitMessage.val();
        }

        $button.prop('disabled', true).text('静的化中...');
        $('#cp-import-settings').prop('disabled', true);
        $('#cp-reset-commit-message').prop('disabled', true);

        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_execute_generation',
                nonce: sgeData.nonce,
                commit_message: commitMessage
            },
            success: function(response) {
                if (response.success) {
                    $('#cp-cancel-button').prop('disabled', false).text('実行中止');

                    startProgressPolling();
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

    function loadProgress() {
        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: {
                action: 'cp_get_progress',
                nonce: sgeData.nonce
            },
            success: function(response) {
                if (response.success) {
                    const progress = response.data.progress;
                    const isRunning = response.data.is_running;
                    const $progressSection = $('.cp-progress-section');

                    if (isRunning || progress.total > 0) {
                        $progressSection.addClass('active');
                    }

                    $('#cp-progress-bar').css('width', progress.percentage + '%');
                    $('#cp-progress-percentage').text(progress.percentage + '%');

                    if (!isRunning) {
                        $('#cp-execute-button').prop('disabled', false).text('静的化を実行');
                        $('#cp-cancel-button').prop('disabled', true).text('実行中止');
                        $('#cp-download-log').prop('disabled', false);
                        $('#cp-import-settings').prop('disabled', false);
                        $('#cp-reset-commit-message').prop('disabled', false);
                        stopProgressPolling();

                        updateExecuteButton();

                        if (progress.percentage === 100 && progress.current === progress.total && progress.total > 0) {
                            if (response.data.zip_download_available) {
                                window.location.href = sgeData.ajaxurl + '?action=cp_download_zip&nonce=' + encodeURIComponent(sgeData.nonce);
                            }

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
                        $progressSection.addClass('active');

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

    function startProgressPolling() {
        if (progressPollInterval) {
            return;
        }
        progressPollInterval = setInterval(loadProgress, 1000); // 1秒間隔
    }

    function stopProgressPolling() {
        if (progressPollInterval) {
            clearInterval(progressPollInterval);
            progressPollInterval = null;
        }
    }

    $('#cp-settings-form').on('keydown', 'input:not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]), textarea, select', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
        }
    });

    $('#cp-settings-form').on('submit', function(e) {
        e.preventDefault();

        const formData = $(this).serialize();

        $.ajax({
            url: sgeData.ajaxurl,
            type: 'POST',
            data: formData + '&action=cp_save_settings&nonce=' + sgeData.nonce,
            success: function(response) {
                if (response.success) {
                    hasUnsavedChanges = false;
                    // アコーディオンの状態をLocalStorageに保存
                    saveAllAccordionStates();

                    showNotice(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showNotice(response.data.messages || response.data.message, 'error');
                }
            },
            error: function() {
                showNotice('エラーが発生しました。', 'error');
            }
        });
    });

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
                        hasUnsavedChanges = false;
                        // アコーディオンの状態もリセット
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

    $('#cp-reset-scheduler').on('click', function() {
        const $button = $(this);
        const confirmMsg = sgeData.isRunning
            ? '⚠ 静的化を実行中です。Scheduled Actionsをリセットすると実行中のタスクがすべて中断されます。本当にリセットしますか？'
            : 'Scheduled Actionsをリセットしますか？すべてのスケジュールされたタスクが削除されます。';
        showConfirm(confirmMsg, function() {
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
        });
    });

    $('#cp-download-log').on('click', function() {
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
                if (response.success) {
                    try {
                        const blob = new Blob([response.data.log], { type: 'text/plain; charset=utf-8' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
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

        $(this).val('');
    });

    if ($('#cp-progress-bar').length > 0) {
        loadProgress();
        startProgressPolling();
    }

    initAccordions();

    $('#cp-settings-form').on('change', 'input, textarea, select', function() {
        hasUnsavedChanges = true;
    });

    $(window).on('beforeunload', function(e) {
        if (hasUnsavedChanges) {
            const message = '変更が保存されていません。このページを離れますか？';
            e.returnValue = message;
            return message;
        }
    });

    updateExecuteButton();

    if ($('#cp-github-enabled').is(':checked') && $('#cp-commit-message').length > 0) {
        const $commitMessage = $('#cp-commit-message');
        if (!$commitMessage.val()) {
            $('#cp-reset-commit-message').trigger('click');
        }
    }

    $(document).on('click', '.nau-tooltip-trigger', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const $wrapper = $(this).closest('.nau-tooltip-wrapper');
        const isOpen = $wrapper.hasClass('show');

        $('.nau-tooltip-wrapper').removeClass('show');
        $('.nau-tooltip-trigger').attr('aria-expanded', 'false');

        if (!isOpen) {
            $wrapper.addClass('show');
            $(this).attr('aria-expanded', 'true');
        }
    });

    $(document).on('keydown', '.nau-tooltip-trigger', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $(this).trigger('click');
        } else if (e.key === 'Escape') {
            const $wrapper = $(this).closest('.nau-tooltip-wrapper');
            $wrapper.removeClass('show');
            $(this).attr('aria-expanded', 'false');
        }
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.nau-tooltip-wrapper').length) {
            $('.nau-tooltip-wrapper').removeClass('show');
            $('.nau-tooltip-trigger').attr('aria-expanded', 'false');
        }
    });

    $(document).on('blur', '.nau-tooltip-trigger', function(e) {
        setTimeout(() => {
            if (!$(document.activeElement).closest('.nau-tooltip-wrapper').length) {
                const $wrapper = $(this).closest('.nau-tooltip-wrapper');
                $wrapper.removeClass('show');
                $(this).attr('aria-expanded', 'false');
            }
        }, 100);
    });
});
