<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\base\LocalFsInterface;
use craft\elements\db\EntryQuery;
use craft\elements\db\UserQuery;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fields\Table;
use craft\web\Controller;
use verbb\supertable\elements\SuperTableBlockElement;
use verbb\supertable\fields\SuperTableField;
use vnali\studio\elements\Episode as EpisodeElement;
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

    /**
     * Get page context
     *
     * @param int $elementId
     * @return Response
     */
    public function actionGetPageContext(int $elementId): Response
    {
        $elementType = Craft::$app->getElements()->getElementTypeById($elementId);

        // Chapter field
        list($chapterField, $chapterBlockTypeHandle) = GeneralHelper::getFieldDefinition('chapter');

        // Soundbite field
        list($soundbiteField, $soundbiteBlockTypeHandle) = GeneralHelper::getFieldDefinition('soundbite');

        // Transcript text field
        list($transcriptTextField) = GeneralHelper::getFieldDefinition('transcriptText');

        $speakers = [];

        if ($transcriptTextField) {
            $episode = Craft::$app->elements->getElementById($elementId);
            $fieldLayout = $episode->getFieldLayout();
    
            $transcriptTextIncluded = $fieldLayout->isFieldIncluded($transcriptTextField->handle);
            if ($transcriptTextIncluded) {
                // Speakers
                // TODO: we should not suggest all person and person roles as speaker
                list($personField, $personBlockTypeHandle) = GeneralHelper::getFieldDefinition('episodePerson');
                if ($personField) {
                    $personFieldHandle = $personField->handle;
                    if (get_class($personField) == PlainText::class) {
                        if (isset($episode->$personFieldHandle) && $episode->$personFieldHandle) {
                            $speaker = [];
                            $speaker['value'] = $episode->$personFieldHandle;
                            $speaker['label'] = $episode->$personFieldHandle;
                            $speakers[] = $speaker;
                        }
                    } elseif (get_class($personField) == Table::class) {
                        if (isset($episode->$personFieldHandle) && $episode->$personFieldHandle) {
                            foreach ($episode->$personFieldHandle as $row) {
                                if (isset($row['person']) && $row['person']) {
                                    $speaker = [];
                                    $speaker['value'] = $row['person'];
                                    $speaker['label'] = $row['person'];
                                    $speakers[] = $speaker;
                                }
                            }
                        }
                    } elseif (get_class($personField) == Matrix::class || get_class($personField) == SuperTableField::class) {
                        $personBlocks = [];
                        if (get_class($personField) == Matrix::class) {
                            $blockQuery = \craft\elements\MatrixBlock::find();
                            $personBlocks = $blockQuery->fieldId($personField->id)->owner($episode)->type($personBlockTypeHandle)->all();
                        } elseif (get_class($personField) == SuperTableField::class) {
                            $blockQuery = SuperTableBlockElement::find();
                            $personBlocks = $blockQuery->fieldId($personField->id)->owner($episode)->all();
                        }
                        foreach ($personBlocks as $personBlock) {
                            if (isset($personBlock->person) && $personBlock->person) {
                                // Person Value
                                if (!is_object($personBlock->person)) {
                                    $speaker = [];
                                    $speaker['value'] = $personBlock->person;
                                    $speaker['label'] = $personBlock->person;
                                    $speakers[] = $speaker;
                                } else {
                                    if (get_class($personBlock->person) == UserQuery::class || get_class($personBlock->person) == EntryQuery::class) {
                                        $person = $personBlock->person->one();
                                        if ($person) {
                                            if (get_class($personBlock->person) == UserQuery::class) {
                                                if ($person->fullName) {
                                                    $speaker = [];
                                                    $speaker['value'] = $person->fullName;
                                                    $speaker['label'] = $person->fullName;
                                                    $speakers[] = $speaker;
                                                }
                                            } elseif (get_class($personBlock->person) == EntryQuery::class) {
                                                if (isset($person->title) && $person->title) {
                                                    $speaker = [];
                                                    $speaker['value'] = $person->title;
                                                    $speaker['label'] = $person->title;
                                                    $speakers[] = $speaker;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                if (!$speakers) {
                    // If speakers from episodePerson is empty, try podcastPerson
                    /** @var EpisodeElement $episode */
                    $podcast = $episode->getPodcast();
                    list($personField, $personBlockTypeHandle) = GeneralHelper::getFieldDefinition('podcastPerson');
                    if ($personField) {
                        $personFieldHandle = $personField->handle;
                        if (get_class($personField) == PlainText::class) {
                            if (isset($podcast->$personFieldHandle) && $podcast->$personFieldHandle) {
                                $speaker = [];
                                $speaker['value'] = $podcast->$personFieldHandle;
                                $speaker['label'] = $podcast->$personFieldHandle;
                                $speakers[] = $speaker;
                            }
                        } elseif (get_class($personField) == Table::class) {
                            if (isset($podcast->$personFieldHandle) && $podcast->$personFieldHandle) {
                                foreach ($podcast->$personFieldHandle as $row) {
                                    if (isset($row['person']) && $row['person']) {
                                        $speaker = [];
                                        $speaker['value'] = $row['person'];
                                        $speaker['label'] = $row['person'];
                                        $speakers[] = $speaker;
                                    }
                                }
                            }
                        } elseif (get_class($personField) == Matrix::class || get_class($personField) == SuperTableField::class) {
                            $personBlocks = [];
                            if (get_class($personField) == Matrix::class) {
                                $blockQuery = \craft\elements\MatrixBlock::find();
                                $personBlocks = $blockQuery->fieldId($personField->id)->owner($podcast)->type($personBlockTypeHandle)->all();
                            } elseif (get_class($personField) == SuperTableField::class) {
                                $blockQuery = SuperTableBlockElement::find();
                                $personBlocks = $blockQuery->fieldId($personField->id)->owner($podcast)->all();
                            }
                            foreach ($personBlocks as $personBlock) {
                                if (isset($personBlock->person) && $personBlock->person) {
                                    // Person Value
                                    if (!is_object($personBlock->person)) {
                                        $speaker = [];
                                        $speaker['value'] = $personBlock->person;
                                        $speaker['label'] = $personBlock->person;
                                        $speakers[] = $speaker;
                                    } else {
                                        if (get_class($personBlock->person) == UserQuery::class || get_class($personBlock->person) == EntryQuery::class) {
                                            $person = $personBlock->person->one();
                                            if ($person) {
                                                if (get_class($personBlock->person) == UserQuery::class) {
                                                    if ($person->fullName) {
                                                        $speaker = [];
                                                        $speaker['value'] = $person->fullName;
                                                        $speaker['label'] = $person->fullName;
                                                        $speakers[] = $speaker;
                                                    }
                                                } elseif (get_class($personBlock->person) == EntryQuery::class) {
                                                    if (isset($person->title) && $person->title) {
                                                        $speaker = [];
                                                        $speaker['value'] = $person->title;
                                                        $speaker['label'] = $person->title;
                                                        $speakers[] = $speaker;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Pass data
        $array = [
            'elementType' => $elementType,
            'chapterFieldType' => $chapterField ? get_class($chapterField) : null,
            'chapterFieldHandle' => $chapterField ? $chapterField->handle : null,
            'chapterBlockTypeHandle' => $chapterBlockTypeHandle ?? null,
            'soundbiteFieldType' => $soundbiteField ? get_class($soundbiteField) : null,
            'soundbiteFieldHandle' => $soundbiteField ? $soundbiteField->handle : null,
            'soundbiteBlockTypeHandle' => $soundbiteBlockTypeHandle ?? null,
            'transcriptTextFieldHandle' => ($transcriptTextField && $transcriptTextIncluded) ? $transcriptTextField->handle : null,
            'speakers' => $speakers,
        ];
        return $this->asJson($array);
    }
}
