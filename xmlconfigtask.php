<?php

namespace MailPet;


require_once("vendor/autoload.php");


use KzykHys\Parallel\SharedThread;

class XMLConfigTask extends SharedThread {

	private $id;

	private $is_successful = FALSE;

	private $default_curlopts;

	private $curlopts = array();

	private $unparsed_xml = NULL;
	private $parsed_xml = NULL;

	private $error_details = NULL;

	public function __construct( $url, $options = array() ) {

		$this->default_curlopts = array(
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_HEADER => FALSE,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_TIMEVALUE => time() - (60*60*24),
			CURLOPT_DEFAULT_PROTOCOL => 'https',
			CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:74.0) Gecko/20100101 Firefox/74.0'
		);

		$this->id = spl_object_hash($this);

		$this->unparsed_xml = NULL;

		$this->curlopts = $this->default_curlopts;
		$this->curlopts[CURLOPT_URL] = $url;
	}

	public function run() {

		$curl = curl_init();

		curl_setopt_array($curl, $this->curlopts);
		$data = curl_exec($curl);

		if($data != FALSE) {
			
			$this->unparsed_xml = $data;

			if(curl_getinfo($curl, CURLINFO_RESPONSE_CODE) == 200)
				$this->is_successful = TRUE;
		}
		else {
			$this->error_details = curl_error($curl);

			log_syslog(LOG_CRIT, "Error running configuration task for url (" . $this->get_url() . "): " . $this->error_details);
		}

		return $this;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_url() {
		return $this->curlopts[CURLOPT_URL];
	}

	public function is_successful() {
		return $this->is_successful;
	}

	public function get_simple_xml_element() {
		if($this->is_successful())
			return simplexml_load_string($this->unparsed_xml);
		else {
			throw new \Exception("Task result not ready or it's unsuccessful");
		}
	}

	public function get_response() {
		return $this->unparsed_xml;
	}

	public function get_error_details() {
		if(!$this->is_successful())
			return $this->error_details;
	}

	public function get_smtp_host_info() {
		if(!$this->is_successful())
			throw new \Exception("Task result not ready or it's unsuccessful");

		if(is_null($this->parsed_xml))
			$this->parsed_xml = $this->get_simple_xml_element();


	}
}

?>