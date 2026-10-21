<?php

declare(strict_types=1);

namespace QUITests\QUI;

use PHPUnit\Framework\TestCase;
use QUI\Ajax;
use QUI\Events\Event;
use QUI\Exception;
use QUI\ExceptionStack;
use QUI\Users\Exception as UserException;

final class AjaxHeaderExceptionTest extends TestCase
{
    public function testHeaderEventsPreserveTheOriginalAuthenticationException(): void
    {
        $Original = new UserException('Session expired', 401);
        $Events = new Event();
        $Events->addEvent('onHeaderLoaded', static function () use ($Original): void {
            throw $Original;
        });

        try {
            $Events->fireEvent('headerLoaded');
            self::fail('Expected the header event to reject the request.');
        } catch (ExceptionStack $Stack) {
            $Wrapped = $Stack->getExceptionList()[0];
            self::assertSame($Original, $Wrapped->getPrevious());
            $response = (new Ajax())->getHeaderExceptionResponse($Wrapped, true);
            self::assertNotNull($response);
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(401, $result['Exception']['code']);
            self::assertSame(UserException::class, $result['Exception']['type']);
            self::assertArrayNotHasKey('Jwt', $result);
        }
    }

    public function testUnbundledHeaderErrorsUseTheNormalAjaxEnvelope(): void
    {
        $response = (new Ajax())->getHeaderExceptionResponse(new UserException('Session expired', 401));
        self::assertNotNull($response);
        self::assertStringStartsWith('<quiqqer>', $response);
        self::assertStringEndsWith('</quiqqer>', $response);
        $result = json_decode(substr($response, 9, -10), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(UserException::class, $result['Exception']['type']);
        self::assertArrayNotHasKey('Jwt', $result);
    }

    public function testHeaderErrorsPreserveTheGenericAuthenticationMarker(): void
    {
        $Original = new Exception('Authentication required', 401);
        $Original->setAttribute('authenticationRequired', true);
        $Wrapped = new Exception('Header listener failed', 401, [], $Original);
        $response = (new Ajax())->getHeaderExceptionResponse($Wrapped, true);
        self::assertNotNull($response);
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($result['Exception']['attributes']['authenticationRequired']);
        self::assertSame(Exception::class, $result['Exception']['type']);
        self::assertArrayNotHasKey('Jwt', $result);
    }

    public function testOtherHeaderFailuresKeepTheirExistingErrorHandling(): void
    {
        foreach ([403, 500, 503] as $code) {
            self::assertNull((new Ajax())->getHeaderExceptionResponse(new Exception('Rejected', $code)));
        }
    }

    public function testSessionLossStillRequiresLoginWhenCsrfAlsoRejectsTheRequest(): void
    {
        $Stack = new ExceptionStack();
        $Stack->addException(new Exception('CSRF token not found in session', 0));
        $Stack->addException(new Exception(
            'JWT listener failed',
            401,
            [],
            new UserException('Session expired', 401)
        ));
        $response = (new Ajax())->getHeaderExceptionResponse($Stack, true);
        self::assertNotNull($response);
        $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(UserException::class, $result['Exception']['type']);
        self::assertSame(401, $result['Exception']['code']);
        self::assertArrayNotHasKey('Jwt', $result);
    }

    public function testStacksWithoutAuthenticationErrorsKeepTheirExistingHandler(): void
    {
        $Stack = new ExceptionStack();
        $Stack->addException(new Exception('CSRF rejected', 0));
        $Stack->addException(new Exception('Unavailable', 503));
        self::assertNull((new Ajax())->getHeaderExceptionResponse($Stack));
    }
}
