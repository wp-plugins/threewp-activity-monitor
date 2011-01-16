<?php
/*                                                                                                                                                                                                                                                             
Plugin Name: ThreeWP Activity Monitor
Plugin URI: http://mindreantre.se/threewp-activity-monitor/
Description: Plugin to track user activity. Network aware.
Version: 1.2
Author: Edward Hevlund
Author URI: http://www.mindreantre.se
Author Email: edward@mindreantre.se
*/

if(preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) { die('You are not allowed to call this page directly.'); }

require_once('ThreeWP_Activity_Monitor_3Base.php');
class ThreeWP_Activity_Monitor extends ThreeWP_Activity_Monitor_3Base
{
	private $cache = array('user' => array(), 'blog' => array(), 'post' => array());
	
	protected $options = array(
		'activities_limit' => 100000,
		'activities_limit_view' => 100,
		'role_logins_view'	=>			'administrator',			// Role required to view own logins
		'role_logins_view_other' =>		'administrator',			// Role required to view other users' logins
		'role_logins_delete' =>			'administrator',			// Role required to delete own logins 
		'role_logins_delete_other' =>	'administrator',			// Role required to delete other users' logins
		'database_version' => 110,									// Version of database
	);
	
	public function __construct()
	{
		parent::__construct(__FILE__);
		register_activation_hook(__FILE__, array(&$this, 'activate') );
		register_deactivation_hook(__FILE__, array(&$this, 'deactivate') );
		
		add_action('admin_print_styles', array(&$this, 'admin_print_styles') );
		add_action('admin_menu', array(&$this, 'admin_menu') );
		
		add_action('threewp_activity_monitor_cron', array(&$this, 'cron') );

		add_filter('wp_login', array(&$this, 'wp_login'), 10, 3);						// Successful logins
		add_filter('wp_login_failed', array(&$this, 'wp_login_failed'), 10, 3);			// Login failures
		add_filter('wp_logout', array(&$this, 'wp_logout'), 10, 3);						// Logouts
		
		add_filter('user_register', array(&$this, 'user_register'), 10, 3);
		add_filter('profile_update', array(&$this, 'profile_update'), 10, 3);
		add_filter('wpmu_delete_user', array(&$this, 'delete_user'), 10, 3); 
		add_filter('delete_user', array(&$this, 'delete_user'), 10, 3); 
		
		add_filter('retrieve_password', array(&$this, 'retrieve_password'), 10, 3);		// Send password
		add_filter('password_reset', array(&$this, 'password_reset'), 10, 3);
		
		// Posts (and pages)
		add_action('transition_post_status', array(&$this, 'publish_post'), 10, 3);
		add_action('post_updated', array(&$this, 'post_updated'), 10, 3);
		add_action('trashed_post', array(&$this, 'trash_post'));
		add_action('untrash_post', array(&$this, 'untrash_post'));
		add_action('deleted_post', array(&$this, 'delete_post'));
		
		// Comments
		add_action('wp_set_comment_status', array(&$this, 'wp_set_comment_status'), 10, 3);
		
		add_action('threewp_activity_monitor_new_activity', array(&$this, 'action_new_activity'), 1, 1);		
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Callbacks
	// --------------------------------------------------------------------------------------------
	public function activate()
	{
		parent::activate();
		
		// If we are on a network site, make the site-admin the default role to access the functions.
		if ($this->is_network)
		{
			foreach(array('role_logins_view', 'role_logins_view_other', 'role_logins_delete', 'role_logins_delete_other') as $key)
				$this->options[$key] = 'super_admin';
		}
		
		$this->register_options();
		
		// v0.3 has an activity_monitor table. Not necessary anymore.		
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."activity_monitor`");

		wp_schedule_event(time() + 600, 'daily', 'threewp_activity_monitor_cron');
		
		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."_3wp_activity_monitor_index` (
				  `i_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Index ID',
				  `index_action` varchar(25) NOT NULL COMMENT 'What action was executed?',
				  `i_datetime` datetime NOT NULL,
				  `l_id` int(11) DEFAULT NULL COMMENT 'Login action ID',
				  `p_id` int(11) DEFAULT NULL COMMENT 'Post action ID',
				  `data` text COMMENT 'Misc data associated with the query at hand',
			  PRIMARY KEY (`i_id`),
			  KEY `index_action` (`index_action`)
			) ENGINE = MYISAM ;
		");

		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."_3wp_activity_monitor_logins` (
				  `l_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Login action ID',
				  `l_blog_id` int(11) NOT NULL COMMENT 'Blog ID',
				  `l_user_id` int(11) NOT NULL COMMENT 'User ID',
				  `remote_addr` varchar(15) NOT NULL COMMENT 'Remote addr of user',
				  `remote_host` text DEFAULT NULL COMMENT 'Remote host of user',
			  PRIMARY KEY (`l_id`)
			) ENGINE = MYISAM COMMENT = 'Login actions';
		");
		
		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."_3wp_activity_monitor_posts` (
				  `p_id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Row ID',
				  `blog_id` int(11) NOT NULL COMMENT 'Blog ID',
				  `post_id` int(11) NOT NULL COMMENT 'Post ID',
				  `comment_id` int(11) DEFAULT NULL COMMENT 'Comment ID',
				  `user_id` int(11) DEFAULT NULL COMMENT 'User ID',
			  PRIMARY KEY (`p_id`)
			) ENGINE = MYISAM COMMENT = 'Post actions';
		");
		
		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."_3wp_activity_monitor_user_statistics` (
			  `user_id` int(11) NOT NULL COMMENT 'User ID',
			  `key` varchar(100) NOT NULL,
			  `value` text NOT NULL,
			  KEY `key` (`key`),
			  KEY `user_id` (`user_id`)
			) ENGINE=MyISAM DEFAULT CHARSET=latin1;
		");
		
		if ($this->get_option('database_version') < 120)
		{
			// v1.2 serializes AND base64_encodes the data. So go through all the rows and encode what is necessary.
			$rows = $this->sql_index_list(array('limit' => 100000000));
			foreach($rows as $row)
			{
				$data = @unserialize($row['data']);
				if ( $data !== false)
					$this->query("UPDATE `".$this->wpdb->base_prefix."_3wp_activity_monitor_index` SET data = '". base64_encode(serialize($data))."' WHERE i_id = '".$row['i_id']."'");
			}
			$this->update_option('database_version', 120);
		}
	}
	
	public function deactivate()
	{
		parent::deactivate();
		wp_clear_scheduled_hook('threewp_activity_monitor_cron');
	}
	
	public function cron()
	{
		$this->sqlActivitiesCrop(array(
			'limit' => $this->get_option('activities_limit'),
		));
	}

	protected function uninstall()
	{
		$this->deregister_options();
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."_3wp_activity_monitor_index`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."_3wp_activity_monitor_logins`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."_3wp_activity_monitor_posts`");
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."_3wp_activity_monitor_user_statistics`");
	}
	
	public function admin_menu()
	{
		if ($this->role_at_least( $this->get_option('role_logins_view') ))
			add_filter('show_user_profile', array(&$this, 'show_user_profile'));
		if ($this->role_at_least( $this->get_option('role_logins_delete') ))
			add_filter('personal_options_update', array(&$this, 'personal_options_update'));

		if ($this->role_at_least( $this->get_option('role_logins_view_other') ))
			add_filter('edit_user_profile', array(&$this, 'show_user_profile'));
		if ($this->role_at_least( $this->get_option('role_logins_delete_other') ))
			add_filter('edit_user_profile_update', array(&$this, 'personal_options_update'));

		if ($this->role_at_least( $this->get_option('role_logins_view_other') ))
		{
			add_filter('manage_users_columns', array(&$this, 'manage_users_columns')); 
			add_filter('wpmu_users_columns', array(&$this, 'manage_users_columns')); 

			add_filter('manage_users_custom_column', array(&$this, 'manage_users_custom_column'), 10, 3);

			if ($this->is_network)
				add_submenu_page('ms-admin.php', __('Activity Monitor', 'ThreeWP_Activity_Monitor'), __('Activity Monitor', 'ThreeWP_Activity_Monitor'), 'read', 'ThreeWP_Activity_Monitor', array (&$this, 'admin'));
			else
				add_submenu_page('index.php', __('Activity Monitor', 'ThreeWP_Activity_Monitor'), __('Activity Monitor', 'ThreeWP_Activity_Monitor'), 'read', 'ThreeWP_Activity_Monitor', array (&$this, 'admin'));
		}
	}
	
	public function admin()
	{
		$this->loadLanguages('ThreeWP_Activity_Monitor');
		
		$tab_data = array(
			'tabs'		=>	array(),
			'functions' =>	array(),
		);
		
		$tab_data['tabs'][] = __('Overview', 'ThreeWP_Activity_Monitor');
		$tab_data['functions'][] = 'adminOverview';

		if ($this->role_at_least( $this->get_option('role_logins_delete_other') ))
		{
			$tab_data['tabs'][] = __('Settings', 'ThreeWP_Activity_Monitor');
			$tab_data['functions'][] = 'adminSettings';
	
			$tab_data['tabs'][] = __('Uninstall', 'ThreeWP_Activity_Monitor');
			$tab_data['functions'][] = 'admin_uninstall';
		}
		
		$this->tabs($tab_data);
	}
	
	public function admin_print_styles()
	{
		$load = false;
		if ( isset($_GET['page']) )
			$load |= strpos($_GET['page'],get_class()) !== false;

		foreach(array('profile.php', 'user-edit.php') as $string)
			$load |= strpos($_SERVER['SCRIPT_FILENAME'], $string) !== false;
		
		if (!$load)
			return;
		
		wp_enqueue_style('3wp_activity_monitor', '/' . $this->paths['path_from_base_directory'] . '/css/ThreeWP_Activity_Monitor.css', false, '1.0', 'screen' );
	}
	
	/**
		Logs the successful login of a user.
	*/
	public function wp_login($username)
	{
		$userdata = get_userdatabylogin($username);
		$this->sqlLoginSuccess($userdata->ID);
		
		// Updated the latest login time.
		$this->sqlStatsSet($userdata->ID, 'latest_login', $this->now());
	}
	
	/**
		Logs the unsuccessful login of a user.
	*/
	public function wp_login_failed($username)
	{
		$userdata = get_userdatabylogin($username);
		$this->sqlLoginFailure($userdata->ID, $_POST['pwd']);
	}
	
	/**
		Logs the logout of a user.
	*/
	public function wp_logout($username)
	{
		$userdata = get_userdatabylogin($username);
		$this->sqlLogout($userdata->ID);
	}
	
	public function retrieve_password($username)
	{
		$userdata = get_userdatabylogin($username);
		$this->sqlPasswordRetrieve($userdata->ID);
	}
	
	public function password_reset($userdata)
	{
		// Yes... this is the only action here that passes the whole user data, not just the name. *sigh*
		$this->sqlPasswordReset($userdata->ID);
	}
	
	public function profile_update($user_id, $old_userdata)
	{
		$new_userdata = get_userdata($user_id);		
		
		$changes = array();
		
		if ($old_userdata->user_pass != $new_userdata->user_pass)
			$changes['Password changed'] = '';
		
		if ($old_userdata->first_name != $new_userdata->first_name)
			$changes['First name changed'] = array($old_userdata->first_name, $new_userdata->first_name);
		
		if ($old_userdata->last_name != $new_userdata->last_name)
			$changes['Last name changed'] = array($old_userdata->last_name, $new_userdata->last_name);
		
		if ( count($changes) < 1 )
			return;
		
		global $current_user;
		get_currentuserinfo();
		$this->sqlLogIndex('profile_update', array(
			'data' => array(
				'user_id' => $current_user->ID,
				'user_id_target' => $user_id,
				'changes' => $changes
			),
		));
	}
	
	public function delete_user($user_id)
	{
		$userdata = get_userdata($user_id);		
		global $current_user;
		get_currentuserinfo();
		$this->sqlLogIndex('delete_user', array(
			'data' => array(
				'user_id' => $current_user->ID,
				'user_login' => $userdata->user_login,
				'user_email' => $userdata->user_email,
			),
		));
	}
	
	public function user_register($user_id)
	{
		$userdata = get_userdata($user_id);		
		global $current_user;
		get_currentuserinfo();
		$this->sqlLogIndex('user_register', array(
			'data' => array(
				'user_id' => $current_user->ID,
				'user_id_target' => $user_id,
			),
		));
	}
	
	public function publish_post($new_status, $old_status, $post)
	{
		if ($old_status == 'trash')
			return;
		if ($old_status == 'publish')
			return;
		
		if ( !$this->post_is_for_real($post) )
			return;

		$post_id = $post->ID;
		
		global $blog_id;

		global $current_user;
		get_currentuserinfo();

		$this->sqlPostPublish($current_user->ID, $blog_id, $post_id, array(
			'title' => $post->post_title,
		));
	}

	public function post_updated($post_id, $new_post, $old_post)
	{
		if ( !$this->post_is_for_real($old_post) )
			return;
		if ( !$this->post_is_for_real($new_post) )
			return;
			
		global $blog_id;

		global $current_user;
		get_currentuserinfo();

		$this->sqlPostUpdate($current_user->ID, $blog_id, $post_id, array(
			'title' => $new_post->post_title,
			'title_old' => $old_post->post_title,
		));
	}
	
	public function trash_post($post_id)
	{
		$post = get_post($post_id);

		global $blog_id;

		global $current_user;
		get_currentuserinfo();

		$this->sqlPostTrash($current_user->ID, $blog_id, $post_id, array(
			'title' => $post->post_title,
		));
	}
	
	public function untrash_post($post_id)
	{
		$post = get_post($post_id);
		
		global $blog_id;

		global $current_user;
		get_currentuserinfo();

		$this->sqlPostUntrash($current_user->ID, $blog_id, $post_id, array(
			'title' => $post->post_title,
		));
	}
	
	public function delete_post($post_id)
	{
		$post = get_post($post_id);
		
		if ( !$this->post_is_for_real($post) && $post->post_status != 'trash')
			return;

		global $blog_id;

		global $current_user;
		get_currentuserinfo();
		
		// This is Wordpress autocleaning trashed posts. No need to log it.
		if ( $current_user->ID < 1 )
			return;
		
		$this->sqlPostDelete($current_user->ID, $blog_id, $post_id, array(
			'title' => $post->post_title,
		));
	}
	
	public function wp_set_comment_status($comment_id, $status)
	{
		$comment = get_comment($comment_id);
		$post_id = $comment->comment_post_ID;
		$user_id = ($comment->user_id == 0 ? null : $comment->user_id);
		
		global $blog_id;

		global $current_user;
		get_currentuserinfo();

		// This is Wordpress autocleaning trashed comments. No need to log it.
		if ( $current_user->ID < 1 )
			return;
		
		$post = get_post($post_id);
		
		switch($status)
		{
			case '0':
				$status = 'pending';
			break;
			case '1':
				$status = 'reapprove';
			break;
			default:
			break;
		}

		$this->sqlCommentSet('comment_' . $status, $current_user->ID, $blog_id, $post_id, $comment_id, array(
			'post_title' => $post->post_title,
			'comment_user_id' => $user_id,
		)); 
	}
	
	public function adminOverview()
	{
		$count = $this->sql_index_list(array(
			'count' => true,
		));
		
		$per_page = $this->get_option('activities_limit_view');
		$max_pages = floor($count / $per_page);
		$page = (isset($_GET['paged']) ? $_GET['paged'] : 1);
		$page = $this->minmax($page, 1, $max_pages);
		$activities = $this->sql_index_list( array(
			'limit' => $per_page,
			'page' => ($page-1),
		));
		
		$page_links = paginate_links( array(
			'base' => add_query_arg( 'paged', '%#%' ),
			'format' => '',
			'prev_text' => __('&laquo;'),
			'next_text' => __('&raquo;'),
			'current' => $page,
			'total' => $max_pages,
		));
		
		if ($page_links)
			$page_links = '<div class="tablenav"><div class="tablenav-pages">' . $page_links . '</div></div>';
		
		echo $page_links;
		echo $this->show_activities($activities);		
		echo $page_links;
	}
	
	public function adminSettings()
	{
		// Collect all the roles.
		$roles = array();
		if ($this->is_network)
			$roles['super_admin'] = array('text' => 'Site admin', 'value' => 'super_admin');
		foreach($this->roles as $role)
			$roles[$role['name']] = array('value' => $role['name'], 'text' => ucfirst($role['name']));
			
		if (isset($_POST['3am_submit']))
		{
			$this->update_option( 'activities_limit', intval($_POST['activities_limit']) );
			$this->update_option( 'activities_limit_view', intval($_POST['activities_limit_view']) );
			
			$this->sqlActivitiesCrop(array(
				'limit' => $this->get_option( 'activities_limit' )
			));

			foreach(array('role_logins_view', 'role_logins_view_other', 'role_logins_delete', 'role_logins_delete_other') as $key)
				$this->update_option($key, (isset($roles[$_POST[$key]]) ? $_POST[$key] : 'administrator'));

			$this->message('Options saved!');
		}
		
		$form = $this->form();
		$count = $this->sql_index_list(array(
			'count' => true,
		));
			
		$inputs = array(
			'activities_limit' => array(
				'type' => 'text',
				'name' => 'activities_limit',
				'label' => __('Keep at most this amount of activities in the database', 'ThreeWP_Activity_Monitor'),
				'maxlength' => 10,
				'size' => 5,
				'value' => $this->get_option('activities_limit'),
				'validation' => array(
					'empty' => true,
				),
			),
			'activities_limit_view' => array(
				'type' => 'text',
				'name' => 'activities_limit_view',
				'label' => __('Display this amount of activities per page', 'ThreeWP_Activity_Monitor'),
				'maxlength' => 10,
				'size' => 5,
				'value' => $this->get_option('activities_limit_view'),
				'validation' => array(
					'empty' => true,
				),
			),
			'role_logins_view' => array(
				'name' => 'role_logins_view',
				'type' => 'select',
				'label' => 'View own login statistics',
				'value' => $this->get_option('role_logins_view'),
				'options' => $roles,
			),
			'role_logins_view_other' => array(
				'name' => 'role_logins_view_other',
				'type' => 'select',
				'label' => 'View other users\' login statistics',
				'value' => $this->get_option('role_logins_view_other'),
				'options' => $roles,
			),
			'role_logins_delete' => array(
				'name' => 'role_logins_delete',
				'type' => 'select',
				'label' => 'Delete own login statistics',
				'value' => $this->get_option('role_logins_delete'),
				'options' => $roles,
			),
			'role_logins_delete_other' => array(
				'name' => 'role_logins_delete_other',
				'type' => 'select',
				'label' => 'Delete other users\' login statistics and administer the plugin settings',
				'value' => $this->get_option('role_logins_delete_other'),
				'options' => $roles,
			),
		);
		
		$inputSubmit = array(
			'type' => 'submit',
			'name' => '3am_submit',
			'value' => __('Apply', 'ThreeWP_Activity_Monitor'),
			'cssClass' => 'button-primary',
		);
			
		$returnValue = '
			'.$form->start().'
			
			<h3>Database cleanup</h3>
			
			<p>
				There are currently '.$count.' activities in the database.
			</p>
			
			<p>
				'.$form->makeLabel($inputs['activities_limit']).' '.$form->makeInput($inputs['activities_limit']).'
			</p>
			
			<p>
				'.$form->makeLabel($inputs['activities_limit_view']).' '.$form->makeInput($inputs['activities_limit_view']).'
			</p>
			
			<h3>Roles</h3>
			
			<p>
				Actions can be restricted to specific user roles.
			</p>
			
			<p class="bigp">
				'.$form->makeLabel($inputs['role_logins_view']).' '.$form->makeInput($inputs['role_logins_view']).'
			</p>

			<p class="bigp">
				'.$form->makeLabel($inputs['role_logins_view_other']).' '.$form->makeInput($inputs['role_logins_view_other']).'
			</p>

			<p class="bigp">
				'.$form->makeLabel($inputs['role_logins_delete']).' '.$form->makeInput($inputs['role_logins_delete']).'
			</p>

			<p class="bigp">
				'.$form->makeLabel($inputs['role_logins_delete_other']).' '.$form->makeInput($inputs['role_logins_delete_other']).'
			</p>

			<p>
				'.$form->makeInput($inputSubmit).'
			</p>
			
			'.$form->stop().'
		';

		echo $returnValue;
	}
	
	public function show_user_profile($userdata)
	{
		$returnValue = '<h3>'.__('User activity', 'ThreeWP_Activity_Monitor').'</h3>';
		
		$login_stats = $this->sqlStatsList($userdata->ID);
		$login_stats = $this->array_moveKey($login_stats, 'key');
		$returnValue .= '
			<table class="widefat">
				<thead>
					<tr>
						<th>'.__('Latest login', 'ThreeWP_Activity_Monitor').'</th>
						<th>'.__('Successful logins', 'ThreeWP_Activity_Monitor').'</th>
						<th>'.__('Failed logins', 'ThreeWP_Activity_Monitor').'</th>
						<th>'.__('Retrieved passwords', 'ThreeWP_Activity_Monitor').'</th>
						<th>'.__('Reset passwords', 'ThreeWP_Activity_Monitor').'</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><span title="'.$login_stats['latest_login']['value'].'">'.$this->ago($login_stats['latest_login']['value']).'</span></td>
						<td>'.intval($login_stats['login_success']['value']).'</td>
						<td>'.intval($login_stats['login_failure']['value']).'</td>
						<td>'.intval($login_stats['password_retrieve']['value']).'</td>
						<td>'.intval($login_stats['password_reset']['value']).'</td>
					</tr>
				</tbody>
			</table>
			<br />
		';
		
		$logins = $this->sql_index_list(array(
			'user_id' => $userdata->ID
		));
		$returnValue .= $this->show_activities($logins);

		if ($this->role_at_least( $this->get_option('role_logins_delete') ))
		{
			$form = $this->form();
			
			// Make crop option
			$inputCrop = array(
				'type' => 'text',
				'name' => 'activity_monitor_activities_crop',
				'label' => __('Crop the activity list down to this amount of rows', 'ThreeWP_Activity_Monitor'),
				'value' => count($logins),
				'validation' => array(
					'empty' => true,
				),
			);
			$returnValue .= '<p>'.$form->makeLabel($inputCrop).' '.$form->makeInput($inputCrop).'</p>';

			// Make clear option
			$inputClear = array(
				'type' => 'checkbox',
				'name' => 'activity_monitor_index_delete',
				'label' => __('Clear the user\'s activity list', 'ThreeWP_Activity_Monitor'),
				'checked' => false,
			);
			$returnValue .= '<p>'.$form->makeInput($inputClear).' '.$form->makeLabel($inputClear).'</p>';
		}
		echo $returnValue;
	}
	
	public function personal_options_update($user_id)
	{
		if ( intval($_POST['activity_monitor_activities_crop']) > 0)
		{
			$crop_to = $_POST['activity_monitor_activities_crop'];
			$this->sqlActivitiesCrop(array(
				'limit' => $crop_to,
				'user_id' => $user_id,
			));
		}
	}

	public function manage_users_columns($defaults)
	{
		$defaults['3wp_activity_monitor'] = '<span title="'.__('Various login statistics about the user', 'ThreeWP_Activity_Monitor').'">'.__('Login statistics', 'ThreeWP_Activity_Monitor').'</span>';
		return $defaults;
	}
	
	public function manage_users_custom_column($p1, $p2, $p3 = '')
	{
		// echo is the variable that tells us whether we need to echo our returnValue. That's because wpmu... needs stuff to be echoed while normal wp wants stuff returned.
		// *sigh*
		
		if ($p3 == '')
		{
			$column_name = $p1;
			$user_id = $p2;
			$echo = true;
		}
		else
		{
			$column_name = $p2;
			$user_id = $p3;
			$echo = false;
		}
		
		$returnValue = '';
		
		$login_stats = $this->sqlStatsList($user_id);
		$login_stats = $this->array_moveKey($login_stats, 'key');

		if (count($login_stats) < 1)
		{
			$message = __('No login data available', 'ThreeWP_Activity_Monitor');
			if ($echo)
				echo $message;
			return $message;
		}
			
		$stats = array();
		
		// Translate the latest login date/time to the user's locale.
		if ($login_stats['latest_login'] != '')
			$stats[] = sprintf('<span title="%s: '.$login_stats['latest_login']['value'].'">' . $this->ago($login_stats['latest_login']['value']) . '</span>',
			__('Latest login', 'ThreeWP_Activity_Monitor')
			);
		
		$returnValue .= implode(' | ', $stats);
		
		if ($echo)
			echo $returnValue;
		return $returnValue;
	}
	
	public function action_new_activity($data)
	{
		global $current_user;
		global $blog_id;
		get_currentuserinfo();
		$user_id = $current_user->ID;				// Convenience
		$bloginfo_name = get_bloginfo('name');		// Convenience
		$bloginfo_url = get_bloginfo('url');		// Convenience
		$new_data = array();
		// Replace the keywords in the activity.
		foreach($data as $index => $text)
		{
			$replacements = array(
				'%user_id%' => $user_id,
				'%user_login%' => $current_user->user_login,
				'%user_login_with_link%' => $this->make_profile_link($user_id),
				'%user_display_name%' => $current_user->display_name,
				'%user_display_name_with_link%' => $this->make_profile_link($user_id, $current_user->display_name),
				'%blog_id%' => $blog_id,
				'%blog_name%' => $bloginfo_name,
				'%blog_link%' => $bloginfo_url,
				'%blog_panel_link%' => $bloginfo_url . '/wp-admin',
				'%blog_name_with_link%' => sprintf('<a href="%s">%s</a>', $bloginfo_url, $bloginfo_name),
				'%blog_name_with_panel_link%' => sprintf('<a href="%s">%s</a>', $bloginfo_url . '/wp-admin', $bloginfo_name),
			);
			foreach($replacements as $replace_this => $with_this)
			{
				$index = str_replace($replace_this, $with_this, $index);
				$text = str_replace($replace_this, $with_this, $text);
			}
			$new_data[$index] = $text;
		}
		$this->sqlLogIndex('action_new_activity', array(
			'data' => $new_data,
		));
	}

	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- Misc functions
	// --------------------------------------------------------------------------------------------
	private function cache_blog($target_blog_id)
	{
		global $blog_id;

		if ( isset($this->cache['blog'][$target_blog_id]) )
			return;
		
		if ($target_blog_id != $blog_id)
			switch_to_blog($target_blog_id);
		$this->cache['blog'][$target_blog_id] = array(
			'title' => get_bloginfo('title'),
			'url' => get_bloginfo('url'),
		);
		if ($target_blog_id != $blog_id)
			restore_current_blog(); 
	}
	
	private function cache_user($user_id)
	{
		if ( isset($this->cache['user'][$user_id]) )
			return;
		
		$this->cache['user'][$user_id] = get_userdata($user_id);

		if ( ! $this->cache['user'][$user_id] instanceof stdClass )
		{
			$this->cache['user'][$user_id] = new stdClass();
			$this->cache['user'][$user_id]->user_login = 'Wordpress';
		}
	}
	
	private function makeIP($login_data, $type = 'text1')
	{
		switch($type)
		{
			case 'text1':
				if ($login_data['remote_host'] != '')
					return $login_data['remote_host'] . ' ('.$login_data['remote_addr'].')';
				else
					return $login_data['remote_addr'];
			break;
			case 'text2':
				if ($login_data['remote_host'] != '')
					return $login_data['remote_host'] . ' / '.$login_data['remote_addr'];
				else
					return $login_data['remote_addr'];
			break;
			case 'html1':
				if ($login_data['remote_host'] != '')
					return '<span title="'.$login_data['remote_addr'].'">' . $login_data['remote_host'] . '</span>';
				else
					return $login_data['remote_addr'];
			break;
			case 'html2':
				if ($login_data['remote_host'] != '')
					return $login_data['remote_host'] . ' <span class="threewp_activity_monitor_sep">|</span> '.$login_data['remote_addr'];
				else
					return $login_data['remote_addr'];
			break;
		}
	}
	
	private function show_activities($activities)
	{
		$ago = true;			// Display the login time as "ago" or datetime?
		$tBody = '';
		foreach($activities as $activity)
		{
			$show = array(							// An array of info to show the user.
				'addr' => false,
				'user_agent' => false,
			);
			
			// Decide which user_id or blog_id to use. The one in the l_ table, or the one in the post table.
			$user_id = ($activity['user_id'] == '' ? $activity['l_user_id'] : $activity['user_id']);
			$blog_id = ($activity['blog_id'] == '' ? $activity['l_blog_id'] : $activity['blog_id']);
			
			if ($blog_id === null)
				$blog_id = 1;
			
			if ( $blog_id !== null )
				$this->cache_blog( $blog_id );
			if ( $user_id != '' )
				$this->cache_user( $user_id );
			
			$backend = '';
			if (is_super_admin())
			{
				$backend = sprintf('<span class="threewp_activity_monitor_overview_backend_link">[<a title="%s" href="%s">%s</a>]</span>',
						__("Go directly to the blog's backend", 'ThreeWP_Activity_Monitor'),
						$this->cache['blog'][$blog_id]['url'] . '/wp-admin',
						__('B', 'ThreeWP_Activity_Monitor')
				);
					
			}
			
			$strings = array(
				'user' => ($user_id > 0 ? $this->make_profile_link($user_id) : 'Wordpress'),
				'blog' => '<a href="'.$this->cache['blog'][$blog_id]['url'].'">'.$this->cache['blog'][$blog_id]['title'].'</a>',
				'activity_message' => $this->activity_message($activity['index_action']),
			);
			
			$tr_class = array('activity_monitor_action action_' . $activity['index_action']);
			$activity_strings = array();
			
			// Unserialize the data.
			$data = unserialize( base64_decode($activity['data']) );
			
			switch($activity['index_action'])
			{
				// The login table has a lot of common info, so we bunch together as many login-actions as possible.
				case 'login_failure':
				case 'login_success':
				case 'password_retrieve':
				case 'password_reset':
					$show['addr'] = true;
					$show['user_agent'] = true;
				case 'logout':
					$activity_strings[] = sprintf('%s %s %s %s',
						$strings['user'],
						$strings['activity_message'],
						$strings['blog'],
						$backend
						);
					if ( $activity['index_action'] == 'login_failure' && isset($data['password']) ) 
						$activity_strings[] = sprintf('<span class="threewp_activity_monitor_activity_info_key">%s</span> <span class="activity_info_data">%s</span>',
							__('Password tried:', 'ThreeWP_Activity_Monitor'),
							$data['password']
						);
				break;
				
				// And the post table, like the login table, also has a lot of common info that we can bunch together.
				case 'post_publish':
				case 'post_update':
				case 'trash_post':
				case 'untrash_post':
				case 'delete_post':
					$activity_strings[] = sprintf('%s %s <a href="%s">%s</a> %s %s %s',
						$strings['user'],
						$strings['activity_message'],
						$this->cache['blog'][$blog_id]['url'] . '/?p=' . $activity['post_id'],
						$data['title'],
						__('on', 'ThreeWP_Activity_Monitor'),
						$strings['blog'],
						$backend
					);
				break;

				// The comment table shares the post table, but the string looks different.
				case 'comment_pending':
				case 'comment_hold':
				case 'comment_approve':
				case 'comment_reapprove':
				case 'comment_spam':
				case 'comment_unspam':
				case 'comment_trash':
				case 'comment_untrash':
				case 'comment_delete':
					$activity_strings[] = sprintf('%s %s <a href="%s">%s</a> %s <a href="%s">%s</a> %s %s %s',
						//                                                   for                    on    backend
						$strings['user'],

						$strings['activity_message'],

						$this->cache['blog'][$blog_id]['url'] . '/?p=' . $activity['post_id'] . '#comment-' . $activity['comment_id'],
						__('comment #', 'ThreeWP_Activity_Monitor') . $activity['comment_id'],

						__('for the post', 'ThreeWP_Activity_Monitor'),
						$this->cache['blog'][$blog_id]['url'] . '/?p=' . $activity['post_id'],
						$data['post_title'],
						
						__('on', 'ThreeWP_Activity_Monitor'),
						$strings['blog'],
						$backend
					);
				break;
				
				case 'user_register':
					$activity_strings[] = sprintf('%s %s %s',
						$this->make_profile_link( $data['user_id'] ),
						$strings['activity_message'],
						$this->make_profile_link( $data['user_id_target'] )
					);
				break;
				case 'profile_update':
					$activity_strings[] = sprintf('%s %s %s',
						$this->make_profile_link( $data['user_id'] ),
						$strings['activity_message'],
						$this->make_profile_link( $data['user_id_target'] )
					);
					
					$change_string = '<ul>';
					foreach( $data['changes'] as $change_key=>$change_data )
					{
						$change_string .= '<li>';
						$change_string .= $this->change_message($change_key, $change_data);
						$change_string .= '</li>';
					}
					$change_string .= '</ul>';

					$activity_strings[] .= $change_string;
				break;
				case 'delete_user':
					$activity_strings[] = sprintf('%s %s %s',
						$this->make_profile_link( $data['user_id'] ),
						$strings['activity_message'],
						$data['user_login'] . ' <span class="sep">/</span> ' . '<a href="mailto:'.$data['user_email'].'">' . $data['user_email'] . '</a>'
					);
				break;
				case 'action_new_activity':
					foreach ($data['activity'] as $data_key => $data_value)
						$activity_strings[] = sprintf('<span class="threewp_activity_monitor_activity_info_key">%s</span> <span class="activity_info_data">%s</span>', trim($data_key), $data_value);
					if (isset($data['tr_class']))
						$tr_class[] = $data['tr_class'];
				break;
			}
			
			foreach($show as $key => $value)
			{
				if ( $value !== true )
					continue;
				
				switch($key)
				{
					case 'addr':
						$activity_strings[] = sprintf('<span class="threewp_activity_monitor_activity_info_key">%s</span> <span class="activity_info_data">%s</span>',
							__('Address:', 'ThreeWP_Activity_Monitor'),
							$this->makeIP($activity, 'html2')
							);
					break;
					case 'user_agent':
						$activity_strings[] = sprintf('<span class="threewp_activity_monitor_activity_info_key">%s</span> <span class="activity_info_data">%s</span>',
							__('Web browser:', 'ThreeWP_Activity_Monitor'),
							$data['user_agent']
							);
					break;
				}
			}
			
			if ($ago)
			{
				$loginTime = strtotime( $activity['i_datetime'] );
				if (time() - $loginTime > 60*60*24)		// Older than 24hrs and we can display the datetime normally.
					$ago = false;
				$time = '<span title="'.$activity['i_datetime'].'">'. $this->ago($activity['i_datetime']) .'</span>';
			}
			else
				$time = $activity['i_datetime'];

			$tBody .= '
				<tr class="'.implode(' ', $tr_class).'">
					<td class="activity_monitor_action_time">'.$time.'</td>
					<td class="activity_monitor_action"><div>'.implode('</div><div>', $activity_strings).'</div></td>
				</tr>
			';
		}
		
		return '
			<table class="widefat threewp_activity_monitor">
				<thead>
					<tr>
						<th>'.__('Time', 'ThreeWP_Activity_Monitor').'</th>
						<th>'.__('Activity', 'ThreeWP_Activity_Monitor').'</th>
					</tr>
				</thead>
				<tbody>
					'.$tBody.'
				</tbody>
			</table>
		';
	}
	
	private function activity_message($type)
	{
		switch($type)
		{
			case 'login_success':
				return __('logged in to', 'ThreeWP_Activity_Monitor');
			case 'login_failure':
				return __('tried to log in to', 'ThreeWP_Activity_Monitor');
			case 'logout':
				return __('logged out from', 'ThreeWP_Activity_Monitor');
			case 'password_retrieve':
				return __('retrieved a password reset link from', 'ThreeWP_Activity_Monitor');
			case 'password_reset':
				return __('reset his password on', 'ThreeWP_Activity_Monitor');
				
			case 'post_publish':
				return __('posted', 'ThreeWP_Activity_Monitor');
			break;
			case 'post_update':
				return __('updated', 'ThreeWP_Activity_Monitor');
			break;
			case 'trash_post':
				return __('trashed', 'ThreeWP_Activity_Monitor');
			break;
			case 'untrash_post':
				return __('restored', 'ThreeWP_Activity_Monitor');
			break;
			case 'delete_post':
				return __('deleted', 'ThreeWP_Activity_Monitor');
			break;
			
			case 'comment_pending':
				return __('requeued', 'ThreeWP_Activity_Monitor');
			break;
			case 'comment_approve':
				return __('approved', 'ThreeWP_Activity_Monitor');
			break;
			case 'comment_reapprove':
				return __('reapproved', 'ThreeWP_Activity_Monitor');
			break;
			case 'comment_hold':
				return __('held back', 'ThreeWP_Activity_Monitor');
			break;
			case 'comment_spam':
				return __('spam marked', 'ThreeWP_Activity_Monitor');
			break;
			case 'comment_unspam':
				return __('unspammed', 'ThreeWP_Activity_Monitor');
			break;
			case 'comment_trash':
				return __('trashed', 'ThreeWP_Activity_Monitor');
			break;
			case 'comment_untrash':
				return __('restored', 'ThreeWP_Activity_Monitor');
			break;
			case 'comment_delete':
				return __('deleted', 'ThreeWP_Activity_Monitor');
			break;
			
			case 'user_register':
				return __('has created the user', 'ThreeWP_Activity_Monitor');
			break;
			case 'profile_update':
				return __('has updated the profile of', 'ThreeWP_Activity_Monitor');
			break;
			case 'delete_user':
				return __('has deleted the user', 'ThreeWP_Activity_Monitor');
			break;
		}
	}
	
	private function change_message($change_type, $change_data)
	{
		switch ($change_type)
		{
			case 'Password changed':
				return __('Password changed', 'ThreeWP_Activity_Monitor');
			break;
			case 'First name changed':
				return sprintf( __('First name changed from <em>"%s"</em> to <em>"%s"</em>', 'ThreeWP_Activity_Monitor'),
					$change_data[0],
					$change_data[1]
				);
			break;
			case 'Last name changed':
				return sprintf( __('Last name changed from <em>"%s"</em> to <em>"%s"</em>', 'ThreeWP_Activity_Monitor'),
					$change_data[0],
					$change_data[1]
				);
			break;
		}
	}
	
	private function post_is_for_real($post)
	{
		// Posts must be published and the parent must be 0 (meaning no autosaves)
		return $post->post_status == 'publish' && $post->post_parent == 0;
	}
	
	private function make_profile_link($user_id, $text = "")
	{
		$this->cache_user($user_id);
		if ($text == "")
			$text = $this->cache['user'][$user_id]->user_login;
		
		return '<a href="user-edit.php?user_id='.$user_id.'">'. $text .'</a>';
	}
	
	// --------------------------------------------------------------------------------------------
	// ----------------------------------------- SQL
	// --------------------------------------------------------------------------------------------
	private function sqlLoginSuccess($user_id)
	{
		$this->sqlLoginLog($user_id, 'login_success');
	}
	
	private function sqlLoginFailure($user_id, $password)
	{
		$this->sqlLoginLog($user_id, 'login_failure', array(
			'password' => $password,
		));
	}

	private function sqlLogout($user_idd)
	{
		global $current_user;
		get_currentuserinfo();
		$this->sqlLoginLog($current_user->ID, 'logout');
	}

	private function sqlPasswordRetrieve($user_id)
	{
		$this->sqlLoginLog($user_id, 'password_retrieve');
	}

	private function sqlPasswordReset($user_id)
	{
		$this->sqlLoginLog($user_id, 'password_reset');
	}

	private function sqlPasswordChanged($user_id)
	{
	}

	private function sqlLoginLog($user_id, $action, $data = array())
	{
		global $blog_id;
		
		if ($user_id == 0)
			return;
		
		$data = array_merge(array(
			'user_agent' => $_SERVER['HTTP_USER_AGENT'],
		), $data);
		
		$l_id = $this->query_insert_id("INSERT INTO `".$this->wpdb->base_prefix."_3wp_activity_monitor_logins` (l_blog_id, l_user_id, remote_addr, remote_host) VALUES
			('".$blog_id."', '".$user_id."', '".$_SERVER['REMOTE_ADDR']."', '".$_SERVER['REMOTE_HOST']."')");
			
		$this->sqlLogIndex($action, array(
			'l_id' => $l_id,
			'data' => $data,
		));
		
		$this->sqlStatsIncrement($user_id, $action);
	}
	
	private function sqlStatsIncrement($user_id, $action)
	{
		$this->sqlStatsSet($user_id, $action, intval($this->sqlStatsGet($user_id, $action)) + 1);
	}
	
	private function sqlStatsGet($user_id, $key)
	{
		$result = $this->query("SELECT value FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_user_statistics` WHERE `user_id` = '".$user_id."' AND `key` = '".$key."'");
		if (count($result) < 1)
			return null;
		else
			return $result[0]['value'];
	}
	
	private function sqlStatsSet($user_id, $key, $value)
	{
		if ($this->sqlStatsGet($user_id, $key) === null)
		{
			$this->query("INSERT INTO `".$this->wpdb->base_prefix."_3wp_activity_monitor_user_statistics` (`user_id`, `key`, `value`) VALUES
				('".$user_id."', '".$key."', '".$value."')");
		}
		else
		{
			$this->query("UPDATE `".$this->wpdb->base_prefix."_3wp_activity_monitor_user_statistics`
				SET `value` = '".$value."'
				WHERE `user_id` = '".$user_id."'
				AND `key` = '".$key."'
			");
		}
	}
	
	private function sqlStatsList($user_id)
	{
		return $this->query("SELECT `key`, `value` FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_user_statistics` WHERE `user_id` = '".$user_id."'");
	}
	
	private function sqlLogIndex($action, $options)
	{
		$options = array_merge(array(
			'l_id' => null,
			'p_id' => null,
			'data' => array(),
		), $options);
		
		$data = $options['data'];
		// Remove unused keys from the data array
		foreach($data as $key => $value)
			if ($value === null)
				unset( $data[$key] );
		if ( count($data) < 1 )
			$data = null;
		else
			$data = base64_encode( serialize( $data) );
		$options['data'] = $data;
		
		foreach(array('l_id', 'p_id', 'data') as $key)
			$options[$key] = ($options[$key] === null ? 'null' : "'" . $options[$key] . "'");
		
		$this->query("INSERT INTO `".$this->wpdb->base_prefix."_3wp_activity_monitor_index` (index_action, i_datetime, l_id, p_id, data) VALUES
		 	('".$action."', '".$this->now()."', ".$options['l_id'].", ".$options['p_id'].", ".$options['data'].")
		 ");
	}
	
	private function sql_index_list($options)
	{
		$options = array_merge(array(
			'limit' => 1000,
			'count' => false,
			'page' => 0,
			'select' => '*',
			'user_id' => null,
		), $options);

		$select = ($options['count'] ? 'count(*) as ROWS' : $options['select']);
		
		if ($options['page'] > 0)
		{
			$options['page'] = $options['page'] * $options['limit'];
		}

		$query = ("SELECT ".$select." FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_index`
			LEFT OUTER JOIN `".$this->wpdb->base_prefix."_3wp_activity_monitor_logins` USING (l_id)
			LEFT OUTER JOIN `".$this->wpdb->base_prefix."_3wp_activity_monitor_posts` USING (p_id)
			WHERE 1 = 1
			".($options['user_id'] !== null ? "AND (user_id = '".$options['user_id']."' OR l_user_id = '".$options['user_id']."')" : '')."
			ORDER BY i_datetime DESC
			".(isset($options['limit']) ? "LIMIT ".$options['page'].",".$options['limit']."" : '')."
		 ");
		 
		 $result = $this->query($query);
		 
		 if ($options['count'])
		 	$result = $result[0]['ROWS'];
		 
		 return $result;
	}
	
	private function sqlPostPublish($user_id, $blog_id, $post_id, $data)
	{
		$this->sqlPostLog('post_publish', array(
			'user_id' => $user_id,
			'blog_id' => $blog_id,
			'post_id' => $post_id,
			'data' => $data,
		));
	}
	
	private function sqlPostUpdate($user_id, $blog_id, $post_id, $data)
	{
		$this->sqlPostLog('post_update', array(
			'user_id' => $user_id,
			'blog_id' => $blog_id,
			'post_id' => $post_id,
			'data' => $data,
		));
	}
	
	private function sqlPostTrash($user_id, $blog_id, $post_id, $data)
	{
		$this->sqlPostLog('trash_post', array(
			'user_id' => $user_id,
			'blog_id' => $blog_id,
			'post_id' => $post_id,
			'data' => $data,
		));
	}
	
	private function sqlPostUntrash($user_id, $blog_id, $post_id, $data)
	{
		$this->sqlPostLog('untrash_post', array(
			'user_id' => $user_id,
			'blog_id' => $blog_id,
			'post_id' => $post_id,
			'data' => $data,
		));
	}
	
	private function sqlPostDelete($user_id, $blog_id, $post_id, $data)
	{
		$this->sqlPostLog('delete_post', array(
			'user_id' => $user_id,
			'blog_id' => $blog_id,
			'post_id' => $post_id,
			'data' => $data,
		));
	}
	
	private function sqlCommentSet($status, $user_id, $blog_id, $post_id, $comment_id, $data)
	{
		$this->sqlPostLog($status, array(
			'user_id' => $user_id,
			'blog_id' => $blog_id,
			'post_id' => $post_id,
			'comment_id' => $comment_id,
			'data' => $data,
		));
	}
	
	private function sqlPostLog($action, $options)
	{
		$options = array_merge(array(
			'blog_id' => null,
			'user_id' => null,
			'post_id' => null,
			'comment_id' => null,
			'data' => null,
		), $options);
		
		foreach(array('blog_id', 'user_id', 'post_id', 'comment_id') as $key)
			$options[$key] = ($options[$key] === null ? 'null' : "'" . $options[$key] . "'");
		
		$query = "INSERT INTO `".$this->wpdb->base_prefix."_3wp_activity_monitor_posts` (user_id, blog_id, post_id, comment_id) VALUES
		 	(".$options['user_id'].", 
		 	".$options['blog_id'].", 
		 	".$options['post_id'].", 
		 	".$options['comment_id']."
		 	)
		";
		
		$p_id = $this->query_insert_id($query);
		
		$this->sqlLogIndex($action, array(
			'p_id' => $p_id,
			'data' => $options['data'],
		));
	}
	
	private function sqlActivitiesCrop($options)
	{
		$options = array_merge(array(
			'user_id' => null,
		), $options);
		
		$rows = $this->sql_index_list(array(
			'user_id' => $options['user_id'],
			'limit' => ($options['user_id'] !== null ? null : $options['limit']),
			'select' => 'i_id, l_id, p_id',
		));
		
		if ($options['user_id'] !== null)
		{
			for($counter=0; $counter < $options['limit']; $counter++)
				array_shift($rows);

			$rows_to_delete = array(
				'i_id' => array(),
				'l_id' => array(),
				'p_id' => array(),
			);
			foreach($rows as $row)
				foreach($rows_to_delete as $key => $ignore)
					if ($row[$key] != '')
						$rows_to_delete[$key][] = $row[$key];
						
			$query = "DELETE FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_index` WHERE i_id IN ('".(implode("', '", $rows_to_delete['i_id']))."')";
			$this->query($query);
			$query = "DELETE FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_logins` WHERE l_id IN ('".(implode("', '", $rows_to_delete['l_id']))."')";
			$this->query($query);
			$query = "DELETE FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_posts` WHERE p_id IN ('".(implode("', '", $rows_to_delete['p_id']))."')";
			$this->query($query);
			return;
		}
		
		$rows = $this->array_moveKey($rows, 'i_id');
		
		$rows_to_keep = array(
			'i_id' => array(),
			'l_id' => array(),
			'p_id' => array(),
		);
		foreach($rows as $row)
			foreach($rows_to_keep as $key => $ignore)
				if ($row[$key] != '')
					$rows_to_keep[$key][] = $row[$key];
		
		$query = "DELETE FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_index` WHERE i_id NOT IN ('".(implode("', '", $rows_to_keep['i_id']))."')";
		$this->query($query);
		$query = "DELETE FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_logins` WHERE l_id NOT IN ('".(implode("', '", $rows_to_keep['l_id']))."')";
		$this->query($query);
		$query = "DELETE FROM `".$this->wpdb->base_prefix."_3wp_activity_monitor_posts` WHERE p_id NOT IN ('".(implode("', '", $rows_to_keep['p_id']))."')";
		$this->query($query);
	}
}
$threewp_activity_monitor = new ThreeWP_Activity_Monitor();
?>