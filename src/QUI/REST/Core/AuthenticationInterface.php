<?php

namespace QUI\REST\Core;

use Psr\Http\Message\ServerRequestInterface;
use QUI\Interfaces\Users\User;

interface AuthenticationInterface
{
    public function authenticate(ServerRequestInterface $Request, string $scope): User;
}
