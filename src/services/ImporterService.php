<?php

/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\LocalFsInterface;
use craft\elements\Asset;
use craft\fs\Local;
use craft\helpers\Assets;
use craft\helpers\StringHelper;

use Imagine\Exception\NotSupportedException;
use InvalidArgumentException;

use verbb\supertable\SuperTable;

use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\elements\Podcast;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\helpers\Id3;
use vnali\studio\helpers\Time;

use yii\web\BadRequestHttpException;

class ImporterService extends Component
{
    /**
     * Create items (currently only episodes) from assets index
     *
     * @param ElementInterface $element
     * @param string $item
     * @param array $importSetting
     * @param array $siteIds siteIds to propagate the element
     * @return void
     */
    public function importByAssetIndex(ElementInterface $element, string $item, array $importSetting, array $siteIds): void
    {
        // PHP Stan fix
        if (!$element instanceof Asset) {
            throw new InvalidArgumentException('Import item can only be used for asset elements.');
        }

        if ($item == 'episode') {
            $podcastId = $importSetting['podcastId'];
            $podcast = Podcast::find()->id($podcastId)->siteId('*')->status(null)->one();
            /** @var Podcast|null $podcast */
            $podcastFormat = $podcast->getPodcastFormat();
            $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
            $itemElement = new EpisodeElement();
            $itemElement->podcastId = $podcastId;
            $fieldLayout = $itemElement->getFieldLayout();
            if ($fieldLayout->isFieldIncluded('episodeGUID')) {
                $itemElement->episodeGUID = StringHelper::UUID();
            }
            $mapping = json_decode($podcastFormatEpisode->mapping, true);
        } else {
            throw new NotSupportedException('not supported' . $item);
        }
        $sitesSettings = $podcastFormat->getSiteSettings();
        if (empty($sitesSettings)) {
            Craft::warning('studio', "You should have set $item site settings");
            return;
        }

        $itemFieldId = null;
        $itemFieldContainer = null;
        $fieldsService = Craft::$app->getFields();

        if (isset($mapping['mainAsset']['container'])) {
            $itemFieldContainer = $mapping['mainAsset']['container'];
        }
        if (isset($mapping['mainAsset']['field']) && $mapping['mainAsset']['field']) {
            $itemFieldId = $mapping['mainAsset']['field'];
            $itemField = $fieldsService->getFieldByUid($itemFieldId);
            if ($itemField) {
                $itemFieldHandle = $itemField->handle;
            }
        }

        if (!isset($itemFieldHandle)) {
            craft::warning("$item file is not specified in setting");
            return;
        }

        list($imageField, $imageFieldContainer) = GeneralHelper::getElementImageField($item, $mapping);

        list($keywordField, $keywordFieldType, $keywordFieldHandle, $keywordFieldGroup) = GeneralHelper::getElementKeywordsField('episode', $mapping);

        // Genres
        list($genreFieldType, $genreFieldHandle, $genreFieldGroup) = GeneralHelper::getElementGenreField($item, $mapping);

        // Get file meta info based on being file is local or remote
        $fs = $element->getVolume()->getFs();

        if ($fs instanceof LocalFsInterface) {
            /** @var Local $fs */
            $volumePath = $fs->path;
            $path = Craft::getAlias($volumePath . '/' . $element->getPath());
            $type = 'local';
        } else {
            $path = $element->getUrl();
            $type = 'remote';
        }
        $fileInfo = Id3::analyze($type, $path);

        if ($fieldLayout->isFieldIncluded('duration')) {
            if (isset($fileInfo['playtime_string'])) {
                $duration = $fileInfo['playtime_string'];
                if ($duration) {
                    if (!ctype_digit((string)$duration)) {
                        $duration = Time::time_to_sec($duration);
                    }
                    $itemElement->duration = $duration;
                }
            }
        }

        if (isset($fileInfo['tags']['id3v2']['title'][0])) {
            $title = $fileInfo['tags']['id3v2']['title'][0];
        }

        if (!isset($title) || !$title) {
            $title = $element->title;
        }

        $itemElement->title = $title;

        list($pubDateField) = GeneralHelper::getElementPubDateField($item, $mapping);

        if (isset($pubDateField)) {
            $pubDate = null;
            $pubDateOption = $importSetting['pubDateOption'];
            if ($pubDateOption == 'only-default') {
                $pubDate = $importSetting['defaultPubDate'];
            } elseif ($pubDateOption == 'only-metadata') {
                $pubDate = Id3::getYear($fileInfo);
            } elseif ($pubDateOption == 'default-if-not-metadata') {
                $pubDate = Id3::getYear($fileInfo);
                if (!$pubDate) {
                    $pubDate = $importSetting['defaultPubDate'];
                }
            }
            $itemElement->{$pubDateField->handle} = $pubDate;
        }

        if ($fieldLayout->isFieldIncluded('episodeNumber')) {
            if (isset($fileInfo['tags']['id3v2']['track_number'][0])) {
                $track = trim($fileInfo['tags']['id3v2']['track_number'][0]);
                $itemElement->episodeNumber = (int)$track;
            }
        }

        $genreIds = [];
        if ($genreFieldHandle) {
            $tagImportOptions = $importSetting['genreImportOption'];
            $tagImportCheck = $importSetting['genreImportCheck'];
            $defaultGenres = $importSetting['defaultGenres'];
            if ($tagImportOptions) {
                list($genreIds,) = Id3::getGenres($fileInfo, $genreFieldType, $genreFieldGroup->id, $tagImportOptions, $tagImportCheck, $defaultGenres);
            }
        }

        // Genre field might be overlapped with keyword field
        if ($genreFieldHandle != $keywordFieldHandle) {
            $columns = [];
            if ($genreFieldHandle) {
                $columns[$genreFieldHandle] = $genreIds;
            }
            if ($keywordFieldHandle && isset($importSetting['keywordsOnImport'])) {
                $columns[$keywordFieldHandle] = $importSetting['keywordsOnImport'];
            }
            $itemElement->setFieldValues($columns);
        } else {
            $columns = [];
            if ($genreFieldHandle) {
                if (isset($importSetting['keywordsOnImport']) && is_array($importSetting['keywordsOnImport'])) {
                    foreach ($importSetting['keywordsOnImport'] as $keywordId) {
                        if (!in_array($keywordId, $genreIds)) {
                            $genreIds[] = $keywordId;
                        }
                    }
                }
                $columns[$genreFieldHandle] = $genreIds;
            }
            $itemElement->setFieldValues($columns);
        }

        // Set site status for episode
        $siteId = null;
        $siteStatus = [];
        // $siteStatus[$key] = $siteSetting[$item . 'EnabledByDefault'];
        // To Prevent unwanted content on RSS or site, we force disabled status to be checked by admin first
        // Also if we use enabled status by default, there is a chance that doesn't save due to validation error like required rules
        foreach ($sitesSettings as $key => $siteSettings) {
            if (in_array($key, $siteIds)) {
                if (!$siteId) {
                    $siteId = $key;
                }
                //$siteStatus[$key] = $siteSettings['episodeEnabledByDefault'];
                $siteStatus[$key] = false;
            }
        }
        if (!$siteId) {
            Craft::warning("not any site is enabled for $item");
        }
        $itemElement->siteId = $siteId;
        $itemElement->setEnabledForSite($siteStatus);

        /** @var string|null $container0Type */
        $container0Type = null;
        /** @var string|null $container0Handle */
        $container0Handle = null;
        /** @var string|null $container1Handle */
        $container1Handle = null;
        if (!$itemFieldContainer) {
            // TODO: check if we can set an asset to an item field which volume of that asset is not supported by field volume
            $itemElement->{$itemFieldHandle} = [$element->id];
        } else {
            $fieldContainers = explode('|', $itemFieldContainer);
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
        }

        // Get image Id3 meta data from audio and create a Craft asset
        if (isset($imageField)) {
            $imageIds = [];
            $useDefaultImage = false;
            $imageOption = $importSetting['imageOption'];
            // Do we need to read image metadata? -based on image import option-
            if ($imageOption == 'only-metadata' || $imageOption == 'default-if-not-metadata') {
                $assetFilename = $element->filename;
                if (isset($fileInfo)) {
                    list($img, $mime, $ext) = Id3::getImage($fileInfo);
                    if ($img) {
                        $tempFile = Assets::tempFilePath();
                        file_put_contents($tempFile, $img);

                        $folderId = $imageField->resolveDynamicPathToFolderId($itemElement);
                        if (empty($folderId)) {
                            throw new BadRequestHttpException('The target destination provided for uploading is not valid');
                        }

                        $folder = Craft::$app->getAssets()->findFolder(['id' => $folderId]);

                        if (!$folder) {
                            throw new BadRequestHttpException('The target folder provided for uploading is not valid');
                        }

                        $imgAsset = new Asset();
                        $imgAsset->avoidFilenameConflicts = true;
                        $imgAsset->setScenario(Asset::SCENARIO_CREATE);
                        $imgAsset->tempFilePath = $tempFile;
                        $assetFilenameArray = explode('.', $assetFilename);
                        $imgAsset->filename = $assetFilenameArray[0] . '.' . $ext;
                        $imgAsset->newFolderId = $folder->id;
                        $imgAsset->setVolumeId($folder->volumeId);

                        if (Craft::$app->getElements()->saveElement($imgAsset)) {
                            $imageId = $imgAsset->id;
                            $imageIds[] = $imageId;
                        } else {
                            craft::Warning('can not save save image asset' . $assetFilename . json_encode($imgAsset));
                        }
                    } elseif ($imageOption == 'default-if-not-metadata') {
                        $useDefaultImage = true;
                        craft::Warning('no image from meta for ' . $assetFilename);
                    }
                } elseif ($imageOption == 'default-if-not-metadata') {
                    $useDefaultImage = true;
                }
            }

            // If image import option is set to default image, or set to default if not metadata and there is no image in metadata
            if (
                ($useDefaultImage || $imageOption == 'only-default') &&
                isset($importSetting['defaultImage']) && is_array($importSetting['defaultImage'])
            ) {
                foreach ($importSetting['defaultImage'] as $defaultElementImg) {
                    $elementImg = \craft\elements\Asset::find()
                        ->id($defaultElementImg)
                        ->one();
                    if ($elementImg) {
                        $imageIds[] = $elementImg->id;
                    }
                }
            }

            // Set created image assets to specified custom field, if image import option allow import and there is any image to import
            /** @var string|null $imgContainer0Type */
            $imgContainer0Type = null;
            /** @var string|null $imgContainer0Handle */
            $imgContainer0Handle = null;
            /** @var string|null $imgContainer1Type */
            $imgContainer1Type = null;
            /** @var string|null $imgContainer1Handle */
            $imgContainer1Handle = null;
            if ($imageIds && $imageOption) {
                if (!$imageFieldContainer) {
                    $itemElement->{$imageField->handle} = $imageIds;
                } else {
                    $fieldContainers = explode('|', $imageFieldContainer);
                    foreach ($fieldContainers as $key => $fieldContainer) {
                        $containerHandleVar = 'imgContainer' . $key . 'Handle';
                        $containerTypeVar = 'imgContainer' . $key . 'Type';
                        $container = explode('-', $fieldContainer);
                        if (isset($container[0])) {
                            $$containerHandleVar = $container[0];
                        }
                        if (isset($container[1])) {
                            $$containerTypeVar = $container[1];
                        }
                    }
                }
            }
        }

        $itemBlockType = null;
        $imgBlockType = null;
        if ($container0Handle || isset($imgContainer0Handle)) {
            if ($container0Type && ($container0Type === 'SuperTable')) {
                $superTableField = $fieldsService->getFieldByHandle($container0Handle);
                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($superTableField->id);
                $blockType = $blockTypes[0];
                $itemBlockType = $blockType->id;
            } elseif ($container0Type) {
                $itemBlockType = $container1Handle;
            }
            if (isset($imgContainer0Handle) && isset($imgContainer0Type) && ($imgContainer0Type === 'SuperTable')) {
                $superTableField = $fieldsService->getFieldByHandle($imgContainer0Handle);
                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($superTableField->id);
                $blockType = $blockTypes[0];
                $imgBlockType = $blockType->id;
            } elseif (isset($imgContainer1Handle) && isset($imgContainer0Type)) {
                $imgBlockType = $imgContainer1Handle;
            }
            if (isset($itemBlockType) && ($itemBlockType == $imgBlockType)) {
                $data = [];
                $containerFields = [];
                if (isset($imgContainer0Type) && isset($imageIds) && isset($imageOption) && $imageOption) {
                    $containerFields[$imageField->handle] = $imageIds;
                }
                if (isset($container0Type)) {
                    $containerFields[$itemFieldHandle] = [$element->id];
                }
                $data['new1'] = [
                    'type' => $itemBlockType,
                    'fields' => $containerFields,
                ];
                $itemElement->setFieldValue($container0Handle, $data);
            } elseif (isset($itemBlockType) || isset($imgBlockType)) {
                $data = [];
                if (isset($itemBlockType)) {
                    $data['new1'] = [
                        'type' => $itemBlockType,
                        'fields' => [
                            $itemFieldHandle => [$element->id],
                        ],
                    ];
                }
                if (isset($imgBlockType) && isset($imageIds) && isset($imageOption) && $imageOption) {
                    $data['new2'] = [
                        'type' => $imgBlockType,
                        'fields' => [
                            $imageField->handle => $imageIds,
                        ],
                    ];
                }
                if (isset($imgContainer0Handle) && $container0Handle === $imgContainer0Handle) {
                    $itemElement->setFieldValue($container0Handle, $data);
                } else {
                    if (isset($container0Handle)) {
                        $itemElement->setFieldValue($container0Handle, $data);
                    }
                    if (isset($imgContainer0Handle)) {
                        $itemElement->setFieldValue($imgContainer0Handle, $data);
                    }
                }
            }
        }

        if (!Craft::$app->getElements()->saveElement($itemElement)) {
            craft::warning("$item Creation error" . json_encode($itemElement->getErrors()));
        }
    }
}
