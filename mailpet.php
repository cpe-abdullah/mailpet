<?php

require_once("require.php");

use KzykHys\Parallel\Parallel;

//SMTP Standard Attribute Values
$smtp_ports = array(
	25,
	465,
	587
);

$smtp_secure_protocols = array(
	'NONE',
	'STARTTLS',
	'SSL'
);

$common_mail_server_names = array(
	'smtp',
	'mail'
);

// Requirements
$requirements = array(
	//test_function => extension
	'curl_init' => 'curl',
	'simplexml_load_string' => 'xml'
);


// Start by processing arguments

$no_auth = FALSE;

$verbose_output = FALSE;

$user_host = ''; $user_port = NULL;

// USERNAME-PASSWORD AUTHENTICATION (The Only Authentication Method Supported For Now)
$username = '';$password = '';

$input_file = '';

$fresh_cache = FALSE;

$args_list = array_slice($argv, 1, count($argv) - 1);

if(count($args_list) <= 0) {
	usage();
	exit(0);
}

for($j = 0; $j < count($args_list); $j++) {

	if(is_arg($args_list[$j])) {

		// One-part arguments
		if(is_ctrl_arg($args_list[$j])) {
			switch ($args_list[$j]) {
				case '-v':	// Verbose output
					$verbose_output = TRUE;
					$logger_options = $logger_options | LOG_PERROR;
					break;
				case '-rc':
					$fresh_cache = TRUE;
					break;
				case '-help':
					usage();
					exit(0);
				
				default:
					break;
			}

			continue;
		}

		$j++;

		// Multi-part arguments
		if(!is_arg($args_list[$j])) {

			switch ($args_list[$j - 1]) {
				case '-u':
					$username = $args_list[$j];
					break;

				case '-p':
					$password = $args_list[$j];
					break;

				case '-h':
					$user_host = $args_list[$j];
					break;

				case '-r':
					$user_port = $args_list[$j];
					break;

				case '-f':
					$input_file = $args_list[$j];
					break;
			}
		}
		else {
			log_syslog(LOG_ERR, "Value expected for option " . $args_list[$j - 1]);

			echo "Error: value expected for option " . $args_list[$j - 1] . ", instead an argument was provided\n";
			exit(14);
		}
	}
	else {
		log_syslog(LOG_ERR, "Unknown argument \"" . $args_list[$j] . "\"");

		echo "Error: unknown argument \"" . $args_list[$j] . "\"\n";
		exit(13);
	}
}

if($password !== '' && $username === '') {
	log_syslog(LOG_ERR, 'Password is provided without a username');

	echo "Error: Password is provided without a username, use -u option to specify a username\n";
	exit(11);
}

if($user_port != NULL && $user_host === '') {
	log_syslog(LOG_ERR, 'Port is provided without SMTP host');

	echo "Error: Port is provided without SMTP host, use -h option to specify SMTP host\n";
	exit(12);
}

if($password === '' && $username === '')
	$no_auth = TRUE;

// Done processing arguments


// Requirements Pre-Check

$extensions = array();

foreach ($requirements as $function => $extension) {
	if(!function_exists($function))
		array_push($extensions, $extension);
}

if(count($extensions)) {
	log_syslog(LOG_CRIT, "Required extensions (" . implode(', ', $extensions) . ") aren't available");

	echo "Required extensions aren't available\n"
		. "Please make sure the following extensions are properly installed and enabled: " . implode(', ', $extensions) . "\n";
	exit(20);
}

// Parallel Library Requirements Pre-Check
$parallel = new Parallel();

if(!$parallel->isSupported()) {
	log_syslog(LOG_CRIT, "Required PHP components (pcntl) aren't available");

	echo "Required PHP components aren't available\n"
		. "Please make sure the following components are available and enabled: pcntl\n";
	exit(21);
}

// End of Requirements Pre-Check


$user_input = '';

// Consume input file if available
if($input_file !== '' && file_exists($input_file)) {

	$user_input = file_get_contents($input_file);

	if($user_input === FALSE) {
		log_syslog("Error reading contents of input file: " . $input_file);

		echo "Error reading contents of input file: " . $input_file . "\nMake sure it's accessible for reading\n";
		exit(31);
	}
}
// Or pend for user input through standard input stream
else {

	while($buffer = fread(STDIN, 2048)) {
		$user_input .= $buffer;
	}
}

//Sometimes inputs may go chaotic, further processing is needed
$user_input = trim($user_input);

//Or may be there is no input available at the moment
if($user_input === '') {
	echo "No valid input is available, quitting now\n";
	exit(30);
}

// Done reading input


// Start parsing input to build better idea about message content

$to = '';
$from = '';

$host = '';

try {

	$mime_data = mailparse_msg_create();

	if(!mailparse_msg_parse($mime_data, $user_input)) {
		log_debuglog("Bad input, possible malformed or incomplete mime format", LOG_ERR);

		echo "Bad input, possible malformed or incomplete mime format\nCheck your inputs please\n";
		exit(40);
	}

	$mime_greps = mailparse_msg_get_part_data($mime_data);

	// Check if the returned mime headers are complete
	foreach (array('to', 'from') as $header) {
		if(!array_key_exists($header, $mime_greps['headers'])) {
			log_debuglog("Bad input, possible malformed or incomplete mime header format", LOG_ERR);

			echo "Bad input, possible malformed or incomplete mime header format\nCheck your inputs please\n";
			exit(42);
		}
	}

	$to = $mime_greps['headers']['to'];

	$from = $mime_greps['headers']['from'];

	$host = explode('@', $from);
	$host = count($host) <= 1 ? php_gethostname() : $host[1];
}
catch (Error $e) {
	// Looks like we have some problems with mailparse, let's try imap with rfc822

	if(function_exists('imap_rfc822_parse_headers')) {

		$headers = imap_rfc822_parse_headers($user_input);

		// Check if the returned headers object is qualified to be used, in the case of a collapsed mail format.
		$headers_properties = get_object_vars($headers);

		foreach (array('toaddress', 'fromaddress', 'from') as $property) {
			if(!array_key_exists($property, $headers_properties)) {
				log_debuglog("Bad input, possible malformed or incomplete mime (RFC822) format", LOG_ERR);

				echo "Bad input, possible malformed or incomplete mime (RFC822) format\nCheck your inputs please\n";
				exit(43);
			}
		}

		$to = $headers->toaddress;

		$from = $headers->fromaddress;

		$host = $headers->from[0]->host;
		$host = strtolower($host) === "UNKNOWN" ? php_gethostname() : $host;
	}
	else {
		log_syslog(LOG_CRIT, "Required extentions mailparse/imap aren't available");

		echo "Required feature(s) aren't available\n"
			. "Please make sure either of the following extensions is properly installed and enabled: mailparse, imap\n";
		exit(41);
	}
}

// Done parsing input

// OK, Now we can initiate our PHPMailer

$smtp = new PHPMailer\PHPMailer\SMTP();

if($verbose_output) {
	$smtp->setDebugOutput('log_debuglog');
	$smtp->setDebugLevel(PHPMailer\PHPMailer\SMTP::DEBUG_CONNECTION);
}

$smtp->setVerp(FALSE);

// PHPMailer initiation completed


// Determine SMTP host

/* 
	If no host was provided by the user, use the host in From field to determine SMTP host(s).
	In case host is provided without port, try forming port combinations.
	If user provided a host and port use them only, and try forming secure combinations.
*/

$smtp_host = NULL;

// Ways to determine SMTP host:
//	1- Local Cache Formed By Previous Net-based Attempts
// 	2- ISP
//	3- OnlineDB
//	4- MX & OnlineDB
//	5- MX
//	6- Guessing using common mail server names (smtp. mail.)

$cache_file_path = realpath($_SERVER['HOME']) . "/" . DEFAULT_CACHE_FILE_NAME;

$cache_content = NULL;

$cache_outofdate = FALSE;

if (!$fresh_cache && file_exists($cache_file_path)) {
	// Get cache data already stored
	$cache_data = file_get_contents($cache_file_path);

	if($cache_data === FALSE) {
		log_debuglog("Error reading contents of cache file: " . $cache_file_path . ", Possible reasons: it's not accessible for reading", LOG_WARNING);
	}
	else {
		$cache_content = json_decode($cache_data, TRUE);

		// Parse cache contents for host

		if($cache_content && $cache_content != NULL) {

			$smtp_hosts = $cache_content['smtp_hosts'];

			foreach ($smtp_hosts as $host_entry) {
				if($host_entry['domain'] === $host) {

					// Cache expiry date set to 14 days
					if(time() - $host_entry['last_checked'] > 60 * 60 * 24 * 14)
						$cache_outofdate = TRUE;
					else
						$smtp_host = array($host_entry);

					break;
				}
			}
		}
	}
}

if($smtp_host == NULL) {

	if($user_host === '' && $user_port == NULL) {

		/*
			Most of the credit for this section goes to the guys at comm-central project (Mozilla Thunderbird)
			The entire host determination logic was inspired by the way they handle finding a certain hostname's
			config combined with my cute little mods of course, keep hitting the spot boys...
		*/

		$smtp_hosts_data = array();


		// ISP-BASED SMTP HOST DETERMINATION LOGIC (speed depends on connection)
		$isp_pool = new XMLTaskPool();
		$isp_pool->add_task_byurl("https://autoconfig.{$host}/mail/config-v1.1.xml");
		$isp_pool->add_task_byurl("https://{$host}/.well-known/autoconfig/mail/config-v1.1.xml");
		$isp_pool->start();
		$smtp_hosts_data = array_merge($smtp_hosts_data, $isp_pool->get_smtp_hosts_info());
		$isp_pool->close();

		
		// MX/DB-BASED SMTP HOST DETERMINATION LOGIC (speed depends on connection)
		$mx_db_pool = new XMLTaskPool();


		// DB
		$mx_db_pool->add_task_byurl("https://live.thunderbird.net/autoconfig/v1.1/{$host}");
		$mx_db_pool->add_task_byurl("https://autoconfig.thunderbird.net/v1.1/{$host}");

		$mxhosts = array();

		dns_get_mx($host, $mxhosts);

		for ($i=0; $i < count($mxhosts); $i++) {

			// MX/DB
			$mx_db_pool->add_task_byurl("https://live.thunderbird.net/autoconfig/v1.1/{$mxhosts[$i]}");
			$mx_db_pool->add_task_byurl("https://autoconfig.thunderbird.net/v1.1/{$mxhosts[$i]}");
		}

		$mx_db_pool->start();
		$smtp_hosts_data = array_merge($smtp_hosts_data, $mx_db_pool->get_smtp_hosts_info());
		$mx_db_pool->close();

		if(count($smtp_hosts_data) == 0) {

			// MX-BASED SMTP HOST GUESS LOGIC (takes longer time)
			for ($i=0; $i < count($mxhosts); $i++) {
				foreach ($smtp_ports as $port) {
					foreach ($smtp_secure_protocols as $protocol) {
						$host_entry = array(
							"host" => $mxhosts[$i],
							"port" => $port,
							"socketType" => $protocol
						);

						// Determine host configuration connectivity
						if($smtp->connect(get_schemed_host($host_entry), $host_entry['port'], SMTP_CONNECTION_TIMEOUT)) {

							if(!in_array($host_entry, $smtp_hosts_data))
								array_push($smtp_hosts_data, $host_entry);

							$smtp->quit();
							$smtp->close();
						}
					}
				}
			}
		}


		// SMTP HOST GUESS LOGIC BASED ON COMMON-SERVER-NAMES
		foreach ($common_mail_server_names as $mail_server_name) {
			foreach ($smtp_ports as $port) {
				foreach ($smtp_secure_protocols as $protocol) {
					$host_entry = array(
						"host" => $mail_server_name . "." . $host,
						"port" => $port,
						"socketType" => $protocol
					);

					// First a check is performed for results from previous effort(s) to avoid wasting times in connectivity checks
					if(!in_array($host_entry, $smtp_hosts_data)) {

						// Determine host configuration connectivity
						if($smtp->connect(get_schemed_host($host_entry), $host_entry['port'], SMTP_CONNECTION_TIMEOUT)) {

							array_push($smtp_hosts_data, $host_entry);

							$smtp->quit();
							$smtp->close();
						}
					}
				}
			}
		}

		$smtp_host = $smtp_hosts_data;
	}
	else if($user_host !== '' && $user_port == NULL) {

		// Depend on user-provided host, with port and secure protocol discovery (takes a while)

		$smtp_hosts_data = array();

		foreach ($smtp_ports as $port) {
			foreach ($smtp_secure_protocols as $protocol) {
				$host_entry = array(
					"host" => $user_host,
					"port" => $port,
					"socketType" => $protocol
				);

				// Determine host configuration connectivity
				if($smtp->connect(get_schemed_host($host_entry), $host_entry['port'], SMTP_CONNECTION_TIMEOUT)) {

					if(!in_array($host_entry, $smtp_hosts_data))
						array_push($smtp_hosts_data, $host_entry);

					$smtp->quit();
					$smtp->close();
				}
			}
		}

		$smtp_host = $smtp_hosts_data;
	}
	else {

		// Depend on user-provided host/port, with secure protocol discovery only (takes short time)

		$smtp_hosts_data = array();

		foreach($smtp_secure_protocols as $protocol) {
			$host_entry = array(
				"host" => $user_host,
				"port" => $user_port,
				"socketType" => $protocol
			);

			// Determine host configuration connectivity
			if($smtp->connect(get_schemed_host($host_entry), $host_entry['port'], SMTP_CONNECTION_TIMEOUT)) {

				if(!in_array($host_entry, $smtp_hosts_data))
					array_push($smtp_hosts_data, $host_entry);

				$smtp->quit();
				$smtp->close();
			}
		}

		$smtp_host = $smtp_hosts_data;
	}
}

if (count($smtp_host) == 0) {
	/*
		This is the rare case when being out of any valid SMTP host configurations for a certain domain after
		applying any of the methods in the previous section, had the user provided valid inputs of course...
	*/
	log_debuglog("Unable to find any working SMTP host configuration(s) for domain: " . $host, LOG_WARNING);
}


// Initiate SMTP sequence

$msg_sent = FALSE;

$last_check_time = NULL;

for ($i=0; $i < count($smtp_host); $i++) {

	$check_time_start = time();

	$invocation_list = array();
	array_push($invocation_list, array('connect' => array(get_schemed_host($smtp_host[$i]), $smtp_host[$i]['port'])));
	array_push($invocation_list, array('hello' => array($smtp_host[$i]['host'])));

	if($smtp_host[$i]['socketType'] === 'STARTTLS') {
		array_push($invocation_list, array('startTLS' => array()));
		array_push($invocation_list, array('hello' => array($smtp_host[$i]['host'])));
	}

	if(!$no_auth)
		// Authentication type is left for PHPMailer to decide (usually LOGIN depending on server capabilities)
		array_push($invocation_list, array('authenticate' => array($username, $password, NULL)));

	array_push($invocation_list, array('mail' => array($from)));
	array_push($invocation_list, array('recipient' => array($to)));
	array_push($invocation_list, array('data' => array($user_input)));

	// Now we are all set, let's start SMTP sequence

	if(!methods_invoke_assert($smtp, $invocation_list)) {
		$smtp->close();
		continue;
	}
	else {
		$smtp->quit();
		$smtp->close();

		// In case of success, let's keep the current working configuration for later use to avoid time spent during configuration lookup

		$smtp_host[$i]["domain"] = $host;
		$smtp_host[$i]["last_checked"] = $check_time_start;

		$smtp_host_set = FALSE;

		if($cache_content && $cache_content != NULL) {

			// This is the general case scenario when cache contents are already available

			if(!array_key_exists('smtp_hosts', $cache_content))
				$cache_content["smtp_hosts"] = array();

			if($cache_outofdate || $fresh_cache) {
				for ($j = 0; $j < count($cache_content["smtp_hosts"]); $j++) {
					if($cache_content["smtp_hosts"][$j]["host"] === $smtp_host[$i]["host"]) {
						$cache_content["smtp_hosts"][$j] = $smtp_host[$i];
						$smtp_host_set = TRUE;

						break;
					}
				}

				if(!$smtp_host_set) {
					array_push($cache_content["smtp_hosts"], $smtp_host[$i]);
					$smtp_host_set = TRUE;
				}
			}
		}
		else {
			// This is the startup case scenario when cache contents are not available

			$cache_content = array();

			$cache_content["smtp_hosts"] = array($smtp_host[$i]);
			$smtp_host_set = TRUE;
		}

		if($smtp_host_set) {
			if(FALSE === file_put_contents($cache_file_path, json_encode($cache_content)))
				log_debuglog("Error writing data to cache file: " . $cache_file_path . ", Possible reasons: directory is not accessible for writing", LOG_WARNING);
		}

		$msg_sent = TRUE;
		break;
	}
}

// SMTP sequence completed

if($msg_sent) {
	echo "Message sent successfully\n";
	exit(0);
}
else {
	echo "Couldn't send message, check logs for more details\n";
	exit(1);
}

?>