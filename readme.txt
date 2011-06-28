=== ThreeWP Activity Monitor ===
Tags: wp, wpms, network, threewp, activity, monitor, activity monitor, blog activity, user, comments, logins,
Requires at least: 3.1
Tested up to: 3.1.1
Stable tag: trunk
Contributors: edward mindreantre
Track and display site or network-wide user activity.

== Description ==

Displays a multitude of user actions to keep the site administrator informed that all is well and that the blog or network is not being abused. Displays:

* Logins (successful and failed)
* Retrieved and reset passwords
* Posts/pages created, updated, trashed, untrashed and deleted
* Comments approved, trashed, spammed, unspammed, trashed, untrashed and deleted
* Changed passwords
* Changed user info
* User registrations
* User deletions
* Custom activities from other plugins

Keeps track of latest login times and displays a column in the user overview(s).

Since this plugin allows you to monitor all activity sitewide, it will be very easy to quickly locate spam blogs and their activities.

Unlike the wpmu.org "premium" plugins, Blog Activity and User Activity, this plugin displays information about _what_ is happening, not just that there is _something_ happening.

Has an uninstall option to completely remove itself from the database.

Available in English and Swedish.

Since v1.2 other plugins can add new activities.

== Custom activities ==

= action: threewp_activity_monitor_new_activity =

If you're the author of a plugin and want a custom action added to the list, use the following code.

`do_action('threewp_activity_monitor_new_activity', array(
	'activity_type' => '25char_description',
	'tr_class' => 'first_action_class second_action_class',
	'activity' => array(
		"" => "%user_display_name_with_link% removed a category on %blog_name_with_panel_link%",
		"Action key 1" => "The key is displayed as small, grey text in front of the other text. Something interesting happened and user # %user_id% caused it!",
		"  " => "%user_login% didnt want any header here so %user_login_with_link% left it blank with two spaces",
		"Another key!" => "This time I wanted a key on %blog_name% (%blog_id%).",
	),
));`

*activity_type* is an optional string to use as an index. See the _index table for the existing index_action strings which you should avoid (comment_approve, login_success, etc). 25 chars max.
*tr_class* is an optional value that signifies which extra css classes to give the action's row.
*activity* is the activity itself, that is an array of header => text.

The *key* is the small, gray text. It can be left empty. If you want several empty keys, use a different amount of spaces for each one. The spaces are trimmed off.
The *value* is the black text to be displayed to the right of the key or by itself on a line.

Both the *key* and *value* can be normal HTML and are both capable of *keywords*.

The following keywords are automatically replaced by Activity Monitor.

* %user_id% ID of user.
* %user_login% User's login name.
* %user_login_with_link% User's login name in link format (link goes to the user edit page).
* %user_display_name% User's display name.
* %user_display_name_with_link% User's display name in link format (link goes to the user edit page).
* %user_display_name% User's login name.
* %user_display_name_with_link% User's login name in link format (link goes to the user edit page).
* %blog_id% ID of blog.
* %blog_name% Name of blog.
* %blog_link% Link to front page of blog.
* %blog_panel_link% Link to blog's admin panel.
* %blog_name_with_link% Blog's name with link to front page.
* %blog_name_with_panel_link% Blog's name with link to admin panel.

= filter: threewp_activity_monitor_list_activities =

List all of the activities that your plugin creates.

`
add_filter( 'threewp_activity_monitor_list_activities', array(&$this, 'list_activities') 10, 1);

public function list_activities($activities)
{
	$activities = array_merge(array(
		'post_publish' => array(						// The activity name as per the database
			'name' => 'Post published',					// Human readable activity name
		),
		'comment_approve' => array(
			'name' => 'Comment approved',
			'description => 'A comment has been approved by an administrator and is now visible to blog visitors.',		// Optional description.
			'sensitive_information' => false,			// Optional value to tell the other plugins that this activity contains information that shouldn't be shown to just anyone. Default is false. 
		),
	), $activities);
	return $activities;
}
`
Since the Activity Monitor itself adds default values then only the array key and name values are necessary.

If you decide that more keys are necessary, <a href="edward@mindreantre.se">send me a patch</a>.

= filter: threewp_activity_monitor_convert_activity_to_post =

If your plugin creates activities that can be displayed to the user in the form of posts (eg: new posts, updated posts, new comments) then add a filter.

Return a complete post. The guid should be used as a link to the post or comment in question.


== Installation ==

1. Unzip and copy the zip contents (including directory) into the `/wp-content/plugins/` directory
1. Activate the plugin sitewide through the 'Plugins' menu in WordPress.

== Screenshots ==

1. Main activity monitor tab
1. User list with "last login" column
1. Password tried info
1. Settings tab
1. Uninstall settings

== Upgrade Notice ==

= 1.2 =
Converts the data column to a base64encoded serialized string.
= 1.0 =
The old activity table is removed.

== Changelog ==
= 1.4 =
* Only posts and pages are counted as activity. Not menus or attachments.
* Updated the framework
= 1.3 =
* WP 3.1 support
* User's activities shown in profile (fixed)
= 1.2 =
* threewp_activity_monitor_new_activity action added.
= 1.1 =
* Wordpress deleting posts and comments isn't logged anymore.
* Pagination added
* Password tried info added for login failures
= 1.0 =
* Major overhaul.
* Settings are kept when activating the plugin.
= 0.3 =
* WP3.0 compatability
= 0.0.2 =
* Backend link to each blog
* Code cleanup (new base class, etc)
= 0.0.1 =
* Initial public release
