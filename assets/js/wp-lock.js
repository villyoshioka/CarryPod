/**
 * Carry Pod - WP admin screen lock
 * Disables state-changing operations in WP admin while CP static generation is running
 */

(function($) {
    'use strict';

    if (typeof cpLockData === 'undefined') {
        return;
    }

    var pollInterval = null;
    var hook = cpLockData.hook;
    var locked = false;

    function checkRunningStatus() {
        $.ajax({
            url: cpLockData.ajaxUrl,
            type: 'POST',
            data: { action: 'cp_is_running' },
            success: function(response) {
                if (!response.success) return;

                if (response.data.is_running && !locked) {
                    lockAll();
                    locked = true;
                } else if (!response.data.is_running && locked) {
                    unlockAll();
                    locked = false;
                }
            }
        });
    }

    function startPolling() {
        if (pollInterval) return;
        pollInterval = setInterval(checkRunningStatus, 5000);
    }

    /**
     * Lock the post edit screen (Gutenberg)
     */
    function lockGutenberg() {
        if (typeof wp === 'undefined' || !wp.data || !wp.data.dispatch) return false;

        var editor = wp.data.dispatch('core/editor');
        if (!editor || !editor.lockPostSaving) return false;

        wp.domReady(function() {
            wp.data.dispatch('core/editor').lockPostSaving('cp-running');
        });
        return true;
    }

    function unlockGutenberg() {
        if (typeof wp === 'undefined' || !wp.data || !wp.data.dispatch) return;

        var editor = wp.data.dispatch('core/editor');
        if (editor && editor.unlockPostSaving) {
            editor.unlockPostSaving('cp-running');
        }
    }

    /**
     * Lock the post edit screen (Classic Editor)
     */
    function lockClassicEditor() {
        $('#publish, #save-post').prop('disabled', true);
        $('.edit-post-status, .edit-visibility, .edit-timestamp').hide();
    }

    function unlockClassicEditor() {
        $('#publish, #save-post').prop('disabled', false);
        $('.edit-post-status, .edit-visibility, .edit-timestamp').show();
    }

    /**
     * Lock the themes screen
     */
    function lockThemes() {
        disableActivateButtons();

        $(document).on('click.cplock', '.theme', function() {
            setTimeout(disableActivateButtons, 100);
        });
    }

    function disableActivateButtons() {
        $('.theme-actions .activate, .button.activate').each(function() {
            var $btn = $(this);
            if (!$btn.hasClass('cp-locked')) {
                $btn.addClass('cp-locked');
                $btn.css({ opacity: 0.5, cursor: 'default' });
                $btn.on('click.cplock', function(e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                });
            }
        });
    }

    function unlockThemes() {
        $('.cp-locked').each(function() {
            $(this).removeClass('cp-locked').off('click.cplock').css({ opacity: '', cursor: '' });
        });
        $(document).off('click.cplock');
    }

    /**
     * Lock the Customizer
     */
    function lockCustomizer() {
        $('#save').prop('disabled', true);
    }

    function unlockCustomizer() {
        $('#save').prop('disabled', false);
    }

    /**
     * Lock the menu edit screen
     */
    function lockMenus() {
        $('#save_menu_header, #save_menu_footer').prop('disabled', true);
    }

    function unlockMenus() {
        $('#save_menu_header, #save_menu_footer').prop('disabled', false);
    }

    /**
     * Lock the widgets screen
     */
    function lockWidgets() {
        $('.widget-control-save').prop('disabled', true);
        $('.edit-widgets-header__actions button').prop('disabled', true);
    }

    function unlockWidgets() {
        $('.widget-control-save').prop('disabled', false);
        $('.edit-widgets-header__actions button').prop('disabled', false);
    }

    /**
     * Lock the settings screens (Permalinks, General)
     */
    function lockOptions() {
        $('#submit').prop('disabled', true);
    }

    function unlockOptions() {
        $('#submit').prop('disabled', false);
    }

    /**
     * Apply the lock for the current screen type
     */
    function lockAll() {
        switch (hook) {
            case 'post.php':
            case 'post-new.php':
                if (!lockGutenberg()) {
                    lockClassicEditor();
                }
                break;
            case 'themes.php':
                lockThemes();
                break;
            case 'customize.php':
                lockCustomizer();
                break;
            case 'nav-menus.php':
                lockMenus();
                break;
            case 'widgets.php':
                lockWidgets();
                break;
            case 'options-permalink.php':
            case 'options-general.php':
                lockOptions();
                break;
        }
    }

    /**
     * Release the lock for the current screen type
     */
    function unlockAll() {
        switch (hook) {
            case 'post.php':
            case 'post-new.php':
                unlockGutenberg();
                unlockClassicEditor();
                break;
            case 'themes.php':
                unlockThemes();
                break;
            case 'customize.php':
                unlockCustomizer();
                break;
            case 'nav-menus.php':
                unlockMenus();
                break;
            case 'widgets.php':
                unlockWidgets();
                break;
            case 'options-permalink.php':
            case 'options-general.php':
                unlockOptions();
                break;
        }
    }

    $(function() {
        if (cpLockData.isRunning) {
            lockAll();
            locked = true;
        }
        startPolling();
    });

})(jQuery);
