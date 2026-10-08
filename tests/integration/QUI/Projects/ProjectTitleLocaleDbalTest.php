<?php

namespace QUI\Projects;

use QUI;

class ProjectTitleLocaleDbalTest extends ProjectIntegrationTestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $originalTitleEntries = [];

    protected function setUp(): void
    {
        $this->originalTitleEntries = QUI\Translator::get('project/' . self::getTestProject()->getName(), 'title');
    }

    protected function tearDown(): void
    {
        $group = 'project/' . self::getTestProject()->getName();
        $Connection = QUI::getDataBaseConnection();
        $table = QUI\Utils\Doctrine::quoteIdentifier(QUI\Translator::table());

        foreach (QUI\Translator::get($group, 'title') as $entry) {
            $Connection->delete($table, ['id' => $entry['id']]);
        }

        foreach ($this->originalTitleEntries as $entry) {
            $this->insertTitleEntry($entry);
        }

        QUI\Translator::publish($group);
    }

    public function testProjectTitleIsSavedToEditLocaleOnly(): void
    {
        $Project = self::getTestProject();
        $before = $Project->getTitleLocaleData();

        self::assertArrayHasKey('id', $before);

        ProjectTestHelper::runAsSystemUser(static function () use ($Project): void {
            $Project->setTitleLocaleData([
                'de' => 'NerdSpot – Grüße für Neugierige'
            ]);
        });

        $after = $Project->getTitleLocaleData();

        self::assertSame($before['id'], $after['id']);
        self::assertSame($before['de'], $after['de']);
        self::assertSame('NerdSpot – Grüße für Neugierige', $after['de_edit']);
    }

    public function testPackageBoundProjectTitleIsSavedWithoutCreatingAnotherEntry(): void
    {
        $Project = self::getTestProject();
        $before = $Project->getTitleLocaleData();

        self::assertArrayHasKey('id', $before);

        $Connection = QUI::getDataBaseConnection();
        $table = QUI\Utils\Doctrine::quoteIdentifier(QUI\Translator::table());

        $Connection->update($table, [
            'package' => 'quiqqer/core',
            'de' => 'Original project title',
            'priority' => 50,
            'datatype' => 'php',
            'html' => 1
        ], ['id' => $before['id']]);
        $before = QUI\Translator::get('project/' . $Project->getName(), 'title')[0];

        ProjectTestHelper::runAsSystemUser(static function () use ($Project): void {
            $Project->setTitleLocaleData([
                'de' => 'Geänderter Projekttitel'
            ]);
        });

        $after = $Project->getTitleLocaleData();
        $expected = $before;
        $expected['de_edit'] = 'Geänderter Projekttitel';

        self::assertSame($expected, $after);
        self::assertCount(1, QUI\Translator::get('project/' . $Project->getName(), 'title'));
        self::assertSame(
            'Geänderter Projekttitel',
            QUI::getLocale()->getByLang('de', 'project/' . $Project->getName(), 'title')
        );
    }

    public function testPackageLessTitleRemainsPreferredAndPackageTitleIsUntouched(): void
    {
        $Project = self::getTestProject();
        $group = 'project/' . $Project->getName();
        $entry = $Project->getTitleLocaleData();

        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(QUI\Translator::table()),
            ['package' => 'quiqqer/core', 'de' => 'Package title', 'priority' => -1],
            ['id' => $entry['id']]
        );
        $packageEntry = QUI\Translator::get($group, 'title', 'quiqqer/core')[0];
        unset($entry['id']);
        $this->insertTitleEntry($entry);
        $before = $Project->getTitleLocaleData();

        self::assertEmpty($before['package']);

        ProjectTestHelper::runAsSystemUser(static function () use ($Project): void {
            $Project->setTitleLocaleData(['de' => 'Eigener Projekttitel']);
        });

        $after = $Project->getTitleLocaleData();
        self::assertSame($before['id'], $after['id']);
        self::assertSame('Eigener Projekttitel', $after['de_edit']);
        self::assertSame($packageEntry, QUI\Translator::get($group, 'title', 'quiqqer/core')[0]);
        self::assertSame('Eigener Projekttitel', QUI::getLocale()->getByLang('de', $group, 'title'));
    }

    public function testAmbiguousPackageTitlesRemainUnchangedWhenSavingFails(): void
    {
        $Project = self::getTestProject();
        $group = 'project/' . $Project->getName();
        $entry = $Project->getTitleLocaleData();

        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier(QUI\Translator::table()),
            ['package' => 'quiqqer/core'],
            ['id' => $entry['id']]
        );
        unset($entry['id']);
        $entry['package'] = 'quiqqer/title-test';
        $entry['priority'] = 50;
        $entry['de'] = 'Titel mit höherer Priorität';
        $this->insertTitleEntry($entry);
        $before = QUI\Translator::get($group, 'title');

        self::assertSame([], $Project->getTitleLocaleData());

        try {
            ProjectTestHelper::runAsSystemUser(static function () use ($Project): void {
                $Project->setTitleLocaleData(['de' => 'Darf nicht gespeichert werden']);
            });
            self::fail('Saving ambiguous project titles must fail without changing either entry.');
        } catch (QUI\Exception $Exception) {
            self::assertSame(QUI\Translator::ERROR_CODE_VAR_EXISTS, $Exception->getCode());
        }

        self::assertSame($before, QUI\Translator::get($group, 'title'));
    }

    public function testMissingProjectTitleIsCreatedAndPublished(): void
    {
        $Project = self::getTestProject();
        $group = 'project/' . $Project->getName();
        $entry = $Project->getTitleLocaleData();
        QUI::getDataBaseConnection()->delete(
            QUI\Utils\Doctrine::quoteIdentifier(QUI\Translator::table()),
            ['id' => $entry['id']]
        );

        self::assertSame([], $Project->getTitleLocaleData());

        ProjectTestHelper::runAsSystemUser(static function () use ($Project): void {
            $Project->setTitleLocaleData(['de' => 'Neuer Projekttitel']);
        });

        self::assertCount(1, QUI\Translator::get($group, 'title'));
        self::assertEmpty($Project->getTitleLocaleData()['package']);
        self::assertSame('Neuer Projekttitel', QUI::getLocale()->getByLang('de', $group, 'title'));
    }

    /** @param array<string, mixed> $entry */
    private function insertTitleEntry(array $entry): void
    {
        $quoted = [];

        foreach ($entry as $key => $value) {
            $quoted[QUI\Utils\Doctrine::quoteIdentifier($key)] = $value;
        }

        QUI::getDataBaseConnection()->insert(
            QUI\Utils\Doctrine::quoteIdentifier(QUI\Translator::table()),
            $quoted
        );
    }
}
