# communicator_server
This is a package for PHP-CLI, the communicator_client package should be used to connect to the communicator server, see https://www.tomgriffiths.net/php-cli/package-docs/communicator_server.html for function defenitions.

# Action types
- **stop**: Stops communicator server, result is STOP on success.
- **custom**: Runs a custom action that a package has made, requires package, action, and optionally an args list in the payload, result and success are the same as function_string.
- **command**: Runs a command on the server, payload is the command string, success is true if the command ran without errors.
- **command_output**: Requires protocol 2 or higher, payload is the command string, result is the text outputted from running the command.
- **function_string**: Runs some code, payload is the code to be run, success is true if the function ran without any errors, result is the return of the function.
- **fileup**: Uploads a file to the server, payload is an array containing the name of the file and an overwrite boolean, requires communicators sendFromFile, the result is the path the server saved the file to.
- **filedown**: Downloads a file from the server, payload is an array containing the name of the file, requires communicators receiveFile, the result is the path where the server read the file from.

# Settings
- **autostart**: Boolean, weather to start communicator automatically at cli startup.
- **port**: Integer, the port number the server runs on.
- **ip**: String, the ip address the server listens on.
- **timeout**: Integer, the number of seconds to wait for an incoming connection before skipping to the repeat actions.
- **filesDir**: String, the folder to store relative files.
- **filesAreNameLocked**: Boolean, weather to lock files to client names, only works on relative paths.
- **allowAbsolutePaths**: Boolean, weather to allow file paths starting with a drive letter, allows clients to read and write to any file path the communicator server has access to, make sure you have a secure password before enabling this if needed.
- **disabledTypes**: Array, a list of disabled actions.
- **stopWord**: String, a sort of second password required to send the stop command to the server, empty for no required stop word.

# Things and Actions
This function should be present in any package that wants to have custom actions in the communicator_server.
- Items in args that are in --x format where x is the index of an element in the args array in the payload are replaced with the value.
- The defArgs are what can be substituted if the payload args does not contain a specific index, here index 1 which corresponds to arg 3, can be omitted or included whereas arg 1 which is payload args 0 must be provided as there is no defArg for 0.

```php
public static function communicatorServerActions():array{
    return [
        "custom-action-name" => [
            "function" => "mypackage::test4",
            "args" => [
                "--0",
                "fixed value",
                "--1"
            ],
            "defArgs" => [
                1 => null
            ]
        ]
    ];
}
```

This function should be present in any package that wants to use communicator_server to run automated tasks.
```php
public static function communicatorServerThingsToDo():array{
    return [
        [
            "type" => "startup",
            "function" => 'mypackage::test1()'
        ],
        [
            "type" => "repeat",
            "interval" => 10,
            "function" => 'mypackage::test2()'
        ],
        [
            "type" => "shutdown",
            "function" => 'mypackage::test3()'
        ],
    ];
}
```

Example functions for mypackage.
```php
public static function test1(){
    echo "IM STARTING\n";
}
public static function test2(){
    echo "HI THERE\n";
}
public static function test3(){
    echo "IM ENDING\n";
}
public static function test4($param1, $param2, $param3){
    echo $param1 . $param2 . $param3;
}
```