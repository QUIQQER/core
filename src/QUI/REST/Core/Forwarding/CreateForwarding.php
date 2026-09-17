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

final class CreateForwarding extends ForwardingEndpoint
{
    public const METHOD = 'POST';
    public const PATH = '/forwardings';

    protected function handle(
        ServerRequestInterface $Request,
        ResponseInterface $Response,
        array $arguments,
        Actor $User
    ): ResponseInterface {
        self::authorize($User);
        $body = Input::body($Request, [
            'source' => 'string', 'target' => 'string', 'httpCode' => 'integer'
        ], ['source', 'target']);
        $source = self::url($body['source']);
        $target = self::url($body['target'], true);
        $code = self::code($body['httpCode'] ?? 301);

        if (isset(QUI\System\Forwarding::getList()->toArray()[$source])) {
            throw new ApiException('conflict', 'The forwarding source already exists.', 409);
        }

        QUI\System\Forwarding::create($source, $target, $code);
        $data = self::data($source, ['target' => $target, 'code' => $code]);
        $location = rtrim($Request->getUri()->getPath(), '/') . '/' . $data['id'];
        return JsonResponse::write($Response->withHeader('Location', $location), ['data' => $data], 201);
    }
}
