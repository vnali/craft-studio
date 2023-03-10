<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\services;

use Craft;
use craft\events\ConfigEvent;
use craft\helpers\ProjectConfig;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;

use vnali\studio\elements\db\PodcastQuery;
use vnali\studio\elements\Podcast as PodcastElement;
use vnali\studio\models\PodcastEpisodeSettings;
use vnali\studio\models\PodcastGeneralSettings;
use vnali\studio\records\PodcastEpisodeSettingsRecord;
use vnali\studio\records\PodcastGeneralSettingsRecord;
use vnali\studio\Studio;

use yii\base\Component;

/**
 * Podcast class
 */
class podcastsService extends Component
{
    public const CONFIG_PODCASTFIELDLAYOUT_KEY = 'studio.podcastFieldLayout';

    /**
     * Returns podcast by Id
     *
     * @param int $id
     * @param int|null $siteId
     * @return PodcastElement|null
     */
    public function getPodcastById(int $id, ?int $siteId = null): ?PodcastElement
    {
        return Craft::$app->getElements()->getElementById($id, PodcastElement::class, $siteId);
    }

    /**
     * Returns podcast by Uid
     *
     * @param string $uid
     * @param int|null $siteId
     * @return PodcastElement|null
     */
    public function getPodcastByUid(string $uid, ?int $siteId = null): ?PodcastElement
    {
        return Craft::$app->getElements()->getElementByUid($uid, PodcastElement::class, $siteId);
    }

    /**
     * Get podcast by slug.
     *
     * @param string $podcastSlug
     * @return PodcastElement|null
     */
    public function getPodcastBySlug(string $podcastSlug): ?PodcastElement
    {
        /** @var PodcastElement|null $podcast; */
        $podcast = PodcastElement::find()->status(null)->where(['slug' => $podcastSlug])->one();
        return $podcast;
    }

    /**
     * Delete podcast.
     *
     * @param PodcastElement $podcast
     * @return boolean
     */
    public function deletePodcast(PodcastElement $podcast): bool
    {
        return Craft::$app->getElements()->deleteElement($podcast);
    }

    /**
     * Get all podcasts.
     *
     * @return array
     */
    public function getAllPodcasts(): array
    {
        $query = (new PodcastQuery(PodcastElement::class))
            ->status(null)
            ->orderBy('title');
        $rows = $query->all();
        return $rows;
    }

    /**
     * Get all podcasts from all sites.
     *
     * @return array
     */
    public function getAllPodcastsAllSites(): array
    {
        $query = (new PodcastQuery(PodcastElement::class))
            ->siteId('*')
            ->status(null)
            ->orderBy('title');
        $rows = $query->all();
        return $rows;
    }

    /**
     * Saves the podcast field layout.
     *
     * @param FieldLayout $fieldLayout
     * @return bool
     */
    public function savePodcastFieldLayout(FieldLayout $fieldLayout): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $fieldLayoutConfig = $fieldLayout->getConfig();
        $uid = StringHelper::UUID();

        $projectConfig->set(self::CONFIG_PODCASTFIELDLAYOUT_KEY, [$uid => $fieldLayoutConfig], 'Save the podcast field layout');

        return true;
    }

    /**
     * Handles a changed podcast field layout.
     *
     * @param ConfigEvent $event
     * @return void
     */
    public function handleChangedPodcastFieldLayout(ConfigEvent $event): void
    {
        $data = $event->newValue;

        // Make sure all fields are processed
        ProjectConfig::ensureAllFieldsProcessed();

        $fieldsService = Craft::$app->getFields();

        // Save the field layout
        $layout = FieldLayout::createFromConfig(reset($data));
        $layout->id = $fieldsService->getLayoutByType(PodcastElement::class)->id;
        $layout->type = PodcastElement::class;
        $layout->uid = key($data);
        $fieldsService->saveLayout($layout, false);
    }

    /**
     * Returns translatable native fields
     *
     * @param int $podcastFormatId
     * @return array
     */
    public function translatableFields(int $podcastFormatId): array
    {
        $podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatById($podcastFormatId);
        $podcastNativeFields = $podcastFormat->podcastNativeFields();

        $nativeSettings = json_decode($podcastFormat->nativeSettings, true);

        foreach ($podcastNativeFields as $key => $podcastAttribute) {
            if (!isset($nativeSettings[$key]) || !$nativeSettings[$key]['translatable']) {
                unset($podcastNativeFields[$key]);
            }
        }
        return $podcastNativeFields;
    }

    /**
     * Returns not translatable native fields
     *
     * @param int $podcastFormatId
     * @return array
     */
    public function notTranslatableFields(int $podcastFormatId): array
    {
        $podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatById($podcastFormatId);
        $podcastNativeFields = $podcastFormat->podcastNativeFields();

        $nativeSettings = json_decode($podcastFormat->nativeSettings, true);


        foreach ($podcastNativeFields as $key => $podcastAttribute) {
            if (isset($nativeSettings[$key]) && $nativeSettings[$key]['translatable']) {
                unset($podcastNativeFields[$key]);
            }
        }
        return $podcastNativeFields;
    }

    /**
     * Get episode settings for a podcast
     *
     * @param int $podcastId
     * @return PodcastEpisodeSettings
     */
    public function getPodcastEpisodeSettings(int $podcastId): PodcastEpisodeSettings
    {
        $record = PodcastEpisodeSettingsRecord::find()
            ->where(['podcastId' => $podcastId])
            ->one();
        if (!$record) {
            $episodeImportSettings = new PodcastEpisodeSettings();
        } else {
            /** @var PodcastEpisodeSettingsRecord $record */
            $settings = $record->settings;
            $settings = json_decode($settings, true);
            $episodeImportSettings = new PodcastEpisodeSettings();
            foreach ($settings as $key => $setting) {
                // Check if we still have this record property also on model
                if (property_exists($episodeImportSettings, $key)) {
                    $episodeImportSettings->$key = $setting;
                }
            }
        }
        return $episodeImportSettings;
    }

    /**
     * Get general settings for a podcast
     *
     * @param int $podcastId
     * @return PodcastGeneralSettings
     */
    public function getPodcastGeneralSettings(int $podcastId): PodcastGeneralSettings
    {
        $podcastGeneralSettings = new PodcastGeneralSettings();
        $record = PodcastGeneralSettingsRecord::find()
            ->where(['podcastId' => $podcastId])
            ->one();
        if ($record) {
            $podcastGeneralSettings = new PodcastGeneralSettings();
            /** @var PodcastGeneralSettingsRecord $record */
            $podcastGeneralSettings->podcastId = $record->podcastId;
            $podcastGeneralSettings->publishRSS = $record->publishRSS;
            $podcastGeneralSettings->allowAllToSeeRSS = $record->allowAllToSeeRSS;
        }
        return $podcastGeneralSettings;
    }
}
