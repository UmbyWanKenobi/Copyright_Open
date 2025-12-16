<?php
if (!defined('ABSPATH')) exit;

class CO_Metabox {

    public static function register() {
        $post_types = apply_filters('copyright_open_post_types', ['post', 'page']);
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'co-cert-box',
                __('Copyright_Open — Certificazione', 'copyright-open'),
                [__CLASS__, 'render'],
                $post_type,
                'side',
                'high'
            );
        }
    }

    public static function render($post) {
        wp_nonce_field('co_metabox_action', 'co_metabox_nonce');
        
        $record = co_get_latest_cert($post->ID);
        $queue = CO_Processor::get_queue();
        $in_queue = isset($queue[$post->ID]);
        
        // Stato
        if ($record) {
            $status = 'certified';
            $status_text = __('Certificato', 'copyright-open');
            $status_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($record['created']));
        } elseif ($in_queue) {
            $status = 'pending';
            $status_text = __('In coda', 'copyright-open');
            $attempts = $queue[$post->ID]['attempts'];
            $status_date = sprintf(__('Tentativo %d', 'copyright-open'), $attempts + 1);
        } else {
            $status = 'not-certified';
            $status_text = __('Non certificato', 'copyright-open');
            $status_date = '';
        }
        
        ?>
        <div class="co-metabox">
            <div class="co-status co-status-<?php echo $status; ?>">
                <strong><?php echo $status_text; ?></strong>
                <?php if ($status_date): ?>
                    <br><small><?php echo $status_date; ?></small>
                <?php endif; ?>
            </div>
            
            <div class="co-actions">
                <?php if ($status === 'certified'): ?>
                    <a href="<?php echo esc_url($record['url']); ?>" 
                       class="button button-primary" 
                       target="_blank" 
                       download>
                        <?php _e('Scarica .ots', 'copyright-open'); ?>
                    </a>
                    
                    <?php if (get_option('co_force_on_update', 1)): ?>
                        <button type="button" 
                                class="button co-recertify-button" 
                                data-post-id="<?php echo $post->ID; ?>">
                            <?php _e('Rigenera', 'copyright-open'); ?>
                        </button>
                    <?php endif; ?>
                    
                <?php elseif ($status === 'pending'): ?>
                    <button type="button" class="button" disabled>
                        <?php _e('In elaborazione...', 'copyright-open'); ?>
                    </button>
                    
                    <a href="<?php echo admin_url('tools.php?page=co-processor'); ?>" 
                       class="button button-link">
                        <?php _e('Gestisci coda', 'copyright-open'); ?>
                    </a>
                    
                <?php else: ?>
                    <button type="button" 
                            class="button button-primary co-certify-button" 
                            data-post-id="<?php echo $post->ID; ?>">
                        <?php _e('Certifica ora', 'copyright-open'); ?>
                    </button>
                <?php endif; ?>
            </div>
            
            <?php if ($record): ?>
                <div class="co-info">
                    <p>
                        <small>
                            <?php printf(
                                __('Hash: %s', 'copyright-open'),
                                '<code title="' . esc_attr($record['hash_hex']) . '">' . 
                                substr($record['hash_hex'], 0, 12) . '...</code>'
                            ); ?>
                        </small>
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="co-hide-badge">
                <label>
                    <input type="checkbox" 
                           name="co_hide_badge" 
                           value="1" 
                           <?php checked(get_post_meta($post->ID, '_co_hide_badge', true)); ?> />
                    <?php _e('Nascondi badge pubblico', 'copyright-open'); ?>
                </label>
            </div>
            
            <p class="description">
                <small>
                    <?php if ($in_queue): ?>
                        <?php _e('Il post è in coda di certificazione.', 'copyright-open'); ?>
                        <a href="<?php echo admin_url('tools.php?page=co-processor'); ?>">
                            <?php _e('Vai alla coda', 'copyright-open'); ?>
                        </a>
                    <?php else: ?>
                        <?php _e('Certifica questo contenuto sulla blockchain Bitcoin.', 'copyright-open'); ?>
                    <?php endif; ?>
                </small>
            </p>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.co-certify-button, .co-recertify-button').on('click', function() {
                var $button = $(this);
                var postId = $button.data('post-id');
                var action = $button.hasClass('co-recertify-button') ? 'recertify' : 'certify';
                
                $button.text('<?php _e('Aggiungendo alla coda...', 'copyright-open'); ?>').prop('disabled', true);
                
                $.post(ajaxurl, {
                    action: 'co_add_to_queue',
                    post_id: postId,
                    nonce: '<?php echo wp_create_nonce('co_queue_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('<?php _e('Post aggiunto alla coda di certificazione. Verrà processato a breve.', 'copyright-open'); ?>');
                        location.reload();
                    } else {
                        alert('<?php _e('Errore: ', 'copyright-open'); ?>' + response.data);
                        $button.text(action === 'recertify' ? '<?php _e('Rigenera', 'copyright-open'); ?>' : '<?php _e('Certifica ora', 'copyright-open'); ?>').prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Aggiungi post alla coda
     */
    public static function ajax_add_to_queue() {
        if (!check_ajax_referer('co_queue_nonce', 'nonce', false)) {
            wp_die(json_encode([
                'success' => false,
                'data' => __('Verifica di sicurezza fallita.', 'copyright-open')
            ]));
        }
        
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(json_encode([
                'success' => false,
                'data' => __('Permessi insufficienti.', 'copyright-open')
            ]));
        }
        
        $result = CO_Processor::add_to_queue($post_id);
        
        wp_die(json_encode([
            'success' => $result,
            'data' => $result ? 
                __('Aggiunto alla coda.', 'copyright-open') : 
                __('Errore nell\'aggiunta alla coda.', 'copyright-open')
        ]));
    }
    
    /**
     * Salva impostazioni metabox
     */
    public static function save_post($post_id) {
        if (!isset($_POST['co_metabox_nonce']) || 
            !wp_verify_nonce($_POST['co_metabox_nonce'], 'co_metabox_action')) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $hide_badge = isset($_POST['co_hide_badge']) ? 1 : 0;
        update_post_meta($post_id, '_co_hide_badge', $hide_badge);
    }
}

// Hook per salvataggio metabox
add_action('save_post', ['CO_Metabox', 'save_post']);

// AJAX per aggiungere alla coda
add_action('wp_ajax_co_add_to_queue', ['CO_Metabox', 'ajax_add_to_queue']);