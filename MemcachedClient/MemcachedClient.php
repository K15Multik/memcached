<?php

namespace Cache;

class MemcachedClient
{
    const VAR_TYPE_INT = 1;
    const VAR_TYPE_BOOL = 2;
    const VAR_TYPE_FLOAT = 3;
    const VAR_TYPE_ARRAY = 4;
    const VAR_TYPE_OBJECT = 5;
    const VAR_TYPE_STRING = 6;
    const VAR_TYPE_NULL = 7;

    protected $host;
    protected $port;
    protected $socket;
    protected $connection;
    protected $key;

    public function __construct($host = '127.0.0.1', $port = 11211)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function __destruct()
    {
        if ($this->socket) {
            socket_close($this->socket);
        }
    }

    /**
     * Store value into cache
     * @param string $key Key name
     * @param mix $value Stored value
     * @param int $lifetime Cached variable life time in seconds
     * @return boolean Caching status . True if success false otherwise
     * @throws \Exception Socket operations errors
     */
    public function setValue($key, $value, $lifetime = 3600)
    {
        if (is_null($this->connection) || $this->connection === false) {
            $this->createConnection();
        }
        $type = $this->getVarType($value);
        $data = $this->prepareData($value);
        $len = strlen($data);
        $cmd = sprintf("set %s %d %d %d\r\n%s\r\n", $key, $type, $lifetime, $len, $data);

        socket_clear_error($this->socket);
        if (($n = socket_write($this->socket, $cmd)) === false) {
            $errCode = socket_last_error($this->socket);
            throw new \Exception("Socket write ERROR: ".socket_strerror($errCode), $errCode);
        }
        if (($response = socket_read($this->socket, 1024)) == false) {
            $errCode = socket_last_error();
            throw new \Exception("Socket read response ERROR: ".socket_strerror($errCode), $errCode);
        }
        $response = trim($response);
        if (strtoupper($response) == 'STORED') {
            return true;
        }
        return false;
    }

    /**
     * Retrieve single variable or array of variables
     * @param string $key Variable name or several names separated by space
     * @return mix|mix[] Variable value or array of values keyed by variables names. Null if key not exists
     * @throws \Exception Socket operations errors
     * @example
     *      $instance->get('var_name')//Retrieve single variable
     *      $instance->get('var_1 var_2 var_3')//Retrieve several variables
     */
    public function getValue($key)
    {
        if (empty($key) || !is_string($key)) {
            return false;
        }

        $this->nbPrepareGetValue($key);
        $this->key = $key;
        return $this->retrieveRequestedValue();
    }

    public function delValue($key)
    {
        if (empty($key) || !is_string($key)) {
            return false;
        }

        if (is_null($this->connection) || $this->connection === false) {
            $this->createConnection();
        }

        $cmd = sprintf("delete %s\r\n", $key);
        if (($n = socket_write($this->socket, $cmd)) === false) {
            $errCode = socket_last_error();
            throw new \Exception("Socket write ERROR: ".socket_strerror($errCode), $errCode);
        }

        if (($response = socket_read($this->socket, 1024)) == false) {
            $errCode = socket_last_error();
            throw new \Exception("Socket read response ERROR: ".socket_strerror($errCode), $errCode);
        }
        $response = trim($response);

        if ($response == 'DELETED') {
            return true;
        }
        return false;
    }

    public function nbPrepareGetValue($key)
    {
        if (empty($key) || !is_string($key)) {
            return false;
        }
        $this->key = $key;

        if (is_null($this->connection) || $this->connection === false) {
            $this->createConnection();
        }

        $cmd = sprintf("get %s\r\n", $key);
        if (($n = socket_write($this->socket, $cmd)) === false) {
            $errCode = socket_last_error($this->socket);
            throw new \Exception("Socket write ERROR: ".socket_strerror($errCode), $errCode);
        }
        return true;
    }

    public function retrieveRequestedValue()
    {
        $payload = [];
        $MAX_BUFF_LEN = 4096;
        while (true) {
            if (($response = socket_read($this->socket, $MAX_BUFF_LEN)) == false) {
                $errCode = socket_last_error($this->socket);
                throw new \Exception("Socket read response ERROR: ".socket_strerror($errCode), $errCode);
            }
            $response .= trim($response);

            if (strlen($response) >= $MAX_BUFF_LEN) {
                continue;
            }

            $lines = $this->explodeAndSanitize("\n", $response);

            foreach ($lines as $line) {
                if (in_array($line, ['END', 'ERROR'])) {
                    break 2;
                }
                $payload[] = $line;
            }
        }
        if ($payload) {
            $vars = $this->extractVariables($payload);
            return array_key_exists($this->key, $vars) ? $vars[$this->key] : $vars;
        }
        return null;
    }

    protected function createConnection()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            $errCode = socket_last_error();
            throw new \Exception("Socket creation ERROR: ".socket_strerror($errCode), $errCode);
        }

        $address = gethostbyname($this->host);
        $result = socket_connect($this->socket, $address, $this->port);
        if ($result === false) {
            $errCode = socket_last_error();
            throw new \Exception("Socket connection ERROR: ".socket_strerror($errCode), $errCode);
        }
        $this->connection = $result;
    }

    protected function getVarType($var)
    {
        if (is_int($var)) {
            return self::VAR_TYPE_INT;
        } elseif (is_float($var)) {
            return self::VAR_TYPE_FLOAT;
        } elseif (is_bool($var)) {
            return self::VAR_TYPE_BOOL;
        } elseif (is_array($var)) {
            return self::VAR_TYPE_ARRAY;
        } elseif (is_object($var)) {
            return self::VAR_TYPE_OBJECT;
        } elseif (is_null($var)) {
            return self::VAR_TYPE_NULL;
        } else {
            return self::VAR_TYPE_STRING;
        }
    }

    protected function prepareData($val)
    {
        //Strings encoded for multiline scenario
        if (is_array($val) || is_object($val) || is_string($val) || is_null($val)) {
            return json_encode($val);
        }
        return strval($val);
    }

    protected function decodeData($data, $dataType)
    {
        switch ($dataType) {
            case self::VAR_TYPE_STRING:
            case self::VAR_TYPE_OBJECT:
                return json_decode($data);
            case self::VAR_TYPE_ARRAY:
                return json_decode($data, true);
            case self::VAR_TYPE_BOOL:
                return (bool)$data;
            case self::VAR_TYPE_INT:
                return (int)$data;
            case self::VAR_TYPE_FLOAT:
                return (float)$data;
            case self::VAR_TYPE_NULL:
                return null;
            default:
                return $data;
        }
    }

    protected function explodeAndSanitize($delim, $text)
    {
        $retval = explode($delim, $text);
        $retval = array_map(function ($item) {
            return trim($item);
        }, $retval);
        return $retval;
    }

    protected function extractVariables($responseLines)
    {
        $retval = [];
        $total = count($responseLines)/2;
        for ($i=0; $i < $total; $i++) {
            $slice = array_slice($responseLines, $i*2, 2);
            $varDescription = $this->explodeAndSanitize(' ', $slice[0]);

            if ($varDescription[0] != 'VALUE') {
                continue;
            }
            $varName = $varDescription[1];
            $varType = $varDescription[2];
            $value = $this->decodeData($slice[1], $varType);
            $retval[$varName] = $value;
        }
        return $retval;
    }
}
