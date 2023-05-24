<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\base\Element;
use craft\db\Table;
use craft\elements\db\AssetQuery;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UrlManager;
use craft\web\View;
use Symfony\Component\DomCrawler\Crawler;

use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\elements\Podcast as PodcastElement;

;
use vnali\studio\jobs\importEpisodeJob;
use vnali\studio\models\ImportEpisodeRSS;
use vnali\studio\records\PodcastEpisodeSettingsRecord;
use vnali\studio\Studio;
use yii\caching\TagDependency;
use yii\queue\Queue;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class EpisodesController extends Controller
{
    protected int|bool|array $allowAnonymous = ['chapter'];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
    }

    /**
     * Get all available site Ids for the podcast which user has access to
     *
     * @param PodcastElement $podcast
     * @return array
     */
    protected function editableSiteIds(PodcastElement $podcast): array
    {
        if (!Craft::$app->getIsMultiSite()) {
            return [Craft::$app->getSites()->getPrimarySite()->id];
        }

        // Only use the sites that the user has access to
        $podcastFormatSiteIds = array_keys($podcast->getPodcastFormat()->getSiteSettings());
        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        $siteIds = array_merge(array_intersect($podcastFormatSiteIds, $editableSiteIds));
        if (empty($siteIds)) {
            throw new ForbiddenHttpException('User not permitted to edit content in any sites supported by this podcast format');
        }
        return $siteIds;
    }

    /**
     * Creates a new unpublished draft for episodes and redirects to its edit page.
     *
     * @param string|null $podcastHandle
     * @return Response
     */
    public function actionCreate(?string $podcastHandle = null): Response
    {
        if (!$podcastHandle) {
            $podcastHandle = $this->request->getRequiredBodyParam('podcastHandle');
        }

        $sitesService = Craft::$app->getSites();
        $siteId = $this->request->getBodyParam('siteId');

        if ($siteId) {
            $site = $sitesService->getSiteById($siteId);
            if (!$site) {
                throw new BadRequestHttpException("Invalid site ID: $siteId");
            }
        } else {
            $site = Cp::requestedSite();
            if (!$site) {
                throw new ForbiddenHttpException('User not authorized to edit content in any sites.');
            }
        }

        $podcast = Studio::$plugin->podcasts->getPodcastByHandle($podcastHandle, $site->id);
        if (!$podcast) {
            throw new BadRequestHttpException("Invalid podcast handle: $podcastHandle for site  $site->name");
        }

        // get sites that user has access and podcast format available for it
        $editableSiteIds = $this->editableSiteIds($podcast);

        if (!in_array($site->id, $editableSiteIds)) {
            // If there’s more than one possibility and podcasts doesn’t propagate to all sites, let the user choose
            if (count($editableSiteIds) > 1) {
                $podcastHandle = $podcast->id . '-' . $podcast->slug;
                return $this->renderTemplate('_special/sitepicker.twig', [
                    'siteIds' => $editableSiteIds,
                    'baseUrl' => "episodes/$podcastHandle/new",
                ]);
            }

            // Go with the first one
            $site = $sitesService->getSiteById($editableSiteIds[0]);
        }

        $episode = new EpisodeElement();
        $episode->podcastId = $podcast->id;
        $episode->siteId = $site->id;

        // Make sure the user is allowed to create this episode
        $user = Craft::$app->getUser()->getIdentity();
        if (!$episode->canSave($user)) {
            throw new ForbiddenHttpException('User not authorized to save this episode.');
        }

        $sitesSettings = $podcast->getPodcastFormat()->getSiteSettings();
        $siteSettings = $sitesSettings[$episode->siteId];
        //status
        if (($status = $this->request->getQueryParam('status')) !== null) {
            $enabled = $status === 'enabled';
        } else {
            $enabled = $siteSettings->episodeEnabledByDefault;
        }
        if (Craft::$app->getIsMultiSite() && count($episode->getSupportedSites()) > 1) {
            $episode->enabled = true;
            $episode->setEnabledForSite($enabled);
        } else {
            $episode->enabled = $enabled;
            $episode->setEnabledForSite(true);
        }

        // Title & slug
        $episode->title = $this->request->getQueryParam('title');
        $episode->slug = $this->request->getQueryParam('slug');
        if ($episode->title && !$episode->slug) {
            $episode->slug = ElementHelper::generateSlug($episode->title, null, $site->language);
        }
        if (!$episode->slug) {
            $episode->slug = ElementHelper::tempSlug();
        }

        $episode->setScenario(Element::SCENARIO_ESSENTIALS);
        $success = Craft::$app->getDrafts()->saveElementAsDraft($episode, Craft::$app->getUser()->getId(), null, null, false);

        if (!$success) {
            return $this->asModelFailure($episode, Craft::t('app', 'Couldn’t create {type}.', [
                'type' => EpisodeElement::lowerDisplayName(),
            ]), 'episode');
        }

        $editUrl = $episode->getCpEditUrl();

        $response = $this->asModelSuccess($episode, Craft::t('app', '{type} created.', [
            'type' => EpisodeElement::displayName(),
        ]), 'episode', array_filter([
            'cpEditUrl' => $this->request->isCpRequest ? $editUrl : null,
        ]));

        if (!$this->request->getAcceptsJson()) {
            $response->redirect(UrlHelper::urlWithParams($editUrl, [
                'fresh' => 1,
            ]));
        }

        return $response;
    }

    /**
     * Import episodes
     *
     * @return Response|null|false
     */
    public function actionImportJob(): Response|null|false
    {
        $this->requirePostRequest();
        $podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');
        $siteIds = Craft::$app->getRequest()->getBodyParam('siteIds');
        if ($siteIds && !is_array($siteIds)) {
            $siteIds = [$siteIds];
        }
        $settings = new ImportEpisodeRSS();
        $settings->rssURL = Craft::$app->getRequest()->getBodyParam('rssURL');
        $settings->ignoreMainAsset = Craft::$app->getRequest()->getBodyParam('ignoreMainAsset');
        $settings->ignoreImageAsset = Craft::$app->getRequest()->getBodyParam('ignoreImageAsset');
        $limit = Craft::$app->getRequest()->getBodyParam('limit');
        $settings->limit = $limit ? $limit : null;
        $settings->siteIds = $siteIds;
        if (!$settings->validate()) {
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }
        // A validation check for passed podcastId and siteIds
        $podcast = null;
        foreach ($siteIds as $site) {
            $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId, $site);
            if (!$podcast) {
                throw new NotFoundHttpException('Invalid podcast id');
            }
        }
        if ($podcast) {
            $user = Craft::$app->getUser()->getIdentity();
            if (!$user->can('studio-manageEpisodes') && !$user->can('studio-importEpisodeByRSS-' . $podcast->uid)) {
                throw new ForbiddenHttpException('User is not authorized to perform this action.');
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_URL, $settings->rssURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // TODO: let request to sent via proxy
        #curl_setopt($ch, CURLOPT_PROXY, 'ip:port');
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPGET, 1);
        $content = trim(curl_exec($ch));
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$httpCode || $httpCode != 200) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'problem reaching site.'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        $crawler = new Crawler($content);

        // Prevent import from a podcast when the podcast is locked
        if ($crawler->filter('podcast|locked')->count()) {
            $locked = $crawler->filter('podcast|locked')->text();
            if (strtolower($locked) == 'yes') {
                Craft::$app->getSession()->setError(Craft::t('studio', 'This podcast is locked.'));
                /** @var UrlManager $urlManager */
                $urlManager = Craft::$app->getUrlManager();
                $urlManager->setRouteParams([
                    'settings' => $settings,
                ]);
    
                return null;
            }
        }

        $total = $crawler->filter('rss channel item')->count();
        if ($crawler->filter('rss channel item')->count() == 0) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'No item found'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        $items = [];
        $crawler = new Crawler($content);
        foreach ($crawler->filter('rss channel')->children() as $domElement) {
            $html = $domElement->ownerDocument->saveHTML($domElement);
            $items[] = $html;
        }

        /** @var Queue $queue */
        $queue = Craft::$app->getQueue();
        // Add sync job to queue

        $queue->push(
            new importEpisodeJob(
                [
                    'items' => $items,
                    'total' => $total,
                    'podcastId' => $podcastId,
                    'limit' => $settings->limit ?? $total,
                    'ignoreMainAsset' => $settings->ignoreMainAsset,
                    'ignoreImageAsset' => $settings->ignoreImageAsset,
                    'siteIds' => $settings->siteIds,
                    'siteId' => $siteId,
                ]
            )
        );

        Craft::$app->getSession()->setNotice(Craft::t('studio', 'Importing {limit} from {total} episodes', [
            'limit' => $settings->limit ?? $total,
            'total' => $total,
        ]));

        return $this->redirectToPostedUrl();
    }

    /**
     * Generate Import template From RSS
     *
     * @param int $podcastId
     * @param int $siteId
     * @param mixed $settings
     * @return Response
     */
    public function actionImportFromRss(int $podcastId, int $siteId, $settings = null): Response
    {
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId, $siteId);
        if (!$podcast) {
            throw new NotFoundHttpException('Invalid podcast id');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user->can('studio-manageEpisodes') && !$user->can('studio-importEpisodeByRSS-' . $podcast->uid)) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        $variables['podcastId'] = $podcastId;

        if ($settings === null) {
            $settings = new ImportEpisodeRSS();
        }
        $variables['settings'] = $settings;

        $propagatedSites = PodcastElement::find()->status(null)->id($podcast->id)->site('*')->select('elements_sites.siteId')->column();
        $items = [];
        $currentUser = Craft::$app->getUser()->getIdentity();
        foreach ($propagatedSites as $propagatedSite) {
            // Allow only sites that user has access
            $siteUid = Db::uidById(Table::SITES, $propagatedSite);
            if (Craft::$app->getIsMultiSite() && !$currentUser->can('editSite:' . $siteUid)) {
                continue;
            }
            $site = Craft::$app->sites->getSiteById($propagatedSite);
            if ($site) {
                $item = [];
                $item['label'] = $site->name;
                $item['value'] = $site->id;
                $items[] = $item;
            }
        }
        if (Craft::$app->getIsMultiSite() && !$items) {
            throw new ServerErrorHttpException('User have no access to any sites');
        }
        $variables['sites'] = $items;

        $variables['podcastId'] = $podcastId;
        $variables['settings'] = $settings;
        $variables['podcast'] = $podcast;

        $site = Craft::$app->sites->getSiteById($siteId);
        $variables['site'] = $site;

        return $this->renderTemplate(
            'studio/episodes/_importFromRSS',
            $variables
        );
    }

    /**
     * Generate Import template from asset index
     *
     * @param int $podcastId
     * @param int $siteId
     * @param mixed $settings
     * @return Response
     */
    public function actionImportFromAssetIndex(int $podcastId, int $siteId, $settings = null): Response
    {
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId, $siteId);
        if (!$podcast) {
            throw new NotFoundHttpException('Invalid podcast id');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user->can('studio-manageEpisodes') && !$user->can('studio-importEpisodeByAssetIndex-' . $podcast->uid)) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        if ($settings === null) {
            $settings = Studio::$plugin->podcasts->getPodcastAssetIndexesSettings($podcastId);
        }

        $variables['podcastId'] = $podcastId;

        $variables['volumes'][] = ['value' => '', 'label' => Craft::t('studio', 'select volume')];
        foreach (Craft::$app->volumes->getAllVolumes() as $volumeItem) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            // Allow only volumes that user has access
            if ($currentUser->can('viewAssets:' . $volumeItem->uid)) {
                $volume['value'] = $volumeItem->id;
                $volume['label'] = $volumeItem->name;
                $variables['volumes'][] = $volume;
            }
        }

        $variables['settings'] = $settings;

        $variables['enable'] = $settings->enable;
        $propagatedSites = PodcastElement::find()->status(null)->id($podcast->id)->site('*')->select('elements_sites.siteId')->column();
        $items = [];
        $currentUser = Craft::$app->getUser()->getIdentity();
        foreach ($propagatedSites as $propagatedSite) {
            // Allow only sites that user has access
            $siteUid = Db::uidById(Table::SITES, $propagatedSite);
            if (Craft::$app->getIsMultiSite() && !$currentUser->can('editSite:' . $siteUid)) {
                continue;
            }
            $site = Craft::$app->sites->getSiteById($propagatedSite);
            if ($site) {
                $item = [];
                $item['label'] = $site->name;
                $item['value'] = $site->id;
                $items[] = $item;
            }
        }
        if (Craft::$app->getIsMultiSite() && !$items) {
            throw new ServerErrorHttpException('User have no access to any any sites');
        }
        $variables['sites'] = $items;
        $variables['podcast'] = $podcast;

        $site = Craft::$app->sites->getSiteById($siteId);
        $variables['site'] = $site;

        return $this->renderTemplate(
            'studio/episodes/_importFromAssetIndex',
            $variables
        );
    }

    /**
     * Save Import from asset index settings
     *
     * @return Response|null|false
     */
    public function actionSaveImportFromAssetIndex(): Response|null|false
    {
        $this->requirePostRequest();
        $podcastId = Craft::$app->getRequest()->getRequiredBodyParam('podcastId');
        $siteIds = Craft::$app->getRequest()->getRequiredBodyParam('siteIds');
        if ($siteIds && !is_array($siteIds)) {
            $siteIds = [$siteIds];
        }

        if ($podcastId) {
            $settings = Studio::$plugin->podcasts->getPodcastAssetIndexesSettings($podcastId);
        } else {
            throw new NotFoundHttpException(Craft::t('studio', 'Podcasts id is not provided.'));
        }

        $settings->podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        $settings->volumes = Craft::$app->getRequest()->getBodyParam('volumes');
        $settings->enable = Craft::$app->getRequest()->getBodyParam('enable');
        $settings->siteIds = $siteIds;
        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'Couldn’t save podcast import settings.'));

            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        $podcast = null;
        // A validation check for passed podcastId and siteIds
        foreach ($siteIds as $siteId) {
            $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId, $siteId);
            if (!$podcast) {
                throw new NotFoundHttpException('Invalid podcast id');
            }
        }

        if ($podcast) {
            $user = Craft::$app->getUser()->getIdentity();
            if (!$user->can('studio-manageEpisodes') && !$user->can('studio-importEpisodeByAssetIndex-' . $podcast->uid)) {
                throw new ForbiddenHttpException('User is not authorized to perform this action.');
            }
        }

        // Save it
        $user = Craft::$app->getUser()->getIdentity();
        $userId = $user->id;
        Db::upsert('{{%studio_podcast_assetIndexes_settings}}', [
            'userId' => $userId,
            'podcastId' => $podcastId,
            'settings' => json_encode($settings),
            'enable' => $settings->enable,
        ], [
            'settings' => json_encode($settings),
            'enable' => $settings->enable,
        ]);

        $podcastEpisodeSetting = PodcastEpisodeSettingsRecord::find()->where(['podcastId' => $podcastId, 'siteId' => $settings->siteIds[0]])->one();
        if (!$podcastEpisodeSetting) {
            $site = Craft::$app->sites->getSiteById($settings->siteIds[0]);
            Craft::$app->getSession()->setNotice(Craft::t('studio', 'Now you can use asset indexes utility to import episodes. but you should set episode settings for {site} site first', [
                'site' => $site->handle,
            ]));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('studio', 'Now you can use asset indexes utility to import episodes.'));
        }
        return $this->redirectToPostedUrl();
    }

    /**
     * Generate episode's chapter json
     *
     * @param integer $episodeId
     * @param string|null $site
     * @return Response
     */
    public function actionChapter(int $episodeId, ?string $site = null): Response
    {
        $cache = Craft::$app->getCache();
        if ($site) {
            $site = Craft::$app->sites->getSiteByHandle($site);
        }

        // If site is not passed or not found use default site
        if (!$site) {
            $site = Craft::$app->sites->getCurrentSite();
        }
        $siteId = $site->id;
        /** @var EpisodeElement|null $episode */
        $episode = EpisodeElement::find()->id($episodeId)->status(null)->siteId($siteId)->one();
        $podcast = $episode->getPodcast();
        $generalSettings = Studio::$plugin->podcasts->getPodcastGeneralSettings($podcast->id, $siteId);
        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();

        if (!$episode) {
            throw new ServerErrorHttpException('Invalid episode');
        }

        $siteStatuses = ElementHelper::siteStatusesForElement($podcast, true);
        $podcastEnabled = $siteStatuses[$podcast->siteId];

        $siteStatuses = ElementHelper::siteStatusesForElement($episode, true);
        $episodeEnabled = $siteStatuses[$episode->siteId];

        if ((!$podcastEnabled || !$episodeEnabled) && (!$currentUser || (!$currentUser->can("studio-viewPodcast-" . $episode->getPodcast()->uid) && !$currentUser->can("studio-managePodcasts")))) {
            throw new ForbiddenHttpException('User is not authorized to view this page.');
        }

        if ($generalSettings->publishRSS && !$generalSettings->allowAllToSeeRSS) {
            if (!$currentUser || (!$currentUser->can('studio-viewPublishedRSS-' . $podcast->uid) && !$currentUser->can("studio-managePodcasts"))) {
                throw new ForbiddenHttpException('User is not authorized to view this page.');
            }
        }

        if (!$generalSettings->publishRSS) {
            if (!$currentUser || (!$currentUser->can('studio-viewNotPublishedRSS-' . $podcast->uid) && !$currentUser->can("studio-managePodcasts"))) {
                throw new ForbiddenHttpException('User is not authorized to view this page.');
            }
        }

        $rssCacheKey = 'studio-plugin-' . $siteId . '-' . $episode->id;

        $jsonChapter = $cache->getOrSet($rssCacheKey, function() use ($episode) {
            $chaptersArray = [];
            if (isset($episode->episodeChapter)) {
                $chapters = $episode->episodeChapter->all();
                foreach ($chapters as $key => $chapter) {
                    $chapterArray = [];
                    if (is_null($chapter->type->handle) || $chapter->type->handle == 'chapter') {
                        // Start time is required
                        if ($chapter->startTime === null) {
                            continue;
                        }
                        $chapterArray['startTime'] = $chapter->startTime;
                        if (isset($chapter->chapterTitle) && $chapter->chapterTitle) {
                            $chapterArray['title'] = $chapter->chapterTitle;
                        }
                        if (isset($chapter->img) && $chapter->img) {
                            if (!is_object($chapter->img)) {
                                $chapterArray['img'] = $chapter->img;
                            } elseif (is_a($chapter->img, AssetQuery::class)) {
                                $img = $chapter->img->one();
                                if ($img) {
                                    $chapterArray['img'] = $img->getUrl();
                                }
                            }
                        }
                        if (isset($chapter->toc) && $chapter->toc) {
                            $chapterArray['toc'] = $chapter->toc;
                        }
                        if (isset($chapter->chapterUrl) && $chapter->chapterUrl) {
                            $chapterArray['url'] = $chapter->chapterUrl;
                        }
                        if (isset($chapter->endTime) && $chapter->endTime) {
                            $chapterArray['endTime'] = $chapter->endTime;
                        }
                        $chaptersArray[] = $chapterArray;
                    }
                }
            }
            $jsonChapter = [];
            $jsonChapter['version'] = '1.2.0';
            $jsonChapter['title'] = $episode->title;
            $jsonChapter['podcastName'] = $episode->getPodcast()->title;
            if ($author = $episode->getUploader()->fullName) {
                $jsonChapter['author'] = $author;
            }
            $jsonChapter['chapters'] = $chaptersArray;
            return $jsonChapter;
        }, 0, new TagDependency(['tags' => ['studio-plugin', 'element::' . PodcastElement::class . '::*', 'element::' . EpisodeElement::class . '::*']]));

        $variables['json'] = json_encode($jsonChapter, JSON_UNESCAPED_UNICODE);
        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_CP);
        return $this->renderTemplate(
            'studio/episodes/_jsonChapter',
            $variables
        );
    }
}
