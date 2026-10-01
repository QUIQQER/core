<?php

namespace QUITests\QUI\InstallationWizard;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\InstallationWizard\QuiqqerProvider;
use QUI\InstallationWizard\QuiqqerSteps\MailSMTP;

final class MailSMTPTest extends TestCase
{
    #[DataProvider('smtpSettings')]
    public function testWizardPersistsSmtpSettings(?bool $auth, string $secure): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quiqqer-smtp-test-');
        self::assertNotFalse($file);
        file_put_contents($file, ";<?php exit; ?>\n[unrelated]\nvalue = keep\n");
        $Original = QUI::$Conf;

        try {
            QUI::$Conf = new QUI\Config($file);
            $data = [
                'use-smtp' => '1',
                'smtp-server' => 'smtp.example.test',
                'smtp-port' => '587',
                'smtp-user' => 'user@example.test',
                'smtp-password' => 'test-only-password',
                'smtp-secure' => $secure,
                'smtp-secure-verify_peer' => 1,
                'smtp-secure-verify_peer_name' => 0,
                'mail.settings.allow_self_signed' => 0
            ];

            if ($auth !== null) {
                $data['smtp-auth'] = $auth;
            }

            (new QuiqqerProvider())->execute($data);
            $Saved = new QUI\Config($file);
            self::assertSame(1, (int)$Saved->get('mail', 'SMTP'));
            self::assertSame((string)(int)$auth, (string)$Saved->get('mail', 'SMTPAuth'));
            self::assertSame($secure, $Saved->get('mail', 'SMTPSecure'));
            self::assertSame('smtp.example.test', $Saved->get('mail', 'SMTPServer'));
            self::assertSame('user@example.test', $Saved->get('mail', 'SMTPUser'));
            self::assertSame('test-only-password', $Saved->get('mail', 'SMTPPass'));
            self::assertSame(1, (int)$Saved->get('mail', 'SMTPSecureSSL_verify_peer'));
            self::assertSame(0, (int)$Saved->get('mail', 'SMTPSecureSSL_verify_peer_name'));
            self::assertSame(0, (int)$Saved->get('mail', 'SMTPSecureSSL_allow_self_signed'));
            self::assertSame('keep', $Saved->get('unrelated', 'value'));
        } finally {
            QUI::$Conf = $Original;
            unlink($file);
        }
    }

    public static function smtpSettings(): iterable
    {
        yield 'authenticated TLS' => [true, 'tls'];
        yield 'unauthenticated SSL' => [false, 'ssl'];
        yield 'missing checkbox and no encryption' => [null, ''];
    }

    #[DataProvider('authStates')]
    public function testFormRestoresAuthenticationAndEscapesCredentials(int $auth): void
    {
        QUI::getTemplateManager()->getEngine();
        $Original = QUI::$Conf;
        $Config = clone $Original;
        $attack = '"><script>alert(1)</script>';
        $Config->set('mail', 'SMTPAuth', $auth);
        $Config->set('mail', 'SMTPServer', $attack);
        $Config->set('mail', 'SMTPPort', '587');
        $Config->set('mail', 'SMTPUser', $attack);
        $Config->set('mail', 'SMTPPass', $attack);

        try {
            QUI::$Conf = $Config;
            $html = (new MailSMTP())->create();
        } finally {
            QUI::$Conf = $Original;
        }

        $Document = new DOMDocument();
        $Document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $XPath = new DOMXPath($Document);
        $Checkbox = $XPath->query('//input[@name="smtp-auth"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $Checkbox);
        self::assertSame('checkbox', $Checkbox->getAttribute('type'));
        self::assertSame((bool)$auth, $Checkbox->hasAttribute('checked'));
        self::assertSame('label', $Checkbox->parentNode->nodeName);
        self::assertSame(0, $Document->getElementsByTagName('script')->length);

        foreach (['smtp-server', 'smtp-user', 'smtp-password'] as $name) {
            $Input = $XPath->query('//input[@name="' . $name . '"]')->item(0);
            self::assertInstanceOf(DOMElement::class, $Input);
            self::assertSame($attack, $Input->getAttribute('value'));
        }
    }

    public static function authStates(): iterable
    {
        yield 'enabled' => [1];
        yield 'disabled' => [0];
    }
}
