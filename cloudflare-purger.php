<?php
/**
 * Plugin Name: Cloudflare Purger
 * Plugin URI: https://github.com/MattGHarvey/CloudflarePurger
 * Description: Automatically purge Cloudflare cache when WordPress posts are saved, including all attached images and their size variants. Includes admin interface for manual cache management.
 * Version: 1.2.0
 * Author: Matt Harvey
 * Author URI: https://robotsprocket.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cloudflare-purger
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CLOUDFLARE_PURGER_VERSION', '1.0.0');
define('CLOUDFLARE_PURGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLOUDFLARE_PURGER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CLOUDFLARE_PURGER_PLUGIN_FILE', __FILE__);

/**
 * Main Cloudflare Purger Plugin Class
 */
class CloudflarePurger {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Plugin options
     */
    private $options;
    
    /**
     * Debug notices
     */
    private $debug_notices = array();
    
    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->options = get_option('cloudflare_purger_options', array());
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        // AJAX hooks for manual purging
        add_action('wp_ajax_cloudflare_purge_url', array($this, 'ajax_purge_url'));
        add_action('wp_ajax_cloudflare_purge_all', array($this, 'ajax_purge_all'));
        add_action('wp_ajax_cloudflare_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_cloudflare_purge_post', array($this, 'ajax_purge_post'));
        add_action('wp_ajax_cloudflare_purge_media', array($this, 'ajax_purge_media'));
        add_action('wp_ajax_cloudflare_get_image_variants', array($this, 'ajax_get_image_variants'));

        
        // Edit Media screen hooks
        add_action('add_meta_boxes_attachment', array($this, 'add_attachment_meta_box'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_attachment_scripts'));
        
        // Post save hooks for automatic purging
        add_action('save_post', array($this, 'on_post_save'), 20);
        add_action('cloudflare_purge_post_images', array($this, 'purge_post_images'), 10, 1);
        add_action('cloudflare_purge_media_delayed', array($this, 'purge_media_cache_delayed'), 10, 1);
        
        // Media replacement hooks for automatic purging
        add_action('emr_replaced_attachment', array($this, 'on_media_replaced'), 10, 2); // Enable Media Replace
        add_action('wp_media_replace_uploaded', array($this, 'on_media_replaced_wp_media_replace'), 10, 1); // WP Media Replace
        add_action('replace_attachment', array($this, 'on_media_replaced_simple'), 10, 1); // Media Replace
        add_action('easy_media_replace_after', array($this, 'on_media_replaced_simple'), 10, 1); // Easy Media Replace
        add_action('attachment_updated', array($this, 'on_attachment_updated'), 10, 3); // Generic WordPress hook
        
        // Enable Media Replace hooks (keep the working ones)
        add_action('emr_replaced_attachment', array($this, 'on_media_replaced'), 10, 2); 
        add_action('emr_attachment_replaced', array($this, 'on_emr_attachment_replaced'), 10, 2);
        
        // WordPress core hooks that are working
        add_action('wp_update_attachment_metadata', array($this, 'on_attachment_metadata_updated'), 10, 2);
        add_action('updated_post_meta', array($this, 'on_post_meta_updated'), 10, 4);
        
        // Add admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_notices', array($this, 'show_debug_notices'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create default options
        $default_options = array(
            'zone_id' => '',
            'api_token' => '',
            'auto_purge_on_save' => true,
            'purge_attached_images' => true,
            'purge_content_images' => true,
            'auto_purge_on_media_replace' => true,
            'log_operations' => true,
            'async_purging' => true,
        );
        
        if (!get_option('cloudflare_purger_options')) {
            add_option('cloudflare_purger_options', $default_options);
        }
        
        // Create log table for purge operations
        $this->create_log_table();
        
        // Clear any existing scheduled events and reschedule
        wp_clear_scheduled_hook('cloudflare_purge_post_images');
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('cloudflare_purge_post_images');
    }
    
    /**
     * Create log table for tracking purge operations
     */
    private function create_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cloudflare_purger_log';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            operation_type varchar(50) NOT NULL,
            urls text NOT NULL,
            post_id bigint(20) DEFAULT NULL,
            status varchar(20) NOT NULL,
            response_message text DEFAULT NULL,
            operation_time datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY operation_time (operation_time)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_options_page(
            'Cloudflare Purger Settings',
            'Cloudflare Purger',
            'manage_options',
            'cloudflare-purger',
            array($this, 'admin_page')
        );
        
        add_management_page(
            'Cloudflare Cache Management',
            'Cloudflare Cache',
            'manage_options',
            'cloudflare-cache-management',
            array($this, 'cache_management_page')
        );
    }
    
    /**
     * Initialize admin settings
     */
    public function admin_init() {
        register_setting(
            'cloudflare_purger_options',
            'cloudflare_purger_options',
            array($this, 'sanitize_options')
        );
        
        // Settings sections and fields will be added here
        $this->add_settings_sections();
    }
    
    /**
     * Add settings sections and fields
     */
    private function add_settings_sections() {
        // Credentials section
        add_settings_section(
            'cloudflare_credentials',
            'Cloudflare Credentials',
            array($this, 'credentials_section_callback'),
            'cloudflare-purger'
        );
        
        add_settings_field(
            'zone_id',
            'Zone ID',
            array($this, 'zone_id_field_callback'),
            'cloudflare-purger',
            'cloudflare_credentials'
        );
        
        add_settings_field(
            'api_token',
            'API Token',
            array($this, 'api_token_field_callback'),
            'cloudflare-purger',
            'cloudflare_credentials'
        );
        
        // Automatic purging section
        add_settings_section(
            'auto_purge_settings',
            'Automatic Purging Settings',
            array($this, 'auto_purge_section_callback'),
            'cloudflare-purger'
        );
        
        add_settings_field(
            'auto_purge_on_save',
            'Auto-purge on Post Save',
            array($this, 'auto_purge_on_save_field_callback'),
            'cloudflare-purger',
            'auto_purge_settings'
        );
        
        add_settings_field(
            'purge_attached_images',
            'Purge Attached Images',
            array($this, 'purge_attached_images_field_callback'),
            'cloudflare-purger',
            'auto_purge_settings'
        );
        
        add_settings_field(
            'purge_content_images',
            'Purge Content Images',
            array($this, 'purge_content_images_field_callback'),
            'cloudflare-purger',
            'auto_purge_settings'
        );
        
        add_settings_field(
            'auto_purge_on_media_replace',
            'Auto-purge on Media Replace',
            array($this, 'auto_purge_media_replace_field_callback'),
            'cloudflare-purger',
            'auto_purge_settings'
        );
        
        add_settings_field(
            'async_purging',
            'Asynchronous Purging',
            array($this, 'async_purging_field_callback'),
            'cloudflare-purger',
            'auto_purge_settings'
        );
        
        add_settings_field(
            'log_operations',
            'Log Operations',
            array($this, 'log_operations_field_callback'),
            'cloudflare-purger',
            'auto_purge_settings'
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if (strpos($hook, 'cloudflare') !== false || $hook === 'upload.php' || $hook === 'post.php') {
            wp_enqueue_script(
                'cloudflare-purger-admin',
                CLOUDFLARE_PURGER_PLUGIN_URL . 'assets/admin.js',
                array('jquery'),
                CLOUDFLARE_PURGER_VERSION,
                true
            );
            
            wp_localize_script('cloudflare-purger-admin', 'cloudflare_purger_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cloudflare_purger_nonce')
            ));
            
            wp_enqueue_style(
                'cloudflare-purger-admin',
                CLOUDFLARE_PURGER_PLUGIN_URL . 'assets/admin.css',
                array(),
                CLOUDFLARE_PURGER_VERSION
            );
        }
    }
    
    /**
     * Handle post save for automatic purging
     */
    public function on_post_save($post_id) {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Check if auto-purge is enabled
        if (!$this->get_option('auto_purge_on_save', true)) {
            return;
        }
        
        // Only purge for published posts
        if (get_post_status($post_id) !== 'publish') {
            return;
        }
        
        // Schedule async purging if enabled, otherwise purge immediately
        if ($this->get_option('async_purging', true)) {
            wp_schedule_single_event(time() + 2, 'cloudflare_purge_post_images', array($post_id));
        } else {
            $this->purge_post_images($post_id);
        }
    }
    
    /**
     * Handle media replacement from Enable Media Replace plugin
     */
    public function on_media_replaced($old_attachment_id, $new_attachment_id) {
        // Check if auto-purge on media replace is enabled
        if (!$this->get_option('auto_purge_on_media_replace', true)) {
            return;
        }
        
        if (!$this->is_configured()) {
            return;
        }
        
        // Purge both old and new attachment URLs to be safe
        $this->purge_media_cache($old_attachment_id);
        if ($new_attachment_id && $new_attachment_id !== $old_attachment_id) {
            $this->purge_media_cache($new_attachment_id);
        }
        
        $this->log_operation('media_replace', [], $old_attachment_id, 'success', 'Media replacement detected and cache purged');
    }
    
    /**
     * Handle media replacement from WP Media Replace plugin
     */
    public function on_media_replaced_wp_media_replace($data) {
        // Check if auto-purge on media replace is enabled
        if (!$this->get_option('auto_purge_on_media_replace', true)) {
            return;
        }
        
        if (!$this->is_configured()) {
            return;
        }
        
        $attachment_id = isset($data['attachment_id']) ? $data['attachment_id'] : null;
        if (!$attachment_id) {
            return;
        }
        
        $this->purge_media_cache($attachment_id);
        $this->log_operation('media_replace', [], $attachment_id, 'success', 'WP Media Replace detected and cache purged');
    }
    
    /**
     * Handle media replacement from simple replacement plugins
     */
    public function on_media_replaced_simple($attachment_id) {
        // Check if auto-purge on media replace is enabled
        if (!$this->get_option('auto_purge_on_media_replace', true)) {
            return;
        }
        
        if (!$this->is_configured()) {
            return;
        }
        
        if (!wp_attachment_is_image($attachment_id)) {
            return;
        }
        
        $this->purge_media_cache($attachment_id);
        $this->log_operation('media_replace', [], $attachment_id, 'success', 'Media replacement detected and cache purged');
    }
    
    /**
     * Handle generic attachment updates
     */
    public function on_attachment_updated($attachment_id, $attachment_after, $attachment_before) {
        // Check if auto-purge on media replace is enabled
        if (!$this->get_option('auto_purge_on_media_replace', true)) {
            return;
        }
        
        if (!$this->is_configured()) {
            return;
        }
        
        // Only process if it's an image
        if (!wp_attachment_is_image($attachment_id)) {
            return;
        }
        
        // Check if the file has actually changed (avoid purging on metadata updates)
        $old_file = get_attached_file($attachment_id, true);
        $old_url = wp_get_attachment_url($attachment_id);
        
        // Only purge if this seems like a file replacement (not just metadata update)
        if ($attachment_after && $attachment_before) {
            $guid_changed = $attachment_after->guid !== $attachment_before->guid;
            $modified_changed = $attachment_after->post_modified !== $attachment_before->post_modified;
            
            if ($guid_changed || $modified_changed) {
                $this->purge_media_cache($attachment_id);
                $this->log_operation('media_replace', [], $attachment_id, 'success', 'Attachment update detected and cache purged');
            }
        }
    }
    

    

    

    

    

    
    /**
     * Handle Enable Media Replace attachment replaced hook
     */
    public function on_emr_attachment_replaced($old_attachment_id, $new_attachment_id) {

        
        if ($this->get_option('auto_purge_on_media_replace', true) && $this->is_configured()) {
            $this->purge_media_cache($old_attachment_id);
            if ($new_attachment_id && $new_attachment_id !== $old_attachment_id) {
                $this->purge_media_cache($new_attachment_id);
            }
            $this->log_operation('media_replace', [], $old_attachment_id, 'success', 'EMR attachment replaced detected and cache purged');
        }
    }
    

    
    /**
     * Handle attachment metadata updates (often happens during media replacement)
     */
    public function on_attachment_metadata_updated($data, $attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            return $data;
        }
        
        error_log("CloudflarePurger DEBUG: wp_update_attachment_metadata fired - Attachment ID: $attachment_id");
        
        // Check if this is likely a media replacement (new file size or dimensions)
        $old_metadata = wp_get_attachment_metadata($attachment_id);
        
        if ($this->get_option('auto_purge_on_media_replace', true) && $this->is_configured()) {
            // Look for signs this is a replacement, not just a metadata update
            $is_replacement = false;
            
            if ($old_metadata && $data) {
                // Check if file size changed significantly
                if (isset($data['filesize']) && isset($old_metadata['filesize'])) {
                    $size_diff = abs($data['filesize'] - $old_metadata['filesize']);
                    if ($size_diff > 1000) { // More than 1KB difference
                        $is_replacement = true;
                    }
                }
                
                // Check if dimensions changed
                if (isset($data['width'], $data['height'], $old_metadata['width'], $old_metadata['height'])) {
                    if ($data['width'] !== $old_metadata['width'] || $data['height'] !== $old_metadata['height']) {
                        $is_replacement = true;
                    }
                }
                
                // Check if filename changed
                if (isset($data['file']) && isset($old_metadata['file']) && $data['file'] !== $old_metadata['file']) {
                    $is_replacement = true;
                }
            }
            
            if ($is_replacement) {

                
                // Schedule delayed purge to allow WordPress to finish processing
                wp_schedule_single_event(time() + 3, 'cloudflare_purge_media_delayed', array($attachment_id));
                
                // Try immediate purge but don't log failure if no URLs (timing issue)
                $urls_to_purge = $this->get_image_urls($attachment_id);
                if (!empty($urls_to_purge)) {
                    $result = $this->purge_cloudflare_cache($urls_to_purge, 'media_replace_immediate', $attachment_id);
                } else {
                    $this->log_operation('media_replace_immediate', [], $attachment_id, 'info', 'Media replacement detected, delayed purge scheduled (no URLs available yet)');
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Handle post meta updates (catch file path changes)
     */
    public function on_post_meta_updated($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key !== '_wp_attached_file') {
            return;
        }
        
        if (!wp_attachment_is_image($post_id)) {
            return;
        }
        
        error_log("CloudflarePurger DEBUG: _wp_attached_file meta updated for Attachment ID: $post_id, New file: $meta_value");
        
        if ($this->get_option('auto_purge_on_media_replace', true) && $this->is_configured()) {
            $this->purge_media_cache($post_id);
            $this->log_operation('media_replace', [], $post_id, 'success', 'File path change detected and cache purged');
        }
    }
    

    

    
    /**
     * Add a success notice to be displayed in admin
     */
    private function add_success_notice($message) {
        $this->debug_notices[] = $message;
        
        // Also store in transient for display after redirect
        $existing = get_transient('cloudflare_purger_success_notices') ?: array();
        $existing[] = $message;
        set_transient('cloudflare_purger_success_notices', $existing, 60);
    }
    
    /**
     * Display success notices in admin
     */
    public function show_debug_notices() {
        // Show success notices from transient (after redirects)
        $notices = get_transient('cloudflare_purger_success_notices');
        if ($notices) {
            foreach ($notices as $notice) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Cloudflare Purger:</strong> ' . esc_html($notice) . '</p></div>';
            }
            delete_transient('cloudflare_purger_success_notices');
        }
        
        // Also show any notices from current request
        foreach ($this->debug_notices as $notice) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Cloudflare Purger:</strong> ' . esc_html($notice) . '</p></div>';
        }
    }
    
    /**
     * Get option value with fallback
     */
    private function get_option($key, $default = null) {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }
    
    /**
     * Check if plugin is configured
     */
    public function is_configured() {
        return !empty($this->get_option('zone_id')) && !empty($this->get_option('api_token'));
    }
    
    /**
     * Display admin notices
     */
    public function admin_notices() {
        if (!$this->is_configured()) {
            $settings_url = admin_url('options-general.php?page=cloudflare-purger');
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>Cloudflare Purger:</strong> Plugin is not configured. ';
            echo '<a href="' . esc_url($settings_url) . '">Configure your Cloudflare credentials</a> to enable automatic cache purging.</p>';
            echo '</div>';
        }
    }
    
    /**
     * Render admin settings page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Cloudflare Purger Settings</h1>
            
            <?php if (!$this->is_configured()): ?>
            <div class="notice notice-info">
                <p><strong>Getting Started:</strong> Enter your Cloudflare Zone ID and API Token below to enable automatic cache purging.</p>
                <p>You can find these in your Cloudflare dashboard:</p>
                <ul>
                    <li><strong>Zone ID:</strong> Go to your domain overview page, scroll down to the "API" section</li>
                    <li><strong>API Token:</strong> Go to "My Profile" → "API Tokens" → "Create Token" with "Zone:Zone:Read, Zone:Cache Purge:Edit" permissions</li>
                </ul>
            </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('cloudflare_purger_options');
                do_settings_sections('cloudflare-purger');
                submit_button();
                ?>
            </form>
            
            <?php if ($this->is_configured()): ?>
            <div class="cloudflare-test-connection">
                <h3>Test Connection</h3>
                <p>Test your Cloudflare credentials to ensure they're working correctly.</p>
                <button type="button" id="test-cloudflare-connection" class="button">Test Connection</button>
                <div id="test-result"></div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render cache management page
     */
    public function cache_management_page() {
        ?>
        <div class="wrap">
            <h1>Cloudflare Cache Management</h1>
            
            <?php if (!$this->is_configured()): ?>
            <div class="notice notice-error">
                <p>Plugin is not configured. <a href="<?php echo admin_url('options-general.php?page=cloudflare-purger'); ?>">Configure your Cloudflare credentials</a> first.</p>
            </div>
            <?php return; endif; ?>
            
            <div class="cloudflare-cache-actions">
                <div class="cache-action-box">
                    <h3>Purge Specific URLs</h3>
                    <p>Enter one URL per line to purge specific files from the cache.</p>
                    <textarea id="purge-urls" rows="5" cols="80" placeholder="https://yoursite.com/image1.jpg&#10;https://yoursite.com/image2.png"></textarea>
                    <br><br>
                    <button type="button" id="purge-specific-urls" class="button button-secondary">Purge URLs</button>
                </div>
                
                <div class="cache-action-box">
                    <h3>Purge All Cache</h3>
                    <p><strong>Warning:</strong> This will purge your entire Cloudflare cache for this zone.</p>
                    <button type="button" id="purge-all-cache" class="button button-secondary" onclick="return confirm('Are you sure you want to purge ALL cache? This action cannot be undone.')">Purge All Cache</button>
                </div>
                
                <div class="cache-action-box">
                    <h3>Purge Post Images</h3>
                    <p>Enter a post ID to purge all images associated with that post.</p>
                    <input type="number" id="post-id-purge" placeholder="Enter post ID" min="1">
                    <button type="button" id="purge-post-images" class="button button-secondary">Purge Post Images</button>
                </div>
            </div>
            
            <div id="purge-results"></div>
            
            <div class="purge-log">
                <h3>Recent Purge Operations</h3>
                <?php $this->display_purge_log(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Sanitize and validate options
     */
    public function sanitize_options($input) {
        $sanitized = array();
        
        // Sanitize zone ID (alphanumeric characters only)
        if (isset($input['zone_id'])) {
            $sanitized['zone_id'] = preg_replace('/[^a-zA-Z0-9]/', '', $input['zone_id']);
        }
        
        // Sanitize API token (alphanumeric and specific characters)
        if (isset($input['api_token'])) {
            $sanitized['api_token'] = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['api_token']);
        }
        
        // Boolean options
        $boolean_options = [
            'auto_purge_on_save',
            'purge_attached_images', 
            'purge_content_images',
            'auto_purge_on_media_replace',
            'log_operations',
            'async_purging'
        ];
        
        foreach ($boolean_options as $option) {
            $sanitized[$option] = isset($input[$option]) ? (bool) $input[$option] : false;
        }
        
        return $sanitized;
    }
    
    /**
     * Display recent purge log entries
     */
    private function display_purge_log() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cloudflare_purger_log';
        
        $results = $wpdb->get_results("
            SELECT * FROM $table_name 
            ORDER BY operation_time DESC 
            LIMIT 20
        ");
        
        if (empty($results)) {
            echo '<p>No purge operations recorded yet.</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Time</th><th>Operation</th><th>URLs</th><th>Status</th><th>Response</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($results as $log) {
            $urls = json_decode($log->urls, true);
            $url_count = is_array($urls) ? count($urls) : 1;
            $status_class = $log->status === 'success' ? 'success' : 'error';
            
            echo '<tr>';
            echo '<td>' . esc_html($log->operation_time) . '</td>';
            echo '<td>' . esc_html($log->operation_type) . '</td>';
            echo '<td title="' . esc_attr(implode("\n", is_array($urls) ? $urls : [$log->urls])) . '">';
            echo $url_count . ' URL' . ($url_count > 1 ? 's' : '') . '</td>';
            echo '<td><span class="status-' . $status_class . '">' . esc_html($log->status) . '</span></td>';
            echo '<td>' . esc_html(substr($log->response_message, 0, 100)) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    // Settings section callbacks
    public function credentials_section_callback() {
        echo '<p>Enter your Cloudflare credentials to enable cache purging functionality.</p>';
    }
    
    public function zone_id_field_callback() {
        $zone_id = $this->get_option('zone_id', '');
        echo '<input type="text" name="cloudflare_purger_options[zone_id]" value="' . esc_attr($zone_id) . '" class="regular-text" placeholder="e.g., 1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6p" />';
        echo '<p class="description">Your Cloudflare Zone ID (found in your domain overview page)</p>';
    }
    
    public function api_token_field_callback() {
        $api_token = $this->get_option('api_token', '');
        $masked_token = $api_token ? str_repeat('*', strlen($api_token) - 8) . substr($api_token, -8) : '';
        echo '<input type="password" name="cloudflare_purger_options[api_token]" value="' . esc_attr($api_token) . '" class="regular-text" placeholder="Enter your API token" />';
        if ($masked_token) {
            echo '<p class="description">Current token: ' . esc_html($masked_token) . '</p>';
        }
        echo '<p class="description">Create an API token with "Zone:Zone:Read" and "Zone:Cache Purge:Edit" permissions</p>';
    }
    
    public function auto_purge_section_callback() {
        echo '<p>Configure when and how the plugin should automatically purge cache.</p>';
    }
    
    public function auto_purge_on_save_field_callback() {
        $checked = $this->get_option('auto_purge_on_save', true);
        echo '<label><input type="checkbox" name="cloudflare_purger_options[auto_purge_on_save]" value="1" ' . checked($checked, true, false) . ' />';
        echo ' Automatically purge cache when posts are saved or updated</label>';
    }
    
    public function purge_attached_images_field_callback() {
        $checked = $this->get_option('purge_attached_images', true);
        echo '<label><input type="checkbox" name="cloudflare_purger_options[purge_attached_images]" value="1" ' . checked($checked, true, false) . ' />';
        echo ' Purge all attached images and their size variants</label>';
    }
    
    public function purge_content_images_field_callback() {
        $checked = $this->get_option('purge_content_images', true);
        echo '<label><input type="checkbox" name="cloudflare_purger_options[purge_content_images]" value="1" ' . checked($checked, true, false) . ' />';
        echo ' Purge images found in post content</label>';
    }
    
    public function async_purging_field_callback() {
        $checked = $this->get_option('async_purging', true);
        echo '<label><input type="checkbox" name="cloudflare_purger_options[async_purging]" value="1" ' . checked($checked, true, false) . ' />';
        echo ' Use asynchronous purging (recommended)</label>';
        echo '<p class="description">Prevents page load delays by processing purge requests in the background</p>';
    }
    
    public function auto_purge_media_replace_field_callback() {
        $checked = $this->get_option('auto_purge_on_media_replace', true);
        echo '<label><input type="checkbox" name="cloudflare_purger_options[auto_purge_on_media_replace]" value="1" ' . checked($checked, true, false) . ' />';
        echo ' Automatically purge cache when media files are replaced</label>';
        echo '<p class="description">Works with Enable Media Replace, WP Media Replace, and other media replacement plugins</p>';
    }

    public function log_operations_field_callback() {
        $checked = $this->get_option('log_operations', true);
        echo '<label><input type="checkbox" name="cloudflare_purger_options[log_operations]" value="1" ' . checked($checked, true, false) . ' />';
        echo ' Log purge operations for debugging and monitoring</label>';
    }
    
    /**
     * AJAX handler for purging specific URLs
     */
    public function ajax_purge_url() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cloudflare_purger_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $urls_input = sanitize_textarea_field($_POST['urls'] ?? '');
        if (empty($urls_input)) {
            wp_send_json_error('No URLs provided');
        }
        
        // Parse URLs (one per line)
        $urls = array_filter(array_map('trim', explode("\n", $urls_input)));
        
        // Validate URLs
        $valid_urls = array();
        foreach ($urls as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $valid_urls[] = $url;
            }
        }
        
        if (empty($valid_urls)) {
            wp_send_json_error('No valid URLs found');
        }
        
        $result = $this->purge_cloudflare_cache($valid_urls, 'manual_urls');
        
        if ($result) {
            wp_send_json_success('Successfully purged ' . count($valid_urls) . ' URLs');
        } else {
            wp_send_json_error('Failed to purge URLs. Check the log for details.');
        }
    }
    
    /**
     * AJAX handler for purging all cache
     */
    public function ajax_purge_all() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cloudflare_purger_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $result = $this->purge_all_cache();
        
        if ($result) {
            wp_send_json_success('Successfully purged all cache');
        } else {
            wp_send_json_error('Failed to purge all cache. Check the log for details.');
        }
    }
    
    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cloudflare_purger_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $result = $this->test_connection();
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * AJAX handler for purging post images
     */
    public function ajax_purge_post() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cloudflare_purger_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error('Invalid post ID');
        }
        
        $result = $this->purge_post_images($post_id);
        
        if ($result) {
            wp_send_json_success('Successfully purged images for post #' . $post_id);
        } else {
            wp_send_json_error('Failed to purge post images. Check the log for details.');
        }
    }
    
    /**
     * AJAX handler for purging media files from Cloudflare cache
     */
    public function ajax_purge_media() {
        // Security checks
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cloudflare_purger_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            wp_send_json_error('Invalid attachment ID or not an image');
        }
        
        $result = $this->purge_media_cache($attachment_id);
        
        if ($result) {
            wp_send_json_success('Successfully purged cache for media file');
        } else {
            wp_send_json_error('Failed to purge media cache. Check the log for details.');
        }
    }
    
    /**
     * AJAX handler for getting image variants
     */
    public function ajax_get_image_variants() {
        // Security checks
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cloudflare_purger_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            wp_send_json_error('Invalid attachment ID or not an image');
        }
        
        // Get all image URLs and their details
        $variants = $this->get_image_variant_details($attachment_id);
        
        if (empty($variants)) {
            wp_send_json_error('No image variants found');
        }
        
        wp_send_json_success($variants);
    }
    
    /**
     * Get detailed information about image variants
     */
    private function get_image_variant_details($attachment_id) {
        $variants = array();
        $attachment_metadata = wp_get_attachment_metadata($attachment_id);
        
        // Get full size image
        $full_url = wp_get_attachment_url($attachment_id);
        if ($full_url) {
            $variants[] = array(
                'name' => 'Full Size',
                'url' => $full_url,
                'width' => $attachment_metadata['width'] ?? 'Unknown',
                'height' => $attachment_metadata['height'] ?? 'Unknown',
                'filesize' => $attachment_metadata['filesize'] ?? null
            );
        }
        
        // Get all image size variants
        $image_sizes = get_intermediate_image_sizes();
        foreach ($image_sizes as $size_name) {
            $image_data = wp_get_attachment_image_src($attachment_id, $size_name);
            if ($image_data && $image_data[0] && $image_data[0] !== $full_url) {
                $variants[] = array(
                    'name' => ucwords(str_replace(array('-', '_'), ' ', $size_name)),
                    'url' => $image_data[0],
                    'width' => $image_data[1] ?? 'Unknown',
                    'height' => $image_data[2] ?? 'Unknown',
                    'filesize' => null // Not easily available for variants
                );
            }
        }
        
        return $variants;
    }
    
    /**
     * Add Cloudflare Cache meta box to attachment edit screen
     */
    public function add_attachment_meta_box() {
        global $post;
        
        // Only add for images and if user has upload permissions
        if (wp_attachment_is_image($post->ID) && current_user_can('upload_files')) {
            add_meta_box(
                'cloudflare-cache-purge',
                '<span class="dashicons dashicons-cloud" style="margin-right: 8px; color: #0073aa;"></span>' . __('Cloudflare Cache', 'cloudflare-purger'),
                array($this, 'render_attachment_meta_box'),
                'attachment',
                'side',
                'high'
            );
        }
    }

    /**
     * Render the Cloudflare Cache meta box content
     */
    public function render_attachment_meta_box($post) {
        ?>
        <div class="cloudflare-meta-box-content">
            <?php if ($this->is_configured()) : ?>
                <p class="cloudflare-description">
                    <?php _e('Purge this image and all its size variants from Cloudflare cache.', 'cloudflare-purger'); ?>
                </p>
                
                <p class="cloudflare-button-container">
                    <button type="button" class="button button-primary cloudflare-purge-attachment" data-attachment-id="<?php echo esc_attr($post->ID); ?>">
                        <span class="dashicons dashicons-update" style="font-size: 16px; line-height: 1.2; margin-right: 5px;"></span>
                        <?php _e('Purge Cache', 'cloudflare-purger'); ?>
                    </button>
                </p>
                
                <p style="margin-top: 10px;">
                    <button type="button" class="button cloudflare-show-variants" data-attachment-id="<?php echo esc_attr($post->ID); ?>" style="width: 100%;">
                        <span class="dashicons dashicons-visibility" style="font-size: 14px; margin-right: 5px;"></span>
                        <?php _e('View Image Variants', 'cloudflare-purger'); ?>
                    </button>
                </p>
                
                <div id="cloudflare-purge-result" class="cloudflare-result-message"></div>
                
                <div class="cloudflare-info">
                    <p class="description">
                        <?php 
                        $image_sizes = $this->get_image_size_info($post->ID);
                        printf(
                            __('This will purge %d image variant(s) from cache.', 'cloudflare-purger'),
                            count($image_sizes)
                        );
                        ?>
                    </p>
                    
                    <?php if ($this->get_option('auto_purge_on_media_replace', true)) : ?>
                        <p class="description" style="margin-top: 8px; color: #46b450;">
                            <span class="dashicons dashicons-yes-alt" style="font-size: 14px; margin-right: 3px;"></span>
                            <?php _e('Auto-purge is enabled for media replacement (with delayed fallback).', 'cloudflare-purger'); ?>
                        </p>
                        <p class="description" style="font-size: 11px; color: #666; margin-top: 4px;">
                            <?php _e('Cache is purged immediately when possible, with a 3-second delayed backup to ensure all image variants are cleared.', 'cloudflare-purger'); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
            <?php else : ?>
                <div class="cloudflare-not-configured">
                    <p>
                        <span class="dashicons dashicons-warning" style="color: #d63638; margin-right: 5px;"></span>
                        <?php _e('Cloudflare Purger is not configured.', 'cloudflare-purger'); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('options-general.php?page=cloudflare-purger')); ?>" class="button">
                            <?php _e('Configure Plugin', 'cloudflare-purger'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get information about image sizes for display
     */
    private function get_image_size_info($attachment_id) {
        $urls = $this->get_image_urls($attachment_id);
        return $urls;
    }

    /**
     * Enqueue scripts specifically for attachment editing
     */
    public function enqueue_attachment_scripts() {
        global $pagenow, $typenow;
        
        // Only enqueue on post.php when editing attachments
        if ($pagenow === 'post.php' && $typenow === 'attachment') {
            // Scripts are already enqueued by admin_enqueue_scripts, just add inline script
            add_action('admin_footer', array($this, 'add_attachment_edit_scripts'));
        }
    }

    /**
     * Add JavaScript for attachment edit screen
     */
    public function add_attachment_edit_scripts() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Initialize attachment purge functionality
            if (typeof window.cloudflareAttachmentPurge === 'undefined') {
                window.cloudflareAttachmentPurge = true;
                
                // Handle attachment purge button clicks
                $(document).on('click', '.cloudflare-purge-attachment', function(e) {
                    e.preventDefault();
                    
                    var button = $(this);
                    var attachmentId = button.data('attachment-id');
                    var resultDiv = $('#cloudflare-purge-result');
                    var originalHtml = button.html();
                    var originalText = button.find('span:not(.dashicons)').text() || button.text().trim();
                    
                    // Prevent multiple clicks
                    if (button.hasClass('purging')) {
                        return;
                    }
                    
                    // Update button state
                    button.addClass('purging').prop('disabled', true);
                    button.html('<span class="dashicons dashicons-update"></span> Purging...');
                    
                    // Show processing message
                    resultDiv.removeClass('success error').html('<p>Processing purge request...</p>');
                    
                    $.ajax({
                        url: cloudflare_purger_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'cloudflare_purge_media',
                            attachment_id: attachmentId,
                            nonce: cloudflare_purger_ajax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                resultDiv.addClass('success').html('<p>✓ ' + response.data + '</p>');
                                
                                // Temporarily show success state
                                button.html('<span class="dashicons dashicons-yes"></span> Purged!');
                                setTimeout(function() {
                                    button.html(originalHtml);
                                }, 2000);
                            } else {
                                resultDiv.addClass('error').html('<p>✗ ' + (response.data || 'Failed to purge cache') + '</p>');
                            }
                        },
                        error: function() {
                            resultDiv.addClass('error').html('<p>✗ Network error occurred while purging cache</p>');
                        },
                        complete: function() {
                            setTimeout(function() {
                                button.removeClass('purging').prop('disabled', false);
                                if (!button.html().includes('dashicons-yes')) {
                                    button.html(originalHtml);
                                }
                            }, response && response.success ? 2000 : 100);
                        }
                    });
                });
                
                // Handle View Image Variants button
                $(document).on('click', '.cloudflare-show-variants', function(e) {
                    e.preventDefault();
                    
                    var button = $(this);
                    var attachmentId = button.data('attachment-id');
                    var originalHtml = button.html();
                    
                    // Prevent multiple clicks
                    if (button.hasClass('loading-variants')) {
                        return;
                    }
                    
                    // Update button state
                    button.addClass('loading-variants').prop('disabled', true);
                    button.html('<span class="dashicons dashicons-update"></span> Loading...');
                    
                    $.ajax({
                        url: cloudflare_purger_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'cloudflare_get_image_variants',
                            attachment_id: attachmentId,
                            nonce: cloudflare_purger_ajax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                showVariantsModal(response.data);
                            } else {
                                alert('Error loading variants: ' + (response.data || 'Unknown error'));
                            }
                        },
                        error: function(xhr, status, error) {
                            alert('An error occurred while loading image variants. Please try again.');
                        },
                        complete: function() {
                            button.removeClass('loading-variants').prop('disabled', false);
                            button.html(originalHtml);
                        }
                    });
                });
                
                // Function to show the variants modal
                function showVariantsModal(variants) {
                    // Create modal HTML
                    var modalHtml = '<div id="cloudflare-variants-modal" class="cloudflare-modal" style="display: block; position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.8); opacity: 0; transition: opacity 0.3s ease;">' +
                                   '<div class="cloudflare-modal-content" style="background-color: white; margin: 5% auto; padding: 0; border: 1px solid #ddd; width: 90%; max-width: 900px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); transform: translateY(-50px); transition: transform 0.3s ease;">' +
                                   '<div class="cloudflare-modal-header" style="padding: 20px; background-color: #f8f9fa; border-bottom: 1px solid #ddd; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center;">' +
                                   '<h3 style="margin: 0; color: #1d2327; font-size: 18px;">Image Variants <span style="color: #666; font-weight: normal; font-size: 14px;">(' + variants.length + ' found)</span></h3>' +
                                   '<span class="cloudflare-modal-close" style="font-size: 24px; font-weight: bold; cursor: pointer; color: #666; padding: 5px; line-height: 1; transition: color 0.2s ease;">&times;</span>' +
                                   '</div>' +
                                   '<div class="cloudflare-modal-body" style="padding: 20px; max-height: 500px; overflow-y: auto;">' +
                                   '<table class="cloudflare-variants-table" style="width: 100%; border-collapse: collapse; font-size: 14px;">' +
                                   '<thead>' +
                                   '<tr style="background-color: #f8f9fa;">' +
                                   '<th style="padding: 12px; border-bottom: 2px solid #ddd; text-align: center; font-weight: 600; color: #1d2327; width: 100px;">Preview</th>' +
                                   '<th style="padding: 12px; border-bottom: 2px solid #ddd; text-align: left; font-weight: 600; color: #1d2327;">Size Name</th>' +
                                   '<th style="padding: 12px; border-bottom: 2px solid #ddd; text-align: left; font-weight: 600; color: #1d2327;">Dimensions</th>' +
                                   '<th style="padding: 12px; border-bottom: 2px solid #ddd; text-align: left; font-weight: 600; color: #1d2327;">URL</th>' +
                                   '</tr>' +
                                   '</thead>' +
                                   '<tbody>';
                    
                    // Add each variant to the table
                    variants.forEach(function(variant, index) {
                        var dimensions = variant.width + ' × ' + variant.height;
                        var rowColor = index % 2 === 0 ? '#ffffff' : '#f8f9fa';
                        
                        // Create thumbnail with max dimensions
                        var thumbnailStyle = 'max-width: 80px; max-height: 60px; width: auto; height: auto; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.2); cursor: pointer;';
                        
                        modalHtml += '<tr style="background-color: ' + rowColor + '; transition: background-color 0.2s ease;" onmouseover="this.style.backgroundColor=\'#e3f2fd\'" onmouseout="this.style.backgroundColor=\'' + rowColor + '\'">' +
                                    '<td style="padding: 10px; border-bottom: 1px solid #e0e0e0; text-align: center;"><img src="' + variant.url + '" style="' + thumbnailStyle + '" alt="' + variant.name + '" title="Click to view full size" onclick="window.open(\'' + variant.url + '\', \'_blank\')"></td>' +
                                    '<td style="padding: 10px; border-bottom: 1px solid #e0e0e0; font-weight: 500;">' + variant.name + '</td>' +
                                    '<td style="padding: 10px; border-bottom: 1px solid #e0e0e0; color: #666;">' + dimensions + '</td>' +
                                    '<td style="padding: 10px; border-bottom: 1px solid #e0e0e0;"><a href="' + variant.url + '" target="_blank" style="color: #0073aa; text-decoration: none; word-break: break-all;" title="' + variant.url + '">' + 
                                    variant.url.split('/').pop() + '</a></td>' +
                                    '</tr>';
                    });
                    
                    modalHtml += '</tbody>' +
                                '</table>' +
                                '</div>' +
                                '</div>' +
                                '</div>';
                    
                    // Add modal to page
                    $('body').append(modalHtml);
                    
                    // Trigger animation
                    setTimeout(function() {
                        $('#cloudflare-variants-modal').css('opacity', '1');
                        $('#cloudflare-variants-modal .cloudflare-modal-content').css('transform', 'translateY(0)');
                    }, 10);
                    
                    // Function to close modal with animation
                    function closeModal() {
                        var modal = $('#cloudflare-variants-modal');
                        modal.css('opacity', '0');
                        modal.find('.cloudflare-modal-content').css('transform', 'translateY(-50px)');
                        setTimeout(function() {
                            modal.remove();
                        }, 300);
                        $(document).off('keydown.cloudflare-modal');
                    }
                    
                    // Handle close button
                    $('.cloudflare-modal-close').click(closeModal);
                    
                    // Handle click outside modal to close
                    $('#cloudflare-variants-modal').click(function(e) {
                        if (e.target.id === 'cloudflare-variants-modal') {
                            closeModal();
                        }
                    });
                    
                    // Handle escape key to close
                    $(document).on('keydown.cloudflare-modal', function(e) {
                        if (e.keyCode === 27) { // Escape key
                            closeModal();
                        }
                    });
                }
            }
        });
        </script>
        <?php
    }    /**
     * Purge cache for a specific media file and all its variants
     */
    public function purge_media_cache($attachment_id) {
        if (!$this->is_configured()) {
            $this->log_operation('media_purge', [], $attachment_id, 'error', 'Plugin not configured');
            return false;
        }
        
        $urls_to_purge = $this->get_image_urls($attachment_id);
        
        if (empty($urls_to_purge)) {
            $this->log_operation('media_purge', [], $attachment_id, 'info', 'No URLs found to purge');
            return false;
        }
        
        $result = $this->purge_cloudflare_cache($urls_to_purge, 'media_purge', $attachment_id);
        
        // Show success message only if purge was successful
        if ($result) {
            $this->add_success_notice(sprintf(
                __('Successfully purged %d image variant(s) from Cloudflare cache.', 'cloudflare-purger'),
                count($urls_to_purge)
            ));
        }
        
        return $result;
    }
    
    /**
     * Handle delayed media cache purging
     */
    public function purge_media_cache_delayed($attachment_id) {
        if (!$this->is_configured()) {
            $this->log_operation('media_replace_delayed', [], $attachment_id, 'error', 'Plugin not configured for delayed purge');
            return false;
        }
        
        // Get URLs directly and purge with correct operation type
        $urls_to_purge = $this->get_image_urls($attachment_id);
        
        if (empty($urls_to_purge)) {
            $this->log_operation('media_replace_delayed', [], $attachment_id, 'info', 'No URLs found for delayed purge');
            return false;
        }
        
        $result = $this->purge_cloudflare_cache($urls_to_purge, 'media_replace_delayed', $attachment_id);
        
        // Show success message only if delayed purge was successful
        if ($result) {
            $this->add_success_notice(sprintf(
                __('Media replacement detected: Successfully purged %d image variant(s) from Cloudflare cache.', 'cloudflare-purger'),
                count($urls_to_purge)
            ));
        }
        
        return $result;
    }
    
    /**
     * Purge all images associated with a post
     */
    public function purge_post_images($post_id) {
        if (!$this->is_configured()) {
            $this->log_operation('post_purge', [], $post_id, 'error', 'Plugin not configured');
            return false;
        }
        
        $urls_to_purge = array();
        
        // Get attached images if enabled
        if ($this->get_option('purge_attached_images', true)) {
            $attachments = get_attached_media('image', $post_id);
            foreach ($attachments as $attachment) {
                $attachment_urls = $this->get_image_urls($attachment->ID);
                $urls_to_purge = array_merge($urls_to_purge, $attachment_urls);
            }
        }
        
        // Get content images if enabled
        if ($this->get_option('purge_content_images', true)) {
            $content_image_url = $this->extract_first_image_from_post($post_id);
            if ($content_image_url) {
                $urls_to_purge[] = $content_image_url;
            }
        }
        
        // Remove duplicates and empty values
        $urls_to_purge = array_unique(array_filter($urls_to_purge));
        
        if (empty($urls_to_purge)) {
            $this->log_operation('post_purge', [], $post_id, 'info', 'No images found to purge');
            return true;
        }
        
        return $this->purge_cloudflare_cache($urls_to_purge, 'post_purge', $post_id);
    }
    
    /**
     * Get all image URLs for an attachment including all size variants
     */
    private function get_image_urls($attachment_id) {
        $urls = array();
        
        // Get full size image URL
        $full_url = wp_get_attachment_url($attachment_id);
        
        if ($full_url) {
            $urls[] = $full_url;
        }
        
        // Get all image size variants
        $image_sizes = get_intermediate_image_sizes();
        
        foreach ($image_sizes as $size) {
            $image_data = wp_get_attachment_image_src($attachment_id, $size);
            if ($image_data && $image_data[0] && $image_data[0] !== $full_url) {
                $urls[] = $image_data[0];
            }
        }
        
        // Fallback: try to construct URLs manually if WordPress functions fail
        if (empty($urls)) {
            $attached_file = get_attached_file($attachment_id);
            if ($attached_file && file_exists($attached_file)) {
                $upload_dir = wp_upload_dir();
                $relative_path = str_replace($upload_dir['basedir'], '', $attached_file);
                $manual_url = $upload_dir['baseurl'] . $relative_path;
                $urls[] = $manual_url;
            }
        }
        
        return $urls;
    }
    
    /**
     * Extract first image URL from post content (based on your sample code)
     */
    private function extract_first_image_from_post($post_id) {
        $post = get_post($post_id);
        if (!$post || empty($post->post_content)) {
            return '';
        }
        
        // Handle Block Editor content first
        if (function_exists('has_blocks') && has_blocks($post->post_content)) {
            $blocks = parse_blocks($post->post_content);
            
            foreach ($blocks as $block) {
                // Image block with attachment ID
                if ($block['blockName'] === 'core/image' && isset($block['attrs']['id'])) {
                    $image_id = $block['attrs']['id'];
                    $image_url = wp_get_attachment_url($image_id);
                    if ($image_url) {
                        return $image_url;
                    }
                }
                
                // Image URL directly in block attributes
                if (isset($block['attrs']['url'])) {
                    $url = $block['attrs']['url'];
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $url)) {
                        return $url;
                    }
                }
                
                // Check innerContent for image tags
                if (!empty($block['innerHTML'])) {
                    if (preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $block['innerHTML'], $matches) && !empty($matches[1][0])) {
                        return $matches[1][0];
                    }
                }
            }
        }
        
        // Fall back to classic content method
        if (preg_match_all('/<img.+src=[\'"]([^\'"]+)[\'"].*>/i', $post->post_content, $matches) && !empty($matches[1][0])) {
            return $matches[1][0];
        }
        
        return '';
    }
    
    /**
     * Main Cloudflare cache purging function
     */
    public function purge_cloudflare_cache($urls, $operation_type = 'manual', $post_id = null) {
        if (!$this->is_configured()) {
            return false;
        }
        
        $zone_id = $this->get_option('zone_id');
        $api_token = $this->get_option('api_token');
        
        // Ensure URLs are properly formatted as an indexed array
        $urls_to_purge = array_values(array_unique(array_filter($urls)));
        
        if (empty($urls_to_purge)) {
            $this->log_operation($operation_type, [], $post_id, 'error', 'No URLs provided for purging');
            return false;
        }
        
        $payload = array('files' => $urls_to_purge);
        
        $response = wp_remote_post("https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache", array(
            'headers' => array(
                'Authorization' => "Bearer {$api_token}",
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode($payload),
            'timeout' => 15,
            'blocking' => true
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_operation($operation_type, $urls_to_purge, $post_id, 'error', $error_message);
            error_log("Cloudflare Purger: API error - {$error_message}");
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        if ($response_code === 200 && isset($response_data['success']) && $response_data['success']) {
            $message = "Successfully purged " . count($urls_to_purge) . " URLs";
            $this->log_operation($operation_type, $urls_to_purge, $post_id, 'success', $message);
            error_log("Cloudflare Purger: {$message}");
            return true;
        } else {
            $error_message = "HTTP {$response_code}";
            if (isset($response_data['errors']) && is_array($response_data['errors'])) {
                $error_details = array();
                foreach ($response_data['errors'] as $error) {
                    $error_details[] = $error['message'] ?? 'Unknown error';
                }
                $error_message .= ": " . implode(', ', $error_details);
            }
            
            $this->log_operation($operation_type, $urls_to_purge, $post_id, 'error', $error_message);
            error_log("Cloudflare Purger: API error - {$error_message}");
            return false;
        }
    }
    
    /**
     * Purge all cache from Cloudflare
     */
    public function purge_all_cache() {
        if (!$this->is_configured()) {
            return false;
        }
        
        $zone_id = $this->get_option('zone_id');
        $api_token = $this->get_option('api_token');
        
        $payload = array('purge_everything' => true);
        
        $response = wp_remote_post("https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache", array(
            'headers' => array(
                'Authorization' => "Bearer {$api_token}",
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode($payload),
            'timeout' => 15,
            'blocking' => true
        ));
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log_operation('purge_all', ['all'], null, 'error', $error_message);
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        if ($response_code === 200 && isset($response_data['success']) && $response_data['success']) {
            $message = "Successfully purged all cache";
            $this->log_operation('purge_all', ['all'], null, 'success', $message);
            return true;
        } else {
            $error_message = "HTTP {$response_code}";
            if (isset($response_data['errors']) && is_array($response_data['errors'])) {
                $error_details = array();
                foreach ($response_data['errors'] as $error) {
                    $error_details[] = $error['message'] ?? 'Unknown error';
                }
                $error_message .= ": " . implode(', ', $error_details);
            }
            
            $this->log_operation('purge_all', ['all'], null, 'error', $error_message);
            return false;
        }
    }
    
    /**
     * Test Cloudflare connection
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return array('success' => false, 'message' => 'Plugin not configured');
        }
        
        $zone_id = $this->get_option('zone_id');
        $api_token = $this->get_option('api_token');
        
        // Test by getting zone information
        $response = wp_remote_get("https://api.cloudflare.com/client/v4/zones/{$zone_id}", array(
            'headers' => array(
                'Authorization' => "Bearer {$api_token}",
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        if ($response_code === 200 && isset($response_data['success']) && $response_data['success']) {
            $zone_name = $response_data['result']['name'] ?? 'Unknown';
            return array('success' => true, 'message' => "Connection successful! Zone: {$zone_name}");
        } else {
            $error_message = "Connection failed (HTTP {$response_code})";
            if (isset($response_data['errors']) && is_array($response_data['errors'])) {
                $error_details = array();
                foreach ($response_data['errors'] as $error) {
                    $error_details[] = $error['message'] ?? 'Unknown error';
                }
                $error_message .= ": " . implode(', ', $error_details);
            }
            return array('success' => false, 'message' => $error_message);
        }
    }
    
    /**
     * Log purge operations to database
     */
    private function log_operation($operation_type, $urls, $post_id = null, $status = 'success', $message = '') {
        if (!$this->get_option('log_operations', true)) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cloudflare_purger_log';
        
        $wpdb->insert(
            $table_name,
            array(
                'operation_type' => $operation_type,
                'urls' => json_encode($urls),
                'post_id' => $post_id,
                'status' => $status,
                'response_message' => $message,
                'operation_time' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s')
        );
    }
}

// Initialize the plugin
CloudflarePurger::get_instance();