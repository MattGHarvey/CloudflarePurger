# Cloudflare Purger

A WordPress plugin that automatically purges Cloudflare cache when posts are saved, including all attached images and their size variants. Features a user-friendly admin interface for manual cache management.

## Features

### Automatic Cache Purging
- **Post Save Integration**: Automatically purges cache when WordPress posts are published or updated
- **Image Variants**: Purges all image size variants (thumbnails, medium, large, etc.) automatically
- **Content Images**: Extracts and purges images found within post content
- **Asynchronous Processing**: Non-blocking purge operations that won't slow down your site

### Manual Cache Management
- **Specific URL Purging**: Purge individual files or URLs from Cloudflare cache
- **Bulk URL Purging**: Enter multiple URLs (one per line) for batch purging
- **Complete Cache Purge**: Purge entire Cloudflare cache with one click
- **Post-Specific Purging**: Purge all images associated with a specific post ID

### Advanced Features
- **Block Editor Support**: Full compatibility with WordPress Block Editor (Gutenberg)
- **Classic Editor Support**: Works with classic WordPress editor content
- **Operation Logging**: Detailed logs of all purge operations with timestamps and results
- **Connection Testing**: Test your Cloudflare credentials directly from the admin interface
- **Security**: Proper nonce verification and user capability checks

## Installation

1. **Upload the Plugin**
   - Upload the `CloudflarePurger` folder to `/wp-content/plugins/`
   - Or install via WordPress admin: Plugins → Add New → Upload Plugin

2. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Find "Cloudflare Purger" and click "Activate"

3. **Configure Cloudflare Credentials**
   - Go to Settings → Cloudflare Purger
   - Enter your Cloudflare Zone ID and API Token
   - Click "Save Changes"

## Configuration

### Getting Your Cloudflare Credentials

#### Zone ID
1. Log into your Cloudflare dashboard
2. Select your domain
3. Scroll down to the "API" section on the right sidebar
4. Copy the "Zone ID"

#### API Token
1. Go to Cloudflare → My Profile → API Tokens
2. Click "Create Token"
3. Use the "Custom token" template
4. Configure permissions:
   - **Zone:Zone:Read** (to verify the zone)
   - **Zone:Cache Purge:Edit** (to purge cache)
5. Set Zone Resources to include your specific zone
6. Create the token and copy it immediately

### Plugin Settings

#### Cloudflare Credentials
- **Zone ID**: Your Cloudflare zone identifier
- **API Token**: Your custom API token with purge permissions

#### Automatic Purging Settings
- **Auto-purge on Post Save**: Enable/disable automatic purging when posts are saved
- **Purge Attached Images**: Include all attached images and their size variants
- **Purge Content Images**: Include images found within post content
- **Asynchronous Purging**: Process purges in the background (recommended)
- **Log Operations**: Keep logs of purge operations for debugging

## Usage

### Automatic Purging
Once configured, the plugin automatically purges cache when:
- Posts are published
- Posts are updated
- Post content changes

### Manual Purging
Access manual purging via **Tools → Cloudflare Cache**:

1. **Purge Specific URLs**: Enter URLs one per line and click "Purge URLs"
2. **Purge All Cache**: Click "Purge All Cache" to clear entire zone cache
3. **Purge Post Images**: Enter a post ID to purge all associated images

### Monitoring
- View recent purge operations in the log table
- Check operation status (success/error) and response messages
- Test your Cloudflare connection anytime

## Technical Details

### Image Detection
The plugin intelligently detects images using multiple methods:

1. **Attached Media**: WordPress media library attachments
2. **Block Editor**: Core/image blocks and block attributes
3. **Classic Editor**: IMG tags in post content via regex
4. **Size Variants**: All WordPress-generated image sizes

### Performance Considerations
- **Asynchronous Processing**: Purge operations run in background via WordPress cron
- **Minimal Database Impact**: Efficient queries and optional logging
- **Error Handling**: Graceful failure with detailed error messages
- **Timeout Management**: Configurable API timeouts to prevent hanging

### Security Features
- **Nonce Verification**: All AJAX requests protected with WordPress nonces
- **Capability Checks**: Only users with `manage_options` can use the plugin
- **Input Sanitization**: All user inputs properly sanitized and validated
- **Error Logging**: Security events logged to WordPress error log

## Troubleshooting

### Common Issues

#### "Plugin not configured" message
- Ensure you've entered both Zone ID and API Token
- Verify credentials using the "Test Connection" button

#### Purge operations failing
- Check your API token permissions (Zone:Zone:Read, Zone:Cache Purge:Edit)
- Verify the Zone ID is correct for your domain
- Review the purge log for specific error messages

#### Images not being purged
- Ensure "Purge Attached Images" and/or "Purge Content Images" are enabled
- Check that images are properly attached to posts or embedded in content
- Review the purge log to see what URLs were processed

### Debug Information
Enable WordPress debugging to see detailed plugin operation logs:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check `/wp-content/debug.log` for Cloudflare Purger log entries.

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Cloudflare Account**: With API access enabled
- **WordPress Capabilities**: `manage_options` for admin users

## Changelog

### Version 1.0.0
- Initial release
- Automatic post save purging
- Manual cache management interface
- Block and Classic editor support
- Operation logging
- Connection testing
- Security hardening

## Support

For support, feature requests, or bug reports:
- GitHub: [https://github.com/MattGHarvey/CloudflarePurger](https://github.com/MattGHarvey/CloudflarePurger)
- WordPress Plugin Directory: [Coming Soon]

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [Matt Harvey](https://robotsprocket.com)

Based on original utility functions for image cache management in WordPress with Cloudflare integration.