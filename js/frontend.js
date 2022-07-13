window.GFAFFIRM = null;
(function($) {
    window.GFAFFIRM = function(args) {
        for (var prop in args) {
            if (args.hasOwnProperty(prop)) {
                this[prop] = args[prop];
            }
        }
        this.form = null;
        this.countryCode = '';
        this.paymentMethod = '';
        this.activeFeed = null;
        this.feedActivated = false;
        this.strings = gforms_affirm_frontend_strings;
        this.hasAppleCoupon = false;
        this.total = 0;

        var self = this;

        self.init = function() {
            self.form = '#gform_' + self.formId;
            self.GFCCField = '#field_' + self.formId + '_' + self.ccFieldId;
            self.affirmButtons = $('#gform_affirm_smart_payment_buttons');
            self.orderIdField = $('#gf_affirm_order_id');
            self.checkoutTokenField = $('#gf_affirm_checkout_token');
            self.totalName = 'gform_affirm_amount_' + self.formId;
            self.btnArgs = null;
            self.bindFrontendFeedsEvaluated();
            self.bindFormTotalUpdate();

            $(self.form).on('submit', function() {
                if (self.isGoPrevPage()) {
                    self.removeIdFields();
                }
            });

            gformCalculateTotalPrice(self.formId);
            self.bindAffirmRemove();
            self.checkout();
        };

        self.countObj = function(obj) {
            if (typeof Object.keys === 'function') {
                return Object.keys(obj).length;
            } else {
                var count = 0;
                var i;
                for (i in obj) {
                    if (obj.hasOwnProperty(i)) {
                        count++;
                    }
                }
                return count;
            }
        }

        self.bindFormTotalUpdate = function() {

            // Set priority to 51 so it will be triggered after the coupons add-on
            gform.addFilter('gform_product_total', function(total, formId) {
                // When the page is just initiated, the total and formId are both '0'.
                if (formId === '0') {
                    return total;
                }

                if (window[self.totalName] !== undefined && window[self.totalName] !== total) {
                    self.removeIdFields();
                }

                self.total = window[self.totalName] = total;

                // Checkout buttons need to be hidden if the total is 0.
                self.renderAffirmField();
                return total;
            }, 51);
        };

        self.canShowSmartButtons = function() {
            var canShowSubmitButton = gf_check_field_rule(self.formId, 0) === 'show';

            var canShowAffirmButtons = canShowSubmitButton && gf_check_field_rule(self.formId, self.ccFieldId) === 'show';

            return canShowAffirmButtons && self.feedActivated && window[self.totalName] && self.paymentMethod === 'Affirm Checkout' && self.isLastPage();
        };

        self.isLastPage = function() {
            var targetPageInput = $('#gform_target_page_number_' + self.formId);
            if (targetPageInput.length > 0) {
                return targetPageInput.val() === '0';
            }

            return true;
        };
        self.isGoPrevPage = function() {
            // don't create card token if clicking on the Previous button.
            var sourcePage = parseInt($('#gform_source_page_number_' + self.formId).val(), 10);
            var targetPage = parseInt($('#gform_target_page_number_' + self.formId).val(), 10);

            return sourcePage > targetPage && targetPage !== 0;
        };
        self.bindSpinnerTargetElem = function() {
            gform.addFilter('gform_spinner_target_elem', function(elem) {
                return $('#gform_wrapper_' + self.formId + ' .gform_next_button, #gform_send_resume_link_button_' + self.formId + ', #gform_affirm_smart_payment_buttons');
            });
        };

        self.renderAffirmField = function() {
            if (self.paymentMethod !== 'Credit Card') {
                self.renderAffirmButtons();
            }
        };
        self.renderAffirmButtons = function() {

            if (!self.canShowSmartButtons()) {
                self.toggleAffirmButtons('none');
                self.toggleSubmitButton('visible');
                return;
            }

            // Hide the submit button.
            self.toggleSubmitButton('hidden');
            // Show the Smart Buttons.
            self.toggleAffirmButtons('block');

            if (self.affirmButtons.is(":visible")) {
                self.bindSpinnerTargetElem();
            }
        };

        self.checkout = function() {
            self.affirmButtons.on("click", function(event) {
                if (!self.feedActivated || $('#gform_save_' + self.formId).val() === '1' || $(self.form).data('gfaffirmsubmitting') || window[self.totalName] <= 0 || self.isGoPrevPage() || 'undefined' !== typeof gformIsRecaptchaPending && gformIsRecaptchaPending($(self.form))) {
                    return true;
                }
                gformAddSpinner(self.formId);
                event.preventDefault();
                if (!$(self.form).data('gfaffirmsubmitting')) {
                    $(self.form).data('gfaffirmsubmitting', true);
                }
                self.getOrderData();
            })
        };

        self.process_checkout = function() {
            var data = {};
            for (var prop in self.activeFeed) {
                if (self.activeFeed.hasOwnProperty(prop)) {
                    if (prop == "paymentAmount")
                        continue;
                    if (prop == "feedId" || prop == "feedName" || prop == "no_shipping" || prop == "intent") {
                        data[prop] = self.activeFeed[prop];
                        continue;
                    }

                    data[prop] = $('#input_' + self.formId + '_' + self.activeFeed[prop]).val();
                    if (data[prop] === undefined)
                        data[prop] = $('input[name="input_' + self.activeFeed[prop] + '"]').val();
                    if (data[prop] === undefined)
                        data[prop] = $('#input_' + self.formId + '_' + String(self.activeFeed[prop]).replace('.', '_')).val();
                    if (data[prop] === undefined)
                        data[prop] = self.activeFeed[prop];
                }
            }

            window['affirm_object'] = self.affirm_object = {
                "merchant": {
                    "user_confirmation_url": window.location.href,
                    "user_cancel_url": window.location.href,
                    "user_confirmation_url_action": "POST",
                    "name": data.feedName
                },
                "shipping": {
                    "name": {
                        "first": data.first_name,
                        "last": data.last_name
                    },
                    "address": {
                        "line1": data.address_line1,
                        "line2": data.address_line2,
                        "city": data.address_city,
                        "state": data.address_state,
                        "zipcode": data.address_zip,
                        "country": data.address_country
                    },
                    "phone_number": data.phone,
                    "email": data.email
                },
                "billing": {
                    "name": {
                        "first": data.first_name,
                        "last": data.last_name
                    },
                    "address": {
                        "line1": data.address_line1,
                        "line2": data.address_line2,
                        "city": data.address_city,
                        "state": data.address_state,
                        "zipcode": data.address_zip,
                        "country": data.address_country
                    },
                    "phone_number": data.phone,
                    "email": data.email
                },
                "items": self.items,
                "discounts": self.discounts,
                "metadata": {
                    "mode": "modal"
                },
                "order_id": self.create_order_nonce,
                "currency": self.currency,
                // "financing_program": "flyus_3z6r12r",
                "shipping_amount": self.shipping,
                // "tax_amount": 0,
                "total": self.total * 100
            };
            affirm.checkout(self.affirm_object);
            affirm.checkout.open({
                onFail: function() {
                    console.log("User cancelled the Affirm checkout flow.");
                },
                onSuccess: function(a) {
                    console.log("Affirm checkout successful.");
                    $(self.form).append($('<input type="hidden" id="gf_affirm_order_id" name="affirm_order_id" />').val(self.create_order_nonce));
                    $(self.form).append($('<input type="hidden" id="gf_affirm_checkout_token" name="affirm_checkout_token" />').val(a.checkout_token));
                    self.affirmButtons.bind("click", function() {
                        return false;
                    });
                    $(self.form).submit();
                },
                onOpen: function(token) {
                    console.log("Affirm modal was opened successfully.")
                }
            });

        };

        self.getOrderData = function() {
            self.items = [];
            self.itemTotal = self.shipping = 0;
            $.ajax({
                async: false,
                url: self.strings.ajaxurl,
                dataType: 'json',
                method: 'POST',
                data: {
                    action: "gfaffirm_get_order_data",
                    nonce: self.get_order_nonce,
                    form_id: self.formId,
                    feed_id: self.activeFeed.feedId,
                    data: $(self.form).serialize()
                },
                success: function success(response) {
                    if (response.success) {
                        self.items = response.data.items;
                        self.discounts = response.data.discounts;
                        self.itemTotal = response.data.itemTotal;
                        // Set the field total as the submission total.
                        if (self.activeFeed.paymentAmount !== 'form_total') {
                            window[self.totalName] = self.total = self.itemTotal;
                        }
                        self.shipping = response.data.shipping;
                        self.process_checkout();
                    }
                },
                error: function(jqXHR, exception) {
                    console.log(jqXHR + "\n" + exception);
                    if ($(self.form).data('gfaffirmsubmitting')) {
                        self.affirmInit();
                        $(self.form).data('gfaffirmsubmitting', false);
                        self.hideFormSpinner();
                    }
                }
            });
        };

        self.bindAffirmRemove = function() {
            $(document).bind('DOMNodeRemoved', '.affirm-sandbox-container', function(e) {
                if (!$(e.target).hasClass('affirm-sandbox-container'))
                    return;
                if ($(self.form).data('gfaffirmsubmitting')) {
                    self.affirmInit();
                    $(self.form).data('gfaffirmsubmitting', false);
                    self.hideFormSpinner();
                }
            });
        }

        self.affirmInit = function() {
            _affirm_config = {
                public_api_key: self.public_key,
                script: self.affirm_src
            };
            (function(m, g, n, d, a, e, h, c) {
                var b = m[n] || {},
                    k = document.createElement(e),
                    p = document.getElementsByTagName(e)[0],
                    l = function(a, b, c) { return function() { a[b]._.push([c, arguments]) } };
                b[d] = l(b, d, "set");
                var f = b[d];
                b[a] = {};
                b[a]._ = [];
                f._ = [];
                b._ = [];
                b[a][h] = l(b, a, h);
                b[c] = function() { b._.push([h, arguments]) };
                a = 0;
                for (c = "set add save post open empty reset on off trigger ready setProduct".split(" "); a < c.length; a++) f[c[a]] = l(b, d, c[a]);
                a = 0;
                for (c = ["get", "token", "url", "items"]; a < c.length; a++) f[c[a]] = function() {};
                k.async = !0;
                k.src = g[e];
                p.parentNode.insertBefore(k, p);
                delete g[e];
                f(g);
                m[n] = b
            })(window, _affirm_config, "affirm", "checkout", "ui", "script", "ready", "jsReady");
        }

        self.hideFormSpinner = function() {
            if ($('#gform_ajax_spinner_' + self.formId).length > 0) {
                $('.gform_ajax_spinner').remove();
            }
        };

        self.toggleSubmitButton = function(status) {
            var submitButton = $('#gform_submit_button_' + self.formId);
            submitButton.css('visibility', status);
            if (status === 'hidden') {
                submitButton.addClass("canshowbuttonstatus-" + status);
            } else {
                submitButton.removeClass("canshowbuttonstatus-hidden");
            }
        };

        self.toggleAffirmButtons = function(status) {
            self.affirmButtons.css('display', status);
        };

        self.bindFrontendFeedsEvaluated = function() {
            gform.addAction('gform_frontend_feeds_evaluated', function(feeds, formId) {
                if (formId !== self.formId) {
                    return;
                }
                self.feedActivated = false;

                var firstActiveAffirmFeed = self.getFirstActiveAffirmFeed(feeds);

                if (false === firstActiveAffirmFeed) {
                    return;
                }

                self.feedActivated = true;
                self.loadActiveFeedSettings(firstActiveAffirmFeed);
                self.renderPaymentMethod();
            });
        };

        self.loadActiveFeedSettings = function(FirstActiveAffirmFeed) {
            // Reset the active feed in case it was set.
            self.activeFeed = null;

            for (var i = 0; i < self.countObj(self.feeds); i++) {
                if (self.feeds[i].feedId === FirstActiveAffirmFeed.feedId) {
                    self.activeFeed = self.feeds[i];
                    return self.activeFeed;
                }
            }
            return false;
        };

        self.renderPaymentMethod = function() {
            var paymentMethodField = $('.gform_affirm_payment_method select');
            var paymentMethod = self.countObj(self.paymentMethods) > 1 && paymentMethodField.size() ? paymentMethodField.val() : self.paymentMethods[0];
            self.displayPaymentMethod(paymentMethod);
        };

        self.displayPaymentMethod = function(paymentMethod) {
            if (!self.feedActivated) {
                self.paymentMethod = '';
            } else {

                if (typeof paymentMethod === 'string') {
                    self.paymentMethod = paymentMethod;
                } else {
                    // This is triggered by payment selection change.
                    if (paymentMethod.hasOwnProperty('target')) {
                        self.removeIdFields();
                        paymentMethod = $(paymentMethod.target);
                    }
                    self.paymentMethod = paymentMethod.val();
                }
            }

            self.renderAffirmField();
        };

        self.getFirstActiveAffirmFeed = function(EvaluatedFormFeeds) {

            var activeAffirmFeeds = EvaluatedFormFeeds.filter(function(feed) {
                return feed.addonSlug === 'gfaffirm' && feed.isActivated;
            });
            if (activeAffirmFeeds.length) {
                return activeAffirmFeeds.shift();
            }
            return false;
        };

        self.removeIdFields = function() {
            self.orderIdField.remove();
            self.checkoutTokenField.remove();
        };

        self.init();
    };
})(jQuery);