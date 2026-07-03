/**
 * WP Book Catalog - Frontend JavaScript
 *
 * @package WP_Book_Catalog
 */

(function($) {
    'use strict';

    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function() {
        initShowAllButton();
        initTouchSupport();
    });

    /**
     * Initialize Show All button functionality
     */
    function initShowAllButton() {
        $(document).on('click', '.wpbc-show-all-btn', function(e) {
            e.preventDefault();

            var $button = $(this);
            var instanceId = $button.data('instance');
            var $container = $('#' + instanceId);
            var $grid = $container.find('.wpbc-grid');

            // Prevent multiple clicks
            if ($button.hasClass('loading')) {
                return;
            }

            // Add loading state
            $button.addClass('loading').prop('disabled', true);
            $grid.attr('aria-busy', 'true');
            $container.find('.wpbc-load-error').remove();

            // AJAX request to load all books
            $.ajax({
                url: wpbc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbc_load_all_books',
                    nonce: wpbc_ajax.nonce,
                    orderby: $button.data('orderby'),
                    order: $button.data('order'),
                    genre: $button.data('genre')
                },
                success: function(response) {
                    if (response.success) {
                        // Replace grid content with all books
                        $grid.html(response.data.html).attr('aria-busy', 'false');

                        // Add fade-in animation to new items
                        $grid.find('.wpbc-book-item').each(function(index) {
                            var $item = $(this);
                            setTimeout(function() {
                                $item.addClass('wpbc-fade-in');
                            }, index * 50);
                        });

                        // Move keyboard focus to the first newly revealed book.
                        // Both <a> and <div tabindex="0"> cards are already focusable,
                        // so focus without overriding tabindex (which would drop the
                        // element from the Tab order).
                        $grid.find('.wpbc-book-link').first().trigger('focus');

                        // Remove the button wrapper
                        $button.closest('.wpbc-show-all-wrapper').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        showLoadError($button);
                    }
                },
                error: function() {
                    showLoadError($button);
                }
            });
        });
    }

    /**
     * Show an error message under the Show All button and reset it
     */
    function showLoadError($button) {
        $button.removeClass('loading').prop('disabled', false);
        $button.closest('.wpbc-container').find('.wpbc-grid').attr('aria-busy', 'false');
        $('<p class="wpbc-load-error" role="alert"></p>')
            .text((window.wpbc_ajax && wpbc_ajax.error_text) || 'Error loading books.')
            .insertAfter($button);
    }

    /**
     * Initialize touch support for mobile devices
     */
    function initTouchSupport() {
        // Use the same signal the CSS uses (@media (hover: none)) so hover-capable
        // touch laptops keep the single-click + :hover path instead of needing two taps.
        var noHover = window.matchMedia && window.matchMedia('(hover: none)').matches;

        if (!noHover) {
            return;
        }

        // Add touch class to body
        $('body').addClass('wpbc-touch-device');

        // First tap reveals the overlay; the second tap follows the link.
        $(document).on('click', '.wpbc-book-link', function(e) {
            var $link = $(this);
            var $overlay = $link.find('.wpbc-book-overlay');

            if ($overlay.hasClass('wpbc-visible')) {
                // Overlay already open: links navigate, plain cards close it.
                if (!$link.is('a')) {
                    $overlay.removeClass('wpbc-visible');
                }
                return;
            }

            e.preventDefault();

            // Hide all other overlays first
            $('.wpbc-book-overlay.wpbc-visible').removeClass('wpbc-visible');
            $overlay.addClass('wpbc-visible');
        });

        // Close overlay when tapping outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.wpbc-book-item').length) {
                $('.wpbc-book-overlay.wpbc-visible').removeClass('wpbc-visible');
            }
        });
    }

})(jQuery);
