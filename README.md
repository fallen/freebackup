# freebackup

This plugin is a very basic one that basically does one thing: creates a daily database backup and uploads it to a destination of your choice via sFTP.
It is your responsability to have some kind of automated task on the remote server to backup and secure your data (using borg or borgmatic for instance).
This plugins supports sFTP via either username/password or public/private key.

Why would you need this plugin?

This kind of plugin is needed if you are using a web hosting service instead of VPS or dedicated server.
When you don't have access to SSH or cron, you can't run "mysqldump" yourself to dump your database.

This plugin allows to do just that: automate the production of a database backup and the extraction of it to another server.

I developped this plugin for the internal use of an organization (RAP), therefore its features are tailored to what we need:

* only works on Linux web hosts
* only works with PHP 5.3.3+ (because of phpseclib used for sFTP)
 
License: GPLv3 or later

Thanks to updraftplus plugin, I took some code from it for mysqldump tool path detection and for the actual sql dump function.
