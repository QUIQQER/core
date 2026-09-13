<?php

namespace QUI\REST\Core;

use QUI;
use QUI\REST\ProviderInterface;
use QUI\REST\Server;

final class Provider implements ProviderInterface
{
    public const PREFIX = '/quiqqer/core';

    public function __construct(private readonly AuthenticationInterface $Authentication = new OAuthAuthentication())
    {
    }

    /** @return list<class-string<Endpoint>> */
    public static function getEndpoints(): array
    {
        return [
            Groups\ActivateGroups::class,
            Groups\DeactivateGroups::class,
            Groups\ListGroups::class,
            Groups\CreateGroup::class,
            Groups\GetGroup::class,
            Groups\UpdateGroup::class,
            Groups\DeleteGroup::class,
            Groups\ListUserGroups::class,
            Groups\ListGroupUsers::class,
            Groups\AddUserGroup::class,
            Groups\RemoveUserGroup::class,
            Users\ActivateUsers::class,
            Users\DeactivateUsers::class,
            Users\InviteUser::class,
            Users\ListUsers::class,
            Users\CreateUser::class,
            Users\GetUser::class,
            Users\UpdateUser::class,
            Users\DeleteUser::class,
            Users\ListUserAddresses::class,
            Users\GetUserAddress::class,
            Users\CreateUserAddress::class,
            Users\UpdateUserAddress::class,
            Users\DeleteUserAddress::class,
            Users\SetDefaultUserAddress::class,
            Users\SetUserPassword::class,
            Users\SendUserPasswordReset::class,
            Users\ListUserAuthenticators::class,
            Users\DisableUserAuthenticator::class,
            Users\DeleteUserWebAuthnCredential::class,
            Project\Sites\GetSite::class
        ];
    }

    public function register(Server $Server): void
    {
        foreach (self::getEndpoints() as $endpoint) {
            $Server->getSlim()->map(
                [$endpoint::METHOD],
                self::PREFIX . $endpoint::PATH,
                new $endpoint($this->Authentication)
            );
        }
    }

    public function getOpenApiDefinitionFile(): string
    {
        return __DIR__ . '/OpenApi.json';
    }

    public function getName(): string
    {
        return 'QuiqqerCore';
    }

    public function getTitle(?QUI\Locale $Locale = null): string
    {
        return 'QUIQQER Core REST API';
    }
}
