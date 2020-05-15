<?php

//Constants

define('LOGGING_IDENTITY', 'MailPet');

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
?>
