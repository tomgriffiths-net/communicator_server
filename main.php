<?php
class communicator_server{
    public static function socketServer($port = 8080){
        extensions::ensure('sockets');

        if(network::ping('127.0.0.1',8080,1)){
            mklog('warning','Unable to listen on 127.0.0.1:' . $port . ' as it is already in use',false);
            return;
        }

        $socket = communicator::createServer('127.0.0.1',$port,false,$socketError,$socketErrorString);
        if(!$socket){
            mklog('warning','Unable to listen on 127.0.0.1:' . $port,false);
            return;
        }
        echo "Listening on 127.0.0.1:$port\n";
        exec('title Communicator Server ' . $port);

        stream_set_timeout($socket,5);

        while(true){
            $break = false;
            $clientSocket = communicator::acceptConnection($socket,5);
            if($clientSocket){
                $startTime = time::millistamp();
                $tempconid = date("Y-m-d H:i:s");
                echo "$tempconid: Received connection\n";

                $data = communicator::receive($clientSocket);
                $data = json_decode(base64_decode($data),true);
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
                    $response = "EXIT COMMUNICATOR SERVER";
                }
                elseif($data["type"] === "command"){
                    cli_run($data['payload']);
                    $response = "Command run";
                }
                elseif($data["type"] === "function_string"){
                    try{
                        $response = eval('return ' . $data['payload'] . ';');
                    }
                    catch(Throwable $throwable){
                        mklog("warning","communicator_client: Something went wrong while trying to process: " . substr($data['payload'],0,strpos($data['payload'],"(")) . " (" . substr($throwable,0,strpos($throwable,"\n")) . ")");
                    }
                }

                respond:
                $response = base64_encode(json_encode($response));
                communicator::send($clientSocket,$response);

                $timeTaken = (time::millistamp() - $startTime)/1000;
                echo $connid . ": Closing connection (" . $timeTaken . "s)\n";
                communicator::close($clientSocket);

                if($timeTaken > 2){
                    echo "Warning: " . $connid . ": Took longer than 2 seconds to execute: " . $data['payload'] . "\n";
                }
            }
            
            if($break){
                break;
            }
        }
        @communicator::close($socket);
    }
}