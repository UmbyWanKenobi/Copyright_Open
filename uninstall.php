<?php
/**
 * Copyright_Open - Uninstall
 *
 * @package Copyright_Open
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Opzione: eliminare o conservare i dati?
$delete_data = get_option('co_delete_data_on_uninstall', false);

if ($delete_data) {
    global $wpdb;
    
    // Rimuovi opzioni
    $options = [
        'co_enabled',
        'co_force_on_update',
        'co_keep_history',
        'co_calendar_endpoint',
        'co_badge_text_verify',
        'co_delete_data_on_uninstall',
        'co_version',
    ];
    
    foreach ($options as $option) {
        delete_option($option);
    }
    
    // Rimuovi meta post
    $meta_keys = [
        '_co_certs',
        '_co_cert_last',
        '_co_certified_at',
        '_co_ots_url',
        '_co_last_hash',
        '_co_cert_count',
        '_co_last_cert_attempt',
        '_co_hide_badge',
    ];
    
    foreach ($meta_keys as $meta_key) {
        $wpdb->delete(
            $wpdb->postmeta,
            ['meta_key' => $meta_key],
            ['%s']
        );
    }
    
    // Rimuovi transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_co_ots_%' OR option_name LIKE '_transient_timeout_co_ots_%'"
    );
    
    // Rimuovi directory certificati (opzionale)
    $upload_dir = wp_upload_dir();
    $cert_dir = trailingslashit($upload_dir['basedir']) . 'copyright_open';
    
    if (file_exists($cert_dir)) {
        // Rimuovi tutti i file .ots
        $files = glob(trailingslashit($cert_dir) . '*.ots');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        // Rimuovi .htaccess
        $htaccess = trailingslashit($cert_dir) . '.htaccess';
        if (file_exists($htaccess)) {
            unlink($htaccess);
        }
        
        // Rimuovi directory se vuota
        @rmdir($cert_dir);
    }
    
    // Rimuovi eventi schedulati
    $timestamp = wp_next_scheduled('co_daily_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'co_daily_cleanup');
    }
}

// Cancella cache
wp_cache_flush();