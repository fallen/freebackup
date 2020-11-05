<?php
/**
 * @package Free Backup
 * @version 1.0.0
 */

/*
Plugin name: Free Backup
Description: Backup your database automatically and upload the backup via sFTP for free.
Author: Yann Sionneau
Version: 1.0.0
Author: http://sionneau.net
*/
require __DIR__ . '/vendor/autoload.php';

function find_working_sqldump() {
	$sqldump_candidates = "/usr/bin/mysqldump,/bin/mysqldump,/usr/local/bin/mysqldump,/usr/sfw/bin/mysqldump,/usr/xdg4/bin/mysqldump,/opt/bin/mysqldump";
	foreach (explode(',', $sqldump_candidates) as $potsql) {
		if (!@is_executable($potsql)) continue;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		return $potsql;
	}
	return false;
}

function upload_dump_via_sftp( $backup_file ) {
	$options = get_option( 'freebackup_options' );
	$host = $options[ 'freebackup_field_sftp_host' ];
	$privkey_path = $options[ 'freebackup_field_sftp_privkey_file_path' ];
	$username = $options[ 'freebackup_field_sftp_username' ];
	$password = $options[ 'freebackup_field_sftp_password' ];
	$pwd_or_key = $options[ 'freebackup_field_sftp_pwd_or_key' ];
	$remote_path = $options[ 'freebackup_field_sftp_remote_path' ];

	$sftp = new \phpseclib\Net\SFTP( $host );

	if ( $pwd_or_key == "key" ) {
		$key = new \phpseclib\Crypt\RSA();
		$key->loadKey(file_get_contents( $privkey_path ));
		if (!$sftp->login( $username, $key )) {
			    exit( 'Login Failed' );
		}
	} else if ( $pwd_or_key == "password" ) {
		if (!$sftp->login( $username, $password)) {
			    exit( 'Login Failed' );
		}
	} else {
		exit("You must chose either public key or password authentication");
	}

	if ( $remote_path == "" )
		$path = "db_backup.sql";
	else
		$path = $remote_path."/db_backup.sql";


	echo "path: $path";
	$res = $sftp->put( $path, $backup_file, \phpseclib\Net\SFTP::SOURCE_LOCAL_FILE );
	if ( !$res )
		echo "sFTP upload failed: ".$sftp->getLastSFTPError();

	@unlink($backup_file);
}

function do_mysql_dump() {
	$backup_file = tempnam("/tmp", "FreeBackup");
	$backup_file_name = basename($backup_file);
	$backup_file_parent_directory = dirname($backup_file);
	$sqldump = find_working_sqldump();
	$pfile = tempnam("/tmp", "defaultsFile");
	file_put_contents($pfile, "[mysqldump]\npassword=".DB_PASSWORD."\n");
	$exec = "cd ".escapeshellarg($backup_file_parent_directory)."; ";
	$exec .= $sqldump." --defaults-file=$pfile --max_allowed_packet=1M --quote-names --add-drop-table --skip-comments --skip-set-charset --allow-keywords --dump-date --extended-insert --user=".escapeshellarg(DB_USER)." --host=".escapeshellarg(DB_HOST)." ".DB_NAME. " > ".$backup_file_name;
	$ret = false;
	$any_output = false;
	$handle = popen($exec, "r");
	if ($handle) {
		while (!feof($handle)) {
			$w = fgets($handle);
		}
		$ret = pclose($handle);
		if (0 != $ret) {
			echo "Binary mysqldump: error (code: $ret)";
			// Keep counter of failures? Change value of binsqldump?
		}
	} else {
		echo "Binary mysqldump error: bindump popen failed";
	}
	@unlink($pfile);// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
	return $backup_file;
}

/**
	*  * Add the top level menu page.
	*   */
function freebackup_options_page() {
	    add_menu_page(
            'Free backup',
            'Free backup options',
            'manage_options',
            'free_backup_options',
            'freebackup_options_page_html'
        );
}

function freebackup_options_page_html() {
	// check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_GET['settings-updated'] ) ) {
	     // add settings saved message with the class of "updated"
		add_settings_error( 'freebackup_messages', 'freebackup_message', __( 'Settings Saved', 'freebackup' ), 'updated' );
	}

	settings_errors( 'freebackup_messages' );
	?>
	<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<form action="options.php" method="post">
	<?php
		settings_fields( 'freebackup' );
		do_settings_sections( 'freebackup' );
		submit_button( 'Save Settings' );
	?>
	</form>
	</div>
	<?php
}
 
/**
 *  * Register our freebackup_options_page to the admin_menu action hook.
 *   */
add_action( 'admin_menu', 'freebackup_options_page' );

function freebackup_section_sftp_settings_callback() {
	echo "Enter settings for sFTP connection";
}

function freebackup_section_cron_settings_callback() {
	echo "Enter settings for your automatic backup schedule";
}

function freebackup_field_sftp_host_cb( $args ) {
	$options = get_option( 'freebackup_options' );
	if ( isset( $options[ $args[ 'label_for' ] ] ) )
		$value = esc_attr( $options[ $args[ 'label_for' ] ] );
	else
		$value = "";
	?>
	<input type="text" id="<?php echo esc_attr( $args['label_for'] ); ?>"
	name="freebackup_options[<?php
	echo esc_attr ( $args['label_for'] );
	?>]" value="<?php
	echo $value;
	?>">
	<?php
}

function freebackup_field_sftp_username_cb( $args ) {
	$options = get_option( 'freebackup_options' );
	if ( isset( $options[ $args[ 'label_for' ] ] ) )
		$value = esc_attr( $options[ $args[ 'label_for' ] ] );
	else
		$value = "";
	?>
	<input type="text" id="<?php echo esc_attr( $args['label_for'] ); ?>"
	name="freebackup_options[<?php
	echo esc_attr ( $args['label_for'] );
	?>]" value="<?php
	echo $value;
	?>">
	<?php
}

function freebackup_field_sftp_privkey_file_path_cb( $args ) {
	$options = get_option( 'freebackup_options' );
	if ( isset( $options[ $args[ 'label_for' ] ] ) )
		$value = esc_attr( $options[ $args[ 'label_for' ] ] );
	else
		$value = "";
	?>
	<input type="text" id="<?php echo esc_attr( $args['label_for'] ); ?>"
	name="freebackup_options[<?php
	echo esc_attr ( $args['label_for'] );
	?>]" value="<?php
	echo $value;
	?>">
	<?php
	echo "<p class=\"description\">Hint: your WordPress is installed at this path: ".ABSPATH."</p>";
}

function freebackup_field_sftp_password_cb( $args ) {
	$options = get_option( 'freebackup_options' );
	if ( isset( $options[ $args[ 'label_for' ] ] ) )
		$value = esc_attr( $options[ $args[ 'label_for' ] ] );
	else
		$value = "";
	?>
	<input type="password" id="<?php echo esc_attr( $args['label_for'] ); ?>"
	name="freebackup_options[<?php
	echo esc_attr ( $args['label_for'] );
	?>]" value="<?php
	echo $value;
	?>">
	<?php
}

function freebackup_field_sftp_remote_path_cb( $args ) {
	$options = get_option( 'freebackup_options' );
	if ( isset( $options[ $args[ 'label_for' ] ] ) )
		$value = esc_attr( $options[ $args[ 'label_for' ] ] );
	else
		$value = "";
	?>
	<input type="text" id="<?php echo esc_attr( $args['label_for'] ); ?>"
	name="freebackup_options[<?php
	echo esc_attr ( $args['label_for'] );
	?>]" value="<?php
	echo $value;
	?>">
	<?php
}

function freebackup_field_sftp_port_cb( $args ) {
	$options = get_option( 'freebackup_options' );
	if ( isset( $options[ $args[ 'label_for' ] ] ) )
		$value = esc_attr( $options[ $args[ 'label_for' ] ] );
	else
		$value = "";
	?>
	<input type="number" id="<?php echo esc_attr( $args['label_for'] ); ?>"
	name="freebackup_options[<?php
	echo esc_attr ( $args['label_for'] );
	?>]" value="<?php
	echo $value;
	?>">
	<?php
}

function freebackup_field_sftp_pwd_or_key_cb( $args ) {
	$options = get_option( 'freebackup_options' );
	if ( isset ( $options[ $args[ 'label_for' ] ] ) )
		$value = esc_attr( $options[ $args[ 'label_for' ] ] );
	else
		$value = "";

	?>
	<input type="radio" name="freebackup_options[<?php echo esc_attr( $args['label_for'] ); ?>]" value="password" <?php checked("password", $value, true); ?>>password
	<input type="radio" name="freebackup_options[<?php echo esc_attr( $args['label_for'] ); ?>]" value="key" <?php checked("key", $value, true); ?>>public key
   <?php
}

function freebackup_settings_init() {
	register_setting( 'freebackup', 'freebackup_options' );

	add_settings_section(
		'freebackup_section_sftp_settings',
		__( 'sFTP settings', 'freebackup' ), 'freebackup_section_sftp_settings_callback',
		'freebackup'
	);

	add_settings_section(
		'freebackup_section_cron_settings',
		__( 'Backup schedule settings', 'freebackup' ), 'freebackup_section_cron_settings_callback',
		'freebackup'
	);

	add_settings_field(
		'freebackup_sftp_host',
		__( 'Hostname', 'freebackup' ),
		'freebackup_field_sftp_host_cb',
		'freebackup',
		'freebackup_section_sftp_settings',
		array(
			'label_for' => 'freebackup_field_sftp_host',
		)
	);

	add_settings_field(
		'freebackup_sftp_port',
		__( 'Port number', 'freebackup' ),
		'freebackup_field_sftp_port_cb',
		'freebackup',
		'freebackup_section_sftp_settings',
		array(
			'label_for' => 'freebackup_field_sftp_port',
		)
	);

	add_settings_field(
		'freebackup_sftp_username',
		__( 'Username', 'freebackup' ),
		'freebackup_field_sftp_username_cb',
		'freebackup',
		'freebackup_section_sftp_settings',
		array(
			'label_for' => 'freebackup_field_sftp_username',
		)
	);

	add_settings_field(
		'freebackup_sftp_pwd_or_key',
		__( 'Using password or private key?', 'freebackup' ),
		'freebackup_field_sftp_pwd_or_key_cb',
		'freebackup',
		'freebackup_section_sftp_settings',
		array(
			'label_for' => 'freebackup_field_sftp_pwd_or_key',
		)
	);

	add_settings_field(
		'freebackup_sftp_privkey_file_path',
		__( 'Private key file path', 'freebackup' ),
		'freebackup_field_sftp_privkey_file_path_cb',
		'freebackup',
		'freebackup_section_sftp_settings',
		array(
			'label_for' => 'freebackup_field_sftp_privkey_file_path',
		)
	);

	add_settings_field(
		'freebackup_sftp_password',
		__( 'Password', 'freebackup' ),
		'freebackup_field_sftp_password_cb',
		'freebackup',
		'freebackup_section_sftp_settings',
		array(
			'label_for' => 'freebackup_field_sftp_password',
		)
	);

	add_settings_field(
		'freebackup_sftp_remote_path',
		__( 'Path where you want to put the file on remote server', 'freebackup' ),
		'freebackup_field_sftp_remote_path_cb',
		'freebackup',
		'freebackup_section_sftp_settings',
		array(
			'label_for' => 'freebackup_field_sftp_remote_path',
		)
	);
}

add_action( 'admin_init', 'freebackup_settings_init' );

function freebackup_cron_func() {
	$backup = do_mysql_dump();
	upload_dump_via_sftp( $backup );
}

function activate_my_cron_job() {
	if ( ! wp_next_scheduled( 'freebackup_cron_hook' ) )
		wp_schedule_event(time(), 'daily', 'freebackup_cron_hook');
}

function deactivate_my_cron_job() {
	wp_clear_scheduled_hook('my_cron_hook');
}

register_activation_hook( __FILE__, 'activate_my_cron_job' );
register_deactivation_hook( __FILE__, 'deactivate_my_cron_job' );
add_action( 'freebackup_cron_hook', 'freebackup_cron_func' );
?>
