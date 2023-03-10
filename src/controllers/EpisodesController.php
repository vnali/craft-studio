<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\base\Element;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UrlManager;

use Symfony\Component\DomCrawler\Crawler;

use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\elements\Podcast;
use vnali\studio\jobs\importEpisodeJob;
use vnali\studio\models\ImportEpisodeRSS;
use vnali\studio\Studio;

use yii\queue\Queue;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EpisodesController extends Controller
{
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
     * @param Podcast $podcast
     * @return array
     */
    protected function editableSiteIds(Podcast $podcast): array
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
     * @param string|null $podcast
     * @return Response
     */
    public function actionCreate(?string $podcast = null): Response
    {
        if ($podcast) {
            $podcastHandle = $podcast;
        } else {
            $podcastHandle = $this->request->getRequiredBodyParam('podcast');
        }

        $podcast = Studio::$plugin->podcasts->getPodcastBySlug($podcastHandle);
        if (!$podcast) {
            throw new BadRequestHttpException("Invalid podcast format handle: $podcastHandle");
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

        // get sites that user has access and podcast format available for it
        $editableSiteIds = $this->editableSiteIds($podcast);

        if (!in_array($site->id, $editableSiteIds)) {
            // If there’s more than one possibility and podcasts doesn’t propagate to all sites, let the user choose
            if (count($editableSiteIds) > 1) {
                return $this->renderTemplate('_special/sitepicker.twig', [
                    'siteIds' => $editableSiteIds,
                    'baseUrl' => "episodes/$podcast->slug/new",
                ]);
            }

            // Go with the first one
            $site = $sitesService->getSiteById($editableSiteIds[0]);
        }

        $episode = new EpisodeElement();
        $episode->podcastId = $podcast->id;
        $podcast->siteId = $site->id;

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
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }
        $this->requirePermission('studio-importEpisodeByRSS-' . $podcast->uid);

        $settings = new ImportEpisodeRSS();
        $settings->setScenario('import');
        $settings->importFromRSS = Craft::$app->getRequest()->getBodyParam('importFromRSS');
        $settings->ignoreMainAsset = Craft::$app->getRequest()->getBodyParam('ignoreMainAsset');
        $limit = Craft::$app->getRequest()->getBodyParam('limit');
        $settings->limit = $limit ? $limit : null;
        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'Couldn’t save podcast import settings.'));

            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_URL, Craft::$app->getRequest()->getBodyParam('importFromRSS'));
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
            $this->redirect('studio/episodes/import-from-rss?podcastId=' . $podcastId);
            return false;
        }

        $crawler = new Crawler($content);
        $total = $crawler->filter('rss channel item')->count();
        if ($crawler->filter('rss channel item')->count() == 0) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'No item found'));
            $this->redirect('studio/episodes/import-from-rss?podcastId=' . $podcastId);
            return false;
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
     * @param mixed $settings
     * @return Response
     */
    public function actionImportFromRss(int $podcastId, $settings = null): Response
    {
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }
        $this->requirePermission('studio-importEpisodeByRSS-' . $podcast->uid);

        $variables['podcastId'] = $podcastId;

        if ($settings === null) {
            $settings = new ImportEpisodeRSS();
        }
        $variables['settings'] = $settings;

        return $this->renderTemplate(
            'studio/episodes/_importFromRSS',
            $variables
        );
    }

    /**
     * Generate Import template from asset index
     *
     * @param int $podcastId
     * @param mixed $settings
     * @return Response
     */
    public function actionImportFromAssetIndex(int $podcastId, $settings = null): Response
    {
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }
        $this->requirePermission('studio-importEpisodeByAssetIndex-' . $podcast->uid);

        if ($settings === null) {
            $settings = Studio::$plugin->podcasts->getPodcastEpisodeSettings($podcastId);
        }

        $variables['podcastId'] = $podcastId;

        $variables['volumes'][] = ['value' => '', 'label' => Craft::t('studio', 'select volume')];
        foreach (Craft::$app->volumes->getAllVolumes() as $volumeItem) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            // Allow only volumes that user has access
            if ($currentUser->can('saveAssets:' . $volumeItem->uid)) {
                $volume['value'] = $volumeItem->id;
                $volume['label'] = $volumeItem->name;
                $variables['volumes'][] = $volume;
            }
        }

        $variables['settings'] = $settings;

        $variables['enable'] = $settings->enable;

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
        $podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        if ($podcastId) {
            $settings = Studio::$plugin->podcasts->getPodcastEpisodeSettings($podcastId);
        } else {
            throw new NotFoundHttpException(Craft::t('studio', 'Podcasts id is not provided.'));
        }
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }
        $this->requirePermission('studio-importEpisodeByAssetIndex-' . $podcast->uid);

        $settings->setScenario('import');
        $settings->podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        $settings->volumesImport = Craft::$app->getRequest()->getBodyParam('volumesImport', $settings->volumesImport);
        $settings->enable = Craft::$app->getRequest()->getBodyParam('enable', $settings->enable);

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'Couldn’t save podcast import settings.'));

            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();
            $urlManager->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        // Save it
        $user = Craft::$app->getUser()->getIdentity();
        $userId = $user->id;
        Db::upsert('{{%studio_podcast_episode_settings}}', [
            'userId' => $userId,
            'podcastId' => $podcastId,
            'settings' => json_encode($settings),
            'enable' => $settings->enable,
        ], [
            'settings' => json_encode($settings),
            'enable' => $settings->enable,
        ]);

        return $this->redirectToPostedUrl();
    }
}
