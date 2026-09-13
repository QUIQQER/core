<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class InviteUser extends UserEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/users/invite';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'create');
        Permission::checkPermission('quiqqer.admin.users.send_mail', $User);
        $body = Input::body($Request, ['email' => 'string', 'groupIds' => 'array'], ['email']);

        if (!is_string($body['email']) || filter_var($body['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('invalid_input', 'email must be a valid email address.');
        }

        $groupIds = [];

        if (!empty($body['groupIds'])) {
            foreach (Input::ids($body['groupIds'], 'groupIds') as $id) {
                $Group = QUI::getGroups()->get($id);
                $groupIds[] = $Group->getId();
            }
        }

        $Invited = (new QUI\Users\Invite())->invite($body['email'], $groupIds);
        $location = substr($Request->getUri()->getPath(), 0, -strlen('/invite'));
        $location .= '/' . rawurlencode((string)$Invited->getUUID());
        return JsonResponse::write(
            $Response->withHeader('Location', $location),
            ['data' => self::representation($Invited)],
            201
        );
    }
}
