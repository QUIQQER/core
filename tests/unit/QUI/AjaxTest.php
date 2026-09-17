<?php

namespace QUI;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
class AjaxTest extends TestCase
{
    public function testRegisterAndGetFunction(): void
    {
        $testFunction = static function (): void {
        };
        $testFunctionName = 'test_function';

        $sut = new Ajax();
        $sut::registerFunction($testFunctionName, $testFunction);

        $this->assertArrayHasKey($testFunctionName, $sut::getRegisteredCallables());
        $this->assertEquals($testFunction, $sut::getRegisteredCallables()[$testFunctionName]['callable']);
    }

    public function testRegisterAndGetCallable(): void
    {
        $sut = new Ajax();
        $regVars = ['var1', 'var2'];
        $testFunction = 'test_function';

        $sut::register($testFunction, $regVars);

        $this->assertArrayHasKey($testFunction, $sut::getRegisteredFunctions());
        $this->assertEquals($regVars, $sut::getRegisteredFunctions()[$testFunction]);
    }

    public function testWriteExceptionReturnsWellFormedArray(): void
    {
        $message = 'test';
        $code = 123;
        $testException = new \Exception(message: $message, code: $code);

        $sut = (new Ajax())->writeException($testException);

        $this->assertEquals($message, $sut['Exception']['message']);
        $this->assertEquals($code, $sut['Exception']['code']);
        $this->assertEquals($testException::class, $sut['Exception']['type']);
    }

    public function testWriteExceptionRemovesHtmlFromMessage(): void
    {
        $messageToTest = '<iframe></iframe><p>myP</p><div>myDiv</div><h1>myH1</h1>';
        $cleanedMessage = '<p>myP</p><div>myDiv</div>myH1';

        $sut = (new Ajax())->writeException(new \Exception(message: $messageToTest));

        $this->assertEquals($cleanedMessage, $sut['Exception']['message']);
    }

    public function testCallRequestFunctionWithUnregisteredFunctionWritesException(): void
    {
        $ajax = new Ajax();

        $sut = $ajax->callRequestFunction('undefined_function');

        $this->assertArrayHasKey('Exception', $sut);
    }

    public function testCallRequestFunctionWithoutPermissionsWritesException(): void
    {
        $ajax = new Ajax();
        $testFunctionName = 'test_function';

        $ajax::registerFunction($testFunctionName, static function (): void {
        }, false, 'test_permission');

        $sut = $ajax->callRequestFunction($testFunctionName);

        $this->assertArrayHasKey('Exception', $sut);
    }

    public function testCallRequestFunctionWritesFunctionsResult(): void
    {
        $sut = new Ajax();
        $testFunctionName = 'test_function';
        $testParameterValue = 'test_parameter_value';

        $sut::registerFunction(
            $testFunctionName,
            static function ($testParameter) {
                return $testParameter;
            },
            ['testParameter']
        );

        $result = $sut->callRequestFunction($testFunctionName, ['testParameter' => $testParameterValue])['result'];

        $this->assertEquals($testParameterValue, $result);
    }

    public function testCallExecutesDuplicateRequestFunctionOnlyOnce(): void
    {
        $Ajax = new Ajax();
        $functionName = 'test_duplicate_request_function';
        $callCount = 0;
        $Ajax::registerFunction(
            $functionName,
            static function () use (&$callCount): int {
                return ++$callCount;
            }
        );
        $_REQUEST['_rf'] = json_encode(array_fill(0, 1_000, $functionName));

        $Ajax->call();

        self::assertSame(1, $callCount);
    }
}
