<?php
/**
 * An Addon controller.
 *
 * @copyright Incsub (http://incsub.com/)
 *
 * @license http://opensource.org/licenses/GPL-2.0 GNU General Public License, version 2 (GPL-2.0)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,
 * MA 02110-1301 USA
 *
 */

/**
 * Add-On controller for: Taxamo
 *
 * - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
 * How it works:
 *
 * Tax details are stored in the user-metadata.
 * When the user metadata don't contain tax details then Taxamo API is used to
 * get the defaults for the country detected by IP address.
 *
 * If this details are not correct then the user can modify the tax details
 * stored in user-metadata via his profile and on the checkout page by providing
 * two matching proofs such as VAT Number, Credit Card Number, etc.
 *
 * Though tax details are stored in user-metadata each invoice includes basic
 * details (tax-rate and tax-name) since an invoice cannot be modified once it
 * was paid. Changes in the user-meta will affect all invoices that are
 * generated in the future.
 * - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
 *
 * @since 1.1.0
 *
 * @package Membership2
 * @subpackage Controller
 */
class MS_Addon_Taxamo extends MS_Addon {

	/**
	 * The Add-on ID
	 *
	 * @since 1.1.0
	 */
	const ID = 'addon_taxamo';

	// Ajax Actions
	const AJAX_SAVE_SETTING = 'addon_taxamo_save';
	const AJAX_SAVE_USERPROFILE = 'addon_taxamo_profile';

	/**
	 * HTML code that is output in the page footer when tax-editor dialog is
	 * available.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	static protected $footer_html = '';

	/**
	 * Checks if the current Add-on is enabled
	 *
	 * @since  1.1.0
	 * @return bool
	 */
	static public function is_active() {
		return MS_Model_Addon::is_enabled( self::ID );
	}

	/**
	 * Initializes the Add-on. Always executed.
	 *
	 * @since  1.1.0
	 */
	public function init() {
		if ( self::is_active() ) {
			$this->add_action(
				'ms_tax_editor',
				'tax_editor'
			);

			// Add new settings tab
			$this->add_filter(
				'ms_controller_settings_get_tabs',
				'settings_tabs',
				10, 2
			);

			$this->add_filter(
				'ms_view_settings_edit_render_callback',
				'manage_render_callback',
				10, 3
			);

			// Save settings via ajax
			$this->add_ajax_action(
				self::AJAX_SAVE_SETTING,
				'ajax_save_setting'
			);

			// Save settings via ajax
			$this->add_ajax_action(
				self::AJAX_SAVE_USERPROFILE,
				'ajax_save_userprofile',
				true, true
			);

			// Confirm payments with Taxamo
			$this->add_action(
				'ms_gateway_paypalsingle_payment_processed_' . MS_Model_Invoice::STATUS_PAID,
				'confirm_payment'
			);
			$this->add_action(
				'ms_gateway_paypalstandard_payment_processed_' . MS_Model_Invoice::STATUS_PAID,
				'confirm_payment'
			);

			// Add taxes to the price, based on users country.
			$this->add_filter(
				'ms_apply_taxes',
				'apply_taxes'
			);

			// Set tax-details on a new invoice.
			$this->add_filter(
				'ms_invoice_tax_rate',
				'invoice_tax_rate'
			);

			$this->add_filter(
				'ms_invoice_tax_name',
				'invoice_tax_name'
			);

			// Track the transaction in taxamo
			$this->add_filter(
				'ms_invoice_paid',
				'invoice_paid'
			);
		}
	}

	/**
	 * Registers the Add-On
	 *
	 * @since  1.1.0
	 * @param  array $list The Add-Ons list.
	 * @return array The updated Add-Ons list.
	 */
	public function register( $list ) {
		$list[ self::ID ] = (object) array(
			'name' => __( 'Taxamo', MS_TEXT_DOMAIN ),
			'description' => __( 'Addresses EU VAT regulations.', MS_TEXT_DOMAIN ),
			'icon' => 'wpmui-fa wpmui-fa-euro',
			'details' => array(
				array(
					'type' => MS_Helper_Html::TYPE_HTML_TEXT,
					'title' => __( 'Settings', MS_TEXT_DOMAIN ),
					'desc' => __( 'When this Add-on is enabled you will see a new section in the "Settings" page with additional options.', MS_TEXT_DOMAIN ),
				),
			),
		);

		return $list;
	}

	/**
	 * Returns the Taxamo-Settings model.
	 *
	 * @since  1.1.0
	 * @return MS_Addon_Taxamo_Model
	 */
	static public function model() {
		static $Model = null;

		if ( null === $Model ) {
			$Model = MS_Factory::load( 'MS_Addon_Taxamo_Model' );
		}

		return $Model;
	}

	/**
	 * Add taxamo settings tab in settings page.
	 *
	 * @since  1.1.0
	 *
	 * @param array $tabs The current tabs.
	 * @return array The filtered tabs.
	 */
	public function settings_tabs( $tabs ) {
		$tabs[ self::ID ] = array(
			'title' => __( 'Taxamo', MS_TEXT_DOMAIN ),
			'url' => MS_Controller_Plugin::get_admin_url(
				'settings',
				array( 'tab' => self::ID )
			),
		);

		return $tabs;
	}

	/**
	 * Add taxamo settings-view callback.
	 *
	 * @since  1.1.0
	 *
	 * @param  array $callback The current function callback.
	 * @param  string $tab The current membership rule tab.
	 * @param  array $data The data shared to the view.
	 * @return array The filtered callback.
	 */
	public function manage_render_callback( $callback, $tab, $data ) {
		if ( self::ID == $tab ) {
			$view = MS_Factory::load( 'MS_Addon_Taxamo_View' );
			$callback = array( $view, 'render_tab' );
		}

		return $callback;
	}

	/**
	 * Handle Ajax update custom setting action.
	 *
	 * @since 1.1.0
	 */
	public function ajax_save_setting() {
		$msg = MS_Helper_Settings::SETTINGS_MSG_NOT_UPDATED;

		$isset = array( 'field', 'value' );
		if ( $this->verify_nonce()
			&& self::validate_required( $isset, 'POST', false )
			&& $this->is_admin_user()
		) {
			$model = self::model();
			lib2()->array->strip_slashes( $_POST, 'value' );

			$model->set( $_POST['field'], $_POST['value'] );
			$model->save();
			$msg = MS_Helper_Settings::SETTINGS_MSG_UPDATED;
		}

		wp_die( $msg );
	}

	/**
	 * Adds taxes to the net-amount.
	 *
	 * @since  1.1.0
	 * @param  numeric $net_value Net value
	 * @return numeric Gross value
	 */
	public function apply_taxes( $net_value ) {
		$gross_value = 0;

		if ( is_numeric( $net_value ) ) {
			$tax = MS_Addon_Taxamo_Api::tax_info( $net_value );
			$gross_value = $net_value + $tax->amount;
		}

		return $gross_value;
	}

	/**
	 * Return the tax-rate for the users country.
	 *
	 * @since  1.1.0
	 * @param  numeric $rate Default rate (0)
	 * @return numeric Tax rate to apply (e.g. 20 for 20%)
	 */
	public function invoice_tax_rate( $rate ) {
		$tax = MS_Addon_Taxamo_Api::tax_info();

		return $tax->rate;
	}

	/**
	 * Return the tax-name for the users country.
	 *
	 * @since  1.1.0
	 * @param  string $name Default name (empty string)
	 * @return string Tax display-name (e.g. 'EU Standard Tax (20 %)')
	 */
	public function invoice_tax_name( $rate ) {
		$tax = MS_Addon_Taxamo_Api::tax_info();

		return $tax->rate . '% ' . $tax->name;
	}

	/**
	 * When an invoice was paid we need to notify taxamo of the transaction.
	 *
	 * @since  1.1.0
	 * @param  MS_Model_Invoice $invoice The processed invoice.
	 */
	public function invoice_paid( $invoice ) {
		if ( ! $invoice->is_paid() ) { return; }
		if ( 0 == $invoice->total ) { return; }

		$membership = $invoice->get_membership();
		$member = $invoice->get_member();

		MS_Addon_Taxamo_Api::register_payment(
			$invoice->total,   // Transaction amount
			$membership->name, // Transaction title
			$invoice->tax_rate, // Tax-rate
			$invoice->invoice_number, // Internal Transaction ID = Invoice Number
			$member->full_name,  // Buyer name
			$member->email,  // Buyer email
			$invoice->gateway_id // Payment gateway
		);
	}

	/**
	 * Load taxamo scripts.
	 *
	 * @since 2.0.0
	 * @param  MS_Model_Invoice $invoice Optional. The invoice to edit.
	 */
	public function tax_editor( $invoice ) {
		static $Done = false;

		// Only one tax-editor is possible per page.
		if ( $Done ) { return; }
		$Done = true;

		$this->add_action(
			'wp_footer',
			'tax_editor_footer'
		);

		$plugin_url = MS_Plugin::instance()->url;

		wp_enqueue_style(
			'ms-addon-taxamo',
			$plugin_url . '/app/addon/taxamo/assets/css/taxamo.css'
		);

		wp_enqueue_script(
			'ms-addon-taxamo',
			$plugin_url . '/app/addon/taxamo/assets/js/taxamo.js',
			array( 'jquery' )
		);

		$view = MS_Factory::load( 'MS_Addon_Taxamo_Userprofile' );
		$view->data = apply_filters(
			'ms_addon_taxamo_editor_data',
			array( 'invoice' => $invoice ),
			$this
		);
		self::$footer_html .= '<div id="ms-taxamo-wrapper">' . $view->to_html() . '</div>';

		do_action( 'ms_addon_taxamo_enqueue_scripts', $this );
	}

	/**
	 * Outputs tax-editor code in the page footer.
	 *
	 * @since  12.0.0
	 */
	public function tax_editor_footer() {
		echo self::$footer_html;
		?>
		<script>
		window.ms_taxamo = {
			"ajax_url" : "<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>"
		};
		</script>
		<?php
	}

	/**
	 * Handle Ajax action to update a user tax-profile field.
	 *
	 * @since 2.0.0
	 */
	public function ajax_save_userprofile() {
		$response = '';

		$isset = array(
			'vat_number',
			'declare_manually',
			'billing_country',
			'invoice_id',
		);

		if ( $this->verify_nonce()
			&& self::validate_required( $isset, 'POST', false )
		) {
			$invoice_id = intval( $_POST['invoice_id'] );
			$invoice = MS_Factory::load( 'MS_Model_Invoice', $invoice_id );
			$data = $_POST;
			unset( $data['invoice_id'] );
			unset( $data['action'] );
			unset( $data['_wpnonce'] );

			$data['billing_country'] = (object) array(
				'ip' => '',
				'code' => $data['billing_country'],
				'tax_supported' => ('XX' != $data['billing_country']),
			);

			if ( lib2()->is_true( $data['declare_manually'] ) ) {
				$data['vat_number'] = '';
				$data['use_billing'] = true;
			} else {
				$data['billing_country']->code = '';
				$data['billing_country']->tax_supported = false;
				$data['use_billing'] = false;
			}
			unset( $data['declare_manually'] );

			foreach ( $data as $field => $value ) {
				$value = apply_filters(
					'ms_addon_taxamo_userprofile_value',
					$value,
					$field,
					$invoice_id,
					$this
				);

				MS_Addon_Taxamo_Api::set_user_profile( $field, $value );
			}

			// User profile updated. Now update the tax-rate in the invoice.
			if ( $invoice->is_valid() ) {
				$invoice->total_amount_changed();
				$invoice->save();
			}

			$view = MS_Factory::load( 'MS_Addon_Taxamo_Userprofile' );
			$view->data = apply_filters(
				'ms_addon_taxamo_editor_data',
				array( 'invoice' => $invoice ),
				$this
			);
			$response = trim( $view->to_html() );
		}

		wp_die( $response );
	}

}