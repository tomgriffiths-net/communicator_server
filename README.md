# communicator_server
This is a package for PHP-CLI, the communicator_client package should be used to connect to the communicator server.

# Action types
- **stop**: Stops communicator server, result is STOP on success.
- **command**: Runs a command on the server, payload is the command string, success is true if the command ran without errors.
- **command_output**: Requires protocol 2 or higher, payload is the command string, result is the text outputted from running the command.
- **function_string**: Runs some code, payload is the code to be run, success is true if the function ran without any warnings or errors, result is the return of the function.
- **fileup**: Uploads a file to the server, payload is an array containing the name of the file and an overwrite boolean, requires communicators sendFromFile, the result is the path the server saved the file to.
- **filedown**: Downloads a file from the server, payload is an array containing the name of the file, requires communicators receiveFile, the result is the path where the server read the file from.

# Functions
- **socketServer(int|false $port=false, string|false $ip=false, int|false $timeout=false):void**: Starts the server with specified overrides.
- **getSettings():array**: Reads the communicator server settings.