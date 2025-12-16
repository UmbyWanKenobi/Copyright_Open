<?php
if (!defined('ABSPATH')) exit;

class CO_Queue_Page {
    
    public static function register_menu() {
        add_submenu_page(
            'options-general.php',
            __('Coda Certificazione - Copyright_Open', 'copyright-open'),
            __('Coda Certificazione', 'copyright-open'),
            'manage_options',
            'copyright-open-queue',
            [__CLASS__, 'render_page']
        );
    }
    
    public static function render_page() {
        $queue = get_option('co_cert_queue', []);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Coda di Certificazione - Copyright_Open', 'copyright-open'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Stato Coda', 'copyright-open'); ?></h2>
                <p><?php printf(__('Elementi in coda: %d', 'copyright-open'), count($queue)); ?></p>
                <p><?php printf(__('Prossimo processamento: %s', 'copyright-open'), 
                    wp_next_scheduled('co_process_queue_hourly') ? 
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), wp_next_scheduled('co_process_queue_hourly')) : 
                    __('Non schedulato', 'copyright-open')
                ); ?></p>
                
                <?php if (isset($_GET['process_now']) && check_admin_referer('co_process_now')): ?>
                    <?php CO_Cron::process_queue(); ?>
                    <div class="notice notice-success">
                        <p><?php _e('Coda processata manualmente.', 'copyright-open'); ?></p>
                    </div>
                <?php endif; ?>
                
                <p>
                    <a href="<?php echo esc_url(wp_nonce_url(
                        add_query_arg(['process_now' => 1]),
                        'co_process_now'
                    )); ?>" class="button button-primary">
                        <?php _e('Processa Coda Ora', 'copyright-open'); ?>
                    </a>
                </p>
            </div>
            
            <?php if (!empty($queue)): ?>
            <div class="card">
                <h2><?php _e('Elementi in Coda', 'copyright-open'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('ID Post', 'copyright-open'); ?></th>
                            <th><?php _e('Titolo', 'copyright-open'); ?></th>
                            <th><?php _e('Tentativi', 'copyright-open'); ?></th>
                            <th><?php _e('Ultimo Tentativo', 'copyright-open'); ?></th>
                            <th><?php _e('Aggiunto', 'copyright-open'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queue as $post_id => $item): ?>
                            <?php $post = get_post($post_id); ?>
                            <tr>
                                <td><?php echo $post_id; ?></td>
                                <td>
                                    <?php if ($post): ?>
                                        <a href="<?php echo get_edit_post_link($post_id); ?>">
                                            <?php echo esc_html($post->post_title); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php _e('Post eliminato', 'copyright-open'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $item['attempts']; ?></td>
                                <td><?php echo $item['last_attempt'] ?: __('Mai', 'copyright-open'); ?></td>
                                <td><?php echo $item['added']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Aggiungi la pagina di gestione coda
add_action('admin_menu', ['CO_Queue_Page', 'register_menu']);