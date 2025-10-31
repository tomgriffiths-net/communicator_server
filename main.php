<?php
class communicator_server{
    private static $startups = [];
    private static $repeats = [];
    private static $shutdowns = [];
    
    public static function init():void{
        $defaultSettings = [
            'autostart' => true,
            'port' => 8080,
            'ip' => '127.0.0.1',
            'timeout' => 5
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

        self::getThingsToDo();

        self::doThings(self::$startups);
        self::$startups = [];

        while(true){
            $break = false;
            $clientSocket = communicator::acceptConnection($socket, $timeout);
            if($clientSocket){
                $startTime = time();
                $tempconid = date("Y-m-d H:i:s");

                echo "$tempconid: Received connection";

                $data = communicator::receive($clientSocket);
                if(!is_string($data) || empty($data)){
                    echo " (ping)\n";
                    goto close;
                }
                $data = base64_decode($data);
                if(!is_string($data) || empty($data)){
                    echo " (corrupt)\n";
                    goto close;
                }
                $data = json_decode($data, true);
                if(!is_array($data) || empty($data)){
                    echo " (corrupt values)\n";
                    goto close;
                }
                $response = false;
                echo "\n";

                $required = array("type","payload","name","password");
                foreach($required as $require){
                    if(!isset($data[$require])){
                        $response = ucfirst($require) . " not present";
                        echo "$tempconid: Missing data\n";
                        goto close;
                    }
                }

                $connid = $tempconid . " (" . $data['name'] . ")";

                if(!communicator::verifyPassword($data['password'])){
                    $response = false;
                    echo "$connid: Incorrect passowrd submitted\n";
                    goto respond;
                }

                echo "$connid: Processing data\n";

                if($data["type"] === "stop"){
                    $break = true;
                    $response = true;
                }
                elseif($data["type"] === "command"){
                    $response = cli::run($data['payload']);
                }
                elseif($data["type"] === "function_string"){
                    self::tryToRunCode($data['payload'], $response);
                }

                respond:
                $response = base64_encode(json_encode($response));
                if(!communicator::send($clientSocket,$response)){
                    mklog(2, 'Failed to send response to ' . $connid);
                }

                $timeTaken = round((time() - $startTime), 3);
                echo $connid . ": Closing connection (" . $timeTaken . "s)\n";

                close:

                @communicator::close($clientSocket);

                if(isset($timeTaken) && $timeTaken > 5){
                    mklog(2, 'The request to run "' . ($data["type"] === "function_string" ? (substr($data['payload'],0,strpos($data['payload'],"(")) . '(...);') : $data['payload']) . '" took longer than 5 seconds to execute');
                }
            }

            self::doThings(self::$repeats);
            
            if($break){
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

    //This function should be present in any package that wants to use communicator to run automated tasks
    /*public static function communicatorServerThingsToDo():array{
        return [
            [
                "type" => "startup",
                "function" => 'communicator_server::test1()'
            ],
            [
                "type" => "repeat",
                "interval" => 10,
                "function" => 'communicator_server::test2()'
            ],
            [
                "type" => "shutdown",
                "function" => 'communicator_server::test3()'
            ],
        ];
    }
    //Example functions
    public static function test1(){
        echo "IM STARTING\n";
    }
    public static function test2(){
        echo "HI THERE\n";
    }
    public static function test3(){
        echo "IM ENDING\n";
    }*/
}