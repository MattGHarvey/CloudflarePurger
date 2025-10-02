/**
 * Cloudflare Purger Admin JavaScript
 */
jQuery(document).ready(function($) {
    
    // Test Cloudflare connection
    $('#test-cloudflare-connection').on('click', function() {
        var button = $(this);
        var resultDiv = $('#test-result');
        
        button.prop('disabled', true).text('Testing...');
        resultDiv.removeClass('success error').html('');
        
        $.ajax({
            url: cloudflare_purger_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cloudflare_test_connection',
                nonce: cloudflare_purger_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.addClass('success').html('<p><strong>✓ ' + response.data + '</strong></p>');
                } else {
                    resultDiv.addClass('error').html('<p><strong>✗ ' + response.data + '</strong></p>');
                }
            },
            error: function() {
                resultDiv.addClass('error').html('<p><strong>✗ Connection test failed</strong></p>');
            },
            complete: function() {
                button.prop('disabled', false).text('Test Connection');
            }
        });
    });
    
    // Purge specific URLs
    $('#purge-specific-urls').on('click', function() {
        var button = $(this);
        var urls = $('#purge-urls').val().trim();
        var resultDiv = $('#purge-results');
        
        if (!urls) {
            alert('Please enter at least one URL to purge.');
            return;
        }
        
        button.prop('disabled', true).text('Purging...');
        resultDiv.removeClass('success error').html('<p>Processing purge request...</p>');
        
        $.ajax({
            url: cloudflare_purger_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cloudflare_purge_url',
                urls: urls,
                nonce: cloudflare_purger_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.addClass('success').html('<p><strong>✓ ' + response.data + '</strong></p>');
                    $('#purge-urls').val(''); // Clear the textarea
                } else {
                    resultDiv.addClass('error').html('<p><strong>✗ ' + response.data + '</strong></p>');
                }
            },
            error: function() {
                resultDiv.addClass('error').html('<p><strong>✗ Purge request failed</strong></p>');
            },
            complete: function() {
                button.prop('disabled', false).text('Purge URLs');
                // Refresh the log after a successful operation
                if (typeof refreshPurgeLog === 'function') {
                    setTimeout(refreshPurgeLog, 1000);
                }
            }
        });
    });
    
    // Purge all cache
    $('#purge-all-cache').on('click', function() {
        var button = $(this);
        var resultDiv = $('#purge-results');
        
        button.prop('disabled', true).text('Purging...');
        resultDiv.removeClass('success error').html('<p>Processing purge all request...</p>');
        
        $.ajax({
            url: cloudflare_purger_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cloudflare_purge_all',
                nonce: cloudflare_purger_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.addClass('success').html('<p><strong>✓ ' + response.data + '</strong></p>');
                } else {
                    resultDiv.addClass('error').html('<p><strong>✗ ' + response.data + '</strong></p>');
                }
            },
            error: function() {
                resultDiv.addClass('error').html('<p><strong>✗ Purge all request failed</strong></p>');
            },
            complete: function() {
                button.prop('disabled', false).text('Purge All Cache');
                // Refresh the log after a successful operation
                if (typeof refreshPurgeLog === 'function') {
                    setTimeout(refreshPurgeLog, 1000);
                }
            }
        });
    });
    
    // Purge post images
    $('#purge-post-images').on('click', function() {
        var button = $(this);
        var postId = $('#post-id-purge').val();
        var resultDiv = $('#purge-results');
        
        if (!postId || postId < 1) {
            alert('Please enter a valid post ID.');
            return;
        }
        
        button.prop('disabled', true).text('Purging...');
        resultDiv.removeClass('success error').html('<p>Processing post image purge...</p>');
        
        $.ajax({
            url: cloudflare_purger_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cloudflare_purge_post',
                post_id: postId,
                nonce: cloudflare_purger_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.addClass('success').html('<p><strong>✓ ' + response.data + '</strong></p>');
                    $('#post-id-purge').val(''); // Clear the input
                } else {
                    resultDiv.addClass('error').html('<p><strong>✗ ' + response.data + '</strong></p>');
                }
            },
            error: function() {
                resultDiv.addClass('error').html('<p><strong>✗ Post purge request failed</strong></p>');
            },
            complete: function() {
                button.prop('disabled', false).text('Purge Post Images');
                // Refresh the log after a successful operation
                if (typeof refreshPurgeLog === 'function') {
                    setTimeout(refreshPurgeLog, 1000);
                }
            }
        });
    });
    
    // Auto-refresh results every 30 seconds on the cache management page
    if ($('#purge-results').length > 0) {
        setInterval(function() {
            // Optional: Auto-refresh the purge log
            if (typeof refreshPurgeLog === 'function') {
                refreshPurgeLog();
            }
        }, 30000);
    }
    
});

// Function to refresh the purge log (could be enhanced to use AJAX)
function refreshPurgeLog() {
    // For now, we'll just reload the page section
    // This could be improved to use AJAX to refresh just the log table
    if (window.location.href.indexOf('cloudflare-cache-management') !== -1) {
        location.reload();
    }
}