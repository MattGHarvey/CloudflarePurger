<?php
/**
 * Plugin Name: Cloudflare Purger
 * Plugin URI: https://github.com/MattGHarvey/CloudflarePurger
 * Description: Automatically purge Cloudflare cache when WordPress posts are saved, including all attached images and their size variants. Includes admin interface for manual cache management.
 * Version: 1.0.0
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
        
        // Post save hooks for automatic purging
        add_action('save_post', array($this, 'on_post_save'), 20);
        add_action('cloudflare_purge_post_images', array($this, 'purge_post_images'), 10, 1);
        
        // Add admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
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
        if (strpos($hook, 'cloudflare') !== false) {
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