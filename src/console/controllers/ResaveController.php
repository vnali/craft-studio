<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\console\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\base\LocalFsInterface;
use craft\console\Controller;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\events\BatchElementActionEvent;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Assets;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\queue\jobs\ResaveElements;
use craft\services\Elements;

use vnali\studio\elements\Episode;
use vnali\studio\elements\Podcast;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\helpers\Id3;
use vnali\studio\helpers\Time;
use vnali\studio\records\PodcastEpisodeSettingsRecord;
use vnali\studio\Studio;

use yii\console\ExitCode;
use yii\helpers\Console;
use yii\web\BadRequestHttpException;

/**
 * Resave plugin elements on console
 */
class ResaveController extends Controller
{
    public bool $queue = false;

    public bool $drafts = false;

    public bool $provisionalDrafts = false;

    public bool $revisions = false;

    public string|int|null $elementId = null;

    public ?string $uid = null;

    public ?string $site = null;

    public string $status = 'any';

    public ?int $offset = null;

    public ?int $limit = null;

    public bool $updateSearchIndex = false;

    public ?string $set = null;

    public ?string $to = null;

    public bool $ifEmpty = false;

    public bool $metadata = false;

    public bool $imageMetadata = false;

    public bool $ifMetaValueNotEmpty = true;

    public bool $overwriteImage = false;

    public bool $overwriteNumber = false;

    public bool $overwriteTag = false;

    public bool $overwriteTitle = false;

    public bool $previewMetadata = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'queue';
        $options[] = 'elementId';
        $options[] = 'uid';
        $options[] = 'site';
        $options[] = 'status';
        $options[] = 'offset';
        $options[] = 'limit';
        $options[] = 'updateSearchIndex';

        // Available option for episode actions
        switch ($actionID) {
            case 'episodes':
                $options[] = 'metadata';
                $options[] = 'imageMetadata';
                $options[] = 'ifMetaValueNotEmpty';
                $options[] = 'overwriteImage';
                $options[] = 'overwriteNumber';
                $options[] = 'overwriteTitle';
                $options[] = 'previewMetadata';
                break;
        }

        $options[] = 'set';
        $options[] = 'to';
        $options[] = 'ifEmpty';

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (isset($this->set) && !isset($this->to)) {
            $this->stderr('--to is required when using --set.' . PHP_EOL, Console::FG_RED);
            return false;
        }

        return true;
    }

    /**
     * studio/resave/episodes action
     *
     * @return int
     */
    public function actionEpisodes(): int
    {
        $criteria = [];
        return $this->resaveElements(Episode::class, $criteria);
    }

    /**
     * studio/resave/podcasts action
     *
     * @return int
     */
    public function actionPodcasts(): int
    {
        $criteria = [];
        return $this->resaveElements(Podcast::class, $criteria);
    }

    /**
     * @param string $elementType The element type that should be resaved
     * @phpstan-param class-string<ElementInterface> $elementType
     * @param array $criteria The element criteria that determines which elements should be resaved
     * @return int
     * @since 3.7.0
     */
    public function resaveElements(string $elementType, array $criteria = []): int
    {
        /** @var string|ElementInterface $elementType */
        /** @phpstan-var class-string<ElementInterface>|ElementInterface $elementType */
        $criteria += $this->_baseCriteria();

        if ($this->queue) {
            Queue::push(new ResaveElements([
                'elementType' => $elementType,
                'criteria' => $criteria,
                'updateSearchIndex' => $this->updateSearchIndex,
            ]));
            $this->stdout($elementType::pluralDisplayName() . ' queued to be resaved.' . PHP_EOL);
            return ExitCode::OK;
        }

        $query = $elementType::find();
        Craft::configure($query, $criteria);
        return $this->_resaveElements($query);
    }

    /**
     * @param ElementQueryInterface $query
     * @return int
     * @since 3.2.0
     * @deprecated in 3.7.0. Use [[resaveElements()]] instead.
     */
    public function saveElements(ElementQueryInterface $query): int
    {
        if ($this->queue) {
            $this->stderr('This command doesn’t support the --queue option yet.' . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        Craft::configure($query, $this->_baseCriteria());
        return $this->_resaveElements($query);
    }

    /**
     * @return array
     */
    private function _baseCriteria(): array
    {
        $criteria = [];

        if ($this->drafts) {
            $criteria['drafts'] = true;
        }

        if ($this->provisionalDrafts) {
            $criteria['drafts'] = true;
            $criteria['provisionalDrafts'] = true;
        }

        if ($this->revisions) {
            $criteria['revisions'] = true;
        }

        if ($this->elementId) {
            $criteria['id'] = is_int($this->elementId) ? $this->elementId : explode(',', $this->elementId);
        }

        if ($this->uid) {
            $criteria['uid'] = explode(',', $this->uid);
        }

        if ($this->site) {
            $criteria['site'] = $this->site;
        }

        if ($this->status === 'any') {
            $criteria['status'] = null;
        } elseif ($this->status) {
            $criteria['status'] = explode(',', $this->status);
        }

        if (isset($this->offset)) {
            $criteria['offset'] = $this->offset;
        }

        if (isset($this->limit)) {
            $criteria['limit'] = $this->limit;
        }

        return $criteria;
    }

    /**
     * Resave elements
     */
    private function _resaveElements(ElementQueryInterface $query): int
    {
        /** @var ElementQuery $query */
        /** @var ElementInterface $elementType */
        $elementType = $query->elementType;
        switch ($elementType) {
            case Episode::class:
                $elementItem = 'episode';
                break;
            case Podcast::class:
                $elementItem = 'podcast';
                break;
            default:
                $this->stdout('Not supported item');
                return ExitCode::OK;
        }
        $count = (int)$query->count();

        if ($count === 0) {
            $this->stdout('No ' . $elementType::pluralLowerDisplayName() . ' exist for that criteria.' . PHP_EOL, Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if ($query->limit) {
            $count = min($count, (int)$query->limit);
        }

        $to = isset($this->set) ? $this->_normalizeTo() : null;

        $elementsText = $count === 1 ? $elementType::lowerDisplayName() : $elementType::pluralLowerDisplayName();
        $this->stdout("Resaving $count $elementsText ..." . PHP_EOL, Console::FG_YELLOW);

        $elementsService = Craft::$app->getElements();
        $fail = false;

        $beforeCallback = function(BatchElementActionEvent $e) use ($query, $count, $to, $elementItem) {
            if ($e->query === $query) {
                $setting = null;
                $importSetting = null;
                $element = $e->element;
                if ($elementItem == 'episode') {
                    // Currently resave only happen for episode
                    /** @var Episode $element */
                    $podcast = Studio::$plugin->podcasts->getPodcastById($element->podcastId);
                    $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
                    $mapping = json_decode($podcastFormatEpisode->mapping, true);
                    /** @var PodcastEpisodeSettingsRecord|null $setting */
                    $setting = PodcastEpisodeSettingsRecord::find()->where(['podcastId' => $element->podcastId])->one();
                    if ($setting) {
                        $importSetting = json_decode($setting->settings, true);
                    }
                    if (!$importSetting) {
                        //$this->stdout('Import setting is not defined');
                        //return ExitCode::OK;
                    }
                }
                $this->stdout("    - [$e->position/$count] Resaving $element ($element->id) ... ");

                // Checking meta on resave
                $fieldHandle = null;
                $fieldContainer = null;

                if (isset($mapping['mainAsset']['container'])) {
                    $fieldContainer = $mapping['mainAsset']['container'];
                }
                if (isset($mapping['mainAsset']['field'])) {
                    $fieldUid = $mapping['mainAsset']['field'];
                    $field = Craft::$app->fields->getFieldByUid($fieldUid);
                    if ($field) {
                        $fieldHandle = $field->handle;
                        list($assetFilename, $assetFilePath, $assetFileUrl, $blockId, $asset) = GeneralHelper::getElementAsset($element, $fieldContainer, $fieldHandle);

                        $fileInfo = [];
                        // Fetch id3 tag
                        if ($asset && ($this->metadata || $this->imageMetadata)) {
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

                        if ($this->metadata) {
                            if (isset($fileInfo['playtime_string'])) {
                                $duration = $fileInfo['playtime_string'];
                                $this->stdout(PHP_EOL . "    - Duration: $duration", Console::FG_GREEN);
                                if (!$this->previewMetadata && $duration) {
                                    /** @var Episode $element */
                                    if (!ctype_digit($duration)) {
                                        $duration = Time::time_to_sec($duration);
                                    }
                                    $element->duration = $duration;
                                    $this->stdout(PHP_EOL . "    - Duration set", Console::FG_GREEN);
                                }
                            } else {
                                $this->stdout(PHP_EOL . "    - Duration not found", Console::FG_YELLOW);
                            }

                            if (isset($fileInfo['tags']['id3v2']['title'][0])) {
                                $title = $fileInfo['tags']['id3v2']['title'][0];
                                $this->stdout(PHP_EOL . "    - Title: $title", Console::FG_GREEN);
                                if (!$this->previewMetadata && ($this->overwriteTitle || !$element->title) && (!$this->ifMetaValueNotEmpty || $title)) {
                                    $this->stdout(PHP_EOL . "    - Title set", Console::FG_GREEN);
                                    $element->title = $title;
                                }
                            }

                            list($genreFieldType, $genreFieldHandle, $genreFieldGroup) = GeneralHelper::getElementGenreField($elementItem, $mapping);
                            if (isset($genreFieldGroup) && isset($importSetting)) {
                                $elementGenreImportOptions = $importSetting['genreImportOption'];
                                $elementGenreCheck = $importSetting['genreImportCheck'];
                                $defaultGenres = $importSetting['genreOnImport'];

                                list($genreIds, $genres) = Id3::getGenres($fileInfo, $genreFieldType, $genreFieldGroup->id, $elementGenreImportOptions, $elementGenreCheck, $defaultGenres);
                                // TODO: implement if tag field is inside matrix or ST
                                $this->stdout(PHP_EOL . "    - Genres: " . implode(' | ', $genres), Console::FG_GREEN);
                                $currentTagCount = $element->$genreFieldHandle->count();
                                if (!$this->previewMetadata && ($this->overwriteTag || $currentTagCount == 0) && (!$this->ifMetaValueNotEmpty || count($genreIds) > 0)) {
                                    $this->stdout(PHP_EOL . "    - Genres set", Console::FG_GREEN);
                                    $element->setFieldValues([
                                        $genreFieldHandle => $genreIds,
                                    ]);
                                }
                            }

                            if (isset($fileInfo['tags']['id3v2']['track_number'][0])) {
                                $track = trim($fileInfo['tags']['id3v2']['track_number'][0]);
                                $this->stdout(PHP_EOL . "    - track number: " . $track, Console::FG_GREEN);
                                /** @var Episode $element */
                                if (!$this->previewMetadata && ($this->overwriteNumber || !$element->episodeNumber) && (!$this->ifMetaValueNotEmpty || $track)) {
                                    $this->stdout(PHP_EOL . "    - Number set", Console::FG_GREEN);
                                    $element->episodeNumber = (int)$track;
                                }
                            }
                        }

                        if ($this->imageMetadata) {
                            $imageId = null;
                            list($imageField, $imageFieldContainer) = GeneralHelper::getElementImageField($elementItem, $mapping);
                            if ($imageField) {
                                $forceImage = null;
                                if (isset($importSetting['forceImage'])) {
                                    $forceImage = $importSetting['forceImage'];
                                }
                                if (!$forceImage) {
                                    list($img, $mime, $ext) = Id3::getImage($fileInfo);
                                    if ($img) {
                                        $tempFile = Assets::tempFilePath();
                                        file_put_contents($tempFile, $img);
                                        $folderId = $imageField->resolveDynamicPathToFolderId($element);
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
                                        $newAsset->tempFilePath = $tempFile;
                                        $assetFilenameArray = explode('.', $assetFilename);
                                        $newAsset->filename = $assetFilenameArray[0] . '.' . $ext;
                                        $newAsset->newFolderId = $folder->id;
                                        $newAsset->setVolumeId($folder->volumeId);
                                        if (Craft::$app->getElements()->saveElement($newAsset)) {
                                            $this->stdout(PHP_EOL . "    - Image fetched", Console::FG_GREEN);
                                            $imageId = $newAsset->id;
                                        } else {
                                            $forceImage = true;
                                            $errors = array_values($newAsset->getFirstErrors());
                                            if (isset($errors[0])) {
                                                $this->stdout(PHP_EOL . 'Error on extracting image from file');
                                            }
                                        }
                                    } else {
                                        $forceImage = true;
                                        $this->stdout(PHP_EOL . "    - No image extracted from file. default is using", Console::FG_GREEN);
                                    }
                                }

                                if ($forceImage && isset($importSetting['imageOnImport']) && is_array($importSetting['imageOnImport'])) {
                                    foreach ($importSetting['imageOnImport'] as $defaultElementImg) {
                                        $elementImg = \craft\elements\Asset::find()
                                            ->id($defaultElementImg)
                                            ->one();
                                        if ($elementImg) {
                                            $imageId = $elementImg->id;
                                        }
                                    }
                                }

                                if ($imageId) {
                                    if (!$imageFieldContainer) {
                                        $imageFieldHandle = $imageField->handle;
                                        if (get_class($imageField) == 'craft\fields\Assets') {
                                            $checkImage = $element->{$imageFieldHandle}->one();
                                        } else {
                                            $checkImage = $element->{$imageFieldHandle};
                                        }
                                        if (!$this->previewMetadata && ($this->overwriteImage || !$checkImage)) {
                                            $this->stdout(PHP_EOL . "    - Image set", Console::FG_GREEN);
                                            $element->{$imageFieldHandle} = [$imageId];
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
                                        $fieldContainers = explode('|', $imageFieldContainer);
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
                                                if (isset($blockId) && isset($serializedMatrix[$blockId]['fields'][$imageField->handle])) {
                                                    $serializedMatrix[$blockId]['fields'][$imageField->handle][] = $imageId;
                                                    $element->setFieldValue($container0Handle, $serializedMatrix);
                                                } else {
                                                    $sortOrder[] = 'new:1';
                                                    $newBlock = [
                                                        'type' => $container1Handle,
                                                        'fields' => [
                                                            $imageField->handle => [$imageId],
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
                                                if (isset($blockId) && isset($serializedSt[$blockId]['fields'][$imageField->handle])) {
                                                    $serializedSt[$blockId]['fields'][$imageField->handle][] = $imageId;
                                                    $element->setFieldValue($container0Handle, $serializedSt);
                                                } else {
                                                    $sortOrder[] = 'new:1';
                                                    $newBlock = [
                                                        'type' => $container1Handle,
                                                        'fields' => [
                                                            $imageField->handle => [$imageId],
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
                                    $this->stdout(PHP_EOL . "    - No Image", Console::FG_GREEN);
                                }
                            }
                        }
                    }
                }

                $this->stdout(PHP_EOL);

                if ($this->set && (!$this->ifEmpty || $this->_isSetAttributeEmpty($element))) {
                    $element->{$this->set} = $to($element);
                }
            }
        };

        $afterCallback = function(BatchElementActionEvent $e) use ($query, &$fail) {
            if ($e->query === $query) {
                $element = $e->element;
                if ($e->exception) {
                    $this->stderr('error: ' . $e->exception->getMessage() . PHP_EOL, Console::FG_RED);
                    $fail = true;
                } elseif ($element->hasErrors()) {
                    $this->stderr('failed: ' . implode(', ', $element->getErrorSummary(true)) . PHP_EOL, Console::FG_RED);
                    $fail = true;
                } else {
                    $this->stdout('done' . PHP_EOL, Console::FG_GREEN);
                }
            }
        };

        $elementsService->on(Elements::EVENT_BEFORE_RESAVE_ELEMENT, $beforeCallback);
        $elementsService->on(Elements::EVENT_AFTER_RESAVE_ELEMENT, $afterCallback);

        $elementsService->resaveElements($query, true, !$this->revisions, $this->updateSearchIndex);

        $elementsService->off(Elements::EVENT_BEFORE_RESAVE_ELEMENT, $beforeCallback);
        $elementsService->off(Elements::EVENT_AFTER_RESAVE_ELEMENT, $afterCallback);

        $this->stdout("Done resaving $elementsText." . PHP_EOL . PHP_EOL, Console::FG_YELLOW);
        return $fail ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Returns [[to]] normalized to a callable.
     *
     * @return callable
     */
    private function _normalizeTo(): callable
    {
        // empty
        if ($this->to === ':empty:') {
            return function() {
                return null;
            };
        }

        // object template
        if (str_starts_with($this->to, '=')) {
            $template = substr($this->to, 1);
            $view = Craft::$app->getView();
            return function(ElementInterface $element) use ($template, $view) {
                return $view->renderObjectTemplate($template, $element);
            };
        }

        // PHP arrow function
        if (preg_match('/^fn\s*\(\s*\$(\w+)\s*\)\s*=>\s*(.+)/', $this->to, $match)) {
            $var = $match[1];
            $php = sprintf('return %s;', StringHelper::removeLeft(rtrim($match[2], ';'), 'return '));
            return function(ElementInterface $element) use ($var, $php) {
                $$var = $element;
                return eval($php);
            };
        }

        // attribute name
        return function(ElementInterface $element) {
            return $element->{$this->to};
        };
    }

    /**
     * Returns whether the [[set]] attribute on the given element is empty.
     *
     * @param ElementInterface $element
     * @return bool
     */
    private function _isSetAttributeEmpty(ElementInterface $element): bool
    {
        // See if we're setting a custom field
        if ($fieldLayout = $element->getFieldLayout()) {
            foreach ($fieldLayout->getTabs() as $tab) {
                foreach ($tab->elements as $layoutElement) {
                    if ($layoutElement instanceof CustomField && $layoutElement->attribute() === $this->set) {
                        return $layoutElement->getField()->isValueEmpty($element->getFieldValue($this->set), $element);
                    }
                }
            }
        }

        return empty($element->{$this->set});
    }
}
