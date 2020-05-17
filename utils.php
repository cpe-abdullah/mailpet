<?php

//Constants

define('APPLICATION_VERSION', '0.0');

define('DEFAULT_CACHE_FILE_NAME', '.mailpet');
define('LOGGING_IDENTITY', 'MailPet');
define('SMTP_CONNECTION_TIMEOUT', 10);


function get_schemed_host($smtp_host) {

	$schemed_host = $smtp_host['host'];

	if(strtolower($smtp_host['socketType']) === 'ssl')
		$schemed_host = 'ssl://' . $schemed_host;

	return $schemed_host;
}

function methods_invoke_assert($obj, $invocation_list) {

	foreach ($invocation_list as $method_invocation) {

		foreach ($method_invocation as $method_name => $params) {
			if(function_exists('call_user_func_array')) {
				if(!call_user_func_array(array($obj, $method_name), $params))
					return FALSE;
			}
			else {
				if(!call_user_method_array($method_name, $obj, $params))
					return FALSE;
			}
		}
	}

	return TRUE;
}

function is_ctrl_arg($value) {

	switch ($value) {
		case '-v':
		case '-rc':
		case '-help':
			break;
		
		default:
			return FALSE;
			break;
	}

	return TRUE;
}

function is_arg($value) {

	switch ($value) {
		case '-u':
		case '-p':
		case '-h':
		case '-r':
		case '-f':
			break;
		
		default:
			return is_ctrl_arg($value);
			break;
	}

	return TRUE;
}

function php_gethostname() {

	if(function_exists('gethostname'))
		return gethostname();
	else
		return php_uname('n');
}


$logger_options = LOG_PID | LOG_ODELAY;

$logger_initialized = FALSE;

function log_syslog($priority, $log_msg) {

	global $logger_options;

	openlog(LOGGING_IDENTITY, $logger_options, LOG_SYSLOG);
	syslog($priority, $log_msg);
	closelog();
}

function log_maillog($priority, $log_msg) {

	global $logger_options;

	openlog(LOGGING_IDENTITY, $logger_options, LOG_MAIL);
	syslog($priority, $log_msg);
	closelog();
}

function log_debuglog($msg, $level = LOG_DEBUG) {

	log_syslog($level, $msg);
	log_maillog($level, $msg);
}

function usage() {
	echo "\nMailPet v" . APPLICATION_VERSION . " — PHPMailer implementation for sending emails through SMTP in CLI mode\n\n";
	echo "Usage: php mailpet.php [options]\n\n";
	echo "Options\n";
	echo "-u <username> :\t\tusername to authenticate with to the SMTP server\n";
	echo "-p <password> :\t\tpassword to authenticate with to the SMTP server\n";
	echo "-h <host> :\t\tan already known SMTP host to connect to in order to send email\n";
	echo "-r <port> :\t\tan already known SMTP port to use when connecting to SMTP host\n";
	echo "-f <path-to-file> :\tlocation of a file to read email message data from instead of standard input\n";
	echo "-v :\t\t\tverbose output\n";
	echo "-rc :\t\t\trefresh mailpet cache (will delete cache data for the current domain)\n";
	echo "-help :\t\t\tshows this usage message and exit\n";
	echo "\n";
}
?>
