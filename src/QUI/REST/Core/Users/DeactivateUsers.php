<?php

namespace QUI\REST\Core\Users;

final class DeactivateUsers extends ChangeUserActivation
{
    public const PATH = '/users/deactivate';
    protected const ACTIVE = false;
}
