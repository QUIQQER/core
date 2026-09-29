<?php

namespace QUI\Mail;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;

class QueueRecipientsTest extends TestCase
{
    private const SUBJECT_PREFIX = 'phpunit-mailqueue-recipients-';

    private bool $previousMailSendingDisabled;
    private mixed $previousMaxRetries;
    private TestHandler $LogHandler;

    protected function setUp(): void
    {
        Queue::setup();
        self::cleanup();

        $this->previousMailSendingDisabled = Mailer::$DISABLE_MAIL_SENDING;
        $this->previousMaxRetries = QUI::conf('mail', 'queueMaxRetries');

        Mailer::$DISABLE_MAIL_SENDING = false;
        QUI::getConfig('etc/conf.ini.php')->setValue('mail', 'queueMaxRetries', 0);

        $this->LogHandler = new TestHandler();
        QUI\Log\Logger::getLogger()->pushHandler($this->LogHandler);
    }

    protected function tearDown(): void
    {
        QUI\Log\Logger::getLogger()->popHandler();

        Mailer::$DISABLE_MAIL_SENDING = $this->previousMailSendingDisabled;
        QUI::getConfig('etc/conf.ini.php')->setValue(
            'mail',
            'queueMaxRetries',
            $this->previousMaxRetries
        );

        self::cleanup();
    }

    public static function emptyRecipients(): iterable
    {
        yield 'no recipients' => [false];
        yield 'blank recipients with display names' => [true];
    }

    #[DataProvider('emptyRecipients')]
    public function testRejectsNewMailWithoutRecipientsBeforeInserting(bool $blankAddresses): void
    {
        $Mailer = $this->createMailer();
        $Mailer->addReplyTo('reply@example.invalid');

        if ($blankAddresses) {
            $Mailer->addRecipient('   ', 'To display name');
            $Mailer->addCC('', 'CC display name');
            $Mailer->addBCC("\t", 'BCC display name');
        }

        try {
            Queue::addToQueue($Mailer);
            self::fail('A mail without recipients must not be queued.');
        } catch (QUI\Exception $Exception) {
            self::assertSame(400, $Exception->getCode());
            self::assertStringContainsString('no recipients', $Exception->getMessage());
        }

        $count = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Queue::table()))
            ->where('subject LIKE :subject')
            ->setParameter('subject', self::SUBJECT_PREFIX . '%')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, (int)$count);
    }

    public static function recipientMethods(): iterable
    {
        yield 'To' => ['addRecipient', 'mailto', false];
        yield 'named To' => ['addRecipient', 'mailto', 'Recipient'];
        yield 'CC only' => ['addCC', 'cc', false];
        yield 'named CC only' => ['addCC', 'cc', 'Recipient'];
        yield 'BCC only' => ['addBCC', 'bcc', false];
        yield 'named BCC only' => ['addBCC', 'bcc', 'Recipient'];
    }

    #[DataProvider('recipientMethods')]
    public function testAcceptsEachRecipientType(
        string $method,
        string $field,
        false | string $name
    ): void {
        $Mailer = $this->createMailer();
        $Mailer->$method('recipient@example.invalid', $name);

        $mailId = Queue::addToQueue($Mailer);
        $entry = $this->getEntry($mailId);
        $recipients = json_decode($entry[$field], true, flags: JSON_THROW_ON_ERROR);
        $expectedRecipient = $name === false
            ? 'recipient@example.invalid'
            : ['recipient@example.invalid', $name];

        self::assertSame([$expectedRecipient], $recipients);
        self::assertSame(Queue::STATUS_ADDED, (int)$entry['status']);
    }

    public static function storedEmptyRecipients(): iterable
    {
        yield 'empty lists' => ['[]'];
        yield 'blank addresses and display names' => ['[" ",["", "Display name"]]'];
        yield 'null fields' => [null];
        yield 'JSON null' => ['null'];
        yield 'invalid JSON' => ['invalid-json'];
    }

    #[DataProvider('storedEmptyRecipients')]
    public function testCancelsExistingMailOnceWithoutRetryOrTransport(?string $addresses): void
    {
        $Mailer = $this->createMailer();
        $Mailer->addRecipient('recipient@example.invalid');
        $mailId = Queue::addToQueue($Mailer);

        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(Queue::table()),
            [
                'mailto' => $addresses,
                'cc' => $addresses,
                'bcc' => $addresses,
                'replyto' => '["reply@example.invalid"]',
                'status' => Queue::STATUS_ERROR,
                'retry' => 3,
                'lastsend' => 1234567890,
                'errors' => 'Previous failure'
            ],
            ['id' => $mailId]
        );

        $Queue = $this->createQueueWithoutTransport();

        self::assertFalse($Queue->sendById($mailId));

        $entry = $this->getEntry($mailId);

        self::assertSame(Queue::STATUS_CANCELED, (int)$entry['status']);
        self::assertSame(3, (int)$entry['retry']);
        self::assertSame(1234567890, (int)$entry['lastsend']);
        self::assertStringContainsString('Previous failure', $entry['errors']);
        self::assertStringContainsString('no recipients', $entry['errors']);

        // A later call must leave the canceled row intact without another diagnostic or attempt.
        self::assertTrue($Queue->sendById($mailId));
        self::assertSame($entry, $this->getEntry($mailId));

        $records = array_filter(
            $this->LogHandler->getRecords(),
            static fn ($Record) => ($Record['context']['mailQueueId'] ?? null) === $mailId
        );
        $records = array_values($records);

        self::assertCount(1, $records);
        self::assertSame('missing_recipients', $records[0]['context']['reason']);
    }

    private function createQueueWithoutTransport(): Queue
    {
        return new class extends Queue {
            protected function markAsSendingAndIncreaseRetry(array $params): int
            {
                // An Error escapes the production retry handlers and prevents any actual delivery.
                throw new \Error('Recipient validation must finish before a mail transport is created.');
            }
        };
    }

    private function createMailer(): Mailer
    {
        $Mailer = new Mailer();
        $Mailer->setSubject(self::SUBJECT_PREFIX . uniqid('', true));
        $Mailer->setFrom('sender@example.invalid');
        $Mailer->setBody('Recipient validation fixture');

        return $Mailer;
    }

    private function getEntry(int $mailId): array
    {
        $entry = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select('*')
            ->from(QUI\Utils\Doctrine::quoteIdentifier(Queue::table()))
            ->where('id = :id')
            ->setParameter('id', $mailId)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($entry);

        return $entry;
    }

    private static function cleanup(): void
    {
        QUI::getDataBaseConnection()->createQueryBuilder()
            ->delete(QUI\Utils\Doctrine::quoteIdentifier(Queue::table()))
            ->where('subject LIKE :subject')
            ->setParameter('subject', self::SUBJECT_PREFIX . '%')
            ->executeStatement();
    }
}
