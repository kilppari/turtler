<?php
/**
 * @author Pekka Mäkinen
 * @package none
 * @version 0.9.8
 *
 * Email: makinpek [ at ] paju.oulu.fi
 *
 * Simple framework for an IRC bot. Enables easy implementation of user made functions and services.
 * Added user functions are implemented as commands for the bot. (Run only when called by other users)
 * Added user made services are run constantly.
 *
 * Feel free to modify but just remember to give credits to me.
 *
 * Example of how to initialize and run the bot:
 * $turtler = new Turtler("nameofbot", "bot.log");
 * $turtler->connect("irc.quakenet.org", 6667, #channelname);
 *
 * See readme.txt for more information about external functions.
 */
class Turtler {

	private $_version = "0.9.8";
	//variables for host's address and port, bot's nick and for default channel
	private $_host, $_port;
	//Bot's primary and alternative nicknames and the default channel which will be joinen after connection to server.
	private $_nick, $_nickAlt, $_defaultchannel;
	//Array of the names of the channels the bot is on
	private $_channels = array();
	//Array for what channels are ready
	private $_channelready = array();
	//socket resource
	private $_socket;
	//path to the bot's logfile
	private $_logfile;// $_connected = false;
	//array for the bot's callsigns
	private $_callsigns;
	//arrays containing the names of the external functions and services
	private $_functions, $_services;
	//Repository for each service's variables that must be saved at all times
	private $_servicevars;
	//password for the private commands
	private $_passwd = "r00t";
	//flag to determine if all messages from the server is printed on screen
	private $_verbose = true;

	/**
	 * Class constructor
	 * @param string $nick, name for the bot
	 * @param string $logfile, optional, specifies the location of logfile
	 */
	public function __construct($nick, $logfile) {
		$this->_nick = $nick;
		$this->_callsigns = array("$nick, ", "$nick: ");
		$this->_logfile = $logfile;
	}

	/**
	 * @param string $host, IP or name of the server
	 * @param int $port, port of the server
	 * @param string $channel Optional parameter for default channel name;
	 * @return mixed, throws an exception if connection failed, else true
	 */
	function connect($host, $port) {
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if(!socket_connect($socket, $host, $port)) {
			throw new Exception("Could not connect to $host on port $port");
		}else {
			$this->_socket = $socket;
			if(func_num_args() == 3) $this->_defaultchannel = func_get_arg(2); //$channel;
			//$this->_defaultchannel = "#turtler";
			//$this->_connected = true;
			$this->handle($socket);
		}
	}

	/**
	 * The 'main' loop.
	 * Keeps the application running. Runs the connection parameters, checks
	 * for incoming messages and responds accordingly.
	 * @param mixed &$socket Reference to the socket resource we want to read and write to.
	 */
	private function handle(&$socket) {
				
		//Mandatory connection parameters.
		$this->writeMsg("PASS NOPASS\n\r", $socket);
		$this->writeMsg("NICK $this->_nick\n\r", $socket);
		$this->writeMsg("USER $this->_nick 0 * :Turtlerbot\n\r", $socket);

		//start listening to the messages from the socket
		do{
			$buffer = "";
			//512 bytes should be more than enough to read normal commands and server messages.
			//However if custom function is added which is excepted to read more bytes, this should be increased
			$buffer = socket_read($socket, 512);
			$this->log($buffer);
			if($this->_verbose) echo $buffer;

			//Check the buffer for different type of messages. If one is parsed and executed succesfully, do not check the others for nothing.
			if(!$this->parseServerMsg($buffer))
				if(!$this->parseBotCommand($buffer))
					$this->parseUserMsg($buffer);

			//run services
			if(!is_null($this->_services)) {
				foreach($this->_services as $service) {
					if(is_callable($service)) {
						$result = call_user_func_array($service, array(&$this->_servicevars[$service]));
						if($result) $this->writeMsg("PRIVMSG $channel :$result\n\r", $socket);
					}
				}
			}
		}while($buffer != "");
	}

	/**
	 * Specifies the default commands for moderating the bot from another client
	 * Commands are assumed to be sent by private messages
	 * @param string $buffer, socket's message buffer
	 * @return bool true if valid command was found and it was executes, else false
	 */
	private function parseBotCommand($buffer) {
		//parse the command part
		$splits = explode("PRIVMSG $this->_nick :$this->_passwd", $buffer);
		if(isset($splits[1])) {
			
			switch(trim($splits[1])) {
				case "!quit": $this->writeMsg("QUIT :Hyvästi!\r\n", $this->_socket); return true;
				/* Here's the place for more simple, no-parameter commands: */
			}
			//if a nick-command was received
			if(strpos($splits[1], "!nick")) {
				$parts = explode(" ", trim($splits[1]));
				$nick = $parts[1]; //new nickname should be the third element
				$this->writeMsg("NICK $nick\r\n", $this->_socket);
				$this->_nickAlt = "$nick";
				return true;
			}
			//if a channel was given in the command
			$channel = explode("#", trim($splits[1]));
			if(isset($channel[1])) {

				//determine if more parameters were given along the channelname
				$parts = explode(" ",$channel[1]);
				$chan = $parts[0];
				if(isset($parts[1])) $passwd = $parts[1];
				else $passwd = "";

				//check whether te command was !join or !part
				switch(trim($splits[1])) {
					case "!join #$channel[1]":
						$this->writeMsg("JOIN #$chan $passwd\r\n", $this->_socket);
						return true;
						
					case "!part #$chan":
						foreach($this->_channels as $key => $ch) {
							if(strcmp($ch, "#$chan") == 0) {
								unset($this->_channels[$key]);
								unset($this->_channelready[$key]);
								$this->writeMsg("PART #$chan :turtteli poistuu!\r\n", $this->_socket);
								return true;
							}
						}
						$this->log("Error: could not part channel $chan");
						return false;
						break;
				}
			}
			//$this->writeMsg("$msg\r\n", $this->_socket);
		}
		return false;
	}

	/**
	 * Parses some of the messages sent by the server.
	 * @param string $buffer, socket's message buffer
	 * @return bool true if buffer's message could be parsed and action taken, else false
	 */
	private function parseServerMsg($buffer) {

		/*IRC servers periodically send PING messages to inactive clients to determine if they are 'alive',
		  these must be replied with PONG plus any other string that came with the ping*/
		if(strcmp(substr($buffer, 0, 6), "PING :") == 0) {
				$this->writeMsg("PONG :" . substr($buffer, 6) . "\n\r", $this->_socket);
				return true;
		}
		
		//RPL_WELCOME (001) received if connection to the server was succesful
		//as this is received just once, try connecting to the default channel (if it is set)
		if(strpos($buffer, "001")) {
			if(!empty($this->_defaultchannel)) {
				$this->writeMsg("JOIN " . $this->_defaultchannel . "\n\r", $this->_socket);
				return true;
			}
		}

		//Setup the bot's nick and callsigns again if the bot's nick is changed succesfully,
		//server sends a message containing: NICK :'new nickname' to verify it.
		if(!empty($this->_nickAlt)) {
			if(strpos($buffer, "NICK :$this->_nickAlt")) {
				$this->_nick = $this->_nickAlt;
				$this->_callsigns = array("$this->_nick, ", "$this->_nick: ");
				unset($this->_nickAlt);
			}
		}


		//check if 'End of /NAMES list' was received, in other words, if joining on the channel was succesful
		if(strpos($buffer, "366")) {
			$key = count($this->_channels);
			//$splits = explode(" ", $buffer);
			$matches = array();
			//get channel name
			if(preg_match("/#[a-zA-ZäöÄÖ0-9]+/", $buffer, $matches)) {
				$this->_channels[$key] = $matches[0]; //save the channel name
				//$this->_channelready[$key] = true; //set the channel as ready to be listened
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks for messages sent by users on all the channels the bot is on. If valid
	 * message is received, executes an external command-function or lists all the available commands.
	 * @param string $buffer
	 * @return bool true if valid message was parsed and command was executed, else false
	 */
	private function parseUserMsg($buffer) {
		//check messages in each channel
		foreach($this->_channels as $key => $channel) {
			//if 'End of /names list' received (code 366), connection to channel was succesful
			//if(strpos($buffer, "366 $this->_nick $channel :")) $this->_channelready[$key] = true;
			//if($this->_channelready[$key]) {
				//checks if the bot is called by one of its callsigns
				foreach($this->_callsigns as $callsign) {
					$splits = $this->explodei("PRIVMSG $channel :$callsign", $buffer);
					//and determine what command was the bot given if any.
					if(isset($splits[1])) {
						if(strcasecmp(trim($splits[1]), "commands") == 0) {
							$msg = "PRIVMSG $channel :Available commands: " . implode(", ", $this->_functions) . "\r\n";
							$this->writeMsg($msg, $this->_socket);
						}
						else if(strcasecmp(trim($splits[1]), "version") == 0) {
							$msg = "PRIVMSG $channel :[Running Turtlerbot $this->_version]\r\n";
							$this->writeMsg($msg, $this->_socket);
						}
						else $this->getReply($splits[1], $channel, $buffer);
						return true;
					}
				}
			//}
		}
		return false;
	}

	/**
	 * Splits string to parts where the first part is the name for the function to look for and the rest
	 * are parameter values for that function.
	 * Checks if the function is valid and callable and runs it with the parameter values.
	 * @param string $msg, Sring with name and arguments for the function to be executed
	 * @param string $channel Name of the channel
	 * @param string $buffer Socket's message buffer
	 */
	private function getReply($msg, $channel, $buffer) {
	  //check the name of the sender
	  $splits = explode("!", $buffer);
	  //maximum nickname length is 9 in ircnet but can be longer in some other networks,
	  //30 is propably the maximum so I'll use it
	  if(strlen($splits[0]) <= 30) $name = substr($splits[0],1);
	  else $name = "";
		//split the string and extract command name and parameters
		$splits = explode(",", trim($msg));
		for ($i = 1 ; $i < count($splits) ; $i++) {
			$args[] = trim($splits[$i]);
		}
		$command = $splits[0];
		//checks if the function name is specified with the addFunction method
		if(!is_null($this->_functions)) {
			foreach($this->_functions as $function) {
				if(strcasecmp($function, $command) == 0) {
					if(is_callable($command)) {
						$result = call_user_func_array($command, $args);
						if($result) $this->writeMsg("PRIVMSG $channel :$name: $result\n\r", $this->_socket);
					}
				}
			}
		}
	}

	/**
	 * writeMsg makes sure that all bytes from a given buffer is written to the socket.
	 * Script by slyv, http://www.php.net/manual/en/function.socket-write.php
	 * @param <string> msg, message buffer
	 * @param <string> &socket, Reference to the socket we want to write
	 */
	private function writeMsg($msg, &$socket) {
		$len = strlen($msg);
		$offset = 0;
		while ($offset < $len) {
			$sent = socket_write($socket, substr($msg, $offset), $len-$offset);
			if ($sent === false) {
				// Error occurred, break the while loop
				break;
			}
			$offset += $sent;
		}
		if ($offset < $len) {
			$errorcode = socket_last_error();
			$errormsg = socket_strerror($errorcode);
			echo "SENDING ERROR: $errormsg";
		} else {
				echo "[msg sent]: $msg\n";
		// Data sent ok
		}
	}

	/**
	 * Case insensitive explode-function by siavash79_99 (http://theserverpages.com/php/manual/en/function.explode.php)
	 * @param string $separator
	 * @param string $string
	 * @param int $limit
	 * @return array
	 */
	function explodei($separator, $string, $limit = false )
	{
	   $len = strlen($separator);
	   for ( $i = 0; ; $i++ )
	   {
		   if ( ($pos = stripos( $string, $separator )) === false || ($limit !== false && $i > $limit - 2 ) )
		   {
			   $result[$i] = $string;
			   break;
		   }
		   $result[$i] = substr( $string, 0, $pos );
		   $string = substr( $string, $pos + $len );
	   }
	   return $result;
	}



	/**
	 * Saves a message with a timestamp to the logfile if the file is specified.
	 * No error detection, it just doesn't write anything if error occurs.
	 * @param <string> $msg
	 */
	private function log($msg) {
		if(!empty($this->_logfile)) {
			$log = date("j.n.Y-H:i: ") . $msg . "\n\r";
			file_put_contents($this->_logfile, $log);
		}
	}

	/**
	 * Saves the name of command-function
	 * @param string $name
	 */
	public function addFunction($name) {
		$this->_functions[] = $name;
	}

	/**
	 * Saves the name of a service-function and initializes services variables.
	 * @param string $name, Name of the service-function
	 * @param int $varcount, Number of variables the service needs
	 */
	public function addService($name, $varcount) {
		$this->_services[] = $name;
		//Initialize an array of variables for the service, timer as default
		$this->_servicevars[$name] = array("time" => time());
		//initialize additional variables
		for ($i=0; $i < $varcount; $i++) {
			$this->_servicevars[$name][$i] = null;
		}

	}
}

?>
