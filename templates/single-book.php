<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
</head>
<body <?php body_class('wpbc-single-book-page'); ?>>
<?php wp_body_open(); ?>

<?php
while (have_posts()) :
    the_post();

    $meta = WPBC_Meta_Boxes::get_book_meta(get_the_ID());
    $thumbnail = get_the_post_thumbnail_url(get_the_ID(), 'large');
    $description = get_the_content();

    // Fallback image
    if (!$thumbnail) {
        $thumbnail = WPBC_PLUGIN_URL . 'assets/images/no-cover.svg';
    }
?>

<div class="wpbc-single-container">
    <!-- Back Button -->
    <a href="javascript:history.back()" class="wpbc-back-link">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        <?php _e('Back', 'wp-book-catalog'); ?>
    </a>

    <div class="wpbc-single-content">
        <!-- Book Cover -->
        <div class="wpbc-single-cover">
            <img src="<?php echo esc_url($thumbnail); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" />
        </div>

        <!-- Book Details -->
        <div class="wpbc-single-details">
            <h1 class="wpbc-single-title"><?php the_title(); ?></h1>

            <?php if (!empty($meta['author'])) : ?>
                <p class="wpbc-single-author"><?php echo esc_html($meta['author']); ?></p>
            <?php endif; ?>

            <div class="wpbc-single-meta">
                <?php if (!empty($meta['publisher'])) : ?>
                    <div class="wpbc-meta-item">
                        <span class="wpbc-meta-label"><?php _e('Publisher', 'wp-book-catalog'); ?></span>
                        <span class="wpbc-meta-value"><?php echo esc_html($meta['publisher']); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($meta['isbn'])) : ?>
                    <div class="wpbc-meta-item">
                        <span class="wpbc-meta-label"><?php _e('ISBN', 'wp-book-catalog'); ?></span>
                        <span class="wpbc-meta-value"><?php echo esc_html($meta['isbn']); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($description)) : ?>
                <div class="wpbc-single-description">
                    <h2><?php _e('Description', 'wp-book-catalog'); ?></h2>
                    <div class="wpbc-description-content">
                        <?php echo wp_kses_post($description); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($meta['shop_link'])) : ?>
                <div class="wpbc-single-cta">
                    <a href="<?php echo esc_url($meta['shop_link']); ?>" class="wpbc-buy-button" target="_blank" rel="noopener noreferrer">
                        <?php _e('Buy This Book', 'wp-book-catalog'); ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endwhile; ?>

<?php wp_footer(); ?>
</body>
</html>
