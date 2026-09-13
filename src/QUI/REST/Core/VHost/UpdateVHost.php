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

final class UpdateVHost extends VHostEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/vhosts/{host}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User);
        $current = self::vhost($arguments['host']);
        $body = Input::body($Request, self::FIELDS);

        if ($body === []) {
            throw new ApiException('invalid_input', 'Provide at least one VHost setting.');
        }

        $config = self::config(array_replace($current, $body));
        (new QUI\System\VhostManager())->editVhost($arguments['host'], $config);
        return JsonResponse::write($Response, ['data' => self::vhost($arguments['host'])]);
    }
}
