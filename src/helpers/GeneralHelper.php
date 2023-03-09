<?php
/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\helpers;

use Craft;

use craft\base\LocalFsInterface;
use craft\elements\Asset;
use craft\events\ListVolumesEvent;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Tags;
use craft\fs\Local;
use craft\helpers\Assets;
use verbb\supertable\SuperTable;

use vnali\studio\Studio;
use yii\web\BadRequestHttpException;
use yii\web\ServerErrorHttpException;

class GeneralHelper
{
    /**
     * Get Matrix and super tables.
     *
     * @param mixed $fieldLayout
     * @param string $containerTypes
     * @param string $item
     * @param bool $onlyContainer
     * @return array
     */

    public static function containers($fieldLayout, string $containerTypes = 'all',  string $item = null, bool $onlyContainer = true): array
    {
        $containers = [['value' => '', 'label' => 'select one container (Matrix/SuperTable)']];
        switch ($item) {
            case 'podcast':
                $fields = $fieldLayout->getCustomFields();
                break;
            case 'episode':
                $fields = $fieldLayout->getCustomFields();
                break;
            default:
                throw new ServerErrorHttpException('getting container for this item is not supported yet');
        }

        foreach ($fields as $field) {
            if (($containerTypes == 'all' || $containerTypes == 'craft\fields\Matrix') && get_class($field) == 'craft\fields\Matrix') {
                if ($onlyContainer) {
                    $containers[] = ['value' => $field->handle, 'label' => $field->name];
                } else {
                    $types = GeneralHelper::getContainerInside($field);
                    foreach ($types as $type) {
                        $containers[] = $type;
                    }
                }
            }
            /** @phpstan-ignore-next-line */
            if (($containerTypes == 'all' || $containerTypes == 'verbb\\supertable\\fields\\SuperTableField') && get_class($field) == 'verbb\\supertable\\fields\\SuperTableField') {
                if ($onlyContainer) {
                    $containers[] = ['value' => $field->handle, 'label' => $field->name];
                } else {
                    $types = GeneralHelper::getContainerInside($field);
                    foreach ($types as $type) {
                        $containers[] = $type;
                    }
                }
            }
        }
        return $containers;
    }

    /**
     * Get matrix and super inside, blocks and tables.
     *
     * @return array
     */
    public static function getContainerInside($field): array
    {
        $containers = [];
        if (get_class($field) == 'craft\fields\Matrix') {
            $blockTypes = Craft::$app->matrix->getBlockTypesByFieldId($field->id);
            foreach ($blockTypes as $blockType) {
                $containers[] = ['value' => $field->handle . '-Matrix|' . $blockType->handle . '-BlockType', 'label' => $field->name . '(M) | ' . $blockType->name . '(BT)'];
                $blockTypeFields = $blockType->getCustomFields();
                foreach ($blockTypeFields as $blockTypeField) {
                    if (get_class($blockTypeField) == 'craft\fields\Table') {
                        $containers[] = ['value' => $field->handle . '-Matrix|' . $blockType->handle . '-BlockType|' . $blockTypeField->handle . '-Table', 'label' => $field->name . '(M) | ' . $blockType->name . '(BT) | ' . $blockTypeField->name . ' (T)'];
                    }
                }
            }
            /** @phpstan-ignore-next-line */
        } elseif (get_class($field) == 'verbb\supertable\fields\SuperTableField') {
            if (class_exists('verbb\supertable\SuperTable')) {
                $blockTypes = SuperTable::$plugin->service->getBlockTypesByFieldId($field->id);
                foreach ($blockTypes as $blockType) {
                    $blockTypeFields = $blockType->getCustomFields();
                    $containers[] = ['value' => $field->handle . '-SuperTable|', 'label' => $field->name . '(ST)'];
                    foreach ($blockTypeFields as $blockTypeField) {
                        if (get_class($blockTypeField) == 'craft\fields\Table') {
                            $containers[] = ['value' => $field->handle . '-SuperTable|' . $blockTypeField->handle . '-Table', 'label' => $field->name . '(ST) | ' . $blockTypeField->name . ' (T)'];
                        }
                    }
                }
            }
        }
        return $containers;
    }

    /**
     * Filter fields based on field handle, field type, field container and field layout of plugin elements.
     *
     * @param string|null $fieldHandle
     * @param string|null $fieldType
     * @param string $fieldContainer
     * @param string $limitFieldsToLayout
     * @param string $item
     * @return array
     */
    public static function findField(string $fieldHandle = null, string $fieldType = null, string $fieldContainer, string $limitFieldsToLayout = null, string $item = null, $itemId): array
    {
        $fieldsArray = [
            ['value' => '', 'label' => 'Select field'],
        ];
        $fieldsArray = [];
        if ($limitFieldsToLayout == 'true') {
            switch ($item) {
                case 'episode':
                    $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($itemId);
                    $fieldLayout = $podcastFormatEpisode->getFieldLayout();
                    break;
                case 'podcast':
                    $podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatById($itemId);
                    $fieldLayout = $podcastFormat->getFieldLayout();
                    break;
                default:
                    throw new ServerErrorHttpException('finding field for this item is not supported yet');
            }
        }
        if (empty($fieldContainer)) {
            if ($fieldHandle) {
                if ($limitFieldsToLayout == 'true' && isset($fieldLayout)) {
                    $fieldItem = $fieldLayout->getFieldByHandle($fieldHandle);
                } else {
                    $fieldItem = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
                }
                if ($fieldItem) {
                    if ($fieldType) {
                        if (get_class($fieldItem) == $fieldType) {
                            $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->uid, 'label' => $fieldItem->handle];
                        }
                    } else {
                        $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->uid, 'label' => $fieldItem->handle];
                    }
                }
            } else {
                if ($limitFieldsToLayout == 'true' && isset($fieldLayout)) {
                    $fieldItems = $fieldLayout->getCustomFields();
                } else {
                    $fieldItems = Craft::$app->fields->getAllFields();
                }
                foreach ($fieldItems as $key => $fieldItem) {
                    if ($fieldType) {
                        if (get_class($fieldItem) == $fieldType) {
                            $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->uid, 'label' => $fieldItem->handle];
                        }
                    } else {
                        $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->uid, 'label' => $fieldItem->handle];
                    }
                }
            }
        } else {
            /** @var string|null $container0Type */
            $container0Type = null;
            /** @var string|null $container0Handle */
            $container0Handle = null;
            /** @var string|null $container1Type */
            $container1Type = null;
            /** @var string|null $container1Handle */
            $container1Handle = null;
            /** @var string|null $container2Type */
            $container2Type = null;
            /** @var string|null $container2Handle */
            $container2Handle = null;
            $fieldContainers = explode('|', $fieldContainer);

            foreach ($fieldContainers as $key => $fieldContainer) {
                $containerHandleVar = 'container' . $key . 'Handle';
                $containerTypeVar = 'container' . $key . 'Type';
                $container = explode('-', $fieldContainer);
                if (isset($container[0])) {
                    $$containerHandleVar = $container[0];
                }
                if (isset($container[1])) {
                    $$containerTypeVar = $container[1];
                }
            }

            if ($container0Type == 'Matrix' && $container1Type == 'BlockType') {
                $matrixField = Craft::$app->fields->getFieldByHandle($container0Handle);
                if ($matrixField) {
                    $matrixBlockTypes = Craft::$app->matrix->getBlockTypesByFieldId($matrixField->id);
                    foreach ($matrixBlockTypes as $key => $matrixBlockType) {
                        if ($matrixBlockType->handle == $container1Handle) {
                            $blockTypeFields = $matrixBlockType->getCustomFields();
                            foreach ($blockTypeFields as $blockTypeField) {
                                if (!$container2Type) {
                                    if ($fieldType) {
                                        if (get_class($blockTypeField) != $fieldType) {
                                            continue;
                                        }
                                    }
                                    if ($fieldHandle) {
                                        if ($blockTypeField->handle != $fieldHandle) {
                                            continue;
                                        }
                                    }
                                    $fieldsArray[] = ['type' => 'field', 'field' => $blockTypeField, 'value' => $blockTypeField->uid, 'label' => $blockTypeField->name];
                                } elseif ($container2Type == 'Table') {
                                    if (get_class($blockTypeField) == 'craft\fields\Table' && $blockTypeField->handle == $container2Handle) {
                                        foreach ($blockTypeField->columns as $key => $tableColumn) {
                                            if ($fieldType) {
                                                if ($tableColumn['type'] != TableHelper::fieldType2ColumnType($fieldType)) {
                                                    continue;
                                                }
                                            }
                                            if ($fieldHandle) {
                                                if ($tableColumn['handle'] != $fieldHandle) {
                                                    continue;
                                                }
                                            }
                                            if (!empty($tableColumn['handle'])) {
                                                $fieldsArray[] = ['type' => 'column', 'column' => $tableColumn, 'value' => $tableColumn['handle'], 'label' => $tableColumn['handle']];
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                            break;
                        }
                    }
                }
            } elseif ($container0Type == 'Table') {
                $fields = Craft::$app->fields->getAllFields();
                foreach ($fields as $field) {
                    if (get_class($field) == 'craft\fields\Table' && $container0Handle == $field->handle) {
                        // $fieldsArray = ['type' => 'field', 'field' => $fieldItem, 'value' => $field->handle, 'label' => $field->name];
                        foreach ($field->columns as $key => $tableColumn) {
                            if ($fieldType) {
                                if ($tableColumn['type'] != TableHelper::fieldType2ColumnType($fieldType)) {
                                    continue;
                                }
                            }
                            if ($fieldHandle) {
                                if ($tableColumn['handle'] != $fieldHandle) {
                                    continue;
                                }
                            }
                            if (!empty($tableColumn['handle'])) {
                                $fieldsArray[] = ['type' => 'column', 'column' => $tableColumn, 'value' => $tableColumn['handle'], 'label' => $tableColumn['handle']];
                            }
                        }
                        break;
                    }
                }
            } elseif ($container0Type == 'SuperTable') {
                $superTableField = Craft::$app->fields->getFieldByHandle($container0Handle);
                if ($superTableField) {
                    $blocks = SuperTable::$plugin->service->getBlockTypesByFieldId($superTableField->uid);
                    if (isset($blocks[0])) {
                        $fieldLayout = $blocks[0]->getFieldLayout();
                        $superTableFields = $fieldLayout->fields;
                        foreach ($superTableFields as $key => $fieldItem) {
                            if ($container1Type == 'Table') {
                                if (get_class($fieldItem) == 'craft\fields\Table' && $fieldItem->handle == $container1Handle) {
                                    foreach ($fieldItem->columns as $key => $field) {
                                        if ($fieldType) {
                                            if ($field['type'] != TableHelper::fieldType2ColumnType($fieldType)) {
                                                continue;
                                            }
                                        }
                                        if ($fieldHandle) {
                                            if ($field['handle'] != $fieldHandle) {
                                                continue;
                                            }
                                        }
                                        if (!empty($field['handle'])) {
                                            $fieldsArray[] = ['type' => 'column', 'table' => $fieldItem, 'column' => $field, 'value' => $field['handle'], 'label' => $field['handle']];
                                        }
                                    }
                                    break;
                                }
                            } else {
                                if ($fieldHandle) {
                                    if ($fieldItem->handle == $fieldHandle) {
                                        if ($fieldType) {
                                            if (get_class($fieldItem) == $fieldType) {
                                                $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->uid, 'label' => $fieldItem->name];
                                            }
                                        } else {
                                            $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->uid, 'label' => $fieldItem->name];
                                        }
                                    }
                                } else {
                                    if ($fieldType) {
                                        if (get_class($fieldItem) == $fieldType) {
                                            $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->uid, 'label' => $fieldItem->name];
                                        }
                                    } else {
                                        $fieldsArray[] = ['type' => 'field', 'field' => $fieldItem, 'value' => $fieldItem->uid, 'label' => $fieldItem->name];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $fieldsArray;
    }

    /**
     * Get element Resource
     */
    public static function getElementAsset($element, $fieldContainer, $fieldHandle)
    {
        $asset = null;
        $assetFilename = null;
        $assetFilePath = null;
        $assetFileUrl = null;
        $blockId = null;

        if (!$fieldContainer && $fieldHandle) {
            if (isset($element->{$fieldHandle})) {
                if (is_object($element->{$fieldHandle}) && get_class($element->{$fieldHandle}) == 'craft\elements\db\AssetQuery') {
                    $elementQuery = $element->{$fieldHandle};
                    $elementItem = $elementQuery->all();
                    if (isset($elementItem[0])) {
                        $assetId = $elementItem[0]->id;
                        if ($assetId) {
                            $asset = Craft::$app->getAssets()->getAssetById($assetId);
                            $vol = $asset->getVolume();
                            $fs = $vol->getFs();
                            $volumeUrl = $fs->url;
                            $folderPath = $asset->getFolder()->path;
                            $assetFilename = $asset->filename;
                            // TODO: what if fs is not local?
                            if ($fs instanceof LocalFsInterface) {
                                /** @var Local $fs */
                                $volumePath = $fs->path;
                                $assetFilePath = Craft::getAlias($volumePath) . '/' . $folderPath . $assetFilename;
                                $assetFileUrl = Craft::getAlias($volumeUrl) . '/' . $folderPath . $assetFilename;
                            } else {
                                $assetFileUrl = $asset->getUrl();
                            }
                        }
                    }
                } else {
                    $assetFileUrl = $element->{$fieldHandle};
                }
            }
        } else {
            /** @var string|null $container0Type */
            $container0Type = null;
            /** @var string|null $container0Handle */
            $container0Handle = null;
            /** @var string|null $container1Type */
            $container1Type = null;

            $fieldContainers = explode('|', $fieldContainer);
            foreach ($fieldContainers as $key => $fieldContainer) {
                $containerHandleVar = 'container' . $key . 'Handle';
                $containerTypeVar = 'container' . $key . 'Type';
                $container = explode('-', $fieldContainer);
                if (isset($container[0])) {
                    $$containerHandleVar = $container[0];
                }
                if (isset($container[1])) {
                    $$containerTypeVar = $container[1];
                }
            }

            if ($container0Handle) {
                if ($container0Type == 'Matrix' && $container1Type == 'BlockType') {
                    if (isset($element->{$container0Handle})) {
                        $elementMatrixQuery = $element->{$container0Handle};
                        $elementMatrixBlocks = $elementMatrixQuery->all();
                        foreach ($elementMatrixBlocks as $key => $elementMatrixBlock) {
                            if (isset($elementMatrixBlock->{$fieldHandle})) {
                                $matrixBlockField = $elementMatrixBlock->{$fieldHandle};
                                if (is_object($matrixBlockField) && get_class($matrixBlockField) == 'craft\elements\db\AssetQuery') {
                                    $elementItem = $matrixBlockField->all();
                                    if (isset($elementItem[0])) {
                                        $assetId = $elementItem[0]->id;
                                        if ($assetId) {
                                            $asset = Craft::$app->assets->getAssetById($assetId);
                                            $vol = $asset->getVolume();
                                            $fs = $vol->getFs();
                                            $volumeUrl = $fs->url;
                                            $folderPath = $asset->getFolder()->path;
                                            $assetFilename = $asset->filename;
                                            if ($fs instanceof LocalFsInterface) {
                                                /** @var Local $fs */
                                                $volumePath = $fs->path;
                                                $assetFilePath = Craft::getAlias($volumePath) . '/' . $folderPath . $assetFilename;
                                            }
                                            //rawurlencode in case of player doesn't support space
                                            $assetFileUrl = Craft::getAlias($volumeUrl) . '/' . rawurlencode($folderPath . $assetFilename);
                                            $blockId = $elementMatrixBlock->id;
                                            break;
                                        }
                                    }
                                } else {
                                    $assetFileUrl = $matrixBlockField;
                                }
                            }
                        }
                    }
                } elseif ($container0Type == 'SuperTable') {
                    if (isset($element->{$container0Handle})) {
                        $elementSTQuery = $element->{$container0Handle};
                        $elementSTBlocks = $elementSTQuery->all();
                        foreach ($elementSTBlocks as $key => $elementSTBlock) {
                            if (isset($elementSTBlock->{$fieldHandle})) {
                                $elementBlockField = $elementSTBlock->{$fieldHandle};
                                if (is_object($elementBlockField) && get_class($elementBlockField) == 'craft\elements\db\AssetQuery') {
                                    $elementItem = $elementBlockField->all();
                                    if (isset($elementItem[0])) {
                                        $assetId = $elementItem[0]->id;
                                        if ($assetId) {
                                            $asset = Craft::$app->assets->getAssetById($assetId);
                                            $vol = $asset->getVolume();
                                            $fs = $vol->getFs();
                                            $volumeUrl = $fs->url;
                                            $folderPath = $asset->getFolder()->path;
                                            $assetFilename = $asset->filename;
                                            if ($fs instanceof LocalFsInterface) {
                                                /** @var Local $fs */
                                                $volumePath = $fs->path;
                                                $assetFilePath = Craft::getAlias($volumePath) . '/' . $folderPath . $assetFilename;
                                            }
                                            $assetFileUrl = Craft::getAlias($volumeUrl) . '/' . $folderPath . $assetFilename;
                                            $blockId = $elementSTBlock->id;
                                            break;
                                        }
                                    }
                                } else {
                                    $assetFileUrl = $elementBlockField;
                                }
                            }
                        }
                    }
                }
            }
        }

        return array($assetFilename, $assetFilePath, $assetFileUrl, $blockId, $asset);
    }

    public static function getElementGenreField($item, $mapping)
    {
        // Genres
        $genreFieldUid = null;
        $genreFieldHandle = null;
        $genreFieldGroup = null;
        $genreFieldType = null;
        if (isset($mapping[$item . 'Genre']['type'])) {
            $genreFieldType = $mapping[$item . 'Genre']['type'];
        }
        if (isset($mapping[$item . 'Genre']['field']) && $mapping[$item . 'Genre']['field']) {
            $genreFieldUid = $mapping[$item . 'Genre']['field'];
            /** @var Tags|Categories|null $genreField */
            $genreField = Craft::$app->fields->getFieldByUid($genreFieldUid);
            if ($genreField) {
                $genreFieldHandle = $genreField->handle;
                if ($genreFieldType == Tags::class) {
                    $source = $genreField->source;
                    $sources = explode(':', $source);
                    $genreFieldGroup = Craft::$app->tags->getTagGroupByUid($sources[1]);
                } elseif ($genreFieldType == Categories::class) {
                    $source = $genreField->source;
                    $sources = explode(':', $source);
                    $genreFieldGroup = Craft::$app->categories->getGroupByUid($sources[1]);
                } elseif ($genreFieldType == Entries::class) {
                    if ($genreField->sources == '*') {
                        // select one Source
                        $sections = Craft::$app->sections->getEditableSections();
                        $genreFieldGroup = $sections[0];
                    } elseif (is_array($genreField->sources)) {
                        $source = $genreField->sources[0];
                        $sources = explode(':', $source);
                        $genreFieldGroup = Craft::$app->sections->getSectionByUid($sources[1]);
                    } else {
                        throw new ServerErrorHttpException('sources for entries not accepted');
                    }
                }
            }
        }
        return array($genreFieldType, $genreFieldHandle, $genreFieldGroup);
    }

    public static function getElementImageField($item, $mapping)
    {
        $imageField = null;
        $imageFieldUid = null;
        $imageFieldContainer = null;
        if (isset($mapping[$item . 'Image']['container'])) {
            $imageFieldContainer = $mapping[$item . 'Image']['container'];
        }
        if (isset($mapping[$item . 'Image']['field']) && $mapping[$item . 'Image']['field']) {
            $imageFieldUid = $mapping[$item . 'Image']['field'];
            $imageField = Craft::$app->fields->getFieldByUid($imageFieldUid);
        }
        return array($imageField, $imageFieldContainer);
    }

    public static function getElementTagField($item, $mapping)
    {
        $tagField = null;
        $tagFieldUid = null;
        $tagGroup = null;
        if (isset($mapping[$item . 'Tag']['field']) && $mapping[$item . 'Tag']['field']) {
            $tagFieldUid = $mapping[$item . 'Tag']['field'];
            /** @var Tags|null $tagField */
            $tagField = Craft::$app->fields->getFieldByUid($tagFieldUid);
            if ($tagField) {
                $source = $tagField->source;
                $sources = explode(':', $source);
                $tagGroup = Craft::$app->tags->getTagGroupByUid($sources[1]);
            }
        }

        return array($tagGroup, $tagField);
    }

    public static function getElementCategoryField($item, $mapping)
    {
        $categoryField = null;
        $categoryFieldUid = null;
        $categoryGroup = null;
        if (isset($mapping[$item . 'Category']['field']) && isset($mapping[$item . 'Category']['field'])) {
            $categoryFieldUid = $mapping[$item . 'Category']['field'];
            /** @var Categories|Entries|null $categoryField */
            $categoryField = Craft::$app->fields->getFieldByUid($categoryFieldUid);
            if ($categoryField) {
                if (get_class($categoryField) == Categories::class) {
                    $source = $categoryField->source;
                    $sources = explode(':', $source);
                    $categoryGroup = Craft::$app->categories->getGroupByUid($sources[1]);
                } elseif (get_class($categoryField) == Entries::class) {
                    if ($categoryField->sources == '*') {
                        // select one Source
                        $sections = Craft::$app->sections->getEditableSections();
                        $categoryGroup = $sections[0];
                    } elseif (is_array($categoryField->sources)) {
                        $source = $categoryField->sources[0];
                        $sources = explode(':', $source);
                        $categoryGroup = Craft::$app->sections->getSectionByUid($sources[1]);
                    } else {
                        throw new ServerErrorHttpException('sources for entries not accepted');
                    }
                }
            }
        }

        return array($categoryGroup, $categoryField);
    }

    public static function getElementYearField($item, $mapping)
    {
        $yearFieldUid = null;
        $yearField = null;
        if (isset($mapping[$item . 'Year']['field']) && $mapping[$item . 'Year']['field']) {
            $yearFieldUid = $mapping[$item . 'Year']['field'];
            $yearField = Craft::$app->fields->getFieldByUid($yearFieldUid);
        }
        return array($yearField);
    }

    public static function getElementPubDateField($item, $mapping)
    {
        $postDateFieldUid = null;
        $postDateField = null;
        if (isset($mapping[$item . 'PubDate']['field']) && $mapping[$item . 'PubDate']['field']) {
            $postDateFieldUid = $mapping[$item . 'PubDate']['field'];
            $postDateField = Craft::$app->fields->getFieldByUid($postDateFieldUid);
        }
        return array($postDateField);
    }

    public static function getElementDescriptionField($item, $mapping)
    {
        $descriptionField = null;
        $descriptionFieldUid = null;
        if (isset($mapping[$item . 'Description']['field']) && $mapping[$item . 'Description']['field']) {
            $descriptionFieldUid = $mapping[$item . 'Description']['field'];
            $descriptionField = Craft::$app->fields->getFieldByUid($descriptionFieldUid);
        }
        return $descriptionField;
    }

    public static function getElementContentEncodedField($item, $mapping)
    {
        $contentEncodedField = null;
        $contentEncodedFieldUid = null;
        if (isset($mapping[$item . 'ContentEncoded']['field']) && $mapping[$item . 'ContentEncoded']['field']) {
            $contentEncodedFieldUid = $mapping[$item . 'ContentEncoded']['field'];
            $contentEncodedField = Craft::$app->fields->getFieldByUid($contentEncodedFieldUid);
        }
        return $contentEncodedField;
    }

    public static function UploadFile($content, $contentFile, $fileField, $fieldContainer, $element, $assetFilename, $ext, $blockId = null)
    {
        // If there is a fetched content, create a temp file.
        if ($content) {
            $contentFile = Assets::tempFilePath();
            file_put_contents($contentFile, $content);
        }

        $folderId = $fileField->resolveDynamicPathToFolderId($element);
        if (empty($folderId)) {
            throw new BadRequestHttpException('The target destination provided for uploading is not valid');
        }

        $folder = Craft::$app->getAssets()->findFolder(['id' => $folderId]);

        if (!$folder) {
            throw new BadRequestHttpException('The target folder provided for uploading is not valid');
        }

        $newAsset = new Asset();
        $newAsset->avoidFilenameConflicts = true;
        $newAsset->setScenario(Asset::SCENARIO_CREATE);
        $newAsset->tempFilePath = $contentFile;
        $assetFilenameArray = explode('.', $assetFilename);
        $newAsset->filename = $assetFilenameArray[0] . '.' . $ext;
        $newAsset->newFolderId = $folder->id;
        $newAsset->setVolumeId($folder->volumeId);

        if (Craft::$app->getElements()->saveElement($newAsset)) {
            $fileId = $newAsset->id;
            if (!$fieldContainer) {
                $element->{$fileField->handle} = [$fileId];
            } else {
                /** @var string|null $container0Type */
                $container0Type = null;
                /** @var string|null $container0Handle */
                $container0Handle = null;
                /** @var string|null $container1Type */
                $container1Type = null;
                /** @var string|null $container1Handle */
                $container1Handle = null;
                $fieldContainers = explode('|', $fieldContainer);
                foreach ($fieldContainers as $key => $fieldContainer) {
                    $containerHandleVar = 'container' . $key . 'Handle';
                    $containerTypeVar = 'container' . $key . 'Type';
                    $container = explode('-', $fieldContainer);
                    if (isset($container[0])) {
                        $$containerHandleVar = $container[0];
                    }
                    if (isset($container[1])) {
                        $$containerTypeVar = $container[1];
                    }
                }

                if ($container0Handle && $container1Handle) {
                    if ($container0Type == 'Matrix' && $container1Type == 'BlockType') {
                        $field = Craft::$app->fields->getFieldByHandle($container0Handle);
                        $existingMatrixQuery = $element->getFieldValue($container0Handle);
                        $serializedMatrix = $field->serializeValue($existingMatrixQuery, $element);

                        $sortOrder = array_keys($serializedMatrix);
                        //
                        if (isset($blockId) && isset($serializedMatrix[$blockId]['fields'][$fileField->handle])) {
                            $serializedMatrix[$blockId]['fields'][$fileField->handle][] = $fileId;
                            $element->setFieldValue($container0Handle, $serializedMatrix);
                        } else {
                            $sortOrder[] = 'new:1';
                            $newBlock = [
                                'type' => $container1Handle,
                                'fields' => [
                                    $fileField->handle => [$fileId],
                                ],
                            ];
                            $serializedMatrix['new:1'] = $newBlock;
                            $element->setFieldValue($container0Handle, [
                                'sortOrder' => $sortOrder,
                                'blocks' => $serializedMatrix,
                            ]);
                        }
                    } elseif ($container0Type == 'SuperTable') {
                        $field = Craft::$app->fields->getFieldByHandle($container0Handle);
                        $existingStQuery = $element->getFieldValue($container0Handle);
                        $serializedSt = $field->serializeValue($existingStQuery, $element);

                        $sortOrder = array_keys($serializedSt);
                        //
                        if (isset($blockId) && isset($serializedSt[$blockId]['fields'][$fileField->handle])) {
                            $serializedSt[$blockId]['fields'][$fileField->handle][] = $fileId;
                            $element->setFieldValue($container0Handle, $serializedSt);
                        } else {
                            $sortOrder[] = 'new:1';
                            $newBlock = [
                                'type' => $container1Handle,
                                'fields' => [
                                    $fileField->handle => [$fileId],
                                ],
                            ];
                            $serializedSt['new:1'] = $newBlock;
                            $element->setFieldValue($container0Handle, [
                                'sortOrder' => $sortOrder,
                                'blocks' => $serializedSt,
                            ]);
                        }
                    }
                }
            }
        } else {
            $errors = array_values($newAsset->getFirstErrors());
            if (isset($errors[0])) {
                Craft::$app->getSession()->setError(Craft::t('studio', 'error on extracting image from file: ') . ' ' . Craft::t('studio', $errors[0]));
            }
        }
        return $element;
    }

    /**
     * List allowed volumes for element indexing
     *
     * @param ListVolumesEvent $event
     * @return void
     */
    public static function listVolumes(ListVolumesEvent $event): void
    {
        $volumes = [];
        foreach (Craft::$app->volumes->getAllVolumes() as $volumeItem) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            // Allow only volumes that user has access
            if ($currentUser->can('saveAssets:' . $volumeItem->uid)) {
                $volumes[] = $volumeItem;
            }
        }
        $event->volumes = $volumes;
    }
}
