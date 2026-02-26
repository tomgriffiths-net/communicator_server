<?php
class communicator_server{
    private static $startups = [];
    private static $repeats = [];
    private static $shutdowns = [];

    private static $customActions = [];

    private static $exitServer = false;
    
    public static function init():void{
        $defaultSettings = [
            'autostart' => true,
            'port' => 8080,
            'ip' => '0.0.0.0',
            'timeout' => 5,
            'filesDir' => 'communicator_server\\files',
            'filesAreNameLocked' => true,
            'disabledTypes' => [],
            'stopWord' => ""
        ];

        foreach($defaultSettings as $defaultName => $defaultValue){
            settings::set($defaultName, $defaultValue, false);
        }

        global $arguments;

        if(is_string($arguments['command']) && strpos($arguments['command'], 'communicator_server::socketServer()') !== false){
            return;
        }

        if(settings::read('autostart')){
            $settings = self::getSettings();
            if(!network::ping($settings['ip'], $settings['port'], 1)){
                cmd::newWindow('php\php cli.php command "timetest communicator_server::socketServer();" no-loop true');
            }
        }
    }

    public static function socketServer(int|false $port=false, string|false $ip=false, int|false $timeout=false):void{
        $settings = self::getSettings();
        $port = network::isValidPort($port) ? $port : $settings['port'];
        $ip = network::isValidIp($ip) ? $ip : $settings['ip'];
        $timeout = network::isValidTimeout($timeout) ? $timeout : $settings['timeout'];

        if(!extension_ensure('sockets')){
            mklog(2, 'Unable to run communicator server without sockets extension enabled');
            return;
        }

        if(network::ping($ip, $port, 1)){
            mklog(2,'Unable to listen on ' . $ip . ':' . $port . ' as it is already in use');
            return;
        }

        $socket = communicator::createServer($ip, $port, false, $socketError, $socketErrorString);
        if(!$socket){
            mklog(2,'Unable to listen on ' . $ip . ':' . $port);
            return;
        }

        mklog(1, 'Starting communicator server');
        echo cli_formatter::formatLine("Listening on $ip:$port", "green");
        exec('title Communicator Server ' . $port);

        self::getCustomActions();
        self::getThingsToDo();

        self::doThings(self::$startups);
        self::$startups = [];

        while(true){
            $clientSocket = communicator::acceptConnection($socket, $timeout);
            if($clientSocket){
                $startTime = time();
                $tempconid = date("Y-m-d H:i:s");

                echo "$tempconid: Received connection";

                $data = communicator::receiveData($clientSocket, true);
                if(!is_array($data) || empty($data)){
                    echo " (empty input)\n";
                    goto close;
                }
                
                echo "\n";

                $data['name'] = communicator::getLastReceivedName();

                $connid = $tempconid . " (" . $data['name'] . ")";

                echo "$connid: Processing data\n";

                $response = self::run($data, $clientSocket);

                if(empty($response)){
                    mklog(2, "Empty result, aborting connection " . $connid);
                    goto close;
                }

                respond:
                if(!communicator::sendData($clientSocket, $response, true)){
                    mklog(2, 'Failed to send response to ' . $connid);
                }

                $timeTaken = round((time() - $startTime), 3);
                echo $connid . ": Closing connection (" . $timeTaken . "s)\n";

                close:

                @communicator::close($clientSocket);

                if(isset($timeTaken) && $timeTaken > 5){
                    if(is_array($data) && isset($data["type"]) && isset($data['payload']) && is_string($data['payload'])){
                        mklog(2, 'The request to run "' . ($data["type"] === "function_string" ? (substr($data['payload'],0,strpos($data['payload'],"(")) . '(...);') : $data['payload']) . '" took longer than 5 seconds to execute');
                    }
                    else{
                        mklog(2, 'The last request took longer than 5 seconds to execute');
                    }
                }
            }

            self::doThings(self::$repeats);
            
            if(self::$exitServer){
                break;
            }
        }

        @communicator::close($socket);

        self::doThings(self::$shutdowns);
        self::$repeats = [];
        self::$shutdowns = [];

        mklog(1, 'Communicator server stopped');
    }
    public static function getSettings():array{
        $ip = settings::read('ip');
        if(!is_string($ip) || !preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?:\.|$)){4}$/', $ip)){
            $ip = "127.0.0.1";
        }

        $port = settings::read('port');
        if(!is_int($port) || $port < 1 || $port > 65535){
            $port = 8080;
        }

        $timeout = settings::read('timeout');
        if(!is_int($timeout) || $timeout < 1 ){
            $timeout = 5;
        }

        return [
            'ip' => $ip,
            'port' => $port,
            'timeout' => $timeout
        ];
    }

    public static function run(array $data, $clientSocket=null):mixed{
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        if(!isset($backtrace[1]['class']) || !in_array($backtrace[1]['class'], ["communicator_server", "communicator_client"])){
            return [];
        }

        $response = ["success"=>false, "result"=>null];

        if(!isset($data['type'])){
            $response["error"] = "Type not set";
            return $response;
        }

        $disabledTypes = settings::read('disabledTypes');
        if(!is_array($disabledTypes)){
            mklog(2, "Failed to read blocked types");
            $response['error'] = "Internal server error";
            return $response;
        }
        if(in_array($data['type'], $disabledTypes)){
            $response['error'] = $data['type'] . " is disabled on this server";
            return $response;
        }

        if(!isset($data['version'])){
            $data['version'] = 1;
        }

        if($data['version'] < 4){
            echo "communicator_client outdated\n";
        }

        if(!isset($data['payload'])){
            $response["error"] = "Payload not set";
            return $response;
        }

        if($data["type"] === "stop"){
            $stopWord = settings::read('stopWord');
            if(!is_string($stopWord)){
                $response['error'] = "Internal server error";
                return $response;
            }

            if(!empty($stopWord)){
                if($data['payload'] !== $stopWord){
                    $response['error'] = "Stop word does not match";
                    return $response;
                }
            }

            self::$exitServer = true;
            $response["success"] = true;
            return $response;
        }
        elseif($data["type"] === "custom" && $data['version'] > 3){
            if(!is_array($data['payload']) || array_is_list($data['payload'])){
                $response["error"] = "Invalid payload";
                goto respond;
            }

            foreach(['package','action'] as $thing){
                if(!isset($data['payload'][$thing]) || !is_string($data['payload'][$thing])){
                    $response["error"] = "Invalid " . $thing;
                    goto respond;
                }
            }

            $actionPackage = $data['payload']['package'];
            $actionName = $data['payload']['action'];

            if(!isset(self::$customActions[$actionPackage])){
                $response["error"] = "The package " . $actionPackage . " doesnt have any actions";
                goto respond;
            }

            if(!isset(self::$customActions[$actionPackage][$actionName])){
                $response["error"] = "The package " . $actionPackage . " doesnt have an action called " . $actionName;
                goto respond;
            }

            $action = self::$customActions[$actionPackage][$actionName];

            $function = $action['function'] . "(";

            if(isset($action['args'])){
                
                if(!isset($data['payload']['args']) || !is_array($data['payload']['args']) || !array_is_list($data['payload']['args'])){
                    $response['error'] = "That action reqires arguments";
                    goto respond;
                }
                foreach($action['args'] as $arg){
                    if(preg_match('/^--\d+$/', $arg)){
                        $index = intval(substr($arg, 2));
                        if(!isset($data['payload']['args'][$index])){
                            if(isset($action['defArgs']) && array_key_exists($index, $action['defArgs'])){
                                $data['payload']['args'][$index] = $action['defArgs'][$index];
                            }
                            else{
                                $response['error'] = "The action " . $actionName . " requires argument " . $index . " (0 indexed)";
                                goto respond;
                            }
                        }
                        $value = $data['payload']['args'][$index];
                    }
                    else{
                        $value = $arg;
                    }

                    $function .= "unserialize(base64_decode('" . base64_encode(serialize($value)) . "')),";
                }
                if(!empty($action['args'])){
                    $function = substr($function, 0, -1);
                }
            }

            $function .= ")";

            $response["success"] = self::tryToRunCode($function, $response["result"]);
            if(!$response["success"]){
                $response["error"] = "Failed to run function";
            }
        }
        elseif($data["type"] === "command"){
            if(!is_string($data['payload'])){
                $response['error'] = "payload is not a string";
                goto respond;
            }

            $response["success"] = cli::run($data['payload'], false);
        }
        elseif($data["type"] === "command_output" && $data['version'] > 1){
            if(!is_string($data['payload'])){
                $response['error'] = "payload is not a string";
                goto respond;
            }

            $response["success"] = true;
            $response["result"] = cli::run($data['payload'], true);
        }
        elseif($data["type"] === "function_string"){
            if(!is_string($data['payload'])){
                $response['error'] = "payload is not a string";
                goto respond;
            }

            $response["success"] = self::tryToRunCode($data['payload'], $response["result"]);
        }
        elseif(($data["type"] === "fileup" || $data["type"] === "filedown") && $data['version'] > 2){
            if(!is_resource($clientSocket)){
                $response["success"] = false;
                $response["error"] = "Cannot do file transfers without socket information";
                goto respond;
            }

            if(!is_array($data['payload'])|| !isset($data['payload']['name'])){
                $response["success"] = false;
                $response["error"] = "Missing file name";
                goto respond;
            }

            if(!is_string($data['payload']['name']) || !preg_match('/^(?!\.)[a-zA-Z0-9.]+(?<!\.)$/', $data['payload']['name'])){
                $response['success'] = false;
                $response['error'] = "Invalid file name";
                goto respond;
            }

            $data['payload']['name'] = strtolower($data['payload']['name']);

            $path = settings::read('filesDir');
            if(!is_string($path)){
                mklog(2, "Failed to read filesDir setting");
                $response['success'] = false;
                $response['error'] = "Internal error";
                goto respond;
            }

            if(settings::read('filesAreNameLocked')){
                $path .= '\\' . $data['name'];
            }

            $path .= '\\' . $data['payload']['name'];

            if($data["type"] === "fileup"){
                if(is_file($path)){
                    if(!isset($data['payload']['overwrite']) || !$data['payload']['overwrite']){
                        $response['success'] = false;
                        $response['error'] = "The file " . $data['payload']['name'] . " already exists.";
                        goto respond;
                    }
                }

                echo "Receiving file " . $data['payload']['name'] . " from " . $data['name'] . "\n";
                if(!communicator::receiveFile($clientSocket, $path)){
                    $response['success'] = false;
                    $response['error'] = "Unable to receive file data.";
                    goto respond;
                }
            }
            else{//filedown
                if(!is_file($path)){
                    $response['success'] = false;
                    $response['error'] = "The file " . $data['payload']['name'] . " does not exist.";
                    goto respond;
                }

                echo "Sending file " . $data['payload']['name'] . " to " . $data['name'] . "\n";
                if(!communicator::sendFromFile($clientSocket, $path)){
                    $response['success'] = false;
                    $response['error'] = "Unable to send file data.";
                    goto respond;
                }
            }

            $response['success'] = true;
            $response['result'] = $path;
        }
        else{
            $response["error"] = "Unknown type";
        }

        respond:

        if($data['version'] < 2){
            if($response['result'] !== null){
                $response = $response['result'];
            }
            else{
                $response = $response['success'];
            }
        }

        return $response;
    }

    private static function getCustomActions():void{
        $packages = pkgmgr::getLoadedPackages();
        foreach($packages as $packageId => $packageVersion){
            if(method_exists($packageId, "communicatorServerActions")){
                $actions = $packageId::communicatorServerActions();
                if(!is_array($actions)){
                    continue;    
                }

                foreach($actions as $actionName => $action){
                    if(!is_string($actionName) || empty($actionName) || !is_array($action)){
                        continue;
                    }

                    if(!isset($action['function']) || !is_string($action['function'])){
                        continue;
                    }

                    if(isset($action['args'])){
                        if(!is_array($action['args']) || !array_is_list($action['args'])){
                            continue;
                        }

                        if(isset($action['defArgs']) && !is_array($action['defArgs'])){
                            continue;
                        }
                    }

                    self::$customActions[$packageId][$actionName] = $action;
                }

                if(isset(self::$customActions[$packageId])){
                    mklog(1, $packageId . " registered " . count(self::$customActions[$packageId]) . " custom actions");
                }
            }
        }
    }
    private static function getThingsToDo():void{
        $packages = pkgmgr::getLoadedPackages();
        foreach($packages as $packageId => $packageVersion){
            if(method_exists($packageId, "communicatorServerThingsToDo")){
                $newThings = $packageId::communicatorServerThingsToDo();
                if(!is_array($newThings) || !array_is_list($newThings)){
                    continue;    
                }

                foreach($newThings as $newThing){
                    if(!is_array($newThing)){
                        continue;
                    }
                    if(!isset($newThing['type']) || !is_string($newThing['type'])){
                        continue;
                    }

                    if($newThing['type'] === "startup"){
                        if(data_types::validateData($newThing, ['function'=>'string'])){
                            mklog(1, $packageId . ' created a startup action');
                            self::$startups[] = $newThing;
                        }
                    }
                    if($newThing['type'] === "repeat"){
                        if(data_types::validateData($newThing, ['function'=>'string','interval'=>'integer'])){
                            mklog(1, $packageId . ' created a repeating action');
                            $newThing['lastRunTime'] = time();
                            self::$repeats[] = $newThing;
                        }
                    }
                    if($newThing['type'] === "shutdown"){
                        if(data_types::validateData($newThing, ['function'=>'string'])){
                            mklog(1, $packageId . ' created a shutdown action');
                            self::$shutdowns[] = $newThing;
                        }
                    }
                }
            }
        }
    }
    private static function tryToRunCode(string $code, &$return):bool{
        try{
            $return = eval('return ' . $code . ';');
        }
        catch(Throwable $throwable){
            mklog(2, "Something went wrong while trying to process: " . substr($code,0,strpos($code,"(")) . "(...); (" . substr($throwable,0,strpos($throwable,"\n")) . ")");
            return false;
        }
        return true;
    }
    private static function doThings(array &$things):bool{
        $return = true;

        foreach($things as &$thing){
            if($thing['type'] === "repeat"){
                if((time() - $thing['lastRunTime']) < $thing['interval']){
                    continue;
                }
            }
            
            if(!self::tryToRunCode($thing['function'], $shutdownThingReturn)){
                $return = false;
            }

            if($thing['type'] === "repeat"){
                $thing['lastRunTime'] = time();
            }
        }

        return $return;
    }
}