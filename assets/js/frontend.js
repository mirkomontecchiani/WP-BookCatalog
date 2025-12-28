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

            // AJAX request to load all books
            $.ajax({
                url: wpbc_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wpbc_load_all_books',
                    nonce: wpbc_ajax.nonce,
                    columns: $button.data('columns'),
                    orderby: $button.data('orderby'),
                    order: $button.data('order')
                },
                success: function(response) {
                    if (response.success) {
                        // Replace grid content with all books
                        $grid.html(response.data.html);

                        // Add fade-in animation to new items
                        $grid.find('.wpbc-book-item').each(function(index) {
                            var $item = $(this);
                            setTimeout(function() {
                                $item.addClass('wpbc-fade-in');
                            }, index * 50);
                        });

                        // Remove the button wrapper
                        $button.closest('.wpbc-show-all-wrapper').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        console.error('WPBC Error:', response.data);
                        $button.removeClass('loading').prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('WPBC AJAX Error:', error);
                    $button.removeClass('loading').prop('disabled', false);
                }
            });
        });
    }

    /**
     * Initialize touch support for mobile devices
     */
    function initTouchSupport() {
        // Detect touch device
        var isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

        if (isTouchDevice) {
            // Add touch class to body
            $('body').addClass('wpbc-touch-device');

            // Handle tap to show overlay
            $(document).on('click', '.wpbc-book-cover', function(e) {
                var $cover = $(this);
                var $overlay = $cover.find('.wpbc-book-overlay');

                // If clicking on a button/link inside overlay, let it proceed
                if ($(e.target).closest('a, button').length) {
                    return;
                }

                // Toggle overlay visibility
                if ($overlay.hasClass('wpbc-visible')) {
                    $overlay.removeClass('wpbc-visible');
                } else {
                    // Hide all other overlays first
                    $('.wpbc-book-overlay.wpbc-visible').removeClass('wpbc-visible');
                    $overlay.addClass('wpbc-visible');
                }
            });

            // Close overlay when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.wpbc-book-item').length) {
                    $('.wpbc-book-overlay.wpbc-visible').removeClass('wpbc-visible');
                }
            });
        }
    }

    /**
     * Handle keyboard navigation for accessibility
     */
    $(document).on('keydown', '.wpbc-book-cover', function(e) {
        // Enter or Space key
        if (e.keyCode === 13 || e.keyCode === 32) {
            e.preventDefault();
            $(this).trigger('click');
        }
    });

    /**
     * Make book covers focusable for keyboard navigation
     */
    $(document).on('focus', '.wpbc-book-cover', function() {
        $(this).find('.wpbc-book-overlay').css('opacity', '1').css('visibility', 'visible');
    });

    $(document).on('blur', '.wpbc-book-cover', function() {
        $(this).find('.wpbc-book-overlay').css('opacity', '').css('visibility', '');
    });

})(jQuery);
