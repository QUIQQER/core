<?php

namespace QUI\REST\Core\Users;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI\Interfaces\Users\User as Actor;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;
use Throwable;

abstract class ChangeUserActivation extends UserEndpoint
{
    public const METHOD = 'POST';
    protected const ACTIVE = true;

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User, 'edit');
        $body = Input::body($Request, ['userIds' => 'array'], ['userIds']);
        $ids = Input::ids($body['userIds'], 'userIds');
        $results = [];

        foreach ($ids as $id) {
            try {
                $Target = self::user($id);
                $Target->checkEditPermission($User);
                $changed = $Target->isActive() !== static::ACTIVE;

                if ($changed && static::ACTIVE) {
                    $Target->activate('', $User);
                } elseif ($changed) {
                    $Target->deactivate($User);
                }

                $results[] = [
                    'id' => $id,
                    'status' => 200,
                    'changed' => $changed,
                    'data' => self::representation($Target)
                ];
            } catch (Throwable $Error) {
                $ErrorResponse = JsonResponse::error(new \QUI\REST\Response(), $Error);
                $error = json_decode((string)$ErrorResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $results[] = ['id' => $id, 'status' => $ErrorResponse->getStatusCode(), 'error' => $error['error']];
            }
        }

        return JsonResponse::write($Response, ['data' => $results]);
    }
}
