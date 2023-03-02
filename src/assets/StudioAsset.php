<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\assets;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

use vnali\studio\elements\Podcast;
use vnali\studio\records\PodcastFormatSitesRecord;
use vnali\studio\Studio;

/**
 * StudioAsset Bundle
 */
class StudioAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = '@vnali/studio/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/EpisodeIndex.js',
            'js/PodcastIndex.js',
        ];

        parent::init();
    }

    /**
     * @inheritDoc
     */
    public function registerAssetFiles($view)
    {
        parent::registerAssetFiles($view);

        // Define the Studio plugin object
        $craftJson = Json::encode($this->_studioData(), JSON_UNESCAPED_UNICODE);
        $js = <<<JS
        window.Studio = {$craftJson};
JS;
        $view->registerJs($js, View::POS_HEAD);
    }

    /**
     * Returns available items for plugin elements.
     *
     * @return array
     */
    private function _studioData(): array
    {
        $data = [
            'availablePodcasts' => $this->_availablePodcasts() ?: [],
            'availablePodcastFormats' => $this->_availablePodcastFormats() ?: [],
        ];
        return $data;
    }

    /**
     * Returns available podcast formats for creating episodes
     *
     * @return array
     */
    private function _availablePodcastFormats(): array
    {
        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();
        $availablePodcastFormats = [];

        $podcastFormats = Studio::$plugin->podcastFormats->getAllPodcastFormats();
        foreach ($podcastFormats as $podcastFormat) {
            $podcastFormatRecords = PodcastFormatSitesRecord::find()->where(['podcastFormatId' => $podcastFormat->id])->all();
            // Maybe checking edit site before pushing site to siteIds
            $siteIds = ArrayHelper::getColumn($podcastFormatRecords, 'siteId');
            if (
                $currentUser->can("studio-managePodcasts") || $currentUser->can("studio-createDraftPodcasts")
            ) {
                $availablePodcastFormats[] = [
                    'handle' => $podcastFormat->handle,
                    'id' => (int)$podcastFormat->id,
                    'name' => Craft::t('site', $podcastFormat->name),
                    'sites' => $siteIds,
                    'uid' => $podcastFormat->uid,
                ];
            }
        }
        return $availablePodcastFormats;
    }

    /**
     * Returns available podcasts for creating episodes
     *
     * @return array
     */
    private function _availablePodcasts(): array
    {
        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();
        $availablePodcasts = [];

        $podcasts = Podcast::find()->status(null)->orderBy('dateCreated asc')->all();
        /** @var Podcast $podcast */
        foreach ($podcasts as $podcast) {
            $podcastFormatSiteRecords = PodcastFormatSitesRecord::find()->where(['podcastFormatId' => $podcast->podcastFormatId])->all();
            $siteIds = ArrayHelper::getColumn($podcastFormatSiteRecords, 'siteId');
            
            // Get podcast titles for all available sites
            $names = [];
            foreach ($siteIds as $siteId) {
                $podcastForSite = Podcast::find()->status(null)->siteId($siteId)->where(['studio_podcast.id' => $podcast->id])->one();
                if ($podcastForSite) {
                    $names[$siteId] = $podcastForSite->title;
                }
            }

            if (
                $currentUser->can("studio-manageEpisodes") ||
                ($currentUser->can("studio-viewPodcasts-" . $podcast->uid) && $currentUser->can("studio-createDraftEpisodes-" . $podcast->uid))
            ) {
                $availablePodcasts[] = [
                    'handle' => $podcast->slug,
                    'id' => (int)$podcast->id,
                    'name' => $names,
                    'sites' => $siteIds,
                    'uid' => $podcast->uid,
                ];
            }
        }

        return $availablePodcasts;
    }
}
