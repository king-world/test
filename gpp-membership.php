<?php
/**
 * Plugin Name: GPP Membership
 * Version: 0.1
 * Description: Membership management for the GPP site
 * Author: Fat Beehive
 * Author URI: http://fatbeehive.com
 * Plugin URI: http://fatbeehive.com
 * Text Domain: gpp-membership
 * Domain Path: /languages
 *
 * @package Gpp_Membership
 */


class GPP_Membership {

	/**
	 * Constructor
	 */
	function __construct() {
		// activation hook
		register_activation_hook( __FILE__, array( $this, 'activate' ) );

		// scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'includeScripts' ), 100 );

		// styles
		$this->includeStyles();

		// product
		add_action( 'woocommerce_product_get_price', array( $this, 'getPrice' ), 10, 2 );
		add_action( 'woocommerce_add_cart_item_data', array( $this, 'filterCartItem' ), 10, 2 );

		// cart
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'calcTotals' ), 99 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cartMeta' ), 10, 2 );
		add_filter( 'woocommerce_checkout_registration_required', array( $this, 'isRegistrationRequired' ), 10 );

		// checkout
		add_filter( 'woocommerce_checkout_fields', array( $this, 'addCheckoutFields' ) );
		add_filter( 'woocommerce_form_field_upload', array( $this, 'uploadField' ), 10, 3 );
		add_action( 'woocommerce_before_checkout_process', array( $this, 'maybeUploadFiles' ), 10 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'updateOrderMeta' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'updateAfterPayment' ) );
		add_filter( 'woocommerce_form_field_args', array( $this, 'filterFormFieldArgs' ) );
		add_action( 'woocommerce_check_cart_items', array( $this, 'ensureMembershipAllowed' ), 2 );
		//add_action( 'woocommerce_pre_payment_complete', array( $this, 'setShortTermSettings' ) );

		// order admin page
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'addGiftAidStatus' ), 10, 1 );

		// user admin page
		add_filter( 'users_list_table_query_args', array( $this, 'filterUserListArgs' ) );
		add_filter( 'map_meta_cap', array( $this, 'maybeRestrictEditUser' ), 10, 4 );
		add_filter( 'views_users', array( $this, 'filterUserListViews' ) );
		add_filter( 'manage_users_columns', array( $this, 'filterUsersColumns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'getMembershipColumn' ), 10, 3 );

		// ACF overrides
		add_filter( 'acf/load_field/name=membership_type', array( $this, 'membershipTypeDropdown' ), 10 );
		add_filter( 'acf/load_value/name=join_renewal_date', array( $this, 'renewalDateField' ), 10, 2 );
		add_filter( 'acf/load_value/name=payment_status_main', array( $this, 'paymentStatusField' ), 10, 2 );
		add_filter( 'acf/load_value/name=membership_status', array( $this, 'paymentStatusField' ), 10, 2 );

		// payment methods
		add_filter( 'woocommerce_payment_gateways', array( $this, 'addOfflinePaymentGateway' ), 10 );

		// expiry emails
		add_action( 'gpp_send_expiry_emails', array( $this, 'sendExpiryEmails' ) );

		// my account
		add_filter( 'woocommerce_account_menu_items', array( $this, 'tidyMyAccountNav' ) );
		add_action( 'woocommerce_edit_account_form', array( $this, 'myAccountForm' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'saveMyAccount' ) );
		add_action( 'woocommerce_account_content', array( $this, 'printExpiryMessageHTML' ), 10 );
		add_action( 'woocommerce_before_cart', array( $this, 'printExpiryMessageHTML' ), 10 );
		add_action( 'woocommerce_login_form', array( $this, 'loginFormRedirect' ) );
	}

	static $instance = null;

	static $logger = null;

	/**
	 * On plugin activation
	 */
	function activate() {
		wp_schedule_event( time(), 'daily', 'gpp_send_expiry_emails' );
	}

	/**
	 * Load JS for various pages
	 */
	function includeScripts() {
		if ( is_checkout() ) {
			wp_enqueue_script( 'gpp_checkout_uploads', plugin_dir_url( __FILE__ ) . '/js/checkout-uploads.js', array(), 1, true );
			wp_enqueue_script( 'gpp_checkout_filer', plugin_dir_url( __FILE__ ) . '/js/jQuery-filer/js/jquery.filer.min.js', array( 'gpp_checkout_uploads' ), 1, true );
		}
	}

	/**
	 * Load the CSS for various pages
	 */
	function includeStyles() {
		wp_enqueue_style( 'gpp_checkout_filer_css', plugin_dir_url( __FILE__ ) . '/js/jQuery-filer/css/jquery.filer.css' );
	}

	/**
	 * Get the membership types from the options page
	 */
	function getMembershipTypes( $include_links = true ) {
		$types = (array) get_field( 'membership_types', 'option' );

		// add our 'unregistered' membership type
		$types['unregistered'] = array(
			'title'                            => 'Unregistered',
			'description'                      => 'Non-members',
			'price'                            => 0,
			'requires_supplementary_documents' => false,
		);

		// maybe add our short term membership type
		if ( get_field( 'short_term_enabled', 'option' ) ) {
			$short_term = array(
				'title'                            => get_field( 'short_term_title', 'option' ),
				'image'                            => get_field( 'short_term_image', 'option' ),
				'description'                      => get_field( 'short_term_description', 'option' ),
				'price'                            => get_field( 'short_term_price', 'option' ),
				'requires_supplementary_documents' => false,
				'short_term'                       => true,
				'short_term_duration'              => get_field( 'short_term_duration', 'option' ),
				'short_term_unavailable'           => ! $this->userCanRegisterShortTerm(),
                'is_short_term'                    => true,
			);

			$types['short_term'] = $short_term;
		}

		if ( ! count( $types ) ) {
			return array();
		}

		foreach ( $types as $index => &$type ) {
			if ( $include_links && ! $type['short_term_unavailable'] ) {
				$type['purchase_link'] = $this->getMembershipPurchaseLink( $index );
			}

			if ( is_numeric( $index ) ) {
				$types[ $type['_unique_key'] ] = $type;
				unset( $types[$index] ); // unset the numbered keys
			}
		}

		// moving short term at the end of the array
        $short_term = $types['short_term'];
		unset( $types['short_term'] );
		$types['short_term'] = $short_term;

		return $types;
	}

	/**
	 * Get a single membership type from its index
	 */
	function getMembershipType( $index ) {
		$types = $this->getMembershipTypes();

		if ( array_key_exists( $index, $types ) ) {
			return $types[ $index ];
		}

		return false;
	}

	function getCurrentUserMembershipExpDate() {

		$user_id = get_current_user_id();

		if ( $user_id ) {
			$membership_exp_date = get_field('membership_expiry_date','user_' . $user_id);
			return ( $membership_exp_date ? $membership_exp_date : false );
		} else {
			return false;
		}

	}

	function user_can_buy_or_renew_membership() {
		// For current user
		$membership_status = $this->getUserMembershipStatus( 0, true );

		$user_paid = ($membership_status == 'paid');
		$is_within_a_month_of_expiry = $this->is_within_a_month_of_expiry(  $this->getCurrentUserMembershipExpDate() );
	  $allow_renewal = in_array( $membership_status, array( 'lapsed', 'in_grace_period', 'expires_in_one_month' ) );

		// allow_renewal is true if  membership status is 'lapsed', 'in_grace_period', 'expires_in_one_month'
		if ( $is_within_a_month_of_expiry	|| $allow_renewal  ) {
				$allowed_to_buy = true;
		} elseif ( $user_paid ) { 	// has the user paid up? (If so, we won't let them buy another membership)
			  $allowed_to_buy = false;
		} elseif ( !$user_paid  ) { // User hasn't paid, e.g allow non-logged in users to by a membership
				$allowed_to_buy = true;
		} else {
			 $allowed_to_buy = false;
		}

		return $allowed_to_buy;
	}

  function is_within_a_month_of_expiry( $membership_expiry_date ) {

		$membership_exp_date_ts = strtotime($membership_expiry_date);
		$min_date_ts = ( $membership_exp_date_ts - MONTH_IN_SECONDS ) + DAY_IN_SECONDS;
		$max_date_ts = $membership_exp_date_ts + DAY_IN_SECONDS;

		if ( $min_date_ts < strtotime('now')  &&  strtotime('now') < $max_date_ts)  {
			return true;
		} else {
			return false;
		}

	}

	function getMembershipIdFromName( $name ) {
		$types = $this->getMembershipTypes();

		foreach ( $types as $i => $type ) {
			if ( trim( strtolower( $type['title'] ) ) == trim( strtolower( $name ) ) ){
				return $type['_unique_key'];
			}
		}

		return false;
	}

	/**
	 * Get the link to purchase a membership, given its index
	 */
	function getMembershipPurchaseLink( $index = 0 ) {
		$membership = $this->getMembershipTypes( false );

		if ( ! array_key_exists( $index, $membership ) ) {
			return '';
		}

		$product_link = get_the_permalink( $this->getMembershipProductId() );

		return add_query_arg( 'membership_type', $index, $product_link );
	}

	/**
	 * Return true if the current user is allowed to register for
	 * a short term membership
	 */
	function userCanRegisterShortTerm( $user_id = 0 ) {
		if ( ! get_field( 'short_term_enabled', 'option' ) ) {
			return false; // bail if short term memberships not enabled
		}

		if ( ! get_field( 'short_term_limit_per_email', 'option' ) ) {
			return true; // allow if multiple short term memberships are enabled
		}

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return true; // return true if we're not logged in
		}

		if ( get_field( 'multiple_short_term_allowed', 'user_' . $user_id ) ) {
			return true; // allow if multiple short term memberships explicitly allowed on this user
		}

		$year = date( 'Y' );

		return ! get_user_meta( $user_id, 'membership_short_term_in_' . $year, true );
	}

	/**
	 * Get the product which is being used for memberships
	 */
	function getMembershipProductId() {
		return get_field( 'membership_product', 'option' );
	}

	/**
	 * Filter the product price if it's the membership product
	 */
	function getPrice( $price, $product ) {
		$membership_type = isset($_GET['membership_type'])?$_GET['membership_type']:null;

		if ( ! isset( $membership_type ) ) {
			return $price;
		}

		if ( $product->get_id() != $this->getMembershipProductId() ) {
			// bail if this isn't the membership product
			return $price;
		}

		$new_price = $this->getMembershipPrice( $membership_type );

		if ( is_numeric( $new_price ) ) {
			$price = $new_price;
		}

		return (float) $price;
	}

	/**
	 * Get the price of a membership
	 */
	function getMembershipPrice( $membership_type ) {
		$membership = $this->getMembershipType( $membership_type );

		if ( ! $membership ) {
			return false;
		}

		return $membership['price'];
	}

	/**
	 * Filter the cart item so we set the correct price
	 */
	function filterCartItem( $data, $product_id ) {
		$membership_type = $_GET['membership_type'];

		if ( $product_id != $this->getMembershipProductId() ) {
			return;
		}

		$data['custom_price'] = $this->getMembershipPrice( $membership_type );
		$data['membership_type'] = $membership_type;
		$data['is_concession'] = $this->isMembershipConcession( $membership_type );
		$data['is_short_term'] = $this->isMembershipShortTerm( $membership_type );

		return $data;
	}

	/**
	 * Determine if a membership type is a concession
	 */
	function isMembershipConcession( $membership_type ) {
		$membership = $this->getMembershipType( $membership_type );

		return ! ! $membership['requires_supplementary_documents'];
	}

	/**
	 * Determine if a membership type is short term
	 */
	function isMembershipShortTerm( $membership_type ) {
		$membership = $this->getMembershipType( $membership_type );

		return ! ! $membership['short_term'];
	}

	/**
	 * Adjust the totals to include custom prices
	 */
	function calcTotals( $cart ) {
		$contents = $cart->cart_contents;

		foreach ( $contents as $item ) {
			$custom_price = $item['custom_price'];

			if ( $custom_price ) {
				$item['data']->set_price( $custom_price );
			}
		}
	}

	/**
	 * Include the meta data for the cart item on the cart page
	 */
	function cartMeta( $item_data, $cart_item ) {

		if ( isset( $cart_item['membership_type'] ) ) {
			$membership = $this->getMembershipType( $cart_item['membership_type'] );
			$item_data['membership_type'] = array(
				'key'     => 'Type',
				'display' => $membership['title'],
			);

			// also set the membership type in session so we can access it later
			// (is there a better way to do this?)
			WC()->session->set( 'membership_type', $cart_item['membership_type'] );
		}

		return $item_data;
	}

	/**
	 * Add fields to the checkout for membership
	 */
	function addCheckoutFields( $fields ) {
		$order_fields = &$fields['order'];

		// remove the order comments field
		// unset( $order_fields['order_comments'] );
		$billing_fields  = &$fields['billing'];

		$title_field = array(
			'label'   => 'Title',
			'type'    => 'select',
			'options' => array(
				'mr'   => 'Mr',
				'mrs'  => 'Mrs',
				'ms'   => 'Ms',
				'miss' => 'Miss',
			),
		);

		// add the title field
		$billing_fields = array( 'title' => $title_field ) + $billing_fields;

		$membership_in_cart = $this->membershipInCart();
		$short_term_membership_in_cart = $this->membershipInCart(true);

		// only show this fields if we are buying a membership (NOT short term)
		if ( $membership_in_cart && ! $short_term_membership_in_cart ) {

			$billing_fields['postal_only'] = array(
				'label'       => 'Postal correspondence only',
				'type'        => 'checkbox',
				'description' => '',
			);

		}

		// only show this fields if we are buying a membership
		if ( $membership_in_cart ) {

			$billing_fields['how_did_you_hear'] = array(
				'label'       => 'How did you hear about The Guild?',
				'type'        => 'text',
				'description' => '',
			);

			$billing_fields['previously_member'] = array(
				'label'       => 'Have you previously been a member?',
				'type'        => 'checkbox',
				'description' => '',
			);

			$billing_fields['gift_aid'] = array(
				'label'       => 'Is this a Gift Aid donation?',
				'type'        => 'checkbox',
				'description' => '',
			);

		}

		// only show the fields below if we have a concession item in the cart
		if ( ! $this->concessionInCart() ) {
			return $fields;
		}

		// add the concession fields
		$order_fields['concession_details'] = array(
			'type'        => 'textarea',
			'label'       => 'Concession eligibility details',
			'required'    => true,
			'description' => 'Please provide evidence that shows you are eligible for your concession',
		);

		$order_fields['concession_upload'] = array(
			'type'              => 'upload',
			'label'             => 'Concession supporting documents',
			'description'       => 'Please upload any supporting documents that show you are eligible for your concession',
			'max_file_size'     => '1mb',
			'allowed_filetypes' => 'pdf',
			'multiple'          => false,
		);

		return $fields;
	}

	/**
	 * Check if a concession item is in the cart
	 */
	function concessionInCart() {
		$items = WC()->cart->get_cart();

		foreach ( $items as $item ) {
			if ( $item['is_concession'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a membership item is in the cart
	 */
	function membershipInCart( $short_term = false ) {
		// Bail. This was causing a fatal error. The filter woocommerce_checkout_registration_required
		// It's triggered by WC 4.9.2
		if ( ! isset( WC()->cart ) ) {
			return false;
		}

		$items = WC()->cart->get_cart();

		$membership_product_id = $this->getMembershipProductId();

		foreach ( $items as $item ) {
			if ( $item['product_id'] == $membership_product_id ) {
				if ( ! $short_term ) {
					return true;
				}

				if ( $item['is_short_term'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Custom upload field for WC checkout
	 */
	function uploadField( $field, $key, $args ) {
		$field_container = '<p class="form-row %1$s" id="%2$s">%3$s</p>';

		$field_html = '';

		$field .= '<input type="file" class="input-upload ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '"/>';

		if ( $args['label'] ) {
			$field_html .= '<label for="' . esc_attr( $label_id ) . '" class="' . esc_attr( implode( ' ', $args['label_class'] ) ) . '">' . $args['label'] . $required . '</label>';
		}

		$field .= '<label class="upload-feedback" id="upload-feedback"></label>';

		$field_html .= $field;

		if ( $args['description'] ) {
			$field_html .= '<span class="description">' . esc_html( $args['description'] ) . '</span>';
		}

		$container_class = esc_attr( implode( ' ', $args['class'] ) );
		$container_id = esc_attr( $args['id'] ) . '_field';

		$after = ! empty( $args['clear'] ) ? '<div class="clear"></div>' : '';

		$field = sprintf( $field_container, $container_class, $container_id, $field_html ) . $after;

		return $field;
	}



	/**
	 * Given user ID, return the user's membership status on the site
	 * If no ID is given, assume the current user.
	 * Returns: grace_period, lapsed, unpaid, paid, unregistered
	 * 			expiring_soon might also be returned if the argument
	 *			is set and the account is expiring in the next month
	 */
	function getUserMembershipStatus( $user_id = 0, $include_expiring_soon_status = false ) {

		if ( $user_id == 0 ) {
			$user_id = get_current_user_id();

			if ( $user_id == 0 ) {
				return 'unregistered';
			}
		}

		if ( ! get_user_by( 'id', $user_id ) ) {
			return 'unregistered'; // if the user ID doesn't exist, it's unregistered
		}

		if ( user_can( $user_id, 'gpp_admin_membership_access' ) || user_can( $user_id, 'shop_manager' ) ) {
			return 'paid'; // admins/editors/authors/shop managers are exempt from renewals
		}

		$user = 'user_' . $user_id;

		$is_lifetime_member = get_field( 'lifetime_member', $user );

    $expiry_date_string = get_field( 'membership_expiry_date', $user ); // e.g. 20180913

		// don't allow short term members to have grace periods
		$allowed_grace_period = ! $this->isUserShortTerm( $user_id );

    if ( ! $expiry_date_string && ! $is_lifetime_member ) {
			// not be a member at all
			return 'unregistered';
		}

    // lifetime members don't pay and should therefore NOT receive a notice of any kind
    if ( $is_lifetime_member ) {
    	$this->clearExpiryNotificationsFlag( $user_id, true );
      return 'paid';
    }

      $expiry_date = strtotime($expiry_date_string); // $expiry_date_obj->format( 'U' );
      $todays_date = time();
      $seconds_over_expiry = $todays_date - $expiry_date;

				if ( $include_expiring_soon_status ) {

            if ( ( $expiry_date <= (strtotime('now') + MONTH_IN_SECONDS) ) && ( $expiry_date >= (strtotime('now') + (MONTH_IN_SECONDS - DAY_IN_SECONDS) ) )) {

                // if the expiry date is less than one month from now but greater than one month MINUS one day
                return 'expires_in_one_month';
            }
        }

        if ( $seconds_over_expiry >= DAY_IN_SECONDS && $seconds_over_expiry <= MONTH_IN_SECONDS && $allowed_grace_period ) {

            // if the membership has expired by more than one day, but less than one month, and a grace period is allowed
            return 'in_grace_period';

        } else if ( $seconds_over_expiry > MONTH_IN_SECONDS || ( ! $allowed_grace_period && $seconds_over_expiry > 0 ) ) {

            // if the membership has expired by more than one month, or a grace period is not allowed and the membership has expired by more than zero seconds
            return 'lapsed';
        }

		$period_began = strtotime( 'today - 12 months' );

		// Check we have received a payment from this user in at least the last 12 months
		$payments_received = $this->getPaymentsSince( $user_id, $period_began, true );

		if ( is_array( $payments_received ) ) {

			foreach ( $payments_received as $payment ) {
				if ( $payment['payment_status'] == 'paid' ) {
					$this->clearExpiryNotificationsFlag( $user_id );
					return 'paid';
				}
			}

		}

		// we must not be a paid up member
		return 'unpaid';

	} // END getUserMembershipStatus

    /**
     * Given a user_id, delete the sent membership notification email flag, allowing
     * expiry emails to be sent again the next time the account lapses
     */
    function clearExpiryNotificationsFlag( $user_id, $clear_all = false ) {
        $keys = array(
            'in_grace_period',
            'lapsed',
        );

				if ( $clear_all ) {
					$keys[] = 'expires_in_one_month';
				}

        foreach ( $keys as $key ) {
            delete_metadata( 'user', $user_id, '_sent_email_' . $key );
        }
    }

    /**
     * Given a membership object, return the date it will expire for a new member
     */
    function getMembershipExpiryDate( $membership, $existing_expiry = false ) {
        if ( ! $membership ) {
            return false;
        }

        $now = time();

        // first possibility -- we have no existing expiry date.
        // this is a new membership. Use today's date as our baseline
        if ( ! $existing_expiry ) {
        	$renewal_time = $now;
        }

        // second possibility -- we have an existing expiry date
        // this is an existing membership
        if ( $existing_expiry ) {

        	// convert our Ymd expiry date into a time
        	$existing_expiry_time  = strtotime( $existing_expiry );
        	$grace_period_end_time = strtotime( $existing_expiry . ' + 1 month' );

        	if ( $now < $grace_period_end_time ) {
	        	// the grace period has not passed yet, use the existing expiry
	        	// date as our baseline

        		$renewal_time = $existing_expiry_time;
        	} else {
        		// the grace period has passed, use today's date as our baseline
        		$renewal_time = $now;
        	}
        }

        if ( ! $membership['short_term'] ) {
        	// if we are renewing a NORMAL membership, add one year to the
        	// expiry date we've established above

            $new_expiry = strtotime( '+1 year', $renewal_time );
        } else {
        	// else, if we are renewing a SHORT TERM membership, add the
        	// given number of days indiciated in the membership

            $days = $membership['short_term_duration'];
            $new_expiry = strtotime( '+' . $days . ' days', $renewal_time );
        }

        return $new_expiry;
    }

	/**
	 * Get the privilege of a member.
	 * Returns true if the member can access members-only resources, else false
	 */
	function getUserMembershipPrivilege( $user_id = 0 ) {
		$status = $this->getUserMembershipStatus( $user_id );

		return $status == 'paid' || $status == 'in_grace_period';
	}

	/**
	 * Return true if a given user is registered as a short term member
	 */
	function isUserShortTerm( $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		return 'short_term' == get_user_meta( $user_id, 'membership_type', true );
	}

	/**
	 * Redirect the current user to the login screen
	 */
	function redirectToLogin( $then = '' ) {
		$url = add_query_arg( 'not_activated', 1, '/member-login' );

		if ( $then ) {
			$url = add_query_arg( 'redirect', urlencode( $then ), $url );
		}

		wp_redirect( $url );
	}

	/**
	 * Redirect the current user to the membership/renewal page
	 */
	function redirectToMembershipPage() {
		wp_redirect( get_the_permalink( 30 ) );
	}

	/**
	 * Get the payments since a given date for a user
	 */
	function getPaymentsSince( $user_id, $when = 0, $only_paid = false ) {
		$renewals = get_field( 'renewals', 'user_' . $user_id );

		if ( is_array( $renewals ) && ! count( $renewals ) ) {
			return $renewals;
		}

		$cleaned_renewals = array();

		if ( ! is_array( $renewals ) ) {
			return $cleaned_renewals;
		}

		// Go through all the renewals and return the relevant ones
		foreach ( $renewals as $renewal ) {
			if ( ! $renewal['payment_date'] || ! $renewal['payment_amount'] ) {
				continue;
			}

			$renewal_date = Datetime::createFromFormat( 'Ymd', $renewal['payment_date'] )->format( 'U' );

			if ( isset($renewal['type']) && $renewal['type'] == 'event_ticket' ) {
				continue; // ignore event tickets
			}

			if ( $renewal_date < $when ) {
				continue;
			}

			if ( $only_paid && $renewal['payment_status'] != 'paid' ) {
				continue;
			}

			$cleaned_renewals[] = $renewal;
		}

		return $cleaned_renewals;
	}

	/**
	 * Insert a payment into the renewals list of a given user
	 */
	function insertPayment(
			$user,
			$amount,
			$date = false,
			$type = 'joining',
			$method = 'offline',
			$status = 'paid',
			$associated_event = false
		) {
				global $wpdb;

				$renewals = get_field( 'renewals', $user );

				$new_renewal = array(
					'payment_date'     => $date ? $date : date( 'Ymd' ),
					'payment_type'     => $type,
					'payment_method'   => $method,
					'payment_status'   => $status,
					'payment_amount'   => $amount,
					'associated_event' => $associated_event,
				);

				// Log for debugging purposes
				$data['unixtime'] = time();
				$data['str_datetime'] = date(DATE_RSS);
				$data['debug_text'] = 'Inserting payment/renewal for ' . $user . ': ' . implode(', ', $new_renewal ) ;
				$data['debug_type'] = 'TXT : Inside insertPayment function';
        $wpdb->insert( 'wp_gpp_debugging_log', $data );

				$renewals[] = $new_renewal;

				return update_field( 'renewals', $renewals, $user );
	}

	/**
	 * Add our offline payment gateway (based on COD)
	 */
	function addOfflinePaymentGateway( $gateways = array() ) {
		// load in the gateway now since the dependent class will be available
		require_once( 'includes/class-wc-gateway-offline.php' );

		$gateways[] = 'WC_Gateway_Offline';

		return $gateways;
	}

	/**
	 * Upload any files which are being sent in during joining/renewal
	 * (Concessions)
	 */
	function maybeUploadFiles() {
		if ( ! $_POST['gpp_upload'] ) {
			return;
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}

		$overrides = array(
			'test_form' => false,
		);

		$concession_upload = $_FILES['concession_upload'];

		// remove any arrays
		foreach ( $concession_upload as &$val ) {
			if ( is_array( $val ) ) {
				$val = current( $val );
			}
		}

		$move_file = wp_handle_upload( $concession_upload, $overrides );

		$file_url  = $move_file['url'];
		$file_path = $move_file['file'];

		if ( $file_path ) {
			// add the file to our list of user uploaded files and return the hash for the url
			$supporting_documents = get_option( 'gpp_user_uploads' );

			$hash = md5( $file_path );

			// Insert the file as an attachment...
			// Check the type of file. We'll use this as the 'post_mime_type'.
			$filetype = wp_check_filetype( basename( $file_path ), null );

			// Get the path to the upload directory.
			$wp_upload_dir = wp_upload_dir();

			// Prepare an array of post data for the attachment.
			$attachment = array(
				'guid'           => $wp_upload_dir['url'] . '/' . basename( $file_path ),
				'post_mime_type' => $filetype['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file_path ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			// Insert the attachment.
			$attach_id = wp_insert_attachment( $attachment, $file_path );

			if ( ! $attach_id ) {
				print json_encode( array(
					'error' => 'The file could not be uploaded.',
				) );
				die();
			}

			$supporting_documents[ $hash ] = $attach_id;

			// Make sure that this file is included, as wp_generate_attachment_metadata() depends on it.
			require_once( ABSPATH . 'wp-admin/includes/image.php' );

			// Generate the metadata for the attachment, and update the database record.
			$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
			wp_update_attachment_metadata( $attach_id, $attach_data );

			update_option( 'gpp_user_uploads', $supporting_documents );

			print json_encode( array(
				'document' => $hash,
				'success'     => true,
			) );
			die();
		}

		print json_encode( $move_file );
		die();
	}


	/**
	 * Set up the membership on the user after purchase
	 * Note: Expiry date/renewal date and renewals record will be set
	 *       once the order is paid for.
	 */
	function updateOrderMeta( $order_id, $post ) {
		$order = wc_get_order( $order_id );

		if ( ! $this->orderIncludesMembership( $order_id ) ) {
			return; // bail if we're not buying a membership
		}

		$user = 'user_' . $order->user_id;

		// set any concession details
		update_field( 'concession_details', $post['concession_details'], $user );

		// set the concession documents
		$concession_docs = $this->prepareConcessionDocs( $user, $_POST['attached_document'] );

		update_field( 'concession', $concession_docs );

		// set the user's title
		update_field( 'title', $post['title'] );

		// set the join date IF it's not been set already
		if ( ! get_field( 'date_first_joined', $user ) ) {
			update_field( 'date_first_joined', date( 'Ymd' ), $user );

			$new_user = true;
		}

		// set whether the member receives communication only by post
		update_field( 'postal_only', $post['postal_only'], $user );

		// set the 'how did you hear about the guild?' field
		update_field( 'how_did_you_hear', $post['how_did_you_hear'], $user );

		// set the previously a member field
		update_field( 'previously_member', $post['previously_member'], $user );

		// set how the order was paid for (offline or paypal)
		update_field( 'payment_method', $order->payment_method, $user );

		// set whether the order was paid for with gift aid (on the order)
		update_post_meta( $order_id, '_gpp_giftaid', $post['gift_aid'] );

		// get the old membership type and the new one
		$membership_type_old = get_field( 'membership_type', $user );
		$membership_type_new = WC()->session->get( 'membership_type' );

		// get the membership objects
		$membership_old = $this->getMembershipType( $membership_type_old );
		$membership_new = $this->getMembershipType( $membership_type_new );

		// if the membership type changes and we are not a new user...
		if ( $membership_type_old != $membership_type_new && ! $new_user ) {
			do_action( 'gpp_email_new_membership_type', $order_id, $order->user_id, $membership_old, $membership_new );
		}

		// update the membership type on the user
		update_field( 'membership_type', $membership_type_new, $user );

		// set short term member flags on the user
		if ( $membership_new['short_term'] ) {
			update_user_meta( $order->user_id, 'membership_short_term_duration', $membership_new['short_term_duration'], $user );
			// set a permanent flag to show a short term membership was created for this user this year
			$year = intval( date( 'Y' ) );
			update_user_meta( $order->user_id, 'membership_short_term_in_' . $year, true );
		} else {
			delete_user_meta( $order->user_id, 'membership_short_term_duration' );
		}

		// ... and on the order
		$order->update_meta_data( '_gpp_member_type', $membership_type_new );
		$order->save();

		// set the user ID on the member if they don't already have one
		$this->maybeSetMemberId( $user );

		// set the user role
		$wp_user = new WP_User( $order->user_id );

		$wp_user->set_role( 'gpp_member' );

		wp_update_user( $wp_user );

		$existing_expiry = get_field( 'membership_expiry_date', $user );

		// if this is an OFFLINE payment, set the expiry date now
		if ( $order->payment_method == 'offline' ) {
			$expiry_date = date( 'Ymd', $this->getMembershipExpiryDate( $membership_new, $existing_expiry ) );
			update_field( 'membership_expiry_date', $expiry_date, $user );
		}

		// trigger an additional email if the order came through with offline payment
		if ( $order->payment_method == 'offline' ) {
			do_action( 'gpp_email_offline_payment', $order_id, $order->user_id );

			// send out the welcome email now?
			// do_action( 'gpp_email_new_registration', $order_id, $order->user_id );
		}

		// FINALLY... if the order is free, make sure we update it immediately
		if ( $order->get_total() == 0 ) {
			$this->updateAfterPayment( $order_id );
		}
	}

	/**
	 * After an order is paid for, activate the membership
	 */
	function updateAfterPayment( $order_id ) {

		global $wpdb;

		$order = wc_get_order( $order_id );

		// Log for debugging purposes
		$data['unixtime'] = time();
		$data['str_datetime'] = date(DATE_RSS);
		$data['debug_text'] = 'Attempting to update order #' . $order_id . ' after payment...';
		$data['debug_type'] = 'TXT : Inside updateAfterPayment function';
		$wpdb->insert( 'wp_gpp_debugging_log', $data );


		$membership = $this->getMembershipFromOrder( $order_id );

		if ( $membership === false ) {
			// Log for debugging purposes
			$data['unixtime'] = time();
			$data['str_datetime'] = date(DATE_RSS);
			$data['debug_text'] = 'Abandoning updating order #' . $order_id . ' b/c it doesn\'t include a membership product.';
			$data['debug_type'] = 'TXT : Inside updateAfterPayment function, return null after this';
			$wpdb->insert( 'wp_gpp_debugging_log', $data );
			return; // bail if the order doesn't contain membership
		}

		if ( get_post_meta( $order->id, '_gpp_membership_activated', true ) ) {
			// Log for debugging purposes
			$data['unixtime'] = time();
			$data['str_datetime'] = date(DATE_RSS);
			$data['debug_text'] =  'Abandoning updating order #' . $order_id . ' b/c it is already activated';
			$data['debug_type'] = 'TXT : Inside updateAfterPayment function, return null after this';
			$wpdb->insert( 'wp_gpp_debugging_log', $data );
			return; // bail if the membership has already been activated
		}

		if ( 0 == $this->getMembershipRenewalCount( $order->user_id ) ) {
			do_action( 'gpp_email_new_registration', $order_id, $order->user_id );
		}

		$user_id = $order->get_user_id();
		$user = 'user_' . $user_id;

		// add a payment to the payments list
		$amount = number_format( $order->get_total(), 2, '.', '' );
		$renewal_count = $this->getMembershipRenewalCount( $user );

		$type = $renewal_count > 0 ? 'renewal' : 'joining';

		$this->insertPayment( $user, $amount, date( 'Ymd' ), $type, $order->payment_method );
		$this->clearExpiryNotificationsFlag( $user_id , true );

		if ( 'paypal' != $order->payment_method && $order->get_total() > 0 ) {
			// Log for debugging purposes
			$data['unixtime'] = time();
			$data['str_datetime'] = date(DATE_RSS);
			$data['debug_text'] =  'Ignoring order #' . $order_id . ' because it\'s not Paypal';
			$data['debug_type'] = 'TXT : Inside updateAfterPayment function, return null after this';
			$wpdb->insert( 'wp_gpp_debugging_log', $data );
			return; // bail if the order was paid for offline or not paypal (will be done manually)
		}

		// The order has been paid for! Activate the membership
		// Log for debugging purposes
		$data['unixtime'] = time();
		$data['str_datetime'] = date(DATE_RSS);
		$data['debug_text'] =  'Activating membership for user #' . $user_id . '...';
		$data['debug_type'] = 'TXT : Inside updateAfterPayment function';
		$wpdb->insert( 'wp_gpp_debugging_log', $data );

		$existing_expiry = get_field( 'membership_expiry_date', $user );

		// set the expiry date
		$expiry_date = date( 'Ymd', $this->getMembershipExpiryDate( $membership, $existing_expiry ) );

		// set the membership activated timestamp
		update_post_meta( $order->id, '_gpp_membership_activated', time() );

		update_field( 'membership_expiry_date', $expiry_date, $user );

		wc_empty_cart(); // empty the cart
	}

	/**
	 * Get the total number of renewals for a user
	 */
	function getMembershipRenewalCount( $user_id ) {
		$renewals = get_field( 'renewals', 'user_' . $user_id );

		if ( ! is_array( $renewals ) ) {
			return 0;
		}

		return count( $renewals );
	}

	/**
	 * Get the member ID for the user
	 * Note: this is separate from the WP user ID
	 */
	function maybeSetMemberId( $user ) {
		$current_member_id = (int) get_field( 'member_id', $user );
		$highest_member_id = $this->getHighestMemberId();

		if ( $current_member_id > 0 ) {
			return; // bail if the member already has a member id
		}

		$new_id = $highest_member_id + 1;

		update_field( 'member_id', $new_id, $user );

		return $new_id;
	}

	/**
	 * Get the highest member ID stored in the system
	 * Note: this is separate from the WP user ID
	 */
	function getHighestMemberId() {
		$args = array(
			'meta_key'     => 'member_id',
			'meta_compare' => 'EXISTS',
			'fields'       => 'ID',
			'orderby'      => 'meta_value_num',
			'order'        => 'desc',
			'number'       => 1,
		);

		$users = new WP_User_Query( $args );

		if ( ! count( $users->results ) || count( $users->results ) < 1 ) {
			return 0;
		}

		$highest_uid = current( $users->results );

		return (int) get_user_meta( $highest_uid, 'member_id', true );
	}

	/**
	 * Prepare an array of concession attachment IDs for the backend
	 */
	function prepareConcessionDocs( $user, $attachments ) {

		if ( ! is_array( $attachments ) ) {
			return;
		}

		$user_uploads = get_option( 'gpp_user_uploads' );

		$new_attachments = array();

		// loop through the attachments and find the associated files
		foreach ( $attachments as $attachment_key ) {
			if ( ! $user_uploads[ $attachment_key ] ) {
				continue;
			}

			$new_attachments[] = $user_uploads[ $attachment_key ];
		}

		$existing_attachments = get_field( 'concession_attachments', $user );

		if ( ! is_array( $existing_attachments ) ) {
			$existing_attachments = array();
		}

		foreach ( $new_attachments as $new ) {
			$existing_attachments[] = array(
				'file' => (int) $new,
			);
		}

		update_field( 'concession_attachments', $existing_attachments, $user );
	}

	/**
	 * Check if an order includes a membership
	 */
	function orderIncludesMembership( $order_id ) {
		$order = wc_get_order( $order_id );

		$items = $order->get_items();

		$membership_product_id = $this->getMembershipProductId();

		foreach ( $items as $item ) {

			if ( $item['product_id'] == $membership_product_id ) {
				return true;
			}
		}

		return false;
	}
	/**
	 * Get the membership product from an order (or false)
	 */
	function getMembershipFromOrder( $order_id ) {
		$order = wc_get_order( $order_id );

		$member_key = $order->get_meta( '_gpp_member_type' );

		if ( $member_key > -1 ) {
			return $this->getMembershipType( $member_key );
		}
	}

	/**
	 * Show the gift aid status of the order on the order page in admin
	 */
	function addGiftAidStatus( $order ) {
		$is_gift_aid = get_post_meta( $order->id, '_gpp_giftaid', true );
		?>
		<p class="form-field form-field-wide wc-order-gift-aid"><label for="gift_aid"><?php _e( 'Is Gift Aid:', 'woocommerce' ) ?></label>
		<strong><?php print $is_gift_aid ? 'Yes' : 'No'; ?></strong></p>
		<?php
	}

	/**
	 * Prepare the dropdown of membership types for user pages
	 * and event booking page
	 */
	function membershipTypeDropdown( $field ) {
		$types = $this->getMembershipTypes();

		foreach ( $types as $index => $type ) {
			$field['choices'][ $index ] = $type['title'];
		}

		return $field;
	}

	/**
     * Send emails to any members whose accounts have just expired
     */
    function sendExpiryEmails() {
        $args = array(
            'fields' => 'ID',
        );

        $query = new WP_User_Query( $args );

        foreach ( $query->results as $user_id ) {
            $membership_status = $this->getUserMembershipStatus( $user_id, true );

            if ( $this->isUserShortTerm( $user_id ) ) {
                continue; // skip sending emails for short term users
            }
			// These actions are implemented on plugins/gpp-emails/gpp-emails.php Line 32
            if ( $membership_status == 'expires_in_one_month' ) {
                do_action( 'gpp_email_member_expiring_soon', $user_id );
            } elseif ( $membership_status == 'in_grace_period' ) {
                do_action( 'gpp_email_member_in_grace_period', $user_id );
            } elseif ( $membership_status == 'lapsed' ) {
                do_action( 'gpp_email_member_expired', $user_id );
            }
        }
    }

	/**
	 * Get the value for the renewal date field on the user profile
	 */
	function renewalDateField( $value, $user ) {
		$user_id = substr( $user, 5 );

		$payments = $this->getPaymentsSince( $user_id );

		$latest = 0;

		if ( is_array($payments) && sizeof( $payments ) < 1 ) {
			return 0;
		}

		foreach ( $payments as $payment ) {
			if ( $payment['payment_date'] > $latest ) {
				$latest = $payment['payment_date'];
			}
		}

		return Datetime::createFromFormat( 'Ymd', $latest )->format( 'F j, Y' );
	}

	/**
	 * Get the value of the payment status field on the user profile
	 */
	function paymentStatusField( $value, $user ) {
		$user_id = substr( $user, 5 );

		return $this->getUserMembershipStatus( $user_id );
	}

	/**
	 * Tidy the My Account nav
	 */
	function tidyMyAccountNav( $nav ) {
		unset( $nav['downloads'] );

		return $nav;
	}

	/**
	 * Get the extra form elemetns for My Account
	 */
	function myAccountForm() {
		$user = 'user_' . get_current_user_id();
		?>
		<fieldset>
			<legend>Membership Details</legend>
			<p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
				<label for="postal_only">Postal Correspondence Only</label>
				<input type="checkbox" class="woocommerce-Input woocommerce-Input--checkbox" name="postal_only" id="postal_only" <?php checked( get_field( 'postal_only', $user ) ); ?>/>
			</p>
			<p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
				<label for="membership_type">Membership Type </label>
				<?php
				$membership_type = $this->getMembershipType( get_field( 'membership_type', $user ) );
				print ( $membership_type['title'] );
				?>
			</p>
			<p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
				<label for="membership_expiry_date">Membership Expiry Date</label>
				<?php
				$expiry_date = get_field( 'membership_expiry_date', $user );
				$date = date_parse_from_format( 'Ymd', $expiry_date );

				print( $date['day'] . '/' . $date['month'] . '/' . $date['year'] );
				?>
			</p>
			<p class="woocommerce-FormRow woocommerce-FormRow--wide form-row form-row-wide">
				<label for="username">Username</label>
				<?php
				$data = get_userdata( get_current_user_id() );
				print $data->user_login;
				?>
			</p>
		</fieldset>
		<div class="clear"></div>
		<?php
	}

	/**
	 * Save the My Account form
	 */
	function saveMyAccount( $user_id ) {
		update_field( 'postal_only', $_POST['postal_only'] == 'on' ? 1 : 0, 'user_' . $user_id );
	}

	/**
	 * Get the expiry message HTML
	 */
	function getExpiryMessageHTML() {
		$status = gpp_membership()->getUserMembershipStatus();

		if ( $status == 'in_grace_period' ) {
			$string = '<p class="membership-expiry grace-period">' . get_field( 'membership_grace_period_message', 'option' ) . '</p>';
		} else if ( $status == 'lapsed' ) {
			$string = '<p class="membership-expiry lapsed">' . get_field( 'membership_expired_message', 'option' ) . '</p>';
		} else if ( $status == 'unpaid' && $_GET['not_activated'] ) {
			$string = '<p class="membership-expiry unpaid">Your membership is not active yet. The page you tried to access will become available when your membership is activated. Please, contact us if you have any questions.</p>';
		}

		return $string;
	}

	/**
	 * Print the expiry message
	 */
	function printExpiryMessageHTML() {
		print $this->getExpiryMessageHTML();
	}

	/**
	 * Filter the list of users in users.php so only administrators
	 * and membership administrators can see all users
	 */
	function filterUserListArgs( $args ) {
		if ( current_user_can( 'gpp_view_all_users' ) ) {
			return $args;
		}

		$args['role__not_in'] = 'gpp_member';

		return $args;
	}

	/**
	 * Filter the views we have available
	 */
	function filterUserListViews( $views ) {
		if ( ! current_user_can( 'gpp_view_all_users' ) ) {
			unset( $views['gpp_member'] );
		}

		return $views;
	}

	/**
	 * Prevent users from deleting users they cannot view
	 */
	function maybeRestrictEditUser( $caps, $cap, $user_id, $args ) {
		$protected = array(
			'edit_user',
			'remove_user',
			'promote_user',
			'delete_user',
		);

		if ( ! in_array( $cap, $protected ) ) {
			return $caps;
		}

		$target_user_id = $args[0];

		if ( $target_user_id == $user_id && $cap == 'edit_user' ) {
			return $caps; // let people edit themselves
		}

		$target_user = new WP_User( $target_user_id );

		if ( user_can( $user_id, 'gpp_view_all_users' ) ) {
			return $caps; // bail if we can list all users
		}

		if ( $target_user->has_cap( 'gpp_member' ) ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/**
	 * Add the hidden field on the login page to redirect if necessary
	 */
	function loginFormRedirect() {
		// the ID of the post to redirect to
		$redirect = $_GET['redirect'];

		if ( ! $redirect ) {
			return;
		}

		$redirect = esc_url( urldecode( $redirect ) );

		?>
		<input type="hidden" name="redirect" value="<?= $redirect; ?>">
		<?php
	}


	function ensureMembershipAllowed() {
		if ( $this->membershipInCart( true ) ) {
			if ( ! $this->userCanRegisterShortTerm() ) {
				wc_add_notice( 'Sorry, you are not able to register for a short term membership at this point.', 'error' );
			}
		}
	}

	function filterUsersColumns( $cols ) {
		$cols['membership'] = 'Membership';

		$posts_index = array_search( 'posts', array_keys( $cols ) );

		// pop in the membership column before 'posts'
		return array_slice( $cols, 0, $posts_index, true )
			+ array( 'membership' => 'Membership' )
			+ array_slice( $cols, $posts_index, count( $cols ) );
	}

	function getMembershipColumn( $output, $column_name, $user_id ) {
		if ( $column_name != 'membership' ) {
			return $output;
		}

		$membership_id = get_field( 'membership_type', 'user_' . $user_id );

		$membership_type = $this->getMembershipType( $membership_id );
		$status = $this->getUserMembershipStatus( $user_id );
		$title = $membership_type['title'];

		if ( ! $title ) {
			return $output;
		}

		if ( $status == 'unregistered' ) {
			$status = '';
		}  else {
			$status = ' (' . $status . ')';
		}

		return $title . $status;
	}

	/**
	 * Filter the args of a WC form field
	 */
	function filterFormFieldArgs( $args ) {
		if ( $args['type'] != 'select' && $args['type'] != 'radio' && $args['type'] != 'country' ) {
			$args['input_class'][] = 'form_item_input';
		}

		return $args;
	}

	/**
	 * Get the instance of the Membership singleton
	 */
	static function instance() {
		if ( self::$instance ) {
			return self::$instance;
		}

		return self::$instance = new self();
	}

	/**
	 * Only require registration if there's a membership in the cart
	 */
	function isRegistrationRequired() {
		return $this->membershipInCart();
	}
}

function gpp_membership() {
	return GPP_Membership::instance();
}

$gpp_membership = GPP_Membership::instance();
