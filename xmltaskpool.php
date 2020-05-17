<?php

namespace MailPet;


require_once("xmlconfigtask.php");

require_once("vendor/autoload.php");


use MailPet\XMLConfigTask;

use KzykHys\Parallel\Server;

class XMLTaskPool {

	private $tasks = array();

	private $tasksServer;

	private $is_active = FALSE;

	public function __construct() {

		$this->tasksServer = Server::getInstance();
		$this->tasksServer->listen();
	}

	public function start() {

		$this->is_active = TRUE;

		$xmlTasks_count = count($this->tasks);

		for ($i=0; $i < $xmlTasks_count; $i++) {
			$this->tasks[$i]->start();
		}

		$j = $xmlTasks_count;

		while(TRUE) {
			if(($xmlTask = $this->tasksServer->accept()) != FALSE)
				$xmlTask = $xmlTask[1];
			else
				continue;

			for ($i=0; $i < $xmlTasks_count; $i++) {
				if($this->tasks[$i]->get_id() === $xmlTask->get_id()) {

					// Parallel tends to wait for each Task Thread (fork) even if run() part is fully completed
					$xmlTask->wait();

					$this->tasks[$i] = $xmlTask;
					$j--;

					log_syslog(LOG_INFO, "Completed configuration task for url: " . $xmlTask->get_url());
				}
			}

			if($j == 0)
				break;
		}
	}

	public function is_active() {

		return $this->is_active;
	}

	public function add_task_byurl($url) {

		$this->tasks[count($this->tasks)] = new XMLConfigTask($url);
	}

	public function add_task($task) {

		if(gettype($task) === 'object'
			&& get_class($task) === 'petpm\XMLConfigTask')
			$this->tasks[count($this->tasks)] = $task;
		else
			throw new Exception("Not a valid instance of class XMLConfigTask");
	}

	public function get_smtp_hosts_info() {

		$smtp_hosts_info = array();

		for ($i=0; $i < count($this->tasks); $i++) {
			try {
				if($this->tasks[$i]->is_successful()) {
					$xmlconfig = $this->tasks[$i]->get_simple_xml_element();

					$outgoingServer = $xmlconfig->emailProvider->xpath("outgoingServer");

					if($outgoingServer != FALSE) {
						if(count($outgoingServer) > 1) {
							for ($i=0; $i < count($outgoingServer); $i++) {
								if(strtolower($outgoingServer[$i]['type']->__toString()) === 'smtp') {
									$smtp_host = array(
										"host" => $outgoingServer[$i]->hostname->__toString(),
										"port" => $outgoingServer[$i]->port->__toString(),
										"socketType" => $outgoingServer[0]->socketType->__toString()
									);

									if(!in_array($smtp_host, $smtp_hosts_info))
										array_push($smtp_hosts_info, $smtp_host);
								}
							}
						}
						else {
							if(strtolower($outgoingServer[0]['type']->__toString()) === 'smtp') {
								$smtp_host = array(
									"host" => $outgoingServer[0]->hostname->__toString(),
									"port" => $outgoingServer[0]->port->__toString(),
									"socketType" => $outgoingServer[0]->socketType->__toString()
								);

								if(!in_array($smtp_host, $smtp_hosts_info))
									array_push($smtp_hosts_info, $smtp_host);
							}
						}
					}
				}
			}
			catch (\Exception $e) {
				log_syslog(LOG_CRIT, "Error in task for url: " . $this->tasks[$i]->get_url() . " (" . $e->getMessage() . ")");
			}
		}

		return $smtp_hosts_info;
	}

	public function close() {
		$this->tasksServer->close();
	}
}

?>