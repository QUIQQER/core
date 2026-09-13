<?php

namespace QUI\REST\Core\Forwarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use QUI;
use QUI\Interfaces\Users\User as Actor;
use QUI\Permissions\Permission;
use QUI\REST\Core\ApiException;
use QUI\REST\Core\Input;
use QUI\REST\Core\JsonResponse;

final class UpdateForwarding extends ForwardingEndpoint
{
    public const METHOD = 'PATCH';
    public const PATH = '/forwardings/{forwardingId}';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User);
        $current = self::forwarding($arguments['forwardingId']);
        $body = Input::body($Request, ['target' => 'string', 'httpCode' => 'integer']);

        if ($body === []) {
            throw new ApiException('invalid_input', 'Provide target or httpCode.');
        }

        $target = self::url($body['target'] ?? $current['target'], true);
        $code = self::code($body['httpCode'] ?? $current['httpCode']);
        QUI\System\Forwarding::update($current['source'], $target, $code);
        return JsonResponse::write($Response, ['data' => self::forwarding($arguments['forwardingId'])]);
    }
}
