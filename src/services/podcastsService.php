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
use DateTime;
use DateTimeZone;
use vnali\studio\elements\db\PodcastQuery;
use vnali\studio\elements\Podcast as PodcastElement;
use vnali\studio\models\PodcastAssetIndexesSettings;
use vnali\studio\models\PodcastEpisodeSettings;
use vnali\studio\models\PodcastGeneralSettings;
use vnali\studio\records\PodcastAssetIndexesSettingsRecord;
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
     * Get podcast by handle. podcast handle is in this format {podcastId}-{podcastSlug}.
     *
     * @param string $podcastHandle
     * @param int $siteId
     * @return PodcastElement|null
     */
    public function getPodcastByHandle(string $podcastHandle, int $siteId): ?PodcastElement
    {
        $podcastHandleParts = explode('-', $podcastHandle);
        /** @var PodcastElement|null $podcast; */
        $podcast = PodcastElement::find()->siteId($siteId)->status(null)->where(['studio_podcast.id' => $podcastHandleParts[0]])->one();
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
     * @param mixed $siteId
     * @return array
     */
    public function getAllPodcasts($siteId = null): array
    {
        $query = (new PodcastQuery(PodcastElement::class));
        if ($siteId) {
            $query = $query->siteId($siteId);
        }
        $query->status(null)
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
     * @param int $siteId
     * @return PodcastEpisodeSettings
     */
    public function getPodcastEpisodeSettings(int $podcastId, int $siteId): PodcastEpisodeSettings
    {
        $podcastEpisodeSettings = new PodcastEpisodeSettings();
        $record = PodcastEpisodeSettingsRecord::find()
            ->where(['podcastId' => $podcastId, 'siteId' => $siteId])
            ->one();
        if ($record) {
            /** @var PodcastEpisodeSettingsRecord $record */
            $settings = $record->settings;
            $settings = json_decode($settings, true);
            foreach ($settings as $key => $setting) {
                // Check if we still have this record property also on model
                if (property_exists($podcastEpisodeSettings, $key)) {
                    $podcastEpisodeSettings->$key = $setting;
                }
            }
            $tz = Craft::$app->getTimeZone();
            $dateUpdated = new DateTime($record->dateUpdated, new \DateTimeZone("UTC"));
            $tzTime = new DateTimeZone($tz);
            $dateUpdated->setTimezone($tzTime);
            $podcastEpisodeSettings->dateUpdated = $dateUpdated;
            $podcastEpisodeSettings->userId = $record->userId;
        }
        return $podcastEpisodeSettings;
    }

    /**
     * Get asset indexes settings for a podcast
     *
     * @param int $podcastId
     * @return PodcastAssetIndexesSettings
     */
    public function getPodcastAssetIndexesSettings(int $podcastId): PodcastAssetIndexesSettings
    {
        $podcastAssetIndexesSettings = new PodcastAssetIndexesSettings();
        $record = PodcastAssetIndexesSettingsRecord::find()
            ->where(['podcastId' => $podcastId])
            ->one();
        if ($record) {
            /** @var PodcastAssetIndexesSettingsRecord $record */
            $settings = $record->settings;
            $settings = json_decode($settings, true);
            foreach ($settings as $key => $setting) {
                // Check if we still have this record property also on model
                if (property_exists($podcastAssetIndexesSettings, $key)) {
                    $podcastAssetIndexesSettings->$key = $setting;
                }
            }
            $tz = Craft::$app->getTimeZone();
            $dateUpdated = new DateTime($record->dateUpdated, new \DateTimeZone("UTC"));
            $tzTime = new DateTimeZone($tz);
            $dateUpdated->setTimezone($tzTime);
            $podcastAssetIndexesSettings->dateUpdated = $dateUpdated;
            $podcastAssetIndexesSettings->userId = $record->userId;
        }
        return $podcastAssetIndexesSettings;
    }

    /**
     * Get general settings for a podcast
     *
     * @param int $podcastId
     * @param int $siteId
     * @return PodcastGeneralSettings
     */
    public function getPodcastGeneralSettings(int $podcastId, int $siteId): PodcastGeneralSettings
    {
        $podcastGeneralSettings = new PodcastGeneralSettings();
        $record = PodcastGeneralSettingsRecord::find()
            ->where(['podcastId' => $podcastId, 'siteId' => $siteId])
            ->one();
        if ($record) {
            /** @var PodcastGeneralSettingsRecord $record */
            $podcastGeneralSettings->podcastId = $record->podcastId;
            $podcastGeneralSettings->siteId = $record->siteId;
            $podcastGeneralSettings->publishRSS = $record->publishRSS;
            $podcastGeneralSettings->allowAllToSeeRSS = $record->allowAllToSeeRSS;
            $podcastGeneralSettings->enableOP3 = $record->enableOP3;
            $tz = Craft::$app->getTimeZone();
            $dateUpdated = new DateTime($record->dateUpdated, new \DateTimeZone("UTC"));
            $tzTime = new DateTimeZone($tz);
            $dateUpdated->setTimezone($tzTime);
            $podcastGeneralSettings->dateUpdated = $dateUpdated;
            $podcastGeneralSettings->userId = $record->userId;
        }
        return $podcastGeneralSettings;
    }
}
