<?php

use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase
{
    protected static $client;
    protected static $host;

    public static function setUpBeforeClass()
    {
        include_once 'MemcachedClient/MemcachedClient.php';
        self::$host = getenv('MEMCACHED_HOST') ?: '127.0.0.1';
        self::$client = new \Cache\MemcachedClient(self::$host);
    }
    
    public function testNonexistValue()
    {
        $retval = $this->sendRawCommand("get demo");
        $this->assertEquals('"END\r\n"', $retval, 'Expect that variable not exists');
        $demoValue = self::$client->getValue('demo');
        $this->assertNull($demoValue);
    }

    /**
     * @depends testNonexistValue
     */
    public function testStoreValue()
    {
        self::$client->setValue('demo', 'Demo');
        $retval = $this->sendRawCommand("get demo");
        $this->assertEquals('"VALUE demo 6 6\r\n\"Demo\"\r\nEND\r\n"', $retval);
    }

    /**
     * @depends testStoreValue
     */
    public function testRetrieveValue()
    {
        $demo = self::$client->getValue('demo');
        $this->assertEquals($demo, 'Demo');
    }

    /**
     * @depends testRetrieveValue
     */
    public function testDeleteValue()
    {
        self::$client->delValue('demo');
        $retval = $this->sendRawCommand("get demo");
        $this->assertEquals('"END\r\n"', $retval);
    }

    /**
     * @depends testDeleteValue
     */
    public function testNullValue()
    {
        $nullVar = null;
        $response = $this->setValueAndGetRawStored('null', $nullVar);;
        $this->assertEquals($response, '"VALUE null 7 4\r\nnull\r\nEND\r\n"');
        $stored = self::$client->getValue('null');
        $this->assertNull($stored);
        self::$client->delValue('null');
    }

    /**
     * @depends testDeleteValue
     * @dataProvider intData
     */
    public function testIntValue($value, $expect)
    {
        $response = $this->setValueAndGetRawStored('int', $value);
        $this->assertEquals($expect, $response);
        $stored = self::$client->getValue('int');

        $this->assertInternalType('int', $stored);
        $this->assertEquals($value, $stored);

        self::$client->delValue('int');
    }

    public function intData()
    {
        return [
            [123, '"VALUE int 1 3\r\n123\r\nEND\r\n"'],
            [0, '"VALUE int 1 1\r\n0\r\nEND\r\n"'],
            [-123, '"VALUE int 1 4\r\n-123\r\nEND\r\n"'],
        ];
    }

    /**
     * @depends testDeleteValue
     * @dataProvider boolData
     */
    public function testBoolValue($value, $expect)
    {
        $response = $this->setValueAndGetRawStored('bool', $value);
        $this->assertEquals($expect, $response);
        $stored = self::$client->getValue('bool');

        $this->assertInternalType('bool', $stored);
        $this->assertEquals($value, $stored);

        self::$client->delValue('bool');
    }

    public function boolData()
    {
        return [
            [false, '"VALUE bool 2 0\r\n\r\nEND\r\n"'],
            [true, '"VALUE bool 2 1\r\n1\r\nEND\r\n"'],
        ];
    }

    /**
     * @depends testDeleteValue
     * @dataProvider strData
     */
    public function testStringValue($value, $expect)
    {
        $response = $this->setValueAndGetRawStored('str', $value);
        $this->assertEquals($expect, $response);
        $stored = self::$client->getValue('str');

        $this->assertInternalType('string', $stored);
        $this->assertEquals($value, $stored);

        self::$client->delValue('str');
    }

    public function strData()
    {
        return [
            ['Hello ALL', '"VALUE str 6 11\r\n\"Hello ALL\"\r\nEND\r\n"'],
            ['', '"VALUE str 6 2\r\n\"\"\r\nEND\r\n"'],
            ["Multi line\nOther line", '"VALUE str 6 24\r\n\"Multi line\\\\nOther line\"\r\nEND\r\n"'],
            ["Line 1\nEND\nLine 2", '"VALUE str 6 21\r\n\"Line 1\\\\nEND\\\\nLine 2\"\r\nEND\r\n"'],
        ];
    }

    /**
     * @depends testDeleteValue
     * @dataProvider floatData
     */
    public function testFloatValue($value, $expect)
    {
        $response = $this->setValueAndGetRawStored('float', $value);
        $this->assertEquals($expect, $response);
        $stored = self::$client->getValue('float');

        $this->assertInternalType('float', $stored);
        $this->assertEquals($value, $stored);

        self::$client->delValue('float');
    }

    public function floatData()
    {
        return [
            [123.456, '"VALUE float 3 7\r\n123.456\r\nEND\r\n"'],
            [0.0, '"VALUE float 3 1\r\n0\r\nEND\r\n"'],
            [-123.456, '"VALUE float 3 8\r\n-123.456\r\nEND\r\n"'],
        ];
    }

    /**
     * @depends testDeleteValue
     * @dataProvider arrayData
     */
    public function testArrayValue($value, $expect)
    {
        $response = $this->setValueAndGetRawStored('array', $value);
        $this->assertEquals($expect, $response);
        $stored = self::$client->getValue('array');

        $this->assertInternalType('array', $stored);
        $this->assertEquals($value, $stored);

        self::$client->delValue('array');
    }

    public function arrayData()
    {
        return [
            [['a', 'b', 'c'], '"VALUE array 4 13\r\n[\\"a\\",\\"b\\",\\"c\\"]\r\nEND\r\n"'],
            [[3, 9, 1, 2, 5, 100500], '"VALUE array 4 18\r\n[3,9,1,2,5,100500]\r\nEND\r\n"'],
            [[3 => 'a', 'b', 'k1' => 'c'], '"VALUE array 4 26\r\n{\\"3\\":\\"a\\",\\"4\\":\\"b\\",\\"k1\\":\\"c\\"}\r\nEND\r\n"'],
            [[], '"VALUE array 4 2\r\n[]\r\nEND\r\n"'],
        ];
    }

    /**
     * @depends testDeleteValue
     * @dataProvider objectData
     */
    public function testObjectValue($value, $expect)
    {
        $response = $this->setValueAndGetRawStored('object', $value);
        $this->assertEquals($expect, $response);
        $stored = self::$client->getValue('object');

        $this->assertInternalType('object', $stored);
        $this->assertEquals($value, $stored);

        self::$client->delValue('object');
    }

    public function objectData()
    {
        return [
            [(object)['a'=> 'OK', 'b'=>2, 'c'=>'Fine'], '"VALUE object 5 27\r\n{\"a\":\"OK\",\"b\":2,\"c\":\"Fine\"}\r\nEND\r\n"'],
            [(object)[], '"VALUE object 5 2\r\n{}\r\nEND\r\n"'],
        ];
    }

    /**
     * @depends testDeleteValue
     */
    public function testRetrieveMultiple()
    {
        self::$client->setValue('message', 'Hello, World ;)');
        self::$client->setValue('answer', 42);
        self::$client->setValue('position', 'Hero');

        $stored = self::$client->getValue('message answer position');
        $this->assertEquals(['message'=>'Hello, World ;)', 'answer'=>42, 'position'=>'Hero'], $stored);

        self::$client->delValue('message');
        self::$client->delValue('answer');
        self::$client->delValue('position');
    }

    protected function sendRawCommand($cmd)
    {
        return json_encode(shell_exec("echo $cmd | nc -w 1 ".self::$host." 11211"));
    }

    protected function setValueAndGetRawStored($key, $value)
    {
        self::$client->setValue($key, $value);
        return $this->sendRawCommand("get $key");
    }
}
