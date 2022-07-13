<?php
class GF_AFFIRM_API {

	/**
	 * Affirm Checkout API key.
	 *
	 * @since  1.0
	 *
	 * @var    array $credentials Affirm Checkout API credentials.
	 */
	protected $public_key;
	protected $private_key;

	/**
	 * Affirm Checkout API URL.
	 *
	 * @since  1.0
	 *
	 * @var    string $api_url Affirm Checkout API URL.
	 */
	protected $api_url = 'https://sandbox.affirm.com/api/';

	/**
	 * Affirm Checkout environment.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $environment Affirm Checkout environment.
	 */
	protected $environment;

	/**
	 * Initialize Affirm Checkout API library.
	 *
	 * @since 1.0
	 *
	 * @param array|null $credentials Affirm Checkout API credentials.
	 * @param string     $environment Affirm Checkout environment.
	 */
	public function __construct( $public_key = null, $private_key = null, $environment = 'sandbox' ) {

		$this->public_key = $public_key;
		$this->private_key = $private_key;
		$this->environment = $environment;

		if ( $this->environment === 'live' ) {
			$this->api_url = 'https://api.affirm.com/api/';
		}

	}
	
	private function make_request( $action, $options = array(), $method = 'GET', $response_code = 200 ) {

		// Prepare request URL.
		$request_url = $this->api_url . $action;

		// Default headers.
		$headers = array(
			'Content-Type'                  => 'application/json',
		);

		// Add Authorization header if credentials are set.
		if ( ! empty( $this->public_key ) && ! empty( $this->private_key ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $this->public_key . ':' . $this->private_key  );
		}

		// Get body and headers if set in $options.
		$headers = rgar( $options, 'headers' ) ? wp_parse_args( $options['headers'], $headers ) : $headers;
		$body    = rgar( $options, 'body' ) ? $options['body'] : $options;

		// Add query parameters.
		if ( 'GET' === $method ) {
			$request_url = add_query_arg( $options, $request_url );
		}

		// Build request arguments.
		$args = array(
			'method'    => $method,
			'headers'   => $headers,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),

			'timeout'   => apply_filters( 'http_request_timeout', 30, $request_url ),
		);

		// Add body to non-GET requests.
		if ( 'GET' !== $method && ! empty( $body ) ) {
			$args['body'] = ( $args['headers']['Content-Type'] === 'application/json' ) ? json_encode( $body ) : $body;
		}

		// Execute API request.
		$result = wp_remote_request( $request_url, $args );

		// If API request returns a WordPress error, return.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Convert JSON response to array.
		$result_body = wp_remote_retrieve_body( $result );
		if ( ! empty( $result_body ) ) {
			$result_body = gf_affirm()->maybe_decode_json( $result_body );
		} else {
			$result_body = array();
		}

		// If result response code is not the expected response code, return error.
		if ( wp_remote_retrieve_response_code( $result ) !== $response_code ) {
			// Use the error description in the body if available (it's usually more human readable messages).
			$error = rgar( $result_body, 'message' ) ? $result_body['message'] : wp_remote_retrieve_response_message( $result );
			$error_data = rgar( $result_body, 'code' );

			return new WP_Error( wp_remote_retrieve_response_code( $result ), $error, $error_data );
		}
		return $result_body;
	}

	public function verify_key() {
		
		$args = array(
			'body'    => array(
				'checkout_token'    => 'affirm_test_checkout_token',
			),
		);

		$result = $this->make_request( 'v2/charges', $args, 'POST', 400 );

		if ( ! is_wp_error( $result ) ) {
			return true;
		}
		else
			return false;
	}

	public function charges_order( $order_id, $checkout_token) {
		
		$args = array(
			'body'    => array(
				'checkout_token'    => $checkout_token,
				"order_id"			=> $order_id
			),
		);

		$result = $this->make_request( 'v2/charges', $args, 'POST', 200 );

		if ( is_wp_error( $result ) ) {
			return false;
		}
		else
			return $result;
	}
	
	public function capture_charge( $charge_id ) {
		return $this->make_request( 'v2/charges/' . $charge_id . '/capture', array(), 'POST', 200 );
	}
	
	public function refund_charge( $charge_id ) {
		return $this->make_request( 'v2/charges/' . $charge_id . '/refund', array(), 'POST', 200 );
	}

	public function void_charge( $charge_id ) {
		return $this->make_request( 'v2/charges/' . $charge_id . '/void', array(), 'POST', 200 );
	}

	public function read_charge( $charge_id ) {
		return $this->make_request( 'v2/charges/' . $charge_id, array(), 'GET', 200 );
	}
	
}