<?php

namespace QUI\REST\Core\VHost;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class CreateVHost extends VHostEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/vhosts';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User);
        $body = Input::body($Request, ['host' => 'string', ...self::FIELDS], ['host', 'project', 'rootLanguage']);
        $host = self::host($body['host']);
        $config = self::config($body);
        $Manager = new QUI\System\VhostManager();

        if (is_array($Manager->getVhost($host))) {
            throw new ApiException('conflict', 'The VHost already exists.', 409);
        }

        $host = $Manager->addVhost($host);

        try {
            $Manager->editVhost($host, $config);
        } catch (\Throwable $Error) {
            $Manager->removeVhost($host);
            throw $Error;
        }

        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . rawurlencode($host);
        return JsonResponse::write($Response->withHeader('Location', $location), ['data' => self::vhost($host)], 201);
    }
}
