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
        $endpoints = [
            Project\Trash\ListMediaTrash::class,
            Project\Trash\RestoreMediaTrash::class,
            Project\Trash\DestroyMediaTrash::class,
            Project\Trash\ClearMediaTrash::class,
            Project\Trash\ListSiteTrash::class,
            Project\Trash\RestoreSiteTrash::class,
            Project\Trash\DestroySiteTrash::class,
            Project\Trash\ClearSiteTrash::class,

            VHost\ListVHosts::class,
            VHost\GetVHost::class,
            VHost\CreateVHost::class,
            VHost\UpdateVHost::class,
            VHost\DeleteVHost::class,

            Forwarding\ListForwardings::class,
            Forwarding\GetForwarding::class,
            Forwarding\CreateForwarding::class,
            Forwarding\UpdateForwarding::class,
            Forwarding\DeleteForwarding::class,

            Project\Media\DownloadMedia::class,
            Project\Media\UploadMedia::class,
            Project\Media\ReplaceMedia::class,

            Project\Media\ListMedia::class,
            Project\Media\GetMediaEffects::class,
            Project\Media\UpdateMediaEffects::class,

            Project\Media\GetMedia::class,
            Project\Media\CreateFolder::class,
            Project\Media\UpdateMedia::class,
            Project\Media\DeleteMedia::class,
            Project\Media\ActivateMedia::class,
            Project\Media\DeactivateMedia::class,
            Project\Media\MoveMedia::class,
            Project\Media\CopyMedia::class,
            Project\Media\SetMediaVisibility::class,
            Project\Media\SetMediaOrder::class,
            Project\Media\GetFolderPreview::class,
            Project\Media\SetFolderPreview::class,
            Project\Media\GetFolderSize::class,
            Project\Media\CreateImageVariant::class,

            Permissions\GetUserPermissions::class,
            Permissions\UpdateUserPermissions::class,
            Permissions\GetGroupPermissions::class,
            Permissions\UpdateGroupPermissions::class,
            Permissions\GetProjectPermissions::class,
            Permissions\UpdateProjectPermissions::class,
            Permissions\GetSitePermissions::class,
            Permissions\UpdateSitePermissions::class,
            Permissions\GetMediaPermissions::class,
            Permissions\UpdateMediaPermissions::class,
            Permissions\ListPermissions::class,
            Permissions\GetEffectivePermission::class,

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
            Project\ListAvailableLanguages::class,
            Project\ListProjectTemplates::class,
            Project\ListDemoDataSets::class,
            Project\ListProjects::class,
            Project\CreateProject::class,
            Project\GetProject::class,
            Project\RenameProject::class,
            Project\DeleteProject::class,
            Project\ListLanguages::class,
            Project\AddLanguage::class,
            Project\GetSettings::class,
            Project\UpdateSettings::class,
            Project\GetCustomCSS::class,
            Project\SetCustomCSS::class,
            Project\GetCustomJavaScript::class,
            Project\SetCustomJavaScript::class,
            Project\CreateDefaultStructure::class,
            Project\Sites\ActivateSites::class,
            Project\Sites\AddLanguageLink::class,
            Project\Sites\ClearSiteCache::class,
            Project\Sites\CopySite::class,
            Project\Sites\CreateSite::class,
            Project\Sites\CreateSiteCache::class,
            Project\Sites\DeactivateSites::class,
            Project\Sites\DeleteSite::class,
            Project\Sites\GetSite::class,
            Project\Sites\GetSiteLock::class,
            Project\Sites\LinkSite::class,
            Project\Sites\ListSiteLayouts::class,
            Project\Sites\ListSiteTypes::class,
            Project\Sites\ListSites::class,
            Project\Sites\LockSite::class,
            Project\Sites\MoveSite::class,
            Project\Sites\RemoveLanguageLink::class,
            Project\Sites\ResolveSite::class,
            Project\Sites\SetSiteType::class,
            Project\Sites\SortSites::class,
            Project\Sites\UnlinkSite::class,
            Project\Sites\UnlockSite::class,
            Project\Sites\UpdateSite::class
        ];

        // OAuth path scopes must encounter literal action routes before placeholders.
        usort($endpoints, static fn(string $a, string $b): int =>
            substr_count($a::PATH, '{') <=> substr_count($b::PATH, '{'));
        return $endpoints;
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
