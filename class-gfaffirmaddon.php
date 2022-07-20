<?php 
GFForms::include_payment_addon_framework();
class GFAffirmAddOn extends GFPaymentAddOn {


	protected $_version = GFAFFIRM_ADDON_VERSION;
	protected $_min_gravityforms_version = '1.9.16';
	protected $_slug = 'gfaffirm';
	protected $_path = 'gfaffirm/gfaffirmaddon.php';
	protected $_full_path = __FILE__;
	protected $_title = 'GravityForms Affirm Add-on';
	protected $_short_title = 'Affirm';
	protected $_supports_callbacks = true;
	protected $_supports_frontend_feeds = true;
	private $sandbox_js = "https://cdn1-sandbox.affirm.com/js/v2/affirm.js";
	private $live_js = "https://cdn1.affirm.com/js/v2/affirm.js";
	private $environment;
	protected $api;
	public $verified = null;
	private $order_data;

	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return GFAffirmAddOn
	 */
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFAffirmAddOn();
		}

		return self::$_instance;
	}

	/**
	 * Plugin starting point. Handles hooks, loading of language files and Affirm delayed payment support.
	 */

	public function pre_init() {
		parent::pre_init();
		require_once 'includes/class-gf-affirm-api.php';
		require_once 'includes/class-gf-field-affirm.php';
	}

	public function init() {
		parent::init();
		// $this->add_delayed_payment_support(
		// 	array(
		// 		'option_label' => esc_html__( 'Subscribe contact to service x only when payment is received.', 'gfaffirm' )
		// 	)
		// );
		add_filter( 'gform_register_init_scripts', array( $this, 'register_init_scripts' ), 10, 3 );
		add_filter( 'gform_submit_button', array( $this, 'add_smart_payment_buttons' ), 10, 2 );

	}

	public function init_ajax() {

		parent::init_ajax();
		add_action( 'wp_ajax_gfaffirm_payment_details_action', array( $this, 'payment_details_action_handler' ) );
		add_action( 'wp_ajax_nopriv_gfaffirm_get_order_data', array( $this, 'ajax_get_order_data' ) );
		add_action( 'wp_ajax_gfaffirm_get_order_data', array( $this, 'ajax_get_order_data' ) );

	}

	public function init_admin() {

		parent::init_admin();
		add_filter( 'gform_after_save_form', array( $this, 'maybe_add_feed' ), 10, 2 );
		add_filter( 'gform_payment_statuses', array( $this, 'add_custom_payment_status' ), 10, 1 );
		add_action( 'gform_pre_entry_detail', array( $this, 'update_charge_status' ) , 10, 2 );
		add_action( 'gform_payment_details', array( $this, 'maybe_add_payment_details_button' ), 10, 2 );
	}
	
	public function get_menu_icon() {
		return $this->is_gravityforms_supported( '2.5-beta-3.1' ) ? 'gform-icon--dollar' : 'dashicons-admin-generic';
	}


	// # FEED PROCESSING -----------------------------------------------------------------------------------------------

	/**
	 * Process the feed e.g. subscribe the user to a list.
	 *
	 * @param array $feed The feed object to be processed.
	 * @param array $entry The entry object currently being processed.
	 * @param array $form The form object currently being processed.
	 *
	 * @return bool|void
	 */

	private function add_feed_settings_field_billing_name_map( array $feed_settings_fields ) {
		$billing_info = $this->get_field( 'billingInformation', $feed_settings_fields );

		array_unshift(
			$billing_info['field_map'],
			array(
				'name'     => 'first_name',
				'label'    => esc_html__( 'First Name', 'gfaffirm' ),
				'required' => false,

			),
			array(
				'name'     => 'last_name',
				'label'    => esc_html__( 'Last Name', 'gfaffirm' ),
				'required' => false,

			)
		);

		return $this->replace_field( 'billingInformation', $billing_info, $feed_settings_fields );
	}

	public function register_init_scripts( $form, $field_values, $is_ajax ) {

		if ( ! $this->frontend_script_callback( $form ) ) {
			return;
		}

		// Initialize Affirm Checkout script.
		$args = array(
			'formId'              => $form['id'],
			'isAjax'              => $is_ajax,
			'currency'            => GFCommon::get_currency(),
			'feeds'               => array(),
			'public_key'          => $this->get_public_key(),
			'affirm_src'          => $this->get_affirm_src(),
			'create_order_nonce'        => wp_create_nonce( 'gf_affirm_create_order_nonce' ),
			'get_order_nonce'        => wp_create_nonce( 'gf_affirm_get_order_nonce' )
		);

		if ( $this->has_affirm_field( $form ) ) {
			$cc_field = $this->get_affirm_field( $form );
			$args['ccFieldId']      = $cc_field->id;
			$args['ccPage']         = $cc_field->pageNumber;
			$args['paymentMethods'] =  array( 'Affirm Checkout' );

		}

		// Get feed data.
		$feeds = $this->get_active_feeds( $form['id'] );

		foreach ( $feeds as $feed ) {
			$feed_settings = array(
				'feedId'          => $feed['id'],
				'feedName'        => rgars( $feed, 'meta/feedName' ),
				'first_name'      => rgars( $feed, 'meta/billingInformation_first_name' ),
				'last_name'       => rgars( $feed, 'meta/billingInformation_last_name' ),
				'email'           => rgars( $feed, 'meta/billingInformation_email' ),
				'phone'           => rgars( $feed, 'meta/billingInformation_phone' ),
				'address_line1'   => rgars( $feed, 'meta/billingInformation_address' ),
				'address_line2'   => rgars( $feed, 'meta/billingInformation_address2' ),
				'address_city'    => rgars( $feed, 'meta/billingInformation_city' ),
				'address_state'   => rgars( $feed, 'meta/billingInformation_state' ),
				'address_zip'     => rgars( $feed, 'meta/billingInformation_zip' ),
				'address_country' => rgars( $feed, 'meta/billingInformation_country' ),
				'no_shipping'     => rgars( $feed, 'meta/no_shipping' ),
				// 'product_name' => rgars( $feed, 'meta/product_map_product_name' ),
				// 'product_price' => rgars( $feed, 'meta/product_map_product_price' ),
				// 'product_quantity' => rgars( $feed, 'meta/product_map_product_quantity' ),
				// 'product_sku' => rgars( $feed, 'meta/product_map_product_sku' ),
				// 'coupon' => rgars( $feed, 'meta/product_map_coupon' ),
			);
			if ( rgars( $feed, 'meta/transactionType' ) === 'product' ) {
				$feed_settings['paymentAmount'] = rgars( $feed, 'meta/paymentAmount' );
				$feed_settings['intent']        = $this->get_intent( $form['id'], $feed['id'] );
			}

			$args['feeds'][] = $feed_settings;
		}

		$args   = apply_filters( 'gform_affirm_object', $args, $form['id'] );
		$script = 'new GFAFFIRM( ' . json_encode( $args, JSON_FORCE_OBJECT ) . ' );';

		// Add Affirm Checkout script to form scripts.
		GFFormDisplay::add_init_script( $form['id'], 'affirm', GFFormDisplay::ON_PAGE_RENDER, $script );
	}
	
	public function is_authorize_only_feed( $feed_id ) {
		$feed = is_null( $feed_id ) ? array() : $this->get_feed( $feed_id );
		return rgars( $feed, 'meta/authorizeOnly' ) === '1';
	}

	public function capture( $auth, $feed, $submission_data, $form, $entry ) {
		$charge_id = rgars( $this->order_data, 'id' );
		$amount = rgars( $this->order_data, 'amount' );
		gform_update_meta( $entry['id'], 'order_data', $this->order_data );
		// GFAPI::update_entry_property( $entry['id'], 'payment_method', 'Affirm' );
		GFAPI::update_entry_property( $entry['id'], 'payment_method', "Afirm23123" );
		// Do not capture if the payment intent is AUTHORIZE.
		if ( $this->get_intent( $form['id'], $feed['id'] ) === 'authorize' ) {
			return [];
		}

		// Capture order
		$order = $this->api->capture_charge( $charge_id );
		if ( is_wp_error( $order ) ) {
			$this->log_error( __METHOD__ . '(): ' . $order->get_error_message() );

			$error =  esc_html__( 'Cannot capture the payment. ' .$order->get_error_message() , 'gfaffirm'  );
			return array(
				'is_success'    => false,
				'error_message' => $error,
			);
		}

		if ( rgar( $order, 'amount' ) !== $amount  ) {
			$this->log_debug( __METHOD__ . '(): Cannot capture the payment; order details => ' . print_r( $order, true ) );

			$error = sprintf(
				// translators: Placeholder represents order status.
				esc_html__( 'Cannot capture the payment. The order status: %s.', 'gfaffirm' ),
				rgar( $order, 'code' )
			);

			if ( rgar( $order, 'status' ) === 'PENDING' ) {
				$this->log_debug( __METHOD__ . '(): Pending'  );

				// Mark the payment status as Pending.
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Pending' );

				return array();
			} else {
				return array(
					'is_success'    => false,
					'error_message' => $error,
				);
			}
		}
		
		return array(
			'is_success'     => true,
			'transaction_id' => rgars( $order, 'transaction_id' ),
			'amount'         => rgars( $order, 'amount' ) / 100,
			'payment_method' => 'Affirm',
		);

	}

	public function get_intent( $form_id, $feed_id, $context = null ) {
		// Default intent is capture.
		$intent = 'capture';

		// Allow feed settings and filters to change intent.
		if ( $this->is_authorize_only_feed( $feed_id ) ) {
			$intent = 'authorize';
		} 
		return apply_filters( 'gform_affirm_intent', $intent, intval( $form_id ), intval( $feed_id ) );
	}

	public function add_affirm_inputs( $content, $form ) {

		if ( ! $this->has_feed( $form['id'] ) ) {
			return $content;
		}

		if ( rgpost( 'affirm_order_id' ) ) {
			$content .= '<input type="hidden" name="affirm_order_id" id="gf_affirm_order_id" value="' . esc_attr( rgpost( 'affirm_order_id' ) ) . '" />';
		}

		return $content;

	}

	public function get_validation_result( $validation_result, $authorization_result ) {
		if ( empty( $authorization_result['error_message'] ) ) {
			return $validation_result;
		}

		$credit_card_page   = 0;
		$credit_card_page = GFFormDisplay::get_max_page_number( $validation_result['form'] );
		add_filter( 'gform_validation_message', array( $this, 'affirm_checkout_error_message' ) );
		$validation_result['credit_card_page'] = $credit_card_page;
		$validation_result['is_valid']         = false;

		return $validation_result;
	}

	public function affirm_checkout_error_message() {
		$authorization_result = $this->authorization;

		$message = "<div class='validation_error'>" . esc_html__( 'There was a problem with your submission.', 'gfaffirm' ) . ' ' . $authorization_result['error_message'] . '</div>';

		return $message;
	}

	public function authorize( $feed, $submission_data, $form, $entry ) {
		// Authorize product.
		return $this->authorize_product( $feed, $submission_data, $form, $entry );

	}

	public function authorize_product( $feed, $submission_data, $form, $entry ) {

		if ( ! $this->initialize_api()  ) {
			return $this->authorization_error( esc_html__( 'Failed to initialize the API. Cannot authorize the payment.', 'gfaffirm' ) );
		}

		$order_id = sanitize_text_field( rgpost( 'affirm_order_id' ) );
		$checkout_token = sanitize_text_field( rgpost( 'affirm_checkout_token' ) );
		
		// Throw an error if no order id available.
		if ( empty( $order_id ) && $submission_data['payment_amount'] > 0 ) {
			$this->log_error( __METHOD__ . '(): No order ID available, cannot create a new payment.' );

			return $this->authorization_error( esc_html__( 'No order ID available, cannot create a new payment.', 'gfaffirm' ) );
		}
		if ( ! wp_verify_nonce( $order_id, 'gf_affirm_create_order_nonce' ) ) {
			$this->log_error( __METHOD__ . '(): This order ID has expired, please create a new payment.' );

			return $this->authorization_error( esc_html__( 'This order ID has expired, please create a new payment.', 'gfaffirm' ) );
		}

		// Authorize payment for order.
		$authorize = $this->api->charges_order( $order_id, $checkout_token );
		if ( is_wp_error( $authorize ) ) {
			$this->log_error( __METHOD__ . '(): ' . $authorize->get_error_message() );

			return $this->authorization_error( $authorize->get_error_message() );
		}

		// Return error if the order status is not completed.
		if ( rgar( $authorize, 'status' ) !== 'authorized' ) {
			$this->log_error( __METHOD__ . '(): Cannot authorize the payment; order details => ' . print_r( $authorize, true ) );

			$error = sprintf(
				// translators: %s represents the order status.
				esc_html__( 'Cannot authorize the payment. %s', 'gfaffirm' ),
				rgar( $authorize, 'status' )
			);

			return $this->authorization_error( $error );
		}

		$payment_amount = GFCommon::to_number( rgar( $submission_data, 'payment_amount' ), $entry['currency'] );
		
		$order_total = (GFCommon::to_number( rgars( $authorize, 'amount' ), $entry['currency'] ) ) / 100;

		if ( $order_total !== $payment_amount ) {
			$error = esc_html__( 'The order total from Affirm does not match the payment amount of the submission.', 'gfaffirm' );

			$this->log_error( __METHOD__ . '(): ' . $error . ' Payment Amount is: ' . $payment_amount . ' Order Total: '. $order_total.' Order details => ' . print_r( $authorize, true ) );

			return $this->authorization_error( $error );
		}

		$this->order_data = $authorize;
			
		return array(
			'is_authorized'  => true,
			'transaction_id' => rgars( $authorize, 'events/0/transaction_id' ),
			'charges_id'	 => rgars( $authorize, 'id' ),
			'event_id'	 	 => rgars( $authorize, 'events/0/id' ),
			'expires'		 => rgars( $authorize, 'expires' ),
			'amount'		 => rgars( $authorize, 'amount' ),
			'payment_method' => 'Affirm'
		);

	}

	// private function payment_method_is_overridden( $method_name, $base_class = 'GFPaymentAddOn' ) {
	// 	return true;
	// }

	public function payment_details_action_handler() {

		$api_action = sanitize_text_field( $_POST['api_action'] );
		$entry      = GFAPI::get_entry( sanitize_text_field( $_POST['entry_id'] ) );

		if ( ! $this->initialize_api() || empty( $api_action ) || is_wp_error( $entry ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Cannot complete request, please contact us for further assistance.', 'gfaffirm' ) ) );
		}

		if ( ! wp_verify_nonce( rgpost( 'nonce' ), 'payment_details_action_nonce' ) ) {
			wp_send_json_error();
		}

		switch ( $api_action ) {
			case 'capture':
				$result = $this->handle_entry_details_capture( $entry );
				break;
			case 'refund':
				$result = $this->handle_entry_details_refund( $entry );
				break;
			case 'void':
				$result = $this->handle_entry_details_void( $entry );
				break;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success();
	}

	public function handle_entry_details_capture( $entry ) {
		
		$order_data = gform_get_meta( $entry['id'], 'order_data' );

		$capture = $this->api->capture_charge( rgars($order_data, 'id' ) );

		if ( is_wp_error( $capture ) ) {
			$this->log_error( __METHOD__ . '(): ' . $capture->get_error_message() . '; error details => ' . print_r( $capture->get_error_data(), 1 ) );
			return new WP_Error( 'capture-failed', esc_html__( 'Cannot capture payment. If the error persists, please contact us for further assistance.', 'gfaffirm' ) );
		}

		$action['payment_status'] = "Captured";
		$action['amount']         = $entry['payment_amount'];
		$action['transaction_id'] = rgars($capture, 'transaction_id' );

		switch ( rgar( $capture, 'type' ) ) {
			case 'capture':
				return $this->complete_payment( $entry, $action );
			case 'PENDING':
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Pending' );
				GFAPI::update_entry_property( $entry['id'], 'payment_method', 'Affirm' );
				return $this->add_pending_payment( $entry, $action );
			default:
				return $this->fail_payment( $entry, $action );
		}

	}

	public function handle_entry_details_refund( $entry ) {

		$order_data = gform_get_meta( $entry['id'], 'order_data' );

		$refund = $this->api->refund_charge( rgars($order_data, 'id' ) );

		if ( is_wp_error( $refund ) ) {
			$this->log_error( __METHOD__ . '(): ' . $refund->get_error_message() . '; error details => ' . print_r( $refund->get_error_data(), 1 ) );
			return new WP_Error( 'refund-failed', esc_html__( 'Cannot refund payment. If the error persists, please contact us for further assistance.', 'gfaffirm' ) );
		}
		$action['amount']         = $entry['payment_amount'];
		$action['transaction_id'] = rgars($refund, 'transaction_id' );

		switch ( rgar( $refund, 'type' ) ) {
			case 'refund':
				return $this->refund_payment( $entry, $action );
			case 'PENDING':
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Pending' );
				GFAPI::update_entry_property( $entry['id'], 'payment_method', 'Affirm' );
				return $this->add_pending_payment( $entry, $action );
			default:
				return $this->fail_payment( $entry, $action );
		}

	}

	public function handle_entry_details_void( $entry ) {

		$order_data = gform_get_meta( $entry['id'], 'order_data' );

		$void = $this->api->void_charge( rgars($order_data, 'id' ) );

		if ( is_wp_error( $void ) ) {
			$this->log_error( __METHOD__ . '(): ' . $void->get_error_message() . '; error details => ' . print_r( $void->get_error_data(), 1 ) );
			return new WP_Error( 'void-failed', esc_html__( 'Cannot void charge. If the error persists, please contact us for further assistance.', 'gfaffirm' ) );
		}
		$action['amount']         = $entry['payment_amount'];
		$action['transaction_id'] = rgars($void, 'transaction_id' );

		switch ( rgar( $void, 'type' ) ) {
			case 'void':
				return $this->void_authorization( $entry, $action );
			case 'PENDING':
				GFAPI::update_entry_property( $entry['id'], 'payment_status', 'Pending' );
				GFAPI::update_entry_property( $entry['id'], 'payment_method', 'Affirm' );
				return $this->add_pending_payment( $entry, $action );
			default:
				return $this->fail_payment( $entry, $action );
		}

	}

	public function supported_notification_events( $form ) {

		// If this form does not have a Affirm feed, return false.
		if ( ! $this->has_feed( $form['id'] ) ) {
			return false;
		}

		// Return Affirm notification events.
		return array(
			'complete_payment'          => esc_html__( 'Payment Completed', 'gfaffirm' ),
			'refund_payment'            => esc_html__( 'Payment Refunded', 'gfaffirm' ),
			'fail_payment'              => esc_html__( 'Payment Failed', 'gfaffirm' ),
			'add_pending_payment'       => esc_html__( 'Payment Pending', 'gfaffirm' ),
			'void_authorization'        => esc_html__( 'Authorization Voided', 'gfaffirm' ),
			// 'add_subscription_payment'  => esc_html__( 'Subscription Payment Completed', 'gfaffirm' ),
			// 'fail_subscription_payment' => esc_html__( 'Subscription Payment Failed', 'gfaffirm' ),
			// 'cancel_subscription'       => esc_html__( 'Subscription Canceled', 'gfaffirm' ),
			// 'expire_subscription'       => esc_html__( 'Subscription Expired', 'gfaffirm' ),
		);

	}
	
	public function maybe_add_payment_details_button( $form_id, $entry ) {

		if ( ! $this->can_display_payment_details_button( $entry, array( 'Captured', 'Authorized', 'Part Refunded' ) ) ) {
			return;
		}

		switch ( $entry['payment_status'] ) {
			case 'Authorized':
				$buttons[0]['label']      = __( 'Capture Charge', 'gfaffirm' );
				$buttons[0]['api_action'] = 'capture';
				$buttons[1]['label']      = __( 'Void Charge', 'gfaffirm' );
				$buttons[1]['api_action'] = 'void';
				break;
			case 'Captured':
				$buttons[0]['label']      = __( 'Refund Charge', 'gfaffirm' );
				$buttons[0]['api_action'] = 'refund';
				break;
			case 'Part Refunded':
				$buttons[0]['label']      = __( 'Refund Charge', 'gfaffirm' );
				$buttons[0]['api_action'] = 'refund';
				break;
		}
		$style ='<style>
					input#affirm_capture, input#affirm_void, input#affirm_refund {
						font-size: 17px;
						height: 56px;
						line-height: 30px;
						font-weight: 600;
						margin-top: 10px;
						white-space: nowrap;
						flex-wrap: nowrap;
						justify-content: center;
						max-width: 200px;
						min-width: 160px;
						position: relative;
						border-radius: 6px;
						text-align: center;
						text-decoration: none;
						text-overflow: ellipsis;
						transition: background-color 0.1s cubic-bezier(0.25, 0.1, 0.25, 1), color 0.1s cubic-bezier(0.25, 0.1, 0.25, 1);
					}
					input#affirm_capture, input#affirm_refund {
						background-color: #2f2fc1;
						color: #fff;
						border: 0;
					}
					input#affirm_void {
						color: #4a4af4;
						border-color: #4a4af4;
						border-width: 2px
					}
					input#affirm_void:hover {
						background-color: #a8a9fc;
						color: #fff;
					}
				</style>';
		$spinner_url = GFCommon::get_base_url() . '/images/spinner.' . ( $this->is_gravityforms_supported( '2.5-beta' ) ? 'svg' : 'gif' );
		foreach ($buttons as $key => $button) {
		?>
		<div id="affirm_payment_details_button_container">
			<input id="affirm_<?php echo esc_attr( $button['api_action'] ); ?>" type="button" name="<?php echo esc_attr( $button['api_action'] ); ?>"
				value="<?php echo esc_attr( $button['label'] ); ?>" class="button affirm-payment-action"
				data-entry-id="<?php echo esc_attr( absint( $entry['id'] ) ); ?>"
				data-api-action="<?php echo esc_attr( $button['api_action'] ); ?>"
			/>
			<img src="<?php echo esc_url( $spinner_url ); ?>" id="affirm_ajax_spinner" style="display: none;"/>
		</div>
		<?php
		}
		echo $style;
	}

	private function can_display_payment_details_button( $entry, $allowed_statuses ) {
		
		return rgget( 'page' ) === 'gf_entries'
				&& $entry['transaction_type'] === '1'
				&& $this->is_payment_gateway( $entry['id'] )
				&& in_array( $entry['payment_status'], $allowed_statuses );
	}

	public function complete_payment( &$entry, $action ) {
		$action['payment_status'] = 'Captured';
		return parent::complete_payment( $entry, $action);
	}
	
	public function update_charge_status( $form, $entry ) {

		if( in_array( $entry['payment_status'], ['Failed', 'Voided', 'Refunded' ] ) || $entry["payment_method"] != "Affirm" || ! $this->initialize_api() ) 
			return false;
		$action['amount'] = $entry['payment_amount'];
		$order_data = gform_get_meta( $entry['id'], 'order_data' );
		$amount_old = empty(rgar( $order_data, 'refunded_amount') )? 0 : rgar( $order_data, 'refunded_amount'); 
		$charge = $this->api->read_charge( rgar($order_data, 'id' ) );
		if ( is_wp_error( $charge ) ) {
			$this->log_error( __METHOD__ . '(): ' . $charge->get_error_message() . '; error details => ' . print_r( $charge, 1 ) );
			$this->fail_payment( $entry, $action );
			return false;
		}
		$entry["payment_method"] = $action["payment_method"] = "Affirm";
		$event = rgar( $charge, 'events' );
		$action['transaction_id'] = end( $event )["transaction_id"];
		$this->log_debug( "events".print_r(rgar( $charge, 'events' ), 1) );
		$action['payment_status'] = ucwords(rgar( $charge, 'status' )) == 'Partially Refunded' ? 'Part Refunded' : ucwords(rgar( $charge, 'status' ));
		if( $entry['payment_status'] !== $action['payment_status'] ){
			switch ( $action['payment_status'] ) {
				case 'Voided':
					$this->void_authorization( $entry, $action );
					break;
				case 'Captured':
					$this->complete_payment( $entry, $action );
					break;
				case 'Refunded':
					$action['amount'] = (rgar( $charge, 'refunded_amount') - $amount_old ) / 100;
					$this->refund_payment( $entry, $action );
					break;
				case 'Part Refunded':
					$action['amount'] = rgar( $charge, 'refunded_amount' ) / 100;
					$this->refund_payment( $entry, $action );
					break;
				default:
					$this->fail_payment( $entry, [] );
					break;
				}
		}elseif( rgar( $charge, 'refunded_amount') != $amount_old ){
			$action['amount'] = (rgar( $charge, 'refunded_amount') - $amount_old ) / 100;
			$this->refund_payment( $entry, $action );
		}
		$this->log_debug( __METHOD__ . '(): Processing request.'. $entry['payment_method']."\r\n".$entry['payment_status'] );
		gform_update_meta( $entry['id'], 'order_data', $charge );
	}

	function add_custom_payment_status( $payment_statuses ){
		$payment_statuses['Part Refunded'] = esc_html__( 'Partially Refunded', 'gfaffirm' );
		return $payment_statuses;
	}

	public function billing_info_fields() {
		$fields = array(
					array( 'name' => 'phone', 'label' => __( 'Phone', 'gfaffirm' ), 'required' => true ),
		);
		return array_merge( parent::billing_info_fields(), $fields );
	}

	private function add_authorize_setting_field( $default_settings ) {
		$authorize_field = array(
			'name'    => 'authorizeOnly',
			'label'   => esc_html__( 'Authorize only', 'gfaffirm' ),
			'type'    => 'checkbox',
			'tooltip' => '<h6>' . esc_html__( 'Authorize Only', 'gfaffirm' ) . '</h6>' . esc_html__( 'Enable this option if you would like to only authorize payments when the user submits the form, you will be able to capture the payment by clicking the capture button from the entry details page.', 'gfaffirm' ),
			'choices' => array(
				array(
					'label' => esc_html__( 'Only authorize payment and capture later from entry details page.' ),
					'name'  => 'authorizeOnly',
				),
			),
		);

		return parent::add_field_after( 'paymentAmount', $authorize_field, $default_settings );
	}

	// # SCRIPTS & STYLES -----------------------------------------------------------------------------------------------

	/**
	 * Return the scripts which should be enqueued.
	 *
	 * @return array
	 */
	public function scripts() {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';
		$scripts = array(
			array(
				'handle'    => 'gforms_affirm_frontend',
				'src'       => $this->get_base_url() . "/js/frontend{$min}.js",
				'version'   => $this->_version,
				'deps'      => array( 'jquery', 'gform_json', 'gform_gravityforms',  'wp-a11y' ),
				'in_footer' => false,
				'enqueue'   => array(
					array( $this, 'frontend_script_callback' ),
				),
				'strings'   => array(
					'catch_all_error'           => wp_strip_all_tags( __( 'An error occured, please try again.', 'gfaffirm' ) ),
					'ajaxurl'                   => admin_url( 'admin-ajax.php' ),
					'addon_slug'				=> $this->_slug,
				),
			),
			array(
				'handle'  => 'gform_affirm_entry',
				'deps'    => array( 'jquery' ),
				'src'     => $this->get_base_url() . "/js/entry_detail{$min}.js",
				'version' => $this->_version,
				'enqueue' => array(
					array(
						'admin_page' => array( 'entry_view' ),
						'tab'        => $this->_slug,
					),
				),
				'strings' => array(
					'payment_details_action_error' => wp_strip_all_tags( __( 'Cannot complete request, please contact us for further assistance.', 'gfaffirm' ) ),
					'payment_details_action_nonce' => wp_create_nonce( 'payment_details_action_nonce' ),
					'refund_confirmation'          => wp_strip_all_tags( __( 'You are about to refund. Note: Refunds are not reversible.', 'gfaffirm' ) ),
					'void_confirmation'            => wp_strip_all_tags( __( 'You are about to void charge!', 'gfaffirm' ) ),
					'ajaxurl'                      => admin_url( 'admin-ajax.php' ),
				),
			),
			array(
				'handle'  => 'gform_affirm_form_editor',
				'deps'    => array( 'jquery'),
				'src'     => $this->get_base_url() . "/js/form_editor{$min}.js",
				'version' => $this->_version,
				'enqueue' => array(
					array( 'admin_page' => array( 'form_editor' ) ),
				),
				'strings' => array(
					'is_legacy'                       => $this->is_gravityforms_supported( '2.5-beta' ) ? 'false' : 'true',
					'initialize_api'                  => $this->initialize_api(),
					'active'                          => wp_strip_all_tags( __( 'Active', 'gfaffirm' ) ),
					'inactive'                        => wp_strip_all_tags( __( 'Inactive', 'gfaffirm' ) ),
					'show'                            => wp_strip_all_tags( __( 'Show', 'gfaffirm' ) ),
					'imgurl'                          => GFCommon::get_base_url() . '/images/',
					'only_one_affirm_field'           => wp_strip_all_tags( __( 'Only one Affirm field can be added to the form', 'gfaffirm' ) ),
				),
			),
		);

		return array_merge( parent::scripts(), $scripts );
	}

	/**
	 * Return the stylesheets which should be enqueued.
	 *
	 * @return array
	 */
	public function styles() {

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';
		$styles = array(
			array(
				'handle'  => 'gfaffirm_form_settings_css',
				'src'     => $this->get_base_url() . '/css/gfaffirm_form_settings.css',
				'version' => $this->_version,
				'enqueue' => array(
					array( 'field_types' => array( 'poll' ) ),
				),
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => 'gfaffirm',
					),
					array(
						'admin_page' => array( 'plugin_settings' ),
						'tab'        => 'gfaffirm',
					),
				),
			),
			array(
				'handle'  => 'affirm_frontend_style',
				'src'     => $this->get_base_url() . "/css/affirm_frontend_style{$min}.css",
				'version' => $this->_version,
				'enqueue' => array(
					array( 'field_types' => array( $this->_slug ) ),
					array( $this, 'frontend_script_callback' ),
				)
			)
		);

		return array_merge( parent::styles(), $styles );
	}

	// # ADMIN FUNCTIONS -----------------------------------------------------------------------------------------------

	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'  => esc_html__( 'Affirm Add-On Settings', 'gfaffirm' ),
				'fields' => array(
					array(
						'label'   => esc_html__( 'PUBLIC API KEY', 'gfaffirm' ),
						'type'    => 'text',
						'name'    => 'public_key',
						'tooltip' => 'Please enter your Public API key here.',
						'class'   => 'client client_id',
					),
					array(
						'label'   => esc_html__( 'PRIVATE API KEY', 'gfaffirm' ),
						'type'    => 'text',
						'name'    => 'private_key',
						'class'   => 'client client_secret',
					),
					// array(
					// 	'label'   => esc_html__( 'Financial Product Key', 'gfaffirm' ),
					// 	'type'    => 'text',
					// 	'name'    => 'product_key',
					// 	'class'   => 'my-api',
					// ),
					array(
						'label'   => esc_html__( 'Environment', 'gfaffirm' ),
						'type'    => 'radio',
						'name'    => 'mode_option',
						'class'   => 'mode_option',
						'default_value'   => 'mode_option',
						'horizontal'   => true,
						'choices'	=> 	[
											[
												'name'    => 'live-mode',
												'label'    => 'Live mode',
												'value'    => 'live',
											],
											[
												'name'    => 'test-mode',
												'label'    => 'Test mode',
												'value'    => 'test',
											],
										],
						'tooltip' => esc_html__( '<div class="title"><h6>Environment</h6>Start with the Sandbox environment if you are still testing the integration. Use the Live environment on your production site.</div>', 'gfaffirm' ),
					),
					array(
						'label'   => esc_html__( '', 'gfaffirm' ),
						'type'    => 'add_endpoint',
						'name'    => 'add_endpoint',
						'class'   => 'add-endpoint',
						'tooltip' => esc_html__( '<div class="title">You must specify authorized redirect URIs </div>', 'gfaffirm' ),
					),
					
				),
			),
			
		);
	}

	public function settings_add_endpoint(){
		if( $this->initialize_api() ){
			echo "<div class='api-checked'>Your API is authenticated</div>
				<style>
					.api-checked:after{
						display:inline-block;
						width:30px;
						height:30px;
						left: 5px;
						top: 5px;
						position: relative;
						content: url(data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiA/PjxzdmcgZGF0YS1uYW1lPSJMYXllciAxIiBpZD0iTGF5ZXJfMSIgdmlld0JveD0iMCAwIDY0IDY0IiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxkZWZzPjxzdHlsZT4uY2xzLTF7ZmlsbDojZmZiMzAwO30uY2xzLTJ7ZmlsbDojMDA3NGZmO308L3N0eWxlPjwvZGVmcz48dGl0bGUvPjxwYXRoIGNsYXNzPSJjbHMtMSIgZD0iTTI4LjQ2LDQyLjI5QTIsMiwwLDAsMSwyNyw0MS43MWwtOS41LTkuNWEyLDIsMCwwLDEsMi44My0yLjgzbDguMDksOC4wOUw0My42MywyMi4yOWEyLDIsMCwxLDEsMi44MywyLjgzTDI5Ljg3LDQxLjcxQTIsMiwwLDAsMSwyOC40Niw0Mi4yOVoiLz48cGF0aCBjbGFzcz0iY2xzLTIiIGQ9Ik0zMiw2MEEyOCwyOCwwLDEsMSw2MCwzMC40N2EyLDIsMCwwLDEtMS44OCwyLjExQTIsMiwwLDAsMSw1NiwzMC42OSwyNCwyNCwwLDEsMCwzOS42NCw1NC43NSwyMy44NiwyMy44NiwwLDAsMCw1My41OCw0Mi41MWEyLDIsMCwxLDEsMy41OSwxLjc1QTI3Ljc4LDI3Ljc4LDAsMCwxLDQwLjkxLDU4LjU1LDI4LjE0LDI4LjE0LDAsMCwxLDMyLDYwWiIvPjwvc3ZnPg==);
					}
				</style>";
			return;
		}else
			echo "<div>Your API key is not authenticated</div>";
		?>
		<div>
			<?php if ( $this->get_environment() == 'live' )
				echo 'Click<a class ="live-link" href="https://www.affirm.com/dashboard/apikeys"> here</a> to go to your dashboard( live mode).';
			else
				echo 'Click <a class ="sandbox-link" href="https://sandbox.affirm.com/dashboard/apikeys"> here</a> to go to your dashboard( test mode).';
			?>
		</div>
		<?php
	}

	// # FEED SETTINGS -------------------------------------------------------------------------------------------------

	/**
	 * Remove the add new button from the title.
	 *
	 * @since 2.6
	 * @since 3.4 Allow add new feed only for: 1) has CC field + current feeds; 2) has Stripe Card; 3) use Stripe Checkout.
	 *
	 * @return string
	 */
	public function feed_list_title() {
		if ( ! $this->can_create_feed() ) {
			return $this->form_settings_title();
		}

		return GFFeedAddOn::feed_list_title();
	}

	/**
	 * Get the require credit card message.
	 *
	 * @since 2.6
	 * @since 3.4 Allow add new feed only for: 1) has CC field + current feeds; 2) has Stripe Card; 3) use Stripe Checkout.
	 *
	 * @return false|string
	 */
	public function feed_list_message() {

		if ( $this->initialize_api() && ! $this->has_affirm_field() ) {
			return $this->requires_affirm_field_message();
		}

		return GFFeedAddOn::feed_list_message();
	}

	/**
	 * Display the requiring Affirm checkout field message.
	 *
	 * @since 2.6
	 *
	 * @return string
	 */
	public function requires_affirm_field_message() {
		$url = add_query_arg( array( 'view' => null, 'subview' => null ) );

		return sprintf( esc_html__( "You must add a Affirm field to your form before creating a feed. Let's go %sadd one%s!", 'gfaffirm' ), "<a href='" . esc_url( $url ) . "'>", '</a>' );
	}

	/**
	 * Configures the settings which should be rendered on the feed edit page in the Form Settings > Simple Feed Add-On area.
	 *
	 * @return array
	 */

	public function feed_settings_fields() {

		$default_settings = parent::feed_settings_fields();
		$default_settings = $this->add_authorize_setting_field( $default_settings );
		$default_settings = $this->add_feed_settings_field_billing_name_map( $default_settings );
		$default_settings = $this->remove_field( 'options',$default_settings );

		$fields= [
			[
				'name'     => 'product_name',
				'label'    => __( 'Product Name', 'gfaffirm' ),
				'class'    => 'medium',
				'required' => false,
				'tooltip'  => '<h6>' . __( 'Product Name', 'gfaffirm' ) . '</h6>' . __( 'Name for product', 'gfaffirm' )
			],
			[
				'name'     => 'product_price',
				'label'    => __( 'Product Price', 'gfaffirm' ),
				'class'    => 'medium',
				'required' => false,
			],
			[
				'name'     => 'product_quantity',
				'label'    => __( 'Product Quantity', 'gfaffirm' ),
				'class'    => 'medium',
				'required' => false,
			],
			[
				'name'     => 'product_sku',
				'label'    => __( 'Product SKU', 'gfaffirm' ),
				'class'    => 'medium',
				'required' => false,
				'tooltip'  => '<h6>' . __( 'Product SKU', 'gfaffirm' ) . '</h6>' . __( 'SKU for product', 'gfaffirm' )
			]
			,
			[
				'name'     => 'coupon',
				'label'    => __( 'Coupon', 'gfaffirm' ),
				'class'    => 'medium',
				'required' => false,
			]
		];
		$product_map= [
			[
			'name'      => 'product_map',
			'label'     => esc_html__( 'Product Details', 'gfaffirm' ),
			'type'      => 'field_map',
			'tooltip'  => '<h6>' . __( 'Product Details', 'gfaffirm' ) . '</h6>' . __( 'Details of product', 'gfaffirm' ) ,
			'field_map' => $fields ,
			]
		];
		
		// return $this->add_field_after( 'billingInformation', $product_map, $default_settings );
		return $default_settings;
	}

	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'  => esc_html__( 'Name', 'gfaffirm' )
		);
	}

	/**
	 * Prevent feeds being listed or created if an api key isn't valid.
	 *
	 * @return bool
	 */
 
	public function can_create_feed() {
		return $this->initialize_api() && $this->has_affirm_field() ;
	}

	public function config_addon_message (){
		return parrent::config_addon_message();
	}

	// NEW FEA

	public function ajax_get_order_data() {
		check_ajax_referer( 'gf_affirm_get_order_nonce', 'nonce' );

		$feed    = $this->get_feed( rgpost( 'feed_id' ) );
		$form_id = absint( rgpost( 'form_id' ) );

		$data = array();
		parse_str( rgpost( 'data' ), $data );
		$_POST = $data;
		// Add this to make sure `get_input_value_submission()` in field classes would treat this as a real submission,
		// or fields hidden by conditional logic cannot be included in this temp lead.
		$_POST[ 'is_submit_' . $form_id ] = 1;

		$form                 = GFAPI::get_form( $form_id );
		$form_meta            = GFFormsModel::get_form_meta( $form_id );
		$temp_lead            = GFFormsModel::create_lead( $form_meta );
		$temp_submission_data = $this->get_order_data( $feed, $form, $temp_lead );
		$line_items           = array();

		$item_total = 0;
		$shipping   = 0;
		$discount = [];

		foreach ( $temp_submission_data['line_items'] as $item ) {
			if ( rgar( $item, 'is_shipping' ) && $item['is_shipping'] === 1 ) {
				$shipping = $item['unit_price'] * $item['quantity'];
			} else {
				$line_items[] = array(
					'display_name'        => $item['name'],
					'sku'	=> $item['id'],
					'description' => $item['description'],
					'unit_price' => strval( $item['unit_price'] )."00",
					'qty'    => $item['quantity'],
					'categories'	=> $item['options'],
				);

				$item_total += GFCommon::to_number( $item['unit_price'] * $item['quantity'] );
			}
		}

		if( count($temp_submission_data['discounts']) > 0 ){
			foreach ( $temp_submission_data['discounts'] as $discount ) {
				$id = rgar( $discount, "id");
				$code = end(explode( '|', $id  ));
				$discounts[$code]  = [
					"discount_amount" => $discount["unit_price"] > 0 ? $discount["unit_price"] *100 : $discount["unit_price"] *-100 ,
                	"discount_display_name" => $discount["name"]
				];

			}
		};
		wp_send_json_success(
			[
			'items'     => $line_items,
			'itemTotal' => $item_total,
			'shipping'  => $shipping * 100,
			'discounts'	=> $discounts
			]
		);
	}

	public function get_affirm_field( $form ) {
		$fields = GFFormsModel::get_fields_by_type( $form, array( 'affirm' ) );
		return empty( $fields ) ? false : $fields[0];
	}

	public function has_affirm_field( $form = null ) {
		if ( is_null( $form ) ) {
			$form = $this->get_current_form();
		}
		return $this->get_affirm_field( $form ) !== false;
	}

	public function before_delete_field( $form_id, $field_id ) {
		parent::before_delete_field( $form_id, $field_id );

		$form = GFAPI::get_form( $form_id );
		if ( $this->has_affirm_field( $form ) ) {
			$field = $this->get_affirm_field( $form );

			if ( is_object( $field ) && $field->id == $field_id ) {
				$feeds = $this->get_feeds( $form_id );
				foreach ( $feeds as $feed ) {
					if ( $feed['is_active'] ) {
						$this->update_feed_active( $feed['id'], 0 );
					}
				}
			}
		}
	}

	public function get_webhook_url( $feed_id = null ) {

		$url = home_url( '/', 'https' ) . '?callback=' . $this->_slug;

		if ( ! rgblank( $feed_id ) ) {
			$url .= '&fid=' . $feed_id;
		}

		return $url;

	}

	public function frontend_script_callback( $form ) {

		if ( is_admin() ) {
			if ( $this->is_app_settings( $this->_slug ) ) {
				return true;
			}
		} else {
			return $form && $this->has_feed( $form['id'] ) && $this->initialize_api();
		}

	}

	public function get_environment( $settings = null){
		if ( empty( $settings ) ) {
			return $this->get_plugin_setting( 'mode_option' );
		}
		return rgar( $settings, 'mode_option' );
	}

	public function get_public_key( $settings = null){
		if ( empty( $settings ) ) {
			return $this->get_plugin_setting( 'public_key' );
		}
		return rgar( $settings, 'public_key' );
	}

	public function get_private_key( $settings = null){
		if ( empty( $settings ) ) {
			return $this->get_plugin_setting( 'private_key' );
		}
		return rgar( $settings, 'private_key' );
	}

	public function get_product_key( $settings = null){
		if ( empty( $settings ) ) {
			return $this->get_plugin_setting( 'product_key' );
		}
		return rgar( $settings, 'product_key' );
	}

	public function get_affirm_src(){
		if ( $this->get_environment() == "live" )
			return $this->live_js;
		else
			return $this->sandbox_js;
	}

	public function add_smart_payment_buttons( $button, $form ) {
		
		$is_form_editor  = GFCommon::is_form_editor();
		$is_entry_detail = GFCommon::is_entry_detail();
		
		if ( ! $this->has_feed( $form['id'] ) || $this->has_affirm_field( $form )== false || ! $this->initialize_api() || $is_form_editor ) {
			return $button;
		}

		$scripts = '<!-- Affirm -->
		<script>
		 _affirm_config = {
		   public_api_key:  "'. $this->get_public_key().'",
		   script:          "'.$this->get_affirm_src().'"
		 };
		 (function(l,g,m,e,a,f,b){var d,c=l[m]||{},h=document.createElement(f),n=document.getElementsByTagName(f)[0],k=function(a,b,c){return function(){a[b]._.push([c,arguments])}};c[e]=k(c,e,"set");d=c[e];c[a]={};c[a]._=[];d._=[];c[a][b]=k(c,a,b);a=0;for(b="set add save post open empty reset on off trigger ready setProduct".split(" ");a<b.length;a++)d[b[a]]=k(c,e,b[a]);a=0;for(b=["get","token","url","items"];a<b.length;a++)d[b[a]]=function(){};h.async=!0;h.src=g[f];n.parentNode.insertBefore(h,n);delete g[f];d(g);l[m]=c})(window,_affirm_config,"affirm","checkout","ui","script","ready");
		 </script>
		<!-- End Affirm -->';
		$button .= '<div id="gform_affirm_smart_payment_buttons" class="has_feed">Affirm Checkout</div>';

		return $button.$scripts;
	}

	public function callback() {
		 $result = parent::callback();
		 $this->log_debug( __METHOD__ . '():Affirm redirect the seller back to the site; Settings : ' . print_r( $result, 1 ) );
		 return $result;
	}

	public function complete_authorization( &$entry, $auth ) {
		
		$order = gform_get_meta( $entry['id'], 'order_data' );

		if ( rgar( $entry, 'payment_status' ) === 'Pending' ) {
			$auth['amount']         = rgars( $order, 'amount' ) / 100;
			$auth['transaction_id'] = rgars( $order, 'events/0/transaction_id' );

			$this->add_pending_payment( $entry, $auth );

			return true;
		}
		$order_amount            = rgars( $order, 'amount' ) / 100;
		$entry['payment_amount'] = $order_amount;
		$auth['amount']        = $order_amount;
		$entry['payment_method'] = "Affirm";

		return parent::complete_authorization( $entry, $auth );
	}

	public function maybe_add_feed( $form_meta, $is_new ) {
		if ( $is_new ) {
			return;
		}

		if ( $this->has_affirm_field( $form_meta ) ) {
			$field = $this->get_affirm_field( $form_meta );

			$feeds = $this->get_feeds( $field->formId );
			// Only activate the feed if there's only one.
			if ( count( $feeds ) === 1 ) {
				if ( ! $feeds[0]['is_active'] ) {
					$this->update_feed_active( $feeds[0]['id'], 1 );
				}
			} elseif ( ! $feeds ) {
				// Add a new Affirm Checkout feed.
				$name_field    = GFFormsModel::get_fields_by_type( $form_meta, array( 'name' ) );
				$email_field   = GFFormsModel::get_fields_by_type( $form_meta, array( 'email' ) );
				$address_field = GFFormsModel::get_fields_by_type( $form_meta, array( 'address' ) );

				$feed = array(
					'feedName'                                => $this->get_short_title() . ' Feed 1',
					'transactionType'                         => 'product',
					'paymentAmount'                           => 'form_total',
					'no_shipping'                             => '0',
					'feed_condition_conditional_logic'        => '0',
					'feed_condition_conditional_logic_object' => array(),
				);

				if ( ! empty( $name_field ) ) {
					$feed['billingInformation_first_name'] = $name_field[0]->id . '.3';
					$feed['billingInformation_last_name']  = $name_field[0]->id . '.6';
				}

				if ( ! empty( $email_field ) ) {
					$feed['billingInformation_email'] = $email_field[0]->id;
				}

				if ( ! empty( $address_field ) ) {
					$feed['billingInformation_address']  = $address_field[0]->id . '.1';
					$feed['billingInformation_address2'] = $address_field[0]->id . '.2';
					$feed['billingInformation_city']     = $address_field[0]->id . '.3';
					$feed['billingInformation_state']    = $address_field[0]->id . '.4';
					$feed['billingInformation_zip']      = $address_field[0]->id . '.5';
					$feed['billingInformation_country']  = $address_field[0]->id . '.6';
				}

				GFAPI::add_feed( $field->formId, $feed, $this->get_slug() );
			}
		}
	}

	public function initialize_api( $environment = null ) {
		// Get the client environment.
		if ( empty( $environment ) ) {
			$environment = $this->get_environment();
		}

		// If the credentials are not set, return null.
		if (  empty($this->get_public_key()) || empty($this->get_private_key()) ) {
			return false;
		}

		if(  ! is_null($this->verified) )
			return $this->verified;
		// Initialize a new Affirm Checkout API instance.
		if ( class_exists( 'GF_AFFIRM_API' )){
			$affirm = new GF_AFFIRM_API( $this->get_public_key(), $this->get_private_key(), $environment );
			$this->api = $affirm;
			$this->verified = $this->api->verify_key();
			return $this->verified;
		}
		return false;
	}

}