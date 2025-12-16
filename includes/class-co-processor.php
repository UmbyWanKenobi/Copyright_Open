<?php
if (!defined('ABSPATH')) exit;

class CO_Processor {
    
    /**
     * Aggiungi un post alla coda di certificazione
     */
    public static function add_to_queue($post_id) {
        co_log('Aggiungo post alla coda: ' . $post_id);
        
        $post = get_post($post_id);
        if (!$post) {
            co_log('ERRORE: Post non trovato: ' . $post_id);
            return false;
        }
        
        // Prepara i dati per la certificazione
        $payload = co_build_payload($post);
        $hash_hex = hash('sha256', $payload);
        
        $queue_data = [
            'post_id' => $post_id,
            'payload' => $payload,
            'hash_hex' => $hash_hex,
            'added' => current_time('mysql'),
            'attempts' => 0,
            'last_attempt' => null,
            'status' => 'pending'
        ];
        
        // Salva nella coda
        $queue = self::get_queue();
        $queue[$post_id] = $queue_data;
        self::save_queue($queue);
        
        // Marca il post come in attesa
        update_post_meta($post_id, '_co_pending_certification', true);
        
        co_log('Post aggiunto alla coda: ' . $post_id);
        return true;
    }
    
    /**
     * Processa la coda di certificazione
     */
    public static function process_queue() {
        co_log('Inizio processamento coda');
        
        $queue = self::get_queue();
        if (empty($queue)) {
            co_log('Coda vuota');
            return [
                'success' => true,
                'message' => __('Nessun elemento in coda.', 'copyright-open'),
                'processed' => 0
            ];
        }
        
        co_log('Elementi in coda: ' . count($queue));
        
        // Processa massimo 5 elementi per esecuzione
        $processed = 0;
        $max_per_run = 5;
        $success_count = 0;
        $error_count = 0;
        
        foreach ($queue as $post_id => $data) {
            if ($processed >= $max_per_run) {
                co_log('Limite massimo raggiunto (' . $max_per_run . ')');
                break;
            }
            
            // Salta se già fallito 3 volte
            if ($data['attempts'] >= 3) {
                co_log('Saltato post ' . $post_id . ': troppi tentativi falliti');
                continue;
            }
            
            co_log('Processo post: ' . $post_id . ' (tentativo ' . ($data['attempts'] + 1) . ')');
            
            // Incrementa tentativi
            $queue[$post_id]['attempts']++;
            $queue[$post_id]['last_attempt'] = current_time('mysql');
            $queue[$post_id]['status'] = 'processing';
            
            // Tentativo di certificazione
            $result = CO_Post::certify(
                $post_id,
                $data['payload'],
                $data['hash_hex']
            );
            
            if ($result !== false) {
                // Successo: rimuovi dalla coda
                co_log('SUCCESSO: Certificazione completata per post: ' . $post_id);
                unset($queue[$post_id]);
                
                // Rimuovi flag pending
                delete_post_meta($post_id, '_co_pending_certification');
                
                // Aggiorna colonna lista post
                clean_post_cache($post_id);
                
                $success_count++;
            } else {
                // Fallimento
                co_log('ERRORE: Certificazione fallita per post: ' . $post_id);
                $queue[$post_id]['status'] = 'failed';
                
                // Se troppi tentativi falliti, rimuovi dalla coda
                if ($queue[$post_id]['attempts'] >= 3) {
                    co_log('Rimuovo post ' . $post_id . ': troppi tentativi falliti');
                    unset($queue[$post_id]);
                    delete_post_meta($post_id, '_co_pending_certification');
                }
                
                $error_count++;
            }
            
            $processed++;
            
            // Piccola pausa per non sovraccaricare il server OTS
            if ($processed < $max_per_run) {
                sleep(1);
            }
        }
        
        // Salva coda aggiornata
        self::save_queue($queue);
        
        // Salva tempo ultimo processamento
        update_option('co_last_process_time', current_time('mysql'));
        
        $message = sprintf(
            __('Processati %d elementi: %d successi, %d errori. Elementi rimasti in coda: %d.', 'copyright-open'),
            $processed,
            $success_count,
            $error_count,
            count($queue)
        );
        
        co_log('Processamento completato: ' . $message);
        
        return [
            'success' => true,
            'message' => $message,
            'processed' => $processed,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'remaining' => count($queue)
        ];
    }
    
    /**
     * Ottieni la coda corrente
     */
    public static function get_queue() {
        $queue = get_option('co_cert_queue', []);
        return is_array($queue) ? $queue : [];
    }
    
    /**
     * Salva la coda
     */
    private static function save_queue($queue) {
        update_option('co_cert_queue', $queue);
    }
    
    /**
     * Rimuovi un post dalla coda
     */
    public static function remove_from_queue($post_id) {
        $queue = self::get_queue();
        if (isset($queue[$post_id])) {
            unset($queue[$post_id]);
            self::save_queue($queue);
            delete_post_meta($post_id, '_co_pending_certification');
            return true;
        }
        return false;
    }
    
    /**
     * Forza la certificazione di un singolo post
     */
    public static function force_certify($post_id) {
        $queue = self::get_queue();
        
        if (!isset($queue[$post_id])) {
            co_log('Post non in coda: ' . $post_id);
            
            // Aggiungi alla coda
            $post = get_post($post_id);
            if (!$post) {
                return [
                    'success' => false,
                    'message' => __('Post non trovato.', 'copyright-open')
                ];
            }
            
            self::add_to_queue($post_id);
            $queue = self::get_queue();
        }
        
        if (!isset($queue[$post_id])) {
            return [
                'success' => false,
                'message' => __('Impossibile aggiungere il post alla coda.', 'copyright-open')
            ];
        }
        
        co_log('Forzo certificazione per post: ' . $post_id);
        
        // Esegui certificazione immediata
        $result = CO_Post::certify(
            $post_id,
            $queue[$post_id]['payload'],
            $queue[$post_id]['hash_hex']
        );
        
        if ($result !== false) {
            // Rimuovi dalla coda
            self::remove_from_queue($post_id);
            
            return [
                'success' => true,
                'message' => __('Certificazione completata con successo!', 'copyright-open'),
                'record' => $result
            ];
        } else {
            return [
                'success' => false,
                'message' => __('Certificazione fallita dopo tentativo forzato.', 'copyright-open')
            ];
        }
    }
    
    /**
     * Pulisci coda vecchia (più di 7 giorni)
     */
    public static function cleanup_old_queue() {
        $queue = self::get_queue();
        $removed = 0;
        
        foreach ($queue as $post_id => $data) {
            $added = strtotime($data['added']);
            $now = time();
            
            // Rimuovi elementi più vecchi di 7 giorni
            if ($now - $added > 604800) { // 7 giorni in secondi
                unset($queue[$post_id]);
                delete_post_meta($post_id, '_co_pending_certification');
                $removed++;
            }
        }
        
        if ($removed > 0) {
            self::save_queue($queue);
            co_log('Pulita coda vecchia: rimossi ' . $removed . ' elementi');
        }
        
        return $removed;
    }
}

// Gestisci azioni dalla pagina processamento
add_action('admin_init', function() {
    // Forza certificazione singola
    if (isset($_POST['force_single']) && check_admin_referer('co_force_single')) {
        $post_id = (int) $_POST['force_post_id'];
        $result = CO_Processor::force_certify($post_id);
        
        // Reindirizza con messaggio
        wp_redirect(add_query_arg([
            'page' => 'co-processor',
            'message' => urlencode($result['message']),
            'success' => $result['success'] ? '1' : '0'
        ], admin_url('tools.php')));
        exit;
    }
    
    // Rimuovi dalla coda
    if (isset($_POST['remove_from_queue']) && check_admin_referer('co_remove_from_queue')) {
        $post_id = (int) $_POST['remove_post_id'];
        CO_Processor::remove_from_queue($post_id);
        
        wp_redirect(add_query_arg([
            'page' => 'co-processor',
            'message' => urlencode(__('Elemento rimosso dalla coda.', 'copyright-open'))
        ], admin_url('tools.php')));
        exit;
    }
    
    // Cleanup automatico settimanale
    $last_cleanup = get_option('co_last_cleanup', 0);
    if (time() - $last_cleanup > 604800) { // Ogni 7 giorni
        CO_Processor::cleanup_old_queue();
        update_option('co_last_cleanup', time());
    }
});