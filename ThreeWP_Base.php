<?php
if (class_exists('ThreeWP_Base'))
	return;

/*
function dbg($variable)
{
	echo '<pre>';
	var_dump($variable);
	echo '</pre>';
}
*/

/**
 * Base class with some common functions.
 */
class ThreeWP_Base
{
	protected $baseVersion = 20100222;
	protected $baseRequired = 0;
	protected $wpdb;
	protected $pluginPath;
	protected $pluginURL;
	protected $isWPMU; 

	public function __construct()
	{
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->isWPMU = function_exists('is_site_admin');
	}
	
/**

	public function __construct()
	{
		parent::__construct();
		$this->pluginPath = basename(dirname(__FILE__)) . '/' . basename(__FILE__);
		$this->pluginURL = WP_PLUGIN_URL . '/' . basename(dirname(__FILE__));
		register_activation_hook(__FILE__, array(&$this, 'activate') );
	}

 */	
	
	protected function activate()
	{
		if ($this->baseRequired > $this->baseVersion)
		{
			$this->deactivate();
			wp_die('Please upgrade all other ThreeWP plugins before trying to activate this one. This plugin requires a newer ('.$this->baseRequired.') of ThreeWP_Base.php file (currently at '.$this->baseVersion.').');
		}
	}
	
	protected function deactivate()
	{
		deactivate_plugins($this->pluginPath);
	}
	
	/**
	 * Fire an SQL query and return the results in an array.
	 */
	protected function query($query)
	{
		$results = $this->wpdb->get_results($query, 'ARRAY_A');
		return (is_array($results) ? $results : array());
	}
	
	/**
	 * Fire an SQL query and return the row ID of the inserted row.
	 */
	protected function queryInsertID($query)
	{
		$this->wpdb->query($query);
		return $this->wpdb->insert_id;
	}
	
	/**
	 * List of wordpress user roles.
	 * 
	 * The key is the role name and the value is what the user can't do that lower users can't.
	 */
	protected $roles = array(
		array(
			'name' => 'administrator',
			'current_user_can' => 'manage_options',
		),
		array(
			'name' => 'editor',
			'current_user_can' => 'manage_links',
		),
		array(
			'name' => 'author',
			'current_user_can' => 'publish_posts',
		),
		array(
			'name' => 'contributor',
			'current_user_can' => 'edit_posts',
		),
		array(
			'name' => 'subscriber',
			'current_user_can' => 'read',
		),
	);

	/**
	 * Returns the user's role as a string.
	 */
	protected function get_user_role()
	{
		foreach($this->roles as $role)
			if (current_user_can($role['current_user_can']))
				return $role['name'];
	}
	
	// The follow functions work primarily with the site_options, if available (WPMU installation), else fall back to normal get_option().
	
	protected function get_option($option)
	{
		$option = $this->pluginPath . $option;
		if ($this->isWPMU)
			return get_site_option($option);
		else
			return get_option($option);
	}

	protected function update_option($option, $value)
	{
		$option = $this->pluginPath . $option;
		if ($this->isWPMU)
			update_site_option($option, $value);
		else
			update_option($option, $value);
	}
	
	protected function delete_option($option)
	{
		$option = $this->pluginPath . $option;
		if ($this->isWPMU)
			delete_site_option($option);
		else
			delete_option($option);
	}
	
	/**
		Replaces an existing &OPTION=VALUE pair from the uri.
		If value is NULL, will remove the option completely.
		If pair does not exist, the pair will be placed at the end of the uri.
		
		Examples:
			URLmake("sortorder", "name", "index.php?page=start")
			=> "index.php?page=start&sortorder=name"
			
			URLmake("sortorder", "name", "index.php?page=start&sortorder=date")
			=> "index.php?page=start&sortorder=name"
		
			URLmake("sortorder", null, "index.php?page=start&sortorder=date")
			=> "index.php?page=start"
		
			URLmake("page", null, "index.php?page=start&sortorder=date")
			=> "index.php?sortorder=date"
		
			URLmake("sortorder", "name", "index.php?page=start&sortorder=date&security=none")
			=> "index.php?page=start&security=none&sortorder=name"
	*/	
	public static function urlMake($option, $value = null, $url = null)
	{
		if ($url === null)
			$url = $_SERVER['REQUEST_URI'];
		
		$url = html_entity_decode($url);
		
		// Replace all ? with & and add an & at the end
		$url = preg_replace('/\?/', '&', $url);
		$url = preg_replace('/&+$/', '&', $url . '&');
		
		// Remove the value?
		if ($value === null)
		{
			// Remove the key
			$url = preg_replace('/&'.$option.'=?(.*)&/U', '&', $url);
		}
		else
		{
			$value = (string)$value;		// Else we have 0-problems.
			// Fix the value
			if ($value != '')
				$value = '=' . $value;
			// Does the key exist? Replace
			if (strpos($url, '&'.$option) !== false)
				$url = preg_replace('/&'.$option.'=(.*)&|&'.$option.'&/U', '&' . $option . $value . '&', $url);
			else	// Or append
				$url .= $option . $value . '&';
		}
		
		// First & becomes a question mark
		$url = preg_replace('/&(.*)&$/U', '?\1', $url);
		
		// Remove & at the end
		$url = preg_replace('/&$/', '', $url);

		return htmlentities($url);
	}

	protected function tabs($options)
	{
		$options = array_merge(array(
			'display' => true,					// Display the tabs or return them.
			'displayBeforeTabName' => '<h2>',
			'displayTabName' => true,
			'displayAfterTabName' => '</h2>',
			'getKey' =>	'tab',
			'default' => 0,
			'tabs' =>		array(),
			'functions' =>	array(),
			'count' =>		array(),
		), $options);
		
		$getKey = $options['getKey'];			// Convenience.
		if (!isset($_GET[$getKey]))	// Select the default tab if none is selected.
			$_GET[$getKey] = sanitize_title( $options['tabs'][$options['default']] );
		$selected = $_GET[$getKey];
		
		$returnValue = '<ul class="subsubsub">';
		foreach($options['tabs'] as $index=>$tab)
		{
			$slug = sanitize_title($tab);
			$link = ($index == $options['default'] ? self::urlMake($getKey, null) : self::urlMake($getKey, $slug));
			$text = $tab;
			if (isset($options['count'][$index]))
				$text .= ' <span class="count">(' . $options['count'][$index] . ')</span>';
				
			$separator = ($index+1 < count($options['tabs']) ? ' | ' : '');
			
			$current = ($slug == $selected ? ' class="current"' : '');
			
			if ($current)
				$selectedIndex = $index;
			 
			$returnValue .= '<li><a'.$current.' href="'.$link.'">'.$text.'</a>'.$separator.'</li>';
		}
		$returnValue .= '</ul>';
		if ($options['display'])
		{
			if ($options['displayTabName'])
				echo $options['displayBeforeTabName'] . $options['tabs'][$selectedIndex] . $options['displayAfterTabName'];
			echo $returnValue;
			echo '<div style="clear: both"></div>';
			if (isset($options['functions'][$selectedIndex]))
			{
				$functionName = $options['functions'][$selectedIndex];
				$this->$functionName();
			}
		}
		else
			return $returnValue;
	}
	
	protected function adminUninstall()
	{
		require_once('ThreeWP_Form.php');
		$form = new threewp_form();
		
		if (isset($_POST['uninstall']))
		{
			if (!isset($_POST['sure']))
				echo '<div class="error"><p>You have to check the checkbox in order to uninstall the plugin.</p></div>';
			else
			{
				$this->uninstall();
				$this->message('<div class="updated"><p>Plugin has been uninstalled and deactivated.</p></div>');
				$this->deactivate();
			}
		}
		
		$inputs = array(
			'sure' => array(
				'name' => 'sure',
				'type' => 'checkbox',
				'label' => "Yes, I'm sure I want to remove all the plugin tables and settings.",
			),
			'uninstall' => array(
				'name' => 'uninstall',
				'type' => 'submit',
				'cssClass' => 'button-primary',
				'value' => 'Uninstall plugin',
			),
		);
		
		echo '
			'.$form->start().'
			<p>
				This page will remove all the plugin tables and settings from the database and then deactivate the plugin.
			</p>

			<p>
				'.$form->makeInput($inputs['sure']).' '.$form->makeLabel($inputs['sure']).'
			</p>

			<p>
				'.$form->makeInput($inputs['uninstall']).'
			</p>
			'.$form->stop().'
		';
	}
	
	protected function message($string)
	{
		echo '<div class="updated"><p>'.$string.'</p></div>';
	}
}
?>