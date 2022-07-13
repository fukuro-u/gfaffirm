<?php

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * The Affirm field is a payment methods field used specifically by the Affirm Checkout Add-On.
 *
 * @since 1.0
 *
 * Class GF_Field_Affirm
 */
class GF_Field_Affirm extends GF_Field {

	/**
	 * Field type.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	public $type = 'affirm';

	/**
	 * Get field button title.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_form_editor_field_title() {
		return esc_attr__( 'Affirm', 'gfaffirm' );
	}

	/**
	 * Get this field's icon.
	 *
	 * @since 1.4
	 *
	 * @return string
	 */
	public function get_form_editor_field_icon() {
		return gf_affirm()->get_menu_icon();
	}

	/**
	 * Get form editor button.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_form_editor_button() {
		return array(
			'group' => 'pricing_fields',
			'text'  => $this->get_form_editor_field_title(),
		);
	}

	/**
	 * Get field settings in the form editor.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_form_editor_field_settings() {
		return array(
			'affirm_default_payment_method',
			'conditional_logic_field_setting',
			'force_ssl_field_setting',
			'error_message_setting',
			'label_setting',
			'label_placement_setting',
			'admin_label_setting',
			'rules_setting',
			'description_setting',
			'css_class_setting',
		);
	}

	/**
	 * Registers the script returned by get_form_inline_script_on_page_render() for display on the front-end.
	 *
	 * @since 1.0
	 *
	 * @param array $form The Form Object currently being processed.
	 *
	 * @return string
	 */
	public function get_form_inline_script_on_page_render( $form ) {
		
		if ( ! gf_affirm()->initialize_api() ) {
			return '';
		}
		$script = '';
		if ( $this->forceSSL && ! GFCommon::is_ssl() && ! GFCommon::is_preview() ) {
			$script = "document.location.href='" . esc_js( RGFormsModel::get_current_page_url( true ) ) . "';";
		}

		return $script;
	}

	/**
	 * Returns the scripts to be included for this field type in the form editor.
	 *
	 * @since  1.0
	 *
	 * @return string
	 */
	public function get_form_editor_inline_script_on_page_render() {

		// Register inputs (sub-labels).
		$js = sprintf( "function SetDefaultValues_%s(field) {field.label = '%s';
			}",
				$this->type,
                esc_html__( 'Affirm Payment', 'gravityformsppcp' ) ) . PHP_EOL;

		return $js;
	}

	/**
	 * Override the parent validate method.
	 *
	 * @since 1.0
	 *
	 * @param array|string $value The field value.
	 * @param array        $form  The form object.
	 */
	public function validate( $value, $form ) {
		// do nothing here.
	}

	/**
	 * Get field input.
	 *
	 * @since 1.0
	 *
	 * @param array      $form  The Form Object currently being processed.
	 * @param array      $value The field value. From default/dynamic population, $_POST, or a resumed incomplete submission.
	 * @param null|array $entry Null or the Entry Object currently being edited.
	 *
	 * @return string
	 */
	public function get_field_input( $form, $value = array(), $entry = null ) {
		$is_entry_detail = $this->is_entry_detail();
		$is_form_editor  = $this->is_form_editor();
		$is_admin        = $is_entry_detail || $is_form_editor;

		// Display error message when API not connected.
		if ( ! gf_affirm()->initialize_api() ) {
			if ( ! $is_admin ) {
				return sprintf( esc_html__( '%sPlease check your Affirm Checkout Add-On settings. Your API is not connected yet.%s' ), '<div class="gfield_description validation_message">', '</div>' );
			} else {
				return '<div>' . gf_affirm()->configure_addon_message() . '</div>';
			}
		}

		if ( ! $is_admin && ! gf_affirm()->has_feed( $form['id'] ) ) {
			return sprintf( esc_html__( '%sPlease check if you have activated a Affirm Checkout feed for your form.%s' ), '<div class="gfield_description validation_message">', '</div>' );
		}

		$form_id  = $form['id'];
		$id       = intval( $this->id );
		$field_id = $is_entry_detail || $is_form_editor || $form_id == 0 ? "input_$id" : 'input_' . $form_id . "_$id";
		$form_id  = ( $is_entry_detail || $is_form_editor ) && empty( $form_id ) ? rgget( 'id' ) : $form_id;

		$disabled_text = $is_form_editor ? "disabled='disabled'" : '';
		$class_suffix  = $is_entry_detail ? '_admin' : '';

		$style =  '' ;
		$field_input = '';
		if ( $is_form_editor ) {
			$field_input .= '<div class="gf-html-container smart_payment_buttons_note" ' . $style . '>
								<span class="gf_blockheader"><i class="fa fa-money fa-lg"></i> ' . esc_html__( 'Affirm Checkout', 'gfaffirm' ) . '</span>
								<span>' . esc_html__( 'Affirm Checkout is enabled for your form. Your customer can pay with the Affirm Smart Payment Buttons which replaces the Submit button of your form.', 'gfaffirm' ) . '</span>
							</div>';
		}

		return $field_input;
	}

	public function get_field_label_class() {
		$label_classes = 'gfield_label gfield_label_before_complex';
		if ( gf_affirm()->can_create_feed() )
			$label_classes .= ' gfield_visibility_hidden';
		return $label_classes;
	}

	/**
	 * Remove the duplicate admin button.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_admin_buttons() {
		add_filter( 'gform_duplicate_field_link', '__return_empty_string' );

		$admin_buttons = parent::get_admin_buttons();

		remove_filter( 'gform_duplicate_field_link', '__return_empty_string' );

		return $admin_buttons;
	}

	/**
	 * Add tooltips for our custom setting sections.
	 *
	 * @since 1.0
	 *
	 * @param array $tooltips The tooltips.
	 *
	 * @return mixed
	 */
	public static function add_tooltips( $tooltips ) {
		// $tooltips['supported_payment_methods']     = '<h6>' . esc_html__( 'Supported Payment Methods', 'gfaffirm' ) . '</h6>' . esc_html__( 'Enable the payment methods.', 'gfaffirm' );
		$tooltips['affirm_default_payment_method'] = '<h6>' . esc_html__( 'Default Payment Method', 'gfaffirm' ) . '</h6>' . esc_html__( 'Set the default payment method.', 'gfaffirm' );
		$tooltips['affirm_checkout']               = '<h6>' . esc_html__( 'Affirm Checkout', 'gfaffirm' ) . '</h6>' . esc_html__( 'The Affirm Smart Payment Buttons can be customized in the Appearance settings. The Paypal logo will be displayed on the button.', 'gfaffirm' );

		return $tooltips;
	}

	/**
	 * Overwrite the parent method to avoid the field upgrade from the credit card field class.
	 *
	 * @since 1.0
	 */
	public function post_convert_field() {
		GF_Field::post_convert_field();
	}
}

GF_Fields::register( new GF_Field_Affirm() );
