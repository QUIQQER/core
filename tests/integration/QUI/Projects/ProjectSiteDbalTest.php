<?php

namespace QUI\Projects;

use QUI;
use QUI\Interfaces\Projects\Site as SiteInterface;
use QUI\Permissions\Permission;
use ReflectionProperty;

class ProjectSiteDbalTest extends ProjectIntegrationTestCase
{
    private mixed $previousManagerSession;
    private mixed $previousPermissionUser;
    private ReflectionProperty $managerSessionProperty;
    private ReflectionProperty $permissionUserProperty;

    protected function setUp(): void
    {
        parent::setUp();

        $Users = QUI::getUsers();
        $SystemUser = $Users->getSystemUser();
        $this->managerSessionProperty = new ReflectionProperty($Users, 'Session');
        $this->permissionUserProperty = new ReflectionProperty(Permission::class, 'User');
        $this->previousManagerSession = $this->managerSessionProperty->getValue($Users);
        $this->previousPermissionUser = $this->permissionUserProperty->getValue();

        $this->managerSessionProperty->setValue($Users, $SystemUser);
        $this->permissionUserProperty->setValue(null, $SystemUser);
    }

    protected function tearDown(): void
    {
        $this->managerSessionProperty->setValue(QUI::getUsers(), $this->previousManagerSession);
        $this->permissionUserProperty->setValue(null, $this->previousPermissionUser);

        parent::tearDown();
    }

    public function testSiteRewrittenUrlSupportsQueryParametersThroughInterface(): void
    {
        /** @var SiteInterface $Site */
        $Site = self::getTestProject()->firstChild();

        $url = $Site->getUrlRewritten([], [
            'checkout' => '1',
            'return' => 'basket overview'
        ]);
        $query = [];

        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame([
            'checkout' => '1',
            'return' => 'basket overview'
        ], $query);
    }

    public function testLanguageLinksUseTheConfiguredDatabasePrefix(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $id = $Root->createChild(['name' => 'phpunit-language-' . bin2hex(random_bytes(6))]);
        $Site = new Site\Edit($Project, $id);
        $Connection = QUI::getDataBaseConnection();
        $table = QUI::getDBTableName($Project->getName() . '_multilingual');
        $language = $Project->getLang();
        $Connection->insert(QUI\Utils\Doctrine::quoteIdentifier($table), [$language => $id]);

        try {
            self::assertSame($id, $Site->getLangIds()[$language]);
        } finally {
            $Connection->delete(QUI\Utils\Doctrine::quoteIdentifier($table), [$language => $id]);
            $Site->getEdit()->delete();
        }
    }

    public function testSiteChildCanBeCreatedAndLoadedFromTestProject(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $siteName = 'phpunit-site-' . uniqid();
        $siteTitle = 'PHPUnit Site ' . uniqid();

        $siteId = ProjectTestHelper::runAsSystemUser(static function () use ($Root, $siteName, $siteTitle): int {
            return $Root->createChild([
                'name' => $siteName,
                'title' => $siteTitle,
                'short' => 'PHPUnit short text',
                'content' => '<p>PHPUnit content</p>'
            ]);
        });

        $Site = new Site\Edit($Project, $siteId);

        $this->assertGreaterThan(1, $siteId);
        $this->assertSame($siteName, $Site->getAttribute('name'));
        $this->assertSame($siteTitle, $Site->getAttribute('title'));
        $this->assertSame('PHPUnit short text', $Site->getAttribute('short'));
        $this->assertSame('<p>PHPUnit content</p>', $Site->getAttribute('content'));
    }

    public function testSiteCreationAndSavePersistEditDates(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();

        $siteId = ProjectTestHelper::runAsSystemUser(static function () use ($Root): int {
            return $Root->createChild([
                'name' => 'phpunit-edit-date-' . uniqid(),
                'title' => 'PHPUnit Edit Date'
            ]);
        });

        $Site = new Site\Edit($Project, $siteId);
        $creationDate = (string)$Site->getAttribute('c_date');

        $this->assertSame($creationDate, $Site->getAttribute('e_date'));
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $creationDate));

        $historicDate = '2000-01-01 00:00:00';
        QUI::getDataBaseConnection()->update(
            $Project->table(),
            [
                'c_date' => $historicDate,
                'e_date' => null
            ],
            ['id' => $siteId]
        );

        ProjectTestHelper::runAsSystemUser(static function () use ($Project, $siteId): void {
            $Site = new Site\Edit($Project, $siteId);
            $Site->setAttribute('title', 'PHPUnit Edit Date Updated');
            $Site->save();
        });

        $Site = new Site\Edit($Project, $siteId);
        $editDate = (string)$Site->getAttribute('e_date');

        $this->assertSame($historicDate, $Site->getAttribute('c_date'));
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $editDate));
        $this->assertGreaterThan(strtotime($historicDate), strtotime($editDate));
    }

    public function testSaveEventsReceiveExplicitUserInsteadOfAnonymousSessionUser(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $siteId = $Root->createChild(['name' => 'phpunit-save-user-' . uniqid()]);
        $Site = new Site\Edit($Project, $siteId);
        $SaveUser = QUI::getUsers()->getSystemUser();
        $Nobody = QUI::getUsers()->getNobody();
        $localUsers = [];
        $globalUsers = [];
        $localListener = static function ($SavedSite, $User = null) use (&$localUsers): void {
            $localUsers[] = $User;
        };
        $globalListener = static function ($SavedSite, $User = null) use ($siteId, &$globalUsers): void {
            if ($SavedSite->getId() === $siteId) {
                $globalUsers[] = $User;
            }
        };
        $Site->Events->addEvent('onSave', $localListener);
        QUI::getEvents()->addEvent('onSiteSave', $globalListener);

        try {
            QUI::getUsers()->withSessionUser($Nobody, static function () use ($Site, $SaveUser): void {
                $Site->setAttribute('title', 'Saved with an explicit user');
                $Site->save($SaveUser);
            });

            self::assertSame([$SaveUser], $localUsers);
            self::assertSame([$SaveUser], $globalUsers);
        } finally {
            $Site->Events->removeEvent('onSave', $localListener);
            QUI::getEvents()->removeEvent('onSiteSave', $globalListener);
            $Site->delete($SaveUser);
        }
    }

    public function testProjectSitesIdsCanFilterCountAndLimitCreatedSite(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $siteName = 'phpunit-filter-site-' . uniqid();

        $siteId = ProjectTestHelper::runAsSystemUser(static function () use ($Root, $siteName): int {
            return $Root->createChild([
                'name' => $siteName,
                'title' => 'PHPUnit Filter Site'
            ]);
        });

        $count = $Project->getSitesIds([
            'where' => [
                'active' => -1,
                'name' => $siteName
            ],
            'count' => true
        ]);

        $ids = $Project->getSitesIds([
            'where' => [
                'active' => -1,
                'name' => $siteName
            ],
            'order' => 'name ASC',
            'limit' => '0,1'
        ]);

        $this->assertSame(1, (int)$count[0]['count']);
        $this->assertSame($siteId, (int)$ids[0]['id']);
    }

    public function testSiteQueriesShareExplicitActiveFilters(): void
    {
        $Project = self::getTestProject();
        $fixture = $this->createActiveFilterSites();
        $cases = [
            [1, $fixture['active']],
            [0, $fixture['inactive']],
            [-1, $fixture['all']],
            ['1', $fixture['active']],
            ['0', $fixture['inactive']],
            ['-1', $fixture['all']]
        ];

        foreach ($cases as [$active, $expected]) {
            $params = [
                'where' => ['active' => $active],
                'order' => 'name ASC'
            ];
            $siteParams = $params;
            $siteParams['where']['id'] = $fixture['ids'];
            $context = 'active=' . var_export($active, true);
            $sites = $Project->getSitesIds($siteParams);
            self::assertSame($expected, array_map('intval', array_column($sites, 'id')), $context);
            self::assertSame($expected, $Project->getChildrenIdsFrom($fixture['parent'], $params), $context);

            $siteCount = $Project->getSitesIds(array_merge($siteParams, ['count' => true]));
            $childCount = $Project->getChildrenIdsFrom($fixture['parent'], array_merge($params, ['count' => true]));
            self::assertSame(count($expected), (int)$siteCount[0]['count'], $context);
            self::assertSame(count($expected), $childCount, $context);

            $sitePage = $Project->getSitesIds(array_merge($siteParams, ['limit' => '1,1']));
            $childPage = $Project->getChildrenIdsFrom($fixture['parent'], array_merge($params, ['limit' => '1,1']));
            self::assertSame(array_slice($expected, 1, 1), array_map('intval', array_column($sitePage, 'id')), $context);
            self::assertSame(array_slice($expected, 1, 1), $childPage, $context);
        }
    }

    public function testChildQueriesPreserveLegacyActiveFiltersAndPreferExplicitWhere(): void
    {
        $Project = self::getTestProject();
        $fixture = $this->createActiveFilterSites();
        $cases = [
            [[], $fixture['active']],
            [['active' => 0], $fixture['active']],
            [['active' => -1], $fixture['active']],
            [['active' => '0'], $fixture['active']],
            [['active' => '-1'], $fixture['active']],
            [['active' => '0&1'], $fixture['all']],
            [['active' => '0&1', 'where' => ['active' => 0]], $fixture['inactive']],
            [['active' => '0&1', 'where' => ['active' => 1]], $fixture['active']],
            [['active' => '0&1', 'where' => ['active' => -1]], $fixture['all']],
            [['active' => 0, 'where' => ['active' => -1]], $fixture['all']],
            [['active' => -1, 'where' => ['active' => 0]], $fixture['inactive']],
            [['active' => 1, 'where' => ['active' => 0]], $fixture['inactive']]
        ];

        foreach ($cases as [$params, $expected]) {
            $params['order'] = 'name ASC';
            self::assertSame(
                $expected,
                $Project->getChildrenIdsFrom($fixture['parent'], $params),
                json_encode($params, JSON_THROW_ON_ERROR)
            );
        }
    }

    public function testActiveFilterDefaultsAndAdditionalConditionsStayUnchanged(): void
    {
        $Project = self::getTestProject();
        $fixture = $this->createActiveFilterSites();
        $allIds = array_map('intval', array_column($Project->getSitesIds(), 'id'));

        foreach ($fixture['ids'] as $id) {
            self::assertContains($id, $allIds);
        }

        foreach ([[], ['active' => 0], ['active' => -1], ['active' => '0&1']] as $legacyParams) {
            $params = array_merge($legacyParams, [
                'where' => ['id' => $fixture['ids']],
                'order' => 'name ASC'
            ]);
            $ids = $Project->getSitesIds($params);
            self::assertSame($fixture['active'], array_map('intval', array_column($ids, 'id')));
        }

        $params = ['where' => ['active' => -1, 'id' => $fixture['inactive']]];
        self::assertSame($fixture['inactive'], $Project->getChildrenIdsFrom($fixture['parent'], $params));
        self::assertSame(
            [],
            $Project->getChildrenIdsFrom($fixture['parent'], ['where' => ['id' => $fixture['inactive']]])
        );

        $Parent = new Site\Edit($Project, $fixture['parent']);
        self::assertSame($fixture['inactive'], $Parent->getChildrenIds(['where' => ['active' => 0]]));
    }

    /** @return array{parent: int, ids: list<int>, active: list<int>, inactive: list<int>, all: list<int>} */
    private function createActiveFilterSites(): array
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $prefix = 'phpunit-active-filter-' . uniqid();
        $parentId = $Root->createChild(['name' => $prefix, 'title' => 'Active filter parent']);
        $Parent = new Site\Edit($Project, $parentId);
        $ids = [];

        foreach (['a-active', 'b-inactive', 'c-active', 'd-deleted'] as $name) {
            $ids[] = $Parent->createChild(['name' => $prefix . '-' . $name, 'title' => $name]);
        }

        (new Site\Edit($Project, $ids[0]))->activate();
        (new Site\Edit($Project, $ids[2]))->activate();
        QUI::getDataBaseConnection()->update(
            QUI\Utils\Doctrine::quoteIdentifier($Project->table()),
            ['active' => 1, 'deleted' => 1],
            ['id' => $ids[3]]
        );

        return [
            'parent' => $parentId,
            'ids' => $ids,
            'active' => [$ids[0], $ids[2]],
            'inactive' => [$ids[1]],
            'all' => [$ids[0], $ids[1], $ids[2]]
        ];
    }

    public function testProjectSitesIdsSupportsLegacyArrayConditions(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $siteName = "phpunit-legacy-condition-site-" . uniqid();

        $siteId = ProjectTestHelper::runAsSystemUser(static function () use ($Root, $siteName): int {
            return $Root->createChild([
                "name" => $siteName,
                "title" => "PHPUnit Legacy Condition Site"
            ]);
        });

        $likeIds = $Project->getSitesIds([
            "where" => [
                "active" => -1,
                "name" => [
                    "type" => "LIKE",
                    "value" => $siteName
                ]
            ]
        ]);

        $inIds = $Project->getSitesIds([
            "where" => [
                "active" => -1,
                "id" => [$siteId]
            ]
        ]);

        $this->assertSame([$siteId], array_map("intval", array_column($likeIds, "id")));
        $this->assertSame([$siteId], array_map("intval", array_column($inIds, "id")));

        $notArrayValueIds = $Project->getSitesIds([
            "where" => [
                "active" => -1,
                "id" => [
                    "type" => "NOT",
                    "value" => [$siteId]
                ]
            ],
            "limit" => "0,1"
        ]);

        $this->assertIsArray($notArrayValueIds);
    }

    public function testProjectSitesIdsGroupsSearchConditionsForResultsCountAndPagination(): void
    {
        $Project = self::getTestProject();
        $fixture = $this->createSearchSites();
        $ids = $fixture['ids'];
        $params = [
            'where_or' => $fixture['conditions'],
            'order' => 'name ASC'
        ];

        $results = $Project->getSitesIds($params);
        $count = $Project->getSitesIds(array_merge($params, ['count' => true]));
        $page = $Project->getSitesIds(array_merge($params, ['limit' => '1,1']));

        $this->assertSame(
            [$ids['title'], $ids['short'], $ids['content']],
            array_map('intval', array_column($results, 'id'))
        );
        $this->assertSame(3, (int)$count[0]['count']);
        $this->assertSame([$ids['short']], array_map('intval', array_column($page, 'id')));

        $params['where'] = ['id' => $ids['title']];
        $filtered = $Project->getSitesIds($params);
        $this->assertSame([$ids['title']], array_map('intval', array_column($filtered, 'id')));

        $params['where'] = ['id' => $ids['unmatched']];
        $this->assertSame([], $Project->getSitesIds($params));
    }

    public function testProjectSitesIdsSearchPreservesExplicitVisibilityFilters(): void
    {
        $Project = self::getTestProject();
        $fixture = $this->createSearchSites();
        $ids = $fixture['ids'];
        $cases = [
            [
                'where' => ['active' => -1],
                'expected' => [$ids['title'], $ids['short'], $ids['content'], $ids['inactive']]
            ],
            [
                'where' => ['active' => 0],
                'expected' => [$ids['inactive']]
            ],
            [
                'where' => ['active' => -1, 'deleted' => 1],
                'expected' => [$ids['deleted']]
            ],
            [
                'where' => ['active' => -1, 'deleted' => -1],
                'expected' => [
                    $ids['title'],
                    $ids['short'],
                    $ids['content'],
                    $ids['inactive'],
                    $ids['deleted']
                ]
            ]
        ];

        foreach ($cases as $case) {
            $results = $Project->getSitesIds([
                'where' => $case['where'],
                'where_or' => $fixture['conditions'],
                'order' => 'name ASC'
            ]);

            $this->assertSame($case['expected'], array_map('intval', array_column($results, 'id')));
        }
    }

    public function testGetSitesByInputListGroupsMixedSelectorsWithVisibilityFilters(): void
    {
        $Project = self::getTestProject();
        $fixture = $this->createSearchSites();
        $ids = $fixture['ids'];
        $selectors = [$ids['short'], $fixture['type']];
        $params = [
            'order' => 'name ASC',
            'limit' => 10
        ];

        $Sites = Site\Utils::getSitesByInputList($Project, $selectors, $params);
        $count = Site\Utils::getSitesByInputList($Project, $selectors, array_merge($params, ['count' => true]));

        $this->assertSame([$ids['title'], $ids['short']], array_map(
            static fn(Site $Site): int => $Site->getId(),
            $Sites
        ));
        $this->assertSame(2, (int)$count[0]['count']);
    }

    public function testGetSitesByInputListCombinesExplicitIdsWithWhereFilter(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();

        [$activeId, $inactiveId] = ProjectTestHelper::runAsSystemUser(static function () use ($Root): array {
            $activeId = $Root->createChild([
                "name" => "phpunit-input-list-active-" . uniqid(),
                "title" => "PHPUnit Input List Active"
            ]);
            $inactiveId = $Root->createChild([
                "name" => "phpunit-input-list-inactive-" . uniqid(),
                "title" => "PHPUnit Input List Inactive"
            ]);

            (new Site\Edit($Root->getProject(), $activeId))->activate();

            return [$activeId, $inactiveId];
        });

        $Sites = Site\Utils::getSitesByInputList($Project, [$activeId, $inactiveId], [
            "where" => [
                "active" => 1
            ],
            "limit" => 10,
            "order" => "name ASC"
        ]);

        $this->assertSame([$activeId], array_map(
            static fn(Site $Site): int => $Site->getId(),
            $Sites
        ));
    }

    public function testSiteChildrenIdsSupportLegacyArrayConditions(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $parentName = "phpunit-child-condition-parent-" . uniqid();
        $firstName = "phpunit-child-condition-a-" . uniqid();
        $secondName = "phpunit-child-condition-b-" . uniqid();
        $thirdName = "phpunit-child-condition-c-" . uniqid();
        $firstType = "phpunit/condition-a-" . uniqid();
        $secondType = "phpunit/condition-b-" . uniqid();
        $thirdType = "phpunit/condition-c-" . uniqid();

        [$parentId, $firstId, $secondId, $thirdId] = ProjectTestHelper::runAsSystemUser(
            static function () use (
                $Root,
                $parentName,
                $firstName,
                $secondName,
                $thirdName,
                $firstType,
                $secondType,
                $thirdType
            ): array {
                $parentId = $Root->createChild([
                    "name" => $parentName,
                    "title" => "PHPUnit Child Condition Parent"
                ]);
                $Parent = new Site\Edit($Root->getProject(), $parentId);
                $firstId = $Parent->createChild([
                    "name" => $firstName,
                    "title" => "PHPUnit Child Condition First"
                ]);
                $secondId = $Parent->createChild([
                    "name" => $secondName,
                    "title" => "PHPUnit Child Condition Second"
                ]);
                $thirdId = $Parent->createChild([
                    "name" => $thirdName,
                    "title" => "PHPUnit Child Condition Third"
                ]);

                foreach (
                    [
                        $firstId => $firstType,
                        $secondId => $secondType,
                        $thirdId => $thirdType
                    ] as $siteId => $type
                ) {
                    $Site = new Site\Edit($Root->getProject(), $siteId);
                    $Site->setAttribute("type", $type);
                    $Site->save();
                    $Site->activate();
                }

                return [$parentId, $firstId, $secondId, $thirdId];
            }
        );

        $Parent = new Site\Edit($Project, $parentId);

        $this->assertSame([$firstId, $secondId], $Parent->getChildrenIds([
            "where" => [
                "type" => [
                    "type" => "IN",
                    "value" => [$firstType, $secondType]
                ]
            ],
            "order" => "name ASC"
        ]));

        $this->assertSame([$thirdId], $Parent->getChildrenIds([
            "where" => [
                "type" => [$thirdType]
            ],
            "order" => "name ASC"
        ]));
    }

    public function testSiteTreeQueriesReturnChildrenParentsSiblingsAndRecursiveIds(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $parentName = 'phpunit-tree-parent-' . uniqid();
        $firstName = 'phpunit-tree-child-a-' . uniqid();
        $secondName = 'phpunit-tree-child-b-' . uniqid();
        $grandChildName = 'phpunit-tree-grandchild-' . uniqid();

        [$parentId, $firstId, $secondId, $grandChildId] = ProjectTestHelper::runAsSystemUser(
            static function () use ($Root, $parentName, $firstName, $secondName, $grandChildName): array {
                $parentId = $Root->createChild([
                    'name' => $parentName,
                    'title' => 'PHPUnit Tree Parent'
                ]);
                $Parent = new Site\Edit($Root->getProject(), $parentId);
                $firstId = $Parent->createChild([
                    'name' => $firstName,
                    'title' => 'PHPUnit Tree First'
                ]);
                $secondId = $Parent->createChild([
                    'name' => $secondName,
                    'title' => 'PHPUnit Tree Second'
                ]);
                $First = new Site\Edit($Root->getProject(), $firstId);
                $grandChildId = $First->createChild([
                    'name' => $grandChildName,
                    'title' => 'PHPUnit Tree Grandchild'
                ]);

                (new Site\Edit($Root->getProject(), $parentId))->activate();
                (new Site\Edit($Root->getProject(), $firstId))->activate();
                (new Site\Edit($Root->getProject(), $secondId))->activate();
                (new Site\Edit($Root->getProject(), $grandChildId))->activate();

                return [$parentId, $firstId, $secondId, $grandChildId];
            }
        );

        $Parent = new Site\Edit($Project, $parentId);
        $First = new Site\Edit($Project, $firstId);
        $Second = new Site\Edit($Project, $secondId);
        $GrandChild = new Site\Edit($Project, $grandChildId);

        $this->assertSame(2, $Parent->hasChildren(true));
        $this->assertSame($firstId, $Parent->firstChild(['active' => '0&1'])->getId());
        $this->assertSame($secondId, $Parent->lastChild(['active' => '0&1'])->getId());
        $this->assertSame([$firstId, $secondId], $Parent->getChildrenIds(['active' => '0&1']));
        $this->assertSame(2, $Parent->getChildren(['active' => '0&1', 'count' => true]));
        $this->assertSame($secondId, $First->nextSiblings(1)[0]->getId());
        $this->assertSame($firstId, $Second->previousSibling()->getId());
        $this->assertSame($firstId, $Project->getParentId($grandChildId));
        $this->assertSame($firstId, $Project->getParentIdFrom($grandChildId));
        $this->assertContains($parentId, $GrandChild->getParentIdTree());
        $this->assertContains($firstId, $GrandChild->getParentIds());
        $this->assertContains($grandChildId, $Parent->getChildrenIdsRecursive(['active' => '0&1']));
    }

    public function testRootSiteHasNoSiblings(): void
    {
        $Root = self::getTestProject()->firstChild();

        $this->assertSame([], $Root->nextSiblings(1));
        $this->assertSame([], $Root->previousSiblings(1));

        try {
            $Root->nextSibling();
            $this->fail('The root site unexpectedly returned a next sibling.');
        } catch (QUI\Exception $Exception) {
            $this->assertSame(
                QUI::getLocale()->get('quiqqer/core', 'exception.site.no.next.sibling'),
                $Exception->getMessage()
            );
        }

        try {
            $Root->previousSibling();
            $this->fail('The root site unexpectedly returned a previous sibling.');
        } catch (QUI\Exception $Exception) {
            $this->assertSame(
                QUI::getLocale()->get('quiqqer/core', 'exception.site.no.previous.sibling'),
                $Exception->getMessage()
            );
        }
    }

    public function testSiteCanBeCopiedAndLinkedAndLinkCanBeRemoved(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $sourceName = 'phpunit-copy-source-' . uniqid();
        $targetName = 'phpunit-copy-target-' . uniqid();
        $linkParentName = 'phpunit-link-parent-' . uniqid();

        [$sourceId, $targetId, $linkParentId] = ProjectTestHelper::runAsSystemUser(
            static function () use ($Root, $sourceName, $targetName, $linkParentName): array {
                $sourceId = $Root->createChild([
                    'name' => $sourceName,
                    'title' => 'PHPUnit Copy Source',
                    'short' => 'copy source short'
                ]);
                $targetId = $Root->createChild([
                    'name' => $targetName,
                    'title' => 'PHPUnit Copy Target'
                ]);
                $linkParentId = $Root->createChild([
                    'name' => $linkParentName,
                    'title' => 'PHPUnit Link Parent'
                ]);

                return [$sourceId, $targetId, $linkParentId];
            }
        );

        $copyId = ProjectTestHelper::runAsSystemUser(static function () use ($Project, $sourceId, $targetId): int {
            $Copy = (new Site\Edit($Project, $sourceId))->copy($targetId);

            return $Copy->getId();
        });

        $Copy = new Site\Edit($Project, $copyId);
        $this->assertSame($targetId, $Copy->getParentId());
        $this->assertSame('PHPUnit Copy Source', $Copy->getAttribute('title'));
        $this->assertSame('copy source short', $Copy->getAttribute('short'));

        ProjectTestHelper::runAsSystemUser(static function () use ($Project, $sourceId, $linkParentId): void {
            (new Site\Edit($Project, $sourceId))->linked($linkParentId);
        });

        $LinkedSource = new Site\Edit($Project, $sourceId);
        $this->assertContains($linkParentId, array_map('intval', $LinkedSource->getParentIds()));

        ProjectTestHelper::runAsSystemUser(static function () use ($Project, $sourceId, $linkParentId): void {
            (new Site\Edit($Project, $sourceId))->deleteLinked($linkParentId);
        });

        $UnlinkedSource = new Site\Edit($Project, $sourceId);
        $this->assertNotContains($linkParentId, array_map('intval', $UnlinkedSource->getParentIds()));
    }

    public function testCopiedSiteIsAppendedToManualChildOrder(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();

        [$parentId, $firstId, $secondId, $copyId] = ProjectTestHelper::runAsSystemUser(
            static function () use ($Root): array {
                $parentId = $Root->createChild([
                    'name' => 'phpunit-copy-order-parent-' . uniqid(),
                    'title' => 'PHPUnit Copy Order Parent'
                ]);
                $Parent = new Site\Edit($Root->getProject(), $parentId);
                $Parent->setAttribute('order_type', 'manuell');
                $Parent->save();

                $firstId = $Parent->createChild([
                    'name' => 'phpunit-copy-order-first-' . uniqid(),
                    'title' => 'PHPUnit Copy Order First'
                ]);
                $secondId = $Parent->createChild([
                    'name' => 'phpunit-copy-order-second-' . uniqid(),
                    'title' => 'PHPUnit Copy Order Second'
                ]);
                $copyId = (new Site\Edit($Root->getProject(), $firstId))->copy($parentId)->getId();

                return [$parentId, $firstId, $secondId, $copyId];
            }
        );

        $Parent = new Site\Edit($Project, $parentId);
        $First = new Site\Edit($Project, $firstId);
        $Second = new Site\Edit($Project, $secondId);
        $Copy = new Site\Edit($Project, $copyId);

        $this->assertSame(1, (int)$First->getAttribute('order_field'));
        $this->assertSame(2, (int)$Second->getAttribute('order_field'));
        $this->assertSame(3, (int)$Copy->getAttribute('order_field'));
        $this->assertSame(
            [$firstId, $secondId, $copyId],
            $Parent->getChildrenIds(['active' => '0&1'])
        );
    }

    public function testSiteLifecycleCanEditActivateMoveDeleteAndDestroy(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $parentAName = 'phpunit-parent-a-' . uniqid();
        $parentBName = 'phpunit-parent-b-' . uniqid();
        $childName = 'phpunit-child-' . uniqid();

        [$parentAId, $parentBId, $childId] = ProjectTestHelper::runAsSystemUser(
            static function () use ($Root, $parentAName, $parentBName, $childName): array {
                $parentAId = $Root->createChild([
                    'name' => $parentAName,
                    'title' => 'PHPUnit Parent A'
                ]);
                $parentBId = $Root->createChild([
                    'name' => $parentBName,
                    'title' => 'PHPUnit Parent B'
                ]);
                $ParentA = new Site\Edit($Root->getProject(), $parentAId);
                $childId = $ParentA->createChild([
                    'name' => $childName,
                    'title' => 'PHPUnit Child'
                ]);

                return [$parentAId, $parentBId, $childId];
            }
        );

        $Child = new Site\Edit($Project, $childId);
        $this->assertSame($parentAId, $Child->getParentId());

        ProjectTestHelper::runAsSystemUser(static function () use ($Project, $childId): void {
            $Child = new Site\Edit($Project, $childId);
            $Child->setAttribute('title', 'PHPUnit Child Edited');
            $Child->setAttribute('short', 'PHPUnit edited short text');
            $Child->setAttribute('content', '<p>PHPUnit edited content</p>');
            $Child->save();
            $Child->activate();
        });

        $Child = new Site\Edit($Project, $childId);
        $this->assertSame('PHPUnit Child Edited', $Child->getAttribute('title'));
        $this->assertSame('PHPUnit edited short text', $Child->getAttribute('short'));
        $this->assertSame('<p>PHPUnit edited content</p>', $Child->getAttribute('content'));
        $this->assertSame(1, (int)$Child->getAttribute('active'));

        ProjectTestHelper::runAsSystemUser(static function () use ($Project, $childId, $parentBId): void {
            $Child = new Site\Edit($Project, $childId);
            $Child->deactivate();
            $Child->move($parentBId);
        });

        $Child = new Site\Edit($Project, $childId);
        $this->assertSame(0, (int)$Child->getAttribute('active'));
        $this->assertSame($parentBId, $Child->getParentId());

        $ParentB = new Site\Edit($Project, $parentBId);
        $this->assertContains($childId, $ParentB->getChildrenIds(['active' => '0&1']));

        $deleteResult = ProjectTestHelper::runAsSystemUser(static function () use ($Project, $childId): bool {
            return (new Site\Edit($Project, $childId))->delete();
        });

        $this->assertTrue($deleteResult);

        $Child = new Site\Edit($Project, $childId);
        $this->assertSame(1, (int)$Child->getAttribute('deleted'));
        $this->assertSame(-1, (int)$Child->getAttribute('active'));

        ProjectTestHelper::runAsSystemUser(static function () use ($Child): void {
            $Child->destroy();
        });

        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $exists = $Connection->createQueryBuilder()
            ->select('COUNT(' . $Platform->quoteSingleIdentifier('id') . ')')
            ->from($Platform->quoteSingleIdentifier($Project->table()))
            ->where($Platform->quoteSingleIdentifier('id') . ' = :siteId')
            ->setParameter('siteId', $childId)
            ->executeQuery()
            ->fetchOne();

        $this->assertSame(0, (int)$exists);
    }

    public function testDeletedSiteCannotBeEditedUntilRestored(): void
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $siteTitle = 'PHPUnit Deleted Site';

        $siteId = ProjectTestHelper::runAsSystemUser(static function () use ($Root, $siteTitle): int {
            $siteId = $Root->createChild([
                'name' => 'phpunit-deleted-site-' . uniqid(),
                'title' => $siteTitle
            ]);

            (new Site\Edit($Root->getProject(), $siteId))->delete();

            return $siteId;
        });

        $DeletedSite = new Site\Edit($Project, $siteId);
        $DeletedSite->setAttribute('title', 'Changed deleted site');
        $DeletedSite->setAttribute('deleted', 0);

        $this->assertDeletedSiteMutationRejected(
            static fn() => $DeletedSite->save()
        );
        $this->assertDeletedSiteMutationRejected(
            static fn() => $DeletedSite->activate()
        );
        $this->assertDeletedSiteMutationRejected(
            static fn() => $DeletedSite->createChild(['name' => 'not-created'])
        );

        $ReloadedSite = new Site\Edit($Project, $siteId);
        $this->assertSame($siteTitle, $ReloadedSite->getAttribute('title'));
        $this->assertSame(1, (int)$ReloadedSite->getAttribute('deleted'));

        ProjectTestHelper::runAsSystemUser(static function () use ($Project, $Root, $siteId): void {
            $Project->getTrash()->restore($Project, [$siteId], $Root->getId());

            $RestoredSite = new Site\Edit($Project, $siteId);
            $RestoredSite->setAttribute('title', 'Restored site');
            $RestoredSite->save();
        });

        $RestoredSite = new Site\Edit($Project, $siteId);
        $this->assertSame(0, (int)$RestoredSite->getAttribute('deleted'));
        $this->assertSame('Restored site', $RestoredSite->getAttribute('title'));

        ProjectTestHelper::runAsSystemUser(static function () use ($RestoredSite): void {
            $RestoredSite->delete();
            $RestoredSite->refresh();
            $RestoredSite->destroy();
        });
    }

    /**
     * @return array{
     *     ids: array<string, int>,
     *     conditions: array<string, array{type: string, value: string}>,
     *     type: string
     * }
     */
    private function createSearchSites(): array
    {
        $Project = self::getTestProject();
        $Root = $Project->firstChild()->getEdit();
        $prefix = 'phpunit-search-' . bin2hex(random_bytes(6));
        $search = $prefix . "' OR 1=1 --";
        $type = 'phpunit/search-' . bin2hex(random_bytes(6));
        $entries = [
            'title' => ['title' => $search, 'type' => $type],
            'short' => ['short' => $search],
            'content' => ['content' => $search],
            'inactive' => ['title' => $search, 'type' => $type],
            'deleted' => ['title' => $search, 'type' => $type],
            'unmatched' => ['title' => 'Unrelated active page']
        ];
        $ids = [];
        $index = 0;

        foreach ($entries as $key => $attributes) {
            $attributes['name'] = $prefix . '-' . $index;
            $siteId = $Root->createChild($attributes);
            $Site = new Site\Edit($Project, $siteId);

            if (isset($attributes['type'])) {
                $Site->setAttribute('type', $attributes['type']);
                $Site->save();
            }

            if ($key === 'deleted') {
                $Site->delete();
            } elseif ($key !== 'inactive') {
                $Site->activate();
            }

            $ids[$key] = $siteId;
            $index++;
        }

        $conditions = [];

        foreach (['title', 'short', 'content'] as $field) {
            $conditions[$field] = [
                'type' => '%LIKE%',
                'value' => $search
            ];
        }

        return [
            'ids' => $ids,
            'conditions' => $conditions,
            'type' => $type
        ];
    }

    /**
     * @param callable(): void $Mutation
     */
    private function assertDeletedSiteMutationRejected(callable $Mutation): void
    {
        try {
            ProjectTestHelper::runAsSystemUser($Mutation);
            $this->fail('Deleted site mutation was not rejected.');
        } catch (\QUI\Exception $Exception) {
            $this->assertSame(706, $Exception->getCode());
            $this->assertSame(
                QUI::getLocale()->get('quiqqer/core', 'exception.site.deleted.cannot.edit'),
                $Exception->getMessage()
            );
        }
    }
}
