<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\LocalFsInterface;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\DeleteSiteEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlDirectivesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fieldlayoutelements\TextField;
use craft\fieldlayoutelements\TitleField;
use craft\fs\Local;
use craft\helpers\Assets;
use craft\helpers\Cp;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Assets as AssetsServices;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\services\Sites;
use craft\services\UserPermissions;
use craft\utilities\AssetIndexes;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use Imagine\Exception\NotSupportedException;
use InvalidArgumentException;

use vnali\studio\assetpreviews\AudioPreview;
use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\elements\Podcast as PodcastElement;
use vnali\studio\fields\DurationField;
use vnali\studio\fields\EpisodeField;
use vnali\studio\fields\EpisodeTypeField;
use vnali\studio\fields\GUIDField;
use vnali\studio\fields\NativeLightswitchField;
use vnali\studio\fields\NumberField;
use vnali\studio\fields\PodcastField;
use vnali\studio\fields\PodcastTypeField;
use vnali\studio\gql\directives\SecToTime;
use vnali\studio\gql\interfaces\elements\EpisodeInterface;
use vnali\studio\gql\interfaces\elements\PodcastInterface;
use vnali\studio\gql\queries\EpisodeQuery;
use vnali\studio\gql\queries\PodcastQuery;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\helpers\Id3;
use vnali\studio\helpers\ProjectConfigData;
use vnali\studio\helpers\Time;
use vnali\studio\models\Settings;
use vnali\studio\records\PodcastEpisodeSettingsRecord;
use vnali\studio\records\PodcastFormatEpisodeRecord;
use vnali\studio\records\PodcastFormatRecord;
use vnali\studio\services\episodesService;
use vnali\studio\services\podcastFormatsService;
use vnali\studio\services\podcastsService;
use vnali\studio\services\settingsService;
use vnali\studio\Studio as StudioPlugin;
use vnali\studio\twig\CraftVariableBehavior;

use yii\base\Event;
use yii\web\BadRequestHttpException;

/**
 * @property-read episodesService $episodes
 * @property-read settingsService $pluginSettings
 * @property-read podcastsService $podcasts
 * @property-read podcastFormatsService $podcastFormats
 */
class Studio extends Plugin
{
    /**
     * @var Studio
     */
    public static Studio $plugin;

    public string $schemaVersion = '0.2.0';

    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'episodes' => episodesService::class,
                'podcasts' => podcastsService::class,
                'podcastFormats' => podcastFormatsService::class,
                'pluginSettings' => settingsService::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->hasCpSection = true;
        $this->hasCpSettings = true;

        $this->_registerProjectConfigEventListeners();
        $this->_registerFieldTypes();
        $this->_registerElementTypes();
        $this->_registerRules();
        $this->_registerPermissions();
        $this->_registerVariables();
        $this->_registerPreviewHandler();

        $gqlService = Craft::$app->getGql();
        $gqlService->flushCaches();
        $this->_registerGraphQl();

        // Check version before installing
        if (version_compare(Craft::$app->getInfo()->version, '4.4', '>=')) {
            $settings = StudioPlugin::$plugin->getSettings();
            /** @var Settings $settings */
            if ($settings->checkAccessToVolumes) {
                Event::on(AssetIndexes::class, AssetIndexes::EVENT_LIST_VOLUMES, [GeneralHelper::class, 'listVolumes']);
            }
        }

        Event::on(Cp::class, Cp::EVENT_DEFINE_ELEMENT_INNER_HTML, [PodcastElement::class, 'updatePodcastElementHtml']);

        Event::on(
            Element::class,
            Element::EVENT_AFTER_RESTORE,
            function(Event $event) {
                // Restore episodes which were deleted with podcast
                if (get_class($event->sender) == PodcastElement::class) {
                    $episodes = EpisodeElement::find()
                        ->podcastId($event->sender->id)
                        ->drafts(null)
                        ->draftOf(false)
                        ->status(null)
                        ->trashed()
                        ->site('*')
                        ->unique()
                        ->andWhere(['studio_episode.deletedWithPodcast' => true])
                        ->all();
                    Craft::$app->getElements()->restoreElements($episodes);
                }
            }
        );

        Event::on(FieldLayout::class, FieldLayout::EVENT_DEFINE_NATIVE_FIELDS, function(DefineFieldLayoutFieldsEvent $event) {
            /** @var FieldLayout $fieldLayout */
            $fieldLayout = $event->sender;

            switch ($fieldLayout->type) {
                case EpisodeElement::class:
                    $record = PodcastFormatEpisodeRecord::find()->where(['fieldLayoutId' => $fieldLayout->id])->one();
                    if ($record) {
                        $episodeNativeFieldSettings = json_decode($record['nativeSettings'], true);
                    }
                    $event->fields[] = [
                        'class' => TitleField::class,
                    ];
                    $event->fields[] = [
                        'class' => DurationField::class,
                        'attribute' => 'duration',
                        'mandatory' => false,
                        'requirable' => true,
                        'label' => Craft::t('studio', 'Duration'),
                        'translatable' => $episodeNativeFieldSettings['duration']['translatable'] ?? false,
                        'instructions' => Craft::t('studio', 'in HH:MM:SS format or enter in seconds'),
                    ];
                    $event->fields[] = [
                        'class' => NativeLightswitchField::class,
                        'attribute' => 'episodeBlock',
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Episode Block'),
                        'translatable' => $episodeNativeFieldSettings['episodeBlock']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => NativeLightswitchField::class,
                        'attribute' => 'episodeExplicit',
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Episode Explicit'),
                        'translatable' => $episodeNativeFieldSettings['episodeExplicit']['translatable'] ?? 0,
                    ];
                    $event->fields[] = [
                        'class' => NumberField::class,
                        'attribute' => 'episodeSeason',
                        'requirable' => true,
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Episode Season'),
                        'translatable' => $episodeNativeFieldSettings['episodeSeason']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => NumberField::class,
                        'attribute' => 'episodeNumber',
                        'requirable' => true,
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Episode Number'),
                        'translatable' => $episodeNativeFieldSettings['episodeNumber']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => EpisodeTypeField::class,
                        'attribute' => 'episodeType',
                        'requirable' => true,
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Episode Type'),
                        'translatable' => $episodeNativeFieldSettings['episodeType']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => GUIDField::class,
                        'attribute' => 'episodeGUID',
                        'requirable' => true,
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'GUID'),
                        'translatable' => $episodeNativeFieldSettings['episodeGUID']['translatable'] ?? false,
                    ];
                    break;
                case PodcastElement::class:
                    $record = PodcastFormatRecord::find()->where(['fieldLayoutId' => $fieldLayout->id])->one();
                    if ($record) {
                        $podcastNativeFieldSettings = json_decode($record['nativeSettings'], true);
                    }
                    $event->fields[] = [
                        'class' => TitleField::class,
                    ];
                    $event->fields[] = [
                        'class' => TextField::class,
                        'mandatory' => false,
                        'requirable' => true,
                        'label' => Craft::t('studio', 'Owner name'),
                        'translatable' => $podcastNativeFieldSettings['ownerName']['translatable'] ?? false,
                        'attribute' => 'ownerName',
                    ];
                    $event->fields[] = [
                        'class' => TextField::class,
                        'mandatory' => false,
                        'requirable' => true,
                        'label' => Craft::t('studio', 'Owner email'),
                        'translatable' => $podcastNativeFieldSettings['ownerEmail']['translatable'] ?? false,
                        'attribute' => 'ownerEmail',
                    ];
                    $event->fields[] = [
                        'class' => TextField::class,
                        'mandatory' => false,
                        'requirable' => true,
                        'label' => Craft::t('studio', 'Author name'),
                        'translatable' => $podcastNativeFieldSettings['authorName']['translatable'] ?? false,
                        'attribute' => 'authorName',
                    ];
                    $event->fields[] = [
                        'class' => NativeLightswitchField::class,
                        'attribute' => 'podcastBlock',
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Podcast Block'),
                        'translatable' => $podcastNativeFieldSettings['podcastBlock']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => NativeLightswitchField::class,
                        'attribute' => 'podcastComplete',
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Podcast Complete'),
                        'translatable' => $podcastNativeFieldSettings['podcastComplete']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => NativeLightswitchField::class,
                        'attribute' => 'podcastExplicit',
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Podcast Explicit'),
                        'translatable' => $podcastNativeFieldSettings['podcastExplicit']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => PodcastTypeField::class,
                        'attribute' => 'podcastType',
                        'mandatory' => false,
                        'requirable' => true,
                        'label' => Craft::t('studio', 'Podcast Type'),
                        'translatable' => $podcastNativeFieldSettings['podcastType']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => TextField::class,
                        'attribute' => 'copyright',
                        'mandatory' => false,
                        'requirable' => true,
                        'label' => Craft::t('studio', 'Copyright'),
                        'translatable' => $podcastNativeFieldSettings['copyright']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => TextField::class,
                        'attribute' => 'podcastLink',
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Podcast link'),
                        'translatable' => $podcastNativeFieldSettings['podcastLink']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => TextField::class,
                        'attribute' => 'podcastRedirectTo',
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Redirect to'),
                        'translatable' => $podcastNativeFieldSettings['podcastRedirectTo']['translatable'] ?? false,
                    ];
                    $event->fields[] = [
                        'class' => NativeLightswitchField::class,
                        'attribute' => 'podcastIsNewFeedUrl',
                        'mandatory' => false,
                        'label' => Craft::t('studio', 'Is New Feed Url'),
                        'translatable' => $podcastNativeFieldSettings['podcastIsNewFeedUrl']['translatable'] ?? false,
                    ];
                    break;
            }
        });

        Event::on(
            \craft\services\Elements::class,
            \craft\services\Elements::EVENT_AFTER_SAVE_ELEMENT,
            function(\craft\events\ElementEvent $event) {
                if (!Craft::$app->getRequest()->getIsConsoleRequest() && Craft::$app->request->pathInfo == 'actions/asset-indexes/process-indexing-session') {
                    // It's coming from asset index utility, try to create episode for each audio, video file.
                    $element = $event->element;
                    if (
                        is_a($element, Asset::class) && $element->firstSave && !$element->propagating &&
                        in_array($element->kind, ['audio', 'video'])
                    ) {
                        Craft::info($element->kind . 'kind:');
                        $found = false;
                        $importSettings = PodcastEpisodeSettingsRecord::find()->where(['enable' => 1])->all();
                        // Check which podcast setting allows for importing episodes.
                        /** @var PodcastEpisodeSettingsRecord $importSetting */
                        foreach ($importSettings as $importSetting) {
                            $importSetting = json_decode($importSetting->settings, true);
                            if (isset($importSetting['volumesImport']) && is_array($importSetting['volumesImport']) && in_array($element->volumeId, $importSetting['volumesImport'])) {
                                Craft::info('Importing episode via asset index');
                                // TODO: don't let create episode if user can't have access to the volume
                                $this->_importItem($element, 'episode', $importSetting);
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            craft::info('No volume set for importing studio items');
                        }
                    }
                }
            }
        );
    }

    /**
     * Registers event listeners for project config changes.
     *
     * @return void
     */
    private function _registerProjectConfigEventListeners(): void
    {
        // Podcast formats
        Craft::$app->getProjectConfig()
            ->onAdd(podcastFormatsService::CONFIG_PODCAST_FORMATS_KEY . '.{uid}', [Studio::$plugin->podcastFormats, 'handleChangedPodcastFormat'])
            ->onUpdate(podcastFormatsService::CONFIG_PODCAST_FORMATS_KEY . '.{uid}', [Studio::$plugin->podcastFormats, 'handleChangedPodcastFormat'])
            ->onRemove(podcastFormatsService::CONFIG_PODCAST_FORMATS_KEY . '.{uid}', [Studio::$plugin->podcastFormats, 'handleDeletedPodcastFormat']);

        // Prune deleted sites from site settings
        Event::on(Sites::class, Sites::EVENT_AFTER_DELETE_SITE, function(DeleteSiteEvent $event) {
            if (!Craft::$app->getProjectConfig()->getIsApplyingExternalChanges()) {
                Studio::$plugin->podcastFormats->pruneDeletedSite($event);
            }
        });

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            $event->config['studio'] = ProjectConfigData::rebuildProjectConfig();
        });
    }

    private function _registerGraphQl(): void
    {
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            // Add the plugin GraphQL schema permissions
            $podcastFormats = Studio::$plugin->podcastFormats->getAllPodcastFormats();
            if (!empty($podcastFormats)) {
                $label = Craft::t('studio', 'Studio');
                $event->queries[$label]['podcastFormats.all:read'] = ['label' => Craft::t('studio', 'View all podcastFormats')];

                foreach ($podcastFormats as $podcastFormat) {
                    $suffix = 'podcastFormats.' . $podcastFormat->uid;

                    $event->queries[$label][$suffix . ':read'] = [
                        'label' => Craft::t('studio', 'View podcast format - {podcastFormat}', ['podcastFormat' => Craft::t('studio', $podcastFormat->name)]),
                    ];
                }
            }
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_TYPES, static function(RegisterGqlTypesEvent $event) {
            // Add the plugin GraphQL types
            $types = $event->types;
            $types[] = PodcastInterface::class;
            $types[] = EpisodeInterface::class;
            $event->types = $types;
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, static function(RegisterGqlQueriesEvent $event) {
            // Add the plugin GraphQL queries
            $event->queries = array_merge(
                $event->queries,
                PodcastQuery::getQueries(),
                EpisodeQuery::getQueries(),
            );
        });

        // Register the GraphQL directive
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_DIRECTIVES, function(RegisterGqlDirectivesEvent $event) {
            $event->directives[] = SecToTime::class;
        });
    }

    /**
     * Register plugin field types.
     *
     * @return void
     */
    private function _registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = EpisodeField::class;
                $event->types[] = PodcastField::class;
            }
        );
    }

    /**
     * Create items (currently only episodes) from assets index
     *
     * @param ElementInterface $element
     * @param string $item
     * @param array $importSetting
     * @return void
     */
    private function _importItem(ElementInterface $element, string $item, array $importSetting): void
    {
        // PHP Stan fix
        if (!$element instanceof Asset) {
            throw new InvalidArgumentException('Import item can only be used for asset elements.');
        }

        if ($item == 'episode') {
            $podcastId = $importSetting['podcastId'];
            $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId);
            $podcastFormat = $podcast->getPodcastFormat();
            $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
            $itemElement = new EpisodeElement();
            $itemElement->episodeGUID = StringHelper::UUID();
            $itemElement->podcastId = $podcastId;
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

        if (isset($mapping['mainAsset']['container'])) {
            $itemFieldContainer = $mapping['mainAsset']['container'];
        }
        if (isset($mapping['mainAsset']['field']) && $mapping['mainAsset']['field']) {
            $itemFieldId = $mapping['mainAsset']['field'];
            $itemField = Craft::$app->fields->getFieldByUid($itemFieldId);
            if ($itemField) {
                $itemFieldHandle = $itemField->handle;
            }
        }

        if (!isset($itemFieldHandle)) {
            craft::warning("$item file is not specified in setting");
            return;
        } else {
            craft::warning('warning' . $itemFieldHandle);
        }

        list($imageField, $imageFieldContainer) = GeneralHelper::getElementImageField($item, $mapping);

        list(, $tagField) = GeneralHelper::getElementTagField($item, $mapping);
        $tagFieldHandle = null;
        if ($tagField) {
            $tagFieldHandle = $tagField->handle;
        }

        list(, $categoryField) = GeneralHelper::getElementCategoryField($item, $mapping);
        $categoryFieldHandle = null;
        if ($categoryField) {
            $categoryFieldHandle = $categoryField->handle;
        }

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

        if (isset($fileInfo['playtime_string'])) {
            $duration = $fileInfo['playtime_string'];
            if ($duration) {
                if (!ctype_digit((string)$duration)) {
                    $duration = Time::time_to_sec($duration);
                }
                $itemElement->duration = $duration;
            }
        }

        if (isset($fileInfo['tags']['id3v2']['title'][0])) {
            $title = $fileInfo['tags']['id3v2']['title'][0];
        }

        if (!isset($title) || !$title) {
            $title = $element->title;
        }

        $itemElement->title = $title;

        list($yearField) = GeneralHelper::getElementYearField($item, $mapping);

        if (isset($yearField)) {
            $forceYear = $importSetting['forceYear'];
            $yearOnImport = $importSetting['yearOnImport'];
            $year = Id3::getYear('import', $fileInfo, $forceYear, $yearOnImport);
            $itemElement->{$yearField->handle} = $year;
        }

        if (isset($fileInfo['tags']['id3v2']['track_number'][0])) {
            $track = trim($fileInfo['tags']['id3v2']['track_number'][0]);
            $itemElement->episodeNumber = (int)$track;
        }

        $genreIds = [];
        if ($genreFieldHandle) {
            $tagImportOptions = $importSetting['genreImportOption'];
            $tagImportCheck = $importSetting['genreImportCheck'];
            $defaultGenres = $importSetting['genreOnImport'];

            list($genreIds,) = Id3::getGenres($fileInfo, $genreFieldType, $genreFieldGroup->id, $tagImportOptions, $tagImportCheck, $defaultGenres);
        }

        // Genre field might be overlapped with tag field or category field
        if ($genreFieldHandle != $tagFieldHandle && $genreFieldHandle != $categoryFieldHandle) {
            $columns = [];
            if ($genreFieldHandle) {
                $columns[$genreFieldHandle] = $genreIds;
            }
            if ($tagFieldHandle) {
                $columns[$tagFieldHandle] = $importSetting['tagOnImport'];
            }
            if ($categoryFieldHandle) {
                $columns[$categoryFieldHandle] = $importSetting['categoryOnImport'];
            }
            $itemElement->setFieldValues($columns);
        } elseif ($genreFieldHandle == $tagFieldHandle && $genreFieldHandle != $categoryFieldHandle) {
            $columns = [];
            if ($genreFieldHandle) {
                $tagIds = $importSetting['tagOnImport'];
                if (is_array($tagIds)) {
                    foreach ($tagIds as $tagId) {
                        if (!in_array($tagId, $genreIds)) {
                            $genreIds[] = $tagId;
                        }
                    }
                }
                $columns[$genreFieldHandle] = $genreIds;
            }
            if ($categoryFieldHandle) {
                $columns[$categoryFieldHandle] = $importSetting['categoryOnImport'];
            }
            $itemElement->setFieldValues($columns);
        } elseif ($genreFieldHandle != $tagFieldHandle && $genreFieldHandle == $categoryFieldHandle) {
            $columns = [];
            if ($genreFieldHandle) {
                $categoryIds = $importSetting['categoryOnImport'];
                if ($categoryIds) {
                    foreach ($categoryIds as $categoryId) {
                        if (!in_array($categoryId, $genreIds)) {
                            $genreIds[] = $categoryId;
                        }
                    }
                }
                $columns[$genreFieldHandle] = $genreIds;
            }
            if ($tagFieldHandle) {
                $columns[$tagFieldHandle] = $importSetting['tagOnImport'];
            }
            $itemElement->setFieldValues($columns);
        }

        // Set site status for episode
        $siteId = null;
        $siteStatus = [];
        foreach ($sitesSettings as $key => $siteSetting) {
            if (!$siteId) {
                $siteId = $key;
            }
            //$siteStatus[$key] = $siteSetting[$item . 'EnabledByDefault'];
            // To Prevent unwanted content on RSS or site, we force disabled status to be checked by admin first
            // also if we use enabled status by default, there is a chance that doesn't save due to validation error like required rules
            $siteStatus[$key] = false;
        }
        if (!$siteId) {
            Craft::warning("not any site is enabled for $item");
        }
        $itemElement->siteId = $siteId;
        $itemElement->setEnabledForSite($siteStatus);

        if (!$itemFieldContainer) {
            // TODO: check if we can set an asset to an item field which volume of that asset is not supported by field volume
            $itemElement->{$itemFieldHandle} = [$element->id];
        } else {
            /** @var string|null $container0Type */
            $container0Type = null;
            /** @var string|null $container0Handle */
            $container0Handle = null;
            /** @var string|null $container1Type */
            $container1Type = null;
            /** @var string|null $container1Handle */
            $container1Handle = null;
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

            if ($container0Handle && $container1Handle) {
                if ($container0Type == 'Matrix' && $container1Type == 'BlockType') {
                    $sortOrder[] = 'new:1';
                    $newBlock = [
                        'type' => $container1Handle,
                        'fields' => [
                            $itemFieldHandle => [$element->id],
                        ],
                    ];
                    $itemElement->setFieldValue($container0Handle, [
                        'sortOrder' => $sortOrder,
                        'blocks' => [
                            'new:1' => $newBlock,
                        ],
                    ]);
                } elseif ($container0Type == 'SuperTable') {
                    $sortOrder[] = 'new:1';
                    $newBlock = [
                        'type' => $container1Handle,
                        'fields' => [
                            $itemFieldHandle => [$element->id],
                        ],
                    ];
                    $itemElement->setFieldValue($container0Handle, [
                        'sortOrder' => $sortOrder,
                        'blocks' => [
                            'new:1' => $newBlock,
                        ],
                    ]);
                }
            }
        }

        // Get image Id3 meta data from audio and create a Craft asset
        if (isset($imageField)) {
            $imageIds = [];
            $forceImage = $importSetting['forceImage'];
            if (!$forceImage) {
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
                            $forceImage = true;
                            craft::Warning('can not save save image asset' . $assetFilename . json_encode($imgAsset));
                        }
                    } else {
                        $forceImage = true;
                        craft::Warning('no image from meta for ' . $assetFilename);
                    }
                } else {
                    $forceImage = true;
                }
            }

            if ($forceImage && isset($importSetting['imageOnImport']) && is_array($importSetting['imageOnImport'])) {
                foreach ($importSetting['imageOnImport'] as $defaultElementImg) {
                    $elementImg = \craft\elements\Asset::find()
                        ->id($defaultElementImg)
                        ->one();
                    if ($elementImg) {
                        $imageIds[] = $elementImg->id;
                    }
                }
            }

            // Set created asset to specified custom field for image
            if (!$imageFieldContainer) {
                $itemElement->{$imageField->handle} = $imageIds;
            } else {
                /** @var string|null $container0Type */
                $container0Type = null;
                /** @var string|null $container0Handle */
                $container0Handle = null;
                /** @var string|null $container1Type */
                $container1Type = null;
                /** @var string|null $container1Handle */
                $container1Handle = null;
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

                if ($container0Handle && $container1Handle) {
                    if ($container0Type == 'Matrix' && $container1Type == 'BlockType') {
                        $sortOrder[] = 'new:1';
                        $newBlock = [
                            'type' => $container1Handle,
                            'fields' => [
                                $imageField->handle => $imageIds,
                            ],
                        ];
                        $itemElement->setFieldValue($container0Handle, [
                            'sortOrder' => $sortOrder,
                            'blocks' => [
                                'new:1' => $newBlock,
                            ],
                        ]);
                    } elseif ($container0Type == 'SuperTable') {
                        $sortOrder[] = 'new:1';
                        $newBlock = [
                            'type' => $container1Handle,
                            'fields' => [
                                $imageField->handle => $imageIds,
                            ],
                        ];
                        $itemElement->setFieldValue($container0Handle, [
                            'sortOrder' => $sortOrder,
                            'blocks' => [
                                'new:1' => $newBlock,
                            ],
                        ]);
                    }
                }
            }
        }

        if (!Craft::$app->getElements()->saveElement($itemElement)) {
            craft::warning("$item Creation error" . json_encode($itemElement->getErrors()));
        }
    }

    /**
     * Register plugin element types.
     *
     * @return void
     */
    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = EpisodeElement::class;
                $event->types[] = PodcastElement::class;
            }
        );
    }

    /**
     * Register CP Url and site rules.
     *
     * @return void
     */
    private function _registerRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['studio/default/fields-filter'] = 'studio/default/fields-filter';
                $event->rules['studio/default/meta'] = 'studio/default/meta';
                $event->rules['studio/default/get-entry-types'] = 'studio/default/get-entry-types';
                $event->rules['studio/episodes/<podcastHandle:{handle}>'] = ['template' => 'studio/episodes'];
                $event->rules['studio/episodes/<podcastHandle:{handle}>/new'] = 'studio/episodes/create';
                $event->rules['studio/episodes/edit/<elementId:\d+>'] = 'elements/edit';
                $event->rules['studio/episodes/import-from-asset-index'] = 'studio/episodes/import-from-asset-index';
                $event->rules['studio/episodes/import-from-rss'] = 'studio/episodes/import-from-rss';
                $event->rules['studio/episodes/new'] = 'studio/episodes/create';
                $event->rules['studio/import'] = 'studio/import/default';
                $event->rules['studio/import/category'] = 'studio/import/category';
                $event->rules['studio/import/episode-fields'] = 'studio/import/episode-fields';
                $event->rules['studio/import/podcast-fields'] = 'studio/import/podcast-fields';
                $event->rules['studio/podcasts/edit/<elementId:\d+>'] = 'elements/edit';
                $event->rules['studio/podcasts/new'] = 'studio/podcasts/create';
                $event->rules['studio/podcasts/podcast-episode-settings'] = 'studio/podcasts/podcast-episode-settings';
                $event->rules['studio/podcasts/podcast-general-settings'] = 'studio/podcasts/podcast-general-settings';
                $event->rules['studio/settings/general'] = 'studio/settings/general';
                $event->rules['studio/settings/podcast-formats'] = 'studio/podcast-formats/index';
                $event->rules['studio/settings/podcast-formats/<podcastFormatId:\d+>'] = 'studio/podcast-formats/edit';
                $event->rules['studio/settings/podcast-formats/new'] = 'studio/podcast-formats/edit';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['podcasts/rss'] = 'studio/podcasts/rss';
            }
        );
    }

    /**
     * @inheritDoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        $url = UrlHelper::cpUrl('studio/settings');
        return Craft::$app->getResponse()->redirect($url);
    }

    /**
     * @inheritDoc
     */
    public function getCpNavItem(): ?array
    {
        $allowAdminChanges = Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        $nav = parent::getCpNavItem();

        $nav['label'] = Craft::t('studio', 'Studio');

        // Show Podcast nav item
        $hasAccess = false;
        if (Craft::$app->getUser()->checkPermission('studio-managePodcasts') || Craft::$app->getUser()->checkPermission('studio-createDraftPodcasts')) {
            $hasAccess = true;
        } else {
            $podcasts = PodcastElement::find()->status(null)->all();
            foreach ($podcasts as $key => $podcast) {
                if (Craft::$app->getUser()->checkPermission('studio-viewPodcasts-' . $podcast->uid)) {
                    $hasAccess = true;
                    break;
                }
            }
        }
        if ($hasAccess) {
            $nav['subnav']['podcasts'] = [
                'label' => Craft::t('studio', 'Podcasts'),
                'url' => 'studio/podcasts',
            ];
        }

        // Show Episodes nav item
        $hasAccess = false;
        if (Craft::$app->getUser()->checkPermission('studio-manageEpisodes')) {
            $hasAccess = true;
        } else {
            $podcasts = PodcastElement::find()->status(null)->all();
            foreach ($podcasts as $podcast) {
                if (Craft::$app->getUser()->checkPermission('studio-viewPodcasts-' . $podcast->uid)) {
                    $hasAccess = true;
                    break;
                }
            }
        }
        if ($hasAccess) {
            $nav['subnav']['episodes'] = [
                'label' => Craft::t('studio', 'Episodes'),
                'url' => 'studio/episodes',
            ];
        }

        // Import
        if (Craft::$app->getUser()->checkPermission('studio-importCategory')) {
            $nav['subnav']['import'] = [
                'label' => Craft::t('studio', 'Import'),
                'url' => 'studio/import',
            ];
        }

        // Settings
        if ($allowAdminChanges && Craft::$app->getUser()->checkPermission('studio-manageSettings')) {
            $nav['subnav']['settings'] = [
                'label' => Craft::t('studio', 'Settings'),
                'url' => 'studio/settings',
            ];
        }

        return $nav;
    }

    /**
     * Register plugin services and element queries
     *
     * @return void
     */
    private function _registerVariables(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->attachBehavior('studio', CraftVariableBehavior::class);
        });
    }


    private function _registerPermissions()
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $podcastPermissions = [];
                $podcasts = PodcastElement::find()->status(null)->all();
                $viewPodcastPermissions = [];
                $permissions = [
                    'studio-managePodcasts' => [
                        'label' => Craft::t('studio', 'Manage Podcasts'),
                        'info' => Craft::t('studio', 'Includes viewing/creating/deleting podcasts'),
                    ],
                    'studio-manageEpisodes' => [
                        'label' => Craft::t('studio', 'Manage Episodes'),
                        'info' => Craft::t('studio', 'Includes viewing/creating/deleting episodes'),
                    ],
                    'studio-createDraftPodcasts' => [
                        'label' => Craft::t('studio', 'Create draft for podcasts'),
                        'info' => Craft::t('studio', 'Includes viewing/creating draft podcasts'),
                    ],
                ];
                foreach ($podcasts as $podcast) {
                    $podcastPermissions = [];
                    $episodeCreatePermissions = [];
                    $podcastCreatePermissions = [];
                    $podcastCreatePermissions['studio-editPodcasts-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Edit {name} podcast.', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $podcastPermissions['studio-createDraftPodcasts-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Create draft for {name} podcast.', [
                            'name' => $podcast->title,
                        ]),
                        'nested' => $podcastCreatePermissions,
                    ];
                    $podcastPermissions['studio-deletePodcasts-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete {name} podcast.', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $podcastPermissions['studio-editPodcastGeneralSettings-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Set general settings for {name}.', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $podcastPermissions['studio-editPodcastEpisodeSettings-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Set episode settings for {name}.', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $episodeCreatePermissions['studio-createEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Create episode for {name}.', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $podcastPermissions['studio-createDraftEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Create draft episode for {name}.', [
                            'name' => $podcast->title,
                        ]),
                        'nested' => $episodeCreatePermissions,
                    ];
                    // Permission for importing episodes to this podcast from RSS
                    $podcastPermissions['studio-importEpisodeByRSS-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'import episodes by URL'),
                    ];
                    // Permission for set settings to import episodes from Asset index utility
                    $podcastPermissions['studio-importEpisodeByAssetIndex-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'import episodes by Asset index'),
                    ];
                    $podcastPermissions['studio-deleteEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete episode from {name}.', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $viewPodcastPermissions['studio-viewPodcasts-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'View {name} episodes.', [
                            'name' => $podcast->title,
                        ]),
                        'nested' => $podcastPermissions,
                    ];
                }
                $permissions += $viewPodcastPermissions;
                $permissions += [
                    'studio-importCategory' => ['label' => Craft::t('studio', 'Import Category')],
                ];
                $permissions += [
                    'studio-manageSettings' => ['label' => Craft::t('studio', 'Manage Settings')],
                ];
                $event->permissions[] = [
                    'heading' => Craft::t('studio', 'Studio'),
                    'permissions' => $permissions,
                ];
            }
        );
    }

    /**
     * Register preview handler for previewing audio files
     *
     * @return void
     */
    private function _registerPreviewHandler(): void
    {
        Event::on(
            AssetsServices::class,
            AssetsServices::EVENT_REGISTER_PREVIEW_HANDLER,
            function(\craft\events\AssetPreviewEvent $event) {
                if ($event->asset->kind === Asset::KIND_AUDIO) {
                    $event->previewHandler = new AudioPreview($event->asset);
                }
            }
        );
    }
}
