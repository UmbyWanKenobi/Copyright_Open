<?php
if (!defined('ABSPATH')) exit;

class CO_Admin {

    public static function register_menu() {
        add_options_page(
            __('Copyright_Open', 'copyright-open'),
            __('Copyright_Open', 'copyright-open'),
            'manage_options',
            'copyright-open',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings() {
        // Sezione principale
        add_settings_section(
            'co_main_section',
            __('Impostazioni Certificazione', 'copyright-open'),
            [__CLASS__, 'render_section_description'],
            'co_settings'
        );

        // Campo: Abilita certificazione automatica
        add_settings_field(
            'co_enabled',
            __('Abilita certificazione automatica', 'copyright-open'),
            [__CLASS__, 'render_checkbox_field'],
            'co_settings',
            'co_main_section',
            [
                'name' => 'co_enabled',
                'label' => __('Certifica post/pagine alla pubblicazione.', 'copyright-open'),
                'default' => 1
            ]
        );

        // Campo: Rivalida ad ogni aggiornamento
        add_settings_field(
            'co_force_on_update',
            __('Rivalida ad ogni aggiornamento', 'copyright-open'),
            [__CLASS__, 'render_checkbox_field'],
            'co_settings',
            'co_main_section',
            [
                'name' => 'co_force_on_update',
                'label' => __('Se il contenuto cambia, genera un nuovo certificato.', 'copyright-open'),
                'default' => 1
            ]
        );

        // Campo: Mantieni cronologia certificati
        add_settings_field(
            'co_keep_history',
            __('Mantieni cronologia certificati', 'copyright-open'),
            [__CLASS__, 'render_checkbox_field'],
            'co_settings',
            'co_main_section',
            [
                'name' => 'co_keep_history',
                'label' => __('Conserva tutti i certificati invece di sovrascrivere l\'ultimo.', 'copyright-open'),
                'default' => 1
            ]
        );

        // Campo: Calendar endpoint
        add_settings_field(
            'co_calendar_endpoint',
            __('Calendar endpoint', 'copyright-open'),
            [__CLASS__, 'render_url_field'],
            'co_settings',
            'co_main_section',
            [
                'name' => 'co_calendar_endpoint',
                'label' => __('Server OpenTimestamps (default: alice.btc.calendar.opentimestamps.org).', 'copyright-open'),
                'default' => CO_DEFAULT_CALENDAR,
                'placeholder' => 'https://alice.btc.calendar.opentimestamps.org'
            ]
        );

        // Campo: Testo istruzioni di verifica
        add_settings_field(
            'co_badge_text_verify',
            __('Testo istruzioni di verifica', 'copyright-open'),
            [__CLASS__, 'render_text_field'],
            'co_settings',
            'co_main_section',
            [
                'name' => 'co_badge_text_verify',
                'label' => __('Comparirà nel badge a fine articolo.', 'copyright-open'),
                'default' => __('Scarica il file .ots e verifica con il client OpenTimestamps per confermare la marca temporale.', 'copyright-open'),
                'placeholder' => __('Inserisci il testo di istruzioni...', 'copyright-open')
            ]
        );

        // Registra le impostazioni
        register_setting('co_settings', 'co_enabled', [
            'type' => 'boolean',
            'default' => 1,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]);
        
        register_setting('co_settings', 'co_force_on_update', [
            'type' => 'boolean',
            'default' => 1,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]);
        
        register_setting('co_settings', 'co_keep_history', [
            'type' => 'boolean',
            'default' => 1,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ]);
        
        register_setting('co_settings', 'co_calendar_endpoint', [
            'type' => 'string',
            'default' => CO_DEFAULT_CALENDAR,
            'sanitize_callback' => function($value) {
                $value = esc_url_raw(trim($value));
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    add_settings_error(
                        'co_calendar_endpoint',
                        'invalid_url',
                        __('URL del calendar non valido.', 'copyright-open')
                    );
                    return CO_DEFAULT_CALENDAR;
                }
                return $value;
            }
        ]);
        
        register_setting('co_settings', 'co_badge_text_verify', [
            'type' => 'string',
            'default' => __('Scarica il file .ots e verifica con il client OpenTimestamps per confermare la marca temporale.', 'copyright-open'),
            'sanitize_callback' => 'sanitize_textarea_field'
        ]);
    }

    /**
     * Descrizione sezione
     */
    public static function render_section_description() {
        echo '<p>' . esc_html__('Configura le impostazioni per la certificazione temporale dei contenuti su Bitcoin tramite OpenTimestamps.', 'copyright-open') . '</p>';
    }

    /**
     * Render campo checkbox
     */
    public static function render_checkbox_field($args) {
        $value = get_option($args['name'], $args['default']);
        ?>
        <fieldset>
            <legend class="screen-reader-text"><span><?php echo esc_attr($args['label']); ?></span></legend>
            <label>
                <input type="checkbox" 
                       name="<?php echo esc_attr($args['name']); ?>" 
                       value="1" 
                       <?php checked($value, 1); ?> />
                <span class="description"><?php echo esc_html($args['label']); ?></span>
            </label>
        </fieldset>
        <?php
    }

    /**
     * Render campo URL
     */
    public static function render_url_field($args) {
        $value = get_option($args['name'], $args['default']);
        ?>
        <input type="url" 
               name="<?php echo esc_attr($args['name']); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" 
               placeholder="<?php echo esc_attr($args['placeholder']); ?>" />
        <p class="description"><?php echo esc_html($args['label']); ?></p>
        <?php
    }

    /**
     * Render campo testo
     */
    public static function render_text_field($args) {
        $value = get_option($args['name'], $args['default']);
        ?>
        <input type="text" 
               name="<?php echo esc_attr($args['name']); ?>" 
               value="<?php echo esc_attr($value); ?>" 
               class="regular-text" 
               placeholder="<?php echo esc_attr($args['placeholder']); ?>" />
        <p class="description"><?php echo esc_html($args['label']); ?></p>
        <?php
    }

    /**
     * Render pagina impostazioni
     */
    public static function render_settings_page() {
        // Mostra eventuali errori
        settings_errors();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Copyright_Open', 'copyright-open'); ?></h1>
            
            <div class="card">
                <h2><?php echo esc_html__('Statistiche', 'copyright-open'); ?></h2>
                <?php
                global $wpdb;
                $total_certified = $wpdb->get_var(
                    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_co_certified_at'"
                );
                $total_certs = $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_co_certified_at'"
                );
                ?>
                <ul>
                    <li><strong><?php echo esc_html__('Contenuti certificati:', 'copyright-open'); ?></strong> <?php echo (int) $total_certified; ?></li>
                    <li><strong><?php echo esc_html__('Certificati totali:', 'copyright-open'); ?></strong> <?php echo (int) $total_certs; ?></li>
                    <li><strong><?php echo esc_html__('Calendar in uso:', 'copyright-open'); ?></strong> <?php echo esc_html(get_option('co_calendar_endpoint', CO_DEFAULT_CALENDAR)); ?></li>
                </ul>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('co_settings'); ?>
                <?php do_settings_sections('co_settings'); ?>
                
                <?php submit_button(__('Salva impostazioni', 'copyright-open')); ?>
            </form>

            <div class="card">
                <h2><?php echo esc_html__('Informazioni', 'copyright-open'); ?></h2>
                <p><?php echo esc_html__('Il plugin genera una marca temporale immutabile su Bitcoin per ogni contenuto pubblicato.', 'copyright-open'); ?></p>
                <ul>
                    <li><?php echo esc_html__('Ogni certificato è un file .ots che può essere verificato con OpenTimestamps', 'copyright-open'); ?></li>
                    <li><?php echo esc_html__('I certificati sono salvati nella directory uploads/copyright_open/', 'copyright-open'); ?></li>
                    <li><?php echo esc_html__('I contenuti sono identificati da un hash SHA-256 del loro contenuto', 'copyright-open'); ?></li>
                </ul>
                <p><a href="https://opentimestamps.org/" target="_blank" rel="noopener"><?php echo esc_html__('Documentazione OpenTimestamps', 'copyright-open'); ?></a></p>
            </div>
        </div>
        <?php
    }
}