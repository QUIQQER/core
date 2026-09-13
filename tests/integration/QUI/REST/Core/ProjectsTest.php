<?php

namespace QUI\REST\Core;

use QUI;
use QUI\Projects\ProjectTestHelper;

require_once __DIR__ . '/RestIntegrationTestCase.php';

class ProjectsTest extends RestIntegrationTestCase
{
    public function testProjectCanBeCreatedRenamedAndDeleted(): void
    {
        $name = 'phpunit_rest_' . bin2hex(random_bytes(4));
        $renamed = $name . '_renamed';
        $current = $name;

        try {
            $created = $this->data($this->request('POST', '/projects', ['name' => $name, 'defaultLanguage' => 'de']), 201);
            self::assertSame($name, $created['name']);
            self::assertSame(['de'], $created['languages']);
            $updated = $this->data($this->request('PATCH', '/projects/' . $name, ['name' => $renamed]));
            $current = $renamed;
            self::assertSame($renamed, $updated['name']);
            self::assertSame(404, $this->request('GET', '/projects/' . $name)->getStatusCode());
            $Response = $this->request('DELETE', '/projects/' . $renamed);
            self::assertSame(204, $Response->getStatusCode(), (string)$Response->getBody());
            self::assertFalse(QUI\Projects\Manager::existsProject($renamed));
        } finally {
            foreach ([$current, $name, $renamed] as $project) {
                if (QUI\Projects\Manager::existsProject($project)) {
                    $this->request('DELETE', '/projects/' . $project);
                }
            }
        }
    }

    public function testSettingsAreProjectWideAndInvalidBatchDoesNotPartiallySave(): void
    {
        $name = ProjectTestHelper::getProjectName();
        $path = '/projects/' . $name . '/settings';
        $before = $this->data($this->request('GET', $path));
        $values = array_column($before, 'value', 'key');
        $old = $values['adminSitemapMax'];
        $changed = $old === 37 ? 38 : 37;

        try {
            $Response = $this->request('PATCH', $path, ['adminSitemapMax' => $changed, 'unknown.rest.setting' => true]);
            self::assertSame(422, $Response->getStatusCode());
            $unchanged = array_column($this->data($this->request('GET', $path)), 'value', 'key');
            self::assertSame($old, $unchanged['adminSitemapMax']);
            $updated = array_column($this->data($this->request('PATCH', $path, ['adminSitemapMax' => $changed])), 'value', 'key');
            self::assertSame($changed, $updated['adminSitemapMax']);
        } finally {
            $this->request('PATCH', $path, ['adminSitemapMax' => $old]);
        }
    }

    public function testCustomCssAndJavascriptCanBeReplacedWithoutLanguagePath(): void
    {
        $name = ProjectTestHelper::getProjectName();

        foreach (['custom-css' => 'css', 'custom-javascript' => 'javascript'] as $resource => $field) {
            $path = '/projects/' . $name . '/' . $resource;
            $old = $this->data($this->request('GET', $path))[$field];

            try {
                $result = $this->data($this->request('PUT', $path, [$field => '/* REST integration test */']));
                self::assertSame('/* REST integration test */', trim($result[$field]));
            } finally {
                $this->request('PUT', $path, [$field => $old]);
            }
        }
    }
}
