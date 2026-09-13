<?php

namespace QUI\REST\Core\Project;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class CreateProject extends ProjectEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/projects';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        Permission::checkAdminUser($User);
        $body = Input::body($Request, [
            'name' => 'string', 'defaultLanguage' => 'string', 'languages' => 'array', 'template' => 'string',
            'applyDemoData' => 'boolean', 'demoDataSet' => 'string'
        ], ['name', 'defaultLanguage']);
        Lifecycle::availableName($body['name']);
        $languages = self::languages($body['defaultLanguage'], $body['languages'] ?? []);
        $template = $body['template'] ?? '';
        Lifecycle::template($template);
        $demoDataSet = $body['demoDataSet'] ?? null;
        $applyDemoData = $body['applyDemoData'] ?? false;

        if ($applyDemoData) {
            $sets = $template === '' ? [] : QUI\Utils\Project::getDemoDataSetsForTemplate($template);

            if ($sets === [] || ($demoDataSet !== null && !isset($sets[$demoDataSet]))) {
                throw new ApiException('invalid_input', 'The selected template or demo data set is unavailable.');
            }

            if ($demoDataSet === null && count($sets) > 1) {
                throw new ApiException('invalid_input', 'Select a demoDataSet.');
            }

            $demoDataSet ??= (string)array_key_first($sets);
        }

        $Project = QUI\Projects\Manager::createProject($body['name'], $body['defaultLanguage'], $languages, $template);
        $data = self::representation($Project);

        if ($applyDemoData) {
            try {
                QUI\Utils\Project::applyDemoDataToProject($Project, $template, $demoDataSet);
                $data['demoDataApplied'] = true;
            } catch (\Throwable $Error) {
                QUI\System\Log::addError((string)$Error);
                $data['demoDataApplied'] = false;
                $data['demoDataError'] = 'The project was created but demo data could not be applied.';
            }
        }

        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . rawurlencode($Project->getName());
        return JsonResponse::write($Response->withHeader('Location', $location), ['data' => $data], 201);
    }
}
