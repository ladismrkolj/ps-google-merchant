/**
 * gmcfeedmanager - back office configuration screen behaviour.
 * Depends on jQuery (bundled with the PrestaShop back office) and the
 * gmcAjaxUrl / gmcAdminToken globals printed by configuration.tpl.
 */
(function ($) {
    'use strict';

    function ajaxUrl(action) {
        return gmcAjaxUrl + '&ajax=1&action=' + action + '&token=' + encodeURIComponent(gmcAdminToken);
    }

    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

    /* ------------------------------------------------------------------ */
    /* Feed URL: copy + regenerate                                        */
    /* ------------------------------------------------------------------ */

    function initFeedUrlControls() {
        $('#gmc-copy-feed-url').on('click', function () {
            var input = document.getElementById('gmc-feed-url');
            input.select();
            input.setSelectionRange(0, 99999);
            try {
                document.execCommand('copy');
            } catch (e) {
                // Clipboard API not available: selection alone lets the user Ctrl+C.
            }
        });

        $('#gmc-regenerate-token').on('click', function () {
            var message = $(this).data('confirm');
            if (window.confirm(message)) {
                $('#gmc-regenerate-token-form').submit();
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Category taxonomy typeahead                                        */
    /* ------------------------------------------------------------------ */

    function closeAllResultLists() {
        $('.gmc-typeahead-results').removeClass('gmc-open').empty();
    }

    function renderResults($wrapper, results) {
        var $results = $wrapper.find('.gmc-typeahead-results');
        $results.empty();

        if (!results.length) {
            $results.removeClass('gmc-open');
            return;
        }

        results.forEach(function (item) {
            var $row = $('<div class="gmc-typeahead-result"></div>')
                .attr('data-id', item.id)
                .attr('data-name', item.name)
                .html('<span class="gmc-cat-name"></span><span class="gmc-cat-id"></span>');
            $row.find('.gmc-cat-name').text(item.name);
            $row.find('.gmc-cat-id').text('#' + item.id);
            $results.append($row);
        });

        $results.addClass('gmc-open');
    }

    function initCategoryTypeahead() {
        var search = debounce(function ($input) {
            var term = $input.val();
            var $wrapper = $input.closest('.gmc-typeahead-wrapper');

            if (term.length < 2) {
                closeAllResultLists();
                return;
            }

            $.get(ajaxUrl('SearchGoogleCategory'), { term: term })
                .done(function (data) {
                    renderResults($wrapper, data || []);
                })
                .fail(function () {
                    renderResults($wrapper, []);
                });
        }, 300);

        $(document).on('input', '.gmc-category-search', function () {
            search($(this));
        });

        $(document).on('click', '.gmc-typeahead-result', function () {
            var $result = $(this);
            var $wrapper = $result.closest('.gmc-typeahead-wrapper');
            $wrapper.find('.gmc-category-search').val($result.data('name'));
            $wrapper.find('.gmc-category-id').val($result.data('id'));
            closeAllResultLists();
        });

        $(document).on('click', function (event) {
            if (!$(event.target).closest('.gmc-typeahead-wrapper').length) {
                closeAllResultLists();
            }
        });
    }

    function initCategoryMappingSave() {
        $(document).on('click', '.gmc-save-mapping', function () {
            var $button = $(this);
            var $row = $button.closest('tr');
            var $status = $row.find('.gmc-mapping-status');

            var payload = {
                id_category: $row.data('id-category'),
                google_category_id: $row.find('.gmc-category-id').val(),
                google_category_name: $row.find('.gmc-category-search').val()
            };

            $status.removeClass('gmc-ok gmc-error').html('<i class="icon-spinner icon-spin"></i>');

            $.post(ajaxUrl('SaveCategoryMapping'), payload)
                .done(function (response) {
                    if (response && response.success) {
                        $status.addClass('gmc-ok').html('<i class="icon-check"></i>');
                    } else {
                        $status.addClass('gmc-error').html('<i class="icon-close"></i>');
                    }
                })
                .fail(function () {
                    $status.addClass('gmc-error').html('<i class="icon-close"></i>');
                });
        });
    }

    /* ------------------------------------------------------------------ */
    /* Diagnostics                                                        */
    /* ------------------------------------------------------------------ */

    function setBadge(id, value) {
        var $badge = $('#' + id);
        $badge.text(value);
        $badge.toggleClass('gmc-zero', value === 0);
        $badge.toggleClass('gmc-nonzero', value > 0);
    }

    function runDiagnostics() {
        $('#gmc-diagnostics-loading').removeClass('gmc-hidden');

        $.get(ajaxUrl('RunDiagnostics'))
            .done(function (data) {
                if (!data) {
                    return;
                }
                setBadge('gmc-badge-missing-identifiers', data.missing_identifiers);
                setBadge('gmc-badge-unmapped-categories', data.unmapped_categories);
                setBadge('gmc-badge-missing-cover-image', data.missing_cover_image);
                setBadge('gmc-badge-out-of-stock', data.out_of_stock_missing_availability);
            })
            .always(function () {
                $('#gmc-diagnostics-loading').addClass('gmc-hidden');
            });
    }

    function initDiagnostics() {
        $('#gmc-run-diagnostics').on('click', runDiagnostics);
        $('#gmc-diagnostics-tab-link').one('shown.bs.tab click', function () {
            runDiagnostics();
        });
    }

    $(function () {
        if (typeof gmcAjaxUrl === 'undefined') {
            return;
        }

        initFeedUrlControls();
        initCategoryTypeahead();
        initCategoryMappingSave();
        initDiagnostics();
    });
})(jQuery);
