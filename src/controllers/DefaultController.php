<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\base\LocalFsInterface;
use craft\web\Controller;

use vnali\studio\helpers\GeneralHelper;
use vnali\studio\helpers\Id3;
use vnali\studio\helpers\Time;
use vnali\studio\Studio;

use yii\web\Response;
use yii\web\ServerErrorHttpException;

class DefaultController extends Controller
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
    }

    /**
     * Get entry types.
     *
     * @param int $sectionId
     * @return Response
     */
    public function actionGetEntryTypes(int $sectionId): Response
    {
        $variables['entryType'][] = ['value' => '', 'label' => 'select one'];
        if ($sectionId) {
            foreach (Craft::$app->sections->getEntryTypesBySectionId($sectionId) as $entryType) {
                $entryTypes['value'] = $entryType->id;
                $entryTypes['label'] = $entryType->name;
                $variables['entryType'][] = $entryTypes;
            }
        }
        return $this->asJson($variables['entryType']);
    }

    /**
     * Filter suggested fields based on type, container and item's field layout.
     *
     * @param string $convertTo
     * @param string $fieldContainer
     * @param string|null $limitFieldsToLayout
     * @param string|null $item
     * @param int|null $itemId
     * @return Response
     */
    public function actionFieldsFilter(string $convertTo, string $fieldContainer, ?string $limitFieldsToLayout = null, ?string $item = null, ?int $itemId = null): Response
    {
        $fieldsArray = GeneralHelper::findField(null, $convertTo, $fieldContainer, $limitFieldsToLayout, $item, $itemId);
        return $this->asJson($fieldsArray);
    }

    /**
     * Fetch metadata from asset
     *
     * @param string|null $site
     * @return Response
     */
    public function actionMeta(?string $site = null): Response
    {
        $request = Craft::$app->getRequest();

        $elementId = $request->getBodyParam('elementId');
        $fetchId3Metadata = $request->getBodyParam('fetchId3Metadata');
        $fetchId3ImageMetadata = $request->getBodyParam('fetchId3ImageMetadata');
        $item = $request->getBodyParam('item');

        // Get the site if site handle is set
        if ($site !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($site);
        } else {
            $site = Craft::$app->getSites()->getCurrentSite();
        }

        if ($item == 'episode') {
            $element = Studio::$plugin->episodes->getEpisodeById($elementId, $site->id);
            $podcast = Studio::$plugin->podcasts->getPodcastById($element->podcastId, $site->id);
            $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
            $episodeMapping = json_decode($podcastFormatEpisode->mapping, true);
        } else {
            throw new ServerErrorHttpException($item . ' is not supported');
        }

        $fieldHandle = null;
        $fieldContainer = null;
        if (isset($episodeMapping['mainAsset']['container'])) {
            $fieldContainer = $episodeMapping['mainAsset']['container'];
        }
        if (isset($episodeMapping['mainAsset']['field'])) {
            $fieldUid = $episodeMapping['mainAsset']['field'];
            $field = Craft::$app->fields->getFieldByUid($fieldUid);
            if ($field) {
                $fieldHandle = $field->handle;
            }
        }

        if (!$fieldHandle) {
            Craft::$app->getSession()->setError(ucfirst($item) . " main asset field is not specified. Please set the episode custom file first.");
            return $this->redirect($element->getCpEditUrl());
        }

        list($assetFilename, $assetFilePath, $assetFileUrl, $blockId, $asset) = GeneralHelper::getElementAsset($element, $fieldContainer, $fieldHandle);

        // Fetch id3 tag
        $fileInfo = [];
        if ($asset && ($fetchId3Metadata || $fetchId3ImageMetadata)) {
            $vol = $asset->getVolume();
            $fs = $vol->getFs();
            if ($fs instanceof LocalFsInterface) {
                $type = 'local';
                $path = $assetFilePath;
            } else {
                $type = 'remote';
                $path = $assetFileUrl;
            }
            $fileInfo = Id3::analyze($type, $path);
        }
        if ($fetchId3Metadata) {
            $fieldLayout = $element->getFieldLayout();

            if ($fieldLayout->isFieldIncluded('duration')) {
                if (isset($fileInfo['playtime_string'])) {
                    $duration = $fileInfo['playtime_string'];
                    if ($duration) {
                        if (!ctype_digit((string)$duration)) {
                            $duration = Time::time_to_sec($duration);
                        }
                        if (!$element->duration) {
                            $element->duration = $duration;
                        }
                    }
                }
            }

            if ($fieldLayout->isFieldIncluded('episodeNumber')) {
                if (isset($fileInfo['tags']['id3v2']['track_number'][0])) {
                    $track = trim($fileInfo['tags']['id3v2']['track_number'][0]);
                    if ($track) {
                        if (!$element->episodeNumber) {
                            $element->episodeNumber = (int)$track;
                        }
                    }
                }
            }

            if (isset($fileInfo['tags']['id3v2']['title'][0])) {
                $title = $fileInfo['tags']['id3v2']['title'][0];
                if (!$element->title) {
                    $element->title = $title;
                }
            }

            list($genreFieldType, $genreFieldHandle, $genreFieldGroup) = GeneralHelper::getElementGenreField($item, $episodeMapping);
            if (isset($genreFieldGroup)) {
                $itemGenreImportOptions = 'only-metadata';
                $itemGenreCheck = false;
                $defaultGenres = [];

                list($genreIds,) = Id3::getGenres($fileInfo, $genreFieldType, $genreFieldGroup->id, $itemGenreImportOptions, $itemGenreCheck, $defaultGenres);
                $element->setFieldValues([
                    $genreFieldHandle => $genreIds,
                ]);
            }

            list($pubDateField) = GeneralHelper::getElementPubDateField($item, $episodeMapping);

            if (isset($pubDateField)) {
                $pubDate = Id3::getYear($fileInfo);
                if (!$element->{$pubDateField->handle}) {
                    $element->{$pubDateField->handle} = $pubDate;
                }
            }
        }

        if ($fetchId3ImageMetadata) {
            list($imageField, $imageFieldContainer) = GeneralHelper::getElementImageField($item, $episodeMapping);

            // Fetch image with id3tag metadata if image field is specified
            if ($imageField) {
                list($img, $mime, $ext) = Id3::getImage($fileInfo);
                // use main asset file name and image extension to create a file name for image
                $assetFilenameArray = explode('.', $assetFilename);
                $assetFilename = $assetFilenameArray[0] . '.' . $ext;
                if ($img) {
                    $element = GeneralHelper::uploadFile($img, null, $imageField, $imageFieldContainer, $element, $assetFilename, $blockId);
                } else {
                    Craft::$app->getSession()->setError(Craft::t('studio', 'no image extracted from file'));
                }
            }
        }

        if (!$element->title) {
            $element->title = "Untitled $item";
        }

        if (!Craft::$app->getElements()->saveElement($element)) {
            craft::warning('Problem with saving episode on fetching metadata: ' . $element->getErrors());
            throw new ServerErrorHttpException('there was a problem saving element');
        }

        return $this->redirect($element->getCpEditUrl());
    }
}
