<?php
if (!defined('ABSPATH')) exit;

/**
 * Crea il payload testuale normalizzato per hash del contenuto
 */
function co_build_payload($post) {
    // Filtra il contenuto prima della pulizia
    $content = apply_filters('copyright_open_raw_content', $post->post_content, $post);
    
    // Rimuovi shortcode se necessario
    if (apply_filters('copyright_open_strip_shortcodes', true)) {
        $content = strip_shortcodes($content);
    }
    
    // Pulisci il contenuto
    $clean_content = wp_kses_post($content);
    
    // Prepara array di campi
    $fields = [
        'type:' . $post->post_type,
        'title:' . get_the_title($post),
        'slug:' . $post->post_name,
        'author:' . (string) $post->post_author,
        'date_gmt:' . (string) $post->post_date_gmt,
        'content:' . $clean_content,
    ];
    
    // Filtra i campi prima dell'assemblaggio
    $fields = apply_filters('copyright_open_payload_fields', $fields, $post);
    
    // Assembla payload
    $payload = implode("\n", $fields);
    
    // Filtra il payload finale
    return apply_filters('copyright_open_final_payload', $payload, $post);
}

/**
 * Restituisce hash binario SHA-256 del payload
 */
function co_compute_hash_binary($payload) {
    // Assicurati che il payload sia una stringa
    if (!is_string($payload)) {
        $payload = (string) $payload;
    }
    
    // Filtra il payload prima dell'hashing
    $payload = apply_filters('copyright_open_pre_hash_payload', $payload);
    
    // Calcola hash
    $hash = hash('sha256', $payload, true);
    
    // Filtra l'hash risultante
    return apply_filters('copyright_open_computed_hash', $hash, $payload);
}

/**
 * Richiesta al calendar OpenTimestamps: ritorna binario .ots o false
 */
/*
function co_request_ots($hash_binary, $endpoint) {
    // Cache per evitare richieste duplicate
    $cache_key = 'co_ots_' . bin2hex($hash_binary);
    $cache_time = apply_filters('copyright_open_cache_time', HOUR_IN_SECONDS);
    
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return apply_filters('copyright_open_cached_ots', $cached, $hash_binary);
    }
    
    // Hook pre-richiesta
    do_action('copyright_open_before_ots_request', $hash_binary, $endpoint);
    
    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Content-Type' => 'application/octet-stream',
            'User-Agent' => 'WordPress Copyright_Open/' . CO_PLUGIN_VERSION,
        ],
        'body'    => $hash_binary,
        'timeout' => apply_filters('copyright_open_request_timeout', 30),
        'blocking' => true,
    ]);
    
    // Hook post-richiesta
    do_action('copyright_open_after_ots_request', $response, $hash_binary, $endpoint);
    
    // Gestione errori
    if (is_wp_error($response)) {
        error_log(sprintf('[Copyright Open] Errore richiesta OTS: %s', $response->get_error_message()));
        return false;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        error_log(sprintf('[Copyright Open] Errore HTTP %d nel recupero OTS', $code));
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        error_log('[Copyright Open] Risposta OTS vuota');
        return false;
    }
    
    // Valida che sia un file OTS valido (minimo 50 bytes)
    if (strlen($body) < 50) {
        error_log('[Copyright Open] File OTS troppo piccolo, probabilmente non valido');
        return false;
    }
    
    // Cache il risultato
    set_transient($cache_key, $body, $cache_time);
    
    return apply_filters('copyright_open_ots_response', $body, $hash_binary);
}
*/
function co_request_ots($hash_binary, $endpoint) {
    co_log('Richiesta OTS a: ' . $endpoint);
    
    // Cache per evitare richieste duplicate
    $cache_key = 'co_ots_' . bin2hex($hash_binary);
    $cache_time = apply_filters('copyright_open_cache_time', HOUR_IN_SECONDS);
    
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        co_log('OTS trovato in cache');
        return $cached;
    }
    
    co_log('Invio richiesta HTTP POST...');
    
    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Content-Type' => 'application/octet-stream',
            'User-Agent' => 'WordPress Copyright_Open/' . CO_PLUGIN_VERSION,
        ],
        'body'    => $hash_binary,
        'timeout' => 30,
        'blocking' => true,
    ]);
    
    if (is_wp_error($response)) {
        co_log('ERRORE wp_remote_post: ' . $response->get_error_message());
        return false;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    co_log('Codice risposta HTTP: ' . $code);
    
    if ($code !== 200) {
        co_log('ERRORE: Codice HTTP non 200');
        $body = wp_remote_retrieve_body($response);
        co_log('Corpo risposta: ' . substr($body, 0, 500));
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    co_log('Risposta ricevuta, lunghezza: ' . strlen($body));
    
    if (empty($body)) {
        co_log('ERRORE: Corpo risposta vuoto');
        return false;
    }
    
    // Valida che sia un file OTS valido (minimo 50 bytes)
    if (strlen($body) < 50) {
        co_log('ERRORE: File OTS troppo piccolo: ' . strlen($body) . ' bytes');
        return false;
    }
    
    co_log('OTS valido ricevuto');
    set_transient($cache_key, $body, $cache_time);
    
    return $body;
}


/**
 * Salva il certificato .ots negli upload e ritorna array con path/url/metadati
 */
function co_save_ots_file($post_id, $ots_binary, $hash_hex) {
    // Prepara directory
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) {
        error_log('[Copyright Open] Errore directory upload: ' . $upload['error']);
        return false;
    }
    
    $base_dir = trailingslashit($upload['basedir']) . 'copyright_open';
    $base_url = trailingslashit($upload['baseurl']) . 'copyright_open';
    
    // Crea directory se non esiste
    if (!file_exists($base_dir)) {
        $created = wp_mkdir_p($base_dir);
        if (!$created) {
            error_log('[Copyright Open] Impossibile creare directory: ' . $base_dir);
            return false;
        }
        
        // Proteggi directory
        $htaccess = trailingslashit($base_dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\nDeny from all");
        }
    }
    
    // Genera nome file univoco
    $timestamp = gmdate('Ymd-His');
    $filename = sprintf('post-%d-%s-%s.ots', 
        $post_id, 
        $timestamp,
        substr($hash_hex, 0, 8)
    );
    
    // Filtra il nome file
    $filename = apply_filters('copyright_open_ots_filename', $filename, $post_id, $hash_hex);
    
    $filepath = trailingslashit($base_dir) . $filename;
    $fileurl  = trailingslashit($base_url) . $filename;
    
    // Salva file
    $written = file_put_contents($filepath, $ots_binary);
    if ($written === false) {
        error_log('[Copyright Open] Impossibile scrivere file: ' . $filepath);
        return false;
    }
    
    // Imposta permessi sicuri
    chmod($filepath, 0644);
    
    // Prepara record
    $record = [
        'path'     => $filepath,
        'url'      => $fileurl,
        'hash_hex' => $hash_hex,
        'created'  => gmdate('c'),
        'filename' => $filename,
        'size'     => $written,
    ];
    
    // Filtra il record prima del ritorno
    return apply_filters('copyright_open_saved_record', $record, $post_id);
}

/**
 * Aggiunge un record certificazione (cronologia) o sovrascrive l'ultimo
 */
function co_store_cert_record($post_id, $record, $keep_history) {
    // Hook pre-salvataggio
    do_action('copyright_open_before_store_record', $post_id, $record, $keep_history);
    
    if ($keep_history) {
        // Modalità cronologia
        $list = get_post_meta($post_id, '_co_certs', true);
        if (!is_array($list)) {
            $list = [];
        }
        
        // Limita cronologia (max 50 certificati)
        if (count($list) >= 50) {
            $list = array_slice($list, -49); // Mantieni ultimi 49
        }
        
        $list[] = $record;
        update_post_meta($post_id, '_co_certs', $list);
        
        // Aggiorna anche l'ultimo certificato per compatibilità
        update_post_meta($post_id, '_co_cert_last', $record);
    } else {
        // Modalità sovrascrittura
        update_post_meta($post_id, '_co_cert_last', $record);
    }
    
    // Metadati veloci per badge
    update_post_meta($post_id, '_co_certified_at', $record['created']);
    update_post_meta($post_id, '_co_ots_url', $record['url']);
    update_post_meta($post_id, '_co_last_hash', $record['hash_hex']);
    
    // Incrementa contatore
    $count = (int) get_post_meta($post_id, '_co_cert_count', true);
    update_post_meta($post_id, '_co_cert_count', $count + 1);
    
    // Hook post-salvataggio
    do_action('copyright_open_after_store_record', $post_id, $record, $keep_history);
    
    return $record;
}

/**
 * Recupera ultimo certificato (per badge)
 */
function co_get_latest_cert($post_id) {
    // Prima cerca l'ultimo certificato
    $last = get_post_meta($post_id, '_co_cert_last', true);
    if (is_array($last) && !empty($last)) {
        return apply_filters('copyright_open_latest_cert', $last, $post_id);
    }
    
    // Se si usa cronologia, prendi l'ultimo dalla lista
    $list = get_post_meta($post_id, '_co_certs', true);
    if (is_array($list) && !empty($list)) {
        $latest = end($list);
        return apply_filters('copyright_open_latest_cert', $latest, $post_id);
    }
    
    return false;
}

/**
 * Recupera tutti i certificati per un post
 */
function co_get_all_certs($post_id) {
    $certs = [];
    
    $last = get_post_meta($post_id, '_co_cert_last', true);
    if (is_array($last)) {
        $certs[] = $last;
    }
    
    $list = get_post_meta($post_id, '_co_certs', true);
    if (is_array($list)) {
        $certs = array_merge($certs, $list);
    }
    
    return apply_filters('copyright_open_all_certs', array_unique($certs, SORT_REGULAR), $post_id);
}

/**
 * Verifica se un post è certificato
 */
function co_is_post_certified($post_id) {
    $record = co_get_latest_cert($post_id);
    return !empty($record);
}

/**
 * Pulisci cache OTS
 */
function co_clear_ots_cache($hash_hex = '') {
    if ($hash_hex) {
        $cache_key = 'co_ots_' . $hash_hex;
        delete_transient($cache_key);
    } else {
        // Pulisci tutta la cache OTS
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_co_ots_%' OR option_name LIKE '_transient_timeout_co_ots_%'"
        );
    }
}