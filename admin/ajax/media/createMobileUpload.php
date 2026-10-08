<?php

declare(strict_types=1);

use QUI\Projects\Media\MobileUploadProvider;
use QUI\Upload\MobileUpload\Links;
use QUI\Upload\MobileUpload\Manager;

QUI::getAjax()->registerFunction(
    'ajax_media_createMobileUpload',
    static function (string $project, int $parentid): array {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new QUI\Exception('Method not allowed.', 405);
        }

        $Project = QUI\Projects\Manager::getProject($project);
        $session = (new Manager())->create(
            MobileUploadProvider::class,
            ['project' => $Project->getName(), 'folderId' => (string)$parentid],
            QUI::getUserBySession()
        );

        return Links::describe($session);
    },
    ['project', 'parentid'],
    'Permission::checkAdminUser'
);
