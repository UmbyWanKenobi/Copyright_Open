(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Gestione notifiche
        $(document).on('click', '.notice-dismiss', function() {
            var $notice = $(this).closest('.notice');
            $notice.fadeOut();
        });
        
        // Pulsante certificazione (solo visuale)
        $('.co-certify-button').on('click', function(e) {
            var $button = $(this);
            
            // Mostra messaggio informativo
            if (!$button.data('clicked')) {
                e.preventDefault();
                
                var $message = $('<div>', {
                    'class': 'notice notice-info',
                    'style': 'margin: 10px 0; padding: 10px;',
                    'html': '<p><?php echo esc_js(__("La certificazione è stata aggiunta alla coda. Verrà processata entro un\'ora.", "copyright-open")); ?></p>'
                });
                
                $button.after($message);
                $button.data('clicked', true);
                
                // Continua con il redirect dopo 2 secondi
                setTimeout(function() {
                    window.location.href = $button.attr('href');
                }, 2000);
            }
        });
        
        // Aggiorna automaticamente lo stato ogni 30 secondi se ci sono elementi in coda
        var checkPendingStatus = function() {
            $('.co-status.pending').each(function() {
                var $status = $(this);
                var postId = $status.closest('.co-metabox').find('.co-certify-button').data('post-id');
                
                if (postId) {
                    // Controlla se è ancora in coda
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'co_check_status',
                            post_id: postId,
                            nonce: co_admin.nonce
                        },
                        success: function(response) {
                            if (response.success && !response.data.pending) {
                                // Ricarica la pagina
                                location.reload();
                            }
                        }
                    });
                }
            });
        };
        
        // Avvia il controllo se ci sono elementi in coda
        if ($('.co-status.pending').length > 0) {
            setInterval(checkPendingStatus, 30000); // Ogni 30 secondi
        }
    });
    
})(jQuery);