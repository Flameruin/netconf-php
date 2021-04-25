<?php
error_reporting(0);
//ini_set('expect.loguser', 0);
// if set it off, then user will not be able to see what server is sending,like capabilities and other information.
include('CommitException.php');
include('LoadException.php');
include('XML.php');
include('NetconfException.php');
define("XML_VERSION", "<?xml version=\"1.0\" encoding=\"utf-8\"?>");
//base:1.1 uses a diffrent method to mark end of message @link https://tools.ietf.org/html/rfc6242#section-4.1
define("NETCONF_MSG_END", "]]>]]>");

class Device{
	private $messageId = 0;
	protected $server_capability;
	protected $port;
	protected $helloRpc;
	protected $is_connected = false;
	protected $last_rpc_reply;
	private $hostName;
	private $userName;
	private $password;
	private $stream;
	private $connect_timeout = 10;
	private $reply_timeout_sec = 0;
	private $reply_timeout_usec = 450000;//450 milliseconds, 0.45 seconds

	/**
	 * A <code>Device</code> is used to define a Netconf server.
	 * <p>
	 * Typically, one
	 * <ol>
	 * <li>creates a {@link #Device(String,String,String) Device}
	 * object.</li>
	 * <li>perform netconf operations on the Device object.</li>
	 * <li>Finally, one must close the Device and release resources with the
	 * {@link #close() close()} method.</li>
	 * </ol>
	 */
	public function __construct(){

		if(func_num_args() == 1 && is_array(func_get_arg(0))){
			$this->Device_array(func_get_arg(0));
		}else{
			$this->Device_string(func_get_args());
		}
	}

	/**
	 * This function is called when user passes list of string as arguments
	 * while creating object of Device class
	 * @param $arr
	 */
	public function Device_string($arr){

		if(count($arr) == 4){
			if(is_array($arr[3])){
				$this->hello_rpc = $this->create_hello_rpc($arr[3]);
				$this->port      = 830;
			}else{
				$this->port      = $arr[3];
				$this->hello_rpc = $this->default_hello_rpc();
			}
		}else{
			if(count($arr) == 5){
				if(is_array($arr[3])){
					$this->hello_rpc = $this->create_hello_rpc($arr[3]);
					$this->port      = $arr[4];
				}else{
					$this->port      = $arr[3];
					$this->hello_rpc = $this->create_hello_rpc($arr[4]);
				}
			}else{
				$this->port      = 830;
				$this->hello_rpc = $this->default_hello_rpc();
			}
		}
		$this->hostName = $arr[0];
		$this->userName = $arr[1];
		$this->password = $arr[2];
	}

	/**
	 * This function is called when user passes argument as array,
	 * while creating object of Device class
	 * @param array $params
	 */
	public function Device_array(array $params){

		if($params["hostname"] != null && !(empty($params["hostname"])) && (is_string($params["hostname"]))){
			$this->hostName = $params["hostname"];
		}else{
			die ("host name should be string and should not be empty or null\n");
		}
		if(empty($params["username"]) || is_null($params["username"])){
			die ("user name should not be empty or null\n");
		}else{
			$this->userName = $params["username"];
		}
		if(empty($params["password"]) || is_null($params["password"])){
			die("password should not be empty or null\n");
		}else{
			$this->password = $params["password"];
		}
		if($params["port"] != null && !(empty($params["port"])) && is_numeric($params["port"])){
			$this->port = $params["port"];
		}else{
			$this->port = 830;
		}
		if($params["capability"] != null && !(empty($params["capability"]))){
			$this->hello_rpc = $this->create_hello_rpc($params["capability"]);
		}else{
			$this->hello_rpc = $this->default_hello_rpc();
		}
	}

	/**
	 *Prepares a new <code?Device</code> object, either with default
	 *client capabilities and default port 830, or with user specified
	 *capabilities and port no, which can then be used to perform netconf
	 *operations.
	 * @throws NetconfException
	 */
	public function connect(){

		$ctx          = stream_context_create([
			'http' => [
				'timeout' => $this->reply_timeout_sec
			]
		]);
		$this->stream = fopen("expect://ssh -o connectTimeout={$this->connect_timeout} {$this->userName}@{$this->hostName} -p {$this->port} -s netconf", "r+", false, $ctx);
		ini_set('expect.timeout', $this->reply_timeout_sec);
		ini_set('default_charset', 'UTF-8');
		$flag = true;
		//short delay to avoid race condition
		usleep(1000);//1 micro = 0.001 mili, 1000000 micro = 1 sec
		while($flag){
			$res = $this->read_until(array(
				'password:',
				'passphrase',
				'yes/no)?',
				NETCONF_MSG_END
			), false);
			switch($res){
				case "PASSWORD:":
				case "PASSPHRASE":
					fwrite($this->stream, $this->password."\n");
					$this->send_hello($this->hello_rpc);
					if(stripos($this->last_rpc_reply, 'password') !== false){
						throw new NetconfException("Wrong username or password");
					}elseif(strpos($this->last_rpc_reply, 'hello') === false){
						throw new NetconfException("Somthing went wrong");
					}
					$flag = false;
					break;
				case NETCONF_MSG_END://NO PASSPHRASE so say hello
					$this->send_hello($this->hello_rpc);
					$flag = false;
					break;
				case 'YES/NO)?'://YES or NO - we say yes
					fwrite($this->stream, "yes\n");  // default value of yes for for new netconf host
					break;
				case 'EOF'://EOF or Timeout:
					throw new NetconfException("Timeout Connecting to device");
				default:
					throw new NetconfException("Device not found/ unknown error occurred while connecting to Device");
			}
		}
		$this->is_connected = true;
	}

	/**
	 * Sends the Hello capabilities to the netconf server.
	 * @param $hello - rpc message
	 * @throws NetconfException
	 */
	private function send_hello($hello){

		$reply                   = "";
		$reply                   = $this->get_rpc_reply($hello);
		$this->server_capability = $server_capability = $reply;
		$this->last_rpc_reply    = $reply;
	}

	/**
	 * Gets the current rpc attribute string. If the rpc attribute string is not yet generated or has been reset then
	 * we generate rpc attributes from the RPC Attribute Map.
	 * @return string The attribute set XML formatted into a string.
	 */
	public function getRpcAttributes(){

		//        if(rpcAttributes == null) {
		//            $attributes = "";
		//            $useDefaultNamespace = true;
		//            for (Map.Entry<String, String> attribute : rpcAttrMap.entrySet()) {
		//                  $attributes .=(String.format(" %1s=\"%2s\"", attribute.getKey(), attribute.getValue()));
		//                if ("xmlns".equals(attribute.getKey()))
		//                    $useDefaultNamespace = false;
		//            }
		//            if($useDefaultNamespace)
		$attributes .= " xmlns=\"urn:ietf:params:xml:ns:netconf:base:1.0\"";

		//            }
		return $attributes;
	}

	/**
	 * Sends the RPC as a string and returns the response as a string.
	 * based on juniper but made changes @link https://github.com/Juniper/netconf-php/blob/652a8b61c27bbe627c752569a489bcb455b31b67/netconf/Device.php#L209
	 * @Auther Dekel Tamam
	 * @param $rpc
	 * @return string
	 */
	private function get_rpc_reply($rpc){

		//send rpc
		$rpc = $this->send_rpc_request($rpc);
		//read replay
		$rpc_reply = $this->read_rpc_reply();
		//removes the sent $rpc from $rpc_reply
		$rpc_reply = str_replace($rpc, '', $rpc_reply);

		//returns $rpc_replay without the ending and new lines
		return str_replace(array(
			NETCONF_MSG_END,
			"\r",
			"\n"
		), "", $rpc_reply);
	}

	/**
	 * write the rpc to the device and returns the wrriten rpc
	 * @param string $rpc
	 * @return string rpc that was written to device
	 */
	private function send_rpc_request($rpc){

		$rpc_reply = "";
		$this->messageId++;
		if(strpos($rpc, XML_VERSION) === false){
			$rpc = XML_VERSION.$rpc;
		}
		$rpc = trim(str_replace("<rpc>", "<rpc".$this->getRpcAttributes()." message-id=\"{$this->messageId}\">", $rpc));
		//writing rpc to stream (device)
		$rpc = preg_replace("/\s+/", ' ', $rpc);//removes spaces and replace with single space
		fwrite($this->stream, $rpc."\n");

		return $rpc;
	}

	/**
	 * read the rpc replay of the device
	 * @return string rpc that was read from the device
	 */
	private function read_rpc_reply(){

		$rpc_reply = '';
		//we are reading both what we sent to the device and what the device sent to so we weill have two NETCONF_MSG_END
		//if we could have flushed the before it would have been nice but didn't work for me
		$write  = null;
		$except = null;
		$read   = array($this->stream);
		//read until stream_select returns false, meaning we no longer have the stream OR $char false (EOF)
		while(stream_select($read, $write, $except, $this->reply_timeout_sec, $this->reply_timeout_usec) && ($char = fgetc($this->stream)) !== false){
			$rpc_reply .= $char;
		}

		return $rpc_reply;
	}

	/**
	 * read stream until string in $str_arr is found
	 * @param string|string[] $str_arr        array to search for (if strings make array)
	 * @param bool            $case_sensitive defualt true checks and returns string as is
	 *                                        false will use strtoupper and return the found string in uppercase
	 * @return string
	 * @Auther   Dekel Tamam
	 * @internal made this to get rid of expect
	 */
	private function read_until($str_arr, $case_sensitive = true){

		$ret  = false;
		$line = '';
		if( !is_array($str_arr)){
			$str_arr = array($str_arr);
		}
		if( !$case_sensitive){
			foreach($str_arr as $key => $value){
				$str_arr[$key] = strtoupper($value);
			}
		}
		//might want to check this
		//foreach($str_arr as $key => $value){
		//	if(!is_string($value)) return $ret;
		//}
		$write  = null;
		$except = null;
		$read   = array($this->stream);
		//read until stream_select returns false, meaning we no longer have the stream or $ret not false
		while(stream_select($read, $write, $except, $this->reply_timeout_sec, $this->reply_timeout_usec) && $ret === false){
			$char = fgetc($this->stream);
			if($char === false){
				error_log("Reached EOF, Read: {$line}");
				$ret = 'EOF';
				break;
			}
			$line .= $case_sensitive ? $char : strtoupper($char);
			foreach($str_arr as $str){
				if(Device::ends_with($line, $str) !== false){
					$ret = $str;
					break;
				}
			}
		}

		return $ret;
	}

	/**
	 * Sends RPC(as XML object or as a String) over the default Netconf session
	 * and get the response as an XML object.
	 * <p>
	 * @param rpc
	 *       RPC content to be sent.
	 * @return false|XML RPC reply sent by Netconf server.
	 * @throws NetconfException
	 */
	public function execute_rpc($rpc){

		if($rpc == null){
			throw new NetconfException("Null RPC");
		}
		if(gettype($rpc) == "string"){
			$rpc = trim($rpc);
			if( !Device::starts_with($rpc, "<rpc>")){
				if( !Device::starts_with($rpc, "<")){
					$rpc = "<".$rpc."/>";
				}
				$rpc = "<rpc>{$rpc}</rpc>".NETCONF_MSG_END;
			}
			$rpc_reply_string = $this->get_rpc_reply($rpc);
		}else{
			$rpcString        = $rpc->toString();
			$rpc_reply_string = $this->get_rpc_reply($rpcString);
		}
		$this->last_rpc_reply = $rpc_reply_string;

		return $this->convert_to_xml($rpc_reply_string);
	}

	/**
	 * Converts the string to XML.
	 * @param $rpc_reply
	 * @return false|XML object.
	 */
	private function convert_to_xml($rpc_reply){

		$dom = new DomDocument();
		$xml = $dom->loadXML($rpc_reply);
		if( !$xml){
			return false;
		}
		$root = $dom->documentElement;

		return new XML($root, $dom, $rpc_reply);
	}

	/**
	 * @retrun the last RPC Reply sent by Netconf server.
	 */
	public function get_last_rpc_reply(){

		return $this->last_rpc_reply;
	}

	/**
	 * @return string
	 */
	public function get_server_capability(){

		return $this->server_capability;
	}

	/**
	 * Leaving out the filter on the <get> operation returns the entire data
	 * model.
	 * @return false|XML returns the entire data model
	 * @throws NetconfException
	 */
	public function get_entire_data(){

		return $this->execute_rpc('<get/>');;
	}

	/**
	 * @return string|int return device's port
	 */
	public function get_port(){

		return $this->port;
	}

	/**
	 * @return string return device's helloRpc
	 */
	public function get_hello_rpc(){

		return $this->helloRpc;
	}

	/**
	 * @return bool is device connected
	 */
	public function get_is_connected(){

		return $this->is_connected;
	}

	/**
	 * sets the username of the Netconf server.
	 * @param username is the username which is to be set
	 * @throws NetconfException
	 */
	public function set_username($username){

		if($this->is_connected){
			throw new NetconfException("Can't change username on a live device. Close the device first.");
		}else{
			$this->userName = $username;
		}
	}

	/**
	 * sets the hostname of the Netconf server.
	 * @param hostname is the hostname which is to be set.
	 * @throws NetconfException
	 */
	public function set_hostname($hostname){

		if($this->is_connected){
			throw new NetconfException("Can't change hostname on a live device. Close the device first");
		}else{
			$this->hostName = $hostname;
		}
	}

	/**
	 * sets the password of the Netconf server.
	 * @param password is the password which is to be set.
	 * @throws NetconfException
	 */
	public function set_password($password){

		if($this->is_connected){
			throw new NetconfException("Can't change the password for the live device. Close the device first");
		}else{
			$this->password = $password;
		}
	}

	/**
	 * sets the port of the Netconf server.
	 * @param port is the port no. which is to be set.
	 * @throws NetconfException
	 */
	public function set_port($port){

		if($this->is_connected){
			throw new NetconfException("Can't change the port no for the live device. Close the device first");
		}else{
			$this->port = $port;
		}
	}

	/**
	 * Set the client capabilities to be advertised to the Netconf server.
	 * @param capabilities Client capabilities to be advertised to the Netconf server.
	 * @throws NetconfException
	 */
	public function set_capabilities($capabilities){

		if($capabilities == null){
			die("Client capabilities cannot be null");
		}
		if($this->is_connected){
			throw new NetconfException("Can't change clien capabilities on a live device. Close the device first.");
		}
		$this->helloRpc = $this->create_hello_rpc($capabilities);
	}

	/**
	 * set connect_timeout of the Netconf server
	 * @param $ctime - is the connection timeout which is to be set
	 * @throws NetconfException
	 */
	public function set_connect_timeout($ctime){

		if($this->is_connected){
			throw new NetconfException("Can't change connect timeout value for live device. Close the device first");
		}else{
			$this->connect_timeout = $ctime;
		}
	}

	/**
	 * set reply_timeout seconds and useconds of the Netconf server
	 * recommended to not set less than 0.45 seconds
	 * @param $rtime - is the reply timeout in which reply should come from server in seconds
	 * @throws NetconfException
	 */
	public function set_reply_timeout($rtime){

		if($this->is_connected){
			throw new NetconfException("Can't change reply timeout value for live device. Close the device first");
		}elseif($rtime >= 0){
			//if $rtime is float assumes it's in seconds and convert to useconds
			$this->reply_timeout_usec = (is_float($rtime)) ? filter_var(fmod($rtime, 1), FILTER_SANITIZE_NUMBER_INT) * 100000 : 0;
			$this->reply_timeout_sec  = (int)floor($rtime);
		}else{
			throw new NetconfException("Reply timeout must be bigger than 0");
		}
	}

	/**
	 *Check if the last RPC reply returned from Netconf server has any error.
	 * @return bool true if any errors are found in last RPC reply.
	 * @throws NetconfException
	 */
	public function has_error(){

		if( !$this->is_connected){
			throw new NetconfException("No RPC executed yet, you need to establish a connection first");
		}
		if($this->last_rpc_reply == "" || !(strstr($this->last_rpc_reply, "<rpc-error>"))){
			return false;
		}
		$reply = $this->convert_to_xml($this->last_rpc_reply);
		//$replay_arr = $reply->toArray();
		//$tagList    = array("rpc-error", "error-severity");
		//$errorSeverity = $reply->find_value($tagList);
		//$errorSeverity = $replay_arr['rpc-error']['error-severity'];
		$errorSeverity = (string)$reply->simplexml->{'rpc-error'}->{'error-severity'};
		if($errorSeverity === "error"){
			return true;
		}

		return false;
	}

	/**
	 *Check if the last RPC reply returned from Netconf server has any warning.
	 * @return bool true if any warnings are found in last RPC reply.
	 * @throws NetconfException
	 */
	public function has_warning(){

		if( !$this->is_connected){
			throw new NetconfException("No RPC executed yet, you need to establish a connection first");
		}
		if($this->last_rpc_reply == "" || !(strstr($this->last_rpc_reply, "<rpc-error>"))){
			return false;
		}
		$reply         = $this->convert_to_xml($this->last_rpc_reply);
		$tagList[0]    = "rpc-error";
		$tagList[1]    = "error-severity";
		$errorSeverity = $reply->find_value($tagList);
		if($errorSeverity != null && $errorSeverity == "warning"){
			return true;
		}

		return false;
	}

	/**
	 * Check if the last RPC reply returned from the Netconf server.
	 * contains &lt;ok&gt; (<ok/>) tag
	 * @return bool true if &lt;ok&gt; (<ok/>) tag is found in last RPC reply.
	 * @throws NetconfException
	 */
	public function is_ok(){

		if( !$this->is_connected){
			throw new NetconfException("No RPC executed yet, you need to establish a connection first");
		}
		if($this->last_rpc_reply != null && strstr($this->last_rpc_reply, "<ok/>")){
			return true;
		}

		return false;
	}

	/**
	 * Locks the candidate configuration.
	 * @return bool true if successful.
	 * @throws NetconfException
	 */
	public function lock_config(){

		$rpc                  = "<rpc>";
		$rpc                  .= "<lock>";
		$rpc                  .= "<target>";
		$rpc                  .= "<candidate/>";
		$rpc                  .= "</target>";
		$rpc                  .= "</lock>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		if($this->has_error() || !$this->is_ok()){
			return false;
		}

		return true;
	}

	/**
	 * Unlocks the candidate configuration.
	 * @return bool true if successful.
	 * @throws NetconfException
	 */
	public function unlock_config(){

		$rpc                  = "<rpc>";
		$rpc                  .= "<unlock>";
		$rpc                  .= "<target>";
		$rpc                  .= "<candidate/>";
		$rpc                  .= "</target>";
		$rpc                  .= "</unlock>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		if($this->has_error() || !$this->is_ok()){
			return false;
		}

		return true;
	}

	/**
	 * function to check start
	 * @param $string
	 * @param $substring
	 * @return bool
	 */
	static function starts_with($string, $substring){

		trim($substring);
		trim($string);
		$length = strlen($substring);
		if(substr($string, 0, $length) === $substring){
			return true;
		}

		return false;
	}

	/**
	 * function to check end added
	 * @param $string
	 * @param $substring
	 * @return bool
	 */
	static function ends_with($string, $substring){

		trim($substring);
		trim($string);
		$start -= $length = strlen($substring);
		if(substr($string, $start, $length) === $substring){
			return true;
		}

		return false;
	}

	/**
	 *Loads the candidate configuration, Configuration should be in XML format.
	 * @param $configuration
	 *        Configuration, in XML fromat, to be loaded. For eg:
	 *        &lt;configuration&gt;&lt;system&gt;&lt;services&gt;&lt;ftp/&gt;&lt;/services&gt;&lt;/
	 *        system&gt;&lt;/configuration&gt;
	 *        will load 'ftp' under the 'systems services' hierarchy.
	 * @param $loadType
	 *        You can choose "merge" or "replace" as the loadType.
	 * @throws NetconfException|LoadException
	 */
	public function load_xml_configuration($configuration, $loadType){

		if($loadType == null || ( !($loadType == "merge") && !($loadType == "replace"))){
			throw new NetconfException("'loadType' argument must be merge|replace\n");
		}
		if(Device::starts_with($configuration, "<?xml version")){
			$configuration = preg_replace('/\<\?xml[^=]*="[^"]*"\?\>/', "", $configuration);
		}
		$rpc                  = "<rpc>";
		$rpc                  .= "<edit-config>";
		$rpc                  .= "<target>";
		$rpc                  .= "<candidate/>";
		$rpc                  .= "</target>";
		$rpc                  .= "<default-operation>";
		$rpc                  .= $loadType;
		$rpc                  .= "</default-operation>";
		$rpc                  .= "<config>";
		$rpc                  .= $configuration;
		$rpc                  .= "</config>";
		$rpc                  .= "</edit-config>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		if($this->has_error() || !$this->is_ok()){
			throw new LoadException("Load operation returned error");
		}
	}

	/**
	 * Loads the candidate configuration, Configuration should be in text/tree format.
	 * @param $configuration
	 *                            Configuration, in text/tree format, to be loaded.
	 *                            For example,
	 *                            "system{
	 *                            services{
	 *                            ftp;
	 *                            }
	 *                            }"
	 *                            will load 'ftp' under the 'systems services' hierarchy.
	 * @param $loadType           - You can choose "merge" or "replace" as the loadType.
	 * @throws LoadException
	 * @throws NetconfException
	 */
	public function load_text_configuration($configuration, $loadType){

		if($loadType == null || ( !($loadType == "merge") && !($loadType == "replace"))){
			throw new NetconfException ("'loadType' argument must be merge|replace\n");
		}
		$rpc                  = "<rpc>";
		$rpc                  .= "<edit-config>";
		$rpc                  .= "<target>";
		$rpc                  .= "<candidate/>";
		$rpc                  .= "</target>";
		$rpc                  .= "<default-operation>";
		$rpc                  .= $loadType;
		$rpc                  .= "</default-operation>";
		$rpc                  .= "<config-text>";
		$rpc                  .= "<configuration-text>";
		$rpc                  .= $configuration;
		$rpc                  .= "</configuration-text>";
		$rpc                  .= "</config-text>";
		$rpc                  .= "</edit-config>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		if($this->has_error() || !$this->is_ok()){
			throw new LoadException("Load operation returned error");
		}
	}

	/**
	 * Loads the candidate configuration, Configuration should be in set format.
	 * NOTE: This method is applicable only for JUNOS release 11.4 and above.
	 * @param $configuration
	 *       Configuration, in set format, to be loaded. For example,
	 *       "set system services ftp"
	 *       will load 'ftp' under the 'systems services' hierarchy.
	 *       To load multiple set statements, separate them by '\n' character.
	 * @throws LoadException
	 * @throws NetconfException
	 */
	public function load_set_configuration($configuration){

		$rpc                  = "<rpc>";
		$rpc                  .= "<load-configuration action=\"set\">";
		$rpc                  .= "<configuration-set>";
		$rpc                  .= $configuration;
		$rpc                  .= "</configuration-set>";
		$rpc                  .= "</load-configuration>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		if($this->has_error() || !$this->is_ok()){
			throw new LoadException("Load operation returned error");
		}
	}

	/**
	 * Commit the candidate configuration.
	 * @throws NetconfException|CommitException
	 */
	public function commit(){

		$rpc                  = "<rpc>";
		$rpc                  .= "<commit/>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		if($this->has_error() || !$this->is_ok()){
			throw new CommitException("Commit operation returned error");
		}
	}

	/**
	 * Commit the candidate configuration, temporarily. This is equivalent of
	 * 'commit confirm'
	 * @param $seconds
	 *        Time in seconds, after which the previous active configuratio
	 *        is reverted back to.
	 * @throws CommitException|NetconfException
	 */
	public function commit_confirm($seconds){

		$rpc                  = "<rpc>";
		$rpc                  .= "<commit>";
		$rpc                  .= "<confirmed/>";
		$rpc                  .= "<confirm-timeout>".$seconds."</confirm-timeout>";
		$rpc                  .= "</commit>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		if($this->has_error() || !$this->is_ok()){
			throw new CommitException("Commit operation returned error");
		}
	}

	/**
	 * Validate the candidate configuration.
	 * @return bool true if validation successful.
	 * @throws NetconfException
	 */
	public function validate(){

		$rpc                  = "<rpc>";
		$rpc                  .= "<validate>";
		$rpc                  .= "<source>";
		$rpc                  .= "<candidate/>";
		$rpc                  .= "</source>";
		$rpc                  .= "</validate>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		if($this->has_error() || !$this->is_ok()){
			return false;
		}

		return true;
	}

	/**
	 * Reboot the device corresponding to the Netconf Session.
	 * @return string RPC reply sent by Netconf servcer.
	 * @throws NetconfException
	 */
	public function reboot(){

		$rpc = "<rpc>";
		$rpc .= "<request-reboot/>";
		$rpc .= "</rpc>";
		$rpc .= NETCONF_MSG_END."\n";

		return $this->get_rpc_reply($rpc);
	}

	/**
	 * This method should be called for load operations to happen in 'private' mode.
	 * @param $mode
	 *       Mode in which to open the configuration.
	 *       Permissible mode(s) : "private"
	 * @throws NetconfException
	 */
	public function open_configuration($mode){

		$rpc                  = "<rpc>";
		$rpc                  .= "<open-configuration>";
		$rpc                  .= "<";
		$rpc                  .= $mode;
		$rpc                  .= "/>";
		$rpc                  .= "</open-configuration>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
	}

	/**
	 * This method should be called to close a private session, in case its started.
	 * @throws NetconfException
	 */
	public function close_configuration(){

		$rpc                  = "<rpc>";
		$rpc                  .= "<close-configuration/>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
	}

	/**
	 * Run a cli command.
	 * NOTE: The text utput is supported for JUNOS 11.4 and alter.
	 * arg 0 is the cli command to be executed.
	 * @return  string|The|null $result of the command,as a String.
	 */
	public function run_cli_command(){

		$rpcReply = "";
		$format   = "text";
		if(func_num_args() == 2){
			$format = "html";
		}
		$rpc                  = "<rpc>";
		$rpc                  .= "<command format=\"text\">";
		$rpc                  .= func_get_arg(0);
		$rpc                  .= "</command>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		trim($rpcReply);
		$xmlreply = $this->convert_to_xml($rpcReply);
		if( !$xmlreply){
			echo "RPC-REPLY is an invalid XML\n";

			return null;
		}
		$tags[0] = "output";
		$output  = $xmlreply->find_value($tags);
		if($output != null){
			return $output;
		}

		return $rpcReply;
	}

	/**
	 * Loads the candidate configuration from file,
	 * configuration should be in XML format.
	 * @param $configFile
	 *       Path name of file containing configuration,in xml format,
	 *       ro be loaded.
	 * @param $loadType
	 *       You can choose "merge" or "replace" as the loadType.
	 * @throws NetconfException|LoadException
	 */
	public function load_xml_file($configFile, $loadType){

		$configuration = "";
		$file          = fopen($configFile, "r");
		if( !$file){
			throw new NetconfException ("File not found error");
		}
		while( !feof($file)){
			$line          = fgets($file);
			$configuration .= $line;
		}
		fclose($file);
		if($loadType == null || ( !($loadType == "merge") && !($loadType == "replace"))){
			throw new NetconfException("'loadType' must be merge|replace");
		}
		$this->load_xml_configuration($configuration, $loadType);
	}

	/**
	 * Loads the candidate configuration from file,
	 * configuration should be in text/tree format.
	 * @param $configFile Path name of file containining configuration, in xml format,
	 *                    to be loaded.
	 * @param $loadType   You can choose "merge" or "replace" as the loadType.
	 * @throws LoadException
	 * @throws NetconfException
	 */
	public function load_text_file($configFile, $loadType){

		$configuration = "";
		$file          = fopen($configFile, "r");
		if( !$file){
			throw new NetconfException("File not found error");
		}
		while($line = fgets($file)){
			$configuration .= $line;
		}
		fclose($file);
		if($loadType == null || ( !($loadType == "merge") && !($loadType == "replace"))){
			throw new NetconfException("'loadType' argument must be merge|replace\n");
		}
		$this->load_text_configuration($configuration, $loadType);
	}

	/**
	 * Loads the candidate configuration from file,
	 * configuration should be in set format.
	 * NOTE: This method is applicable only for JUNOS release 11.4 and above.
	 * @param $configFile
	 *     Path name of file containing configuration, in set format,
	 *     to be loaded.
	 * @throws LoadException
	 * @throws NetconfException
	 */
	public function load_set_file($configFile){

		$configuration = "";
		$file          = fopen($configFile, "r");
		if( !$file){
			throw new NetconfException("File not found error");
		}
		while($line = fgets($file)){
			$configuration .= $line;
		}
		fclose($file);
		$this->load_set_configuration($configuration);
	}

	/**
	 * @param $target
	 * @param $configTree
	 * @return string
	 * @throws NetconfException
	 */
	private function get_config($target, $configTree){

		$rpc                  = "<rpc>";
		$rpc                  .= "<get-config>";
		$rpc                  .= "<source>";
		$rpc                  .= "<".$target."/>";
		$rpc                  .= "</source>";
		$rpc                  .= "<filter type=\"subtree\">";
		$rpc                  .= $configTree;
		$rpc                  .= "</filter>";
		$rpc                  .= "</get-config>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;

		return $rpcReply;
	}

	/**
	 * Retrieve the candidate configuration, or part of the configuration.
	 * If no argument is specified, then the
	 * configuration is returned for
	 * &gt;<configuration$gt;&lt;/configuration&gt;
	 * else
	 * For example, to get the whole configuration, argument should be
	 * &lt;configuration&gt;&lt;/configuration&gt;
	 * return configuration data as XML object.
	 * @throws NetconfException
	 */
	public function get_candidate_config(){

		if(func_num_args() == 1){
			return $this->convert_to_xml($this->get_config("candidate", func_get_arg(0)));
		}

		return $this->convert_to_xml($this->get_config("candidate", "<configuration></configuration>"));
	}

	/**
	 * Retrieve the running configuration, or part of the configuration.
	 * If no argument is specified then
	 * configuration is returned for
	 * &lt;configuration&gt;&lt;/configuration&gt;
	 * else
	 * For example, to get the whole configuration, argument should be
	 * &lt;configuration&gt;&lt;/configuration&gt;
	 * @return false|XML configuration data as XML object.
	 * @throws NetconfException
	 */
	public function get_running_config(){

		if(func_num_args() == 1){
			return $this->convert_to_xml($this->get_config("running", func_get_arg(0)));
		}

		return $this->convert_to_xml($this->get_config("running", "<configuration></configuration>"));
	}

	/**
	 * Loads and commits the candidate configuration, Configuration can be in text/xml/set foramt.
	 * @param $configFile
	 *        Path name of file containing configuration, in text/xml/set format,
	 *        to be loaded. For example,
	 *        "system{
	 *        services{
	 *        ftp;
	 *        }
	 *        }"
	 *        will load 'ftp' under the 'systems services' hierarchy.
	 *        OR
	 *        &lt;configuration&gt;&lt;system&gt;&lt;serivces&gt;ftp&lt;/services&gt;&lt;/system&gt;&lt;/
	 *        configuration&gt;
	 *        will load 'ftp' under the 'systems services' hierarchy.
	 *        OR
	 *        "set system services ftp"
	 *        wull load 'ftp' under the 'systems services' hierarchy.
	 * @param $loadType
	 *        You can choose "merge" or "replace" as the loadType.
	 *        NOTE : This parameter's value is redundant in case the file contains
	 *        configuration in 'set' format.
	 * @throws CommitException
	 * @throws LoadException
	 * @throws NetconfException
	 */
	public function commit_this_configuration($configFile, $loadType){

		$configuration = "";
		$file          = fopen($configFile, "r");
		if( !$file){
			throw new NetconfException ("File not found");
		}
		while($line = fgets($file)){
			$configuration .= $line;
		}
		trim($configuration);
		fclose($file);
		if($this->lock_config()){
			if(Device::starts_with($configuration, "<")){
				$this->load_xml_configuration($configuration, $loadType);
			}else{
				if(Device::starts_with($configuration, "set")){
					$this->load_set_configuration($configuration);
				}else{
					$this->load_text_configuration($configuration, $loadType);
				}
			}
			$this->commit();
			$this->unlock_config();
		}else{
			throw new NetconfException ("Unclean lock operation. Cannot proceed further");
		}
	}

	/**
	 * Closes the Netconf session
	 * @return bool
	 * @throws NetconfException
	 */
	public function close(){

		$rpc                  = "<rpc>";
		$rpc                  .= "<close-session/>";
		$rpc                  .= "</rpc>";
		$rpc                  .= NETCONF_MSG_END."\n";
		$rpcReply             = $this->get_rpc_reply($rpc);
		$this->last_rpc_reply = $rpcReply;
		fclose($this->stream);

		return $this->is_connected = $this->is_ok() ? false : true;
	}

	/**
	 * Create hello_rpc packet with user defined capabilities
	 * @param array $capabilities specified by user
	 * @return string
	 */
	private function create_hello_rpc(array $capabilities){

		$hello_rpc = XML_VERSION."<hello xmlns=\"urn:ietf:params:xml:ns:netconf:base:1.0\">";
		$hello_rpc .= "<capabilities>";
		foreach($capabilities as $capIter){
			$hello_rpc .= "<capability>".$capIter."</capability>";
		}
		$hello_rpc .= "</capabilities>";
		$hello_rpc .= "</hello>";
		$hello_rpc .= NETCONF_MSG_END;

		return $hello_rpc;
	}

	/**
	 * function to generate default capabilities of client
	 */
	private function get_default_client_capabilities(){

		$defaultCap[0] = "urn:ietf:params:netconf:base:1.0";
		$defaultCap[1] = "urn:ietf:params:netconf:base:1.0#candidate";
		$defaultCap[2] = "urn:ietf:params:netconf:base:1.0#confirmed-commit";
		$defaultCap[3] = "urn:ietf:params:netconf:base:1.0#validate";
		$defaultCap[4] = "urn:ietf:params:netconf:base:1.0#url?protocol=http,ftp,file";

		return $defaultCap;
	}

	/**
	 *  function to generate default hello_rpc packet.
	 *  It calls get_default_client_capabilities() function to generate default capabilites of client
	 */
	private function default_hello_rpc(){

		$defaultCap = $this->get_default_client_capabilities();

		return $this->create_hello_rpc($defaultCap);
	}

	/**
	 * method missing function
	 * It is called when some operation command is called directly
	 * For Example
	 * $device_name->get_alarm_information()
	 * this will call __call()function which will call execute_rpc("get-alarm-information")
	 * It will output alarm information which can be obtained from execute_rpc("get-alarm-information")
	 * @param $function
	 * @param $args
	 * @return false|RPC|XML
	 * @throws NetconfException
	 */
	public function __call($function, $args){

		$change = preg_replace('/_/', '-', $function);

		return $this->execute_rpc($change);
	}
}

?>
