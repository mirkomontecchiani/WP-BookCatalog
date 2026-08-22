/**
 * WP Book Catalog - Admin JavaScript (ISBN lookup / autofill)
 *
 * @package WP_Book_Catalog
 */

(function($) {
    'use strict';

    var strings = (window.wpbc_admin && wpbc_admin.strings) || {};
    var fieldNames = strings.fields || {};

    /**
     * Substitute the single %s placeholder of a translated string
     */
    function format(template, value) {
        return String(template).replace('%s', value);
    }

    /**
     * Show a notice inside the meta box result area
     */
    function showNotice(type, message) {
        var $area = $('#wpbc-isbn-lookup-result');
        $area
            .removeClass('notice-success notice-error notice-info')
            .addClass('notice notice-' + type)
            .html('<p>' + message + '</p>')
            .show();
    }

    /**
     * Fill an input only when it is currently empty
     */
    function fillIfEmpty(selector, value) {
        var $field = $(selector);
        if (!$field.length || !value) {
            return false;
        }
        if ($.trim(String($field.val())) !== '') {
            return false;
        }
        $field.val(value).trigger('change');
        return true;
    }

    /**
     * Whether the block editor (Gutenberg) is active on this screen
     */
    function isBlockEditor() {
        return !!(window.wp && wp.data && wp.data.select && wp.data.select('core/editor'));
    }

    /**
     * Set the post title when it is empty
     */
    function maybeSetTitle(title) {
        if (!title) {
            return false;
        }
        if (isBlockEditor()) {
            var current = wp.data.select('core/editor').getEditedPostAttribute('title');
            if (!current) {
                wp.data.dispatch('core/editor').editPost({ title: title });
                return true;
            }
            return false;
        }
        var $title = $('#title');
        if ($title.length && $.trim(String($title.val())) === '') {
            $title.val(title);
            $('#title-prompt-text').addClass('screen-reader-text');
            return true;
        }
        return false;
    }

    /**
     * Set the post content (description) when the editor is empty
     */
    function maybeSetDescription(description) {
        if (!description) {
            return false;
        }
        if (isBlockEditor()) {
            var content = wp.data.select('core/editor').getEditedPostAttribute('content');
            if (!content || $.trim(content) === '') {
                var paragraphs = description.split(/\n{2,}/).map(function(p) {
                    return '<!-- wp:paragraph --><p>' + escapeHtml(p) + '</p><!-- /wp:paragraph -->';
                }).join('');
                wp.data.dispatch('core/editor').editPost({ content: paragraphs });
                return true;
            }
            return false;
        }
        // Classic editor: TinyMCE visual mode or the plain textarea.
        if (window.tinymce && tinymce.get('content') && !tinymce.get('content').isHidden()) {
            var editor = tinymce.get('content');
            if ($.trim(editor.getContent({ format: 'text' })) === '') {
                editor.setContent('<p>' + escapeHtml(description).replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br />') + '</p>');
                return true;
            }
            return false;
        }
        var $content = $('#content');
        if ($content.length && $.trim(String($content.val())) === '') {
            $content.val(description);
            return true;
        }
        return false;
    }

    /**
     * Escape HTML special characters
     */
    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/`/g, '&#096;');
    }

    /**
     * Render the cover preview with an import button
     */
    function renderCoverPreview(coverUrl) {
        var $wrap = $('#wpbc-cover-preview');
        if (!$wrap.length || !coverUrl) {
            return;
        }
        $wrap.html(
            '<img src="' + escapeHtml(coverUrl) + '" alt="" />' +
            '<button type="button" class="button" id="wpbc-import-cover" data-url="' + escapeHtml(coverUrl) + '">' +
            escapeHtml(strings.use_as_cover || 'Use as cover') +
            '</button>'
        ).show();
    }

    /**
     * ISBN lookup button handler
     */
    $(document).on('click', '#wpbc-isbn-lookup-btn', function() {
        var $btn = $(this);
        var isbn = $.trim(String($('#wpbc_isbn').val()));

        if (!isbn) {
            showNotice('error', escapeHtml(strings.enter_isbn || 'Please enter an ISBN first.'));
            return;
        }

        $btn.prop('disabled', true);
        $('#wpbc-isbn-spinner').addClass('is-active');
        $('#wpbc-cover-preview').hide().empty();

        $.post(wpbc_admin.ajax_url, {
            action: 'wpbc_isbn_lookup',
            nonce: wpbc_admin.nonce,
            isbn: isbn
        }).done(function(response) {
            if (!response || !response.success) {
                var msg = (response && response.data && response.data.message) ? response.data.message : (strings.lookup_failed || 'Lookup failed.');
                showNotice('error', escapeHtml(msg));
                return;
            }

            var data = response.data;
            var filled = [];

            if (fillIfEmpty('#wpbc_author', data.authors)) { filled.push(fieldNames.author || 'author'); }
            if (fillIfEmpty('#wpbc_publisher', data.publisher)) { filled.push(fieldNames.publisher || 'publisher'); }
            if (fillIfEmpty('#wpbc_year', data.year)) { filled.push(fieldNames.year || 'year'); }
            if (fillIfEmpty('#wpbc_pages', data.pages)) { filled.push(fieldNames.pages || 'pages'); }
            if (fillIfEmpty('#wpbc_language', data.language)) { filled.push(fieldNames.language || 'language'); }
            if (maybeSetTitle(data.title)) { filled.push(fieldNames.title || 'title'); }
            if (maybeSetDescription(data.description)) { filled.push(fieldNames.description || 'description'); }

            var separator = strings.separator || ', ';
            var message;
            if (filled.length) {
                message = escapeHtml(format(strings.filled_fields || 'Fields filled: %s', filled.join(separator)));
            } else {
                message = escapeHtml(strings.nothing_filled || 'No empty fields to fill. Existing values were kept.');
            }

            var sources = (data.sources || []).join(separator);
            if (sources) {
                message += ' <em>(' + escapeHtml(format(strings.source || 'Source: %s', sources)) + ')</em>';
            }
            showNotice('success', message);

            renderCoverPreview(data.cover);
        }).fail(function() {
            showNotice('error', escapeHtml(strings.lookup_failed || 'Lookup failed. Please try again.'));
        }).always(function() {
            $btn.prop('disabled', false);
            $('#wpbc-isbn-spinner').removeClass('is-active');
        });
    });

    /**
     * Cover import button handler
     */
    $(document).on('click', '#wpbc-import-cover', function() {
        var $btn = $(this);
        var url = $btn.data('url');
        var postId = $('#post_ID').val();

        if (!url || !postId) {
            return;
        }

        $btn.prop('disabled', true).text(strings.importing || 'Importing…');

        $.post(wpbc_admin.ajax_url, {
            action: 'wpbc_import_cover',
            nonce: wpbc_admin.nonce,
            post_id: postId,
            url: url
        }).done(function(response) {
            if (!response || !response.success) {
                var msg = (response && response.data && response.data.message) ? response.data.message : (strings.import_failed || 'Import failed.');
                showNotice('error', escapeHtml(msg));
                $btn.prop('disabled', false).text(strings.use_as_cover || 'Use as cover');
                return;
            }

            if (isBlockEditor()) {
                wp.data.dispatch('core/editor').editPost({ featured_media: response.data.attachment_id });
            }

            showNotice('success', escapeHtml(response.data.message || strings.cover_imported || 'Cover imported.'));
            $btn.remove();

            if (!isBlockEditor()) {
                // Classic editor: reload the featured image meta box on next save;
                // show the imported thumbnail in the preview meanwhile.
                $('#wpbc-cover-preview img').attr('src', response.data.thumbnail_url);
            }
        }).fail(function() {
            showNotice('error', escapeHtml(strings.import_failed || 'Import failed. Please try again.'));
            $btn.prop('disabled', false).text(strings.use_as_cover || 'Use as cover');
        });
    });

})(jQuery);
