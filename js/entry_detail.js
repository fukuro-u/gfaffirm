window.GFAFFIRMEntryActions = null;

(function($) {
    window.GFAFFIRMEntryActions = function() {
        var self = this;

        self.init = function() {
            self.handleButtonClick();
        };
        self.handleButtonClick = function() {
            $('.affirm-payment-action').on('click', function() {
                var $spinner = jQuery('#affirm_ajax_spinner');
                var $button = jQuery(this);
                var api_action = $button.attr('data-api-action');

                if ('refund' === api_action && !confirm(gform_affirm_entry_strings.refund_confirmation)) {
                    return false;
                }

                if ('void' === api_action && !confirm(gform_affirm_entry_strings.void_confirmation)) {
                    return false;
                }

                $spinner.show();
                $button.prop('disabled', true);

                jQuery.ajax({
                    url: gform_affirm_entry_strings.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'gfaffirm_payment_details_action',
                        nonce: gform_affirm_entry_strings.payment_details_action_nonce,
                        entry_id: $button.data('entry-id'),
                        api_action: $button.data('api-action')
                    },
                    success: function success(response) {
                        if (!('success' in response)) {
                            alert(gform_affirm_entry_strings.payment_details_action_error);
                            return;
                        }
                        if (response.success) {
                            window.location.reload();
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function error() {
                        alert(gform_affirm_entry_strings.payment_details_action_error);
                    },
                    complete: function complete() {
                        $spinner.hide();
                        $button.prop('disabled', false);
                    }
                });
            });
        };

        self.init();
    };

    $(document).ready(window.GFAFFIRMEntryActions);
})(jQuery);