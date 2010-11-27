=== ThreeWP Activity Monitor ===
Tags: wp, wpms, network, threewp, activity, monitor, activity monitor, blog activity, user, comments, logins,
Requires at least: 3.0
Tested up to: 3.0
Stable tag: trunk

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

Keeps track of latest login times and displays a column in the user overview(s).

Since this plugin allows you to monitor all activity sitewide, it will be very easy to quickly locate spam blogs and their activities.

Unlike the wpmu.org "premium" plugins, Blog Activity and User Activity, this plugin displays information about _what_ is happening, not just that there is _something_ happening.

Has an uninstall option to completely remove itself from the database.

Available in English and Swedish.

== Installation ==

1. Unzip and copy the zip contents (including directory) into the `/wp-content/plugins/` directory
1. Activate the plugin sitewide through the 'Plugins' menu in WordPress.

== Screenshots ==

1. Main activity monitor tab
1. User list with "last login" column
1. Settings tab
1. Uninstall settings

== Upgrade Notice ==

= 1.0 =
The old activity table is removed.

== Changelog ==
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
