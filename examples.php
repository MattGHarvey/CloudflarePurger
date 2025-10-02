<?php
/**
 * Cloudflare Purger - Usage Examples
 * 
 * This file demonstrates how to programmatically interact with the Cloudflare Purger plugin.
 * These examples can be used in themes, other plugins, or custom functionality.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Example 1: Check if the plugin is active and configured
 */
function my_theme_cloudflare_check() {
    if (class_exists('CloudflarePurger')) {
        $purger = CloudflarePurger::get_instance();
        
        if ($purger->is_configured()) {
            // Plugin is configured and ready to use
            return true;
        } else {
            // Plugin exists but needs configuration
            error_log('Cloudflare Purger is installed but not configured');
            return false;
        }
    }
    
    // Plugin is not active
    return false;
}

/**
 * Example 2: Manually trigger purge for specific URLs
 * Useful for custom post types or when you update content outside WordPress
 */
function my_custom_purge_urls($urls) {
    if (!my_theme_cloudflare_check()) {
        return false;
    }
    
    $purger = CloudflarePurger::get_instance();
    
    // Ensure URLs is an array
    if (!is_array($urls)) {
        $urls = array($urls);
    }
    
    // Purge the URLs
    return $purger->purge_cloudflare_cache($urls, 'custom_function');
}

/**
 * Example 3: Purge images when custom post types are saved
 * Extend the plugin's functionality to work with custom post types
 */
function my_custom_post_type_purge($post_id) {
    // Only run for your custom post type
    if (get_post_type($post_id) !== 'my_custom_gallery') {
        return;
    }
    
    if (!my_theme_cloudflare_check()) {
        return;
    }
    
    $purger = CloudflarePurger::get_instance();
    
    // You can reuse the plugin's purge functionality
    $purger->purge_post_images($post_id);
    
    // Or create custom purge logic
    $custom_image_urls = get_post_meta($post_id, 'gallery_images', true);
    if (!empty($custom_image_urls)) {
        $purger->purge_cloudflare_cache($custom_image_urls, 'custom_gallery');
    }
}
add_action('save_post', 'my_custom_post_type_purge');

/**
 * Example 4: Purge cache when WooCommerce products are updated
 */
function my_woocommerce_product_purge($product_id) {
    if (!my_theme_cloudflare_check()) {
        return;
    }
    
    $purger = CloudflarePurger::get_instance();
    
    // Get product images
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }
    
    $urls_to_purge = array();
    
    // Main product image
    $main_image_id = $product->get_image_id();
    if ($main_image_id) {
        $main_image_url = wp_get_attachment_url($main_image_id);
        if ($main_image_url) {
            $urls_to_purge[] = $main_image_url;
        }
    }
    
    // Gallery images
    $gallery_ids = $product->get_gallery_image_ids();
    foreach ($gallery_ids as $gallery_id) {
        $gallery_url = wp_get_attachment_url($gallery_id);
        if ($gallery_url) {
            $urls_to_purge[] = $gallery_url;
        }
    }
    
    if (!empty($urls_to_purge)) {
        $purger->purge_cloudflare_cache($urls_to_purge, 'woocommerce_product');
    }
}
add_action('woocommerce_update_product', 'my_woocommerce_product_purge');

/**
 * Example 5: Bulk purge multiple posts
 * Useful for batch operations or maintenance tasks
 */
function my_bulk_purge_posts($post_ids) {
    if (!my_theme_cloudflare_check()) {
        return false;
    }
    
    $purger = CloudflarePurger::get_instance();
    $all_urls = array();
    
    foreach ($post_ids as $post_id) {
        // Get all images for this post
        $attachments = get_attached_media('image', $post_id);
        foreach ($attachments as $attachment) {
            $url = wp_get_attachment_url($attachment->ID);
            if ($url) {
                $all_urls[] = $url;
                
                // Also get image size variants
                $image_sizes = get_intermediate_image_sizes();
                foreach ($image_sizes as $size) {
                    $image_data = wp_get_attachment_image_src($attachment->ID, $size);
                    if ($image_data && $image_data[0]) {
                        $all_urls[] = $image_data[0];
                    }
                }
            }
        }
    }
    
    // Remove duplicates
    $all_urls = array_unique($all_urls);
    
    if (!empty($all_urls)) {
        return $purger->purge_cloudflare_cache($all_urls, 'bulk_posts');
    }
    
    return false;
}

/**
 * Example 6: Conditional purging based on post category
 * Only purge cache for posts in specific categories
 */
function my_conditional_purge($post_id) {
    // Only purge for posts in the 'featured' category
    if (!has_category('featured', $post_id)) {
        return;
    }
    
    if (!my_theme_cloudflare_check()) {
        return;
    }
    
    $purger = CloudflarePurger::get_instance();
    $purger->purge_post_images($post_id);
}
add_action('save_post', 'my_conditional_purge', 25); // Run after the main plugin

/**
 * Example 7: Integration with Advanced Custom Fields (ACF)
 * Purge cache when ACF image fields are updated
 */
function my_acf_image_purge($post_id) {
    if (!my_theme_cloudflare_check()) {
        return;
    }
    
    // Check if ACF is active
    if (!function_exists('get_field')) {
        return;
    }
    
    $purger = CloudflarePurger::get_instance();
    $urls_to_purge = array();
    
    // Get ACF image fields (adjust field names as needed)
    $hero_image = get_field('hero_image', $post_id);
    $gallery = get_field('image_gallery', $post_id);
    
    // Process hero image
    if ($hero_image && is_array($hero_image)) {
        $urls_to_purge[] = $hero_image['url'];
        
        // Get size variants if available
        if (isset($hero_image['sizes'])) {
            foreach ($hero_image['sizes'] as $size_url) {
                $urls_to_purge[] = $size_url;
            }
        }
    }
    
    // Process gallery
    if ($gallery && is_array($gallery)) {
        foreach ($gallery as $image) {
            if (is_array($image) && isset($image['url'])) {
                $urls_to_purge[] = $image['url'];
                
                if (isset($image['sizes'])) {
                    foreach ($image['sizes'] as $size_url) {
                        $urls_to_purge[] = $size_url;
                    }
                }
            }
        }
    }
    
    $urls_to_purge = array_unique(array_filter($urls_to_purge));
    
    if (!empty($urls_to_purge)) {
        $purger->purge_cloudflare_cache($urls_to_purge, 'acf_fields');
    }
}
add_action('acf/save_post', 'my_acf_image_purge', 20);

/**
 * Example 8: Scheduled purge operations
 * Set up a cron job to purge cache at specific times
 */
function my_setup_scheduled_purge() {
    if (!wp_next_scheduled('my_daily_cache_purge')) {
        wp_schedule_event(time(), 'daily', 'my_daily_cache_purge');
    }
}
add_action('wp', 'my_setup_scheduled_purge');

function my_daily_cache_purge() {
    if (!my_theme_cloudflare_check()) {
        return;
    }
    
    $purger = CloudflarePurger::get_instance();
    
    // Example: Purge specific static assets daily
    $static_assets = array(
        home_url('/wp-content/themes/mytheme/style.css'),
        home_url('/wp-content/themes/mytheme/script.js'),
        home_url('/favicon.ico'),
    );
    
    $purger->purge_cloudflare_cache($static_assets, 'scheduled_daily');
}
add_action('my_daily_cache_purge', 'my_daily_cache_purge');

/**
 * Example 9: Debug function to test purging
 * Useful for development and testing
 */
function my_test_cloudflare_purge() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    if (!my_theme_cloudflare_check()) {
        echo '<div class="notice notice-error"><p>Cloudflare Purger not configured</p></div>';
        return;
    }
    
    $purger = CloudflarePurger::get_instance();
    
    // Test with a simple image URL
    $test_url = home_url('/wp-content/uploads/test-image.jpg');
    $result = $purger->purge_cloudflare_cache(array($test_url), 'debug_test');
    
    if ($result) {
        echo '<div class="notice notice-success"><p>Test purge successful</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Test purge failed</p></div>';
    }
}

// Add to admin if needed for testing
if (is_admin() && isset($_GET['test_cf_purge'])) {
    add_action('admin_notices', 'my_test_cloudflare_purge');
}