<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio;

use Craft;
use craft\base\Element;
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
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Assets as AssetsServices;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\services\Sites;
use craft\services\UserPermissions;
use craft\utilities\AssetIndexes;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;

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
use vnali\studio\helpers\ProjectConfigData;
use vnali\studio\models\Settings;
use vnali\studio\records\PodcastEpisodeSettingsRecord;
use vnali\studio\records\PodcastFormatEpisodeRecord;
use vnali\studio\records\PodcastFormatRecord;
use vnali\studio\services\episodesService;
use vnali\studio\services\ImporterService;
use vnali\studio\services\podcastFormatsService;
use vnali\studio\services\podcastsService;
use vnali\studio\services\settingsService;
use vnali\studio\Studio as StudioPlugin;
use vnali\studio\twig\CraftVariableBehavior;

use yii\base\Event;

/**
 * @property-read episodesService $episodes
 * @property-read importerService $importer
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
                'importer' => ImporterService::class,
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
        $this->_registerGarbageCollection();

        $gqlService = Craft::$app->getGql();
        $gqlService->flushCaches();
        $this->_registerGraphQl();

        $settings = StudioPlugin::$plugin->getSettings();
        /** @var Settings $settings */
        if ($settings->checkAccessToVolumes) {
            Event::on(AssetIndexes::class, AssetIndexes::EVENT_LIST_VOLUMES, [GeneralHelper::class, 'listVolumes']);
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
                            if (isset($importSetting['volumes']) && is_array($importSetting['volumes']) && in_array($element->volumeId, $importSetting['volumes'])) {
                                Craft::info('Importing episode via asset index');
                                // TODO: don't let create episode if user can't have access to the volume
                                Studio::$plugin->importer->ImportByAssetIndex($element, 'episode', $importSetting);
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
                $event->rules['studio/episodes/import-from-asset-index'] = 'studio/episodes/import-from-asset-index';
                $event->rules['studio/episodes/import-from-rss'] = 'studio/episodes/import-from-rss';
                $event->rules['studio/episodes/<podcastHandle>'] = ['template' => 'studio/episodes'];
                $event->rules['studio/episodes/<podcastHandle>/new'] = 'studio/episodes/create';
                $event->rules['studio/episodes/edit/<elementId:\d+>'] = 'elements/edit';
                $event->rules['studio/import'] = 'studio/import/default';
                $event->rules['studio/import/category'] = 'studio/import/category';
                $event->rules['studio/import/episode-fields'] = 'studio/import/episode-fields';
                $event->rules['studio/import/podcast-fields'] = 'studio/import/podcast-fields';
                $event->rules['studio/podcasts/edit/<elementId:\d+>'] = 'elements/edit';
                $event->rules['studio/podcasts/<podcastFormat>/new'] = 'studio/podcasts/create';
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
        if (Craft::$app->getUser()->checkPermission('studio-managePodcasts') || Craft::$app->getUser()->checkPermission('studio-createDraftNewPodcasts')) {
            $hasAccess = true;
        } else {
            $podcasts = PodcastElement::find()->status(null)->all();
            foreach ($podcasts as $key => $podcast) {
                if (Craft::$app->getUser()->checkPermission('studio-viewPodcast-' . $podcast->uid)) {
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
                if (Craft::$app->getUser()->checkPermission('studio-viewPodcastEpisodes-' . $podcast->uid)) {
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
                $permissions = [
                    'studio-managePodcasts' => [
                        'label' => Craft::t('studio', 'Manage Podcasts'),
                        'info' => Craft::t('studio', 'Includes viewing/creating/deleting and other actions for all podcasts'),
                    ],
                    'studio-manageEpisodes' => [
                        'label' => Craft::t('studio', 'Manage Episodes'),
                        'info' => Craft::t('studio', 'Includes viewing/creating/deleting and other actions for all episodes'),
                    ],
                    'studio-createDraftNewPodcasts' => [
                        'label' => Craft::t('studio', 'Create a draft for new podcasts'),
                        'info' => Craft::t('studio', 'Includes creating/viewing/resaving/deleting those drafts'),
                    ],
                    'studio-importCategory' => ['label' => Craft::t('studio', 'Import Category')],
                    'studio-manageSettings' => ['label' => Craft::t('studio', 'Manage plugin Settings')],
                ];
                $event->permissions[] = [
                    'heading' => Craft::t('studio', 'Studio'),
                    'permissions' => $permissions,
                ];
                foreach ($podcasts as $podcast) {
                    $podcastPermissions = [];
                    $nestedViewPodcast = [];
                    $nestedCreateDraftPodcast = [];
                    $nestedViewOtherUserDraftPodcast = [];
                    $nestedViewEpisodes = [];
                    $nestedCreateDraftEpisodes = [];
                    $nestedViewOtherUserDraftEpisodes = [];
                    $nestedViewOtherUserEpisodes = [];
                    $nestedCreateDraftPodcast['studio-editPodcast-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Edit the podcast', [
                            'name' => $podcast->title,
                        ]),
                        'info' => Craft::t('studio', 'Includes saving a podcast as published. For publishing other user drafts, user also need save other user drafts permission.'),
                    ];
                    $nestedViewPodcast['studio-createDraftPodcast-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Create drafts for the podcast', [
                            'name' => $podcast->title,
                        ]),
                        'nested' => $nestedCreateDraftPodcast,
                    ];
                    $nestedViewPodcast['studio-deleteDraftPodcast-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete own drafts for the podcast', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewOtherUserDraftPodcast['studio-saveOtherUserDraftPodcast-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Save other user drafts for the podcast', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewOtherUserDraftPodcast['studio-deleteOtherUserDraftPodcast-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete other user drafts for the podcast', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewPodcast['studio-viewOtherUserDraftPodcast-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'View other user drafts for the podcast', [
                            'name' => $podcast->title,
                        ]),
                        'nested' => $nestedViewOtherUserDraftPodcast,
                    ];
                    $nestedViewPodcast['studio-deletePodcast-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete the podcast', [
                            'name' => $podcast->title,
                        ]),
                        'info' => Craft::t('studio', 'Includes deleting the podcast and its drafts'),
                    ];
                    $nestedViewPodcast['studio-editPodcastGeneralSettings-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Set general settings for the podcast', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewPodcast['studio-editPodcastEpisodeSettings-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Set episode settings for the podcast', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $podcastPermissions['studio-viewPodcast-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'View the podcast', [
                            'name' => $podcast->title,
                        ]),
                        'nested' => $nestedViewPodcast,
                    ];
                    $nestedCreateDraftEpisodes['studio-createEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Create episodes', [
                            'name' => $podcast->title,
                        ]),
                        'info' => Craft::t('studio', 'Includes saving an episode as published. For publishing other user draft episodes, user also need save other user drafts permission'),
                    ];
                    $nestedViewEpisodes['studio-createDraftEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Create draft episodes', [
                            'name' => $podcast->title,
                        ]),
                        'info' => Craft::t('studio', 'Includes creating drafts from own episodes and drafts. The user can create drafts from other user episodes/drafts if related save other user permissions are selected'),
                        'nested' => $nestedCreateDraftEpisodes,
                    ];
                    $nestedViewOtherUserDraftEpisodes['studio-saveOtherUserDraftEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Save other user drafts for episodes', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewOtherUserDraftEpisodes['studio-deleteOtherUserDraftEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete other user drafts for episodes', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewEpisodes['studio-deleteDraftEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete own drafts for episodes', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewEpisodes['studio-deleteEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete own episodes', [
                            'name' => $podcast->title,
                        ]),
                        'info' => Craft::t('studio', 'Includes deleting episodes created by the user'),
                    ];
                    $nestedViewEpisodes['studio-viewOtherUserDraftEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'View other user drafts for episodes', [
                            'name' => $podcast->title,
                        ]),
                        'nested' => $nestedViewOtherUserDraftEpisodes,
                    ];
                    $nestedViewOtherUserEpisodes['studio-saveOtherUserEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Save other user episodes', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewOtherUserEpisodes['studio-deleteOtherUserEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Delete other user episodes', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $nestedViewEpisodes['studio-viewOtherUserEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'View other user episodes', [
                            'name' => $podcast->title,
                        ]),
                        'nested' => $nestedViewOtherUserEpisodes,
                    ];
                    // Permission for importing episodes to this podcast from RSS
                    $nestedViewEpisodes['studio-importEpisodeByRSS-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Import episodes by URL'),
                    ];
                    // Permission for set settings to import episodes from Asset index utility
                    $nestedViewEpisodes['studio-importEpisodeByAssetIndex-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'Import episodes by Asset index'),
                    ];
                    $podcastPermissions['studio-viewPodcastEpisodes-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'View episodes'),
                        'nested' => $nestedViewEpisodes,
                    ];
                    $podcastPermissions['studio-viewNotPublishedRSS-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'View not published RSS', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $podcastPermissions['studio-viewPublishedRSS-' . $podcast->uid] = [
                        'label' => Craft::t('studio', 'View published RSS', [
                            'name' => $podcast->title,
                        ]),
                    ];
                    $event->permissions[] = [
                        'heading' => Craft::t('studio', 'Studio - {name} podcast', [
                            'name' => $podcast->title,
                        ]),
                        'permissions' => $podcastPermissions,
                    ];
                }
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

    /**
     * Register the items that need to be garbage collected
     *
     * @return void
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function(Event $event) {
            $gc = Craft::$app->getGc();
            $gc->deletePartialElements(PodcastElement::class, '{{%studio_podcast}}', 'id');
            $gc->deletePartialElements(EpisodeElement::class, '{{%studio_episode}}', 'id');
        });
    }
}
