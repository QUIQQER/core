<?php

declare(strict_types=1);

namespace QUI\MCP;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use QUI\Log\Logger;
use QUI\Projects\ProjectIntegrationTestCase;
use QUI\Projects\ProjectTestHelper;
use QUI\Projects\Site\Edit;
use ReflectionMethod;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SiteUrlIntegrationTest extends ProjectIntegrationTestCase
{
    public function testActiveChildOfInactiveParentRemainsListableWithoutUrlErrors(): void
    {
        $Project = self::getTestProject();

        try {
            ProjectTestHelper::runAsSystemUser(function () use ($Project): void {
                $parentId = $Project->firstChild()->getEdit()->createChild([
                    'name' => 'inactive-archive',
                    'title' => 'Inactive archive'
                ]);
                $Parent = new Edit($Project, $parentId);
                $childId = $Parent->createChild([
                    'name' => 'active-entry',
                    'title' => 'Active entry'
                ]);
                $Child = new Edit($Project, $childId);
                $Child->activate();
                $Parent->deactivate();

                $Handler = new TestHandler();
                $Logger = Logger::getLogger();
                $Logger->pushHandler($Handler);

                try {
                    $result = (new ReflectionMethod(AbstractTool::class, 'parseSite'))->invoke(
                        null,
                        new Edit($Project, $childId)
                    );

                    self::assertSame($childId, $result['id']);
                    self::assertTrue($result['active']);
                    self::assertNull($result['url']);
                    self::assertNull($result['urlWithHost']);
                    self::assertTrue($result['languageLinks'][$Project->getLang()]['exists']);
                    self::assertNull($result['languageLinks'][$Project->getLang()]['url']);
                    self::assertFalse($Handler->hasErrorRecords());
                } finally {
                    $Logger->popHandler();
                }
            });
        } finally {
            ProjectTestHelper::cleanup();
        }
    }
}
