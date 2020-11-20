<?php

// syncing learndash group with RCP group and members

function woorcp_add_ld_group( $group_id, $data ) {

	$postarr = array(
		"post_type"		=>	"groups",
		"post_title"	=>	$data["name"],
		"post_content"	=>	$data["description"],
		"post_status"	=>	"publish",
		"meta_input"	=> array(
			"rcp_group_leader_id"	=>	$data["owner_id"],
			"rcp_group_seats"		=>	$data["seats"],
			"rcp_group_id"			=>	$group_id,
		)
	);

	$ld_group_id = wp_insert_post( $postarr, true );

	if( !is_wp_error( $ld_group_id ) ) {
		learndash_set_groups_administrators( $ld_group_id , (array) $data["owner_id"] );
	}

	$member 		= new RCP_Member( $data["owner_id"] );
	$level_id 		= $member->get_subscription_id();
	$ld_courses 	= get_metadata( 'level', $level_id, '_learndash_restrict_content_pro_courses', true );

	if ( !empty( $ld_courses ) ) {
		foreach ( $ld_courses as $course_id ) {
			ld_update_course_group_access( $course_id, $ld_group_id, false );
		}
	}

}
add_action( "rcpga_db_groups_post_insert", "woorcp_add_ld_group", 15, 2);


function woorcp_add_rcp_member_ld_group( $user_id, $args, $group_id ) {

	//$group_id = rcpga_group_accounts()->members->get_group_id( $user_id );
	$owner_id = rcpga_group_accounts()->groups->get_owner_id( $group_id );

	$args = array(
		'post_type'   		=> 'groups',
		'post_status' 		=> 'publish',
		'posts_per_page'	=> 1,
		'meta_key'       	=> 'rcp_group_leader_id',
		'meta_value' 		=> $owner_id,
	);
	
	$query = new WP_Query( $args );

	$member = new RCP_Member( $user_id );
	$member->set_role( 'subscriber' );

	if( $query->found_posts > 0 ) {
		$ld_group_id = $query->posts[0]->ID;

		ld_update_group_access( $user_id, $ld_group_id, false );
	}



}
add_action( "rcpga_add_member_to_group_after", "woorcp_add_rcp_member_ld_group", 15, 3 );



add_action( "init", "revamp_ld_group_courses");
function revamp_ld_group_courses() {
	if( !isset($_GET["woo_revamp"]) ) {
		return;
	}

	$groups = rcpga_get_groups(array(
		'number'	=>	1000
	));
	
	if( !empty($groups) ) {
		foreach ($groups as $group) {

			$group_id 			= $group->get_group_id();
			$owner_id 			= $group->get_owner_id();
			$membership 		= $group->get_membership();
			
			if( empty($membership) || !$membership ) {
				return;
			}

			$level_id 			= $membership->get_object_id();
			
			$level_ld_courses 	= get_metadata( 'level', $level_id, '_learndash_restrict_content_pro_courses', true );
			
			if ( empty( $level_ld_courses ) ) {
				return;
			}
			
			$members 			= $group->get_members(array(
				'role'	=>	'member'
			));


			$args = array(
				'post_type'   		=> 'groups',
				'post_status' 		=> 'publish',
				'posts_per_page'	=> 1,
				'meta_key'       	=> 'rcp_group_leader_id',
				'meta_value' 		=> $owner_id,
			);
			
			$query = new WP_Query( $args );

			if( $query->found_posts > 0 ) {
				$ld_group_id = $query->posts[0]->ID;

				foreach ( $level_ld_courses as $course_id ) {
					ld_update_course_group_access( $course_id, $ld_group_id, false );
				}

				if( !empty($members) ) {
					foreach ($members as $member) {
						ld_update_group_access( $member->get_user_id(), $ld_group_id, false );
					}
				}
			}
		}
	}
}


add_filter( "rcp_is_active", "filter_rcp_group_member_as_active", 999, 3 );
function filter_rcp_group_member_as_active( $ret, $user_id, $member ) {

	$group = rcpga_get_group_member_by_id( $user_id );

	if( !$group && !empty($group) ) {
		if( $group->is_active() ) {
			return true;
		}
	}

	return $ret;
}