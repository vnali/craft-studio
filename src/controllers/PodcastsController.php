<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\base\Element;
use craft\base\LocalFsInterface;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Tags;
use craft\fs\Local;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UrlManager;

use DOMDocument;

use vnali\studio\elements\db\EpisodeQuery;
use vnali\studio\elements\Episode;
use vnali\studio\elements\Podcast as PodcastElement;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\helpers\Id3;
use vnali\studio\models\PodcastEpisodeSettings;
use vnali\studio\models\PodcastFormat;
use vnali\studio\models\PodcastGeneralSettings;
use vnali\studio\Studio;

use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class PodcastsController extends Controller
{
    protected int|bool|array $allowAnonymous = ['rss'];

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
     * @param PodcastFormat $podcastFormat
     * @return array
     */
    protected function editableSiteIds(PodcastFormat $podcastFormat): array
    {
        if (!Craft::$app->getIsMultiSite()) {
            return [Craft::$app->getSites()->getPrimarySite()->id];
        }

        // Only use the sites that the user has access to
        $podcastFormatSiteIds = array_keys($podcastFormat->getSiteSettings());
        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        $siteIds = array_merge(array_intersect($podcastFormatSiteIds, $editableSiteIds));
        if (empty($siteIds)) {
            throw new ForbiddenHttpException('User not permitted to edit content in any sites supported by this podcast format');
        }
        return $siteIds;
    }

    /**
     * Creates a new unpublished draft for podcasts and redirects to its edit page.
     *
     * @param string|null $podcastFormat
     * @return Response
     */
    public function actionCreate(?string $podcastFormat = null): Response
    {
        if ($podcastFormat) {
            $podcastFormatHandle = $podcastFormat;
        } else {
            $podcastFormatHandle = $this->request->getRequiredBodyParam('podcastFormat');
        }

        $podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatByHandle($podcastFormatHandle);
        if (!$podcastFormat) {
            throw new BadRequestHttpException("Invalid podcast format handle: $podcastFormatHandle");
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
        $editableSiteIds = $this->editableSiteIds($podcastFormat);

        if (!in_array($site->id, $editableSiteIds)) {
            // If there’s more than one possibility and podcasts doesn’t propagate to all sites, let the user choose
            if (count($editableSiteIds) > 1) {
                return $this->renderTemplate('_special/sitepicker.twig', [
                    'siteIds' => $editableSiteIds,
                    'baseUrl' => "entries/$podcastFormat->handle/new",
                ]);
            }

            // Go with the first one
            $site = $sitesService->getSiteById($editableSiteIds[0]);
        }

        $user = static::currentUser();

        $podcast = new PodcastElement();
        $podcast->podcastFormatId = $podcastFormat->id;
        $podcast->siteId = $site->id;

        $sitesSettings = $podcastFormat->getSiteSettings();
        $siteSettings = $sitesSettings[$podcast->siteId];
        // Status
        if (($status = $this->request->getQueryParam('status')) !== null) {
            $enabled = $status === 'enabled';
        } else {
            $enabled = $siteSettings->podcastEnabledByDefault;
        }
        if (Craft::$app->getIsMultiSite() && count($podcast->getSupportedSites()) > 1) {
            $podcast->enabled = true;
            $podcast->setEnabledForSite($enabled);
        } else {
            $podcast->enabled = $enabled;
            $podcast->setEnabledForSite(true);
        }

        // Make sure the user is allowed to create this podcast
        if (!Craft::$app->getElements()->canSave($podcast, $user)) {
            throw new ForbiddenHttpException('User not authorized to save this podcast.');
        }

        // Title & slug
        $podcast->title = $this->request->getQueryParam('title');
        $podcast->slug = $this->request->getQueryParam('slug');
        if ($podcast->title && !$podcast->slug) {
            $podcast->slug = ElementHelper::generateSlug($podcast->title, null, $site->language);
        }
        if (!$podcast->slug) {
            $podcast->slug = ElementHelper::tempSlug();
        }

        // Custom fields
        foreach ($podcast->getFieldLayout()->getCustomFields() as $field) {
            if (($value = $this->request->getQueryParam($field->handle)) !== null) {
                $podcast->setFieldValue($field->handle, $value);
            }
        }

        $podcast->setScenario(Element::SCENARIO_ESSENTIALS);

        $success = Craft::$app->getDrafts()->saveElementAsDraft($podcast, Craft::$app->getUser()->getId(), null, null, false);

        if (!$success) {
            return $this->asModelFailure($podcast, Craft::t('app', 'Couldn’t create {type}.', [
                'type' => PodcastElement::lowerDisplayName(),
            ]), 'podcast');
        }

        $editUrl = $podcast->getCpEditUrl();

        $response = $this->asModelSuccess($podcast, Craft::t('app', '{type} created.', [
            'type' => PodcastElement::displayName(),
        ]), 'podcast', array_filter([
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
     * Generate Podcast's RSS
     *
     * @param integer $podcastId
     * @return void
     */
    public function actionRss(int $podcastId)
    {
        $currentSite = Craft::$app->sites->getCurrentSite();
        $siteId = $currentSite->id;

        /** @var PodcastElement|null $podcast */
        $podcast = PodcastElement::find()->id($podcastId)->status(null)->siteId($siteId)->one();
        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();
        if (!$podcast || (!$podcast->enabled && (!$currentUser || !$currentUser->can("studio-viewPodcasts-" . $podcast->uid)))) {
            throw new ServerErrorHttpException('invalid podcast');
        }

        $generalSettings = Studio::$plugin->podcasts->getPodcastGeneralSettings($podcastId);
        if (!$generalSettings->publishRSS && (!$currentUser || !$currentUser->can("studio-viewPodcasts-" . $podcast->uid))) {
            throw new ServerErrorHttpException('invalid podcast');
        }

        $podcastFormat = $podcast->getPodcastFormat();
        $podcastMapping = json_decode($podcastFormat->mapping, true);
        $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
        $episodeMapping = json_decode($podcastFormatEpisode->mapping, true);

        if (isset($podcast->podcastRedirectTo) && $podcast->podcastRedirectTo) {
            header("HTTP/1.1 301 Moved Permanently");
            header("Location: " . $podcast->podcastRedirectTo);
            exit();
        }

        $episodeQuery = Episode::find()->siteId($siteId);
        /** @var EpisodeQuery $episodeQuery */
        $episodeQuery->podcastId = $podcast->id;
        /** @var Episode[] $episodes */
        $episodes = $episodeQuery->all();
        // Create the document.
        $xml = new DOMDocument("1.0", "UTF-8");

        // Create "RSS" element
        $rss = $xml->createElement("rss");
        /** @var \DomElement $rssNode */
        $rssNode = $xml->appendChild($rss);
        $rssNode->setAttribute("version", "2.0");
        $rssNode->setAttribute("xmlns:atom", "http://www.w3.org/2005/Atom");
        $rssNode->setAttribute("xmlns:itunes", 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $rssNode->setAttribute("xmlns:content", 'http://purl.org/rss/1.0/modules/content/');

        $xmlChannel = $xml->createElement("channel");
        $rssNode->appendChild($xmlChannel);
        $podcastTitle = $xml->createElement("title", htmlspecialchars($podcast->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $xmlChannel->appendChild($podcastTitle);
        $podcastTitle = $xml->createElement("itunes:title", htmlspecialchars($podcast->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $xmlChannel->appendChild($podcastTitle);

        // Podcast Link
        if ($podcast->podcastLink) {
            $podcastLink = $xml->createElement("link", htmlspecialchars($podcast->podcastLink, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlChannel->appendChild($podcastLink);
        } elseif ($podcast->url) {
            $podcastLink = $xml->createElement("link", htmlspecialchars($podcast->url, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlChannel->appendChild($podcastLink);
        }

        // Podcast Language
        $site = Craft::$app->getSites()->getCurrentSite();
        $siteLanguage = $site->language;
        $podcastLanguage = $xml->createElement("language", $siteLanguage);
        $xmlChannel->appendChild($podcastLanguage);

        // Podcast Image
        list($imageField, $imageFieldContainer) = GeneralHelper::getElementImageField('podcast', $podcastMapping);
        if ($imageField) {
            if (get_class($imageField) == 'craft\fields\PlainText') {
                $imageFieldHandle = $imageField->handle;
                $imageUrl = $podcast->{$imageFieldHandle};
            } elseif (get_class($imageField) == 'craft\fields\Assets') {
                $imageFieldHandle = $imageField->handle;
                $podcastImage = $podcast->$imageFieldHandle->one();
                if ($podcastImage) {
                    $imageUrl = $podcastImage->url;
                }
            }
            if (isset($imageUrl)) {
                $xmlPodcastImage = $xml->createElement("itunes:image");
                $xmlPodcastImage->setAttribute("href", htmlspecialchars($imageUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlChannel->appendChild($xmlPodcastImage);
            }
        }

        // Podcast Description
        $descriptionField = GeneralHelper::getElementDescriptionField('podcast', $podcastMapping);
        if ($descriptionField) {
            $descriptionFieldHandle = $descriptionField->handle;
            $xmlPodcastItunesSummary = $xml->createElement("itunes:summary", htmlspecialchars($podcast->$descriptionFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlChannel->appendChild($xmlPodcastItunesSummary);
            $xmlPodcastDescription = $xml->createElement("description", htmlspecialchars($podcast->$descriptionFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlChannel->appendChild($xmlPodcastDescription);
        }

        // Podcast Category
        list($categoryGroup, $categoryField) = GeneralHelper::getElementCategoryField('podcast', $podcastMapping);
        if ($categoryGroup) {
            if (get_class($categoryField) == Categories::class) {
                $categories = $podcast->{$categoryField->handle}->level(1)->all();
            } elseif (get_class($categoryField) == Entries::class) {
                $categories = $podcast->{$categoryField->handle}->all();
            } else {
                throw new ServerErrorHttpException('not supported field type' . get_class($categoryField));
            }
            foreach ($categories as $category) {
                $xmlPodcastCategory = $xml->createElement("itunes:category");
                $xmlPodcastCategory->setAttribute("text", htmlspecialchars($category->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlChannel->appendChild($xmlPodcastCategory);
                $subCategories = $podcast->{$categoryField->handle}->descendantOf($category->id)->all();
                foreach ($subCategories as $subCategory) {
                    $xmlPodcastSubCategory = $xml->createElement("itunes:category");
                    $xmlPodcastSubCategory->setAttribute("text", htmlspecialchars($subCategory->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    $xmlPodcastCategory->appendChild($xmlPodcastSubCategory);
                }
            }
        }

        // Podcast Explicit
        if (isset($podcast->podcastExplicit)) {
            if ($podcast->podcastExplicit == '1') {
                $podcastExplicit = 'yes';
            } else {
                $podcastExplicit = 'no';
            }
            $xmlPodcastExplicit = $xml->createElement("itunes:explicit", $podcastExplicit);
            $xmlChannel->appendChild($xmlPodcastExplicit);
        }

        // Podcast Author
        if ($podcast->authorName) {
            $xmlPodcastAuthor = $xml->createElement("itunes:author", htmlspecialchars($podcast->authorName, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlChannel->appendChild($xmlPodcastAuthor);
        }

        // Podcast Owner
        if ($podcast->ownerName || $podcast->ownerEmail) {
            $xmlPodcastOwner = $xml->createElement("itunes:owner");
            $xmlChannel->appendChild($xmlPodcastOwner);

            if ($podcast->ownerName) {
                $xmlPodcastOwnerName = $xml->createElement("itunes:name", htmlspecialchars($podcast->ownerName, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlPodcastOwner->appendChild($xmlPodcastOwnerName);
            }

            if ($podcast->ownerEmail) {
                $xmlPodcastOwnerEmail = $xml->createElement("itunes:email", $podcast->ownerEmail);
                $xmlPodcastOwner->appendChild($xmlPodcastOwnerEmail);
            }
        }

        // Podcast New feed url
        if (isset($podcast->podcastIsNewFeedUrl) && $podcast->podcastIsNewFeedUrl) {
            $xmlPodcastNewFeedURL = $xml->createElement("itunes:new-feed-url", htmlspecialchars($site->getBaseUrl() . 'podcasts/rss?podcastId=' . $podcastId . '&siteId=' . $siteId));
            $xmlChannel->appendChild($xmlPodcastNewFeedURL);
        }

        // Podcast Copyright
        if ($podcast->copyright) {
            $xmlPodcastCopyright = $xml->createElement("copyright", htmlspecialchars($podcast->copyright, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlChannel->appendChild($xmlPodcastCopyright);
        }

        // Podcast Block
        if ($podcast->podcastBlock) {
            $xmlPodcastBlock = $xml->createElement("itunes:block", 'yes');
            $xmlChannel->appendChild($xmlPodcastBlock);
        }

        // Podcast Complete
        if ($podcast->podcastComplete) {
            $xmlPodcastComplete = $xml->createElement("itunes:complete", 'yes');
            $xmlChannel->appendChild($xmlPodcastComplete);
        }

        // Podcast type
        if ($podcast->podcastType) {
            $xmlPodcastType = $xml->createElement("itunes:type", $podcast->podcastType);
            $xmlChannel->appendChild($xmlPodcastType);
        }

        $fieldHandle = null;
        $fieldContainer = null;

        if (isset($episodeMapping['mainAsset']['container'])) {
            $fieldContainer = $episodeMapping['mainAsset']['container'];
        }
        if (isset($episodeMapping['mainAsset']['field'])) {
            $fieldUid = $episodeMapping['mainAsset']['field'];
            if ($fieldUid) {
                $field = Craft::$app->fields->getFieldByUid($fieldUid);
                if ($field) {
                    $fieldHandle = $field->handle;
                } else {
                    throw new ServerErrorHttpException('episode field is not specified');
                }
            }
        }

        foreach ($episodes as $episode) {
            $xmlItem = $xml->createElement("item");
            $xmlTitle = $xml->createElement("title", htmlspecialchars($episode->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlItem->appendChild($xmlTitle);
            list($assetFilename, $assetFilePath, $assetFileUrl, $blockId, $asset) = GeneralHelper::getElementAsset($episode, $fieldContainer, $fieldHandle);
            $xmlEnclosure = $xml->createElement("enclosure");
            if ($assetFileUrl && $asset) {
                $xmlEnclosure->setAttribute("url", htmlspecialchars($assetFileUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlItem->appendChild($xmlEnclosure);

                $fs = $asset->getVolume()->getFs();
                if ($fs instanceof LocalFsInterface) {
                    /** @var Local $fs */
                    $volumePath = $fs->path;
                    $path = Craft::getAlias($volumePath . '/' . $asset->getPath());
                    $type = 'local';
                } else {
                    $path = $asset->getUrl();
                    $type = 'remote';
                }
                $fileInfo = Id3::analyze($type, $path);

                // TODO: maybe keep fileinfo in db instead of fetching from file
                if (isset($fileInfo['filesize'])) {
                    $fileSize = $fileInfo['filesize'];
                    $xmlEnclosure->setAttribute("length", $fileSize);
                }
                if (isset($fileInfo['mime_type'])) {
                    $mime_type = $fileInfo['mime_type'];
                    $xmlEnclosure->setAttribute("type", $mime_type);
                }
            }

            // Episode Link
            if ($episode->url) {
                $episodeLink = $xml->createElement("link", htmlspecialchars($episode->url, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlItem->appendChild($episodeLink);
            }

            // Episode type
            if ($episode->episodeType) {
                $episodeType = $xml->createElement("itunes:episodeType", htmlspecialchars($episode->episodeType, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlItem->appendChild($episodeType);
            }

            // Episode season
            if ($episode->episodeSeason) {
                $episodeSeason = $xml->createElement("itunes:season", (string)$episode->episodeSeason);
                $xmlItem->appendChild($episodeSeason);
            }

            // Episode number
            if ($episode->episodeNumber) {
                $episodeNumber = $xml->createElement("itunes:episode", (string)$episode->episodeNumber);
                $xmlItem->appendChild($episodeNumber);
            }

            // Episode Image
            list($imageField, $imageFieldContainer) = GeneralHelper::getElementImageField('episode', $episodeMapping);
            if ($imageField) {
                if (get_class($imageField) == 'craft\fields\PlainText') {
                    $imageFieldHandle = $imageField->handle;
                    $imageUrl = $episode->{$imageFieldHandle};
                } elseif (get_class($imageField) == 'craft\fields\Assets') {
                    $imageFieldHandle = $imageField->handle;
                    $episodeImage = $episode->$imageFieldHandle->one();
                    if ($episodeImage) {
                        $imageUrl = $episodeImage->url;
                    }
                }
                if (isset($imageUrl)) {
                    $xmlEpisodeImage = $xml->createElement("itunes:image");
                    $xmlEpisodeImage->setAttribute("href", htmlspecialchars($imageUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    $xmlItem->appendChild($xmlEpisodeImage);
                }
            }

            // Episode block
            if (isset($episode->episodeBlock) && $episode->episodeBlock == '1') {
                $xmlEpisodeBlock = $xml->createElement("itunes:block", 'yes');
                $xmlItem->appendChild($xmlEpisodeBlock);
            }

            // Episode Explicit
            if (isset($episode->episodeExplicit)) {
                if ($episode->episodeExplicit == '1') {
                    $episodeExplicit = 'yes';
                } else {
                    $episodeExplicit = 'no';
                }
                $xmlEpisodeExplicit = $xml->createElement("itunes:explicit", $episodeExplicit);
                $xmlItem->appendChild($xmlEpisodeExplicit);
            }

            // Episode Description
            $descriptionField = GeneralHelper::getElementDescriptionField('episode', $episodeMapping);
            if ($descriptionField) {
                $descriptionFieldHandle = $descriptionField->handle;
                $xmlEpisodeSummary = $xml->createElement("itunes:summary", htmlspecialchars($episode->{$descriptionFieldHandle}, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlItem->appendChild($xmlEpisodeSummary);
                $xmlEpisodeDescription = $xml->createElement("description", htmlspecialchars($episode->{$descriptionFieldHandle}, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlItem->appendChild($xmlEpisodeDescription);
            }

            // Episode duration
            if ($episode->duration) {
                $episodeDuration = $xml->createElement("itunes:duration", (string)$episode->duration);
                $xmlItem->appendChild($episodeDuration);
            }

            // Episode Pub Date
            $date = null;
            if (isset($episodeMapping['episodePubDate']['field']) && $episodeMapping['episodePubDate']['field']) {
                $fieldUid = $episodeMapping['episodePubDate']['field'];
                $field = Craft::$app->fields->getFieldByUid($fieldUid);
                if ($field) {
                    $pubDateFieldHandle = $field->handle;
                    $date = $episode->{$pubDateFieldHandle};
                    if ($date) {
                        $date = $date->format('D, d M Y H:i:s T');
                        $xmlEpisodePubDate = $xml->createElement("pubDate", $date);
                        $xmlItem->appendChild($xmlEpisodePubDate);
                    }
                }
            }
            if (!isset($episodeMapping['episodePubDate']['field']) || !$episodeMapping['episodePubDate']['field'] || !$date) {
                $date = $episode->dateCreated;
                $date = $date->format('D, d M Y H:i:s T');
                $xmlEpisodePubDate = $xml->createElement("pubDate", $date);
                $xmlItem->appendChild($xmlEpisodePubDate);
            }

            // Episode GUID
            if ($episode->episodeGUID) {
                $xmlEpisodeGUID = $xml->createElement("guid", $episode->episodeGUID);
                $xmlItem->appendChild($xmlEpisodeGUID);
            }

            $xmlChannel->appendChild($xmlItem);
        }

        \Yii::$app->response->format = \yii\web\Response::FORMAT_XML;
        echo $xml->saveXML();
    }

    /**
     * Generate general setting's template for podcast
     *
     * @param int $podcastId
     * @param PodcastGeneralSettings $settings
     * @return Response
     */
    public function actionPodcastGeneralSettings(int $podcastId, PodcastGeneralSettings $settings = null): Response
    {
        $this->requirePermission('studio-editPodcastGeneralSettings-' . $podcastId);

        if ($settings === null) {
            $settings = Studio::$plugin->podcasts->getPodcastGeneralSettings($podcastId);
        }

        $variables['podcastId'] = $podcastId;
        $variables['settings'] = $settings;

        return $this->renderTemplate(
            'studio/podcasts/_generalSettings',
            $variables
        );
    }

    /**
     * Generate episode setting's template for podcast
     *
     * @param int $podcastId
     * @param PodcastEpisodeSettings $settings
     * @return Response
     */
    public function actionPodcastEpisodeSettings(int $podcastId, PodcastEpisodeSettings $settings = null): Response
    {
        $this->requirePermission('studio-editPodcastEpisodeSettings-' . $podcastId);

        if ($settings === null) {
            $settings = Studio::$plugin->podcasts->getPodcastEpisodeSettings($podcastId);
        }
        $settings->pubDateOnImport = DateTimeHelper::toDateTime($settings->pubDateOnImport);

        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }
        $podcastFormat = $podcast->getPodcastFormat();
        $sitesSettings = $podcastFormat->getSiteSettings();
        $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
        $mapping = json_decode($podcastFormatEpisode->mapping, true);
        if (empty($sitesSettings)) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'You should have set episode site settings first'));
            return $this->redirect('studio/settings/podcast-formats/' . $podcastFormat->id . '?#site-settings');
        }

        // Genres
        list($tagGroup) = GeneralHelper::getElementTagField('episode', $mapping);
        list($categoryGroup) = GeneralHelper::getElementCategoryField('episode', $mapping);
        list($genreFieldType, $genreFieldHandle, $genreFieldGroup) = GeneralHelper::getElementGenreField('episode', $mapping);
        list($imageField) = GeneralHelper::getElementImageField('episode', $mapping);

        $variables['categoryOptions'][] = ['value' => '', 'label' => Craft::t('studio', 'select category')];
        if ($categoryGroup) {
            foreach (\craft\elements\Category::find()->groupId($categoryGroup->id)->all() as $categoryItem) {
                $categoryOption['value'] = $categoryItem->id;
                $categoryOption['label'] = $categoryItem->title;
                $variables['categoryOptions'][] = $categoryOption;
            }
        }

        $variables['tagOptions'][] = ['value' => '', 'label' => Craft::t('studio', 'select tag')];
        if ($tagGroup) {
            foreach (\craft\elements\Tag::find()->groupId($tagGroup->id)->all() as $tagItem) {
                $tagOption['value'] = $tagItem->id;
                $tagOption['label'] = $tagItem->title;
                $variables['tagOptions'][] = $tagOption;
            }
        }

        $variables['genreOptions'][] = ['value' => '', 'label' => Craft::t('studio', 'select genre')];
        if (isset($genreFieldType)) {
            if ($genreFieldType == Tags::class) {
                foreach (\craft\elements\Tag::find()->groupId($genreFieldGroup->id)->all() as $tagItem) {
                    $genreOption['value'] = $tagItem->id;
                    $genreOption['label'] = $tagItem->title;
                    $variables['genreOptions'][] = $genreOption;
                }
            } elseif ($genreFieldType == Categories::class) {
                foreach (\craft\elements\Category::find()->groupId($genreFieldGroup->id)->all() as $categoryItem) {
                    $genreOption['value'] = $categoryItem->id;
                    $genreOption['label'] = $categoryItem->title;
                    $variables['genreOptions'][] = $genreOption;
                }
            } elseif ($genreFieldType == Entries::class) {
                foreach (\craft\elements\Entry::find()->sectionId($genreFieldGroup->id)->all() as $genreItem) {
                    $genreOption['value'] = $genreItem->id;
                    $genreOption['label'] = $genreItem->title;
                    $variables['genreOptions'][] = $genreOption;
                }
            }
        }

        $variables['genreImportOptions'] = [
            ['value' => '', 'label' => Craft::t('studio', 'select one')],
            ['value' => 'only-meta', 'label' => Craft::t('studio', 'use only meta genres')],
            ['value' => 'only-default', 'label' => Craft::t('studio', 'use only default genres')],
            ['value' => 'default-if-not-meta', 'label' => Craft::t('studio', 'use default genres only if meta genre is not available')],
            ['value' => 'meta-and-default', 'label' => Craft::t('studio', 'merge default genres and meta genres')],
        ];

        $variables['volumes'][] = ['value' => '', 'label' => Craft::t('studio', 'select volume')];
        foreach (Craft::$app->volumes->getAllVolumes() as $volumeItem) {
            $volume['value'] = $volumeItem->id;
            $volume['label'] = $volumeItem->name;
            $variables['volumes'][] = $volume;
        }

        $variables['settings'] = $settings;
        $variables['podcasts'] = [];
        $variables['podcastId'] = $podcastId;

        if (isset($imageField)) {
            if (get_class($imageField) == 'craft\fields\Assets') {
                $episode = new Episode();
                $folderId = $imageField->resolveDynamicPathToFolderId($episode);
                if (empty($folderId)) {
                    throw new BadRequestHttpException('The target destination provided for uploading is not valid');
                }

                $folder = Craft::$app->getAssets()->findFolder(['id' => $folderId]);

                if (!$folder) {
                    throw new BadRequestHttpException('The target folder provided for uploading is not valid');
                }

                $folderUid = $folder->uid;
                $variables['folder'] = 'folder:' . $folderUid;
            }
        }

        $variables['images'] = [];
        if (isset($settings->imageOnImport['episode']) && $settings->imageOnImport['episode']) {
            $image = Craft::$app->elements->getElementById($settings->imageOnImport['episode'][0]);
            $variables['images'] = [$image];
        }

        return $this->renderTemplate(
            'studio/podcasts/_episodeSettings',
            $variables
        );
    }

    /**
     * Save podcasts general settings
     *
     * @return Response|null|false
     */
    public function actionGeneralSettingsSave(): Response|null|false
    {
        $this->requirePostRequest();
        $podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        if ($podcastId) {
            $settings = Studio::$plugin->podcasts->getPodcastGeneralSettings($podcastId);
        } else {
            throw new NotFoundHttpException(Craft::t('studio', 'Podcasts id is not provided.'));
        }
        $this->requirePermission('studio-importEpisode' . $podcastId);

        $settings->podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        $settings->publishRSS = Craft::$app->getRequest()->getBodyParam('publishRSS', $settings->publishRSS);

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'Couldn’t save podcast general settings.'));

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
        Db::upsert('{{%studio_podcast_general_settings}}', [
            'userId' => $userId,
            'podcastId' => $podcastId,
            'publishRSS' => $settings->publishRSS,
        ], [
            'publishRSS' => $settings->publishRSS,
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('studio', 'Podcast general settings saved'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Save podcasts episode settings
     *
     * @return Response|null|false
     */
    public function actionEpisodeSettingsSave(): Response|null|false
    {
        $this->requirePostRequest();
        $podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        if ($podcastId) {
            $settings = Studio::$plugin->podcasts->getPodcastEpisodeSettings($podcastId);
        } else {
            throw new NotFoundHttpException(Craft::t('studio', 'Podcasts id is not provided.'));
        }
        $this->requirePermission('studio-importEpisode' . $podcastId);

        $settings->setScenario('import');
        $settings->podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        $settings->tagOnImport = Craft::$app->getRequest()->getBodyParam('tagOnImport', $settings->tagOnImport);
        $settings->categoryOnImport = Craft::$app->getRequest()->getBodyParam('categoryOnImport', $settings->categoryOnImport);
        $settings->genreOnImport = Craft::$app->getRequest()->getBodyParam('genreOnImport', $settings->genreOnImport);
        $settings->genreImportOption = Craft::$app->getRequest()->getBodyParam('genreImportOption', $settings->genreImportOption);
        $settings->genreImportCheck = Craft::$app->getRequest()->getBodyParam('genreImportCheck', $settings->genreImportCheck);
        $settings->imageOnImport = Craft::$app->getRequest()->getBodyParam('imageOnImport', $settings->imageOnImport);
        $settings->pubDateOnImport = Craft::$app->getRequest()->getBodyParam('pubDateOnImport', $settings->pubDateOnImport);
        $settings->forcePubDate = Craft::$app->getRequest()->getBodyParam('forcePubDate', $settings->forcePubDate);
        $settings->forceImage = Craft::$app->getRequest()->getBodyParam('forceImage', $settings->forceImage);

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
        ], [
            'settings' => json_encode($settings),
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('studio', 'Podcast episode settings saved'));

        return $this->redirectToPostedUrl();
    }
}
