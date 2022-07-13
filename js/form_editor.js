window.GFAFFIRMFormEditor = null;

(function($, strings) {
    window.GFAFFIRMFormEditor = function() {
        var self = this;

        self.isLegacy = strings.is_legacy === 'true';

        self.init = function() {
            self.hooks();
        };

        self.hooks = function() {

            gform.addFilter('gform_form_editor_can_field_be_added', function(result, type) {
                if (type !== 'affirm' || GetFieldsByType(['affirm']).length < 1) {
                    return result;
                }

                alert(strings.only_one_affirm_field);

                return false;
            });
        };
        self.init();
    };

    $(document).ready(GFAFFIRMFormEditor);
})(jQuery, gform_affirm_form_editor_strings);