<?php
if (!defined('ABSPATH')) exit;

class CO_Post {

    /**
     * Alla pubblicazione: aggiungi alla coda
     */
    public static function on_save_post($post_id, $post, $update) {
        // Controlli base
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Controlla se il plugin è abilitato
        $enabled = (bool) get_option('co_enabled', 1);
        if (!$enabled) {
            return;
        }
        
        // Controlla se già certificato e non forzare aggiornamento
        $force_on_update = (bool) get_option('co_force_on_update', 1);
        if (!$force_on_update) {
            $last_hash = get_post_meta($post_id, '_co_last_hash', true);
            if ($last_hash) {
                $current_hash = hash('sha256', co_build_payload($post));
                if ($last_hash === $current_hash) {
                    co_log('Hash invariato, salto coda per post: ' . $post_id);
                    return;
                }
            }
        }
        
        co_log('Aggiungo post pubblicato alla coda: ' . $post_id);
        
        // Aggiungi alla coda
        CO_Processor::add_to_queue($post_id);
    }

    /**
     * Certifica un post (chiamata dal processore)
     */
    public static function certify($post_id, $payload, $hash_hex) {
        co_log('Certify chiamato per post: ' . $post_id);
        
        try {
            $endpoint = get_option('co_calendar_endpoint', CO_DEFAULT_CALENDAR);
            $keep_history = (bool) get_option('co_keep_history', 1);
            
            co_log('Endpoint: ' . $endpoint);
            
            // Calcola hash binario
            $hash_binary = hash('sha256', $payload, true);
            
            // Richiedi certificato al server
            co_log('Richiesta OTS a: ' . $endpoint);
            $ots = co_request_ots($hash_binary, $endpoint);
            
            if (!$ots) {
                co_log('ERRORE: co_request_ots ha restituito false');
                throw new Exception('Richiesta OTS fallita');
            }
            
            co_log('OTS ricevuto, lunghezza: ' . strlen($ots));
            
            // Salva file .ots
            $saved = co_save_ots_file($post_id, $ots, $hash_hex);
            if (!$saved) {
                co_log('ERRORE: co_save_ots_file ha fallito');
                throw new Exception('Salvataggio file OTS fallito');
            }
            
            co_log('File OTS salvato: ' . $saved['path']);
            
            // Salva record
            $record = co_store_cert_record($post_id, $saved, $keep_history);
            
            // Aggiorna ultimo hash
            update_post_meta($post_id, '_co_last_hash', $hash_hex);
            update_post_meta($post_id, '_co_last_cert_attempt', current_time('mysql'));
            
            co_log('Certificazione completata con successo per post: ' . $post_id);
            return $record;
            
        } catch (Exception $e) {
            co_log('EXCEPTION in certify: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Badge in fondo al contenuto
     */
    public static function append_badge($content) {
        if (!is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        
        global $post;
        if (!$post) return $content;
        
        $record = co_get_latest_cert($post->ID);
        if (!$record) return $content;
        
        $hide_badge = get_post_meta($post->ID, '_co_hide_badge', true);
        if ($hide_badge) return $content;
        
        wp_enqueue_style('co-style');
        
        return $content . self::render_badge($record, $post->ID);
    }

    /**
     * Renderizza il badge HTML
     */
    public static function render_badge($record, $post_id) {
        $verify_text = get_option('co_badge_text_verify', 
            __('Scarica il file .ots e verifica con il client OpenTimestamps per confermare la marca temporale.', 'copyright-open')
        );
        
        $cert_at = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), 
            strtotime($record['created']));
        
        $url = esc_url($record['url']);
        $hash_short = substr($record['hash_hex'], 0, 12) . '...';
        
        ob_start();
        ?>
        <div class="co-badge">
            <div class="co-header">
                <span class="co-icon">⏱</span>
                <span class="co-title"><?php _e('Certificato temporale Bitcoin', 'copyright-open'); ?></span>
            </div>
            
            <div class="co-content">
                <p>
                    <strong><?php _e('Data certificazione:', 'copyright-open'); ?></strong>
                    <a href="<?php echo $url; ?>" target="_blank" rel="noopener" download>
                        <?php echo esc_html($cert_at); ?> (UTC)
                    </a>
                </p>
                
                <p class="co-verify">
                    <?php echo esc_html($verify_text); ?>
                </p>
                
                <details>
                    <summary><?php _e('Dettagli tecnici', 'copyright-open'); ?></summary>
                    <div class="co-tech">
                        <p><strong><?php _e('Hash SHA-256:', 'copyright-open'); ?></strong> 
                            <code title="<?php echo esc_attr($record['hash_hex']); ?>"><?php echo esc_html($hash_short); ?></code>
                        </p>
                        <p><strong><?php _e('Server OpenTimestamps:', 'copyright-open'); ?></strong> 
                            <?php echo esc_html(get_option('co_calendar_endpoint', CO_DEFAULT_CALENDAR)); ?>
                        </p>
                    </div>
                </details>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode per badge manuale
     */
    public static function shortcode_badge($atts) {
        $atts = shortcode_atts([
            'post_id' => get_the_ID(),
        ], $atts, 'copyright_open_badge');
        
        $post_id = (int) $atts['post_id'];
        if (!$post_id) return '';
        
        $record = co_get_latest_cert($post_id);
        if (!$record) return '';
        
        wp_enqueue_style('co-style');
        return self::render_badge($record, $post_id);
    }
}