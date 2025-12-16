<?php
/**
 * Plugin Name: Copyright_Open
 * Plugin URI: https://esempio.com/copyright-open
 * Description: Certifica automaticamente post/pagine su OpenTimestamps (Bitcoin). Aggiunge badge con certificato .ots, istruzioni di verifica e supporta cronologia o sovrascrittura dei certificati.
 * Version: 2.0.0
 * Author: Umberto Genovese
 * License: GPLv3
 * Text Domain: copyright-open
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.6
 */

if (!defined('ABSPATH')) exit;

// Definisci costanti
define('CO_PLUGIN_VERSION', '2.0.0');
define('CO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CO_DEFAULT_CALENDAR', 'https://finney.calendar.eternitywall.com/digest');

// DEBUG
if (!function_exists('co_log')) {
    function co_log($message, $data = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = '[Copyright_Open] ' . $message;
            if ($data !== null) {
                if (is_array($data) || is_object($data)) {
                    $log_message .= ' - ' . json_encode($data);
                } else {
                    $log_message .= ' - ' . $data;
                }
            }
            error_log($log_message);
            
            // Log anche in un file dedicato
            $log_file = WP_CONTENT_DIR . '/copyright-open.log';
            $log_entry = '[' . current_time('mysql') . '] ' . $log_message . PHP_EOL;
            @file_put_contents($log_file, $log_entry, FILE_APPEND);
        }
    }
}

// Includes
require_once CO_PLUGIN_DIR . 'includes/helpers.php';
require_once CO_PLUGIN_DIR . 'includes/class-co-admin.php';
require_once CO_PLUGIN_DIR . 'includes/class-co-post.php';
require_once CO_PLUGIN_DIR . 'includes/class-co-metabox.php';
require_once CO_PLUGIN_DIR . 'includes/class-co-processor.php';

/**
 * Inizializzazione plugin
 */
class Copyright_Open_Plugin {
    
    public static function init() {
        // Carica traduzioni
        add_action('plugins_loaded', [__CLASS__, 'load_textdomain']);
        
        // Inizializza stili e script
        add_action('init', [__CLASS__, 'register_assets']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        
        // Hook per l'amministrazione
        add_action('admin_menu', ['CO_Admin', 'register_menu']);
        add_action('admin_init', ['CO_Admin', 'register_settings']);
        
        // Pagina processamento
        add_action('admin_menu', [__CLASS__, 'add_processor_page']);
        
        // Colonne lista post
        add_filter('manage_posts_columns', [__CLASS__, 'add_post_column']);
        add_action('manage_posts_custom_column', [__CLASS__, 'render_post_column'], 10, 2);
        add_filter('manage_pages_columns', [__CLASS__, 'add_post_column']);
        add_action('manage_pages_custom_column', [__CLASS__, 'render_post_column'], 10, 2);
        
        // Metabox certificazione manuale
        add_action('add_meta_boxes', ['CO_Metabox', 'register']);
        
        // Certificazione automatica alla pubblicazione (aggiunge alla coda)
        add_action('save_post', ['CO_Post', 'on_save_post'], 20, 3);
        
        // Badge nel contenuto
        add_filter('the_content', ['CO_Post', 'append_badge']);
        
        // Shortcode per badge manuale
        add_shortcode('copyright_open_badge', ['CO_Post', 'shortcode_badge']);
        
        // Hook per estensibilità
        do_action('copyright_open_init');
        
        // Auto-processamento quando possibile
        add_action('wp_footer', [__CLASS__, 'maybe_process_queue']);
        add_action('admin_footer', [__CLASS__, 'maybe_process_queue']);
    }
    
    /**
     * Carica traduzioni
     */
    public static function load_textdomain() {
        load_plugin_textdomain(
            'copyright-open',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }
    
    /**
     * Registra assets
     */
    public static function register_assets() {
        // Stili frontend
        wp_register_style(
            'co-style',
            CO_PLUGIN_URL . 'assets/style.css',
            [],
            CO_PLUGIN_VERSION
        );
        
        // Script admin
        wp_register_script(
            'co-admin-script',
            CO_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            CO_PLUGIN_VERSION,
            true
        );
        
        // Stili admin
        wp_register_style(
            'co-admin-style',
            CO_PLUGIN_URL . 'assets/admin.css',
            [],
            CO_PLUGIN_VERSION
        );
    }
    
    /**
     * Carica assets admin
     */
    public static function admin_assets($hook) {
        global $post;
        
        // Carica solo nelle pagine rilevanti
        if (in_array($hook, ['post.php', 'post-new.php', 'settings_page_copyright-open', 'tools_page_co-processor'])) {
            wp_enqueue_style('co-admin-style');
            wp_enqueue_script('co-admin-script');
            
            // Localizza script
            wp_localize_script('co-admin-script', 'co_admin', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('co_ajax_nonce'),
                'i18n' => [
                    'certifying' => __('Certificazione in corso...', 'copyright-open'),
                    'success' => __('Certificazione completata!', 'copyright-open'),
                    'error' => __('Errore durante la certificazione', 'copyright-open'),
                ]
            ]);
        }
        
        // Carica nelle liste post
        if (in_array($hook, ['edit.php', 'edit-pages.php'])) {
            wp_enqueue_style('co-admin-style');
        }
    }
    
    /**
     * Aggiungi pagina processamento manuale
     */
    public static function add_processor_page() {
        add_submenu_page(
            'tools.php',
            __('Processa Certificazioni - Copyright_Open', 'copyright-open'),
            __('Certificazioni Open', 'copyright-open'),
            'manage_options',
            'co-processor',
            [__CLASS__, 'render_processor_page']
        );
    }
    
    /**
     * Renderizza pagina processamento
     */
    public static function render_processor_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Processa Certificazioni - Copyright_Open', 'copyright-open'); ?></h1>
            
            <?php
            // Processa se richiesto
            if (isset($_POST['process_now']) && check_admin_referer('co_process_now')) {
                $result = CO_Processor::process_queue();
                ?>
                <div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?>">
                    <p><?php echo esc_html($result['message']); ?></p>
                    <?php if (!empty($result['details'])): ?>
                        <pre><?php echo esc_html($result['details']); ?></pre>
                    <?php endif; ?>
                </div>
                <?php
            }
            
            // Mostra stato
            $queue = CO_Processor::get_queue();
            ?>
            
            <div class="card">
                <h2><?php _e('Stato Sistema', 'copyright-open'); ?></h2>
                <ul>
                    <li><strong><?php _e('Elementi in coda:', 'copyright-open'); ?></strong> <?php echo count($queue); ?></li>
                    <li><strong><?php _e('Ultima esecuzione:', 'copyright-open'); ?></strong> 
                        <?php echo get_option('co_last_process_time', __('Mai', 'copyright-open')); ?>
                    </li>
                    <li><strong><?php _e('Prossima esecuzione automatica:', 'copyright-open'); ?></strong> 
                        <?php 
                        $next_auto = wp_next_scheduled('co_auto_process');
                        echo $next_auto ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_auto) : __('Non schedulato', 'copyright-open');
                        ?>
                    </li>
                </ul>
                
                <form method="post">
                    <?php wp_nonce_field('co_process_now'); ?>
                    <p>
                        <button type="submit" name="process_now" class="button button-primary button-large">
                            <?php _e('Processa Coda Ora', 'copyright-open'); ?>
                        </button>
                        <span class="description">
                            <?php _e('Processa manualmente gli elementi in coda di certificazione.', 'copyright-open'); ?>
                        </span>
                    </p>
                </form>
            </div>
            
            <?php if (!empty($queue)): ?>
            <div class="card">
                <h2><?php _e('Elementi in Coda', 'copyright-open'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('ID Post', 'copyright-open'); ?></th>
                            <th><?php _e('Titolo', 'copyright-open'); ?></th>
                            <th><?php _e('Stato', 'copyright-open'); ?></th>
                            <th><?php _e('Tentativi', 'copyright-open'); ?></th>
                            <th><?php _e('Ultimo Tentativo', 'copyright-open'); ?></th>
                            <th><?php _e('Azione', 'copyright-open'); ?></th>
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
                                        <span style="color:#ccc;"><?php _e('Post eliminato', 'copyright-open'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $status_class = '';
                                    if ($item['attempts'] == 0) {
                                        $status = __('In attesa', 'copyright-open');
                                        $status_class = 'pending';
                                    } elseif ($item['attempts'] >= 3) {
                                        $status = __('Fallito', 'copyright-open');
                                        $status_class = 'failed';
                                    } else {
                                        $status = __('In elaborazione', 'copyright-open');
                                        $status_class = 'processing';
                                    }
                                    ?>
                                    <span class="co-status-<?php echo $status_class; ?>"><?php echo $status; ?></span>
                                </td>
                                <td><?php echo $item['attempts']; ?></td>
                                <td><?php echo $item['last_attempt'] ?: __('Mai', 'copyright-open'); ?></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('co_force_single'); ?>
                                        <input type="hidden" name="force_post_id" value="<?php echo $post_id; ?>">
                                        <button type="submit" name="force_single" class="button button-small">
                                            <?php _e('Forza Ora', 'copyright-open'); ?>
                                        </button>
                                    </form>
                                    
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('co_remove_from_queue'); ?>
                                        <input type="hidden" name="remove_post_id" value="<?php echo $post_id; ?>">
                                        <button type="submit" name="remove_from_queue" class="button button-small button-link-delete">
                                            <?php _e('Rimuovi', 'copyright-open'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <h2><?php _e('Istruzioni', 'copyright-open'); ?></h2>
                <ol>
                    <li><?php _e('I post vengono aggiunti alla coda automaticamente alla pubblicazione', 'copyright-open'); ?></li>
                    <li><?php _e('Clicca "Processa Coda Ora" per certificare manualmente', 'copyright-open'); ?></li>
                    <li><?php _e('Il sistema tenterà di certificare fino a 5 post per volta', 'copyright-open'); ?></li>
                    <li><?php _e('I certificati generati sono disponibili nel metabox di ogni post', 'copyright-open'); ?></li>
                </ol>
                
                <h3><?php _e('Configurazione Cron OVH', 'copyright-open'); ?></h3>
                <p><?php _e('Per l\'esecuzione automatica, configura nel pannello OVH:', 'copyright-open'); ?></p>
                <code>wget -q -O /dev/null "<?php echo home_url('/?co_cron=1&key=' . md5(NONCE_SALT)); ?>"</code>
                <p><small><?php _e('Esegui ogni ora per la certificazione automatica.', 'copyright-open'); ?></small></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Aggiungi colonna certificato alla lista post
     */
    public static function add_post_column($columns) {
        $columns['co_status'] = __('Certificato', 'copyright-open');
        return $columns;
    }
    
    /**
     * Renderizza colonna certificato
     */
    public static function render_post_column($column, $post_id) {
        if ($column === 'co_status') {
            $record = co_get_latest_cert($post_id);
            if ($record) {
                $date = date_i18n(get_option('date_format'), strtotime($record['created']));
                printf(
                    '<span class="co-status certified" title="%s">✓ %s</span>',
                    esc_attr(sprintf(__('Certificato il: %s', 'copyright-open'), $record['created'])),
                    esc_html($date)
                );
            } else {
                // Controlla se è in coda
                $queue = CO_Processor::get_queue();
                if (isset($queue[$post_id])) {
                    echo '<span class="co-status pending" title="' . esc_attr__('In attesa di certificazione', 'copyright-open') . '">⏳</span>';
                } else {
                    echo '<span class="co-status not-certified" title="' . esc_attr__('Non certificato', 'copyright-open') . '">—</span>';
                }
            }
        }
    }
    
    /**
     * Tentativo di processare la coda quando possibile
     */
    public static function maybe_process_queue() {
        // Processa se passato abbastanza tempo dall'ultima esecuzione
        $last_run = get_option('co_last_auto_process', 0);
        $now = time();
        
        // Processa automaticamente ogni 5 minuti se ci sono elementi in coda
        if ($now - $last_run > 300) { // 5 minuti
            $queue = CO_Processor::get_queue();
            if (!empty($queue)) {
                co_log('Auto-processamento della coda (ogni 5 min)');
                CO_Processor::process_queue();
                update_option('co_last_auto_process', $now);
            }
        }
        
        // Processa se richiesto via GET (per cron OVH)
        if (isset($_GET['co_cron']) && $_GET['co_cron'] == '1') {
            $key = $_GET['key'] ?? '';
            if ($key === md5(NONCE_SALT)) {
                co_log('Cron esterno chiamato');
                CO_Processor::process_queue();
                update_option('co_last_process_time', current_time('mysql'));
                echo 'Processato';
                exit;
            }
        }
    }
    
    /**
     * Attivazione plugin
     */
    public static function activate() {
        co_log('Attivazione plugin');
        
        // Crea directory per i certificati
        $upload_dir = wp_upload_dir();
        $cert_dir = trailingslashit($upload_dir['basedir']) . 'copyright_open';
        
        if (!file_exists($cert_dir)) {
            wp_mkdir_p($cert_dir);
            
            // Proteggi directory con .htaccess
            $htaccess = trailingslashit($cert_dir) . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Options -Indexes\nDeny from all");
            }
            
            // Crea index.html vuoto
            $index = trailingslashit($cert_dir) . 'index.html';
            if (!file_exists($index)) {
                file_put_contents($index, '');
            }
        }
        
        // Salva opzioni default se non esistono
        if (false === get_option('co_enabled')) {
            update_option('co_enabled', 1);
            update_option('co_force_on_update', 1);
            update_option('co_keep_history', 1);
            update_option('co_calendar_endpoint', CO_DEFAULT_CALENDAR);
            update_option('co_badge_text_verify', __('Scarica il file .ots e verifica con il client OpenTimestamps per confermare la marca temporale.', 'copyright-open'));
        }
        
        do_action('copyright_open_activated');
        
        co_log('Plugin attivato con successo');
    }
    
    /**
     * Disattivazione plugin
     */
    public static function deactivate() {
        co_log('Disattivazione plugin');
        do_action('copyright_open_deactivated');
    }
}

// Registra hook attivazione/disattivazione
register_activation_hook(__FILE__, ['Copyright_Open_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Copyright_Open_Plugin', 'deactivate']);

// Inizializza plugin
Copyright_Open_Plugin::init();