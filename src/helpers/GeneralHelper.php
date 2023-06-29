<?php

/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\helpers;

use Craft;

use craft\base\LocalFsInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\events\ListVolumesEvent;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\fields\Tags;
use craft\fs\Local;
use craft\helpers\Assets;
use craft\helpers\Db;
use verbb\supertable\SuperTable;

use vnali\studio\models\Settings;
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
                    $blocks = SuperTable::$plugin->service->getBlockTypesByFieldId($superTableField->id);
                    if (isset($blocks[0])) {
                        $fieldLayout = $blocks[0]->getFieldLayout();
                        $superTableFields = $fieldLayout->getCustomFields();
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

            if ($container0Handle) {
                if ($container0Type == 'Matrix' && $container1Type == 'BlockType' && $container1Handle) {
                    if (isset($element->{$container0Handle})) {
                        $elementMatrixQuery = $element->{$container0Handle};
                        $elementMatrixBlocks = $elementMatrixQuery->all();
                        foreach ($elementMatrixBlocks as $key => $elementMatrixBlock) {
                            if ($elementMatrixBlock->type->handle == $container1Handle && isset($elementMatrixBlock->{$fieldHandle})) {
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

    public static function getElementKeywordsField($item, $mapping)
    {
        $keywordField = null;
        $keywordFieldUid = null;
        $keywordFieldHandle = null;
        $keywordFieldGroup = null;
        $keywordFieldType = null;
        if (isset($mapping[$item . 'Keywords']['type'])) {
            $keywordFieldType = $mapping[$item . 'Keywords']['type'];
        }
        if (isset($mapping[$item . 'Keywords']['field']) && $mapping[$item . 'Keywords']['field']) {
            $keywordFieldUid = $mapping[$item . 'Keywords']['field'];
            /** @var Tags|Categories|null $keywordField */
            $keywordField = Craft::$app->fields->getFieldByUid($keywordFieldUid);
            if ($keywordField) {
                $keywordFieldHandle = $keywordField->handle;
                if ($keywordFieldType == Tags::class) {
                    $source = $keywordField->source;
                    $sources = explode(':', $source);
                    $keywordFieldGroup = Craft::$app->tags->getTagGroupByUid($sources[1]);
                } elseif ($keywordFieldType == Categories::class) {
                    $source = $keywordField->source;
                    $sources = explode(':', $source);
                    $keywordFieldGroup = Craft::$app->categories->getGroupByUid($sources[1]);
                } elseif ($keywordFieldType == Entries::class) {
                    if ($keywordField->sources == '*') {
                        // select one Source
                        $sections = Craft::$app->sections->getEditableSections();
                        $keywordFieldGroup = $sections[0];
                    } elseif (is_array($keywordField->sources)) {
                        $source = $keywordField->sources[0];
                        $sources = explode(':', $source);
                        $keywordFieldGroup = Craft::$app->sections->getSectionByUid($sources[1]);
                    } else {
                        throw new ServerErrorHttpException('sources for entries not accepted');
                    }
                }
            }
        }
        return array($keywordField, $keywordFieldType, $keywordFieldHandle, $keywordFieldGroup);
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

    public static function getElementSubtitleField($item, $mapping)
    {
        $subtitleField = null;
        $subtitleFieldUid = null;
        if (isset($mapping[$item . 'Subtitle']['field']) && $mapping[$item . 'Subtitle']['field']) {
            $subtitleFieldUid = $mapping[$item . 'Subtitle']['field'];
            $subtitleField = Craft::$app->fields->getFieldByUid($subtitleFieldUid);
        }
        return $subtitleField;
    }

    public static function getElementSummaryField($item, $mapping)
    {
        $summaryField = null;
        $summaryFieldUid = null;
        if (isset($mapping[$item . 'Summary']['field']) && $mapping[$item . 'Summary']['field']) {
            $summaryFieldUid = $mapping[$item . 'Summary']['field'];
            $summaryField = Craft::$app->fields->getFieldByUid($summaryFieldUid);
        }
        return $summaryField;
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

    public static function UploadFile($content, $contentFile, $fileField, $fieldContainer, $element, $assetFilename, $blockId = null)
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
        $newAsset->filename = $assetFilename;
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

                $itemBlockType = null;
                if ($container0Handle) {
                    if ($container0Type && ($container0Type === 'SuperTable')) {
                        $superTableField = Craft::$app->fields->getFieldByHandle($container0Handle);
                        $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($superTableField->id);
                        $blockType = $blockTypes[0];
                        $itemBlockType = $blockType->id;
                    } elseif ($container0Type) {
                        $itemBlockType = $container1Handle;
                    }
                    $field = Craft::$app->fields->getFieldByHandle($container0Handle);
                    $existingMatrixQuery = $element->getFieldValue($container0Handle);
                    $serializedMatrix = $field->serializeValue($existingMatrixQuery, $element);
                    $sortOrder = array_keys($serializedMatrix);
                    //
                    if (isset($blockId) && isset($serializedMatrix[$blockId]['fields'][$fileField->handle])) {
                        $serializedMatrix[$blockId]['fields'][$fileField->handle] = [];
                        $serializedMatrix[$blockId]['fields'][$fileField->handle][] = $fileId;
                        $element->setFieldValue($container0Handle, $serializedMatrix);
                    } else {
                        $sortOrder[] = 'new:1';
                        $newBlock = [
                            'type' => $itemBlockType,
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

    public static function saveKeywords($keywordList, $fieldType, $fieldGroupId, $itemImportOptions = '', $itemCheck = false, $defaultKeywordsList = [])
    {
        $defaultKeywords = [];
        $keywordIds = [];
        $keywords = [];

        $keywordList = explode(', ', $keywordList);

        if (is_array($keywordList)) {
            foreach ($keywordList as $keyword) {
                if ($fieldType == Tags::class) {
                    $tagQuery = Tag::find();
                    $tag = $tagQuery
                        ->groupId($fieldGroupId)
                        ->title(Db::escapeParam($keyword))
                        ->unique()
                        ->one();

                    if (!$itemCheck && !$tag) {
                        $tag = new Tag();
                        $tag->groupId = $fieldGroupId;
                        $tag->title = $keyword;
                        Craft::$app->getElements()->saveElement($tag);
                    }
                    if ($tag) {
                        $keywordIds[] = $tag->id;
                        $keywords[] = $tag->title;
                    }
                } elseif ($fieldType == Categories::class) {
                    $category = \craft\elements\Category::find()
                        ->groupId($fieldGroupId)
                        ->title(Db::escapeParam($keyword))
                        ->unique()
                        ->one();

                    if (!$itemCheck && !$category) {
                        $category = new Category();
                        $category->groupId = $fieldGroupId;
                        $category->title = $keyword;
                        Craft::$app->getElements()->saveElement($category);
                    }
                    if ($category) {
                        $keywordIds[] = $category->id;
                        $keywords[] = $category->title;
                    }
                } elseif ($fieldType == Entries::class) {
                    $entry = \craft\elements\Entry::find()
                        ->sectionId($fieldGroupId)
                        ->title(Db::escapeParam($keyword))
                        ->unique()
                        ->one();

                    if (!$itemCheck && !$entry) {
                        $entry = new Entry();
                        $entry->sectionId = $fieldGroupId;
                        $entryTypes = Craft::$app->sections->getEntryTypesBySectionId($fieldGroupId);
                        $entry->typeId = $entryTypes[0]->id;
                        $entry->title = $keyword;
                        Craft::$app->getElements()->saveElement($entry);
                    }
                    if ($entry) {
                        $keywordIds[] = $entry->id;
                        $keywords[] = $entry->title;
                    }
                } else {
                }
            }
        }

        if ((!$keywordIds && $itemImportOptions == 'default-if-not-metadata') || $itemImportOptions == 'only-default' || $itemImportOptions == 'metadata-and-default') {
            $defaultKeywords = $defaultKeywordsList;
        }

        if (is_array($defaultKeywords)) {
            foreach ($defaultKeywords as $defaultKeyword) {
                if ($fieldType == Tags::class) {
                    $tag = Tag::find()
                        ->groupId($fieldGroupId)
                        ->id($defaultKeyword)
                        ->unique()
                        ->one();

                    if ($tag) {
                        if (!in_array($tag->id, $keywordIds)) {
                            $keywordIds[] = $tag->id;
                            $keywords[] = $tag->title;
                        }
                    }
                } elseif ($fieldType == Categories::class) {
                    $category = Category::find()
                        ->groupId($fieldGroupId)
                        ->id($defaultKeyword)
                        ->unique()
                        ->one();

                    if ($category) {
                        if (!in_array($category->id, $keywordIds)) {
                            $keywordIds[] = $category->id;
                            $keywords[] = $category->title;
                        }
                    }
                } elseif ($fieldType == Entries::class) {
                    $entry = Entry::find()
                        ->sectionId($fieldGroupId)
                        ->id($defaultKeyword)
                        ->unique()
                        ->one();
                    if ($entry) {
                        if (!in_array($entry->id, $keywordIds)) {
                            $keywordIds[] = $entry->id;
                            $keywords[] = $entry->title;
                        }
                    }
                }
            }
        }

        return array($keywordIds, $keywords);
    }

    /**
     * Return field based on item
     *
     * @param string $fieldItem
     * @return array
     */
    public static function getFieldDefinition(string $fieldItem): array
    {
        $field = null;
        $blockTypeHandle = null;
        switch ($fieldItem) {
            case 'chapter':
                $defaultHandle = 'episodeChapter';
                $defaultHandle2 = 'episodeData';
                $defaultBlockType = 'chapter';
                $handleAttribute = 'chapterField';
                $blockTypeAttribute = 'chapterBlockType';
                break;
            case 'soundbite':
                $defaultHandle = 'episodeSoundbite';
                $defaultHandle2 = 'episodeData';
                $defaultBlockType = 'soundbite';
                $handleAttribute = 'soundbiteField';
                $blockTypeAttribute = 'soundbiteBlockType';
                break;
            case 'funding':
                $defaultHandle = 'podcastFunding';
                $defaultHandle2 = 'podcastData';
                $defaultBlockType = 'funding';
                $handleAttribute = 'fundingField';
                $blockTypeAttribute = 'fundingBlockType';
                break;
            case 'podcastLicense':
                $defaultHandle = 'podcastLicense';
                $defaultHandle2 = 'podcastData';
                $defaultBlockType = 'license';
                $handleAttribute = 'podcastLicenseField';
                $blockTypeAttribute = 'podcastLicenseBlockType';
                break;
            case 'episodeLicense':
                $defaultHandle = 'episodeLicense';
                $defaultHandle2 = 'episodeData';
                $defaultBlockType = 'license';
                $handleAttribute = 'episodeLicenseField';
                $blockTypeAttribute = 'episodeLicenseBlockType';
                break;
            case 'podcastPerson':
                $defaultHandle = 'podcastPerson';
                $defaultHandle2 = 'podcastData';
                $defaultBlockType = 'person';
                $handleAttribute = 'podcastPersonField';
                $blockTypeAttribute = 'podcastPersonBlockType';
                break;
            case 'episodePerson':
                $defaultHandle = 'episodePerson';
                $defaultHandle2 = 'episodeData';
                $defaultBlockType = 'person';
                $handleAttribute = 'episodePersonField';
                $blockTypeAttribute = 'episodePersonBlockType';
                break;
            case 'transcript':
                $defaultHandle = 'episodeTranscript';
                $defaultHandle2 = 'episodeData';
                $defaultBlockType = 'transcript';
                $handleAttribute = 'transcriptField';
                $blockTypeAttribute = 'transcriptBlockType';
                break;
            case 'transcriptText':
                $defaultHandle = 'transcriptText';
                $handleAttribute = 'transcriptTextField';
                break;
            default:
                return array($field, $blockTypeHandle);
        }
        // First try to get specified field from plugin config
        /** @var Settings $settings */
        $settings = Studio::$plugin->getSettings();
        if ($settings->$handleAttribute) {
            $fieldHandle = $settings->$handleAttribute;
            $field = Craft::$app->fields->getFieldByHandle($fieldHandle);
            if (!$field) {
                return array($field, $blockTypeHandle);
            }
        }
        // If field is not found based on handle specified on plugin config, try to get based on default handle
        if ($field === null) {
            $field = Craft::$app->fields->getFieldByHandle($defaultHandle);
            if (!$field && isset($defaultHandle2)) {
                // Give default handle for matrix -defaultHandle2- a chance
                $field = Craft::$app->fields->getFieldByHandle($defaultHandle2);
                if (!is_object($field) || (is_object($field) && get_class($field) != Matrix::class)) {
                    $field = null;
                }
            }
        }
        // If field is a matrix, search for block type specified on config file. If not use default block type handle
        if ($field && get_class($field) == Matrix::class) {
            if (isset($blockTypeAttribute) && $settings->$blockTypeAttribute) {
                $blockTypeHandle = $settings->$blockTypeAttribute;
            } elseif (isset($defaultBlockType)) {
                $blockTypeHandle = $defaultBlockType;
            }
        }
        return array($field, $blockTypeHandle);
    }
}
