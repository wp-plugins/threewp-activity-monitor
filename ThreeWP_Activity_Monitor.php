<?php
/*                                                                                                                                                                                                                                                             
Plugin Name: ThreeWP Activity Monitor
Plugin URI: http://mindreantre.se/threewp-activity-monitor/
Description: WPMU sitewide plugin to display sitewide blog activity.
Version: 0.0.2
Author: Edward Hevlund
Author URI: http://www.mindreantre.se
Author Email: edward@mindreantre.se
*/

if(preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) { die('You are not allowed to call this page directly.'); }

require_once('ThreeWP_Base_Activity_Monitor.php');
class ThreeWP_Activity_Monitor extends ThreeWP_Base_Activity_Monitor
{
	private $cache = array('user' => array(), 'blog' => array(), 'post' => array());
	
	protected $options = array(
		'limit' => 1000,
	);

	public function __construct()
	{
		parent::__construct(__FILE__);
		register_activation_hook(__FILE__, array(&$this, 'activate') );
		if ($this->isWPMU)
		{
			add_action('admin_menu', array(&$this, 'add_menu') );
			add_action('wp_login', array(&$this, 'wp_login') );
			add_action('publish_post', array(&$this, 'publish_post'));
			add_action('comment_post', array(&$this, 'comment_post'), 10, 2);
			add_action('wp_set_comment_status', array(&$this, 'comment_post'), 10, 2);
			add_action('admin_print_styles', array(&$this, 'load_styles') );
		}
	}
	
	public function activate()
	{
		parent::activate();
		
		if (!$this->isWPMU)
			wp_die("This plugin requires WPMU.");
			
		$this->register_options();
			
		$this->query("CREATE TABLE IF NOT EXISTS `".$this->wpdb->base_prefix."activity_monitor` (
		  `am_id` int(11) NOT NULL AUTO_INCREMENT,
		  `am_datetime_created` datetime NOT NULL COMMENT 'When this update was created',
		  `blog_id` int(11) NOT NULL COMMENT 'Blog ID',
		  `user_id` int(11) DEFAULT NULL COMMENT 'Activity created by',
		  `post_id` int(11) DEFAULT NULL COMMENT 'ID of newly created post',
		  `comment_id` int(11) DEFAULT NULL COMMENT 'Comment ID',
		  PRIMARY KEY (`am_id`),
		  KEY `am_datetime_created` (`am_datetime_created`)
		) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1;");
	}
	
	public function add_menu()
	{
		if (is_site_admin())
			add_submenu_page('wpmu-admin.php', 'ThreeWP Activity Monitor', 'Activity Monitor', 'administrator', 'ThreeWP_Activity_Monitor', array (&$this, 'admin'));
	}
	
	public function load_styles()
	{
		if ($_GET['page'] == get_class())
			wp_enqueue_style('3wp_activity_monitor', '/' . $this->paths['path_from_base_directory'] . '/css/ThreeWP_Activity_Monitor.css', false, '0.0.1', 'screen' );
	}
	
	public function admin()
	{
		$this->tabs(array(
			'tabs' =>		array('ThreeWP Activity Monitor',	'Settings',			'Uninstall'),
			'functions' =>	array('adminActivityMonitor',		'adminSettings',	'adminUninstall'),
		));
	}
	
	protected function adminActivityMonitor()
	{	
		$dateFormat = get_option('date_format') . ' ' . get_option('time_format');
		
		$activities = $this->sqlActivities();
		
		$limit = $this->get_option('limit');
		$activities = $this->pruneActivities($activities, $limit);
		
		$gmt_offset = get_option('gmt_offset') * 3600;
		
		$tBody = '';
		foreach($activities as $activity)
		{
			$date = date_i18n( get_option('date_format') . ' ' . get_option('time_format'),
				strtotime($activity['am_datetime_created']) + $gmt_offset,
				$gmt = false
			);
			$activityString = $this->getActivityString($activity);
			$tBody .= '
				<tr>
					<td>'.$date.'</td>
					<td>'.$activityString.'</td>
				</tr>
			';
		}

		echo '
			<table class="threewp_activity_monitor">
				<thead>
					<tr>
						<th>Timestamp</th>
						<th>Activity</th>
					</tr>
				</thead>
				<tbody>
					'.$tBody.'
				</tbody>
			</table>
		';
	}
	
	public function adminSettings()
	{
		$form = $this->form();
		
		$limit = $this->get_option('limit');
		
		if (isset($_POST['save']))
		{
			$limit = intval($_POST['limit']);
			$limit = max(1, $limit);
			$this->update_option('limit', $limit);
			$this->message('Settings saved!');
		}
		
		$inputs = array(
			'limit' => array(
				'name' => 'limit',
				'type' => 'text',
				'value' => $limit,
				'label' => 'Maximum amount of activities',
				'size' => 5,
				'maxlength' => 5,
			),
			'save' => array(
				'name' => 'save',
				'type' => 'submit',
				'cssClass' => 'button-primary',
				'value' => 'Save settings',
			),
		);
		
		echo '
			'.$form->start().'
			<p>
				'.$form->makeLabel($inputs['limit']).' '.$form->makeInput($inputs['limit']).'
			</p>
			<p>
				'.$form->makeInput($inputs['save']).'
			</p>
			'.$form->stop().'
		';
	}
	
	protected function uninstall()
	{
		$this->query("DROP TABLE `".$this->wpdb->base_prefix."activity_monitor`");
		$this->deregister_options();
	}
	
	/**
	 * Converts an activity row into a string with links and stuff.
	 */
	private function getActivityString($activity)
	{
		extract($activity);				// Yeah, this is a bad idea (generally)...
		
		if (!isset($this->cache['user'][$user_id]))
			$this->cache['user'][$user_id] = get_userdata($user_id);
		if (!isset($this->cache['blog'][$blog_id]))
			$this->cache['blog'][$blog_id] = get_blog_details($blog_id, true);
		$blog = $this->cache['blog'][$blog_id];
		
		$blogLink = '<a href="'.$blog->siteurl.'">'.$blog->blogname.'</a> [<a title="Backend" href="'.$blog->siteurl.'/wp-admin/">B</a>]';
		
		if ($user_id !== null)
			$userLink = '<a href="user-edit.php?user_id='.$user_id.'">'.$this->cache['user'][$user_id]->user_nicename.'</a>';
			
		if ($post_id === null)
			return $userLink . ' has logged in to ' . $blogLink;

		$by = '';
		
		if ($user_id !== null)
			$by = ' by ' . $userLink;
		
		$on = ' on ' . $blogLink;
		
		if (!isset($this->cache['post'][$post_id]))
			$this->cache['post'][$post_id] = get_blog_post($blog_id, $post_id);
			
		if ($comment_id !== null)
		{
			$returnValue = 'New comment'.$on.$by.': <a href="'.$this->cache['post'][$post_id]->guid.'#comments">' . $this->cache['post'][$post_id]->post_title . '</a>';
		}
		else
		{
			$returnValue = 'New post'.$on.$by.': <a href="'.$this->cache['post'][$post_id]->guid.'">' . $this->cache['post'][$post_id]->post_title . '</a>';
		}
		return $returnValue;
	}
	
	/**
	 * Decider function: should this post activity be logged?
	 */
	public function publish_post($post_id)
	{
		$post = get_post($post_id);
		
		// Posts must be published and the parent must be 0 (meaning no autosaves)
		if ($post->post_status !== 'publish' || $post->post_parent != 0 || $post->post_date_gmt != $post->post_modified_gmt) 
			return;
			
		global $blog_id;
		$this->sqlActivityAdd($post->post_author, $blog_id, $post_id, null, var_export($post, true));
	}
	
	/**
	 * George W Bush function: should this comment activity be logged?
	 */
	public function comment_post($comment_id, $state)
	{		
		// Only approved comments are interesting.
		if ($state !== 'approve')
			return;

		$comment = get_comment($comment_id);		
		$post_id = $comment->comment_post_ID;
		$user_id = ($comment->user_id == 0 ? null : $comment->user_id);
		global $blog_id;
		$this->sqlActivityAdd($user_id, $blog_id, $post_id, $comment_id, var_export($comment, true));
	}
	
	public function wp_login($login)
	{
		$user = get_userdatabylogin($login);
		global $blog_id;
		$this->sqlActivityAdd($user->ID, $blog_id, null, null);
	}
	
	private function sqlActivityAdd($user_id, $blog_id, $post_id, $comment_id)
	{
		$user_id = ($user_id === null ? 'null' : "'$user_id'");
		$post_id = ($post_id === null ? 'null' : "'$post_id'");
		$comment_id = ($comment_id === null ? 'null' : "'$comment_id'");
		$date = current_time('mysql', 1);
		$this->query("INSERT INTO `".$this->wpdb->base_prefix."activity_monitor` (am_datetime_created, user_id, blog_id, post_id, comment_id) VALUES
			('$date', $user_id, '$blog_id', $post_id, $comment_id)");
	}
	
	/**
	 * Returns a list of activities.
	 */
	private function sqlActivities()
	{
		return $this->query("SELECT * FROM `".$this->wpdb->base_prefix."activity_monitor` ORDER BY am_datetime_created DESC");
	}
	
	private function pruneActivities($activities, $limit)
	{
		if (count($activities)<=$limit)
			return $activities;
			
		// Prune the extra activities
		$activities = array_splice($activities, 0, $limit);
		
		// Extract the last date and them trash whatever is older.
		$lastDate = $activities[$limit-1]['am_datetime_created'];
		$this->query("DELETE FROM `".$this->wpdb->base_prefix."activity_monitor` WHERE am_datetime_created < '$lastDate'");
		
		return $activities;
	}
}

$threewp_activity_monitor = new ThreeWP_Activity_Monitor();
?>