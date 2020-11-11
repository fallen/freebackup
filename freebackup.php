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

$dbhandle_isgz = true;
$dbhandle = 0;

	/**
	 * Convert hexadecimal (base16) number into binary (base2) and no need to worry about the platform-dependent of 32bit/64bit size limitation
	 *
	 * @param String $hex Hexadecimal number
	 * @return String a base2 format of the given hexadecimal number
	 */
	function my_hex2bin($hex) {
		$table = array(
			'0' => '0000',
			'1' => '0001',
			'2' => '0010',
			'3' => '0011',
			'4' => '0100',
			'5' => '0101',
			'6' => '0110',
			'7' => '0111',
			'8' => '1000',
			'9' => '1001',
			'a' => '1010',
			'b' => '1011',
			'c' => '1100',
			'd' => '1101',
			'e' => '1110',
			'f' => '1111'
		);
		$bin = '';

		if (!preg_match('/^[0-9a-f]+$/i', $hex)) return '';

		for ($i = 0; $i < strlen($hex); $i++) {
			$bin .= $table[strtolower(substr($hex, $i, 1))];
		}

		return $bin;
	}

	function mylog($line) {
		file_put_contents(get_freebackup_dir().'/log_'.date("j.n.Y").'.log', $line.PHP_EOL, FILE_APPEND);
	}

	/**
	 *
	 * This function is adapted from the set_sql_mode() method in WordPress wpdb class but with few modifications applied, this can be used to switch between different sets of SQL modes.
	 *
	 * @see https://developer.wordpress.org/reference/classes/wpdb/set_sql_mode/
	 * @see https://dev.mysql.com/doc/refman/5.6/en/sql-mode.html
	 * @see https://dev.mysql.com/doc/refman/5.7/en/sql-mode.html
	 * @see https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html
	 * @see https://dev.mysql.com/doc/refman/5.6/en/server-system-variables.html#sysvar_sql_mode
	 * @see https://dev.mysql.com/doc/refman/5.7/en/server-system-variables.html#sysvar_sql_mode
	 * @see https://dev.mysql.com/doc/refman/8.0/en/server-system-variables.html#sysvar_sql_mode
	 * @see https://mariadb.com/kb/en/library/sql-mode/#strict-mode
	 * @see https://mariadb.com/kb/en/library/sql-mode/#setting-sql_mode
	 *
	 * @param Array				   $modes		 - Optional. A list of SQL modes to set.
	 * @param Array				   $remove_modes - modes to remove if they are currently active
	 * @param Resource|Object|NULL $db_handle	 - Optional. If specified, it should either the valid database link identifier(resource) given by mysql(i) or null to instead use the global WPDB object, or a WPDB-compatible object.
	 */
	function set_sql_mode($modes = array(), $remove_modes = array(), $db_handle = null) {

		global $wpdb;
		
		$wpdb_handle_if_used = (null !== $db_handle && is_a($db_handle, 'WPDB')) ? $db_handle : $wpdb;
		
		// If any of these are set, they will be unset
		$strict_modes = array(
			// according to mariadb and mysql docs, strict mode can be one of these or both
			'STRICT_TRANS_TABLES',
			'STRICT_ALL_TABLES',
		);

		$incompatible_modes = array_unique(array_merge(array(
			'NO_ZERO_DATE',
			'ONLY_FULL_GROUP_BY',
			'TRADITIONAL',
		), $strict_modes));

//		$class = get_class();

		if (is_null($db_handle) || is_a($db_handle, 'WPDB')) {
			$initial_modes_str = $wpdb_handle_if_used->get_var('SELECT @@SESSION.sql_mode');
		} else {
			$initial_modes_str = call_user_func_array(array($class, 'get_system_variable'), array('sql_mode', $db_handle));
		}
		if (is_scalar($initial_modes_str) && !is_bool($initial_modes_str)) {
			$modes = array_unique(array_merge($modes, array_change_key_case(explode(',', $initial_modes_str), CASE_UPPER)));
		} else {
			unset($initial_modes_str);
		}

		$modes = array_change_key_case($modes, CASE_UPPER);

		$unwanted_modes = array_merge($incompatible_modes, $remove_modes);
		
		foreach ($modes as $i => $mode) {
			if (in_array($mode, $unwanted_modes)) {
				unset($modes[$i]);
			}
		}

		$modes_str = implode(',', $modes);

		if (is_null($db_handle) || is_a($db_handle, 'WPDB')) {
			$res = $wpdb_handle_if_used->query($wpdb_handle_if_used->prepare("SET SESSION sql_mode = %s", $modes_str));
		} else {
			$res = call_user_func_array(array($class, 'set_system_variable'), array('sql_mode', $modes_str, $db_handle));
		}

	}

	/**
	 * Replace last occurence
	 *
	 * @param  String  $search         The value being searched for, otherwise known as the needle
	 * @param  String  $replace        The replacement value that replaces found search values
	 * @param  String  $subject        The string or array being searched and replaced on, otherwise known as the haystack
	 * @param  Boolean $case_sensitive Whether the replacement should be case sensitive or not
	 *
	 * @return String
	 */
	function str_lreplace($search, $replace, $subject, $case_sensitive = true) {
		$pos = $case_sensitive ? strrpos($subject, $search) : strripos($subject, $search);
		if (false !== $pos) $subject = substr_replace($subject, $replace, $pos, strlen($search));
		return $subject;
	}

	/**
	 * Add backquotes to tables and db-names in SQL queries. Taken from phpMyAdmin.
	 *
	 * @param  string $a_name - the table name
	 * @return string - the quoted table name
	 */
	function backquote($a_name) {
		if (!empty($a_name) && '*' != $a_name) {
			if (is_array($a_name)) {
				$result = array();
				foreach ($a_name as $key => $val) {
					$result[$key] = '`'.$val.'`';
				}
				return $result;
			} else {
				return '`'.$a_name.'`';
			}
		} else {
			return $a_name;
		}
	}

function stow($query_line) {
	global $dbhandle_isgz, $dbhandle;
	if ($dbhandle_isgz) {
		if (false == ($ret = @gzwrite($dbhandle, $query_line))) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			// $updraftplus->log(__('There was an error writing a line to the backup script:', 'updraftplus').'  '.$query_line.'  '.$php_errormsg, 'error');
		}
	} else {
		if (false == ($ret = @fwrite($dbhandle, $query_line))) {// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			// $updraftplus->log(__('There was an error writing a line to the backup script:', 'updraftplus').'  '.$query_line.'  '.$php_errormsg, 'error');
		}
	}
	return $ret;
}

function find_working_sqldump() {
	$sqldump_candidates = "/usr/bin/mysqldump,/bin/mysqldump,/usr/local/bin/mysqldump,/usr/sfw/bin/mysqldump,/usr/xdg4/bin/mysqldump,/opt/bin/mysqldump";
	foreach (explode(',', $sqldump_candidates) as $potsql) {
		if (!@is_executable($potsql)) continue;// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		return $potsql;
	}
	return false;
}

function get_freebackup_dir() {
	$freebackup_dir = WP_CONTENT_DIR."/freebackup";

	if ( !is_dir($freebackup_dir) || !is_file($freebackup_dir.'/index.html') || !is_file($freebackup_dir.'/.htaccess') ) {
		@mkdir($freebackup_dir, 0775, true);
		@file_put_contents($freebackup_dir.'/index.html', "");
		@file_put_contents($freebackup_dir.'/.htaccess', "deny from all");
	}

	return $freebackup_dir;
}

	/**
	 * Replace the first, and only the first, instance within a string
	 *
	 * @param String $needle   - the search term
	 * @param String $replace  - the replacement term
	 * @param String $haystack - the string to replace within
	 *
	 * @return String - the filtered string
	 */
	function str_replace_once($needle, $replace, $haystack) {
		$pos = strpos($haystack, $needle);
		return (false !== $pos) ? substr_replace($haystack, $replace, $pos, strlen($needle)) : $haystack;
	}

	/**
	 * Write out the initial backup information for a table to the currently open file
	 *
	 * @param String $table			  - Full name of database table to backup
	 * @param String $dump_as_table	  - Table name to use when writing out
	 * @param String $table_type	  - Table type - 'VIEW' is supported; otherwise it is treated as an ordinary table
	 * @param Array  $table_structure - Table structure as returned by a DESCRIBE command
	 */
	function write_table_backup_beginning($table, $dump_as_table, $table_type, $table_structure) {

		global $wpdb;
	
		stow("\n# Delete any existing table ".backquote($table)."\n\nDROP TABLE IF EXISTS " . backquote($dump_as_table).";\n");
		
		if ('VIEW' == $table_type) {
			stow("DROP VIEW IF EXISTS " . backquote($dump_as_table) . ";\n");
		}
		
		$description = ('VIEW' == $table_type) ? 'view' : 'table';
		
		stow("\n# Table structure of $description ".backquote($table)."\n\n");
		
		$create_table = $wpdb->get_results("SHOW CREATE TABLE ".backquote($table), ARRAY_N);
		if (false === $create_table) {
			stow("#\n# Error with SHOW CREATE TABLE for $table\n#\n");
		}
		$create_line = str_lreplace('TYPE=', 'ENGINE=', $create_table[0][1]);

		// Remove PAGE_CHECKSUM parameter from MyISAM - was internal, undocumented, later removed (so causes errors on import)
		if (preg_match('/ENGINE=([^\s;]+)/', $create_line, $eng_match)) {
			$engine = $eng_match[1];
			if ('myisam' == strtolower($engine)) {
				$create_line = preg_replace('/PAGE_CHECKSUM=\d\s?/', '', $create_line, 1);
			}
		}

		if ($dump_as_table !== $table) $create_line = str_replace_once($table, $dump_as_table, $create_line);

		stow($create_line.' ;');
		
		if (false === $table_structure) {
			stow("#\n# Error getting $description structure of $table\n#\n");
		}

		// Add a comment preceding the beginning of the data
		stow("\n\n# ".sprintf("Data contents of $description %s", backquote($table))."\n\n");

	}
	/**
	 * Taken partially from phpMyAdmin and partially from Alain Wolf, Zurich - Switzerland to use the WordPress $wpdb object
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	 * Modified by Scott Merrill (http://www.skippy.net/)
	 *
	 * @param String		  $table			   - Full name of database table to backup
	 * @param String		  $table_type		   - Table type - 'VIEW' is supported; otherwise it is treated as an ordinary table
	 * @param Integer|Boolean $start_record		   - Specify the starting record, or true to start at the beginning. Our internal page size is fixed at 1000 (though within that we might actually query in smaller batches).
	 * @param Boolean		  $can_use_primary_key - Whether it is allowed to perform quicker SELECTS based on the primary key. The intended use case for false is to support backups running during a version upgrade.
	 *
	 * @return Integer|Array|WP_Error - a WP_Error to indicate an error; an array indicates that it finished (if it includes 'next_record' that means it finished via producing something); an integer to indicate the next page the case that there are more to do
	 */
	function backup_table($table, $table_type = 'BASE TABLE', $start_record = true, $can_use_primary_key = true) {
		$process_pages = 100;

		global $duplicate_tables_exist, $wpdb;
		$table_prefix = get_table_prefix();

		mylog("backup_table(".$table.")");
	
		$microtime = microtime(true);
		$total_rows = 0;

		// Deal with Windows/old MySQL setups with erroneous table prefixes differing in case
		$dump_as_table = (false == $duplicate_tables_exist && 0 === stripos($table, $table_prefix) && 0 !== strpos($table, $table_prefix)) ? $table_prefix.substr($table, strlen($table_prefix)) : $table;

		$table_structure = $wpdb->get_results("DESCRIBE ".backquote($table));
		if (!$table_structure) {
			mylog(__('Error getting table details', 'updraftplus') . ": $table", 'error');
			return new WP_Error('table_details_error', 'Error getting the table details');
		}
	
		// If at the beginning of the dump for a table, then add the DROP and CREATE statements
		if (true === $start_record) {
			write_table_backup_beginning($table, $dump_as_table, $table_type, $table_structure);
		}

		$table_data = array();
		if ('VIEW' != $table_type) {
			$fields = array();
			$defs = array();
			$integer_fields = array();
			$binary_fields = array();
			$bit_fields = array();
			$bit_field_exists = false;

			$primary_key = false;
			$primary_key_type = false;
			
			// $table_structure was from "DESCRIBE $table"
			foreach ($table_structure as $struct) {
			
				if (isset($struct->Key) && 'PRI' == $struct->Key) {
					$primary_key = $struct->Field;
					$primary_key_type = $struct->Type;
				}
			
				if ((0 === strpos($struct->Type, 'tinyint')) || (0 === strpos(strtolower($struct->Type), 'smallint'))
					|| (0 === strpos(strtolower($struct->Type), 'mediumint')) || (0 === strpos(strtolower($struct->Type), 'int')) || (0 === strpos(strtolower($struct->Type), 'bigint'))
				) {
						$defs[strtolower($struct->Field)] = (null === $struct->Default ) ? 'NULL' : $struct->Default;
						$integer_fields[strtolower($struct->Field)] = true;
				}
				
				if ((0 === strpos(strtolower($struct->Type), 'binary')) || (0 === strpos(strtolower($struct->Type), 'varbinary')) || (0 === strpos(strtolower($struct->Type), 'tinyblob')) || (0 === strpos(strtolower($struct->Type), 'mediumblob')) || (0 === strpos(strtolower($struct->Type), 'blob')) || (0 === strpos(strtolower($struct->Type), 'longblob'))) {
					$binary_fields[strtolower($struct->Field)] = true;
				}
				
				if (preg_match('/^bit(?:\(([0-9]+)\))?$/i', trim($struct->Type), $matches)) {
					if (!$bit_field_exists) $bit_field_exists = true;
					$bit_fields[strtolower($struct->Field)] = !empty($matches[1]) ? max(1, (int) $matches[1]) : 1;
					// the reason why if bit fields are found then the fields need to be cast into binary type is that if mysqli_query function is being used, mysql will convert the bit field value to a decimal number and represent it in a string format whereas, if mysql_query function is being used, mysql will not convert it to a decimal number but instead will keep it retained as it is
					$struct->Field = "CAST(".backquote(str_replace('`', '``', $struct->Field))." AS BINARY) AS ".backquote(str_replace('`', '``', $struct->Field));
					$fields[] = $struct->Field;
				} else {
					$fields[] = backquote(str_replace('`', '``', $struct->Field));
				}
			}
			
			// N.B. At this stage this is for optimisation, mainly targets what is used on the core WP tables (bigint(20)); a value can be relied upon, but false is not definitive
			$use_primary_key = false;
			if ($can_use_primary_key && false !== $primary_key && preg_match('#^(small|medium|big)?int\(#i', $primary_key_type)) {
				$use_primary_key = true;
				if (preg_match('# unsigned$#i', $primary_key_type)) {
					if (true === $start_record) $start_record = -1;
				} else {
					if (true === $start_record) {
						$min_value = $wpdb->get_var('SELECT MIN('.backquote($primary_key).') FROM '.backquote($table));
						$start_record = (is_numeric($min_value) && $min_value) ? (int) $min_value - 1 : -1;
					}
				}
			}
			$search = array("\x00", "\x0a", "\x0d", "\x1a");
			$replace = array('\0', '\n', '\r', '\Z');

			// Experimentation here shows that on large tables (we tested with 180,000 rows) on MyISAM, 1000 makes the table dump out 3x faster than the previous value of 100. After that, the benefit diminishes (increasing to 4000 only saved another 12%)

			$fetch_rows = 500;
			
			$select = $bit_field_exists ? implode(', ', $fields) : '*';
			// FIXME: use that or fix the use primary key logic
			$use_primary_key = false;
			// Loop which retrieves data
			do {

				@set_time_limit(900);// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged

				// Reset back to that which has constructed before the loop began
				
				if ($use_primary_key) {
					$limit_statement = sprintf('LIMIT %d', $fetch_rows);
					
					$order_by = 'ORDER BY '.backquote($primary_key).' ASC';
					mylog("use primary key");
				} else {
					mylog("DONT use primary key");
					$order_by = '';
					if (true === $start_record) $start_record = 0;
					$limit_statement = sprintf('LIMIT %d, %d', $start_record, $fetch_rows);
				}
				
				// $wpdb->prepare() not needed (will throw a notice) as there are no parameters
				
				$select_sql = "SELECT $select FROM ".backquote($table)." $order_by $limit_statement";

				mylog("executing ".$select_sql);
				$table_data = $wpdb->get_results($select_sql, ARRAY_A);
				
				if (!$table_data) continue;
				$entries = 'INSERT INTO '.backquote($dump_as_table).' VALUES ';

				// \x08\\x09, not required
				
				$thisentry = '';
				foreach ($table_data as $row) {
					$total_rows++;
					$values = array();
					foreach ($row as $key => $value) {
					
						if ($use_primary_key && strtolower($primary_key) == strtolower($key) && $value > $start_record) {
							$start_record = $value;
						}
					
						if (isset($integer_fields[strtolower($key)])) {
							// make sure there are no blank spots in the insert syntax,
							// yet try to avoid quotation marks around integers
							$value = (null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
							$values[] = ('' === $value) ? "''" : $value;
						} elseif (isset($binary_fields[strtolower($key)])) {
							if (null === $value) {
								$values[] = 'NULL';
							} elseif ('' === $value) {
								$values[] = "''";
							} else {
								$values[] = "0x" . bin2hex(str_repeat("0", floor(strspn($value, "0") / 4)).$value);
							}
						} elseif (isset($bit_fields[$key])) {
							mbstring_binary_safe_encoding();
							$val_len = strlen($value);
							reset_mbstring_encoding();
							$hex = '';
							for ($i=0; $i<$val_len; $i++) {
								$hex .= sprintf('%02X', ord($value[$i]));
							}
							$values[] = "b'".str_pad(my_hex2bin($hex), $bit_fields[$key], '0', STR_PAD_LEFT)."'";
						} else {
							$values[] = (null === $value) ? 'NULL' : "'" . str_replace($search, $replace, str_replace('\'', '\\\'', str_replace('\\', '\\\\', $value))) . "'";
						}
					}
					
					if ($thisentry) $thisentry .= ",\n ";
					$thisentry .= '('.implode(', ', $values).')';
					// Flush every 512KB
					if (strlen($thisentry) > 524288) {
						stow(" \n".$entries.$thisentry.';');
						$thisentry = "";
					}
					
				}
				if ($thisentry) stow(" \n".$entries.$thisentry.';');
				
				if (!$use_primary_key) {
					$start_record += $fetch_rows;
				}
				
				if ($process_pages > 0) $process_pages--;
				
			} while (count($table_data) > 0 && (-1 == $process_pages || $process_pages > 0));
		}
		
		//$updraftplus->log("Table $table: Rows added in this batch (next record: $start_record): $total_rows in ".sprintf("%.02f", max(microtime(true)-$microtime, 0.00001))." seconds");

		// If all data has been fetched, then write out the closing comment, and return false (which indicates that there is nothing left)
		if (-1 == $process_pages || 0 == count($table_data)) {
			stow("\n# End of data contents of table ".backquote($table)."\n\n");
			return is_numeric($start_record) ? array('next_record' => (int) $start_record) : array();
		}

		return is_numeric($start_record) ? (int) $start_record : $start_record;
		
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
		$path = "db_backup.sql.gz";
	else
		$path = $remote_path."/db_backup.sql.gz";


	mylog("path: $path");
	$res = $sftp->put( $path, $backup_file, \phpseclib\Net\SFTP::SOURCE_LOCAL_FILE );
	if ( !$res )
		echo "sFTP upload failed: ".$sftp->getLastSFTPError();

	@unlink($backup_file);
}

	function get_table_prefix() {
		global $wpdb;
		if (is_multisite()) {
			// In this case (which should only be possible on installs upgraded from pre WP 3.0 WPMU), $wpdb->get_blog_prefix() cannot be made to return the right thing. $wpdb->base_prefix is not explicitly marked as public, so we prefer to use get_blog_prefix if we can, for future compatibility.
			$prefix = $wpdb->base_prefix;
		} else {
			$prefix = $wpdb->get_blog_prefix(0);
		}
		return $prefix;
	}

	function get_stored_routines() {

		global $wpdb;

		$old_val = $wpdb->suppress_errors();
		try {
			$err_msg = __('An error occurred while attempting to retrieve routine status (%s %s)', 'updraftplus');
			$function_status = $wpdb->get_results($wpdb->prepare('SHOW FUNCTION STATUS WHERE DB = %s', DB_NAME), ARRAY_A);
			if (!empty($wpdb->last_error)) throw new Exception(sprintf($err_msg, $wpdb->last_error.' -', $wpdb->last_query), 0);
			$procedure_status = $wpdb->get_results($wpdb->prepare('SHOW PROCEDURE STATUS WHERE DB = %s', DB_NAME), ARRAY_A);
			if (!empty($wpdb->last_error)) throw new Exception(sprintf($err_msg, $wpdb->last_error.' -', $wpdb->last_query), 0);
			$stored_routines = array_merge((array) $function_status, (array) $procedure_status);
			foreach ((array) $stored_routines as $key => $routine) {
				if (empty($routine['Name']) || empty($routine['Type'])) continue;
				$routine_name = $routine['Name'];
				// Since routine name can include backquotes and routine name is typically enclosed with backquotes as well, the backquote escaping for the routine name can be done by adding a leading backquote
				$quoted_escaped_routine_name = backquote(str_replace('`', '``', $routine_name));
				$routine = $wpdb->get_results($wpdb->prepare('SHOW CREATE %1$s %2$s', $routine['Type'], $quoted_escaped_routine_name), ARRAY_A);
				if (!empty($wpdb->last_error)) throw new Exception(sprintf(__('An error occurred while attempting to retrieve the routine SQL/DDL statement (%s %s)', 'updraftplus'), $wpdb->last_error.' -', $wpdb->last_query), 1);
				$stored_routines[$key] = array_merge($stored_routines[$key], $routine ? $routine[0] : array());
			}
		} catch (Exception $ex) {
			$stored_routines = new WP_Error(1 === $ex->getCode() ? 'routine_sql_error' : 'routine_status_error', $ex->getMessage());
		}
		$wpdb->suppress_errors($old_val);

		return $stored_routines;
	}


	function cb_get_name_base_type($a) {
		return array('name' => $a[0], 'type' => 'BASE TABLE');
	}

	function cb_get_name_type($a) {
		return array('name' => $a[0], 'type' => $a[1]);
	}

	function cb_get_name($a) {
		return $a['name'];
	}

	/**
	 * The purpose of this function is to make sure that the options table is put in the database first, then the users table, then the site + blogs tables (if present - multisite), then the usermeta table; and after that the core WP tables - so that when restoring we restore the core tables first
	 *
	 * @param Array $a_arr First array to be compared
	 * @param Array $b_arr Second array to be compared
	 * @return Integer - according to the rules of usort()
	 */
	function backup_db_sorttables($a_arr, $b_arr) {

		global $wpdb;
		$a = $a_arr['name'];
		$a_table_type = $a_arr['type'];
		$b = $b_arr['name'];
		$b_table_type = $b_arr['type'];
	
		// Views must always go after tables (since they can depend upon them)
		if ('VIEW' == $a_table_type && 'VIEW' != $b_table_type) return 1;
		if ('VIEW' == $b_table_type && 'VIEW' != $a_table_type) return -1;
	
		if ($a == $b) return 0;
		$our_table_prefix = get_table_prefix();
		if ($a == $our_table_prefix.'options') return -1;
		if ($b == $our_table_prefix.'options') return 1;
		if ($a == $our_table_prefix.'site') return -1;
		if ($b == $our_table_prefix.'site') return 1;
		if ($a == $our_table_prefix.'blogs') return -1;
		if ($b == $our_table_prefix.'blogs') return 1;
		if ($a == $our_table_prefix.'users') return -1;
		if ($b == $our_table_prefix.'users') return 1;
		if ($a == $our_table_prefix.'usermeta') return -1;
		if ($b == $our_table_prefix.'usermeta') return 1;

		if (empty($our_table_prefix)) return strcmp($a, $b);

		try {
			$core_tables = array_merge($wpdb->tables, $wpdb->global_tables, $wpdb->ms_global_tables);
		} catch (Exception $e) {
			mylog($e->getMessage());
		}
		
		if (empty($core_tables)) $core_tables = array('terms', 'term_taxonomy', 'termmeta', 'term_relationships', 'commentmeta', 'comments', 'links', 'postmeta', 'posts', 'site', 'sitemeta', 'blogs', 'blogversions', 'blogmeta');

		$na = str_replace_once($our_table_prefix, '', $a);
		$nb = str_replace_once($our_table_prefix, '', $b);
		if (in_array($na, $core_tables) && !in_array($nb, $core_tables)) return -1;
		if (!in_array($na, $core_tables) && in_array($nb, $core_tables)) return 1;
		return strcmp($a, $b);
	}

	/**
	 * This function is resumable, using the following method:
	 * Each table is written out to ($final_filename).table.tmp
	 * When the writing finishes, it is renamed to ($final_filename).table
	 * When all tables are finished, they are concatenated into the final file
	 *
	 * @param String $already_done Status of backup
	 * @param String $whichdb      Indicated which database is being backed up
	 * @param Array  $dbinfo       is only used when whichdb != 'wp'; and the keys should be: user, pass, name, host, prefix
	 *
	 * @return Boolean|String - the basename of the database backup, or false for failure
	 */
	function backup_db($already_done = 'begun', $whichdb = 'wp', $dbinfo = array()) {

		global $wpdb;
		global $dbhandle;
		global $dbhandle_isgz;	

		mylog("backup_db(".$whichdb.")");

		// The table prefix after being filtered - i.e. what filters what we'll actually backup
		$table_prefix = get_table_prefix();
		// The unfiltered table prefix - i.e. the real prefix that things are relative to
		$table_prefix_raw = get_table_prefix();
		$dbinfo['host'] = DB_HOST;
		$dbinfo['name'] = DB_NAME;
		$dbinfo['user'] = DB_USER;
		$dbinfo['pass'] = DB_PASSWORD;

		set_sql_mode(array(), array('ANSI_QUOTES'), $wpdb);

		$errors = 0;

		$freebackup_dir = get_freebackup_dir();
		$backup_file_base = tempnam($freebackup_dir, "FreeBackup");
		$file_base = basename($backup_file_base);


		$binsqldump = find_working_sqldump();

		$total_tables = 0;

		// SHOW FULL - so that we get to know whether it's a BASE TABLE or a VIEW
		$all_tables = $wpdb->get_results("SHOW FULL TABLES", ARRAY_N);
		
		if (empty($all_tables) && !empty($wpdb->last_error)) {
			$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
			$all_tables = array_map('cb_get_name_base_type', $all_tables);
		} else {
			$all_tables = array_map('cb_get_name_type', $all_tables);
		}

		// If this is not the WP database, then we do not consider it a fatal error if there are no tables
		if (0 == count($all_tables)) {
			echo __("Error: No WordPress database tables found (SHOW TABLES returned nothing)", 'freebackup');
			echo __("No database tables found", 'freebackup');
			die;
		}

		// Put the options table first
		usort($all_tables, 'backup_db_sorttables');
		
		$all_table_names = array_map('cb_get_name', $all_tables);


		$duplicate_tables_exist = false;
		foreach ($all_table_names as $table) {
			if (strtolower($table) != $table && in_array(strtolower($table), $all_table_names)) {
				$duplicate_tables_exist = true;
				echo "Tables with names differing only based on case-sensitivity exist in the MySQL database: $table / ".strtolower($table);
			}
		}
		$how_many_tables = count($all_tables);

		$stitch_files = array();
		$found_options_table = false;
		$is_multisite = is_multisite();

		// Gather the list of files that look like partial table files once only
		$potential_stitch_files = array();
		$table_file_prefix_base= $file_base.'-db-table-';
		if (false !== ($dir_handle = opendir($freebackup_dir))) {
			while (false !== ($e = readdir($dir_handle))) {
				// The 'r' in 'tmpr' indicates that the new scheme is being used. N.B. That does *not* imply that the table has a usable primary key.
				if (!is_file($freebackup_dir.'/'.$e)) continue;
				if (preg_match('#'.$table_file_prefix_base.'.*\.table\.tmpr?(\d+)\.gz$#', $e, $matches)) {
					// We need to stich them in order
					$potential_stitch_files[] = $e;
				}
			}
		} else {
			echo "Error: Failed to open directory for reading";
			echo __("Failed to open directory for reading:", 'updraftplus').' '.$freebackup_dir;
		}
		
		foreach ($all_tables as $ti) {

			$table = $ti['name'];
			$stitch_files[$table] = array();
			$table_type = $ti['type'];
		
			$manyrows_warning = false;
			$total_tables++;

			// Increase script execution time-limit to 15 min for every table.
		// This check doesn't strictly get all possible duplicates; it's only designed for the case that can happen when moving between deprecated Windows setups and Linux
			@set_time_limit(900);// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			// The table file may already exist if we have produced it on a previous run
			$table_file_prefix = $file_base.'-db-table-'.$table.'.table';

			if ('wp' == $whichdb && (strtolower($table_prefix_raw.'options') == strtolower($table) || ($is_multisite && (strtolower($table_prefix_raw.'sitemeta') == strtolower($table) || strtolower($table_prefix_raw.'1_options') == strtolower($table))))) $found_options_table = true;

			// Already finished?
			if (file_exists($freebackup_dir.'/'.$table_file_prefix.'.gz')) {
				$stitched = count($stitch_files, COUNT_RECURSIVE);
				$skip_dblog = (($stitched > 10 && 0 != $stitched % 20) || ($stitched > 100 && 0 != $stitched % 100));
				//$updraftplus->log("Table $table: corresponding file already exists; moving on", 'notice', false, $skip_dblog);
				
				$max_record = false;
				foreach ($potential_stitch_files as $e) {
					// The 'r' in 'tmpr' indicates that the new scheme is being used. N.B. That does *not* imply that the table has a usable primary key.
					if (preg_match('#'.$table_file_prefix.'\.tmpr?(\d+)\.gz$#', $e, $matches)) {
						// We need to stich them in order
						$stitch_files[$table][$matches[1]] = $e;
						if (false === $max_record || $matches[1] > $max_record) $max_record = $matches[1];
					}
				}
				$stitch_files[$table][$max_record+1] = $table_file_prefix.'.gz';
				
				// Move on to the next table
				continue;
			}

			// === is needed with strpos/stripos, otherwise 'false' matches (i.e. prefix does not match)
//			if (empty($table_prefix) || (!$duplicate_tables_exist && 0 === stripos($table, $table_prefix)) || ($duplicate_tables_exist && 0 === strpos($table, $table_prefix))) {


				//add_filter('updraftplus_backup_table_sql_where', array($this, 'backup_exclude_jobdata'), 3, 10);

				//$updraftplus->jobdata_set('dbcreating_substatus', array('t' => $table, 'i' => $total_tables, 'a' => $how_many_tables));
				
				// .tmp.gz is the current temporary file. When the row limit has been reached, it is moved to .tmp1.gz, .tmp2.gz, etc. (depending on which already exist). When we're all done, then they all get stitched in.
				
				$db_temp_file = $freebackup_dir.'/'.$table_file_prefix.'.tmp.gz';
				// Open file, store the handle
				$file = $db_temp_file;
				if (function_exists('gzopen')) {
					$dbhandle = @gzopen($file, 'w');// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
					$dbhandle_isgz = true;
				} else {
					$dbhandle = @fopen($file, 'w');// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
					$dbhandle_isgz = false;
				}
				if (false === $dbhandle) {
					echo "ERROR: $file: Could not open the backup file for writing";
					echo $file.": ".__("Could not open the backup file for writing", 'updraftplus');
					return false;
				}

				$table_status = $wpdb->get_row("SHOW TABLE STATUS WHERE Name='$table'");
				
				// Create the preceding SQL statements for the table
				stow("# " . sprintf('Table: %s', backquote($table)) . "\n");
				if (isset($table_status->Rows)) {
					$rows = $table_status->Rows;
//					$updraftplus->log("Table $table: Total expected rows (approximate): ".$rows);
					stow("# Approximate rows expected in table: $rows\n");
/*
					if ($rows > UPDRAFTPLUS_WARN_DB_ROWS) {
						$manyrows_warning = true;
//						$updraftplus->log(sprintf(__("Table %s has very many rows (%s) - we hope your web hosting company gives you enough resources to dump out that table in the backup", 'updraftplus'), $table, $rows).' '.__('If not, you will need to either remove data from this table, or contact your hosting company to request more resources.', 'updraftplus'), 'warning', 'manyrows_'.$this->whichdb_suffix.$table);
					}*/
				}

				// If no check-in last time, then we could in future try the other method (but - any point in retrying slow method on large tables??)

				// New Jul 2014: This attempt to use bindump instead at a lower threshold is quite conservative - only if the last successful run was exactly two resumptions ago - may be useful to expand
				$bindump_threshold = 8000;

//				$bindump = (isset($table_status->Rows) && ($table_status->Rows>$bindump_threshold || (defined('UPDRAFTPLUS_ALWAYS_TRY_MYSQLDUMP') && UPDRAFTPLUS_ALWAYS_TRY_MYSQLDUMP)) && is_string($binsqldump));
				$bindump = false;
				if ( $bindump )
					$bindump = backup_table_bindump($binsqldump, $table);
				
				// Means "start of table". N.B. The meaning of an integer depends upon whether the table has a usable primary key or not.
				$start_record = true;
//				$can_use_primary_key = apply_filters('updraftplus_can_use_primary_key_default', true, $table);
				$can_use_primary_key = false; 
				foreach ($potential_stitch_files as $e) {
					// The 'r' in 'tmpr' indicates that the new scheme is being used. N.B. That does *not* imply that the table has a usable primary key.
					if (preg_match('#'.$table_file_prefix.'\.tmp(r)?(\d+)\.gz$#', $e, $matches)) {
						$stitch_files[$table][$matches[2]] = $e;
						if (true === $start_record || $matches[2] > $start_record) $start_record = $matches[2];
						// Legacy scheme. The purpose of this is to prevent backups failing if one is in progress during an upgrade to a new version that implements the new scheme
						if ('r' !== $matches[1]) $can_use_primary_key = false;
					}
				}
				
				// Legacy file-naming scheme in use
				if (false === $can_use_primary_key && true !== $start_record) {
					$start_record = ($start_record + 100) * 1000;
				}
				
				if (true !== $bindump) {
				
					while (!is_array($start_record) && !is_wp_error($start_record)) {
						
						$start_record = backup_table($table, $table_type, $start_record, $can_use_primary_key);
						
						if (is_integer($start_record) || is_array($start_record)) {
							if ( $dbhandle_isgz )
								gzclose( $dbhandle );
							else
								fclose( $dbhandle );
							
							// Add one here in case no records were returned - don't want to over-write the previous file
							$use_record = is_array($start_record) ? (isset($start_record['next_record']) ? $start_record['next_record']+1 : false) : $start_record;
							if (!$can_use_primary_key) $use_record = (ceil($use_record/100000)-1) * 100;
							
							if (false !== $use_record) {
								// N.B. Renaming using the *next* record is intentional - it allows UD to know where to resume from.
								$rename_base = $table_file_prefix.'.tmp'.($can_use_primary_key ? 'r' : '').$use_record.'.gz';
								
								rename($db_temp_file, $freebackup_dir.'/'.$rename_base);
								$stitch_files[$table][$use_record] = $rename_base;
							}
							$file = $db_temp_file;
							if (function_exists('gzopen')) {
								$dbhandle = @gzopen($file, 'w');// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
								$dbhandle_isgz = true;
							} else {
								$dbhandle = @fopen($file, 'w');// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
								$dbhandle_isgz = false;
							}
							if (false === $dbhandle) {
								mylog("ERROR: $file: Could not open the backup file for writing");
								mylog($file.": ".__("Could not open the backup file for writing", 'freebackup'));
								return false;
							}

							
						} elseif (is_wp_error($start_record)) {
							mylog("Error (table=$table) (".$start_record->get_error_code()."): ".$start_record->get_error_message());
							mylog(__("Failed to backup database table:", 'updraftplus').' '.$start_record->get_error_message().' ('.$start_record->get_error_code().')');
							$errors++;
						}
							
					}
				}

				// If we got this far, then there were enough resources; the warning can be removed
//				if (!empty($manyrows_warning)) $updraftplus->log_remove_warning('manyrows_'.$this->whichdb_suffix.$table);

				if ( $dbhandle_isgz )
					gzclose( $dbhandle );
				else
					fclose( $dbhandle );

				// Renaming the file indicates that writing to it finished
				rename($db_temp_file, $freebackup_dir.'/'.$table_file_prefix.'.gz');
				
				$final_stitch_value = empty($stitch_files[$table]) ? 1 : max(array_keys($stitch_files[$table])) + 1;
				
				$stitch_files[$table][$final_stitch_value] = $table_file_prefix.'.gz';
				
				$total_db_size = 0;
				// This is more verbose than it would be if we weren't supporting PHP 5.2
				foreach ($stitch_files[$table] as $basename) {
					$total_db_size += filesize($freebackup_dir.'/'.$basename);
				}
				
				mylog("Table $table: finishing file(s) (".count($stitch_files[$table]).', '.round($total_db_size/1024, 1).' KB)', 'notice', false, false);
				
/*			} else {
				$total_tables--;
				mylog("Skipping table (lacks our prefix (".$table_prefix.")): $table");
				if (empty($skipped_tables)) $skipped_tables = array();
				// whichdb could be an int in which case to get the name of the database and the array key use the name from dbinfo
				$key = ('wp' === $whichdb) ? 'wp' : $dbinfo['name'];
				if (empty($skipped_tables[$key])) $skipped_tables[$key] = array();
				$skipped_tables[$key][] = $table;
			}*/
		}

		if ('wp' == $whichdb) {
			if (!$found_options_table) {
					// Have seen this happen; not sure how, but it was apparently deterministic; if the current process had been running for a long time, then apparently all database commands silently failed.
					// If we have been running that long, then the resumption may be far off; bring it closer
					echo "Have been running very long, and it seems the database went away; scheduling a resumption and terminating for now";
					die;
			}
		}

		// Race detection - with zip files now being resumable, these can more easily occur, with two running side-by-side
		$backup_final_file_name = $backup_file_base.'-db.gz';
		$time_now = time();
		$time_mod = (int) @filemtime($backup_final_file_name);// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		
		if (file_exists($backup_final_file_name)) {
			mylog("The final database file ($backup_final_file_name) exists, but was apparently not modified within the last 30 seconds (time_mod=$time_mod, time_now=$time_now, diff=".($time_now-$time_mod)."). Thus we assume that another UpdraftPlus terminated; thus we will continue.");
		}

		// Finally, stitch the files together
		if (!function_exists('gzopen')) {
			echo "PHP function is disabled; abort expected: gzopen()";
		}

		$file = $backup_final_file_name;
		if (function_exists('gzopen')) {
			$dbhandle = @gzopen($file, 'w');// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$dbhandle_isgz = true;
		} else {
			$dbhandle = @fopen($file, 'w');// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$dbhandle_isgz = false;
		}
		if (false === $dbhandle) {
			mylog("ERROR: $file: Could not open the backup file for writing");
			mylog($file.": ".__("Could not open the backup file for writing", 'updraftplus'));
			return false;
		}


		// We delay the unlinking because if two runs go concurrently and fail to detect each other (should not happen, but there's no harm in assuming the detection failed) then that would lead to files missing from the db dump
		$unlink_files = array();

		$sind = 1;
		
		foreach ($stitch_files as $table => $table_stitch_files) {
			ksort($table_stitch_files);
			foreach ($table_stitch_files as $table_file) {
				if (!$handle = gzopen($freebackup_dir.'/'.$table_file, "r")) {
					mylog("Error: Failed to open database file for reading: ${table_file}.gz");
					mylog(__("Failed to open database file for reading:", 'updraftplus').' '.$table_file, 'error');
					$errors++;
				} else {
					while ($line = gzgets($handle, 65536)) {
						stow($line);
					}
					gzclose($handle);
					$unlink_files[] = $freebackup_dir.'/'.$table_file;
				}
				$sind++;
				// Came across a database with 7600 tables... adding them all took over 500 seconds; and so when the resumption started up, no activity was detected
			}
		}

		// DB triggers
		if ($wpdb->get_results("SHOW TRIGGERS")) {
			// N.B. DELIMITER is not a valid SQL command; you cannot pass it to the server. It has to be interpreted by the interpreter - e.g. /usr/bin/mysql, or UpdraftPlus, and used to interpret what follows. The effect of this is that using it means that some SQL clients will stumble; but, on the other hand, failure to use it means that others that don't have special support for CREATE TRIGGER may stumble, because they may feed incomplete statements to the SQL server. Since /usr/bin/mysql uses it, we choose to support it too (both reading and writing).
			// Whatever the delimiter is set to needs to be used in the DROP TRIGGER and CREATE TRIGGER commands in this section further down.
			stow("DELIMITER ;;\n\n");
			foreach ($all_tables as $ti) {
				$table = $ti['name'];
				if (!empty($skipped_tables)) {
					if ('wp' == $whichdb) {
						if (in_array($table, $skipped_tables['wp'])) continue;
					} elseif (isset($skipped_tables[$dbinfo['name']])) {
						if (in_array($table, $skipped_tables[$dbinfo['name']])) continue;
					}
				}
				$table_triggers = $wpdb->get_results($wpdb->prepare("SHOW TRIGGERS LIKE %s", $table), ARRAY_A);
				if ($table_triggers) {
					stow("\n\n# Triggers of  ".backquote($table)."\n\n");
					foreach ($table_triggers as $trigger) {
						$trigger_name = $trigger['Trigger'];
						$trigger_time = $trigger['Timing'];
						$trigger_event = $trigger['Event'];
						$trigger_statement = $trigger['Statement'];
						// Since trigger name can include backquotes and trigger name is typically enclosed with backquotes as well, the backquote escaping for the trigger name can be done by adding a leading backquote
						$quoted_escaped_trigger_name = backquote(str_replace('`', '``', $trigger_name));
						stow("DROP TRIGGER IF EXISTS $quoted_escaped_trigger_name;;\n");
						$trigger_query = "CREATE TRIGGER $quoted_escaped_trigger_name $trigger_time $trigger_event ON ".backquote($table)." FOR EACH ROW $trigger_statement;;";
						stow("$trigger_query\n\n");
					}
				}
			}
			stow("DELIMITER ;\n\n");
		}

		// DB Stored Routines
		$stored_routines = get_stored_routines();
		if (is_array($stored_routines) && !empty($stored_routines)) {
//			$updraftplus->log("Dumping routines for database {$this->dbinfo['name']}");
			stow("\n\n# Dumping routines for database ".backquote($dbinfo['name'])."\n\n");
			stow("DELIMITER ;;\n\n");
			foreach ($stored_routines as $routine) {
				$routine_name = $routine['Name'];
				// Since routine name can include backquotes and routine name is typically enclosed with backquotes as well, the backquote escaping for the routine name can be done by adding a leading backquote
				$quoted_escaped_routine_name = backquote(str_replace('`', '``', $routine_name));
				stow("DROP {$routine['Type']} IF EXISTS $quoted_escaped_routine_name;;\n\n");
				stow($routine['Create '.ucfirst(strtolower($routine['Type']))]."\n\n;;\n\n");
				//$updraftplus->log("Dumping routine: {$routine['Name']}");
			}
			stow("DELIMITER ;\n\n");
		} elseif (is_wp_error($stored_routines)) {
			echo $stored_routines->get_error_message();
		}

		stow("/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");

		//$updraftplus->log($file_base.'-db'.$this->whichdb_suffix.'.gz: finished writing out complete database file ('.round(filesize($backup_final_file_name)/1024, 1).' KB)');
		if ( $dbhandle_isgz )
			$ret = gzclose( $dbhandle );
		else
			$ret = fclose( $dbhandle );
		if (!$ret) {
			mylog('An error occurred whilst closing the final database file');
			mylog(__('An error occurred whilst closing the final database file', 'updraftplus'));
			$errors++;
		}

		foreach ($unlink_files as $unlink_file) {
			@unlink($unlink_file);// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}

		if ($errors > 0) return false;
		
		// We no longer encrypt here - because the operation can take long, we made it resumable and moved it to the upload loop
//		$updraftplus->jobdata_set('jobstatus', 'dbcreated'.$this->whichdb_suffix);
		
		mylog("Total database tables backed up: $total_tables (".basename($backup_final_file_name).", size: ".filesize($backup_final_file_name));
		@unlink($backup_file_base);
		return $backup_final_file_name;

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
	function memory_check_current($memory_limit = false) {
		// Returns in megabytes
		if (false == $memory_limit) $memory_limit = ini_get('memory_limit');
		$memory_limit = rtrim($memory_limit);
		$memory_unit = $memory_limit[strlen($memory_limit)-1];
		if (0 == (int) $memory_unit && '0' !== $memory_unit) {
			$memory_limit = substr($memory_limit, 0, strlen($memory_limit)-1);
		} else {
			$memory_unit = '';
		}
		switch ($memory_unit) {
			case '':
			$memory_limit = floor($memory_limit/1048576);
				break;
			case 'K':
			case 'k':
			$memory_limit = floor($memory_limit/1024);
				break;
			case 'G':
			$memory_limit = $memory_limit*1024;
				break;
			case 'M':
			// assumed size, no change needed
				break;
		}
		return $memory_limit;
	}

	function verify_free_memory($how_many_bytes_needed) {
		// This returns in MB
		$memory_limit = memory_check_current();
		if (!is_numeric($memory_limit)) return false;
		$memory_limit = $memory_limit * 1048576;
		$memory_usage = round(@memory_get_usage(false)/1048576, 1);// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$memory_usage2 = round(@memory_get_usage(true)/1048576, 1);// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		if ($memory_limit - $memory_usage > $how_many_bytes_needed && $memory_limit - $memory_usage2 > $how_many_bytes_needed) return true;
		return false;
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
//	$backup = do_mysql_dump();
	$backup = backup_db();
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
