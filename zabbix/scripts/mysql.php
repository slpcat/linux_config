<?php

/**
Changelog:
	Version 0.4:  Added round() to Joins_without_indexes_per_day to remove exponents for large query sets.
	Version 0.3:  11:57 AM 10/13/2008
		- Correct where Percent_innodb_cache_hit_rate and/or Percent_innodb_cache_write_waits_required could have been 0
	Version 0.2:  10:43 AM 10/13/2008
		- Corrected error where replication not enabled stopped the monitoring completely (If you had your warning on strict, the server could see things like:  Notice: Undefined variable: Slave_IO_Running)
		- Ditto for Last_Errno, Last_Error
ereg change 2014-11-17
 */

error_reporting(E_ALL|E_STRICT);
define('DEBUG',false);
define('SLOWINNODB',0);   // If you set this to 1, then the script is very careful about status variables to avoid http://bugs.mysql.com/bug.php?id=36600

define('SYSTEM','mysql'.(DEBUG ? "-debug" : ""));
define('LOG',"/tmp/zabbix_".SYSTEM.".log");
define('DAT',"/tmp/zabbix_".SYSTEM.".dat");
define('UTIME',"/tmp/.zabbix_".SYSTEM.".utime");
define('DTIME',"/tmp/.zabbix_".SYSTEM.".dtime");
zabbix_config();

date_default_timezone_set('America/New_York');

$type = $argv[1];

$user = $argv[2];
$pass = $argv[3];

$cred = "-u$user -p$pass";

$tosendDaily = array();

// Get server information for zabbix_sender
$config = file_get_contents("/etc/zabbix/zabbix_agentd.conf");
preg_match("/Hostname\s*=\s*(.*)/i",$config,$parts);
$host = $parts[1];
preg_match("/Server\s*=\s*(.*)/i",$config,$parts);
$server = $parts[1];

$physical_memory = (int)`free -b | grep Mem | awk '{print \$2}'`;
$swap_memory = (int)`free -b | grep Swap | awk '{print \$2}'`;
if ( DEBUG ) echo sprintf("Physical Memory: %s, Swap: %s\n",byte_size($physical_memory),byte_size($swap_memory));

// Is this a 64bit machine?
$bit64 = preg_match("/64/",`uname -m`);

// Gather localhost aliases
$valids = array("localhost","127.0.0.1", "%");
$hosts = `grep 127.0.0.1 /etc/hosts`;
$lines = explode("\n",$hosts);
foreach ( $lines as $line )
{
	$parts = preg_split("/[ \t,]+/",$line);
	for ( $i=1; $i<count($parts); $i++ )
		if ( $parts[$i] > "" )
			$valids[] = $parts[$i];
}

// Connect to the MySQL server
$connection = mysql_connect("localhost",$user,$pass);
mysql_select_db("mysql");

// Get the version number
$parts = explode(" ",`mysql --version`);
$Version = substr($parts[5],0,strlen($parts[4])-1);

// Get server variables
$engines = array('have_myisam'=>'YES','have_memory'=>'YES');		// these are auto enabled.  no config necessary
$result = mysql_query("show global variables;");
if ( $result )
	while ($row = mysql_fetch_assoc($result)) {
		$var = $row["Variable_name"];
		$val = $row["Value"];
		$$var = $val;
		if ( substr($var,0,5) == "have_" && $val == "YES" ) {
			$engines[$var] = $val;
		}
	}

if ( SLOWINNODB ) {
	// Global status variables we use:
	$statusVars = array("Aborted_clients", "Aborted_connects", "Binlog_cache_disk_use", "Binlog_cache_use", "Bytes_received", "Bytes_sent", "Com_alter_db", "Com_alter_table", "Com_create_db", "Com_create_function", "Com_create_index", "Com_create_table", "Com_delete", "Com_drop_db", "Com_drop_function", "Com_drop_index", "Com_drop_table", "Com_drop_user", "Com_grant", "Com_insert", "Com_replace", "Com_revoke", "Com_revoke_all", "Com_select", "Com_update", "Connections", "Created_tmp_disk_tables", "Created_tmp_tables", "Handler_read_first", "Handler_read_key", "Handler_read_next", "Handler_read_prev", "Handler_read_rnd", "Handler_read_rnd_next", "Innodb_buffer_pool_read_requests", "Innodb_buffer_pool_reads", "Innodb_buffer_pool_wait_free", "Innodb_buffer_pool_write_requests", "Innodb_log_waits", "Innodb_log_writes", "Key_blocks_unused", "Key_read_requests", "Key_reads", "Key_write_requests", "Key_writes", "Max_used_connections", "Open_files", "Open_tables", "Opened_tables", "Qcache_free_blocks", "Qcache_free_memory", "Qcache_hits", "Qcache_inserts", "Qcache_lowmem_prunes", "Qcache_not_cached", "Qcache_queries_in_cache", "Qcache_total_blocks", "Questions", "Select_full_join", "Select_range", "Select_range_check", "Select_scan", "Slave_running", "Slow_launch_threads", "Slow_queries", "Sort_merge_passes", "Sort_range", "Sort_rows", "Sort_scan", "Table_locks_immediate", "Table_locks_waited", "Threads_cached", "Threads_connected", "Threads_created", "Threads_running", "Uptime");

	foreach ( $statusVars as $var ) {
		$result = mysql_query("show global status like '$var';");
		if ( $result )
			while ($row = mysql_fetch_assoc($result)) {
				$var = $row["Variable_name"];
				$val = $row["Value"];
				$$var = $val;
			}
	}
} else {
	$result = mysql_query("show global status;");
	if ( $result )
		while ($row = mysql_fetch_assoc($result)) {
			$var = $row["Variable_name"];
			$$var = $row["Value"];
		}
}

// Replication master status
$result = mysql_query("show master status;");
if ( $result )
	while ($row = mysql_fetch_assoc($result)) {
		foreach ( $row as $key => $var ) {
			$key = "Master_Status_$key";
			$$key = $var;
		}
	}

$result = mysql_query("show slave status;");
if ( $result )
	while ($row = mysql_fetch_assoc($result)) {
		foreach ( $row as $key => $var )
			$$key = $var;
	}

// Didn't error above, so Available
$Available = 1;

if ( $type == "daily" ) {
	if ( file_exists(DTIME) ) {
		$Diff = (time() - filectime(DTIME))/60/60/24;
		if ( $Diff < 1 ) {
			if ( DEBUG ) echo "Skipping daily gathering\n";
			echo 1;
			exit(0);
		}
		unlink(DTIME);
	}
	file_put_contents(DTIME,"Ran at ".date("Y-m-d H:i")."\n");

	// These are dangerous privileges
	$privs = array(
			"Insert_priv"=>"Insert_priv_count",
			"Update_priv"=>"Update_priv_count",
			"Delete_priv"=>"Delete_priv_count",
			"Drop_priv"=>"Drop_priv_count",
			"Shutdown_priv"=>"Shut_down_priv_count",
			"Process_priv"=>"Process_priv_count",
			"File_priv"=>"File_priv_count",
			"Grant_priv"=>"Grant_priv_count",
			"Alter_priv"=>"Alter_priv_count",
			"Super_priv"=>"Super_priv_count",
			"Lock_tables_priv"=>"Lock_tables_priv_count",
	);
	foreach ( $privs as $key => $var )
		$$var = 0;

	// Now, load users and let's see what's there
	$result = mysql_query("select * from user");
	$Root_accounts_count = $Accounts_without_password = $Accounts_with_broad_host_specifier = $Anonymous_accounts_count = 0;
	while ($row = mysql_fetch_assoc($result)) {
		if ( $row['Host'] == "" || $row['Host']=='%' )
			$Accounts_with_broad_host_specifier++;
		if ( $row["User"] == "root" )
		{
			$Root_accounts_count++;
			$invalid = false;
			if ( $row["Host"] == "" || $row["Host"]=="%" || !in_array($row["Host"],$valids) )
				$Is_root_remote_login_enabled = 1;
			if ( $row["Password"] == "" )
				$Is_root_without_password = 1;
		}
		if ( $row['Password'] == "" )
			$Accounts_without_password++;
		if ( $row['User'] == "" )
			$Anonymous_accounts_count++;
		foreach ( $privs as $key => $var )
			if ( $row[$key] == "Y" )
				$$var++;
	}

	// How many fragmented tables to we have?
	$result = mysql_query("SELECT COUNT(TABLE_NAME) as Frag FROM information_schema.TABLES WHERE TABLE_SCHEMA NOT IN ('information_schema','mysql') AND Data_free > 10000");
	$Fragmented_table_count = 0;
	while ($row = mysql_fetch_assoc($result)) {
		$Fragmented_table_count = $row["Frag"];
	}
	
	// Get the engines in use
	$result = mysql_query("SELECT DISTINCT ENGINE FROM information_schema.TABLES");
	if ( $result )
		while ($row = mysql_fetch_assoc($result)) {
			foreach ( $row as $key => $var ) {
				$key = "have_".strtolower($var);
				if ( array_key_exists( $key,$engines ) )
					$engines[$key] = "USED";
			}
		}
	$innodb_only_install = ( $engines['have_myisam'] == "YES" && $engines['have_innodb'] == "USED" ) ? 1 : 0;
	$myisam_only_install = ( $engines['have_myisam'] == "USED" && $engines['have_innodb'] == "YES" ) ? 1 : 0;

	$Suggested_table_cache = max(256,$max_connections*2);

	$Maximum_memory_possible = ($innodb_buffer_pool_size + $key_buffer_size + $max_connections * ($sort_buffer_size + $read_buffer_size + $binlog_cache_size) + $max_connections * mb(2));
	$Available_memory = $physical_memory * 9 / 10;
	$Available_memory -= $max_connections * kb(256);

	$Suggested_query_cache_size = ($Available_memory / 10 < mb(8) ? mb(8) : $Available_memory / 10);
	$Available_memory -= $Suggested_query_cache_size;

	$bm = $Available_memory * 7 / 10;
	$tbm = $Available_memory * 3 / 10;
	$mpt = $tbm * 10 / $max_connections;

	$bm -= $Suggested_table_cache * kb(8);
	$btb = $mpt*10;
	$Suggested_tmp_table_size = max(mb(16),$btb);

	$tc = max($max_connections/2, 16);
	$Suggested_thread_cache_size = min($tc,64);

	$msbs = max($btb,mb(8));
	$Suggested_myisam_sort_buffer_size = min($msbs,$physical_memory*2/10);

	$mb = $bm * ($innodb_only_install ? 0.05 : ($myisam_only_install ? 1 : 0.5));
	$Suggested_key_buffer_size = max($mb/2, mb(8));

	$Suggested_read_buffer_size = min($physical_memory/100,$mpt*20/100,kb(64));
	$Suggested_read_rnd_buffer_size = min($physical_memory*4/100,$mpt*40/100,kb(256));
	$Suggested_sort_buffer_size = min($physical_memory*2/100,$mpt*30/100,kb(256));

	if ( $myisam_only_install ) {
		$Suggested_innodb_additional_mem_pool_size = 
		$Suggested_innodb_log_buffer_size = 
		$Suggested_innodb_buffer_pool_size = 
		$Suggested_innodb_log_file_size = 0;
	} else {
		$ib = $bm * (1 - ($innodb_only_install ? 0.05 : ($myisam_only_install ? 1 : 0.5)));
		$Suggested_innodb_additional_mem_pool_size = max(mb(2),$ib*2/100);
		$ib -= $Suggested_innodb_additional_mem_pool_size;

		$Suggested_innodb_log_buffer_size = min(max(mb(1),$ib/100),mb(16));
		$ib -= $Suggested_innodb_log_buffer_size;

		$Suggested_innodb_buffer_pool_size = $ibps = max($ib, mb(8));
		$ib -= $Suggested_innodb_buffer_pool_size;

		$Suggested_innodb_log_file_size = min($ibps*2/10, gb(1));
	}

	$innodb_only_install = ( $engines['have_myisam'] == "YES" && $engines['have_innodb'] == "USED" ) ? 1 : 0;
	$myisam_only_install = ( $engines['have_myisam'] == "USED" && $engines['have_innodb'] == "YES" ) ? 1 : 0;
	if ( $innodb_only_install ) {
		if ( DEBUG ) echo "InnoDB only -- suggesting heavy settings for InnoDB\n";
		$innodb_suggested_buffer_size = round(0.75 * $physical_memory);
		$myisam_suggested_key_buffer_size = 0;
	} elseif ( $myisam_only_install ) {
		if ( DEBUG ) echo "MyISAM only -- suggesting heavy settings for MyISAM\n";
		$innodb_suggested_buffer_size = 0;
		$myisam_suggested_key_buffer_size = round(0.40 * $physical_memory);
	} else { // ok, we're a mixed install
		// These are SWAG's.  Need better calculations....
		if ( DEBUG ) echo "Mixed install.  Balancing settings\n";
		$innodb_suggested_buffer_size = round(0.50 * $physical_memory);
		$myisam_suggested_key_buffer_size = round(0.25 * $physical_memory);
	}
	$innodb_buffer_size_low = !close($innodb_buffer_pool_size,$innodb_suggested_buffer_size);
	$myisam_key_buffer_size_low = !close($key_buffer_size,$myisam_suggested_key_buffer_size);

	$tosendDaily = array(
		'Architecture_handles_all_memory' => ($physical_memory <= 2147483648 || $bit64 ? 1 : 0),
		'Maximum_memory_total' => $Maximum_memory_possible,
		'Maximum_memory_over_physical' => $Maximum_memory_possible > $physical_memory ? 1 :0,
		'Maximum_memory_exceeds_32bit_capabilities' => !$bit64 ? $Maximum_memory_possible >  gb(2) : 0,

		// Suggested configuration settings
		'Suggested_table_cache' => byte_size($Suggested_table_cache),
		'Change_table_cache' => !close($Suggested_table_cache,$table_cache),
		'table_cache' => $table_cache,
		
		'Suggested_query_cache_size' => byte_size($Suggested_query_cache_size),
		'Change_query_cache_size' => !close($Suggested_query_cache_size,$query_cache_size),
		'query_cache_size' => $query_cache_size,
		
		'Suggested_tmp_table_size' => byte_size($Suggested_tmp_table_size),
		'Change_tmp_table_size' => !close($Suggested_tmp_table_size,$tmp_table_size),
		'tmp_table_size' => $tmp_table_size,
		
		'Suggested_myisam_sort_buffer_size' => byte_size($Suggested_myisam_sort_buffer_size),
		'Change_myisam_sort_buffer_size' => !close($Suggested_myisam_sort_buffer_size,$myisam_sort_buffer_size),
		'myisam_sort_buffer_size' => $myisam_sort_buffer_size,
		
		'Suggested_key_buffer_size' => byte_size($Suggested_key_buffer_size),
		'Change_key_buffer_size' => !close($Suggested_key_buffer_size,$key_buffer_size),
		'key_buffer_size' => $key_buffer_size,
		
		'Suggested_read_buffer_size' => byte_size($Suggested_read_buffer_size),
		'Change_read_buffer_size' => !close($Suggested_read_buffer_size,$read_buffer_size),
		'read_buffer_size' => $read_buffer_size,
		
		'Suggested_read_rnd_buffer_size' => byte_size($Suggested_read_rnd_buffer_size),
		'Change_read_rnd_buffer_size' => !close($Suggested_read_rnd_buffer_size,$read_rnd_buffer_size),
		'read_rnd_buffer_size' => $read_rnd_buffer_size,
		
		'Suggested_sort_buffer_size' => byte_size($Suggested_sort_buffer_size),
		'Change_sort_buffer_size' => !close($Suggested_sort_buffer_size,$sort_buffer_size),
		'sort_buffer_size' => $sort_buffer_size,
		
		'Suggested_innodb_additional_mem_pool_size' => byte_size($Suggested_innodb_additional_mem_pool_size),
		'Change_innodb_additional_mem_pool_size' => !close($Suggested_innodb_additional_mem_pool_size,$innodb_additional_mem_pool_size),
		'innodb_additional_mem_pool_size' => $innodb_additional_mem_pool_size,
		
		'Suggested_innodb_log_buffer_size' => byte_size($Suggested_innodb_log_buffer_size),
		'Change_innodb_log_buffer_size' => !close($Suggested_innodb_log_buffer_size,$innodb_log_buffer_size),
		'innodb_log_buffer_size' => $innodb_log_buffer_size,
		
		'Suggested_innodb_buffer_pool_size' => byte_size($Suggested_innodb_buffer_pool_size),
		'Change_innodb_buffer_pool_size' => !close($Suggested_innodb_buffer_pool_size,$innodb_buffer_pool_size),
		'innodb_buffer_pool_size' => $innodb_buffer_pool_size,
		
		'Suggested_innodb_log_file_size' => byte_size($Suggested_innodb_log_file_size),
		'Change_innodb_log_file_size' => !close($Suggested_innodb_log_file_size,$innodb_log_file_size),
		'innodb_log_file_size' => $innodb_log_file_size,

		// Remove these!
		#'myisam_suggested_key_buffer_size' => $myisam_suggested_key_buffer_size,
		#'innodb_suggested_buffer_size' => $innodb_suggested_buffer_size,
		#'innodb_buffer_size_low' => $innodb_buffer_size_low,
		#'myisam_key_buffer_size_low' => $myisam_key_buffer_size_low,

		// Setup/Security parameters
		'Accounts_with_broad_host_specifier' => $Accounts_with_broad_host_specifier,
		'Accounts_without_password' => $Accounts_without_password,
		'Anonymous_accounts_count' => $Anonymous_accounts_count,
		'Alter_priv_count' => $Alter_priv_count,
		'Delete_priv_count' => $Delete_priv_count,
		'Drop_priv_count' => $Drop_priv_count,
		'File_priv_count' => $File_priv_count,
		'Grant_priv_count' => $Grant_priv_count,
		'Insert_priv_count' => $Insert_priv_count,
		'Lock_tables_priv_count' => $Lock_tables_priv_count,
		'Process_priv_count' => $Process_priv_count,
		'Shut_down_priv_count' => $Shut_down_priv_count,
		'Super_priv_count' => $Super_priv_count,
		'Update_priv_count' => $Update_priv_count,
		'Is_root_remote_login_enabled' => $Is_root_remote_login_enabled,
		'Is_root_without_password' => $Is_root_without_password,
		'Root_accounts_count' => $Root_accounts_count,
		'have_symlink' => $have_symlink,
		'old_passwords' => $old_passwords,
		'secure_auth' => $secure_auth,
		'skip_show_database' => $skip_show_database,
		'myisam_recover_options' => $myisam_recover_options == "OFF" ? 0 : 1,
		'wait_timeout' => $wait_timeout,
		'slow_launch_time' => $slow_launch_time,
		'local_infile' => $local_infile,
		'log_bin' => $log_bin,
		'log_queries_not_using_indexes' => $log_queries_not_using_indexes,
		'log_slow_queries' => $log_slow_queries,
		'long_query_time' => $long_query_time,
		'Version' => $Version,
		'binlog_cache_size' => $binlog_cache_size,
		'sync_binlog' => $sync_binlog,
		'have_query_cache' => $have_query_cache,
		'query_cache_limit' => $query_cache_limit,
		'query_cache_min_res_unit' => $query_cache_min_res_unit,
		'query_cache_type' => $query_cache_type,
		'query_prealloc_size' => $query_prealloc_size,
		'join_buffer_size' => $join_buffer_size,
		'key_cache_block_size' => $key_cache_block_size,
		'max_heap_table_size' => $max_heap_table_size,
		'sql_mode' => $sql_mode,
		'max_connections' => $max_connections,
		'thread_cache_size' => $thread_cache_size,
		'innodb_flush_log_at_trx_commit' => $innodb_flush_log_at_trx_commit,
		'innodb_log_files_in_group' => $innodb_log_files_in_group,
		'expire_logs_days' => $expire_logs_days,

		// Types of queries and usage
		'Com_alter_db' => $Com_alter_db,
		'Com_alter_table' => $Com_alter_table,
		'Com_create_db' => $Com_create_db,
		'Com_create_function' => $Com_create_function,
		'Com_create_index' => $Com_create_index,
		'Com_create_table' => $Com_create_table,
		'Com_drop_db' => $Com_drop_db,
		'Com_drop_function' => $Com_drop_function,
		'Com_drop_index' => $Com_drop_index,
		'Com_drop_table' => $Com_drop_table,
		'Com_drop_user' => $Com_drop_user,
		'Com_grant' => $Com_grant,
		'Excessive_revokes' => $Com_revoke + $Com_revoke_all,
		'Percent_writes_vs_total' => percent( ($Com_insert + $Com_replace + $Com_update + $Com_delete) / $Questions ),
		'Percent_inserts_vs_total' => percent( ($Com_insert + $Com_replace) / $Questions ),
		'Percent_selects_vs_total' => percent( ($Com_select + $Qcache_hits) / $Questions ),
		'Percent_deletes_vs_total' => percent( $Com_delete / $Questions ),
		'Percent_updates_vs_total' => percent( $Com_update / $Questions ),
		'Recent_schema_changes' => $Com_create_db > 0 || $Com_alter_db > 0 || $Com_drop_db > 0 || $Com_create_function > 0 || $Com_drop_function > 0 || $Com_create_index > 0 || $Com_drop_index > 0 || $Com_alter_table > 0 || $Com_create_table > 0 || $Com_drop_table > 0 || $Com_drop_user > 0,
		
		'Fragmented_table_count' => $Fragmented_table_count,

	);

}
elseif ( (int)$Uptime < 3600 ) {		// wait 1h before sending data after a restart
	echo 1;
	exit(0);
}

// Make a pretty uptime string
$seconds = $Uptime % 60;
$minutes = floor(($Uptime % 3600) / 60);
$hours = floor(($Uptime % 86400) / (3600));
$days = floor($Uptime / (86400));
if ($days > 0) {
	$Uptimestring = "${days}d ${hours}h ${minutes}m ${seconds}s";
} elseif ($hours > 0) {
	$Uptimestring = "${hours}h ${minutes}m ${seconds}s";
} elseif ($minutes > 0) {
	$Uptimestring = "${minutes}m ${seconds}s";
} else {
	$Uptimestring = "${seconds}s";
}

if ( $days == 0 ) $days = 100000000;		// force percentage to be low on calculation

if ( !isset($Innodb_buffer_pool_read_requests) || $Innodb_buffer_pool_read_requests == 0 )
	$Percent_innodb_cache_hit_rate = 0;
else
	$Percent_innodb_cache_hit_rate = pct2hi( 1 - ($Innodb_buffer_pool_reads / $Innodb_buffer_pool_read_requests) );
if ( !isset($Innodb_buffer_pool_write_requests) || $Innodb_buffer_pool_write_requests == 0 )
	$Percent_innodb_cache_write_waits_required = 0;
else
	$Percent_innodb_cache_write_waits_required = percent( $Innodb_buffer_pool_wait_free / $Innodb_buffer_pool_write_requests );


$tosendLive = array(
	'Available' => $Available,
	'Uptime' => $Uptimestring,
	'Last_Errno' => isset($Last_Errno) ? $Last_Errno : 0,
	'Last_Error' => isset($Last_Error) ? $Last_Error : "",

	// Binlog parameters
	'Binlog_cache_disk_use' => $Binlog_cache_disk_use,
	'Binlog_cache_use' => $Binlog_cache_use,

	// Performance/Usage/Queries
	'Questions' => $Questions,
	'Queries_per_sec' => elapsed((float)$Questions),
	'Bytes_received' => $Bytes_received,
	'Bytes_sent' => $Bytes_sent,
	'Select_full_join' => $Select_full_join,
	'Select_scan' => $Select_scan,
	'Slow_queries' => $Slow_queries,
	'Qcache_free_blocks' => $Qcache_free_blocks,
	'Qcache_free_memory' => $Qcache_free_memory,
	'Qcache_hits' => $Qcache_hits,
	'Qcache_inserts' => $Qcache_inserts,
	'Qcache_lowmem_prunes' => $Qcache_lowmem_prunes,
	'Qcache_lowmem_prunes_per_day' => $Qcache_lowmem_prunes/$days,
	'Qcache_not_cached' => $Qcache_not_cached,
	'Qcache_queries_in_cache' => $Qcache_queries_in_cache,
	'Qcache_total_blocks' => $Qcache_total_blocks, 
	'Average_rows_per_query' => ($Handler_read_first + $Handler_read_key + $Handler_read_next + $Handler_read_prev + $Handler_read_rnd + $Handler_read_rnd_next + $Sort_rows)/$Questions,
	'Total_rows_returned' => $Handler_read_first + $Handler_read_key + $Handler_read_next + $Handler_read_prev + $Handler_read_rnd + $Handler_read_rnd_next + $Sort_rows,
	'Indexed_rows_returned' => $Handler_read_first + $Handler_read_key + $Handler_read_next + $Handler_read_prev,
	'Sort_merge_passes' => $Sort_merge_passes,
	'Sort_range' => $Sort_range,
	'Sort_scan' => $Sort_scan,
	'Total_sort' => $Sort_range+$Sort_scan,
	'Joins_without_indexes' => $Select_range_check + $Select_full_join,
	'Joins_without_indexes_per_day' => round(($Select_range_check + $Select_full_join)/$days,0),
	'Percent_full_table_scans' => percent( ($Handler_read_rnd_next + $Handler_read_rnd) / ($Handler_read_rnd_next + $Handler_read_rnd + $Handler_read_first + $Handler_read_next + $Handler_read_key + $Handler_read_prev) ),
	'Percent_query_cache_fragmentation' => percent( $Qcache_free_blocks / $Qcache_total_blocks ),
	'Percent_query_cache_hit_rate' => percent( $Qcache_hits / ($Qcache_inserts + $Qcache_hits) ),
	'Percent_query_cache_pruned_from_inserts' => percent( $Qcache_lowmem_prunes / $Qcache_inserts ),
	'Percent_myisam_key_cache_in_use' => percent( (1 - ($Key_blocks_unused / ($key_buffer_size / $key_cache_block_size))) ),
	'Percent_myisam_key_cache_hit_rate' => pct2hi( (1 - ($Key_reads / $Key_read_requests)) ),
	'Percent_myisam_key_cache_write_ratio' => percent( $Key_writes / $Key_write_requests ),
	'Number_myisam_key_blocks' => $key_buffer_size / $key_cache_block_size,
	'Used_myisam_key_cache_blocks' => ($key_buffer_size / $key_cache_block_size) - $Key_blocks_unused,
	'Key_read_requests' => $Key_read_requests,
	'Key_reads' => $Key_reads,
	'Key_write_requests' => $Key_write_requests,
	'Key_writes' => $Key_writes,
	
	// Tables and Temp Tables stats
	'Open_tables' => $Open_tables,
	'Opened_tables' => $Opened_tables,
	'Table_locks_immediate' => $Table_locks_immediate,
	'Table_locks_waited' => $Table_locks_waited,
	'Created_tmp_disk_tables' => $Created_tmp_disk_tables,
	'Created_tmp_tables' => $Created_tmp_tables,
	'Percent_table_cache_hit_rate' => $Opened_tables > 0 ? pct2hi($Open_tables/$Opened_tables) : 100,
	'Percent_table_lock_contention' => ($Table_locks_waited + $Table_locks_immediate) > 0 ? percent( $Table_locks_waited / ($Table_locks_waited + $Table_locks_immediate)) : 0,
	'Percent_tmp_tables_on_disk' => ($Created_tmp_disk_tables + $Created_tmp_tables) > 0 ? percent( $Created_tmp_disk_tables / ($Created_tmp_disk_tables +  $Created_tmp_tables)) : 0,
	'Percent_transactions_saved_tmp_file' => ($Binlog_cache_use == 0 ? 0 : percent( $Binlog_cache_disk_use / $Binlog_cache_use) ),
	'Percent_tmp_sort_tables' => ($Sort_range+$Sort_scan > 0 ? percent( $Sort_merge_passes/($Sort_range+$Sort_scan)) : 0 ),
	'Percent_files_open' => $open_files_limit > 0 ? percent( $Open_files/$open_files_limit) : 0,

	// Clients, Threads, and Connections
	'Aborted_clients' => $Aborted_clients,
	'Aborted_connects' => $Aborted_connects,
	'Connections' => $Connections,
	'Successful_connects' => $Connections - $Aborted_connects,
	'Max_used_connections' => $Max_used_connections,
	'Slow_launch_threads' => $Slow_launch_threads,
	'Threads_cached' => $Threads_cached,
	'Threads_connected' => $Threads_connected,
	'Threads_created' => $Threads_created,
	'Threads_created_rate' => $Threads_created,
	'Threads_running' => $Threads_running,
	'Percent_thread_cache_hit_rate' => pct2hi( (1-$Threads_created/$Connections) ),
	'Percent_connections_used' => percent( $Threads_connected / $max_connections ),
	'Percent_aborted_connections' => percent( $Aborted_connects / $Connections ),
	'Percent_maximum_connections_ever' => percent( $Max_used_connections / $max_connections ),
	
	// Innodb stats	
	'Percent_innodb_log_size_vs_buffer_pool' => percent( ($innodb_log_files_in_group * $innodb_log_file_size) / $innodb_buffer_pool_size ),
	'Percent_innodb_log_write_waits_required' => percent( $Innodb_log_waits / $Innodb_log_writes ),
	'Percent_innodb_cache_hit_rate' => $Percent_innodb_cache_hit_rate,
	'Percent_innodb_cache_write_waits_required' => $Percent_innodb_cache_write_waits_required,
	'Innodb_log_file_size_total' => $innodb_log_files_in_group * $innodb_log_file_size,
);

// Sometimes, replication isn't reported if not enabled.  Test first before adding
if ( isset($Master_Status_File) )
{
	$tosendLive = array_merge($tosendLive,array(
		// Replication information	
		'Master_Status_Position' => $Master_Status_Position,
		'Master_Status_File' => $Master_Status_File,
		'Master_Status_Binlog_Do_DB' => $Master_Status_Binlog_Do_DB,
		'Master_Status_Binlog_Ignore_DB' => $Master_Status_Binlog_Ignore_DB,
	));
}

if ( isset($Relay_Log_File) )
{
	$tosendLive = array_merge($tosendLive,array(
		'Master_Host' => $Master_Host,
		'Master_Log_File' => $Master_Log_File,
		'Master_Port' => $Master_Port,
		'Master_User' => $Master_User,
		'Read_Master_Log_Pos' => $Read_Master_Log_Pos,
		'Relay_Log_File' => $Relay_Log_File,
		'Relay_Log_Pos' => $Relay_Log_Pos,
		'Relay_Log_Space' => $Relay_Log_Space,
		'Relay_Master_Log_File' => $Relay_Master_Log_File,
		'Exec_Master_Log_Pos' => $Exec_Master_Log_Pos,
		'Slave_IO_Running' => $Slave_IO_Running,
		'Slave_IO_State' => $Slave_IO_State,
		'Slave_SQL_Running' => $Slave_SQL_Running,
		'Slave_running' => $Slave_IO_Running="Yes" && $Slave_SQL_Running="Yes" ? 1 : 0,
		'Seconds_Behind_Master' => $Seconds_Behind_Master,
	));
}

$tosend = $type == "daily" ? $tosendDaily : $tosendLive;
foreach ( $tosend as $key => $var )
	zabbix_post($key,$var);

//system("zabbix_sender -z $server -i ".DAT." >> ".LOG);
echo 1;
exit(0);

function close($a,$b) { 
	if ( $a == 0 && $b > 1 ) return 0;
	if ( $b == 0 && $a > 1 ) return 0;
	$delta = abs($b-$a)*100/$a;
	return $delta < 90;
}

function kb($a) { return $a*1024; }
function mb($a) { return $a*1024*1024; }
function gb($a) { return $a*1024*1024*1024; }


function byte_size($size)
{
	$filesizename = array("", "K", "M", "G", "T", "P", "E", "Z", "Y");
	return $size ? round($size/pow(1024, ($i = floor(log($size, 1024)))), 0) . $filesizename[$i] : '0';
}

function pct2hi($a) {
	global $Uptime;

	return $Uptime < 86400 ? 100 : percent($a);
}

function percent($a) {
	return round(100*$a);
}

function zabbix_config() {
	global $server,$host;
	
	if ( file_exists(DAT) ) unlink(DAT);
	if ( file_exists(LOG) ) unlink(LOG);

	// Get server information for zabbix_sender
	$config = file_get_contents("/etc/zabbix/zabbix_agentd.conf");
	preg_match("/Hostname\s*=\s*(.*)/i",$config,$parts);
	$host = $parts[1];
	preg_match("/Server\s*=\s*(.*)/i",$config,$parts);
	$server = $parts[1];
}

function zabbix_post($var,$val) {
	global $server,$host;

	switch ( strtolower($val) ) {
		case "yes":
		case "on":
			$val = 1;
			break;
		case "no":
		case "":
		case "off":
			$val = 0;
			break;
	}
	if ( !is_numeric($val) )
		$val = '"'.$val.'"';
	file_put_contents(DAT,"$server $host 10051 ".SYSTEM.".$var $val\n",FILE_APPEND);
	$cmd = "zabbix_sender -z $server -p 10051 -s $host -k ".SYSTEM.".$var -o $val";
	if ( DEBUG ) 
		echo "$cmd\n";
	else
		system("$cmd 2>&1 >> ".LOG);
}

function elapsed($val) { 
	$now = microtime(true);
	if ( !file_exists(UTIME) ) // first time
		file_put_contents(UTIME,serialize(array( "value" => $val, "start" => $now )));
	$data = unserialize(file_get_contents(UTIME));
	file_put_contents(UTIME,serialize(array( "value" => $val, "start" => $now )));
	$seconds = $now-$data["start"];
	$elapsed = (float)($val - $data["value"])/( !$seconds || $seconds==0 ? 1 : $seconds);
	return $elapsed < 0 ? 0 : $elapsed;
}		
	
/** All STATUS variables:
Aborted_clients
Aborted_connects
Binlog_cache_disk_use
Binlog_cache_use
Bytes_received
Bytes_sent
Com_admin_commands
Com_alter_db
Com_alter_table
Com_analyze
Com_backup_table
Com_begin
Com_change_db
Com_change_master
Com_check
Com_checksum
Com_commit
Com_create_db
Com_create_function
Com_create_index
Com_create_table
Com_create_user
Com_dealloc_sql
Com_delete
Com_delete_multi
Com_do
Com_drop_db
Com_drop_function
Com_drop_index
Com_drop_table
Com_drop_user
Com_execute_sql
Com_flush
Com_grant
Com_ha_close
Com_ha_open
Com_help
Com_insert
Com_insert_select
Com_kill
Com_load
Com_load_master_data
Com_load_master_table
Com_lock_tables
Com_optimize
Com_preload_keys
Com_prepare_sql
Com_purge
Com_purge_before_date
Com_rename_table
Com_repair
Com_replace
Com_replace_select
Com_reset
Com_restore_table
Com_revoke
Com_revoke_all
Com_rollback
Com_savepoint
Com_select
Com_set_option
Com_show_binlog_events
Com_show_binlogs
Com_show_charsets
Com_show_collations
Com_show_column_types
Com_show_create_db
Com_show_create_table
Com_show_databases
Com_show_errors
Com_show_fields
Com_show_grants
Com_show_innodb_status
Com_show_keys
Com_show_logs
Com_show_master_status
Com_show_ndb_status
Com_show_new_master
Com_show_open_tables
Com_show_privileges
Com_show_processlist
Com_show_slave_hosts
Com_show_slave_status
Com_show_status
Com_show_storage_engines
Com_show_tables
Com_show_triggers
Com_show_variables
Com_show_warnings
Com_slave_start
Com_slave_stop
Com_stmt_close
Com_stmt_execute
Com_stmt_fetch
Com_stmt_prepare
Com_stmt_reset
Com_stmt_send_long_data
Com_truncate
Com_unlock_tables
Com_update
Com_update_multi
Com_xa_commit
Com_xa_end
Com_xa_prepare
Com_xa_recover
Com_xa_rollback
Com_xa_start
Compression
Connections
Created_tmp_disk_tables
Created_tmp_files
Created_tmp_tables
Delayed_errors
Delayed_insert_threads
Delayed_writes
Flush_commands
Handler_commit
Handler_delete
Handler_discover
Handler_prepare
Handler_read_first
Handler_read_key
Handler_read_next
Handler_read_prev
Handler_read_rnd
Handler_read_rnd_next
Handler_rollback
Handler_savepoint
Handler_savepoint_rollback
Handler_update
Handler_write
Innodb_buffer_pool_pages_data
Innodb_buffer_pool_pages_dirty
Innodb_buffer_pool_pages_flushed
Innodb_buffer_pool_pages_free
Innodb_buffer_pool_pages_misc
Innodb_buffer_pool_pages_total
Innodb_buffer_pool_read_ahead_rnd
Innodb_buffer_pool_read_ahead_seq
Innodb_buffer_pool_read_requests
Innodb_buffer_pool_reads
Innodb_buffer_pool_wait_free
Innodb_buffer_pool_write_requests
Innodb_data_fsyncs
Innodb_data_pending_fsyncs
Innodb_data_pending_reads
Innodb_data_pending_writes
Innodb_data_read
Innodb_data_reads
Innodb_data_writes
Innodb_data_written
Innodb_dblwr_pages_written
Innodb_dblwr_writes
Innodb_log_waits
Innodb_log_write_requests
Innodb_log_writes
Innodb_os_log_fsyncs
Innodb_os_log_pending_fsyncs
Innodb_os_log_pending_writes
Innodb_os_log_written
Innodb_page_size
Innodb_pages_created
Innodb_pages_read
Innodb_pages_written
Innodb_row_lock_current_waits
Innodb_row_lock_time
Innodb_row_lock_time_avg
Innodb_row_lock_time_max
Innodb_row_lock_waits
Innodb_rows_deleted
Innodb_rows_inserted
Innodb_rows_read
Innodb_rows_updated
Key_blocks_not_flushed
Key_blocks_unused
Key_blocks_used
Key_read_requests
Key_reads
Key_write_requests
Key_writes
Last_query_cost
Max_used_connections
Ndb_cluster_node_id
Ndb_config_from_host
Ndb_config_from_port
Ndb_number_of_data_nodes
Not_flushed_delayed_rows
Open_files
Open_streams
Open_tables
Opened_tables
Prepared_stmt_count
Qcache_free_blocks
Qcache_free_memory
Qcache_hits
Qcache_inserts
Qcache_lowmem_prunes
Qcache_not_cached
Qcache_queries_in_cache
Qcache_total_blocks
Questions
Rpl_status
Select_full_join
Select_full_range_join
Select_range
Select_range_check
Select_scan
Slave_open_temp_tables
Slave_retried_transactions
Slave_running
Slow_launch_threads
Slow_queries
Sort_merge_passes
Sort_range
Sort_rows
Sort_scan
Ssl_accept_renegotiates
Ssl_accepts
Ssl_callback_cache_hits
Ssl_cipher
Ssl_cipher_list
Ssl_client_connects
Ssl_connect_renegotiates
Ssl_ctx_verify_depth
Ssl_ctx_verify_mode
Ssl_default_timeout
Ssl_finished_accepts
Ssl_finished_connects
Ssl_session_cache_hits
Ssl_session_cache_misses
Ssl_session_cache_mode
Ssl_session_cache_overflows
Ssl_session_cache_size
Ssl_session_cache_timeouts
Ssl_sessions_reused
Ssl_used_session_cache_entries
Ssl_verify_depth
Ssl_verify_mode
Ssl_version
Table_locks_immediate
Table_locks_waited
Tc_log_max_pages_used
Tc_log_page_size
Tc_log_page_waits
Threads_cached
Threads_connected
Threads_created
Threads_running
Uptime
*/

