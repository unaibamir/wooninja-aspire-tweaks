<?php

class WooRCP_Corporate {

	protected $secret_key;
	protected $publishable_key;

	public $additional_member_price;
	public $default_member_number;
	public $woorcp_corp_form;

	public function __construct() {

		global $rcp_options;

		$this->test_mode               = rcp_is_sandbox();
		$this->additional_member_price = get_option( "woorcp_additional_member_fee", 49 );
		$this->default_member_number   = get_option( "woorcp_default_member_number", 9 );
		$this->woorcp_corp_form        = get_option( "woorcp_corp_form", 7 );

		$this->init();
		$this->hooks();
	}

	public function init() {

		global $rcp_options;

		if ( $this->test_mode ) {

			$this->secret_key      = isset( $rcp_options['stripe_test_secret'] ) ? trim( $rcp_options['stripe_test_secret'] ) : '';
			$this->publishable_key = isset( $rcp_options['stripe_test_publishable'] ) ? trim( $rcp_options['stripe_test_publishable'] ) : '';

		} else {

			$this->secret_key      = isset( $rcp_options['stripe_live_secret'] ) ? trim( $rcp_options['stripe_live_secret'] ) : '';
			$this->publishable_key = isset( $rcp_options['stripe_live_publishable'] ) ? trim( $rcp_options['stripe_live_publishable'] ) : '';

		}

		if ( ! class_exists( 'Stripe\Stripe' ) ) {
			require_once RCP_PLUGIN_DIR . 'includes/libraries/stripe/init.php';
		}

		\Stripe\Stripe::setApiKey( $this->secret_key );

		\Stripe\Stripe::setApiVersion( '2018-02-06' );

		if ( method_exists( '\Stripe\Stripe', 'setAppInfo' ) ) {
			\Stripe\Stripe::setAppInfo( 'Restrict Content Pro', RCP_PLUGIN_VERSION, esc_url( site_url() ) );
		}

	}

	public function hooks() {

		// Corporate registration dropdown fields
		add_filter( 'gform_pre_render', [ $this, 'gf_populate_subscriptions_dropdown' ] );
		add_filter( 'gform_pre_validation', [ $this, 'gf_populate_subscriptions_dropdown' ] );
		add_filter( 'gform_pre_submission_filter', [ $this, 'gf_populate_subscriptions_dropdown' ] );
		add_filter( 'gform_admin_pre_render', [ $this, 'gf_populate_subscriptions_dropdown' ] );

		add_action( 'gform_user_registered', [ $this, 'corporate_user_registered' ], 10, 4 );

		add_action( "init", array( $this, "RedefineStripePlans" ) );

		add_action( "rcp_login_redirect_url", [ $this, "corporate_login_redirect"], 10, 2);

		add_action( "woorc_plugin_activated", [ $this, "corporate_checkout_page" ]);

		add_shortcode( "corporate-checkout", [ $this, "corporate_checkout_callback" ] );

		add_shortcode( "corporate_manage_groups", [ $this, "corporate_manage_groups_callback" ] );

		add_action( "init", [ $this, "corporate_checkout_post" ] );

		add_action( "woorcp_group_leader_created", [ $this, "update_user_fields" ], 10, 5 );

		add_action( "woorcp_group_leader_subscription_created", [ $this, "group_leader_subscription_created" ], 10, 6 );

		add_action( "init", [ $this, "remove_corporate_group" ] );
	}

	public function gf_populate_subscriptions_dropdown( $form ) {
		$allowed_subscriptions = get_option("woorcp_allowed_subscriptions");
		if( empty( $allowed_subscriptions ) ) {
			return $form;
		}
		foreach ( $form['fields'] as &$field ) {
			if ( $field->type != 'select' || strpos( $field->cssClass, 'populate-subscriptions' ) === false ) {
				continue;
			}
			$choices = array();
			foreach ( $allowed_subscriptions as $level_id ) {
				$level = rcp_get_subscription_details($level_id);
				$price = rcp_get_currency_symbol().$level->price;
				$choices[] = array( 'text' => $level->name ." - ". $price, 'value' => $level_id );
			}
			$field->placeholder = 'Select a Subscription Plan';
			$field->choices = $choices;
		}
		return $form;
	}

	public function corporate_user_registered( $user_id, $feed, $entry, $user_pass) {

		$user_data = get_user_by( "ID", $user_id );

		if( !in_array("rcp_corporate", $user_data->roles) ) {
			return;
		}

		global $rcp_options, $rcp_payments_db, $rcp_levels_db;

		$additional_member_price = $this->additional_member_price;
		$woorcp_default_member_number = $this->default_member_number;

		$user_data = get_user_by("ID", $user_id);
		$stripe_token = $_POST["stripeToken"];

		$customer_args = array(
			"email" =>  $user_data->user_email,
			"source"    =>  $stripe_token,
			"metadata"  =>  array(
				"user_id"   =>  $user_id,
				"name"      =>  $user_data->display_name,
				"user_role" =>  "Corporate User"
			)
		);

		$customer = \Stripe\Customer::create( $customer_args );
		$gl_users_data = array();

		if( !empty( $gl_entries = explode(",", rgar($entry, "2") )) ) {
			foreach ($gl_entries as $key => $gl_entry_id) {
				$gl_entry = GFAPI::get_entry( $gl_entry_id );
				$gl_users_data[$key]["fname"] = $gl_entry["1.3"];
				$gl_users_data[$key]["lname"] = $gl_entry["1.6"];
				$gl_users_data[$key]["email"] = $gl_entry["2"];
				$gl_users_data[$key]["sub_id"] = $gl_entry["4"];
				$gl_users_data[$key]["user_count"] = $gl_entry["3"];
				$gl_users_data[$key]["stripe_token"] = $stripe_token;
			}
		}

		update_user_meta( $user_id, 'rcp_payment_profile_id', $customer->id );
		update_user_meta( $user_id, "pending_users", $gl_users_data, "");
		update_user_meta( $user_id, "rcp_status", "active", "");

		rcp_login_user_in( $user_data->ID, $user_data->user_login );

		if( $redirect = get_permalink( get_option("woorcp_corp_profile_page") ) ) {
			wp_safe_redirect( $redirect, $status = 302 );
			exit;
		}

	}

	public function RedefineStripePlans() {

		if( isset($_GET["woorcp_redefine_plans"]) && current_user_can( 'manage_options' ) ) {

			global $rcp_options;

			$levels = rcp_get_subscription_levels( "active" );

			/*$allowed_subscriptions = get_option("woorcp_allowed_subscriptions");
			if( empty( $allowed_subscriptions ) ) {
				return;
			}*/

			if( empty($levels) ) {
				return;
			}

			foreach ($levels as $key => $level) {
				$this->create_delete_stripe_plan( $level->id, $level );
			}
		}
	}

	public function create_delete_stripe_plan( $level_id, $plan ) {

		global $rcp_options, $rcp_levels_db;

		// get all subscription level info for this level
		//$plan           = rcp_get_subscription_details( $level_id );
		$price          = round( $plan->price * rcp_stripe_get_currency_multiplier(), 0 );
		$interval       = $plan->duration_unit;
		$interval_count = $plan->duration;
		$name           = $plan->name;
		$plan_id 		= $this->generate_plan_id( $plan );
		$currency       = strtolower( rcp_get_currency() );
		$amount 		= $rcp_levels_db->get_meta( $level_id, 'per_member_price', true );
		
		if( empty($amount) || $amount <= 0 ) {
			rcp_log( sprintf( __('WOORCP: The subscription level "%s" has no additional member price, existing.', 'rcp') , $plan->name ) );
			return;
		}

		$amount         = round( $amount * rcp_stripe_get_currency_multiplier(), 0 );
		
		$create_plan 	= true;
		$s_plan 		= false;

		try {
			$s_plan 		= \Stripe\Plan::retrieve($plan_id);
			$product_id 	= $s_plan->product;
			$s_plan->delete();
			rcp_log( sprintf( 'WOORCP: Successfully deleted subscription plan from stripe #%s.', $plan_id ) );
			
			// delete product
			$product = \Stripe\Product::retrieve($product_id);
			$product->delete();

			rcp_log( sprintf( 'WOORCP: Successfully deleted subscription product from stripe #%s.', $product_id ) );

			$product = \Stripe\Product::create( array(
				'name' => $name,
				'type' => 'service'
			) );

		} catch ( Exception $e ) {}

		if( $create_plan && $s_plan ) {

			//$additional_member_price = ( $interval == "year" ) ? $this->additional_member_price * 12 : $this->additional_member_price;
			//$additional_member_price = round(rcp_stripe_get_currency_multiplier() * $additional_member_price, 0);

			//$s_plan 		= \Stripe\Plan::retrieve($plan_id);
			$s_product_id 	= $product->id;

			$additional_member_price = $amount;

			$plan = \Stripe\Plan::create( array(
				"interval"       => $interval,
				"interval_count" => $interval_count,
				"currency"       => $currency,
				"id"             => $plan_id,
				"product"        => $s_product_id,
				"billing_scheme" => "tiered",
				"tiers_mode"     => "graduated",
				"tiers"          => array(
					array(
						"flat_amount"   =>  $price,
						"unit_amount"   =>  0,
						"up_to"         =>  1,
					),
					array(
						"flat_amount"   =>  0,
						"unit_amount"   =>  $additional_member_price,
						"up_to"         =>  "inf"
					)
				)
			));

			rcp_log( sprintf( 'WOORCP: Successfully created subscription plan on stripe # %s.', $plan->id ) );
		}
	}


	/**
	 * Generate a Stripe plan ID string based on a membership level
	 *
	 * The plan name is set to {levelname}-{price}-{duration}{duration unit}
	 * Strip out invalid characters such as '@', '.', and '()'.
	 * Similar to WP core's sanitize_html_class() & sanitize_key() functions.
	 *
	 * @param object $membership_level
	 *
	 * @since 3.0.3
	 * @return string
	 */
	private function generate_plan_id( $membership_level ) {

		$level_name = strtolower( str_replace( ' ', '', sanitize_title_with_dashes( $membership_level->name ) ) );
		$plan_id    = sprintf( '%s-%s-%s', $level_name, round($membership_level->price), $membership_level->duration . $membership_level->duration_unit );
		$plan_id    = preg_replace( '/[^a-z0-9_\-]/', '-', $plan_id );

		return $plan_id;

	}

	public function corporate_login_redirect( $redirect, $user ) {
		if( in_array("rcp_corporate", $user->roles) ) {
			$redirect = !empty( $page_id = get_option("woorcp_corp_profile_page")) ? get_permalink( $page_id ) : home_url();
		}
		return $redirect;
	}

	public function corporate_checkout_page() {

	}

	public function corporate_checkout_callback() {
		global $post;

		$user = wp_get_current_user();

		if( !in_array("rcp_corporate", $user->roles) ) {
			return;
		}

		$user_id = $user->ID;
		$user_meta = get_user_meta($user_id);
		if( is_array( $user_meta["pending_users"] ) ) {
			$pending_users = !empty( $user_meta["pending_users"] ) ? $user_meta["pending_users"][0] : array();
		} else {
			$pending_users = !empty( $user_meta["pending_users"][0] ) ? $user_meta["pending_users"][0] : array();
		}

		$pending_users = maybe_unserialize($pending_users);
		ob_start();
		?>

        <?php
        if( !empty($pending_users) && is_array( $pending_users ) ) {
            ?>
            <form action="<?php echo rcp_get_current_url();?>" method="post">
                <table class="tablepress">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Subscription</th>
                            <th>Number</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $total_price = $price = 0;
                    foreach ( $pending_users as $key => $pending_user ) {

                        $level = rcp_get_subscription_details($pending_user["sub_id"]);

                        if( $level->duration_unit == "year" && $pending_user["user_count"] > $this->default_member_number ) {
                            $priced_users = $pending_user["user_count"] - $this->default_member_number;
                            $price = $level->price + ( $priced_users * ( 12 * $this->additional_member_price ) );
                        }
                        elseif ( $level->duration_unit == "year" && $pending_user["user_count"] < $this->default_member_number ) {
                            $price = $level->price;
                        }
                        elseif ( $level->duration_unit == "month" && $pending_user["user_count"] > $this->default_member_number ) {
                            $priced_users = $pending_user["user_count"] - $this->default_member_number;
                            $price = $level->price + ( $priced_users * $this->additional_member_price );
                        }
                        elseif ( $level->duration_unit == "month" && $pending_user["user_count"] < $this->default_member_number ) {
                            $price = $level->price;
                        } else {
                            $price = $level->price;
                        }

                        ?>
                        <tr>
                            <td><?php echo $pending_user["fname"]." ".$pending_user["lname"]; ?></td>
                            <td><?php echo $pending_user["email"]; ?></td>
                            <td><?php echo $level->name; ?></td>
                            <td><?php echo $pending_user["user_count"]; ?></td>
                            <td><?php echo rcp_get_currency_symbol().$price; ?></td>
                            <td style="display: none;">
                                <input type="hidden" name="users_info[<?php echo $key;?>][price]" value="<?php echo $price; ?>">
                                <input type="hidden" name="users_info[<?php echo $key;?>][sub_id]" value="<?php echo $pending_user["sub_id"];?>">
                                <input type="hidden" name="users_info[<?php echo $key;?>][email]" value="<?php echo $pending_user["email"];?>">
                                <input type="hidden" name="users_info[<?php echo $key;?>][fname]" value="<?php echo $pending_user["fname"];?>">
                                <input type="hidden" name="users_info[<?php echo $key;?>][lname]" value="<?php echo $pending_user["lname"];?>">
                                <input type="hidden" name="users_info[<?php echo $key;?>][name]" value="<?php echo $pending_user["fname"]." ".$pending_user["lname"];?>">
                                <input type="hidden" name="users_info[<?php echo $key;?>][user_count]" value="<?php echo $pending_user["user_count"];?>">
                            </td>
                        </tr>
                        <?php
                        $total_price += $price;
                    }
                    ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3"></td>
                            <td>Total</td>
                            <td><?php echo rcp_get_currency_symbol().$total_price;?></td>
                        </tr>
                        <tr>
                            <td colspan="4"></td>
                            <td><input type="submit" name="woorcp_corp_checkout" value="Checkout"></td>
                        </tr>
                    </tfoot>
                </table>
                <input type="hidden" name="total_price" value="<?php echo $total_price; ?>">
                <input type="hidden" name="user_id" value="<?php echo $user_id;?>">
                <input type="hidden" name="cust_id" value="<?php echo $user_meta["rcp_payment_profile_id"][0]; ?>">
                <!--<input type="hidden" name="" value="">
                <input type="hidden" name="" value="">-->
                <?php wp_nonce_field( 'corp_checkout_action', 'corp_checkout_field' ); ?>

            </form>
            <?php
        }
        else {
            ?>
            <p class="rcp_success"><span>Sorry! It seems you do not have any pending group leaders to add.</span></p>
            <?php
        }
        ?>
		<?php
		$output = ob_get_clean();
		return $output;
	}


	public function corporate_checkout_post() {
		global $post;
		if( isset($_POST["woorcp_corp_checkout"]) && !empty($_POST["users_info"] ) ) {
		    extract($_POST);
		    foreach ($_POST["users_info"] as $key => $user_info) {
                $this->create_member($user_info);
            }

		    delete_user_meta( $_POST["user_id"], "pending_users", "" );

		    do_action("woorcp_after_members_added", $_POST["user_id"]);

		    wp_safe_redirect( get_permalink($post) );
		    exit;
		}
    }

    public function create_member( $user_info ) {
	    global $rcp_options, $rcp_levels_db, $post;

	    $corporate           = new RCP_Member( get_current_user_id() );

	    $subscription_id     = $user_info ["sub_id"];
	    $discount            = isset( $_POST['rcp_discount'] ) ? sanitize_text_field( strtolower( $_POST['rcp_discount'] ) ) : '';
	    $price               = number_format( (float) $rcp_levels_db->get_level_field( $subscription_id, 'price' ), 2 );
	    $price               = str_replace( ',', '', $price );
	    $subscription        = $rcp_levels_db->get_level( $subscription_id );
	    $auto_renew          = true; //rcp_registration_is_recurring()
	    $trial_duration      = $rcp_levels_db->trial_duration( $subscription_id );
	    $trial_duration_unit = $rcp_levels_db->trial_duration_unit( $subscription_id );
	    $gateway             = "stripe";
	    $total_price         = $user_info["price"];
	    $user_data           = array();

	    rcp_log( sprintf( 'WOORCP: Started new registration for subscription #%d via %s.', $subscription_id, $gateway ) );

	    $display_name = trim( $user_info['name'] );
	    $user_data['id'] = wp_insert_user( array(
			    'user_login'      => $user_info['email'],
			    'user_pass'       => $user_info['password'],
			    'user_email'      => $user_info['email'],
			    'first_name'      => $user_info['fname'],
			    'last_name'       => $user_info['lname'],
			    'display_name'    => ! empty( $display_name ) ? $display_name : $user_data['login'],
			    'user_registered' => date( 'Y-m-d H:i:s' )
		    )
	    );

	    if ( empty($user_data['id']) && !is_int($user_data['id']) ) {
		    return;
	    }

	    // Setup the member object
	    $member = new RCP_Member( $user_data['id'] );

	    // set member's WP user role
	    $member->set_role("group_leader");

	    do_action("woorcp_group_leader_created", $user_data["id"], $user_data, $subscription_id, $member, $corporate);

	    // Save agreement to terms and privacy policy.
	    if ( ! empty( $_POST['rcp_agree_to_terms'] ) ) {
		    $terms_agreed = get_user_meta( $member->ID, 'rcp_terms_agreed', true );

		    if ( ! is_array( $terms_agreed ) ) {
			    $terms_agreed = array();
		    }

		    $terms_agreed[] = current_time( 'timestamp' );

		    update_user_meta( $member->ID, 'rcp_terms_agreed', $terms_agreed );
	    }
	    if ( ! empty( $_POST['rcp_agree_to_privacy_policy'] ) ) {
		    $privacy_policy_agreed = get_user_meta( $member->ID, 'rcp_privacy_policy_agreed', true );

		    if ( ! is_array( $privacy_policy_agreed ) ) {
			    $privacy_policy_agreed = array();
		    }

		    $privacy_policy_agreed[] = current_time( 'timestamp' );

		    update_user_meta( $member->ID, 'rcp_privacy_policy_agreed', $privacy_policy_agreed );
	    }

	    update_user_meta( $user_data['id'], '_rcp_new_subscription', '1' );

	    $subscription_key = rcp_generate_subscription_key();

	    $old_subscription_id = $member->get_subscription_id();

	    $member_has_trialed = $member->has_trialed();

	    if( $old_subscription_id ) {
		    update_user_meta( $user_data['id'], '_rcp_old_subscription_id', $old_subscription_id );
	    }

	    // Delete pending payment ID. A new one may be created for paid subscriptions.
	    delete_user_meta( $user_data['id'], 'rcp_pending_payment_id' );

	    // Delete old pending data that may have been added in previous versions.
	    delete_user_meta( $user_data['id'], 'rcp_pending_expiration_date' );
	    delete_user_meta( $user_data['id'], 'rcp_pending_subscription_level' );
	    delete_user_meta( $user_data['id'], 'rcp_pending_subscription_key' );

	    // Backwards compatibility pre-2.9: set pending subscription key.
	    update_user_meta( $user_data['id'], 'rcp_pending_subscription_key', $subscription_key );

	    // Create a pending payment
	    /*$amount = ( ! empty( $trial_duration ) && !rcp_has_used_trial() ) ? 0.00 : $total_price;*/
	    $amount = $total_price;

	    $fee    = $user_info["user_count"] > $this->default_member_number ? floatval($total_price) - floatval($subscription->price) : rcp_get_registration()->get_total_fees() + $member->get_prorate_credit_amount();

	    $fee    = rcp_get_registration()->add_fee($fee, "Additional member fee", true, false);

	    $payment_data = array(
		    'date'                  => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
		    'subscription'          => $subscription->name,
		    'object_id'             => $subscription->id,
		    'object_type'           => 'subscription',
		    'gateway'               => $gateway,
		    'subscription_key'      => $subscription_key,
		    'amount'                => $amount,
		    'user_id'               => $user_data['id'],
		    'status'                => 'pending',
		    'subtotal'              => $subscription->price,
		    'credits'               => $member->get_prorate_credit_amount(),
		    'fees'                  => $fee,
		    'discount_amount'       => rcp_get_registration()->get_total_discounts(),
		    'discount_code'         => $discount,
	    );

	    $rcp_payments = new RCP_Payments();
	    $payment_id   = $rcp_payments->insert( $payment_data );
	    update_user_meta( $user_data['id'], 'rcp_pending_payment_id', $payment_id );

	    $member->set_recurring(true);

	    if( ! $member->get_subscription_id() || $member->is_expired() || in_array( $member->get_status(), array( 'expired', 'pending' ) ) ) {

		    // Ensure no pending level details are set
		    delete_user_meta( $user_data['id'], 'rcp_pending_subscription_level' );
		    delete_user_meta( $user_data['id'], 'rcp_pending_subscription_key' );

		    $member->set_status( 'pending' );

	    } else {

		    // Flag the member as having just upgraded
		    update_user_meta( $user_data['id'], '_rcp_just_upgraded', current_time( 'timestamp' ) );

	    }

	    // Remove trialing status, if it exists
	    if ( ! $trial_duration || $trial_duration && $member_has_trialed ) {
		    delete_user_meta( $user_data['id'], 'rcp_is_trialing' );
	    }

	    do_action( 'rcp_form_processing', $_POST, $user_data['id'], $price, $payment_id );
	    do_action( 'woorcp_member_payment_created', $user_data['id'], $price, $payment_id, $corporate );

	    $subscription_data = array(
		    'price'               => $subscription->price, // get total without the fee
		    'recurring_price'     => $subscription->price, // get recurring total without the fee
		    'discount'            => 0,
		    'discount_code'       => $discount,
		    'fee'                 => rcp_get_registration()->get_total_fees(),
		    'length'              => $subscription->duration,
		    'length_unit'         => strtolower( $subscription->duration_unit ),
		    'subscription_id'     => $subscription->id,
		    'subscription_name'   => $subscription->name,
		    'key'                 => $subscription_key,
		    'user_id'             => $user_data['id'],
		    'user_name'           => $member->get('user_login'),
		    'user_email'          => $member->get('user_email'),
		    'currency'            => rcp_get_currency(),
		    'auto_renew'          => $auto_renew,
		    'new_user'            => $user_data['need_new'],
		    'trial_duration'      => $trial_duration,
		    'trial_duration_unit' => $trial_duration_unit,
		    'trial_eligible'      => ! $member_has_trialed,
		    'post_data'           => $_POST,
		    'payment_id'          => $payment_id,
            "user_count"          => $user_info["user_count"],
		    'object_id'           => $subscription->id,
	    );

	    $this->createStripeSubscription( $subscription_data );

    }


    public function createStripeSubscription( $subscription_data ) {

	    global $rcp_options;

	    /**
	     * @var RCP_Payments $rcp_payments_db
	     */
	    global $rcp_payments_db;

	    $corporate = new RCP_Member( get_current_user_id() );
	    $member = new RCP_Member( $subscription_data['user_id'] );
	    $customer_id = $corporate->get_payment_profile_id();
	    $customer = \Stripe\Customer::retrieve( $customer_id );

	    update_user_meta( $subscription_data['user_id'], "rcp_payment_profile_id", $customer_id, "");

	    if ( ! $plan_id = $this->plan_exists( $subscription_data["subscription_id"] ) ) {
		    // create the plan if it doesn't exist
		    $plan_id = $this->create_plan( $subscription_data["subscription_id"] );
	    }

	    \Stripe\InvoiceItem::create( array(
		    'customer'    => $customer->id,
		    'amount'      => 0,
		    'currency'    => rcp_get_currency(),
		    'description' => 'Setting Customer Currency',
	    ) );

	    $temp_invoice = \Stripe\Invoice::create( array(
		    'customer' => $customer->id,
	    ) );

	    // Remove the temporary invoice
	    if( isset( $temp_invoice ) ) {
		    $invoice = \Stripe\Invoice::retrieve( $temp_invoice->id );
		    $invoice->closed = true;
		    $invoice->save();
		    unset( $temp_invoice, $invoice );
	    }

	    $sub_args = array(
		    'plan'     => $plan_id,
		    'quantity' => $subscription_data["user_count"],
		    'metadata' => array(
			    'rcp_subscription_level_id' => $subscription_data["subscription_id"],
			    'rcp_member_id'             => $subscription_data["user_id"],
                "rcp_member_name"           => $member->display_name,
                'rcp_corporate_user_id'     => $corporate->ID,
			    'rcp_corporate_user_name'   => $corporate->display_name,
		    )
	    );

	    if( !empty($subscription_data["trial_eligible"]) && !empty($subscription_data["trial_duration"]) && !empty($subscription_data["trial_duration_unit"]) ) {
		    $sub_args['trial_end'] = strtotime( $subscription_data['trial_duration'] . ' ' . $subscription_data['trial_duration_unit'], current_time( 'timestamp' ) );
        }

	    $sub_options = array();

	    $stripe_connect_user_id = get_option( 'rcp_stripe_connect_account_id', false );

	    if( ! empty( $stripe_connect_user_id ) ) {
		    $sub_options['stripe_account'] = $stripe_connect_user_id;
	    }

	    $subscription = $customer->subscriptions->create( $sub_args, $sub_options );

	    $member->set_merchant_subscription_id( $subscription->id );

	    // Complete payment and activate account.

	    $payment_data = array(
		    'payment_type'   => 'Credit Card',
		    'transaction_id' => '',
		    'status'         => 'complete'
	    );

	    if( !empty($subscription_data["trial_eligible"]) && !empty($subscription_data["trial_duration"]) && !empty($subscription_data["trial_duration_unit"]) ) {
		    $payment_data['transaction_id'] = $subscription->id;
	    } else {
		    // Try to get the invoice from the subscription we just added so we can add the transaction ID to the payment.
		    $invoices = \Stripe\Invoice::all( array(
			    'subscription' => $subscription->id,
			    'limit'        => 1
		    ) );

		    if ( is_array( $invoices->data ) && isset( $invoices->data[0] ) ) {
			    $invoice = $invoices->data[0];

			    // We only want the transaction ID if it's actually been paid. If not, we'll let the webhook handle it.
			    if ( true === $invoice->paid ) {
				    $payment_data['transaction_id'] = $invoice->charge;
			    }
		    }
        }

	    // Only complete the payment if we have a transaction ID. If we don't, the webhook will complete the payment.
	    if ( ! empty( $payment_data['transaction_id'] ) ) {
		    $rcp_payments_db->update( $subscription_data["payment_id"], $payment_data );

	    }

	    do_action("woorcp_group_leader_subscription_created", $subscription_data["object_id"], $subscription_data["payment_id"], $subscription_data["user_count"], $payment_data, $member, $corporate);

    }

	/**
	 * Create plan in Stripe
	 *
	 * @param int $plan_id ID number of the plan.
	 *
	 * @since 2.1
	 * @return bool|string - plan_id if successful, false if not
	 */
	private function create_plan( $plan_id = '' ) {
		global $rcp_options;

		// get all subscription level info for this plan
		$plan           = rcp_get_subscription_details( $plan_id );
		$price          = round( $plan->price * rcp_stripe_get_currency_multiplier(), 0 );
		$interval       = $plan->duration_unit;
		$interval_count = $plan->duration;
		$name           = $plan->name;
		$plan_id        = sprintf( '%s-%s-%s', strtolower( str_replace( ' ', '', $plan->name ) ), $plan->price, $plan->duration . $plan->duration_unit );
		$currency       = strtolower( rcp_get_currency() );

		try {

			$product = \Stripe\Product::create( array(
				'name' => $name,
				'type' => 'service'
			) );

			$plan = \Stripe\Plan::create( array(
				"amount"         => $price,
				"interval"       => $interval,
				"interval_count" => $interval_count,
				"currency"       => $currency,
				"id"             => $plan_id,
				"product"        => $product->id
			) );

			// plann successfully created
			return $plan->id;

		} catch ( Exception $e ) {}

	}

	/**
	 * Determine if a plan exists
	 *
	 * @param int $plan The ID number of the plan to check
	 *
	 * @since 2.1
	 * @return bool|string false if the plan doesn't exist, plan id if it does
	 */
	private function plan_exists( $plan_id ) {

		if ( ! $plan = rcp_get_subscription_details( $plan_id ) ) {
			return false;
		}

		// fallback to old plan id if the new plan id does not exist
		$old_plan_id = strtolower( str_replace( ' ', '', $plan->name ) );
		$new_plan_id = sprintf( '%s-%s-%s', $old_plan_id, $plan->price, $plan->duration . $plan->duration_unit );
		$new_plan_id = $this->generate_plan_id( $plan );

		// check if the plan new plan id structure exists
		try {

			$plan = \Stripe\Plan::retrieve( $new_plan_id );
			return $plan->id;

		} catch ( Exception $e ) {}

		try {
			// fall back to the old plan id structure and verify that the plan metadata also matches
			$stripe_plan = \Stripe\Plan::retrieve( $old_plan_id );

			if ( (int) $stripe_plan->amount !== (int) $plan->price * 100 ) {
				return false;
			}

			if ( $stripe_plan->interval !== $plan->duration_unit ) {
				return false;
			}

			if ( $stripe_plan->interval_count !== intval( $plan->duration ) ) {
				return false;
			}

			return $old_plan_id;

		} catch ( Exception $e ) {
			return false;
		}

	}

	public function update_user_fields( $user_id, $user_data, $subscription_id, $member, $corporate ) {

	    update_user_meta( $user_id, "woorcp_corporate_user_id", $corporate->ID, "" );
	    // @todo update corporate user meta to list its own group leaders
    }

    public function group_leader_subscription_created( $subscription_id, $payment_id, $seats, $payment_data, $member, $corporate ) {

	    $args = array(
		    'owner_id'    => $member->ID,
		    'name'        => wp_unslash( sanitize_text_field( $member->display_name . "'s Group" ) ),
		    'description' => '',
		    'seats'       => $seats,
	    );

	    $group_id = rcpga_group_accounts()->groups->add( $args );

	    $corporate_groups = get_user_meta( $corporate->ID, "rcp_group_ids", true );

	    if( empty( $corporate_groups ) ) {
		    $corporate_groups = array();
        }
	    array_push($corporate_groups, $group_id);

	    update_user_meta( $corporate->ID, "rcp_group_ids", $corporate_groups, "" );

    }

    public function corporate_manage_groups_callback() {

	    global $post;
	    $user = wp_get_current_user();

	    if( !in_array("rcp_corporate", $user->roles) ) {
		    return;
	    }

	    $user_id = $user->ID;
	    $user_group_ids = get_user_meta($user_id, "rcp_group_ids", true);

	    ob_start();

	    if( !empty($_GET["group_id"]) && isset($_GET["action"]) && $_GET["action"] == "view-members" ) {
            return $this->corporate_view_group_members( $_GET["group_id"] );
	    }

	    if( isset($_GET["group"]) && $_GET["group"] == "deleted" ) {
            echo '<p class="rcp_success"><span>Group has been deleted successfully!</span></p>';
            echo "<p><br></p>";
        }

	    if( isset($_GET["msg"]) && $_GET["msg"] == "member_removed" ) {
		    echo '<p class="rcp_success"><span>Member has been removed from group successfully!</span></p>';
		    echo "<p><br></p>";
	    }

	    if( !empty( $user_group_ids ) ) {

		    ?>
            <table class="rcp-table" id="corporate-groups">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Owner</th>
                        <th>Members</th>
                        <th>Seats</th>
                        <th>Date Created</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>


                <?php
                foreach ( $user_group_ids as $group_id ) {
                    $group = rcpga_group_accounts()->groups->get( $group_id );
                    if ( empty( $group ) ) {
                        continue;
                    }
                    $owner = get_user_by("ID", $group->owner_id);
                    $members_link = add_query_arg( ["group_id"=>$group_id, "action"=>"view-members"], get_permalink($post) );
	                $cancel_link = add_query_arg( ["woorcp-action"=>true, "group_id"=>$group_id, "action"=>"delete"], get_permalink($post) );
                    ?>
                    <tr>
                        <td><?php echo $owner->display_name; ?></td>
                        <td><?php echo $group->name; ?></td>
                        <td><?php echo $group->member_count; ?></td>
                        <td><?php echo $group->seats; ?></td>
                        <td><?php echo $group->date_created; ?></td>
                        <td>
                            <a href="<?php echo $members_link; ?>">View Members</a>
                            |
                            <a href="<?php echo $cancel_link; ?>" class="woorcp-delete-group">Delete Group</a>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
		    <?php
        }
	    else {
		    echo '<p class="rcp_success"><span>Sorry! It seems you do not have associated groups.</span></p>';
        }
        ?>

        <?php
	    $output = ob_get_clean();
	    return $output;
    }

    public function remove_corporate_group() {

	    global $post;

	    if( isset($_GET["woorcp-action"]) && !empty($_GET["group_id"]) && isset($_GET["action"]) && $_GET["action"] == "delete" ) {
	        $group_id   = $_GET["group_id"];
		    $group      = rcpga_group_accounts()->groups->get( $group_id );
		    rcpga_group_accounts()->members->remove_all_from_group( $group_id );
		    rcpga_group_accounts()->groups->delete( $group_id );

		    $deleted = rcp_cancel_member_payment_profile( $group->owner_id );
		    wp_delete_user( $group->owner_id );
		    wp_redirect (add_query_arg( "group","deleted", remove_query_arg( array('woorcp-action', 'group_id', 'action'), get_permalink($post) )));
		    exit;
        }

	    if( isset($_GET["woorcp_action"]) && $_GET["woorcp_action"] == "remove-member" && isset($_GET["member_id"]) && !empty($_GET["member_id"]) ) {

		    $deleted = rcpga_group_accounts()->members->remove( $_GET["member_id"] );

		    if( $deleted ) {
                wp_safe_redirect( add_query_arg(["action"=>"view-members", "group_id"=>$_GET["group_id"], "msg"=>"member_removed", "woorcp_action" => false, "member_id" => false], get_permalink($post)) );
                exit;
            }
        }
    }

    public function corporate_view_group_members( $group_id ) {
	    global $post;

	    $members = rcpga_group_accounts()->members->get_members( $group_id );

	    // Sort members by role.
	    $sort_order  = apply_filters( 'rcpga_member_role_sort_order', array( 'owner', 'admin', 'invited', 'member' ) );
	    $member_list = array();

	    foreach( $sort_order as $role ) {
		    foreach( $members as $member ) {
			    if ( $role == $member->role ) {
				    $member_list[] = $member;
			    }
		    }
	    }

	    if( isset($_GET["msg"]) && $_GET["msg"] == "member_removed" ) {
		    echo '<p class="rcp_success"><span>Member has been removed from group successfully!</span></p>';
		    echo "<p><br></p>";
	    }

	    if( !empty( $member_list ) ) {
            ?>
            <table class="rcp-table" id="corporate-groups">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Date Added</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                foreach ($member_list as $member) {

                    $member_info = get_user_by("ID", $member->user_id);
                    $remove_member = add_query_arg(["woorcp_action"=>"remove-member","member_id"=>$member->user_id, "group_id"=>$member->group_id], get_permalink($post));
                    ?>
                    <tr>
                        <td><?php echo $member_info->display_name; ?></td>
                        <td><?php echo ucwords($member->role); ?></td>
                        <td><?php echo $member->date_added; ?></td>
                        <td>
                            <?php if( $member->role == "member" ): ?>
                                <a href="<?php echo $remove_member; ?>" onclick="return confirm('<?php echo __("Are you sure you want to remove this member from group?"); ?>');">Remove Member</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
		    <?php
        }

    }

}

new WooRCP_Corporate();