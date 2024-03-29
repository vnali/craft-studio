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
use craft\errors\InvalidElementException;
use craft\events\BatchElementActionEvent;
use craft\helpers\Assets;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\queue\jobs\ResaveElements;
use craft\services\Elements;
use Throwable;
use verbb\supertable\SuperTable;
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
    /**
     * Returns [[to]] normalized to a callable.
     *
     * @param string|null $to
     * @return callable
     * @since 4.2.6
     * @internal
     */
    final public static function normalizeTo(?string $to): callable
    {
        // empty
        if ($to === ':empty:') {
            return function() {
                return null;
            };
        }

        // object template
        if (str_starts_with($to, '=')) {
            $template = substr($to, 1);
            $view = Craft::$app->getView();
            return function(ElementInterface $element) use ($template, $view) {
                return $view->renderObjectTemplate($template, $element);
            };
        }

        // PHP arrow function
        if (preg_match('/^fn\s*\(\s*(?:\$(\w+)\s*)?\)\s*=>\s*(.+)/', $to, $match)) {
            $var = $match[1];
            $php = sprintf('return %s;', StringHelper::removeLeft(rtrim($match[2], ';'), 'return '));
            return function(ElementInterface $element) use ($var, $php) {
                if ($var) {
                    $$var = $element;
                }
                return eval($php);
            };
        }

        // attribute name
        return static function(ElementInterface $element) use ($to) {
            return $element->$to;
        };
    }

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

    public bool $touch = false;

    public ?string $set = null;

    public string|array|null $propagateTo = null;

    public ?bool $setEnabledForSite = null;

    public ?string $to = null;

    public bool $ifEmpty = false;

    public ?bool $metadata = null;

    public ?bool $imageMetadata = null;

    public ?bool $allowEmptyMetaValue = null;

    public ?bool $overwriteDuration = null;

    public ?bool $overwriteGenre = null;

    public ?bool $overwriteImage = null;

    public ?bool $overwriteNumber = null;

    public ?bool $overwritePubDate = null;

    public ?bool $overwriteTitle = null;

    public ?bool $previewMetadata = null;

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
        $options[] = 'touch';

        // Available option for episode actions
        switch ($actionID) {
            case 'episodes':
                $options[] = 'allowEmptyMetaValue';
                $options[] = 'metadata';
                $options[] = 'imageMetadata';
                $options[] = 'overwriteDuration';
                $options[] = 'overwriteGenre';
                $options[] = 'overwriteImage';
                $options[] = 'overwriteNumber';
                $options[] = 'overwritePubDate';
                $options[] = 'overwriteTitle';
                $options[] = 'previewMetadata';
                $options[] = 'drafts';
                $options[] = 'provisionalDrafts';
                $options[] = 'revisions';
                $options[] = 'propagateTo';
                $options[] = 'setEnabledForSite';
                break;
            case 'podcasts':
                $options[] = 'propagateTo';
                $options[] = 'setEnabledForSite';
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

        if (isset($this->previewMetadata)) {
            if (!isset($this->metadata) && !isset($this->imageMetadata)) {
                $this->stderr('--previewMetadata should be used with --metadata or --imageMetaData.' . PHP_EOL, Console::FG_RED);
                return false;
            }
        }

        if (isset($this->propagateTo)) {
            $siteHandles = array_filter(StringHelper::split($this->propagateTo));
            $this->propagateTo = [];
            $sitesService = Craft::$app->getSites();
            foreach ($siteHandles as $siteHandle) {
                $site = $sitesService->getSiteByHandle($siteHandle, true);
                if (!$site) {
                    $this->stderr("Invalid site handle: $siteHandle" . PHP_EOL, Console::FG_RED);
                    return false;
                }
                $this->propagateTo[] = $site->id;
            }

            if (isset($this->set)) {
                $this->stderr('--propagate-to can’t be coupled with --set.' . PHP_EOL, Console::FG_RED);
                return false;
            }

            if (isset($this->previewMetadata)) {
                $this->stderr('--previewMetadata can’t be coupled with --previewMetadata.' . PHP_EOL, Console::FG_RED);
                return false;
            }

            if (isset($this->metadata)) {
                $this->stderr('--metadata can’t be coupled with --metadata.' . PHP_EOL, Console::FG_RED);
                return false;
            }

            if (isset($this->imageMetadata)) {
                $this->stderr('--imageMetadata can’t be coupled with --imageMetadata.' . PHP_EOL, Console::FG_RED);
                return false;
            }
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

    public function resaveElements(string $elementType, array $criteria = []): int
    {
        $criteria += $this->_baseCriteria();

        if ($this->queue) {
            Queue::push(new ResaveElements([
                'elementType' => $elementType,
                'criteria' => $criteria,
                'set' => $this->set,
                'to' => $this->to,
                'ifEmpty' => $this->ifEmpty,
                'touch' => $this->touch,
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

        if ($query->offset) {
            $count = max($count - (int)$query->offset, 0);
        }

        if ($query->limit) {
            $count = min($count, (int)$query->limit);
        }

        $to = isset($this->set) ? self::normalizeTo($this->to) : null;

        $label = isset($this->propagateTo) ? 'Propagating' : 'Resaving';
        $elementsText = $count === 1 ? $elementType::lowerDisplayName() : $elementType::pluralLowerDisplayName();
        $this->stdout("$label $count $elementsText ..." . PHP_EOL, Console::FG_YELLOW);

        $elementsService = Craft::$app->getElements();
        $fail = false;

        $beforeCallback = function(BatchElementActionEvent $e) use ($query, $count, $to, $elementItem) {
            if ($e->query === $query) {
                $setting = null;
                $importSetting = null;
                $label = isset($this->propagateTo) ? 'Propagating' : 'Resaving';
                $element = $e->element;
                $fieldsService = Craft::$app->getFields();
                if ($elementItem == 'episode') {
                    // Currently resave only happen for episode
                    /** @var Episode $element */
                    $podcast = Studio::$plugin->podcasts->getPodcastById($element->podcastId, $element->siteId);
                    $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
                    $mapping = json_decode($podcastFormatEpisode->mapping, true);
                    /** @var PodcastEpisodeSettingsRecord|null $setting */
                    $setting = PodcastEpisodeSettingsRecord::find()->where(['podcastId' => $element->podcastId, 'siteId' => $element->siteId])->one();
                    if ($setting) {
                        $importSetting = json_decode($setting->settings, true);
                    } else {
                        $this->stdout("Episode setting was not defined for podcast $element->podcastId siteId: $element->siteId" . PHP_EOL, Console::FG_YELLOW);
                    }
                }
                $this->stdout("    - [$e->position/$count] $label $element ($element->id) ... ");

                // Check if podcast is available for the site we want propagate episode to
                if (isset($this->propagateTo) && is_array($this->propagateTo) && $elementItem == 'episode') {
                    foreach ($this->propagateTo as $propagateToSite) {
                        /** @var Episode $element */
                        $podcast = Studio::$plugin->podcasts->getPodcastById($element->podcastId, $propagateToSite);
                        if (!$podcast) {
                            $this->stdout(PHP_EOL . "    -Podcast was not available for siteId $propagateToSite" . PHP_EOL, Console::FG_YELLOW);
                            return ExitCode::OK;
                        }
                    }
                }

                // Checking meta on resave
                $fieldHandle = null;
                $fieldContainer = null;

                if (isset($mapping['mainAsset']['container'])) {
                    $fieldContainer = $mapping['mainAsset']['container'];
                }

                if (isset($mapping['mainAsset']['field']) && $mapping['mainAsset']['field']) {
                    $fieldUid = $mapping['mainAsset']['field'];
                    $field = $fieldsService->getFieldByUid($fieldUid);
                    if ($field) {
                        $fieldHandle = $field->handle;
                        list($assetFilename, $assetFilePath, $assetFileUrl, $blockId, $asset) = GeneralHelper::getElementAsset($element, $fieldContainer, $fieldHandle);

                        $fileInfo = null;

                        if ($this->metadata || $this->imageMetadata) {
                            if (!$asset) {
                                $this->stdout(PHP_EOL . "    - No main Asset" . PHP_EOL, Console::FG_YELLOW);
                                return ExitCode::OK;
                            }

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
                            if (!$fileInfo) {
                                $this->stdout(PHP_EOL . "    - No metadata" . PHP_EOL, Console::FG_YELLOW);
                                return ExitCode::OK;
                            }
                        }

                        if ($this->metadata) {
                            $fieldLayout = $element->getFieldLayout();

                            // Get duration from ID3 metadata
                            if (!isset($fileInfo['playtime_string'])) {
                                $this->stdout(PHP_EOL . "    - Duration not found", Console::FG_YELLOW);
                            } else {
                                $duration = $fileInfo['playtime_string'];
                                $this->stdout(PHP_EOL . "    - Duration: $duration", Console::FG_GREEN);
                                /** @var Episode $element */
                                if (!$this->previewMetadata && (!$element->duration || $this->overwriteDuration) && ($duration || $this->allowEmptyMetaValue)) {
                                    if ($fieldLayout->isFieldIncluded('duration')) {
                                        if (!ctype_digit($duration)) {
                                            $duration = Time::time_to_sec($duration);
                                        }
                                        if ($this->overwriteDuration) {
                                            $this->stdout(PHP_EOL . "    - Duration is overwritten. Old value: " . Time::sec_to_time($element->duration), Console::FG_GREEN);
                                        } else {
                                            $this->stdout(PHP_EOL . "    - Duration is saved", Console::FG_GREEN);
                                        }
                                        $element->duration = $duration;
                                    } else {
                                        $this->stdout(PHP_EOL . "    - Duration is not included in field layout", Console::FG_YELLOW);
                                    }
                                } elseif (!$this->previewMetadata && $element->duration && !$this->overwriteDuration) {
                                    $this->stdout(PHP_EOL . "    - Overwriting of the duration is not allowed", Console::FG_YELLOW);
                                } elseif (!$this->previewMetadata && !$duration && !$this->allowEmptyMetaValue) {
                                    $this->stdout(PHP_EOL . "    - Duration is empty and empty value is not allowed", Console::FG_YELLOW);
                                }
                            }

                            // Get title from ID3 metadata
                            if (isset($fileInfo['tags']['id3v2']['title'][0])) {
                                $title = $fileInfo['tags']['id3v2']['title'][0];
                                $this->stdout(PHP_EOL . "    - Title: $title", Console::FG_GREEN);
                                if (!$this->previewMetadata && (!$element->title || $this->overwriteTitle) && ($title || $this->allowEmptyMetaValue)) {
                                    if ($this->overwriteTitle) {
                                        $this->stdout(PHP_EOL . "    - Title is overWritten. Old value: " . $element->title, Console::FG_GREEN);
                                    } else {
                                        $this->stdout(PHP_EOL . "    - Title is saved", Console::FG_GREEN);
                                    }
                                    $element->title = $title;
                                } elseif (!$this->previewMetadata && $element->title && !$this->overwriteTitle) {
                                    $this->stdout(PHP_EOL . "    - Overwriting of the title is not allowed", Console::FG_YELLOW);
                                } elseif (!$this->previewMetadata && !$title && !$this->allowEmptyMetaValue) {
                                    $this->stdout(PHP_EOL . "    - Title is empty and empty value is not allowed", Console::FG_YELLOW);
                                }
                            } else {
                                $this->stdout(PHP_EOL . "    - Title is not available in metadata", Console::FG_YELLOW);
                            }

                            // Get genres from ID3 metadata
                            list($genreIds, $genres, $metaGenres) = Id3::getGenres($fileInfo);
                            $this->stdout(PHP_EOL . "    - Meta genres: " . implode(' | ', $metaGenres), Console::FG_GREEN);
                            list($genreFieldType, $genreFieldHandle, $genreFieldGroup) = GeneralHelper::getElementGenreField($elementItem, $mapping);
                            if (isset($genreFieldGroup) && isset($importSetting)) {
                                $elementGenreImportOptions = $importSetting['genreImportOption'];
                                $elementGenreCheck = $importSetting['genreImportCheck'];
                                $defaultGenres = $importSetting['defaultGenres'];
                                if ($elementGenreImportOptions) {
                                    list($genreIds, $genres, $metaGenres) = Id3::getGenres($fileInfo, $genreFieldType, $genreFieldGroup->id, $elementGenreImportOptions, $elementGenreCheck, $defaultGenres);
                                    if (!$this->previewMetadata) {
                                        $this->stdout(PHP_EOL . "    - Genres: " . implode(' | ', $genres), Console::FG_GREEN);
                                        $currentTagCount = $element->$genreFieldHandle->count();
                                        if (($currentTagCount == 0 || $this->overwriteGenre) && (count($genreIds) > 0 || $this->allowEmptyMetaValue)) {
                                            if ($this->overwriteGenre) {
                                                $currentTags = $element->$genreFieldHandle->collect();
                                                $currentTags = $currentTags->pluck('title')->join(', ');
                                                $this->stdout(PHP_EOL . "    - Genres are overwritten. Old values: $currentTags", Console::FG_GREEN);
                                            } else {
                                                $this->stdout(PHP_EOL . "    - Genres are saved", Console::FG_GREEN);
                                            }
                                            $element->setFieldValues([
                                                $genreFieldHandle => $genreIds,
                                            ]);
                                        } elseif ($currentTagCount != 0 && !$this->overwriteGenre) {
                                            $this->stdout(PHP_EOL . "    - Overwriting of the genres is not allowed", Console::FG_YELLOW);
                                        } elseif (count($genreIds) == 0 && !$this->allowEmptyMetaValue) {
                                            $this->stdout(PHP_EOL . "    - Genre is empty and empty value is not allowed", Console::FG_YELLOW);
                                        }
                                    }
                                } elseif (!$this->previewMetadata) {
                                    $this->stdout(PHP_EOL . "    - Genre import option is not specified", Console::FG_YELLOW);
                                }
                            } elseif (!$this->previewMetadata && !isset($genreFieldGroup)) {
                                $this->stdout(PHP_EOL . "    - Genre field is not specified", Console::FG_YELLOW);
                            } elseif (!$this->previewMetadata && !isset($importSetting)) {
                                $this->stdout(PHP_EOL . "    - Genres import setting is not specified", Console::FG_YELLOW);
                            }

                            // Get track number from ID3 metadata
                            if (isset($fileInfo['tags']['id3v2']['track_number'][0])) {
                                $track = trim($fileInfo['tags']['id3v2']['track_number'][0]);
                                $this->stdout(PHP_EOL . "    - Track number: " . $track, Console::FG_GREEN);
                                /** @var Episode $element */
                                if (!$this->previewMetadata && (!$element->episodeNumber || $this->overwriteNumber) && ($track || $this->allowEmptyMetaValue)) {
                                    if ($fieldLayout->isFieldIncluded('episodeNumber')) {
                                        if ($this->overwriteNumber) {
                                            $this->stdout(PHP_EOL . "    - Episode number is overwritten. Old value: " . $element->episodeNumber, Console::FG_GREEN);
                                        } else {
                                            $this->stdout(PHP_EOL . "    - Episode number is saved", Console::FG_GREEN);
                                        }
                                        $element->episodeNumber = (int)$track;
                                    } else {
                                        $this->stdout(PHP_EOL . "    - Episode number is not included in field layout", Console::FG_YELLOW);
                                    }
                                } elseif (!$this->previewMetadata && $element->episodeNumber && !$this->overwriteNumber) {
                                    $this->stdout(PHP_EOL . "    - Overwriting of the number is not allowed", Console::FG_YELLOW);
                                } elseif (!$this->previewMetadata && !$track && !$this->allowEmptyMetaValue) {
                                    $this->stdout(PHP_EOL . "    - Track is empty and empty value is not allowed", Console::FG_YELLOW);
                                }
                            } else {
                                $this->stdout(PHP_EOL . "    - Track number is not available in metadata ", Console::FG_YELLOW);
                            }

                            // Get year from ID3 metadata
                            list($pubDateField) = GeneralHelper::getElementPubDateField($elementItem, $mapping);
                            $pubDate = Id3::getYear($fileInfo);
                            if ($pubDate) {
                                $this->stdout(PHP_EOL . "    - Year in metadata $pubDate", Console::FG_GREEN);
                            } else {
                                $this->stdout(PHP_EOL . "    - Year is not available in metadata", Console::FG_YELLOW);
                            }
                            if (isset($pubDateField) && isset($importSetting['pubDateOption'])) {
                                $pubDateOption = $importSetting['pubDateOption'];
                                if ($pubDateOption == 'only-default' || (!$pubDate && $pubDateOption == 'default-if-not-metadata')) {
                                    $pubDate = $importSetting['defaultPubDate'];
                                }
                                /** @var Episode $element */
                                if (!$this->previewMetadata && (!$element->{$pubDateField->handle} || $this->overwritePubDate) && ($pubDate || $this->allowEmptyMetaValue)) {
                                    if ($this->overwritePubDate) {
                                        $oldPubDate = '';
                                        if ($element->{$pubDateField->handle}) {
                                            $oldPubDate = $element->{$pubDateField->handle}->format('D, d M Y H:i:s T');
                                        }
                                        $this->stdout(PHP_EOL . "    - Pub date is overwritten. Old value: " . $oldPubDate, Console::FG_GREEN);
                                    } else {
                                        $this->stdout(PHP_EOL . "    - Pub date is saved", Console::FG_GREEN);
                                    }
                                    $element->{$pubDateField->handle} = $pubDate;
                                } elseif (!$this->previewMetadata && $element->{$pubDateField->handle} && !$this->overwritePubDate) {
                                    $this->stdout(PHP_EOL . "    - Overwriting of the pub date is not allowed", Console::FG_YELLOW);
                                } elseif (!$this->previewMetadata && !$pubDate && !$this->allowEmptyMetaValue) {
                                    $this->stdout(PHP_EOL . "    - Pub date is empty and empty value is not allowed", Console::FG_YELLOW);
                                }
                            }
                        }

                        if ($this->imageMetadata) {
                            $imageId = null;
                            list($img, $mime, $ext) = Id3::getImage($fileInfo);
                            if ($img) {
                                $this->stdout(PHP_EOL . "    - Image is available in metadata", Console::FG_GREEN);
                            } else {
                                $this->stdout(PHP_EOL . "    - Image is not available in metadata", Console::FG_YELLOW);
                            }
                            list($imageField, $imageFieldContainer) = GeneralHelper::getElementImageField($elementItem, $mapping);
                            if ($imageField) {
                                list($imgAssetFilename, $imgAssetFilePath, $imgAssetFileUrl, $imgBlockId, $imgAsset) = GeneralHelper::getElementAsset($element, $imageFieldContainer, $imageField->handle);
                            }
                            if ($imageField && get_class($imageField) == 'craft\fields\Assets') {
                                $imageOption = null;
                                $useDefaultImage = false;
                                if (isset($importSetting['imageOption'])) {
                                    $imageOption = $importSetting['imageOption'];
                                }
                                if (!$this->previewMetadata && ($imageOption == 'only-metadata' || $imageOption == 'default-if-not-metadata')) {
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
                                            $this->stdout(PHP_EOL . "    - Image saved as an asset $newAsset->id", Console::FG_GREEN);
                                            $imageId = $newAsset->id;
                                        } else {
                                            $errors = array_values($newAsset->getFirstErrors());
                                            if (isset($errors[0])) {
                                                $this->stdout(PHP_EOL . 'Error on extracting image from file');
                                            }
                                        }
                                    } elseif ($imageOption == 'default-if-not-metadata') {
                                        $useDefaultImage = true;
                                        $this->stdout(PHP_EOL . "    - No image extracted from file. default image is used", Console::FG_GREEN);
                                    }
                                }

                                if (
                                    !$this->previewMetadata &&
                                    ($useDefaultImage || $imageOption == 'only-default')
                                    && isset($importSetting['defaultImage']) && is_array($importSetting['defaultImage'])
                                ) {
                                    foreach ($importSetting['defaultImage'] as $defaultElementImg) {
                                        $elementImg = \craft\elements\Asset::find()
                                            ->id($defaultElementImg)
                                            ->one();
                                        if ($elementImg) {
                                            $this->stdout(PHP_EOL . "    - Default Image is used", Console::FG_GREEN);
                                            $imageId = $elementImg->id;
                                        }
                                    }
                                }

                                if ($imageOption) {
                                    if (!$imageFieldContainer) {
                                        $imageFieldHandle = $imageField->handle;
                                        $oldValue = $element->{$imageFieldHandle}->one();
                                        if (!$this->previewMetadata && (!$oldValue || $this->overwriteImage) && ($imageId || $this->allowEmptyMetaValue)) {
                                            if ($this->overwriteImage) {
                                                $this->stdout(PHP_EOL . "    - Image is overwritten. old values: $oldValue", Console::FG_GREEN);
                                            } else {
                                                $this->stdout(PHP_EOL . "    - Image is saved", Console::FG_GREEN);
                                            }
                                            if ($imageId) {
                                                $element->{$imageFieldHandle} = [$imageId];
                                            } else {
                                                $element->{$imageFieldHandle} = [];
                                            }
                                        } elseif (!$this->previewMetadata && $oldValue && !$this->overwriteImage) {
                                            $this->stdout(PHP_EOL . "    - Overwriting of the image is not allowed", Console::FG_YELLOW);
                                        } elseif (!$this->previewMetadata && !$imageId && !$this->allowEmptyMetaValue) {
                                            $this->stdout(PHP_EOL . "    - Image is empty and empty value is not allowed", Console::FG_YELLOW);
                                        }
                                    } else {
                                        /** @var string|null $container0Type */
                                        $container0Type = null;
                                        /** @var string|null $container0Handle */
                                        $container0Handle = null;
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
                                        $itemBlockType = null;
                                        if ($container0Handle) {
                                            if ($container0Type && ($container0Type === 'SuperTable')) {
                                                $superTableField = $fieldsService->getFieldByHandle($container0Handle);
                                                $blockTypes = SuperTable::$plugin->getService()->getBlockTypesByFieldId($superTableField->id);
                                                $blockType = $blockTypes[0];
                                                $itemBlockType = $blockType->id;
                                            } elseif ($container0Type) {
                                                $itemBlockType = $container1Handle;
                                            }
                                            $field = $fieldsService->getFieldByHandle($container0Handle);
                                            $existingMatrixQuery = $element->getFieldValue($container0Handle);
                                            $serializedMatrix = $field->serializeValue($existingMatrixQuery, $element);
                                            $sortOrder = array_keys($serializedMatrix);
                                            $oldValue = null;
                                            if (isset($serializedMatrix[$imgBlockId]['fields'][$imageField->handle])) {
                                                $oldValue = $serializedMatrix[$imgBlockId]['fields'][$imageField->handle];
                                                $oldValue = $oldValue[0];
                                            }
                                            //
                                            if (!$this->previewMetadata && ($this->overwriteImage || !$oldValue) && ($this->allowEmptyMetaValue || $imageId)) {
                                                if ($this->overwriteImage) {
                                                    $this->stdout(PHP_EOL . "    - Image is overwritten. old values: $oldValue - new value: $imageId", Console::FG_GREEN);
                                                } else {
                                                    $this->stdout(PHP_EOL . "    - Image is saved - new value: $imageId", Console::FG_GREEN);
                                                }
                                                if (isset($imgBlockId) && isset($serializedMatrix[$imgBlockId]['fields'][$imageField->handle])) {
                                                    if ($imageId) {
                                                        $serializedMatrix[$imgBlockId]['fields'][$imageField->handle] = [];
                                                        $serializedMatrix[$imgBlockId]['fields'][$imageField->handle][] = $imageId;
                                                        $element->setFieldValue($container0Handle, [
                                                            'sortOrder' => $sortOrder,
                                                            'blocks' => $serializedMatrix,
                                                        ]);
                                                    } else {
                                                        $serializedMatrix[$imgBlockId]['fields'][$imageField->handle] = [];
                                                        $element->setFieldValue($container0Handle, $serializedMatrix);
                                                    }
                                                } elseif (isset($blockId) && isset($serializedMatrix[$blockId]['fields'][$imageField->handle])) {
                                                    if ($imageId) {
                                                        $serializedMatrix[$blockId]['fields'][$imageField->handle] = [];
                                                        $serializedMatrix[$blockId]['fields'][$imageField->handle][] = $imageId;
                                                        $element->setFieldValue($container0Handle, $serializedMatrix);
                                                    } else {
                                                        $serializedMatrix[$blockId]['fields'][$imageField->handle] = [];
                                                        $element->setFieldValue($container0Handle, $serializedMatrix);
                                                    }
                                                } else {
                                                    $sortOrder[] = 'new:1';
                                                    $newBlock = [
                                                        'type' => $itemBlockType,
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
                                            }
                                            $field = $fieldsService->getFieldByHandle($container0Handle);
                                            $existingMatrixQuery = $element->getFieldValue($container0Handle);
                                            $serializedMatrix = $field->serializeValue($existingMatrixQuery, $element);
                                        }
                                    }
                                } elseif (!$this->previewMetadata) {
                                    $this->stdout(PHP_EOL . "    - Image import option is not specified", Console::FG_YELLOW);
                                }
                            } elseif (!$this->previewMetadata && !$imageField) {
                                $this->stdout(PHP_EOL . "    - Image field is not specified", Console::FG_YELLOW);
                            } elseif (!$this->previewMetadata && get_class($imageField) != 'craft\fields\Assets') {
                                $this->stdout(PHP_EOL . "    - Image field is not an asset field", Console::FG_YELLOW);
                            }
                        }
                    } elseif ($this->metadata || $this->imageMetadata) {
                        $this->stdout(PHP_EOL . "    - Main asset field is not found", Console::FG_YELLOW);
                    }
                } elseif ($this->metadata || $this->imageMetadata) {
                    $this->stdout(PHP_EOL . "    - Main asset field is not specified", Console::FG_YELLOW);
                }

                $this->stdout(PHP_EOL);

                if (isset($this->propagateTo)) {
                    // Set the full array for all sites, so the propagated element gets the right status
                    $siteStatuses = ElementHelper::siteStatusesForElement($element);
                    foreach ($this->propagateTo as $siteId) {
                        $siteStatuses[$siteId] = $this->setEnabledForSite ?? $siteStatuses[$siteId] ?? $element->getEnabledForSite();
                    }
                    $element->setEnabledForSite($siteStatuses);
                } else {
                    if (isset($this->setEnabledForSite)) {
                        // Just set it for this site
                        $element->setEnabledForSite($this->setEnabledForSite);
                    }

                    try {
                        if (isset($this->set) && (!$this->ifEmpty || ElementHelper::isAttributeEmpty($element, $this->set))) {
                            $element->{$this->set} = $to($element);
                        }
                    } catch (Throwable $e) {
                        throw new InvalidElementException($element, $e->getMessage());
                    }
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
        if (isset($this->propagateTo)) {
            $elementsService->on(Elements::EVENT_BEFORE_PROPAGATE_ELEMENT, $beforeCallback);
            $elementsService->on(Elements::EVENT_AFTER_PROPAGATE_ELEMENT, $afterCallback);
            $elementsService->propagateElements($query, $this->propagateTo, true);
            $elementsService->off(Elements::EVENT_BEFORE_PROPAGATE_ELEMENT, $beforeCallback);
            $elementsService->off(Elements::EVENT_AFTER_PROPAGATE_ELEMENT, $afterCallback);
        } else {
            $elementsService->on(Elements::EVENT_BEFORE_RESAVE_ELEMENT, $beforeCallback);
            $elementsService->on(Elements::EVENT_AFTER_RESAVE_ELEMENT, $afterCallback);
            $elementsService->resaveElements($query, true, !$this->revisions, $this->updateSearchIndex, $this->touch);
            $elementsService->off(Elements::EVENT_BEFORE_RESAVE_ELEMENT, $beforeCallback);
            $elementsService->off(Elements::EVENT_AFTER_RESAVE_ELEMENT, $afterCallback);
        }

        $label = isset($this->propagateTo) ? 'propagating' : 'resaving';

        $this->stdout("Done $label $elementsText." . PHP_EOL . PHP_EOL, Console::FG_YELLOW);
        return $fail ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
