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

use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\Studio;

use yii\base\Component;

/**
 * Episode service class
 */
class episodesService extends Component
{
    public const CONFIG_EPISODEFIELDLAYOUT_KEY = 'studio.episodeFieldLayout';

    /**
     * Get episode by Id
     *
     * @param int $id
     * @param int|null $siteId
     * @return EpisodeElement|null
     */
    public function getEpisodeById(int $id, ?int $siteId = null): ?EpisodeElement
    {
        return Craft::$app->getElements()->getElementById($id, EpisodeElement::class, $siteId);
    }

    /**
     * Get episode by Uid.
     *
     * @param string $uid
     * @param int|null $siteId
     * @return EpisodeElement|null
     */
    public function getEpisodeByUid(string $uid, ?int $siteId = null): ?EpisodeElement
    {
        return Craft::$app->getElements()->getElementByUid($uid, EpisodeElement::class, $siteId);
    }

    /**
     * Delete episode.
     *
     * @param EpisodeElement $episode
     * @return boolean
     */
    public function deleteEpisode(EpisodeElement $episode): bool
    {
        return Craft::$app->getElements()->deleteElement($episode);
    }

    /**
     * Saves the episode field layout.
     *
     * @param FieldLayout $fieldLayout
     * @return boolean
     */
    public function saveEpisodeFieldLayout(FieldLayout $fieldLayout): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $fieldLayoutConfig = $fieldLayout->getConfig();
        $uid = StringHelper::UUID();

        $projectConfig->set(self::CONFIG_EPISODEFIELDLAYOUT_KEY, [$uid => $fieldLayoutConfig], 'Save the episode field layout');

        return true;
    }

    /**
     * Handles a changed episode field layout.
     *
     * @param ConfigEvent $event
     * @return void
     */
    public function handleChangedEpisodeFieldLayout(ConfigEvent $event): void
    {
        $data = $event->newValue;

        // Make sure all fields are processed
        ProjectConfig::ensureAllFieldsProcessed();

        $fieldsService = Craft::$app->getFields();

        // Save the field layout
        $layout = FieldLayout::createFromConfig(reset($data));
        $layout->id = $fieldsService->getLayoutByType(EpisodeElement::class)->id;
        $layout->type = EpisodeElement::class;
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
        $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastFormatId);
        $episodeNativeFields = $podcastFormatEpisode->episodeNativeFields();

        $nativeSettings = json_decode($podcastFormatEpisode->nativeSettings, true);

        foreach ($episodeNativeFields as $key => $episodeAttribute) {
            if (!isset($nativeSettings[$key]) || !$nativeSettings[$key]['translatable']) {
                unset($episodeNativeFields[$key]);
            }
        }
        return $episodeNativeFields;
    }

    /**
     * Returns not translatable native fields
     *
     * @param int $podcastFormatId
     * @return array
     */
    public function notTranslatableFields(int $podcastFormatId): array
    {
        $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastFormatId);
        $episodeNativeFields = $podcastFormatEpisode->episodeNativeFields();

        $nativeSettings = json_decode($podcastFormatEpisode->nativeSettings, true);

        foreach ($episodeNativeFields as $key => $episodeAttribute) {
            if (isset($nativeSettings[$key]) && $nativeSettings[$key]['translatable']) {
                unset($episodeNativeFields[$key]);
            }
        }
        return $episodeNativeFields;
    }
}
