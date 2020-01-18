<?php
/**
 * Created by PhpStorm.
 * User: unaib
 * Date: 12/5/18
 * Time: 6:56 PM
 */


function woorcp_settings_page() {
	add_submenu_page( 'rcp-members', __("Aspire Settings", WOORC_LANG ), __("Aspire Settings", WOORC_LANG ),'manage_options', 'aspire-vet-settings', "woo_rc_admin_settings");
}
add_action('admin_menu', 'woorcp_settings_page', 991);

function woo_rc_admin_settings() {
    $woorcp_default_member_number = get_option("woorcp_default_member_number");
	$woorcp_additional_member_fee = get_option("woorcp_additional_member_fee");
	$woorcp_allowed_subscriptions = get_option("woorcp_allowed_subscriptions");
	$woorcp_corp_form             = get_option("woorcp_corp_form");
	$woorcp_corp_checkout_page    = get_option("woorcp_corp_checkout_page", 0);
	$woorcp_corp_profile_page     = get_option("woorcp_corp_profile_page", 0);
	$woorcp_corp_manage_group_page= get_option("woorcp_corp_manage_group_page", 0);

	$forms = GFAPI::get_forms();
	$levels = rcp_get_subscription_levels( "active" );

?>
<div class="wrap">
	<h2><?php _e( 'Aspire Settings', 'rcp-group-accounts' ); ?></h2>
    <form action="<?php echo admin_url("admin-post.php");?>" method="post">
        <table class="form-table">
            <tbody>
                <!-- <tr class="form-field">
                    <th>
                        <label for="woorcp-additional-member-fee"><?php _e("Additional Member Price", WOORC_LANG); ?></label>
                    </th>
                    <td>
                        <input type="number" name="woorcp_additional_member_fee" id="woorcp-additional-member-fee" value="<?php echo $woorcp_additional_member_fee; ?>" min="0" required pattern="^(\d+\.\d{1,2})|(\d+)$" style="width: 100px;">
                        <p class="description">The price for additional member in any group of any membership level.</p>
                    </td>
                </tr>
                 -->

                <tr class="form-field">
                    <th>
                        <label for="woorcp-default-member-groups"><?php _e("Number of Members", WOORC_LANG); ?></label>
                    </th>
                    <td>
                        <input type="number" name="woorcp_default_member_number" id="woorcp-default-member-groups" value="<?php echo $woorcp_default_member_number; ?>" min="0" required pattern="^(\d+\.\d{1,2})|(\d+)$" style="width: 100px;">
                        <p class="description">The default number of members in any group of any membership level. 1 – Group Lead & 8 Group Members.</p>
                    </td>
                </tr>

                <tr>
                    <th colspan="2">
                        <h3>Corporate Account Settings</h3>
                    </th>
                </tr>

                <?php if( !empty( $levels ) ) : ?>
                <tr>
                    <th>
                        <label for="woorcp_allowed_subscriptions"><?php _e("Allowed Subscriptions", WOORC_LANG); ?></label>
                    </th>
                    <td>
                        <select name="woorcp_allowed_subscriptions[]" id="woorcp_allowed_subscriptions" multiple>
                            <?php
                            foreach( $levels as $key => $level ) {
                                $selected = in_array( $level->id, $woorcp_allowed_subscriptions ) ? "selected" : "";
                                echo '<option '.$selected.' value="'.$level->id.'">'.$level->name.'</option>';
                            }
                            ?>
                        </select>
                        <p class="description">Please choose subscriptions which will show up on Corporate Sign up page.</p>
                    </td>
                </tr>
                <?php endif; ?>

                <tr>
                    <th>
                        <label for="woorcp_corp_form"><?php _e("Corporate Registration Form", WOORC_LANG); ?></label>
                    </th>
                    <td>
                        <select name="woorcp_corp_form" id="woorcp_corp_form">
                            <option value="">Please Select</option>
		                    <?php
		                    foreach( $forms as $key => $form ) {
			                    $selected = $form["id"] == $woorcp_corp_form ? "selected" : "";
			                    echo '<option '.$selected.' value="'.$form["id"].'">'.$form["title"].'</option>';
		                    }
		                    ?>
                        </select>
                        <p class="description">Please choose one from gravity forms.</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="woorcp_corp_profile_page"><?php _e("Corporate Profile Page", WOORC_LANG); ?></label>
                    </th>
                    <td>
	                    <?php
	                    $args = array(
		                    'depth'                 => 0,
		                    'child_of'              => 0,
		                    'selected'              => $woorcp_corp_profile_page,
		                    'echo'                  => 0,
		                    'name'                  => 'woorcp_corp_profile_page',
		                    'id'                    => "woorcp_corp_profile_page", // string
		                    'class'                 => null, // string
		                    'show_option_none'      => "Please Select Page", // string
		                    'show_option_no_change' => "", // string
		                    'option_none_value'     => "", // string
	                    );
	                    $pages = wp_dropdown_pages( $args );
	                    ?>
		                <?php echo $pages; ?>
                        <p class="description">Please choose page for Corporate Profile page.</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="woorcp_corp_manage_group_page"><?php _e("Corporate Manage Groups Page", WOORC_LANG); ?></label>
                    </th>
                    <td>
		                <?php
		                $args = array(
			                'depth'                 => 0,
			                'child_of'              => 0,
			                'selected'              => $woorcp_corp_manage_group_page,
			                'echo'                  => 0,
			                'name'                  => 'woorcp_corp_manage_group_page',
			                'id'                    => "woorcp_corp_manage_group_page", // string
			                'class'                 => null, // string
			                'show_option_none'      => "Please Select Page", // string
			                'show_option_no_change' => "", // string
			                'option_none_value'     => "", // string
		                );
		                $pages = wp_dropdown_pages( $args );
		                ?>
		                <?php echo $pages; ?>
                        <p class="description">Please choose page for Corporate Profile page.</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <label for="woorcp_corp_checkout_page"><?php _e("Corporate Checkout Page", WOORC_LANG); ?></label>
                    </th>
                    <td>
                        <?php
                        $args = array(
	                        'depth'                 => 0,
	                        'child_of'              => 0,
	                        'selected'              => $woorcp_corp_checkout_page,
	                        'echo'                  => 0,
	                        'name'                  => 'woorcp_corp_checkout_page',
	                        'id'                    => "woorcp_corp_checkout_page", // string
	                        'class'                 => null, // string
	                        'show_option_none'      => "Please Select Page", // string
	                        'show_option_no_change' => "", // string
	                        'option_none_value'     => "", // string
                        );
                        $pages = wp_dropdown_pages( $args );
                        ?>
                        <?php echo $pages; ?>
                        <p class="description">Please choose page for Corporate Checkout. This page must have <code>[corporate-checkout]</code> shortcode.</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="submit">
            <input type="hidden" name="action" value="woorcp_save_settings">
            <?php wp_nonce_field("woorcp_settings", "woorcp_settings_field") ?>
            <input type="submit" name="woorcp_save_settings" value="Update Settings" class="button-primary">
        </p>
    </form>
</div>
<?php
}

function woorcp_admin_save_settings() {

    if( isset($_POST["woorcp_save_settings"]) ) {
        update_option("woorcp_additional_member_fee", $_POST["woorcp_additional_member_fee"], "no");
	    update_option("woorcp_default_member_number", $_POST["woorcp_default_member_number"], "no");
	    update_option("woorcp_allowed_subscriptions", $_POST["woorcp_allowed_subscriptions"], "no");
	    update_option("woorcp_corp_form", $_POST["woorcp_corp_form"], "no");
	    update_option("woorcp_corp_checkout_page", $_POST["woorcp_corp_checkout_page"], "0");
	    update_option("woorcp_corp_profile_page", $_POST["woorcp_corp_profile_page"], "0");
	    update_option("woorcp_corp_manage_group_page", $_POST["woorcp_corp_manage_group_page"], "0");

	    wp_safe_redirect( add_query_arg("message", "woorcp_settings_updated", $_POST["_wp_http_referer"]), 301 );
	    exit;
    }
}
add_action( 'admin_post_woorcp_save_settings', 'woorcp_admin_save_settings' );


function woorcp_admin_save_settings_notice() {
    if( isset($_GET["message"]) && $_GET["message"] == "woorcp_settings_updated" ) {
	    $class = 'notice notice-success is-dismissible';
	    $message = __( 'Setting Updated', WOORC_LANG );
	    printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }
}
add_action( 'admin_notices', 'woorcp_admin_save_settings_notice' );

/**
 * Sets the number of group seats allowed for the specified subscription level.
 *
 * @param int $level_id The subscription level ID.
 * @param int $seats_allowed The number of seats allowed.
 */
function woorcp_set_level_group_seats_allowed( $level_id, $seats_allowed ) {

	global $rcp_levels_db;

	$woorcp_default_member_number = get_option("woorcp_default_member_number", 9);

	$rcp_levels_db->update_meta( $level_id, 'group_seats_allowed', absint( $woorcp_default_member_number ) );
}
//add_action("rcpga_set_level_group_seats_allowed", "woorcp_set_level_group_seats_allowed", 11, 2);