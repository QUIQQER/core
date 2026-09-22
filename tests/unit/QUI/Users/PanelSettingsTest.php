<?php

namespace QUI\Users;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Interfaces\Users\User as UserInterface;
use ReflectionProperty;

class PanelSettingsTest extends TestCase
{
    public function testCoreToolbarLoadsSettingsCategoriesInTheirExistingOrder(): void
    {
        $User = $this->createMock(UserInterface::class);
        $User->method('getUUID')->willReturn('');
        $categories = Utils::getUserToolbar($User)->toArray();

        self::assertSame(['details', 'security', 'data'], array_column($categories, 'name'));
        self::assertSame(['fa fa-user', 'fa fa-key', 'fa fa-envelope'], array_column($categories, 'image'));

        foreach ($categories as $category) {
            self::assertSame('xml', $category['type']);
            self::assertStringEndsWith('quiqqer/core/user.xml', $category['plugin']);
        }
    }

    public function testUserCategoryEndpointRendersSettingsWithoutATemplateEngine(): void
    {
        $OriginalUsers = QUI::$Users;
        $OriginalTemplate = QUI::$Template;
        $Instance = new ReflectionProperty(Auth\Handler::class, 'Instance');
        $OriginalHandler = $Instance->getValue();

        try {
            $User = $this->createMock(UserInterface::class);
            QUI::$Users = $this->createMock(Manager::class);
            QUI::$Users->expects(self::once())->method('get')->with(42)->willReturn($User);
            QUI::$Template = $this->createMock(QUI\Template::class);
            QUI::$Template->expects(self::never())->method('getEngine');
            $Handler = $this->createMock(Auth\Handler::class);
            $Handler->method('getAvailableAuthenticators')->willReturn([]);
            $Instance->setValue(null, $Handler);

            $html = Utils::getTab(42, 'packages/quiqqer/core/user.xml', 'details');

            self::assertContains('username', $this->values($this->parse($html), '//input/@name'));
        } finally {
            QUI::$Users = $OriginalUsers;
            QUI::$Template = $OriginalTemplate;
            $Instance->setValue(null, $OriginalHandler);
        }
    }

    public function testDetailsPreserveFormFieldsAndPopulateAvailableLanguages(): void
    {
        $Path = $this->render('details');
        self::assertSame([
            'id', 'uuid', 'su', 'username', 'email', 'avatar', 'usergroup', 'lang',
            'firstname', 'lastname', 'birthday', 'shortcuts', 'toolbar', 'assigned_toolbar',
            'regdate', 'lastedit', 'lastvisit', 'user_agent'
        ], $this->values($Path, '//input/@name | //select/@name'));
        self::assertSame(
            QUI::availableLanguages(),
            $this->values($Path, '//select[@name="lang"]/option/@value')
        );
        self::assertSame(['id', 'uuid', 'regdate', 'lastedit', 'lastvisit', 'user_agent'], $this->values(
            $Path,
            '//input[@disabled]/@name'
        ));
        self::assertSame(1.0, $Path->evaluate('count(//div[@data-qui-options-input="email"])'));
    }

    public function testSecurityPreservesPasswordAndExpirationFieldsWithoutAuthenticators(): void
    {
        $Path = $this->render('security');
        self::assertSame([
            'password', 'password2', 'showPasswords', 'quiqqer.set.new.password',
            'expire', 'expire', 'expire_date', 'quiqqer.has.seen.2fa.info'
        ], $this->values($Path, '//input/@name'));
        self::assertSame(['password', 'password2'], $this->values($Path, '//input[@type="password"]/@name'));
        self::assertSame(['never', 'date'], $this->values($Path, '//input[@name="expire"]/@value'));
        $radioIds = $this->values($Path, '//input[@name="expire"]/@id');
        self::assertCount(2, array_unique($radioIds));
        self::assertSame(1.0, $Path->evaluate('count(//div[@data-name="generate-and-send-password"])'));
        self::assertSame(0.0, $Path->evaluate('count(//li[@data-name="authenticator"])'));
        self::assertSame(
            QUI::getLocale()->get('quiqqer/core', 'user.settings.authenticators.2faList.empty'),
            $Path->evaluate('string(//div[@data-name="authenticators"]/p)')
        );
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function authenticatorStates(): iterable
    {
        yield 'disabled without settings' => [false, false];
        yield 'disabled with settings' => [false, true];
        yield 'enabled without settings' => [true, false];
        yield 'enabled with settings' => [true, true];
    }

    #[DataProvider('authenticatorStates')]
    public function testAuthenticatorStateAndTitleAreRenderedSafely(bool $enabled, bool $hasSettings): void
    {
        $Authenticator = $this->createMock(AuthenticatorInterface::class);
        $Authenticator->method('getTitle')->willReturn('<script>alert("x")</script> & Test');
        $Authenticator->method('getIcon')->willReturn('fa fa-brands fa-google');
        $Authenticator->method('getSettingsControl')->willReturn($hasSettings ? new QUI\Control() : null);
        $User = $this->createMock(UserInterface::class);
        $User->method('hasAuthenticator')->with($Authenticator::class)->willReturn($enabled);
        $Path = $this->parse(PanelSettings::render([OPT_DIR . 'quiqqer/core/user.xml'], 'security', $User, [
            $Authenticator
        ]));
        $Entry = $Path->query('//li[@data-name="authenticator"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $Entry);
        self::assertSame(0.0, $Path->evaluate('count(//div[@data-name="authenticators"]//table)'));
        self::assertSame(1.0, $Path->evaluate('count(//ul/li/div[@data-name="authenticator-actions"])'));
        self::assertSame(0.0, $Path->evaluate('count(//div[@class="description"]/div[@data-name="authenticators"])'));
        self::assertSame($enabled, str_contains($Entry->getAttribute('class'), 'authenticator-enabled'));
        self::assertSame($enabled || $hasSettings ? '1' : '', $Entry->getAttribute('data-settings'));
        self::assertSame($Authenticator::class, $Entry->getAttribute('data-authenticator'));
        $Icon = $Path->query('//li[@data-name="authenticator"]/span[@aria-hidden="true"]')->item(0);
        self::assertInstanceOf(DOMElement::class, $Icon);
        self::assertSame('quiqqer-user-authenticator-icon fa fa-brands fa-google', $Icon->getAttribute('class'));
        self::assertSame('<script>alert("x")</script> & Test', $Entry->textContent);
        self::assertSame(0.0, $Path->evaluate('count(//script)'));
    }

    public function testAddressCategoryRetainsTheGridMountPoint(): void
    {
        $Path = $this->render('data');
        self::assertSame(1.0, $Path->evaluate('count(/html/body/div[@data-name="address-list"])'));
        self::assertSame(0.0, $Path->evaluate('count(//table)'));
    }

    public function testUnknownCategoryIsEmpty(): void
    {
        self::assertSame('', PanelSettings::render(
            [OPT_DIR . 'quiqqer/core/user.xml'],
            'missing',
            $this->createMock(UserInterface::class),
            []
        ));
    }

    private function render(string $category): DOMXPath
    {
        return $this->parse(PanelSettings::render(
            [OPT_DIR . 'quiqqer/core/user.xml'],
            $category,
            $this->createMock(UserInterface::class),
            []
        ));
    }

    private function parse(string $html): DOMXPath
    {
        $Document = new DOMDocument();
        self::assertTrue($Document->loadHTML(
            '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>'
        ));

        return new DOMXPath($Document);
    }

    /**
     * @return list<string>
     */
    private function values(DOMXPath $Path, string $expression): array
    {
        $values = [];

        foreach ($Path->query($expression) ?: [] as $Node) {
            $values[] = $Node->nodeValue;
        }

        return $values;
    }
}
