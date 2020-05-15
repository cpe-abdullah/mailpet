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
	usage(); // To be added
	exit;
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
					usage(); // To be added
					exit;
				
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
			exit("Error: value expected for option " . $args_list[$j - 1] . ", instead an argument was provided\n");
		}
	}
	else {
		log_syslog(LOG_ERR, "Unknown argument \"" . $args_list[$j] . "\"");
		exit("Error: unknown argument \"" . $args_list[$j] . "\"\n");
	}
}

if($password !== '' && $username === '') {
	log_syslog(LOG_ERR, 'Password is provided without a username');
	exit("Error: Password is provided without a username, use -u option to specify a username\n");
}

if($user_port != NULL && $user_host === '') {
	log_syslog(LOG_ERR, 'Port is provided without SMTP host');
	exit("Error: Port is provided without SMTP host, use -h option to specify SMTP host\n");
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
	exit( "Required extensions aren't available\n"
		. "Please make sure the following extensions are properly installed and enabled: " . implode(', ', $extensions) . "\n");
}

// Parallel Library Requirements Pre-Check
$parallel = new Parallel();

if(!$parallel->isSupported()) {
	log_syslog(LOG_CRIT, "Required PHP components (pcntl) aren't available");
	exit( "Required PHP components aren't available\n"
		. "Please make sure the following components are available and enabled: pcntl\n");
}

// End of Requirements Pre-Check


$user_input = '';

// Consume input file if available
if($input_file !== '' && file_exists($input_file)) {

	$user_input = file_get_contents($input_file);

	if($user_input === FALSE) {
		log_syslog("Error reading contents of input file: " . $input_file);
		exit("Error reading contents of input file: " . $input_file . "\nMake sure it's accessible for reading\n");
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
if($user_input === '')
	exit("No valid input is available, quitting now\n");

// Done reading input


// Start parsing input to build better idea about message content

$to = '';
$from = '';

$host = '';

try {

	$mime_data = mailparse_msg_create();

	if(!mailparse_msg_parse($mime_data, $user_input)) {
		log_debuglog("Bad input, possible malformed or incomplete mime format", LOG_ERR);
		exit("Bad input, possible malformed or incomplete mime format\nCheck your inputs please\n");
	}

	$mime_greps = mailparse_msg_get_part_data($mime_data);

	// Check if the returned mime headers are complete
	foreach (array('to', 'from') as $header) {
		if(!array_key_exists($header, $mime_greps['headers'])) {
			log_debuglog("Bad input, possible malformed or incomplete mime header format", LOG_ERR);
			exit("Bad input, possible malformed or incomplete mime header format\nCheck your inputs please\n");
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
				exit("Bad input, possible malformed or incomplete mime (RFC822) format\nCheck your inputs please\n");
			}
		}

		$to = $headers->toaddress;

		$from = $headers->fromaddress;

		$host = $headers->from[0]->host;
		$host = strtolower($host) === "UNKNOWN" ? php_gethostname() : $host;
	}
	else {
		log_syslog(LOG_CRIT, "Required extentions mailparse/imap aren't available");
		exit( "Required feature(s) aren't available\n"
			. "Please make sure either of the following extensions is properly installed and enabled: mailparse, imap\n");
	}
}

// Done parsing input

?>