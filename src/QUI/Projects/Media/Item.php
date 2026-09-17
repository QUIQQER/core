<?php

/**
 * This file contains the \QUI\Projects\Media\Item
 */

namespace QUI\Projects\Media;

use QUI\Locale;
use QUI;
use QUI\Exception;
use QUI\Groups\Group;
use QUI\Permissions\Permission;
use QUI\Projects\Media;
use QUI\Users\User;
use QUI\Utils\Security\SvgSanitizer;
use QUI\Utils\System\File as QUIFile;

use function array_reverse;
use function count;
use function current;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_bool;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function md5;
use function method_exists;
use function pathinfo;
use function preg_replace;
use function reset;
use function str_replace;
use function strtolower;
use function strpos;
use function trim;

use const PATHINFO_EXTENSION;
use const URL_DIR;
use const VAR_DIR;

/**
 * A media item
 * the parent class of each media entry
 *
 * @author  www.pcsg.de (Henning Leutz)
 * @licence For copyright and license information, please view the /README.md
 */
abstract class Item extends QUI\QDOM
{
    /**
     * internal image effect parameter
     *
     * @var array<string, mixed>|false
     */
    protected array | false $effects = false;

    /**
     * internal media object
     */
    protected Media $Media;

    /**
     * internal parent id (use ->getParentId())
     */
    protected int | false $parent_id = false;

    /**
     * Path to the real file
     */
    protected string $file;

    /**
     * @var array<int, string>|null
     */
    protected ?array $pathHistory = null;

    /**
     * @var array<string, mixed>
     */
    protected array $title = [];

    /**
     * @var array<string, mixed>
     */
    protected array $description = [];

    /**
     * @var array<string, mixed>
     */
    protected array $alt = [];

    /**
     * @param array<string, mixed> $params - item attributes
     * @param Media $Media - Media of the file
     */
    public function __construct(array $params, Media $Media)
    {
        $params['id'] = (int)$params['id'];
        $params['hidden'] = (int)$params['hidden'];
        $params['active'] = (int)$params['active'];
        $params['priority'] = (int)$params['priority'];

        $extra = [];

        if (!empty($params['extra'])) {
            $extra = json_decode($params['extra'], true);
        }

        if (isset($params['extra'])) {
            unset($params['extra']);
        }

        $this->Media = $Media;
        $this->setAttributes($params);
        $this->setAttributes($extra);

        $this->file = CMS_DIR . $this->Media->getPath() . $this->getPath();

        // title, description
        $this->setAttribute('title', json_encode($this->title));
        $this->setAttribute('short', json_encode($this->description));
        $this->setAttribute('alt', json_encode($this->alt));

        if (!file_exists($this->file)) {
            QUI::getMessagesHandler()->addAttention(
                'File ' . $this->file . ' (' . $this->getId() . ') doesn`t exist'
            );

            return;
        }

        $this->setAttribute('filesize', QUIFile::getFileSize($this->file));
        $this->setAttribute('url', $this->getUrl());

        $this->setAttribute(
            'cache_url',
            URL_DIR . $this->Media->getCacheDir() . $this->getPath()
        );
    }

    //region General getter and is methods

    /**
     * Return the path of the file, without host, url dir or cms dir
     */
    public function getPath(): string
    {
        return $this->getAttribute('file');
    }

    /**
     * overwritten get attribute
     * -> this method considers multilingual attributes
     */
    public function getAttribute(string $name): mixed
    {
        if ($name === 'title') {
            return json_encode($this->title);
        }

        if ($name === 'short' || $name === 'description') {
            return json_encode($this->description);
        }

        if ($name === 'alt') {
            return json_encode($this->alt);
        }

        return parent::getAttribute($name);
    }

    /**
     * overwritten set attribute
     * -> this method considers multilingual attributes
     */
    public function setAttribute(string $name, mixed $value): void
    {
        if (
            $name !== 'title'
            && $name !== 'short'
            && $name !== 'description'
            && $name !== 'alt'
        ) {
            parent::setAttribute($name, $value);

            return;
        }

        // Multilingual attribute

        $languages = QUI::availableLanguages();
        $result = [];

        // it's already an array
        if (is_array($value)) {
            foreach ($languages as $language) {
                if (isset($value[$language])) {
                    $result[$language] = $value[$language];
                }
            }

            $this->setMultilingualArray($name, $result);

            return;
        }

        // value is a string, so we need to look deeper
        if ($value) {
            $value = json_decode($value, true);
        }

        $current = QUI::getLocale()->getCurrent();

        if (!$value || !is_array($value)) {
            $result[$current] = $value;
        } else {
            foreach ($languages as $language) {
                if (isset($value[$language])) {
                    $result[$language] = $value[$language];
                }
            }
        }

        $this->setMultilingualArray($name, $result);
    }

    /**
     * Set multilingual attributes -> array of attributes [de => text, en => text]
     * - util method
     *
     * @param array<string, mixed> $val
     */
    protected function setMultilingualArray(string $type, array $val): void
    {
        switch ($type) {
            case 'title':
                foreach ($val as $language => $v) {
                    $this->title[$language] = $v;
                }

                break;

            case 'alt':
                foreach ($val as $language => $v) {
                    $this->alt[$language] = $v;
                }

                break;

            case 'short':
            case 'description':
                foreach ($val as $language => $v) {
                    $this->description[$language] = $v;
                }

                break;
        }
    }

    /**
     * Returns the id of the item
     */
    public function getId(): int
    {
        return (int)$this->getAttribute('id');
    }

    /**
     * Returns the url from the file
     */
    public function getUrl(bool $rewritten = false): string
    {
        if (!$rewritten) {
            $Project = $this->Media->getProject();

            $str = 'image.php?id=' . $this->getId() . '&project=' . $Project->getName();

            if ($this->getAttribute('maxheight')) {
                $str .= '&maxheight=' . $this->getAttribute('maxheight');
            }

            if ($this->getAttribute('maxwidth')) {
                $str .= '&maxwidth=' . $this->getAttribute('maxwidth');
            }

            return $str;
        }

        if ($this->getAttribute('active') == 1) {
            return URL_DIR . $this->Media->getCacheDir() . $this->getAttribute('file');
        }

        return '';
    }

    /**
     * Return the Project of the item
     */
    public function getProject(): QUI\Projects\Project
    {
        return $this->getMedia()->getProject();
    }

    /**
     * Return the Media of the item
     */
    public function getMedia(): Media
    {
        return $this->Media;
    }

    /**
     * overwritten get attributes
     * -> this method considers multilingual attributes
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        $attributes = parent::getAttributes();

        $attributes['title'] = $this->getAttribute('title');
        $attributes['short'] = $this->getAttribute('short');
        $attributes['alt'] = $this->getAttribute('alt');

        return $attributes;
    }

    /**
     * Retrieves the title for the current locale.
     *
     * @param Locale|null $Locale The locale to retrieve the title for. If not provided, the current locale will be used.
     *
     * @return string The title for the current locale. Returns an empty string if the title is not available.
     */
    public function getTitle(null | Locale $Locale = null): string
    {
        if ($Locale === null) {
            $Locale = QUI::getLocale();
        }

        $current = $Locale->getCurrent();

        if (isset($this->title[$current])) {
            if (empty($this->title[$current])) {
                return '';
            }

            return $this->title[$current];
        }

        reset($this->title);
        $current = current($this->title);

        if (empty($current)) {
            return '';
        }

        return $current;
    }

    /**
     * @param mixed ...$params
     *
     *      setTitle('text')                 - set this title to all languages
     *      setTitle('text', 'language')     - set this title to the wanted languages
     *      setTitle(['de' => 'text', 'en' => 'text'])  - set the language array
     */
    public function setTitle(...$params): void
    {
        $this->setMultilingualParams($params, 'title');
    }

    //endregion

    // region API Methods - General important file operations

    /**
     * Set multilingual params (attributes)
     * - util helper
     * - looks at the params type
     * - helper for setTitle, setShort, setDescription, setAlt
     *
     * @param array<int|string, mixed> $params
     * @param string $type
     */
    protected function setMultilingualParams($params, $type): void
    {
        $languages = QUI::availableLanguages();

        if (count($params) === 2) {
            $text = $params[0];
            $language = $params[0];

            if (in_array($languages, $language)) {
                $this->setMultilingualArray($type, [$language => $text]);
            }

            return;
        }

        if (is_string($params[0])) {
            $text = $params[0];
            $result = [];

            foreach ($languages as $language) {
                $result[$language] = $text;
            }

            $this->setMultilingualArray($type, $result);

            return;
        }

        $this->setMultilingualArray($type, $params[0]);
    }

    /**
     * Return the short / description
     * alias for getDescription()
     */
    public function getShort(null | QUI\Locale $Locale = null): string
    {
        return $this->getDescription($Locale);
    }

    /**
     * Return the short / description
     */
    public function getDescription(null | QUI\Locale $Locale = null): mixed
    {
        if ($Locale === null) {
            $Locale = QUI::getLocale();
        }

        $current = $Locale->getCurrent();

        if (isset($this->description[$current])) {
            if (empty($this->description[$current])) {
                return '';
            }

            return $this->description[$current];
        }

        reset($this->description);
        $current = current($this->description);

        if (empty($current)) {
            return '';
        }

        return $current;
    }

    /**
     * Set the description of this item
     *
     *      setDescription('text')                 - set this description to all languages
     *      setDescription('text', 'language')     - set this description to the wanted languages
     *      setDescription(['de' => 'text', 'en' => 'text'])  - set the language array
     *
     * @param mixed ...$params
     */
    public function setDescription(...$params): void
    {
        $this->setMultilingualParams($params, 'short');
    }

    /**
     * Return the alt text
     *
     * @param null|QUI\Locale $Locale
     */
    public function getAlt(null | QUI\Locale $Locale = null): string
    {
        if ($Locale === null) {
            $Locale = QUI::getLocale();
        }

        $current = $Locale->getCurrent();

        if (isset($this->alt[$current])) {
            if (empty($this->alt[$current])) {
                return '';
            }

            return $this->alt[$current];
        }

        reset($this->alt);
        $result = current($this->alt);

        if (empty($result)) {
            return '';
        }

        return $result;
    }

    /**
     * Set the alt of this item
     *
     *      setAlt('text')                 - set this alt to all languages
     *      setAlt('text', 'language')     - set this alt to the wanted languages
     *      setAlt(['de' => 'text', 'en' => 'text'])  - set the language array
     *
     * @param mixed ...$params
     */
    public function setAlt(...$params): void
    {
        $this->setMultilingualParams($params, 'alt');
    }

    /**
     * Activates this item
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws Exception
     */
    public function activate(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        try {
            // activate the parents, otherwise the file is not accessible
            $this->getParent()->activate($PermissionUser);
        } catch (Exception) {
            // has no parent
        }

        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            ['active' => 1],
            ['id' => $this->getId()]
        );

        $this->setAttribute('active', 1);


        if (method_exists($this, 'deleteCache')) {
            try {
                $this->deleteCache();
            } catch (\Exception $Exception) {
                QUI\System\Log::addNotice($Exception->getMessage());
            }
        }

        if (method_exists($this, 'createCache')) {
            try {
                $this->createCache();
            } catch (\Exception $Exception) {
                QUI\System\Log::addNotice($Exception->getMessage());
            }
        }

        QUI::getEvents()->fireEvent('mediaActivate', [$this]);
    }

    /**
     * Shortcut for QUI\Permissions\Permission::checkSitePermission
     *
     * @param string $permission - name of the permission
     * @param QUI\Interfaces\Users\User|null $User - optional
     *
     * @throws QUI\Permissions\Exception
     */
    public function checkPermission(string $permission, null | QUI\Interfaces\Users\User $User = null): void
    {
        if (Media::useMediaPermissions() === false) {
            return;
        }


        $Manager = QUI::getPermissionManager();
        $permList = $Manager->getMediaPermissions($this);

        if (empty($permList[$permission])) {
            return;
        }

        QUI\Permissions\Permission::checkMediaPermission(
            $permission,
            $this,
            $User
        );
    }

    /**
     * Return the Parent Media Item Object
     *
     * @throws Exception
     */
    public function getParent(): Folder
    {
        $Item = $this->Media->get((int)$this->getParentId());

        if ($Item instanceof Folder) {
            return $Item;
        }

        throw new QUI\Exception('Something went wrong. Item is no folder');
    }

    /**
     * Return the parent id
     */
    public function getParentId(): int | false
    {
        if ($this->parent_id) {
            return $this->parent_id;
        }

        $id = $this->getId();

        if ($id === 1) {
            return false;
        }

        $this->parent_id = $this->Media->getParentIdFrom($id);

        return $this->parent_id;
    }

    /**
     * Deactivate the file
     * - the file is no longer public
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws Exception
     */
    public function deactivate(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            ['active' => 0],
            ['id' => $this->getId()]
        );

        $this->setAttribute('active', 0);

        if (method_exists($this, 'deleteCache')) {
            $this->deleteCache();
        }

        QUI::getEvents()->fireEvent('mediaDeactivate', [$this]);

        // remove fila path cache
        QUI\Cache\LongTermCache::clear(
            $this->getMedia()->getCacheDir() . 'filePathIds/' . md5($this->getAttribute('file'))
        );
    }

    /**
     * Delete the file and move it to the trash
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     * @throws Exception
     */
    public function delete(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        $this->checkPermission('quiqqer.projects.media.del', $PermissionUser);

        if ($this->isDeleted()) {
            throw new Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.media.already.deleted'),
                ErrorCodes::ALREADY_DELETED
            );
        }

        QUI::getEvents()->fireEvent('mediaDeleteBegin', [$this]);

        $Media = $this->Media;
        $First = $Media->firstChild();

        // Move file to the temp folder
        $original = $this->getFullPath();
        $notFound = false;

        $var_folder = VAR_DIR . 'media/trash/' . $Media->getProject()->getName() . '/';

        if (!is_file($original)) {
            QUI::getMessagesHandler()->addAttention(
                QUI::getLocale()->get(
                    'quiqqer/core',
                    'exception.delete.originalfile.notfound'
                )
            );

            $notFound = true;
        }

        if ($First->getFullPath() === $original) {
            throw new Exception(
                ['quiqqer/core', 'exception.delete.root.file'],
                ErrorCodes::ROOT_FOLDER_CANT_DELETED
            );
        }

        // first, delete the cache
        if (method_exists($this, 'deleteCache')) {
            try {
                $this->deleteCache();
            } catch (\Exception $Exception) {
                QUI\System\Log::addWarning($Exception->getMessage());
            }
        }

        if (method_exists($this, 'deleteAdminCache')) {
            try {
                $this->deleteAdminCache();
            } catch (\Exception $Exception) {
                QUI\System\Log::addWarning($Exception->getMessage());
            }
        }


        // second, move the file to the trash
        try {
            QUIFile::unlink($var_folder . $this->getId());
        } catch (Exception $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());
        }

        try {
            QUIFile::mkdir($var_folder);
            QUIFile::move($original, $var_folder . $this->getId());
        } catch (Exception $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());
        }

        $deletedAt = date('Y-m-d H:i:s');
        $deletedUser = QUI::getUserBySession()->getUUID();

        // change db entries
        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            [
                'deleted' => 1,
                'active' => 0,
                'file' => '',
                'deleted_at' => $deletedAt,
                'e_date' => $deletedAt,
                'e_user' => $deletedUser
            ],
            [
                'id' => $this->getId()
            ]
        );

        QUI::getDataBaseConnection()->delete(
            $this->Media->getTable('relations'),
            ['child' => $this->getId()]
        );

        $this->parent_id = false;
        $this->setAttribute('deleted', 1);
        $this->setAttribute('active', 0);
        $this->setAttribute('deleted_at', $deletedAt);
        $this->setAttribute('e_date', $deletedAt);
        $this->setAttribute('e_user', $deletedUser);

        try {
            QUI::getEvents()->fireEvent('mediaDelete', [$this]);
        } catch (QUI\ExceptionStack $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());
        }

        // remove fila path cache
        QUI\Cache\LongTermCache::clear(
            $this->getMedia()->getCacheDir() . 'filePathIds/' . md5($this->getAttribute('file'))
        );

        if ($notFound) {
            $this->destroy();
        }
    }

    /**
     * Returns if the file is deleted or not
     */
    public function isDeleted(): bool
    {
        return (bool)$this->getAttribute('deleted');
    }

    /**
     * Retrieves the full path for the current file.
     *
     * @return string The full path for the file.
     */
    public function getFullPath(): string
    {
        return $this->Media->getFullPath() . $this->getAttribute('file');
    }

    /**
     * Destroy the File complete from the DataBase and from the Filesystem
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     * @throws Exception
     */
    public function destroy(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        $this->checkPermission('quiqqer.projects.media.del', $PermissionUser);


        if ($this->isActive()) {
            throw new Exception(
                'Only inactive files can be destroyed',
                ErrorCodes::FILE_CANT_DESTROYED
            );
        }

        if (!$this->isDeleted()) {
            throw new Exception(
                'Only deleted files can be destroyed',
                ErrorCodes::FILE_CANT_DESTROYED
            );
        }

        $Media = $this->Media;

        // get the trash file and destroy it
        $var_folder = VAR_DIR . 'media/trash/' . $Media->getProject()->getName() . '/';
        $var_file = $var_folder . $this->getId();

        try {
            QUIFile::unlink($var_file);
        } catch (Exception $Exception) {
            QUI\System\Log::addWarning($Exception->getMessage());
        }

        QUI::getDataBaseConnection()->delete($this->Media->getTable(), [
            'id' => $this->getId()
        ]);

        $Media->invalidateItemCache($this->getId());

        QUI::getEvents()->fireEvent('mediaDestroy', [$this]);

        // remove fila path cache
        QUI\Cache\LongTermCache::clear(
            $Media->getCacheDir() . 'filePathIds/' . md5($this->getAttribute('file'))
        );
    }

    /**
     * Returns if the file is active or not
     */
    public function isActive(): bool
    {
        return (bool)$this->getAttribute('active');
    }

    // endregion

    // region Get Parent Methods

    /**
     * move the item to another folder
     *
     * @param Folder $Folder - the new folder of the file
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws Exception
     */
    public function moveTo(
        Folder $Folder,
        null | QUI\Interfaces\Users\User $PermissionUser = null
    ): void {
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);


        // check if a child with the same name exist
        if ($Folder->fileWithNameExists($this->getAttribute('name'))) {
            throw new Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.media.file.with.same.name.exists', [
                    'name' => $Folder->getAttribute('name')
                ]),
                ErrorCodes::FILE_ALREADY_EXISTS
            );
        }

        $Parent = $this->getParent();
        $old_path = $this->getFullPath();

        $Parent->getFullPath();

        $new_path = str_replace(
            $Parent->getFullPath(),
            $Folder->getFullPath(),
            $this->getFullPath()
        );

        $new_file = str_replace($this->getMedia()->getFullPath(), '', $new_path);

        // delete the file cache
        // @todo move the cache too
        if (method_exists($this, 'deleteCache')) {
            $this->deleteCache();
        }

        if (method_exists($this, 'deleteAdminCache')) {
            $this->deleteAdminCache();
        }


        // update file path
        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            [
                'file' => $new_file,
                'pathHash' => md5($new_file)
            ],
            ['id' => $this->getId()]
        );

        // set the new parent relationship
        QUI::getDataBaseConnection()->update(
            $this->Media->getTable('relations'),
            [
                'parent' => $Folder->getId()
            ],
            [
                'parent' => $Parent->getId(),
                'child' => $this->getId()
            ]
        );

        // move file on the real directory
        QUIFile::move($old_path, $new_path);

        // update internal references
        $this->setAttribute('file', $new_file);


        $this->parent_id = $Folder->getId();
    }

    /**
     * copy the item to another folder
     *
     * @param Folder $Folder
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     * @return QUI\Interfaces\Projects\Media\File - The new file
     *
     * @throws Exception
     */
    public function copyTo(
        Folder $Folder,
        null | QUI\Interfaces\Users\User $PermissionUser = null
    ): QUI\Interfaces\Projects\Media\File {
        $this->checkPermission('quiqqer.projects.media.view', $PermissionUser);
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        $File = $Folder->uploadFile(
            $this->getFullPath(),
            Folder::FILE_OVERWRITE_NONE,
            $PermissionUser
        );

        $File->setAttribute('title', $this->getAttribute('title'));
        $File->setAttribute('alt', $this->getAttribute('alt'));
        $File->setAttribute('short', $this->getAttribute('short'));
        $File->save();

        return $File;
    }

    /**
     * Save the file to the database
     * The id attribute can not be overwritten
     *
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws Exception
     */
    public function save(null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        // permission check
        if (Media::useMediaPermissions() && method_exists($this, 'deleteCache')) {
            $this->deleteCache();
        }

        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);


        // save logic
        QUI::getEvents()->fireEvent('mediaSaveBegin', [$this]);

        // Rename the file, if necessary
        $this->rename($this->getAttribute('name'));

        $image_effects = $this->getEffects();

        switch ($this->getAttribute('order')) {
            case 'priority':
            case 'priority ASC':
            case 'priority DESC':
            case 'c_date':
            case 'c_date ASC':
            case 'c_date DESC':
            case 'name':
            case 'name ASC':
            case 'name DESC':
            case 'title':
            case 'title ASC':
            case 'title DESC':
            case 'id':
            case 'id ASC':
            case 'id DESC':
                $order = $this->getAttribute('order');
                break;

            default:
                $order = '';
        }

        $mimeType = (string)$this->getAttribute('mime_type');
        $extension = strtolower((string)pathinfo($this->getFullPath(), PATHINFO_EXTENSION));
        $mustBeSvg = in_array($extension, ['svg', 'svgz'], true)
            || in_array($mimeType, ['image/svg', 'image/svg+xml'], true);
        $mayBeSvg = $mustBeSvg || in_array(
            $mimeType,
            ['application/xml', 'application/xhtml+xml', 'text/html', 'text/plain', 'text/xml'],
            true
        );

        if ($mayBeSvg) {
            $content = file_get_contents($this->getFullPath());
            $sanitizedSvg = is_string($content) ? SvgSanitizer::sanitize($content) : '';

            if ($sanitizedSvg === '' && $mustBeSvg) {
                throw new QUI\Exception(
                    ['quiqqer/core', 'exception.image.upload.image.corrupted'],
                    ErrorCodes::FILE_IMAGE_CORRUPT
                );
            }

            if ($sanitizedSvg !== '') {
                if (file_put_contents($this->getFullPath(), $sanitizedSvg) === false) {
                    throw new QUI\Exception(
                        ['quiqqer/core', 'exception.image.upload.image.corrupted'],
                        ErrorCodes::FILE_IMAGE_CORRUPT
                    );
                }

                $this->setAttribute('mime_type', 'image/svg+xml');

                QUI::getDataBaseConnection()->update(
                    $this->Media->getTable(),
                    ['mime_type' => 'image/svg+xml'],
                    ['id' => $this->getId()]
                );
            }
        }

        if (method_exists($this, 'deleteCache')) {
            $this->deleteCache();
        }

        if (method_exists($this, 'deleteAdminCache')) {
            $this->deleteAdminCache();
        }

        $fileInfo = QUI\Utils\System\File::getInfo($this->getFullPath());
        $type = QUI\Projects\Media\Utils::getMediaTypeByMimeType($fileInfo['mime_type']);

        if (Utils::isFolder($this)) {
            $type = 'folder';
        }

        // extra attributes
        $extraAttributes = Utils::getExtraAttributeListForMediaItems($this);
        $mediaExtra = [];

        foreach ($extraAttributes as $data) {
            $attribute = $data['attribute'];
            $default = $data['default'];

            if ($this->existsAttribute($attribute)) {
                $mediaExtra[$attribute] = $this->getAttribute($attribute);
                continue;
            }

            $mediaExtra[$attribute] = $default;
        }


        $Connection = QUI::getDataBaseConnection();
        $Platform = $Connection->getDatabasePlatform();
        $QueryBuilder = $Connection->createQueryBuilder();
        $mediaData = [
            'title' => $this->saveMultilingualField($this->title),
            'alt' => $this->saveMultilingualField($this->alt),
            'short' => $this->saveMultilingualField($this->description),
            'order' => $order,
            'priority' => (int)$this->getAttribute('priority'),
            'external' => $this->getAttribute('external'),
            'image_effects' => json_encode($image_effects),
            'type' => $type,
            'pathHistory' => json_encode($this->getPathHistory()),
            'hidden' => $this->isHidden() ? 1 : 0,
            'extra' => json_encode($mediaExtra)
        ];

        $QueryBuilder->update($Platform->quoteSingleIdentifier($this->Media->getTable()))
            ->where($Platform->quoteSingleIdentifier('id') . ' = :mediaId')
            ->setParameter('mediaId', $this->getId());

        foreach ($mediaData as $field => $value) {
            $parameter = 'media_' . $field;
            $QueryBuilder
                ->set($Platform->quoteSingleIdentifier((string)$field), ':' . $parameter)
                ->setParameter($parameter, $value);
        }

        $QueryBuilder->executeStatement();

        // @todo in eine queue setzen
        $Project = $this->getProject();

        if ($Project->getConfig('media_createCacheOnSave') && method_exists($this, 'createCache')) {
            $this->createCache();
        }

        // build frontend cache
        $Media = $this->getMedia();

        // id cache via filepath
        QUI\Cache\LongTermCache::set(
            $Media->getCacheDir() . 'filePathIds/' . md5($this->getAttribute('file')),
            $this->getId()
        );

        QUI::getEvents()->fireEvent('mediaSave', [$this]);
    }

    /**
     * Rename the File
     *
     * @param string $newName - The new name what the file get
     * @param QUI\Interfaces\Users\User|null $PermissionUser
     *
     * @throws Exception
     */
    public function rename(string $newName, null | QUI\Interfaces\Users\User $PermissionUser = null): void
    {
        $this->checkPermission('quiqqer.projects.media.edit', $PermissionUser);

        // spaces making too many problems
        // every space will be converted into a _
        // two _ will be converted into one _
        $newName = trim($newName, "_ \t\n\r\0\x0B"); // Trim the default characters and underscores
        $newName = str_replace(' ', '_', $newName);
        $newName = preg_replace('#(_){2,}#', "$1", $newName);

        $original = $this->getFullPath();
        $extension = QUI\Utils\StringHelper::pathinfo($original, PATHINFO_EXTENSION);
        $Parent = $this->getParent();

        if (mb_strtolower($extension) !== $extension) {
            $fileToUpper = $Parent->getFullPath() . $newName . '.' . $extension;
            $fileToLower = $Parent->getFullPath() . $newName . '.' . mb_strtolower($extension);

            if (!file_exists($fileToUpper) && file_exists($original)) {
                $fileToUpper = $original;
            }

            rename($fileToUpper, $fileToLower);

            QUI::getDataBaseConnection()->update(
                $this->Media->getTable(),
                ['file' => $Parent->getPath() . $newName . '.' . mb_strtolower($extension)],
                ['id' => $this->getId()]
            );

            $this->setAttribute('file', $Parent->getPath() . $newName . '.' . mb_strtolower($extension));

            $extension = mb_strtolower($extension);
            $original = $this->getFullPath();
        }

        $new_full_file = $Parent->getFullPath() . $newName . '.' . $extension;
        $new_file = $Parent->getPath() . $newName . '.' . $extension;

        if ($new_full_file === $original) {
            return;
        }

        if (empty($newName)) {
            return;
        }

        // throws the \QUI\Exception
        $fileParts = explode('/', $new_file);

        foreach ($fileParts as $filePart) {
            Utils::checkMediaName($filePart);
        }


        if ($Parent->childWithNameExists($newName)) {
            throw new Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.media.file.with.same.name.exists', [
                    'name' => $newName
                ]),
                ErrorCodes::FILE_ALREADY_EXISTS
            );
        }

        if ($Parent->fileWithNameExists($newName . '.' . $extension)) {
            throw new Exception(
                QUI::getLocale()->get('quiqqer/core', 'exception.media.file.with.same.name.exists', [
                    'name' => $newName
                ]),
                ErrorCodes::FILE_ALREADY_EXISTS
            );
        }


        if (method_exists($this, 'deleteCache')) {
            $this->deleteCache();
        }

        if (method_exists($this, 'deleteAdminCache')) {
            $this->deleteAdminCache();
        }


        $this->addToPathHistory($new_file);

        QUI::getDataBaseConnection()->update(
            $this->Media->getTable(),
            [
                'name' => $newName,
                'file' => $new_file,
                'pathHash' => md5($new_file),
                'pathHistory' => json_encode($this->getPathHistory())
            ],
            [
                'id' => $this->getId()
            ]
        );

        $this->setAttribute('name', $newName);
        $this->setAttribute('file', $new_file);

        QUIFile::move($original, $new_full_file);

        if (method_exists($this, 'createCache')) {
            $this->createCache();
        }

        QUI::getEvents()->fireEvent('mediaRename', [$this]);
    }

    // endregion
    // region Path and URL Methods
    public function addToPathHistory(string $path): void
    {
        $this->getPathHistory();

        $this->pathHistory[] = $path;
    }

    /**
     * @return array<int, string>|null
     */
    public function getPathHistory(): ?array
    {
        if ($this->pathHistory !== null) {
            return $this->pathHistory;
        }

        $pathHistory = $this->getAttribute('pathHistory');

        if (!empty($pathHistory)) {
            $pathHistory = json_decode($pathHistory, true);

            if (is_array($pathHistory)) {
                $this->pathHistory = $pathHistory;
            }
        }

        if (empty($this->pathHistory)) {
            $this->pathHistory[] = $this->getPath();
        }

        return $this->pathHistory;
    }

    /**
     * Return the effects of the item
     *
     * @return array<string, mixed>
     */
    public function getEffects(): array
    {
        if (is_array($this->effects)) {
            return $this->effects;
        }

        $effects = $this->getAttribute('image_effects');

        if (is_string($effects)) {
            $effects = json_decode($effects, true);
        }

        if (is_array($effects)) {
            $this->effects = $effects;
        } else {
            $this->effects = [];
        }

        return $this->effects;
    }

    /**
     * Set complete effects
     *
     * @param array<string, mixed> $effects
     */
    public function setEffects(array $effects = []): void
    {
        $this->effects = $effects;
    }

    // endregion
    // region Effect methods
    /**
     * @param array<array-key, mixed>|string $value
     */
    protected function saveMultilingualField(array | string $value): string
    {
        if (is_array($value)) {
            return (string)json_encode($value);
        }

        $value = json_decode($value, true);
        $current = QUI::getLocale()->getCurrent();

        if (!$value) {
            return (string)json_encode([
                $current => $value
            ]);
        }

        $result = [];

        foreach ($value as $key => $val) {
            if (QUI::getLocale()->existsLang($key)) {
                $result[$key] = $val;
            }
        }

        return (string)json_encode($result);
    }

    /**
     * Is the media item hidden?
     */
    public function isHidden(): bool
    {
        return (bool)$this->getAttribute('hidden');
    }

    /**
     * Set the title of this item
     *
     *      setShort('text')                 - set this short to all languages
     *      setShort('text', 'language')     - set this short to the wanted languages
     *      setShort(['de' => 'text', 'en' => 'text'])  - set the language array
     *
     * @param mixed ...$params
     */
    public function setShort(...$params): void
    {
        $this->setDescription($params);
    }

    //endregion
    //region Hidden
    /**
     * Return all Parents
     *
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    public function getParents(): array
    {
        $ids = $this->getParentIds();
        $parents = [];

        foreach ($ids as $id) {
            $parents[] = $this->Media->get($id);
        }

        return $parents;
    }

    /**
     * Return all parent ids
     *
     * @return array<int, int>
     */
    public function getParentIds(): array
    {
        if ($this->getId() === 1) {
            return [];
        }

        $parents = [];
        $id = $this->getId();
        $visited = [$id => true];

        while (($id = $this->Media->getParentIdFrom($id)) !== false) {
            if (isset($visited[$id])) {
                throw new QUI\Exception('Cycle detected in the media folder hierarchy.');
            }

            $visited[$id] = true;
            $parents[] = $id;
        }

        return array_reverse($parents);
    }

    /**
     * Returns information about a file path
     *
     * @param int|false $options - If present, specifies a specific element to be returned;
     *                                  one of:
     *                                  PATHINFO_DIRNAME, PATHINFO_BASENAME,
     *                                  PATHINFO_EXTENSION or PATHINFO_FILENAME.
     * @return array<string, mixed>|string
     */
    public function getPathinfo(false | int $options = false): array | string
    {
        if (!$options) {
            return pathinfo($this->getFullPath());
        }

        return pathinfo($this->getFullPath(), $options);
    }

    //endregion

    //region Permissions

    /**
     * Set an item effect
     *
     * @param string $effect - Name of the effect
     * @param float|integer|string $value - Value of the effect
     */
    public function setEffect(string $effect, float | int | string $value): void
    {
        $this->effects = $this->getEffects();
        $this->effects[$effect] = $value;
    }

    /**
     * Set the media item to hidden
     */
    public function setHidden(): void
    {
        $this->setAttribute('hidden', true);
    }

    /**
     * Set the media item from hidden to visible
     */
    public function setVisible(): void
    {
        $this->setAttribute('hidden', false);
    }

    /**
     * Are view permissions set for this item?
     */
    public function hasViewPermissionSet(): bool
    {
        return $this->hasPermissionsSet('quiqqer.projects.media.view');
    }

    /**
     * Are permissions set for this item?
     *
     * @param string $permission
     */
    public function hasPermissionsSet($permission): bool
    {
        if (Media::useMediaPermissions() === false) {
            return false;
        }

        $Manager = QUI::getPermissionManager();
        $permList = $Manager->getMediaPermissions($this);

        return !empty($permList[$permission]);
    }

    /**
     * Shortcut for QUI\Permissions\Permission::hasSitePermission
     */
    public function hasPermission(string $permission, null | QUI\Interfaces\Users\User $User = null): bool
    {
        if (Media::useMediaPermissions() === false) {
            return true;
        }


        $Manager = QUI::getPermissionManager();
        $permList = $Manager->getMediaPermissions($this);

        if (empty($permList[$permission])) {
            return true;
        }

        return QUI\Permissions\Permission::hasMediaPermission(
            $permission,
            $this,
            $User
        );
    }

    /**
     * Add a user to the permission
     *
     * @param string $permission - name of the permission
     * @param User $User - User Object
     * @param null|User $EditUser
     *
     * @throws Exception
     */
    public function addUserToPermission(
        QUI\Interfaces\Users\User $User,
        string $permission,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        Permission::addUserToMediaPermission($User, $this, $permission, $EditUser);
    }

    /**
     * add a group to the permission
     *
     * @throws Exception
     */
    public function addGroupToPermission(
        Group $Group,
        string $permission,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        Permission::addGroupToMediaPermission($Group, $this, $permission, $EditUser);
    }



    //endregion

    //region Path history

    /**
     * Remove a user from the permission
     *
     * @param string $permission - name of the permission
     * @param User $User - User Object#
     * @param null|User $EditUser
     *
     * @throws Exception
     */
    public function removeUserFromSitePermission(
        User $User,
        string $permission,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        Permission::removeUserFromMediaPermission($User, $this, $permission, $EditUser);
    }

    /**
     * Remove a group from the permission
     *
     * @throws Exception
     */
    public function removeGroupFromSitePermission(
        Group $Group,
        string $permission,
        null | QUI\Interfaces\Users\User $EditUser = null
    ): void {
        Permission::removeGroupFromMediaPermission($Group, $this, $permission, $EditUser);
    }

    //endregion
}
