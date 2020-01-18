<?php

Group_member_tweaks::get_instance();
Class Group_member_tweaks {

	/**
	 * @var
	 */
	protected static $_instance;

	protected $secret_key;
	protected $publishable_key;

	/**
	 * Only make one instance of the RCPGA_Settings
	 *
	 * @return RCPGA_Settings
	 */
	public static function get_instance() {
		if ( ! self::$_instance instanceof Group_member_tweaks ) {
			self::$_instance = new Group_member_tweaks();
		}

		return self::$_instance;
	}

	/**
	 * Add Hooks and Actions
	 */
	protected function __construct() {

		$this->test_mode = rcp_is_sandbox();

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

		//\Stripe\Stripe::setApiVersion( '2018-02-06' );
		\Stripe\Stripe::setApiVersion( '2019-05-16' );

		if ( method_exists( '\Stripe\Stripe', 'setAppInfo' ) ) {
			\Stripe\Stripe::setAppInfo( 'Restrict Content Pro', RCP_PLUGIN_VERSION, esc_url( site_url() ) );
		}

	}


	public function testing() {
		if( isset( $_GET["aspire_testing"] ) ) {

			/*$customer = \Stripe\Customer::retrieve('cus_GLY3SaL1eg1Gd9');

			$invoice = \Stripe\Invoice::upcoming(["customer" => $customer->id]);
			
			dd($invoice, false);*/


			/*$plans = \Stripe\Plan::all(['limit' => 70]);
			dd($plans);*/

			/*$plan_id 		= 'gold-aspire-monthly-membership-49-1month';

			$s_plan 		= false;

			try {
				$s_plan 	= \Stripe\Plan::retrieve($plan_id);
			} catch ( Exception $e ) {}

			if( $s_plan ) {

				dd($s_plan, false);

				$s_plan->delete();

				$plan = \Stripe\Plan::create( array(
					"interval"       => 'month',
					"interval_count" => 1,
					"currency"       => 'usd',
					"id"             => $plan_id,
					"product"        => 'prod_GYLeuNNmGGer1u',
					"billing_scheme" => "tiered",
					"tiers_mode"     => "graduated",
					"tiers"          => array(
						array(
							"flat_amount"   =>  4900,
							"unit_amount"   =>  0,
							"up_to"         =>  1,
						),
						array(
							"flat_amount"   =>  0,
							"unit_amount"   =>  1900,
							"up_to"         =>  "inf"
						)
					)
				));

				dd($plan);

			}

			dd("we should not be here.");*/

		} // end if
	}


	/**
	 * Actions and Filters
	 */
	protected function hooks() {

		//add_action("rcp_registration_init", array( $this, "renewal_add_fee_checkout" ), 10 , 1);

		add_filter("init", array( $this, "testing" ) , 10, 1);
		
		//add_filter("rcpga_db_groups_get", array( $this, "alter_add_member_to_group" ) , 10, 1);

		add_filter("rcp_default_user_level", array( $this, "group_owner_default_role" ), 10, 1);

		//add_filter("rcpga_get_level_group_seats_allowed", array( $this, "remove_max_group_seats_error" ), 10 , 1);

		add_filter("rcpga-group-status-message", array( $this, "user_group_dashboard_message" ), 10, 3);

		add_action("rcpga_add_member_to_group_after", array( $this, "charge_group_leader_additional_member" ), 11 , 3);

		add_action("rcpga_remove_member", array( $this, "update_stripe_sub_remove_user" ), 20, 2);

	}

	public function renewal_add_fee_checkout( $object ) {

		global $rcp_options, $rcp_levels_db;

		if( is_user_logged_in() && !empty( $level_id = rcp_get_subscription_id() ) ) {
			$user_id = get_current_user_id();
			$subscription        = $rcp_levels_db->get_level( $level_id );
			
			$user_group = rcpga_group_accounts()->groups->get_group_by_owner( $user_id );

			$group_members = rcpga_group_accounts()->groups->get_member_count( $user_group->group_id );

			$group_seats = rcpga_group_accounts()->groups->get_seats_count( $user_group->group_id );

			$additional_members = $group_members - $group_seats;

			//$additional_member_price 	= get_option("woorcp_additional_member_fee", 49);
			$additional_member_price	= $rcp_levels_db->get_meta($level_id, "per_member_price", true);

			if( $additional_members > 0 ) {

				$object->add_fee(
					$additional_member_price * $additional_members,
					"Additional Member Fee: ".rcp_get_currency_symbol().$additional_member_price . " x ".$additional_members,
					true,
					false
				);
			}
		}
	}

	public function alter_add_member_to_group( $row ) {

		if( !is_admin() && isset($_REQUEST['rcpga-action']) && $_REQUEST['rcpga-action'] == "add-member" ) {
			$row->seats = 99999999;
	    }

		return $row;
	}


	public function group_owner_default_role( $role ) {
		if( !is_admin() && isset( $_REQUEST["rcpga-group-name"] ) && !empty( $_REQUEST["rcpga-group-name"] ) ) {
			$role = "group_leader";
		}
		return $role;
	}
	

	public function remove_max_group_seats_error( $count ) {

		/*if( is_admin() && current_user_can("manage_options") ) {

			$count = get_option("woorcp_default_member_number", 9);

		} elseif ( isset($_REQUEST["rcpga-group-name"]) && !rcp_get_subscription_id() ) {

			$count = get_option("woorcp_default_member_number", 9);

		} elseif( rcp_registration_is_recurring() ) {

			$count = 99;

		} else {
			$count = 999;
		}*/

		$count = 99999999;

		return $count;
		//@todo
	}


	public function user_group_dashboard_message( $message, $group_id, $user_id ) {

		global $rcp_levels_db;

		$user_id     	= get_current_user_id();
		$member 	 	= new RCP_Member( $user_id );
		$customer 		= rcp_get_customer_by_user_id( $user_id );
		$membership 	= rcp_get_customer_single_membership( $customer->get_id() );
		$groups 	 	= rcpga_get_group_by( 'membership_id', $membership->get_id() );
		if( empty( $groups ) ) {
			return $message;
		}
		$group_id    	= $groups->get_group_id();
		$group 			= rcpga_get_group( $group_id );
		$used_seats 	= $group->get_member_count( $group_id );
		$total_seats 	= $group->get_seats( $group_id );
		
		$woorcp_additional_member_fee = $rcp_levels_db->get_meta( $member->get_subscription_id(), "per_member_price", true);

		if( empty($woorcp_additional_member_fee) || $woorcp_additional_member_fee == 0 ) {
			return $message;
		}

		$message = "";
		$message .=  sprintf( 
			'<p>' . __( 'You are currently using %s out of %s seats available in your group.', 'rcp-group-accounts' ) . '</p>', 
			esc_html( $used_seats ), 
			esc_html( $total_seats ) 
		);

		$message .= sprintf( '<p>' . __( 'Please note that additional member will be charged by %s.', 'rcp-group-accounts' ) . '</p>', esc_html( rcp_get_currency_symbol().$woorcp_additional_member_fee ) );

		return $message;
	}

	public function charge_group_leader_additional_member( $user_id, $args, $group_id ) {

		global $rcp_options, $rcp_payments_db, $rcp_levels_db;

		$user_data 		= get_user_by("ID", $user_id);

		$group 			= rcpga_get_group( $group_id );
		$group_owner_id = $group->get_owner_id( $group_id );
		$group_seats 	= $group->get_member_count( $group_id );

		$group_owner_data = get_user_by("ID", $group_owner_id);

		$group_owner_cust_id = get_user_meta( $group_owner_id, "rcp_payment_profile_id", true);


		//$additional_member_price = get_option("woorcp_additional_member_fee", 49);

		$member                 = new RCP_Member( $group_owner_id );
		$customer 				= rcp_get_customer_by_user_id( $group_owner_id );
		$membership 			= rcp_get_customer_single_membership( $customer->get_id() );
		
        $stripe_sub_id          = $member->get_merchant_subscription_id();
        $subscription_id        = $member->get_subscription_id();
        $subscription_name      = $member->get_subscription_name();
        $subscription           = $rcp_levels_db->get_level( $subscription_id );
        $amount 				= $this->get_addition_member_price_prorated( $group_owner_id, $subscription, $member);

		if( ( !$member->is_trialing() || $membership->is_paid() ) && $amount > 0 ) {
            $customer               = \Stripe\Customer::retrieve($group_owner_cust_id);

            $intent_options 		= array();
            $stripe_connect_user_id = get_option( 'rcp_stripe_connect_account_id', false );
            $existing_intent        = false;

			$intent_args = array(
				'customer'    			=> $customer->id,
				"description"   		=> "Additional Member Cost - User Name: ".$user_data->display_name." - Group Leader: ".$group_owner_data->display_name." ",
				'amount'              	=> round($amount * rcp_stripe_get_currency_multiplier(), 0 ),
				'confirmation_method' 	=> 'automatic',
				'confirm'             	=> true,
				'off_session'		  	=> true,
				'currency'            	=> strtolower( rcp_get_currency() ),
				'payment_method' 	  	=> $customer->invoice_settings->default_payment_method,
				'payment_method_types' 	=> ['card'],
				"metadata"      		=>  array(
                    "email"             =>  $user_data->user_email,
                    "user_id"           =>  $user_id,
                    "group_owner_id"    =>  $group_owner_id,
                    "group_owner_name"  =>  $group_owner_data->display_name,
                    "level_id"          =>  $subscription_id,
                    "level"             =>  $subscription_name,
                )
			);

			$intent_options['idempotency_key'] = rcp_stripe_generate_idempotency_key( $intent_args );
			if ( ! empty( $stripe_connect_user_id ) ) {
				$intent_options['stripe_account'] = $stripe_connect_user_id;
			}

			$intent 		= \Stripe\PaymentIntent::create( $intent_args, $intent_options );

            /*$payment_data 	= array(
                'date'                  => date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
                'subscription'          => $subscription_name,
                'object_id'             => $subscription_id,
                'object_type'           => 'subscription',
                'gateway'               => "stripe",
                'subscription_key'      => $member->get_subscription_key(),
                'amount'                => $amount,
                'user_id'               => $group_owner_id,
                'status'                => 'complete',
                'subtotal'              => $amount,
                'payment_type'          => 'Credit Card One Time',
                'transaction_id'        => $intent->id
            );

            $rcp_payments 	= new RCP_Payments();
            $payment_id   	= $rcp_payments->insert( $payment_data );*/
			

			$plan           = rcp_get_subscription_details( $subscription_id );
			$plan_id 		= $this->generate_plan_id( $plan );

			$s_subscription = \Stripe\Subscription::retrieve($stripe_sub_id);
			$s_subscription->prorate = false;
			$s_subscription->items = array(
				array(
					"id"		=> $s_subscription->items->data[0]->id,
					"plan"		=>	$plan_id,
					"quantity"	=>	$group_seats,
				)
			);
			$s_subscription->save();
			unset($s_subscription);
        }

		//dd($charge);
	}

	private function generate_plan_id( $membership_level ) {

		$level_name = strtolower( str_replace( ' ', '', sanitize_title_with_dashes( $membership_level->name ) ) );
		$plan_id    = sprintf( '%s-%s-%s', $level_name, round($membership_level->price), $membership_level->duration . $membership_level->duration_unit );
		$plan_id    = preg_replace( '/[^a-z0-9_\-]/', '-', $plan_id );

		return $plan_id;

	}


	public function get_addition_member_price_prorated( $user_id, $subscription, $member ) {

		if ( empty($user_id) || empty($subscription) || empty($member) ) {
			return;
		}

		global $rcp_levels_db;

		$expiry_date	= $member->get_expiration_date(false);
		$member_price	= $rcp_levels_db->get_meta($subscription->id, "per_member_price", true);

		if( empty($member_price) || $member_price == 0 ) {
			return 0;
		}
		
		$date1		=	date_create( date("y-m-d") );	
		$date2		=	date_create( date("y-m-d", strtotime($expiry_date)) );
		$date_diff	=	date_diff($date1, $date2);

		$chargeable_days = $date_diff->format("%a");
		
		$charge_per_day = $charge_amount = "";

		if ( $subscription->duration_unit == "year" ) {
			/*$member_price 	= $member_price * 12;*/
			$charge_per_day = $member_price / 365;
			$charge_amount 	= round( $charge_per_day * $chargeable_days, 4 );
		} else if ( $subscription->duration_unit == "month" ) {
			$month_days 	= date("t");
			$charge_per_day = round( $member_price / $month_days, 4 );
			$charge_amount 	= round( $charge_per_day * $chargeable_days, 4 );
		} else {
			$charge_amount 	= $member_price;
		}

		return round($charge_amount, 2);
	}


	public function update_stripe_sub_remove_user( $user_id, $group_id ) {

		$group 					= rcpga_get_group( $group_id );
		$group_owner_id 		= $group->get_owner_id( $group_id );
		$group_seats 			= $group->get_member_count( $group_id );

		$member                 = new RCP_Member( $group_owner_id );
		$subscription_id        = $member->get_subscription_id();
		$stripe_sub_id 			= $member->get_merchant_subscription_id();
		if ( empty( $stripe_sub_id ) )
		    return;

		$plan           		= rcp_get_subscription_details( $subscription_id );
		$plan_id        		= sprintf( '%s-%s-%s', strtolower( str_replace( ' ', '', $plan->name ) ), $plan->price, $plan->duration . $plan->duration_unit );

		$s_subscription = \Stripe\Subscription::retrieve($stripe_sub_id);
		$s_subscription->prorate = false;
		$s_subscription->items = array(
			array(
				"id"		=> 	$s_subscription->items->data[0]->id,
				"plan"		=>	$plan_id,
				"quantity"	=>	$group_seats - 1,
			)
		);
		$s_subscription->save();
		unset($s_subscription);
		
	}
}