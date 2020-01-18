<?php
/**
 * Plugin Name: WooNinjas Aspire Tweaks
 * Plugin URL: https://wooninjas.com
 * Description: RCPro Tweaks by WooNinjas
 * Version: 1.0.0
 * Author: WooNinjas
 * Author URI: https://wooninjas.com
 */

/**
 * Main WOO_RCP_TWEAKS Class
 *
 * @since 1.0
 */
class WOO_RCP_TWEAKS {

    /** Singleton ************************************************************/

    /**
     * @var WOO_RCP_TWEAKS The one true WOO_RCP_TWEAKS
     * @since 1.0
     */
    private static $instance;

    /**
     * Main RCPGA_Group_Accounts Instance
     *
     * Insures that only one instance of RCPGA_Group_Accounts exists in memory at any one
     * time. Also prevents needing to define globals all over the place.
     *
     * @since 1.0
     * @static var array $instance
     * @uses RCPGA_Group_Accounts::constants() Setup the plugin constants
     * @uses RCPGA_Group_Accounts::includes() Include the required files
     * @return RCPGA_Group_Accounts
     */
    public static function instance() {
        if ( ! isset( self::$instance ) && ! ( self::$instance instanceof WOO_RCP_TWEAKS ) ) {
            self::$instance = new WOO_RCP_TWEAKS;
            self::$instance->constants();
            self::$instance->includes();
	        self::$instance->hooks();

        }
        return self::$instance;
    }

    /**
     * Setup plugin constants
     *
     * @access private
     * @since 1.0
     * @return void
     */
    private function constants() {

	    if ( ! defined( 'WOORC_FILE' ) ) {
		    define( 'WOORC_FILE', __FILE__);
	    }

	    if ( ! defined( 'WOORC_LANG' ) ) {
		    define( 'WOORC_LANG', "woo-aspire-rc" );
	    }

	    // Plugin Folder Path
        if ( ! defined( 'WOORC_PLUGIN_DIR' ) ) {
            define( 'WOORC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        }

        // Plugin Folder URL
        if ( ! defined( 'WOORC_PLUGIN_URL' ) ) {
            define( 'WOORC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
        }

	    if ( ! defined( 'WOORC_PLUGIN_ASSETS' ) ) {
		    define( 'WOORC_PLUGIN_ASSETS', plugin_dir_url( __FILE__ ) . "assets" );
	    }

        // Plugin Root File
        if ( ! defined( 'WOORC_PLUGIN_FILE' ) ) {
            define( 'WOORC_PLUGIN_FILE', __FILE__ );
        }
    }

    /**
     * Include required files
     *
     * @access private
     * @since 1.0
     * @return void
     */
    private function includes() {

        require_once WOORC_PLUGIN_DIR . 'includes/admin/admin-settings.php';

        require_once WOORC_PLUGIN_DIR . 'includes/admin/extra-options.php';

        require_once WOORC_PLUGIN_DIR . 'includes/group-member-tweaks.php';

	    require_once WOORC_PLUGIN_DIR . 'includes/corporate/corporate.php';

        require_once WOORC_PLUGIN_DIR . 'includes/ld-options.php';

    }

	/**
	 * inline hooks
	 *
	 * @access private
	 * @since 1.0
	 * @return void
	 */
    private function hooks() {
	    add_action( 'wp_enqueue_scripts', array( $this, 'getWooRCPScripts'), 99 );
    }

    public function getWooRCPScripts() {

	    global $rcp_options;

	    wp_enqueue_style("woorcp-style", WOORC_PLUGIN_ASSETS."/css/app.css", array(), time());

	    wp_enqueue_script("stripe-js-3", "https://js.stripe.com/v3/", array(), "1.0.0", true);
	    wp_enqueue_script("woo-stripe-js", WOORC_PLUGIN_ASSETS."/js/app.js", array("stripe-js-3"), time(), true);

	    $test_mode = rcp_is_sandbox();

	    if( $test_mode ) {
		    $secret_key      = isset( $rcp_options['stripe_test_secret'] )      ? trim( $rcp_options['stripe_test_secret'] )      : '';
		    $publishable_key = isset( $rcp_options['stripe_test_publishable'] ) ? trim( $rcp_options['stripe_test_publishable'] ) : '';
	    } else {
		    $secret_key      = isset( $rcp_options['stripe_live_secret'] )      ? trim( $rcp_options['stripe_live_secret'] )      : '';
		    $publishable_key = isset( $rcp_options['stripe_live_publishable'] ) ? trim( $rcp_options['stripe_live_publishable'] ) : '';
	    }

	    $script_obj = [
	    	"stripe_key"=>$publishable_key,
		    "woorcp_corp_form_id"   => get_option("woorcp_corp_form", 7)
	    ];
	    wp_localize_script("woo-stripe-js", "woorcp_obj", $script_obj );

    }

}

if (!function_exists("dd")) {
    function dd($data, $exit_data = true)
    {
        echo '<pre>' . print_r($data, true) . '</pre>';
        if ($exit_data == false) {
            echo '';
        } else {
            exit;
        }
    }
}


/* Function which remove Plugin Update Notices */
function disable_plugin_updates( $values ) {
    if( !empty($values) ) {

        foreach($values as $value) {

            if( isset( $value->response['gravityperks/gravityperks.php'] ) ) {
                unset( $value->response['gravityperks/gravityperks.php'] );
            }

            if( isset( $value->response['gp-nested-forms/gp-nested-forms.php'] ) ) {
                unset( $value->response['gp-nested-forms/gp-nested-forms.php'] );
            }

        }
    }
    return $values;
}
add_filter( 'site_transient_update_plugins', 'disable_plugin_updates' );

/**
 * Load the the class object after other plugins are loaded
 *
 * @since 1.0
 * @access public
 * @return void
 */
function wooninja_rcp() {

    if( ! function_exists( 'rcp_is_active' ) ) {
        return false;
    }

    return WOO_RCP_TWEAKS::instance();

}
add_action( 'plugins_loaded', 'wooninja_rcp' );

function woorcp_plugin_activation() {
	do_action( 'woorc_plugin_activated' );
}
register_activation_hook( __FILE__, 'woorcp_plugin_activation' );

function woorcp_add_user_roles() {
	add_role( 'rcp_corporate', 'Corporate',	array( 'read' => true ) );
}
add_action("woorc_plugin_activated", "woorcp_add_user_roles");