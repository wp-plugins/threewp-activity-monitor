<?php
/**
	Base class with some common functions. v2011-07-19

	2011-07-19			Documentation added.<br />
	2011-05-12			displayMessage now uses now() instead of date.<br />
	2011-04-30			Uses ThreeWP_Form instead of edwardForm.<br />
	2011-04-29	09:19	site options are registered even when using single Wordpress.<br />
	2011-01-25	13:14	load_language assumes filename as domain.<br />
	2011-01-25	13:14	loadLanguages -> load_language.<br />
*/
class ThreeWP_Activity_Monitor_3Base
{
	/**
		Stores whether this blog is a network blog.
		
		@var	bool
	**/
	protected $isNetwork;
	
	/**
		Contains the paths to the plugin and other places of interest.
		
		The keys in the array are:
		
		name<br />
		filename<br />
		filename_from_plugin_directory<br />
		path_from_plugin_directory<br />
		path_from_base_directory<br /> 
		url<br />

		@var	array
	**/
	protected $paths = array();
	
	/**
		Array of options => default_values that this plugin stores sitewide.
		
		@var	array
	**/ 
	protected $site_options = array();

	/**
		Array of options => default_values that this plugin stores locally.
		@var	array
	**/ 
	protected $local_options = array();

	/**
		Text domain of .PO translation.
		
		If left unset will be set to the base filename minus the .php
		
		@var	string
	**/ 
	protected $language_domain = ''; 

	/**
		Links to Wordpress' database object.
		@var	object
	**/
	protected $wpdb;
	
	/**
		The list of the standard user roles in Wordpress.
		
		First an array of role_name => array
		
		And then each role is an array of name => role_name and current_user_can => capability.

		@var	array
	**/
	protected $roles = array(
		'administrator' => array(
			'name' => 'administrator',
			'current_user_can' => 'manage_options',
		),
		'editor' => array(
			'name' => 'editor',
			'current_user_can' => 'manage_links',
		),
		'author' => array(
			'name' => 'author',
			'current_user_can' => 'publish_posts',
		),
		'contributor' => array(
			'name' => 'contributor',
			'current_user_can' => 'edit_posts',
		),
		'subscriber' => array(
			'name' => 'subscriber',
			'current_user_can' => 'read',
		),
	);
	
	/**
		Construct the class.
		
		@param		string		$filename		The full path of the parent class.
	**/
	public function __construct($filename)
	{
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->is_network = MULTISITE;
		$this->isNetwork = $this->is_network;
		
		$this->paths = array(
			'name' => get_class($this),
			'filename' => basename($filename),
			'filename_from_plugin_directory' => basename(dirname($filename)) . '/' . basename($filename),
			'path_from_plugin_directory' => basename(dirname($filename)),
			'path_from_base_directory' => PLUGINDIR . '/' . basename(dirname($filename)),
			'url' => WP_PLUGIN_URL . '/' . basename(dirname($filename)),
		);
		
		add_action( 'admin_init', array(&$this, 'admin_uninstall_post') );
	}
	
	/**
		Overridable activation function.
		
		It's here in case plugins need a common activation method in the future.
	**/
	protected function activate()
	{
	}
	
	/**
		Overridable method to deactive the plugin.
	**/
	protected function deactivate()
	{
	}
	
	/**
		Deactivates the plugin.
	**/
	protected function deactivate_me()
	{
		deactivate_plugins(array(
			$this->paths['filename_from_plugin_directory']
		));
	}
	
	/**
		Overridable uninstall method.
	**/
	protected function uninstall()
	{
		$this->deregister_options();
	}
	
	/**
		Handles the uninstall command in the $_POST.
		
		If the user correctly filled in the uninstall form, the plugin will deactivate itself and return to the plugin list.
	**/
	public function admin_uninstall_post()
	{
		$class_name = get_class($this);
		if ( isset($_POST[ $class_name ]['uninstall']) )
		{
			if ( isset($_POST[ $class_name ]['sure']) )
			{
				$this->uninstall();
				$this->deactivate_me();
				if ($this->is_network)
					wp_redirect( 'ms-admin.php' );
				else
					wp_redirect( 'index.php' );
				exit;
			}
		}
	}

	/**
		Shows the uninstall form.
		
		Form is currently only available in English.
	**/
	protected function admin_uninstall()
	{
		$form = $this->form();
		
		if (isset($_POST[ get_class($this) ]['uninstall']))
			if (!isset($_POST['sure']))
				$this->error('You have to check the checkbox in order to uninstall the plugin.');
		
		$nameprefix = '['.get_class($this).']';
		$inputs = array(
			'sure' => array(
				'name' => 'sure',
				'nameprefix' => $nameprefix,
				'type' => 'checkbox',
				'label' => "Yes, I'm sure I want to remove all the plugin tables and settings.",
			),
			'uninstall' => array(
				'name' => 'uninstall',
				'nameprefix' => $nameprefix,
				'type' => 'submit',
				'css_class' => 'button-primary',
				'value' => 'Uninstall plugin',
			),
		);
		
		echo '
			'.$form->start().'
			<p>
				This page will remove all the plugin tables and settings from the database and then deactivate the plugin.
			</p>

			<p>
				'.$form->make_input($inputs['sure']).' '.$form->make_label($inputs['sure']).'
			</p>

			<p>
				'.$form->make_input($inputs['uninstall']).'
			</p>
			'.$form->stop().'
		';
	}
	
	/**
		Loads this plugin's language files.
		
		Reads the language data from the class's name domain as default.
		
		@param	string		$domain		Optional domain.
	**/
	protected function load_language($domain = '')
	{
		if ( $domain != '')
			$this->language_domain = $domain;
		
		if ($this->language_domain == '')
			$this->language_domain = str_replace( '.php', '', $this->paths['filename'] );
		load_plugin_textdomain($this->language_domain, false, $this->paths['path_from_plugin_directory'] . '/lang');
	}
	
	/**
		Translate a string, if possible.
		
		Like Wordpress' internal _() method except this one automatically uses the plugin's domain.
		
		@param		string		$domain		String to translate.
		@return		string					Translated string, or the untranslated string.
	**/
	protected function _($string)
	{
		return __( $string, $this->language_domain );
	}
	
	// -------------------------------------------------------------------------------------------------
	// ----------------------------------------- SQL
	// -------------------------------------------------------------------------------------------------
	
	/**
		Sends a query to wpdb and return the results.
		
		@param		string		$query		The SQL query.
		@param		object		$wpdb		An optional, other WPDB if the standard $wpdb isn't good enough for you.
		@return		array					The rows from the query.
	**/
	protected function query($query , $wpdb = null)
	{
		if ( $wpdb === null )
			$wpdb = $this->wpdb;
		$results = $wpdb->get_results($query, 'ARRAY_A');
		return (is_array($results) ? $results : array());
	}
	
	/**
		Fire an SQL query and return the results only if there is one row result.
		
		@param		string		$query		The SQL query.
		@return		array|false				Either the row as an array, or false if more than one row.
	**/
	protected function query_single($query)
	{
		$results = $this->wpdb->get_results($query, 'ARRAY_A');
		if ( count($results) != 1)
			return false;
		return $results[0];
	}
	
	/**
		Fire an SQL query and return the row ID of the inserted row.

		@param		string		$query		The SQL query.
		@return		int						The inserted ID.
	**/
	protected function query_insert_id($query)
	{
		$this->wpdb->query($query);
		return $this->wpdb->insert_id;
	}
	
	/**
		Converts an object to a base64 encoded, serialized string, ready to be inserted into sql.
		
		@param		object		$object		An object.
		@return		string					Serialized, base64-encoded string.
	**/
	protected function sql_encode( $object )
	{
		return base64_encode( serialize($object) );
	}
	
	/**
		Converts a base64 encoded, serialized string back into an object.
		@param		string		$string		Serialized, base64-encoded string.
		@return		object					Object, if possible.
	**/
	protected function sql_decode( $string )
	{
		return unserialize( base64_decode($string) );
	}
	
	/**
		Returns whether a table exists.
		
		@param		string		$table_name	Table name to check for.
		@return		bool					True if the table exists.
	**/
	protected function sql_table_exists( $table_name )
	{
		$query = "SHOW TABLES LIKE '$table_name'";
		$result = $this->query( $query );
		return count($result) > 0;
	}
	
	// -------------------------------------------------------------------------------------------------
	// ----------------------------------------- USER
	// -------------------------------------------------------------------------------------------------
	
	/**
		Returns the user's role as a string.
		@return		string					User's role as a string.
	**/
	protected function get_user_role()
	{
		foreach($this->roles as $role)
			if (current_user_can($role['current_user_can']))
				return $role['name'];
	}
	
	/**
		Checks whether the user's role is at least $role.
		
		@param		string		$role		Role as string.
		@return		bool					True if role is at least $role.
	**/
	protected function role_at_least($role)
	{
		if ($role == '')
			return true;

		if ($role == 'super_admin')
			if (function_exists('is_super_admin'))
				return is_super_admin();
			else
				return false;
		return current_user_can($this->roles[$role]['current_user_can']);
	}
	
	/**
		Return the user_id of the current user.
	
		@return		int						The user's ID.
	**/
	protected function user_id()
	{
		global $current_user;
		get_current_user();
		return $current_user->ID;
	}
	
	/**
		Creates a new ThreeWP_Form.
		
		@param		array		$options	Default options to send to the ThreeWP form constructor.
		@return		object					A new ThreeWP form class.
	**/ 
	protected function form($options = array())
	{
		$options = array_merge($options, array('language' => preg_replace('/_.*/', '', get_locale())) );
		if (class_exists('ThreeWP_Form'))
			return new ThreeWP_Form($options);
		require_once('ThreeWP_Form.php');
		return new ThreeWP_Form($options);
	}
	
	// -------------------------------------------------------------------------------------------------
	// ----------------------------------------- OPTIONS
	// -------------------------------------------------------------------------------------------------
	
	/**
		Normalizes the name of an option.
		
		Will prepend the class name in front, to make the options easily findable in the table.
		
		@param		string		$option		Option name to fix.
	**/
	protected function fix_option_name($option)
	{
		return $this->paths['name'] . '_' . $option;
	}
	
	/**
		Get a site option.
		
		If this is a network, the site option is preferred.
		
		@param		string		$option		Name of option to get.
		@return		mixed					Value.
	**/
	protected function get_option($option)
	{
		$option = $this->fix_option_name($option);
		if ($this->isNetwork)
			return get_site_option($option);
		else
			return get_option($option);
	}
	
	/**
		Updates a site option.
		
		If this is a network, the site option is preferred.
		
		@param		string		$option		Name of option to update.
		@param		mixed		$value		New value
	**/
	protected function update_option($option, $value)
	{
		$option = $this->fix_option_name($option);
		if ($this->isNetwork)
			update_site_option($option, $value);
		else
			update_option($option, $value);
	}
	
	/**
		Deletes a site option.
		
		If this is a network, the site option is preferred.
		
		@param		string		$option		Name of option to delete.
	**/
	protected function delete_option($option)
	{
		$option = $this->fix_option_name($option);
		if ($this->isNetwork)
			delete_site_option($option);
		else
			delete_option($option);
	}
	
	/**
		Gets the value of a local option.
		
		@param		string		$option		Name of option to get.
		@return		mixed					Value.
	**/
	protected function get_local_option($option)
	{
		$option = $this->fix_option_name($option);
		return get_option($option);
	}
	
	/**
		Updates a local option.
		
		@param		string		$option		Name of option to update.
		@param		mixed		$value		New value
	**/
	protected function update_local_option($option, $value)
	{
		$option = $this->fix_option_name($option);
		update_option($option, $value);
	}
	
	/**
		Deletes a local option.
		
		@param		string		$option		Name of option to delete.
	**/
	protected function delete_local_option($option)
	{
		$option = $this->fix_option_name($option);
		delete_option($option);
	}
	
	/**
		Gets the value of a site option.
		
		@param		string		$option		Name of option to get.
		@return		mixed					Value.
	**/
	protected function get_site_option($option)
	{
		$option = $this->fix_option_name($option);
		return get_site_option($option);
	}
	
	/**
		Updates a site option.
		
		@param		string		$option		Name of option to update.
		@param		mixed		$value		New value
	**/
	protected function update_site_option($option, $value)
	{
		$option = $this->fix_option_name($option);
		update_site_option($option, $value);
	}
	
	/**
		Deletes a site option.
		
		@param		string		$option		Name of option to delete.
	**/
	protected function delete_site_option($option)
	{
		$option = $this->fix_option_name($option);
		delete_site_option($option);
	}
	
	/**
		Registers all the options this plugin uses.
	**/
	protected function register_options()
	{
		foreach($this->options as $option=>$value)
		{
			if ($this->get_option($option) === false)
				$this->update_option($option, $value);
		}

		foreach($this->local_options as $option=>$value)
		{
			$option = $this->fix_option_name($option);
			if (get_option($option) === false)
				update_option($option, $value);
		}

		if ($this->isNetwork)
		{
			foreach($this->site_options as $option=>$value)
			{
				$option = $this->fix_option_name($option);
				if (get_site_option($option) === false)
					update_site_option($option, $value);
			}
		}
		else
		{
			foreach($this->site_options as $option=>$value)
			{
				$option = $this->fix_option_name($option);
				if (get_option($option) === false)
					update_option($option, $value);
			}
		}
	}
	
	/**
		Removes all the options this plugin uses.
	**/
	protected function deregister_options()
	{
		foreach($this->options as $option=>$value)
		{
			$this->delete_option($option);
		}

		foreach($this->local_options as $option=>$value)
		{
			$option = $this->fix_option_name($option);
			delete_option($option);
		}

		if ($this->isNetwork)
			foreach($this->site_options as $option=>$value)
			{
				$option = $this->fix_option_name($option);
				delete_site_option($option);
			}
		else
		{
			foreach($this->site_options as $option=>$value)
			{
				$option = $this->fix_option_name($option);
				delete_option($option, $value);
			}
		}
	}
	
	// -------------------------------------------------------------------------------------------------
	// ----------------------------------------- MESSAGES
	// -------------------------------------------------------------------------------------------------
	
	/**
		Displays a message.
		
		Autodetects HTML / text.
		
		@param		string		$type		Type of message: error, warning, whatever. Free content.
		@param		string		$string		The message to display.
	**/
	public function displayMessage($type, $string)
	{
		// If this string has html codes, then output it as it.
		$stripped = strip_tags($string);
		if (strlen($stripped) == strlen($string))
		{
			$string = explode("\n", $string);
			$string = implode('</p><p>', $string);
		}
		echo '<div class="'.$type.'">
			<p style="margin-right: 1em; float: left; color: #888;" class="message_timestamp">'.$this->now().'</p>
			<p>'.$string.'</p></div>';
	}
	
	/**
		Displays an error message.
		
		The only thing that makes it an error message is that the div has the class "error".
		
		@param		string		$string		String to display.
	**/
	public function error($string)
	{
		$this->displayMessage('error', $string);
	}
	
	/**
		Displays an informational message.
		
		@param		string		$string		String to display.
	**/
	public function message($string)
	{
		$this->displayMessage('updated', $string);
	}
		
	// -------------------------------------------------------------------------------------------------
	// ----------------------------------------- TOOLS
	// -------------------------------------------------------------------------------------------------
	
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
	
	/**
		Displays Wordpress tabs.
		
		@param	array		$options			See options.
	**/
	protected function tabs($options)
	{
		$options = array_merge(array(
			'tabs' =>		array(),				// Array of tab names
			'functions' =>	array(),				// Array of functions associated with each tab name.
			'page_titles' =>	array(),				// Array of page titles associated with each tab.
			'count' =>		array(),				// Optional array of a strings to display after each tab name. Think: page counts.
			'display' => true,						// Display the tabs or return them.
			'displayTabName' => true,				// If display==true, display the tab name.
			'displayBeforeTabName' => '<h2>',		// If displayTabName==true, what to display before the tab name.
			'displayAfterTabName' => '</h2>',		// If displayTabName==true, what to display after the tab name.
			'getKey' =>	'tab',						// $_GET key to get the tab value from.
			'valid_get_keys' => array(),			// Display only these _GET keys.
			'default' => 0,							// Default tab index.
		), $options);
		
		$getKey = $options['getKey'];			// Convenience.
		if (!isset($_GET[$getKey]))	// Select the default tab if none is selected.
			$_GET[$getKey] = sanitize_title( $options['tabs'][$options['default']] );
		$selected = $_GET[$getKey];
		
		$options['valid_get_keys']['page'] = 'page';
		
		$returnValue = '';
		if (count($options['tabs'])>1)
		{
			$returnValue .= '<ul class="subsubsub">';
			$link = $_SERVER['REQUEST_URI'];

			foreach($_GET as $key => $value)
				if ( !in_array($key, $options['valid_get_keys']) )
					$link = remove_query_arg($key, $link);
			
			$index = 0;
			foreach($options['tabs'] as $tab_index => $tab)
			{
				$slug = $this->tab_slug($tab);
				$link = ($index == $options['default'] ? self::urlMake($getKey, null, $link) : self::urlMake($getKey, $slug, $link));
				
				$text = $tab;
				if (isset($options['count'][$index]))
					$text .= ' <span class="count">(' . $options['count'][$index] . ')</span>';
				
				$separator = ($index+1 < count($options['tabs']) ? ' | ' : '');
				
				$current = ($slug == $selected ? ' class="current"' : '');
				
				if ($current)
					$selected_index = $tab_index;
				 
				$returnValue .= '<li><a'.$current.' href="'.$link.'">'.$text.'</a>'.$separator.'</li>';
				$index++;
			}
			$returnValue .= '</ul>';
		}
		
		if ( !isset($selected_index) )
			$selected_index = $options['default'];
	
		if ($options['display'])
		{
			ob_start();
			echo '<div class="wrap">';
			if ($options['displayTabName'])
			{
				if ( isset( $options['page_titles'][$selected_index] ) )
					$page_title = $options['page_titles'][$selected_index];
				else
					$page_title = $options['tabs'][$selected_index];
				
				echo $options['displayBeforeTabName'] . $page_title . $options['displayAfterTabName'];
			}
			echo $returnValue;
			echo '<div style="clear: both"></div>';
			if (isset($options['functions'][$selected_index]))
			{
				$functionName = $options['functions'][$selected_index];
				$this->$functionName();
			}
			echo '</div>';
			ob_end_flush();
		}
		else
			return $returnValue;
	}
	
	/**
		Sanitizes the name of a tab.
	
		@param		string		$name		String to sanitize.
	**/
	protected function tab_slug($name)
	{
		return sanitize_title($name);
	}
	
	protected function display_form_table($options)
	{
		$options = array_merge(array(
			'header' => '',
			'header_level' => 'h3',
		), $options);
		
		$tr = array();
		
		if ( !isset($options['form']) )
			$options['form'] = $this->form();
			
		foreach( $options['inputs'] as $input )
			$tr[] = $this->display_form_table_row( $input, $options['form'] );
		
		$returnValue = '';
		
		if ( $options['header'] != '' )
			$returnValue .= '<'.$options['header_level'].'>' . $options['header'] . '</'.$options['header_level'].'>';
		
		$returnValue .= '
			<table class="form-table">
				<tr>' . implode('</tr><tr>', $tr) . '</tr>
			</table>
		';
		
		return $returnValue;
	}

	protected function display_form_table_row($input, $form = null)
	{
		if ($form === null)
			$form = $this->form();
		return '
			<tr>
				<th>'.$form->make_label($input).'</th>
				<td>
					<div class="input_itself">
						'.$form->make_input($input).'
					</div>
					<div class="input_description">
						'.$form->make_description($input).'
					</div>
				</td>
			</tr>';
	}
	
	
	/**
		Make a value a key.
		
		Given an array of arrays, take the key from the subarray and makes it the key of the main array.
	
		@param		array		$array		Array to rearrange.
		@param		string		$key		Which if the subarray keys to make the key in the main array.
		@return		array					Rearranged array.
	**/
	public function array_moveKey($array, $key)
	{
		$returnArray = array();
		foreach($array as $value)
			$returnArray[ $value[$key] ] = $value;
		return $returnArray;
	}
	
	/**
		Convert an object to an array.
	
		@param		object		$object		Object to convert.
		@return		array					Returned array.
	**/
	public function object_to_array($object)
	{
		if (is_array($object))
		{
			$returnValue = array();
			foreach($object as $o)
				$returnValue[] = get_object_vars($o);
			return $returnValue;
		}
		else
			return get_object_vars($object);
	}
	
	/**
		Display the time ago as human-readable string.
		
		@param		string		$time_string	"2010-04-12 15:19"
		@param		string		$time			An optional timestamp to base time difference on, if not now.
		@return		string						"28 minutes ago"
	**/
	protected function ago($time_string, $time = null)
	{
		if ($time_string == '')
			return '';
		if ( $time === null )
			$time = current_time('timestamp');
		$diff = human_time_diff( strtotime($time_string), $time );
		return '<span title="'.$time_string.'">' . sprintf( __('%s ago'), $diff) . '</span>';
	}
	
	/**
		Returns WP's current timestamp (corrected for UTC)
		
		@return		string						Current timestamp in MYSQL datetime format.
	*/
	protected function now()
	{
		return date('Y-m-d H:i:s', current_time('timestamp'));
	}
	
	/**
		Returns the current time(), corrected for UTC and DST.

		@return		int							Current, corrected timestamp.
	**/
	protected function time()
	{
		return current_time('timestamp');
	}
	
	/**
		Returns the number corrected into the min and max values.
	*/
	protected function minmax($number, $min, $max)
	{
		$number = min($max, $number);
		$number = max($min, $number);
		return $number;
	}
	
	/**
		Returns a hash value of a string. The standard hash type is sha512 (64 chars).
		
		@param		string		$string			String to hash.
		@param		string		$type			Hash to use. Default is sha512.
		@return		string						Hashed string.
	**/
	protected function hash($string, $type = 'sha512')
	{
		return hash($type, $string);
	}
	
	/**
		Multibyte strtolower.
	
		@param		string		$string			String to lowercase.
		@return		string						Lowercased string.
	**/
	protected function strtolower( $string )
	{
		return mb_strtolower( $string ); 
	}
	
	/**
		Multibyte strtoupper.
	
		@param		string		$string			String to uppercase.
		@return		string						Uppercased string.
	**/
	protected function strtoupper( $string )
	{
		return mb_strtoupper( $string ); 
	}
	
	/**
	 * Sends mail via SMTP.
	 * 
	*/
	public function send_mail($mail_data)
	{
		// backwards compatability
		if (isset($mail_data['bodyhtml']))
			$mail_data['body_html'] = $mail_data['bodyhtml'];
		
		require_once ABSPATH . WPINC . '/class-phpmailer.php';
		$mail = new PHPMailer();
		
		// Mandatory
		$mail->From		= key($mail_data['from']);
		$mail->FromName	= reset($mail_data['from']);
		
		$mail->Subject  = $mail_data['subject'];
		
		// Optional
		
		// Often used settings...
	
		if (isset($mail_data['to']))
			foreach($mail_data['to'] as $email=>$name)
			{
				if (is_int($email))
					$email = $name;
				$mail->AddAddress($email, $name);
			}
			
		if (isset($mail_data['cc']))
			foreach($mail_data['cc'] as $email=>$name)
			{
				if (is_int($email))
					$email = $name;
				$mail->AddCC($email, $name);
			}
	
		if (isset($mail_data['bcc']))
			foreach($mail_data['bcc'] as $email=>$name)
			{
				if (is_int($email))
					$email = $name;
				$mail->AddBCC($email, $name);
			}
			
		if (isset($mail_data['body_html']))
			$mail->MsgHTML($mail_data['body_html'] );
	
		if (isset($mail_data['body']))
			$mail->Body = $mail_data['body'];
		
		if (isset($mail_data['attachments']))
			foreach($mail_data['attachments'] as $attachment=>$filename)
				if (is_numeric($attachment))
					$mail->AddAttachment($filename);
				else
					$mail->AddAttachment($attachment, $filename);

		if ( isset( $mail_data['reply_to'] ) )
		{
			foreach($mail_data['reply_to'] as $email=>$name)
			{
				if (is_int($email))
					$email = $name;
				$mail->AddReplyTo($email, $name);
			}
		}
				
		// Seldom used settings...
		
		if (isset($mail_data['wordwrap']))
			$mail->WordWrap = $mail_data[wordwrap];
	
		if (isset($mail_data['ConfirmReadingTo']))
			$mail->ConfirmReadingTo = true;
		
		if (isset($mail_data['SingleTo']))
		{
			$mail->SingleTo = true;
			$mail->SMTPKeepAlive = true;
		}
		
		if (isset($mail_data['SMTP']))									// SMTP? Or just plain old mail()
		{
			$mail->IsSMTP();
			$mail->Host	= $mail_data['smtpserver'];
			$mail->Port = $mail_data['smtpport'];
		}
		else
			$mail->IsMail();
		
		if ( isset($mail_data['charset']) )
			$mail->CharSet = $mail_data['charset'];
		else
			$mail->CharSet = 'UTF-8';
		
		if ( isset($mail_data['content_type']) )
			$mail->ContentType  = $mail_data['content_type'];
		
		if ( isset($mail_data['encoding']) )
			$mail->Encoding  = $mail_data['encoding'];
		
		// Done setting up.
		if(!$mail->Send())
			$returnValue = $mail->ErrorInfo;
		else 
			$returnValue = true;
			
		$mail->SmtpClose();
		
		return $returnValue;		
	}
}
?>