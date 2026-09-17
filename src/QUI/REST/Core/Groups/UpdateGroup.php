<?php

namespace QUI\REST\Core\Groups;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class UpdateGroup extends GroupEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/groups/{groupId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorizeGroup($User, 'edit');
        $Group = self::group($arguments['groupId']);
        $body = Input::body($Request, [
            'name' => 'string', 'toolbar' => 'string', 'assignedToolbar' => 'string', 'avatar' => 'string', 'parentId' => 'string'
        ]);

        if ($body === []) {
            throw new ApiException('invalid_input', 'At least one group field is required.');
        }

        if (isset($body['name']) && (trim($body['name']) === '' || mb_strlen($body['name']) > 50)) {
            throw new ApiException('invalid_input', 'name must contain between 1 and 50 characters.');
        }

        if (isset($body['parentId'])) {
            $Parent = self::group($body['parentId']);
            $Group->setParent($Parent->getUUID());
            unset($body['parentId']);
        }

        foreach ($body as $name => $value) {
            $Group->setAttribute($name === 'assignedToolbar' ? 'assigned_toolbar' : $name, $value);
        }

        $Group->save();
        return JsonResponse::write($Response, ['data' => self::groupRepresentation($Group)]);
    }
}
