<?php
/**
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

class MS_Model_Gateway extends MS_Model_Option {
	
	protected static $CLASS_NAME = __CLASS__;
	
	protected $id = 'gateway';
	
	protected $name = 'Abstract Gateway';
	
	protected $description = 'Abstract Gateway Desc';
	
	protected $active = false;
	
	protected $is_single = true;
	
	protected $pro_rate = false;
	
	protected $pay_button_url;
	
	protected $upgrade_button_url;
	
	protected $cancel_button_url;
	
	protected $mode;
	
	protected static $gateways;
	
	public function after_load() {
		if( $this->active ) {
			$this->add_action( 'ms_view_registration_payment_form', 'purchase_button', 10, 4 );
			$this->add_action( "ms_model_gateway_handle_payment_return_{$this->id}", 'handle_return' );
		}
	}
	
	public static function get_gateways() {
		if( empty( self::$gateways ) ) {
			self::$gateways = array(
				'free_gateway' => MS_Model_Gateway_Free::load(),
				'manual_gateway' => MS_Model_Gateway_Manual::load(),
				'paypal_standard_gateway' => MS_Model_Gateway_Paypal_Standard::load(),
				'paypal_single_gateway' => MS_Model_Gateway_Paypal_Single::load(),
				'authorize' => MS_Model_Gateway_Authorize::load(),
			);
		}
		return apply_filters( 'ms_model_gateway_get_gateways' , self::$gateways );
	}
	
	public static function get_gateway_names() {
		$gateways = self::get_gateways();
		$names = array();
		foreach( $gateways as $gateway ) {
			$names[ $gateway->id ] = $gateway->name;
		}
		return apply_filters( 'ms_model_gateway_get_gateway_names' , $names );
	}
	
	public static function is_valid_gateway( $gateway_id ) {
		return apply_filters( 'ms_model_gateway_is_valid_gateway', array_key_exists( $gateway_id, self::get_gateways() ) );
	}
	
	public static function factory( $gateway_id ) {
		$gateway = null;
		
		if( self::is_valid_gateway( $gateway_id ) ) {
			$gateways = self::get_gateways();
			$gateway = $gateways[ $gateway_id ];
		}
		
		return apply_filters( 'ms_model_gateway_factory', $gateway, $gateway_id );
	}
	
	public function purchase_button( $membership, $member ) {
		
	}
	
	public function handle_return() {
		
	}
	
	/**
	 * Url that fires handle_return of this gateway.
	 * 
	 * @todo Use pretty permalinks structure like /ms-payment-return/{$this->id}
	 * @return string The return url.
	 */
	public function get_return_url() {
		return apply_filters( 'ms_model_gateway_get_return_url', site_url( '/ms-payment-return/' . $this->id ), $this->id );
	}
	
	public function build_custom( $user_id, $membership_id, $amount, $move_from_id = 0, $coupon_id = 0 ) {
	
		$custom = array(
				time(),
				$user_id,
				$membership_id,
				$move_from_id,
				$coupon_id,
				md5( 'MEMBERSHIP' . $amount ),
		);
	
		return apply_filters( 'ms_model_gateway_build_custom', implode( ':', $custom ), $custom );
	}
	
	public function add_transaction( $membership, $member, $status, $move_from_id = 0, $coupon_id = 0, $external_id = null, $notes = null ) {
		
		if( ! MS_Model_Membership::is_valid_membership( $membership->id ) ) {
			return;
		}
		$transaction = MS_Model_Transaction::create_transaction( $membership, $member, $this->id, $status );
		if(  ! MS_Plugin::instance()->addon->multiple_membership && ! empty( $member->membership_relationships[ $move_from_id ] ) ) {
			$ms_relationship = $member->membership_relationships[ $move_from_id ];
			$ms_relationship->move_to_id = $membership->id;
			$ms_relationship->save();
			if( $this->pro_rate ) {
				$pro_rate = $ms_relationship->calulate_pro_rate();
				$transaction->discount = $pro_rate;
				$notes .= sprintf( __( 'Pro rate discount: %s %s. ', MS_TEXT_DOMAIN ), $transaction->currency, $pro_rate );
			}
		}
		if( ! empty( $coupon_id ) ) {
			$coupon = MS_Model_Coupon::load( $coupon_id );
			$discount = $coupon->get_coupon_application( $member->id, $membership->id );
			$coupon->remove_coupon_application( $member->id, $membership->id );
			$coupon->used++;
			$coupon->save();
			$transaction->discount += $discount; 
			$notes .= sprintf( __( 'Coupon %s, discount: %s %s. ', MS_TEXT_DOMAIN ), $coupon->code, $transaction->currency, $discount );
		}
		$transaction->external_id = $external_id;
		$transaction->notes = $notes;
		$transaction->due_date = MS_Helper_Period::current_date();
// 		$transaction->process_transaction( $status, true );
		$transaction->save();
		return $transaction;
	}
	
	/**
	 * Get gateway mode types.
	 *
	 * @since 4.0
	 *
	 */
	public function get_mode_types() {
		return apply_filters( 'ms_model_gateway_get_mode_types', array(
				'live' => __( 'Live Site', MS_TEXT_DOMAIN ),
				'test' => __( 'Test Mode (Sandbox)', MS_TEXT_DOMAIN ),
		) );
	}
	
	/**
	 * Validate specific property before set.
	 *
	 * @since 4.0
	 *
	 * @access public
	 * @param string $property The name of a property to associate.
	 * @param mixed $value The value of a property.
	 */
	public function __set( $property, $value ) {
		if ( property_exists( $this, $property ) ) {
			switch( $property ) {
				case 'id':
				case 'name':
					break;
				case 'description':
				case 'pay_button_url':
				case 'upggrade_button_url':
				case 'cancel_button_url':
					$this->$property = sanitize_text_field( $value );
					break;
				default:
					$this->$property = $value;
					break;
			}
		}
	}
	
	public function get_country_codes() {
		return apply_filters( 'ms_model_gateway_get_country_codes', array(
				'' => __('Select country', MS_TEXT_DOMAIN ),
				'AX' => __('ÃLAND ISLANDS', MS_TEXT_DOMAIN ),
				'AL' => __('ALBANIA', MS_TEXT_DOMAIN ),
				'DZ' => __('ALGERIA', MS_TEXT_DOMAIN ),
				'AS' => __('AMERICAN SAMOA', MS_TEXT_DOMAIN ),
				'AD' => __('ANDORRA', MS_TEXT_DOMAIN ),
				'AI' => __('ANGUILLA', MS_TEXT_DOMAIN ),
				'AQ' => __('ANTARCTICA', MS_TEXT_DOMAIN ),
				'AG' => __('ANTIGUA AND BARBUDA', MS_TEXT_DOMAIN ),
				'AR' => __('ARGENTINA', MS_TEXT_DOMAIN ),
				'AM' => __('ARMENIA', MS_TEXT_DOMAIN ),
				'AW' => __('ARUBA', MS_TEXT_DOMAIN ),
				'AU' => __('AUSTRALIA', MS_TEXT_DOMAIN ),
				'AT' => __('AUSTRIA', MS_TEXT_DOMAIN ),
				'AZ' => __('AZERBAIJAN', MS_TEXT_DOMAIN ),
				'BS' => __('BAHAMAS', MS_TEXT_DOMAIN ),
				'BH' => __('BAHRAIN', MS_TEXT_DOMAIN ),
				'BD' => __('BANGLADESH', MS_TEXT_DOMAIN ),
				'BB' => __('BARBADOS', MS_TEXT_DOMAIN ),
				'BE' => __('BELGIUM', MS_TEXT_DOMAIN ),
				'BZ' => __('BELIZE', MS_TEXT_DOMAIN ),
				'BJ' => __('BENIN', MS_TEXT_DOMAIN ),
				'BM' => __('BERMUDA', MS_TEXT_DOMAIN ),
				'BT' => __('BHUTAN', MS_TEXT_DOMAIN ),
				'BA' => __('BOSNIA-HERZEGOVINA', MS_TEXT_DOMAIN ),
				'BW' => __('BOTSWANA', MS_TEXT_DOMAIN ),
				'BV' => __('BOUVET ISLAND', MS_TEXT_DOMAIN ),
				'BR' => __('BRAZIL', MS_TEXT_DOMAIN ),
				'IO' => __('BRITISH INDIAN OCEAN TERRITORY', MS_TEXT_DOMAIN ),
				'BN' => __('BRUNEI DARUSSALAM', MS_TEXT_DOMAIN ),
				'BG' => __('BULGARIA', MS_TEXT_DOMAIN ),
				'BF' => __('BURKINA FASO', MS_TEXT_DOMAIN ),
				'CA' => __('CANADA', MS_TEXT_DOMAIN ),
				'CV' => __('CAPE VERDE', MS_TEXT_DOMAIN ),
				'KY' => __('CAYMAN ISLANDS', MS_TEXT_DOMAIN ),
				'CF' => __('CENTRAL AFRICAN REPUBLIC', MS_TEXT_DOMAIN ),
				'CL' => __('CHILE', MS_TEXT_DOMAIN ),
				'CN' => __('CHINA', MS_TEXT_DOMAIN ),
				'CX' => __('CHRISTMAS ISLAND', MS_TEXT_DOMAIN ),
				'CC' => __('COCOS (KEELING) ISLANDS', MS_TEXT_DOMAIN ),
				'CO' => __('COLOMBIA', MS_TEXT_DOMAIN ),
				'CK' => __('COOK ISLANDS', MS_TEXT_DOMAIN ),
				'CR' => __('COSTA RICA', MS_TEXT_DOMAIN ),
				'CY' => __('CYPRUS', MS_TEXT_DOMAIN ),
				'CZ' => __('CZECH REPUBLIC', MS_TEXT_DOMAIN ),
				'DK' => __('DENMARK', MS_TEXT_DOMAIN ),
				'DJ' => __('DJIBOUTI', MS_TEXT_DOMAIN ),
				'DM' => __('DOMINICA', MS_TEXT_DOMAIN ),
				'DO' => __('DOMINICAN REPUBLIC', MS_TEXT_DOMAIN ),
				'EC' => __('ECUADOR', MS_TEXT_DOMAIN ),
				'EG' => __('EGYPT', MS_TEXT_DOMAIN ),
				'SV' => __('EL SALVADOR', MS_TEXT_DOMAIN ),
				'EE' => __('ESTONIA', MS_TEXT_DOMAIN ),
				'FK' => __('FALKLAND ISLANDS (MALVINAS)', MS_TEXT_DOMAIN ),
				'FO' => __('FAROE ISLANDS', MS_TEXT_DOMAIN ),
				'FJ' => __('FIJI', MS_TEXT_DOMAIN ),
				'FI' => __('FINLAND', MS_TEXT_DOMAIN ),
				'FR' => __('FRANCE', MS_TEXT_DOMAIN ),
				'GF' => __('FRENCH GUIANA', MS_TEXT_DOMAIN ),
				'PF' => __('FRENCH POLYNESIA', MS_TEXT_DOMAIN ),
				'TF' => __('FRENCH SOUTHERN TERRITORIES', MS_TEXT_DOMAIN ),
				'GA' => __('GABON', MS_TEXT_DOMAIN ),
				'GM' => __('GAMBIA', MS_TEXT_DOMAIN ),
				'GE' => __('GEORGIA', MS_TEXT_DOMAIN ),
				'DE' => __('GERMANY', MS_TEXT_DOMAIN ),
				'GH' => __('GHANA', MS_TEXT_DOMAIN ),
				'GI' => __('GIBRALTAR', MS_TEXT_DOMAIN ),
				'GR' => __('GREECE', MS_TEXT_DOMAIN ),
				'GL' => __('GREENLAND', MS_TEXT_DOMAIN ),
				'GD' => __('GRENADA', MS_TEXT_DOMAIN ),
				'GP' => __('GUADELOUPE', MS_TEXT_DOMAIN ),
				'GU' => __('GUAM', MS_TEXT_DOMAIN ),
				'GG' => __('GUERNSEY', MS_TEXT_DOMAIN ),
				'GY' => __('GUYANA', MS_TEXT_DOMAIN ),
				'HM' => __('HEARD ISLAND AND MCDONALD ISLANDS', MS_TEXT_DOMAIN ),
				'VA' => __('HOLY SEE (VATICAN CITY STATE)', MS_TEXT_DOMAIN ),
				'HN' => __('HONDURAS', MS_TEXT_DOMAIN ),
				'HK' => __('HONG KONG', MS_TEXT_DOMAIN ),
				'HU' => __('HUNGARY', MS_TEXT_DOMAIN ),
				'IS' => __('ICELAND', MS_TEXT_DOMAIN ),
				'IN' => __('INDIA', MS_TEXT_DOMAIN ),
				'ID' => __('INDONESIA', MS_TEXT_DOMAIN ),
				'IE' => __('IRELAND', MS_TEXT_DOMAIN ),
				'IM' => __('ISLE OF MAN', MS_TEXT_DOMAIN ),
				'IL' => __('ISRAEL', MS_TEXT_DOMAIN ),
				'IT' => __('ITALY', MS_TEXT_DOMAIN ),
				'JM' => __('JAMAICA', MS_TEXT_DOMAIN ),
				'JP' => __('JAPAN', MS_TEXT_DOMAIN ),
				'JE' => __('JERSEY', MS_TEXT_DOMAIN ),
				'JO' => __('JORDAN', MS_TEXT_DOMAIN ),
				'KZ' => __('KAZAKHSTAN', MS_TEXT_DOMAIN ),
				'KI' => __('KIRIBATI', MS_TEXT_DOMAIN ),
				'KR' => __('KOREA, REPUBLIC OF', MS_TEXT_DOMAIN ),
				'KW' => __('KUWAIT', MS_TEXT_DOMAIN ),
				'KG' => __('KYRGYZSTAN', MS_TEXT_DOMAIN ),
				'LV' => __('LATVIA', MS_TEXT_DOMAIN ),
				'LS' => __('LESOTHO', MS_TEXT_DOMAIN ),
				'LI' => __('LIECHTENSTEIN', MS_TEXT_DOMAIN ),
				'LT' => __('LITHUANIA', MS_TEXT_DOMAIN ),
				'LU' => __('LUXEMBOURG', MS_TEXT_DOMAIN ),
				'MO' => __('MACAO', MS_TEXT_DOMAIN ),
				'MK' => __('MACEDONIA', MS_TEXT_DOMAIN ),
				'MG' => __('MADAGASCAR', MS_TEXT_DOMAIN ),
				'MW' => __('MALAWI', MS_TEXT_DOMAIN ),
				'MY' => __('MALAYSIA', MS_TEXT_DOMAIN ),
				'MT' => __('MALTA', MS_TEXT_DOMAIN ),
				'MH' => __('MARSHALL ISLANDS', MS_TEXT_DOMAIN ),
				'MQ' => __('MARTINIQUE', MS_TEXT_DOMAIN ),
				'MR' => __('MAURITANIA', MS_TEXT_DOMAIN ),
				'MU' => __('MAURITIUS', MS_TEXT_DOMAIN ),
				'YT' => __('MAYOTTE', MS_TEXT_DOMAIN ),
				'MX' => __('MEXICO', MS_TEXT_DOMAIN ),
				'FM' => __('MICRONESIA, FEDERATED STATES OF', MS_TEXT_DOMAIN ),
				'MD' => __('MOLDOVA, REPUBLIC OF', MS_TEXT_DOMAIN ),
				'MC' => __('MONACO', MS_TEXT_DOMAIN ),
				'MN' => __('MONGOLIA', MS_TEXT_DOMAIN ),
				'ME' => __('MONTENEGRO', MS_TEXT_DOMAIN ),
				'MS' => __('MONTSERRAT', MS_TEXT_DOMAIN ),
				'MA' => __('MOROCCO', MS_TEXT_DOMAIN ),
				'MZ' => __('MOZAMBIQUE', MS_TEXT_DOMAIN ),
				'NA' => __('NAMIBIA', MS_TEXT_DOMAIN ),
				'NR' => __('NAURU', MS_TEXT_DOMAIN ),
				'NP' => __('NEPAL', MS_TEXT_DOMAIN ),
				'NL' => __('NETHERLANDS', MS_TEXT_DOMAIN ),
				'AN' => __('NETHERLANDS ANTILLES', MS_TEXT_DOMAIN ),
				'NC' => __('NEW CALEDONIA', MS_TEXT_DOMAIN ),
				'NZ' => __('NEW ZEALAND', MS_TEXT_DOMAIN ),
				'NI' => __('NICARAGUA', MS_TEXT_DOMAIN ),
				'NE' => __('NIGER', MS_TEXT_DOMAIN ),
				'NU' => __('NIUE', MS_TEXT_DOMAIN ),
				'NF' => __('NORFOLK ISLAND', MS_TEXT_DOMAIN ),
				'MP' => __('NORTHERN MARIANA ISLANDS', MS_TEXT_DOMAIN ),
				'NO' => __('NORWAY', MS_TEXT_DOMAIN ),
				'OM' => __('OMAN', MS_TEXT_DOMAIN ),
				'PW' => __('PALAU', MS_TEXT_DOMAIN ),
				'PS' => __('PALESTINE', MS_TEXT_DOMAIN ),
				'PA' => __('PANAMA', MS_TEXT_DOMAIN ),
				'PY' => __('PARAGUAY', MS_TEXT_DOMAIN ),
				'PE' => __('PERU', MS_TEXT_DOMAIN ),
				'PH' => __('PHILIPPINES', MS_TEXT_DOMAIN ),
				'PN' => __('PITCAIRN', MS_TEXT_DOMAIN ),
				'PL' => __('POLAND', MS_TEXT_DOMAIN ),
				'PT' => __('PORTUGAL', MS_TEXT_DOMAIN ),
				'PR' => __('PUERTO RICO', MS_TEXT_DOMAIN ),
				'QA' => __('QATAR', MS_TEXT_DOMAIN ),
				'RE' => __('REUNION', MS_TEXT_DOMAIN ),
				'RO' => __('ROMANIA', MS_TEXT_DOMAIN ),
				'RU' => __('RUSSIAN FEDERATION', MS_TEXT_DOMAIN ),
				'RW' => __('RWANDA', MS_TEXT_DOMAIN ),
				'SH' => __('SAINT HELENA', MS_TEXT_DOMAIN ),
				'KN' => __('SAINT KITTS AND NEVIS', MS_TEXT_DOMAIN ),
				'LC' => __('SAINT LUCIA', MS_TEXT_DOMAIN ),
				'PM' => __('SAINT PIERRE AND MIQUELON', MS_TEXT_DOMAIN ),
				'VC' => __('SAINT VINCENT AND THE GRENADINES', MS_TEXT_DOMAIN ),
				'WS' => __('SAMOA', MS_TEXT_DOMAIN ),
				'SM' => __('SAN MARINO', MS_TEXT_DOMAIN ),
				'ST' => __('SAO TOME AND PRINCIPE', MS_TEXT_DOMAIN ),
				'SA' => __('SAUDI ARABIA', MS_TEXT_DOMAIN ),
				'SN' => __('SENEGAL', MS_TEXT_DOMAIN ),
				'RS' => __('SERBIA', MS_TEXT_DOMAIN ),
				'SC' => __('SEYCHELLES', MS_TEXT_DOMAIN ),
				'SG' => __('SINGAPORE', MS_TEXT_DOMAIN ),
				'SK' => __('SLOVAKIA', MS_TEXT_DOMAIN ),
				'SI' => __('SLOVENIA', MS_TEXT_DOMAIN ),
				'SB' => __('SOLOMON ISLANDS', MS_TEXT_DOMAIN ),
				'ZA' => __('SOUTH AFRICA', MS_TEXT_DOMAIN ),
				'GS' => __('SOUTH GEORGIA AND THE SOUTH SANDWICH ISLANDS', MS_TEXT_DOMAIN ),
				'ES' => __('SPAIN', MS_TEXT_DOMAIN ),
				'SR' => __('SURINAME', MS_TEXT_DOMAIN ),
				'SJ' => __('SVALBARD AND JAN MAYEN', MS_TEXT_DOMAIN ),
				'SZ' => __('SWAZILAND', MS_TEXT_DOMAIN ),
				'SE' => __('SWEDEN', MS_TEXT_DOMAIN ),
				'CH' => __('SWITZERLAND', MS_TEXT_DOMAIN ),
				'TW' => __('TAIWAN, PROVINCE OF CHINA', MS_TEXT_DOMAIN ),
				'TZ' => __('TANZANIA, UNITED REPUBLIC OF', MS_TEXT_DOMAIN ),
				'TH' => __('THAILAND', MS_TEXT_DOMAIN ),
				'TL' => __('TIMOR-LESTE', MS_TEXT_DOMAIN ),
				'TG' => __('TOGO', MS_TEXT_DOMAIN ),
				'TK' => __('TOKELAU', MS_TEXT_DOMAIN ),
				'TO' => __('TONGA', MS_TEXT_DOMAIN ),
				'TT' => __('TRINIDAD AND TOBAGO', MS_TEXT_DOMAIN ),
				'TN' => __('TUNISIA', MS_TEXT_DOMAIN ),
				'TR' => __('TURKEY', MS_TEXT_DOMAIN ),
				'TM' => __('TURKMENISTAN', MS_TEXT_DOMAIN ),
				'TC' => __('TURKS AND CAICOS ISLANDS', MS_TEXT_DOMAIN ),
				'TV' => __('TUVALU', MS_TEXT_DOMAIN ),
				'UG' => __('UGANDA', MS_TEXT_DOMAIN ),
				'UA' => __('UKRAINE', MS_TEXT_DOMAIN ),
				'AE' => __('UNITED ARAB EMIRATES', MS_TEXT_DOMAIN ),
				'GB' => __('UNITED KINGDOM', MS_TEXT_DOMAIN ),
				'US' => __('UNITED STATES', MS_TEXT_DOMAIN ),
				'UM' => __('UNITED STATES MINOR OUTLYING ISLANDS', MS_TEXT_DOMAIN ),
				'UY' => __('URUGUAY', MS_TEXT_DOMAIN ),
				'UZ' => __('UZBEKISTAN', MS_TEXT_DOMAIN ),
				'VU' => __('VANUATU', MS_TEXT_DOMAIN ),
				'VE' => __('VENEZUELA', MS_TEXT_DOMAIN ),
				'VN' => __('VIET NAM', MS_TEXT_DOMAIN ),
				'VG' => __('VIRGIN ISLANDS, BRITISH', MS_TEXT_DOMAIN ),
				'VI' => __('VIRGIN ISLANDS, U.S.', MS_TEXT_DOMAIN ),
				'WF' => __('WALLIS AND FUTUNA', MS_TEXT_DOMAIN ),
				'EH' => __('WESTERN SAHARA', MS_TEXT_DOMAIN ),
				'ZM' => __('ZAMBIA', MS_TEXT_DOMAIN ),
		)
		);
	}
}