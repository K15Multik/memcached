<?php

use PHPUnit\Framework\TestCase;

class NonBlockingTest extends TestCase
{
    protected static $client;
    protected static $host;

    public static function setUpBeforeClass()
    {
        include_once 'MemcachedClient/MemcachedClient.php';
        self::$host = getenv('MEMCACHED_HOST') ?: '127.0.0.1';
        self::$client = new \Cache\MemcachedClient(self::$host);
    }

    public function testNonBlockingApproach()
    {
        self::$client->setValue('test', 'Test data');
        $retval = $this->sendRawCommand("get test");
        $this->assertEquals('"VALUE test 6 11\r\n\"Test data\"\r\nEND\r\n"', $retval);

        self::$client->nbPrepareGetValue('test');
        //    ***** Some time-intense code *****
        sleep(1);
        //    ***** End of time-intense code ***
        $stored = self::$client->retrieveRequestedValue();
        $this->assertEquals('Test data', $stored);
        $this->sendRawCommand("delete test");
    }

    protected function sendRawCommand($cmd)
    {
        return json_encode(shell_exec("echo $cmd | nc -w 1 ".self::$host." 11211"));
    }
}
