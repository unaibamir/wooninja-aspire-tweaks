<?php

add_action( "init", function(){

	if( !isset($_GET["unaib_testing"]) ) {
		return;
	}

	global $rcp_options;

	$test_mode = rcp_is_sandbox();

	if ( $test_mode ) {

		$secret_key      = isset( $rcp_options['stripe_test_secret'] ) ? trim( $rcp_options['stripe_test_secret'] ) : '';
		$publishable_key = isset( $rcp_options['stripe_test_publishable'] ) ? trim( $rcp_options['stripe_test_publishable'] ) : '';

	}

	if ( ! class_exists( 'Stripe\Stripe' ) ) {
		require_once RCP_PLUGIN_DIR . 'includes/libraries/stripe/init.php';
	}

	\Stripe\Stripe::setApiKey( $secret_key );

	\Stripe\Stripe::setApiVersion( '2018-02-06' );

	if ( method_exists( '\Stripe\Stripe', 'setAppInfo' ) ) {
		\Stripe\Stripe::setAppInfo( 'Restrict Content Pro', RCP_PLUGIN_VERSION, esc_url( site_url() ) );
	}

	//$s_plan = \Stripe\Plan::retrieve("testdailysubscription-199-1day");
	//dd($s_plan);

	/*$InvoiceItem = \Stripe\InvoiceItem::create([
	    'currency' => 'usd',
	    'customer' => 'cus_ELjI5u9Q8fw6oq',
	    'description' => 'Additional Member Fee',
	    "quantity"	=>	6,
	    "subscription"	=>	"sub_ELjJfp5VmG8ouF",
	    "unit_amount"	=>	rcp_stripe_get_currency_multiplier() * 49
	]);*/

	/*$subscription = \Stripe\Subscription::retrieve("sub_ELdvuyIh5MegLZ");
	$subscription->prorate = false;
	$subscription->items = array(
		array(
			"id"	=> $subscription->items->data[0]->id,
			"plan"	=>	"testdailysubscription-199-1day",
			"quantity"	=>	10,
		)
	);
	$subscription->save();*/

    $s_subscription = \Stripe\Subscription::retrieve("sub_ENW2URPzjxFsVa");
    dd($s_subscription);

});

WOORCP_Options::get_instance();
class WOORCP_Options {

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
		if ( ! self::$_instance instanceof WOORCP_Options ) {
			self::$_instance = new WOORCP_Options();
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

		\Stripe\Stripe::setApiVersion( '2018-02-06' );

		if ( method_exists( '\Stripe\Stripe', 'setAppInfo' ) ) {
			\Stripe\Stripe::setAppInfo( 'Restrict Content Pro', RCP_PLUGIN_VERSION, esc_url( site_url() ) );
		}

	}

	/**
	 * Actions and Filters
	 */
	protected function hooks() {

		// Add form field to subscription level add and edit forms
		add_action( 'rcp_add_subscription_form',  array( $this, 'add_second_tier_price' ) );
		add_action( 'rcp_edit_subscription_form', array( $this, 'add_second_tier_price' ) );

		// Actions for saving subscription seat count
		add_action( 'rcp_edit_subscription_level', array( $this, 'subscription_level_save_settings' ), 10, 2 );
		add_action( 'rcp_add_subscription',        array( $this, 'subscription_level_save_settings' ), 10, 2 );

		// Actions for creating new page based on subscription
		add_action( 'rcp_add_subscription',        array( $this, 'subscription_create_page' ), 11, 2 );

		// Actions for creating stripe plan
		//add_action( 'rcp_edit_subscription_level', array( $this, 'subscription_create_modify_stripe_plan' ), 10, 2 );
		add_action( 'rcp_add_subscription',        array( $this, 'subscription_create_modify_stripe_plan' ), 999, 2 );

		add_action( 'rcp_add_subscription',        array( $this, 'subscription_modify_group_empty_seats' ), 999, 2 );
		add_action( 'rcp_edit_subscription_level', array( $this, 'subscription_modify_group_empty_seats' ), 999, 2 );

	}

	public function add_second_tier_price( $level = null ) {

		global $rcp_levels_db;

		$per_member_price = $rcp_levels_db->get_meta( $level->id, 'per_member_price', true );

		?>
		<tr class="form-field">
			<th scope="row" valign="top">
				<label for="level_per_member_price"><?php _e( 'Per Member Price', 'rcp-group-accounts' ); ?></label>
			</th>
			<td>
				<input id="level_per_member_price" type="text" name="level_per_member_price" value="<?php echo $per_member_price; ?>" pattern="^(\d+\.\d{1,2})|(\d+)$" style="width: 100px;" required >
				<p class="description">
					<?php _e('The price of per additional member in group of this subscription. This field is required.', 'rcp'); ?>
				</p>
			</td>
		</tr>
	<?php
	}

	/**
	 * Save the member type for this subscription
	 *
	 * @param $subscription_id
	 * @param $args
	 */
	public function subscription_level_save_settings( $level_id, $args ) {

		global $rcp_levels_db;

		$rcp_levels_db->update_meta( $level_id, 'per_member_price', $_POST["level_per_member_price"] );

		do_action( 'woorcp_per_member_price_saved', $level_id );

	}

	public function subscription_create_page( $level_id, $args ) {

		$user_id 	= get_current_user_id();
		$plan		= rcp_get_subscription_details( $level_id );
		$plan_name	= $plan->name;
		$content 	= '[vc_row][vc_column width="1/4"][/vc_column][vc_column width="1/2"][vc_column_text][register_form id="{SUB_ID}"][/vc_column_text][/vc_column][vc_column width="1/4"][/vc_column][/vc_row]';
		$content 	= str_replace("{SUB_ID}", $level_id, $content);

		$page_args = array(
			"post_author"	=>	$user_id,
			"post_content"	=> 	$content,
			"post_title"	=>	$plan_name,
			"post_status"	=>	"publish",
			"post_type"		=>	"page",
			"comment_status"=>	"closed",
			"meta_input"	=>	array(
				"plan_id"		=>	$level_id,
				"plan_name"		=>	$plan_name,
			)
		);

		$page_id = wp_insert_post( $page_args, false );

		rcp_log( sprintf( 'Successfully created page for subscription #%d.', absint( $page_id ) ) );


	}

	public function subscription_create_modify_stripe_plan( $level_id, $args ){

		global $rcp_options, $rcp_levels_db;

		// get all subscription level info for this level
		$plan           = rcp_get_subscription_details( $level_id );
		$price          = round( $plan->price * rcp_stripe_get_currency_multiplier(), 0 );

		$interval       = $plan->duration_unit;
		$interval_count = $plan->duration;
		$name           = $plan->name;
		$plan_id 		= $this->generate_plan_id( $plan );
		$currency       = strtolower( rcp_get_currency() );
		$amount 		= $rcp_levels_db->get_meta( $level_id, 'per_member_price', true );

		if( (float) $plan->price <= 0  ) {
			rcp_log( sprintf( 'WOORCP: The membership level is free, so cannot create stripe plan. Plan # %s.', $level_id ) );
			return;
		}

		if( empty($amount) || $amount <= 0 ) {
			rcp_log( sprintf( 'WOORCP: The membership level %s does not have extra member price, exiting.', $level_id ) );
			return;
		}

		$additional_member_price = round(rcp_stripe_get_currency_multiplier() * $amount, 0);

		$create_plan = true;
		$s_plan = false;
		try {

			$s_plan = \Stripe\Plan::retrieve($plan_id);
			$plan_deleted = $s_plan->delete();
			$create_plan = true;
			rcp_log( sprintf( 'WOORCP: Successfully deleted subscription plan from stripe #%d.', absint( $plan_id ) ) );

			// delete product
			/*$product = \Stripe\Product::retrieve($s_plan->product);
			$product->delete();
			rcp_log( sprintf( 'WOORCP: Successfully deleted subscription product from stripe #%d.', absint( $plan_id ) ) );
			unset($product, $s_plan);*/

		} catch ( Exception $e ) {}

		if( $create_plan && $s_plan ) {

			$plan = \Stripe\Plan::create( array(
				"interval"       => $interval,
				"interval_count" => $interval_count,
				"currency"       => $currency,
				"id"             => $plan_id,
				"product"        => $s_plan->product,
				"billing_scheme" => "tiered",
				"tiers_mode"     => "graduated",
				"tiers"          => array(
					[
						"flat_amount"   =>  $price,
						"unit_amount"   =>  0,
						"up_to"         =>  1,
					],
					[
						"flat_amount"   =>  0,
						"unit_amount"   =>  $additional_member_price,
						"up_to"         =>  "inf"
					],
				)
			));

			rcp_log( sprintf( 'WOORCP: Successfully created subscription plan on stripe #%d.', absint( $plan->id ) ) );
		}

		rcp_log( sprintf( 'WOORCP: Successfully created subscription plan on stripe #%d.', absint( $plan->id ) ) );
		
	}


	private function generate_plan_id( $membership_level ) {

		$level_name = strtolower( str_replace( ' ', '', sanitize_title_with_dashes( $membership_level->name ) ) );
		$plan_id    = sprintf( '%s-%s-%s', $level_name, round($membership_level->price), $membership_level->duration . $membership_level->duration_unit );
		$plan_id    = preg_replace( '/[^a-z0-9_\-]/', '-', $plan_id );

		return $plan_id;

	}

	public function subscription_modify_group_empty_seats( $membership_level_id, $args ) {

		// Bail if using activate/deactivate row action.
		if ( ! empty( $_GET['rcp-action'] ) && in_array( $_GET['rcp-action'], array( 'activate_subscription', 'deactivate_subscription' ) ) ) {
			return;
		}

		// Bail if using activate/deactivate bulk action.
		if ( ! empty($_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-membershiplevels' ) ) {
			return;
		}

		if ( isset( $_POST['rcpga-group-seats-allow'] ) ) {
		rcpga_enable_level_group_accounts( $membership_level_id );
		} else {
			rcpga_disable_level_group_accounts( $membership_level_id );
		}

		if ( ! empty( $_POST['rcpga-group-seats'] ) ) {
			if( $_POST['rcpga-group-seats'] == 0 ) {
				rcpga_set_level_group_seats_allowed( $membership_level_id, absint( get_option( "woorcp_default_member_number", 9 ) ) );
			} else {
				rcpga_set_level_group_seats_allowed( $membership_level_id, absint( $_POST['rcpga-group-seats'] ) );
			}
		} else {
			rcpga_remove_level_seat_count( $membership_level_id );
		}
	}

}
