<?php

declare(strict_types=1);

namespace QUITests\QUI\Request;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Request\Bundler;

final class BundlerResultTest extends TestCase
{
    public function testBundledResponsesPublishTheResultEventAfterAllCallsFinish(): void
    {
        $PreviousEvents = QUI::$Events;
        $previousRequest = $_REQUEST;
        $Bundler = $this->getMockBuilder(Bundler::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['parseRequest'])
            ->getMock();
        $Bundler->expects(self::exactly(2))->method('parseRequest')->willReturn(['result' => true]);
        $Events = $this->createMock(QUI\Events\Manager::class);
        $Events->expects(self::once())->method('fireEvent')->willReturnCallback(
            static function (string $event, array $arguments): array {
                self::assertSame('ajaxResult', $event);
                self::assertArrayHasKey('first', $arguments[0]);
                self::assertArrayHasKey('second', $arguments[0]);
                $arguments[0]['Jwt'] = 'new-session-token';

                return [];
            }
        );
        QUI::$Events = $Events;
        $_REQUEST = [
            'quiqqerBundle' => [
                ['rid' => 'first'],
                ['rid' => 'second']
            ]
        ];

        try {
            $response = json_decode((string)$Bundler->response(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('new-session-token', $response['Jwt']);
            self::assertSame(['result' => true], $response['first']);
            self::assertSame(['result' => true], $response['second']);
        } finally {
            QUI::$Events = $PreviousEvents;
            $_REQUEST = $previousRequest;
        }
    }
}
