<?php
class communicator_server{
    public static function socketServer(int $port=8080, string $ip="127.0.0.1"){
        extensions::ensure('sockets');

        if(network::ping($ip, $port, 1)){
            mklog(2,'Unable to listen on ' . $ip . ':' . $port . ' as it is already in use');
            return;
        }

        $socket = communicator::createServer($ip, $port, 10, $socketError, $socketErrorString);
        if(!$socket){
            mklog(2,'Unable to listen on ' . $ip . ':' . $port);
            return;
        }
        echo "Listening on $ip:$port\n";
        exec('title Communicator Server ' . $port);

        while(true){
            $break = false;
            $clientSocket = communicator::acceptConnection($socket, 10);
            if($clientSocket){
                $startTime = time();
                $tempconid = date("Y-m-d H:i:s");
                echo "$tempconid: Received connection\n";

                $data = communicator::receive($clientSocket);
                $data = json_decode(base64_decode($data), true);
                $response = false;

                $required = array("type","payload","name","password");
                foreach($required as $require){
                    if(!isset($data[$require])){
                        $response = ucfirst($require) . " not present";
                        echo "$tempconid: Missing data\n";
                        goto respond;
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
                    try{
                        $response = eval('return ' . $data['payload'] . ';');
                    }
                    catch(Throwable $throwable){
                        mklog(2, "Something went wrong while trying to process: " . substr($data['payload'],0,strpos($data['payload'],"(")) . "(...); (" . substr($throwable,0,strpos($throwable,"\n")) . ")");
                    }
                }

                respond:
                $response = base64_encode(json_encode($response));
                if(!communicator::send($clientSocket,$response)){
                    mklog(2, 'Failed to send response to ' . $connid);
                }

                $timeTaken = round((time() - $startTime), 3);
                echo $connid . ": Closing connection (" . $timeTaken . "s)\n";
                @communicator::close($clientSocket);

                if($timeTaken > 1){
                    mklog(2, 'The payload ' . ($data["type"] === "function_string" ? (substr($data['payload'],0,strpos($data['payload'],"(")) . '(...);') : $data['payload']) . ' took longer than 1 second to execute');
                }
            }
            
            if($break){
                break;
            }
        }
        @communicator::close($socket);
    }
}