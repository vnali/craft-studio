<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\base\Element;
use craft\db\Table;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Tags;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UrlManager;
use craft\web\View;
use DOMDocument;

use vnali\studio\elements\db\EpisodeQuery;
use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\elements\Podcast as PodcastElement;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\models\PodcastEpisodeSettings;
use vnali\studio\models\PodcastFormat;
use vnali\studio\models\PodcastGeneralSettings;
use vnali\studio\Studio;
use yii\caching\TagDependency;
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
                    // TODO: baseUrl need to be fixed
                    'baseUrl' => "podcasts/$podcastFormat->handle/new",
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
     * @param string|null $site
     * @return Response
     */
    public function actionRss(int $podcastId, ?string $site = null): Response
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

        /** @var PodcastElement|null $podcast */
        $podcast = PodcastElement::find()->id($podcastId)->status(null)->siteId($siteId)->one();
        $generalSettings = Studio::$plugin->podcasts->getPodcastGeneralSettings($podcastId, $siteId);
        $userSession = Craft::$app->getUser();
        $currentUser = $userSession->getIdentity();

        if (!$podcast) {
            throw new ServerErrorHttpException('Invalid podcast');
        }

        if (!$podcast->enabled && (!$currentUser || (!$currentUser->can("studio-viewPodcast-" . $podcast->uid) && !$currentUser->can("studio-managePodcasts")))) {
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

        if (isset($podcast->podcastRedirectTo) && $podcast->podcastRedirectTo) {
            header("HTTP/1.1 301 Moved Permanently");
            header("Location: " . $podcast->podcastRedirectTo);
            exit();
        }

        $variables = [];
        $rssCacheKey = 'studio-plugin-' . $siteId . '-' . $podcast->id;
        $variables = $cache->getOrSet($rssCacheKey, function() use ($podcast, $site, $variables) {
            $podcastFormat = $podcast->getPodcastFormat();
            $podcastMapping = json_decode($podcastFormat->mapping, true);
            $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
            $episodeMapping = json_decode($podcastFormatEpisode->mapping, true);

            $episodeQuery = EpisodeElement::find()->siteId($site->id);
            /** @var EpisodeQuery $episodeQuery */
            $episodeQuery->podcastId = $podcast->id;
            /** @var EpisodeElement[] $episodes */
            $episodes = $episodeQuery->all();

            // Latest updated date for episodes
            $lastBuildDate = null;
            $latestUpdatedEpisode = $episodeQuery->orderBy('dateUpdated desc')->one();
            if ($latestUpdatedEpisode) {
                $lastBuildDate = $latestUpdatedEpisode->dateUpdated;
            }

            // Create the document.
            $xml = new DOMDocument("1.0", "UTF-8");
            $xml->preserveWhiteSpace = false;
            $xml->formatOutput = true;
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

            // Compare podcast updated date with latest update date for episodes
            $podcastUpdate = $podcast->dateUpdated;
            if ($lastBuildDate < $podcastUpdate) {
                $lastBuildDate = $podcastUpdate;
            }

            // Add lastBuildDate and pubDate
            $lastBuildDate = $lastBuildDate->format('D, d M Y H:i:s T');
            $pubDate = $xml->createElement("pubDate", $lastBuildDate);
            $xmlChannel->appendChild($pubDate);
            $lastBuildDate = $xml->createElement("lastBuildDate", $lastBuildDate);
            $xmlChannel->appendChild($lastBuildDate);

            // Podcast Link
            if ($podcast->podcastLink) {
                $podcastLink = $xml->createElement("link", htmlspecialchars($podcast->podcastLink, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlChannel->appendChild($podcastLink);
            } elseif ($podcast->url) {
                $podcastLink = $xml->createElement("link", htmlspecialchars($podcast->url, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlChannel->appendChild($podcastLink);
            }

            // Podcast Language
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

            // Podcast Description, itunes:summary
            $descriptionField = GeneralHelper::getElementDescriptionField('podcast', $podcastMapping);
            if ($descriptionField) {
                $descriptionFieldHandle = $descriptionField->handle;
                $descriptionTxt = $podcast->$descriptionFieldHandle;
                if ($descriptionTxt) {
                    $xmlPodcastSummary = $xml->createElement("itunes:summary");
                    $xmlPodcastSummary->appendChild($xml->createCDATASection($descriptionTxt));
                    $xmlChannel->appendChild($xmlPodcastSummary);

                    $xmlPodcastDescription = $xml->createElement("description");
                    $xmlPodcastDescription->appendChild($xml->createCDATASection($descriptionTxt));
                    $xmlChannel->appendChild($xmlPodcastDescription);
                }
            }

            // Podcast Category
            list($categoryGroup, $categoryField) = GeneralHelper::getElementCategoryField('podcast', $podcastMapping);
            if ($categoryGroup) {
                if (get_class($categoryField) == Categories::class || get_class($categoryField) == Entries::class) {
                    $categories = $podcast->{$categoryField->handle}->level(1)->all();
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
                $xmlPodcastNewFeedURL = $xml->createElement("itunes:new-feed-url", htmlspecialchars($site->getBaseUrl() . 'podcasts/rss?podcastId=' . $podcast->id . '&site=' . $site->handle));
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
                // Episode Pub Date
                $pubDate = null;
                if (isset($episodeMapping['episodePubDate']['field']) && $episodeMapping['episodePubDate']['field']) {
                    $fieldUid = $episodeMapping['episodePubDate']['field'];
                    $pubDateField = Craft::$app->fields->getFieldByUid($fieldUid);
                    if ($pubDateField) {
                        $pubDateFieldHandle = $pubDateField->handle;
                        $pubDate = $episode->{$pubDateFieldHandle};
                        $now = new \DateTime('now');
                        if ($now < $pubDate) {
                            continue;
                        }
                    }
                }

                $xmlItem = $xml->createElement("item");
                $xmlTitle = $xml->createElement("title", htmlspecialchars($episode->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $xmlItem->appendChild($xmlTitle);
                list($assetFilename, $assetFilePath, $assetFileUrl, $blockId, $asset) = GeneralHelper::getElementAsset($episode, $fieldContainer, $fieldHandle);
                $xmlEnclosure = $xml->createElement("enclosure");
                if ($assetFileUrl && $asset) {
                    $xmlEnclosure->setAttribute("url", htmlspecialchars($assetFileUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    $xmlItem->appendChild($xmlEnclosure);

                    $xmlEnclosure->setAttribute("length", $asset->size);
                    $xmlEnclosure->setAttribute("type", FileHelper::getMimeTypeByExtension($assetFileUrl));
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
                        if ($episode->$imageFieldHandle) {
                            $episodeImage = $episode->$imageFieldHandle->one();
                            if ($episodeImage) {
                                $imageUrl = $episodeImage->url;
                            } else {
                                $imageUrl = null;
                            }
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

                // Episode itunes:subtitle
                $subtitleField = GeneralHelper::getElementSubtitleField('episode', $episodeMapping);
                if ($subtitleField) {
                    $subtitleFieldHandle = $subtitleField->handle;
                    $xmlEpisodeSubtitle = $xml->createElement("itunes:subtitle", htmlspecialchars($episode->{$subtitleFieldHandle}, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    $xmlItem->appendChild($xmlEpisodeSubtitle);
                }

                // Episode itunes:summary
                $summaryField = GeneralHelper::getElementSummaryField('episode', $episodeMapping);
                if ($summaryField) {
                    $summaryFieldHandle = $summaryField->handle;
                    if ($episode->{$summaryFieldHandle}) {
                        $xmlEpisodeSummary = $xml->createElement("itunes:summary");
                        $xmlEpisodeSummary->appendChild($xml->createCDATASection($episode->{$summaryFieldHandle}));
                        $xmlItem->appendChild($xmlEpisodeSummary);
                    }
                }

                // Episode Description
                $descriptionField = GeneralHelper::getElementDescriptionField('episode', $episodeMapping);
                if ($descriptionField) {
                    $descriptionFieldHandle = $descriptionField->handle;
                    if ($episode->{$descriptionFieldHandle}) {
                        $xmlEpisodeDescription = $xml->createElement("description");
                        $xmlEpisodeDescription->appendChild($xml->createCDATASection($episode->{$descriptionFieldHandle}));
                        $xmlItem->appendChild($xmlEpisodeDescription);
                    }
                }

                // Episode Content encoded
                $contentEncodedField = GeneralHelper::getElementContentEncodedField('episode', $episodeMapping);
                if ($contentEncodedField) {
                    $contentEncodedFieldHandle = $contentEncodedField->handle;
                    if ($episode->{$contentEncodedFieldHandle}) {
                        $xmlEpisodeContentEncoded = $xml->createElement("content:encoded");
                        $xmlEpisodeContentEncoded->appendChild($xml->createCDATASection($episode->{$contentEncodedFieldHandle}));
                        $xmlItem->appendChild($xmlEpisodeContentEncoded);
                    }
                }

                // Episode duration
                if ($episode->duration) {
                    $episodeDuration = $xml->createElement("itunes:duration", (string)$episode->duration);
                    $xmlItem->appendChild($episodeDuration);
                }

                // Episode Pub Date
                if ($pubDate) {
                    $date = $pubDate->format('D, d M Y H:i:s T');
                    $xmlEpisodePubDate = $xml->createElement("pubDate", $date);
                    $xmlItem->appendChild($xmlEpisodePubDate);
                }
                if (!isset($episodeMapping['episodePubDate']['field']) || !$episodeMapping['episodePubDate']['field'] || !$pubDate) {
                    $date = $episode->dateCreated;
                    $date = $date->format('D, d M Y H:i:s T');
                    $xmlEpisodePubDate = $xml->createElement("pubDate", $date);
                    $xmlItem->appendChild($xmlEpisodePubDate);
                }

                // Episode keywords
                list($keywordField) = GeneralHelper::getElementKeywordsField('episode', $episodeMapping);
                if ($keywordField) {
                    if (get_class($keywordField) == 'craft\fields\PlainText') {
                        $keywordFieldHandle = $keywordField->handle;
                        $keywords = $episode->{$keywordFieldHandle};
                    } else {
                        $keywordFieldHandle = $keywordField->handle;
                        $keywords = $episode->$keywordFieldHandle->collect();
                        $keywords = $keywords->pluck('title')->join(', ');
                    }
                    if (isset($keywords)) {
                        $xmlEpisodeKeywords = $xml->createElement("itunes:keywords", htmlspecialchars($keywords, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlItem->appendChild($xmlEpisodeKeywords);
                    }
                }

                // Episode GUID
                if ($episode->episodeGUID) {
                    $xmlEpisodeGUID = $xml->createElement("guid", $episode->episodeGUID);
                    $xmlItem->appendChild($xmlEpisodeGUID);
                }

                $xmlChannel->appendChild($xmlItem);
            }

            $variables['xml'] = $xml->saveXML();
            return $variables;
        }, 0, new TagDependency(['tags' => ['studio-plugin', 'element::' . PodcastElement::class . '::*', 'element::' . EpisodeElement::class . '::*']]));

        Craft::$app->view->setTemplateMode(View::TEMPLATE_MODE_CP);
        return $this->renderTemplate(
            'studio/podcasts/_rss',
            $variables
        );
    }

    /**
     * Generate general setting's template for podcast
     *
     * @param int $podcastId
     * @param int $siteId
     * @param PodcastGeneralSettings $settings
     * @return Response
     */
    public function actionPodcastGeneralSettings(int $podcastId, int $siteId, PodcastGeneralSettings $settings = null): Response
    {
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId, $siteId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user->can('studio-managePodcasts') && !$user->can('studio-editPodcastGeneralSettings-' . $podcast->uid)) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        if ($settings === null) {
            $settings = Studio::$plugin->podcasts->getPodcastGeneralSettings($podcastId, $siteId);
        }

        $site = Craft::$app->sites->getSiteById($siteId);

        $variables['podcastId'] = $podcastId;
        $variables['settings'] = $settings;
        $variables['podcast'] = $podcast;
        $variables['site'] = $site;

        return $this->renderTemplate(
            'studio/podcasts/_generalSettings',
            $variables
        );
    }

    /**
     * Generate episode setting's template for podcast
     *
     * @param int $podcastId
     * @param int $siteId
     * @param PodcastEpisodeSettings $settings
     * @return Response
     */
    public function actionPodcastEpisodeSettings(int $podcastId, int $siteId, PodcastEpisodeSettings $settings = null): Response
    {
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId, $siteId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user->can('studio-managePodcasts') && !$user->can('studio-editPodcastEpisodeSettings-' . $podcast->uid)) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        $siteUid = Db::uidById(Table::SITES, $siteId);
        if (Craft::$app->getIsMultiSite() && !$currentUser->can('editSite:' . $siteUid)) {
            throw new ServerErrorHttpException('User have no access to this site');
        }

        if ($settings === null) {
            $settings = Studio::$plugin->podcasts->getPodcastEpisodeSettings($podcastId, $siteId);
        }
        $settings->defaultPubDate = DateTimeHelper::toDateTime($settings->defaultPubDate);
        $podcastFormat = $podcast->getPodcastFormat();
        $sitesSettings = $podcastFormat->getSiteSettings();
        $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
        $mapping = json_decode($podcastFormatEpisode->mapping, true);
        if (empty($sitesSettings)) {
            Craft::$app->getSession()->setError(Craft::t('studio', 'You should have set episode site settings first'));
            return $this->redirect('studio/settings/podcast-formats/' . $podcastFormat->id . '?#site-settings');
        }

        // Genres
        list($genreFieldType, $genreFieldHandle, $genreFieldGroup) = GeneralHelper::getElementGenreField('episode', $mapping);
        list($imageField) = GeneralHelper::getElementImageField('episode', $mapping);

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
            ['value' => '', 'label' => Craft::t('studio', "Don't import")],
            ['value' => 'only-metadata', 'label' => Craft::t('studio', 'Use only genre metadata')],
            ['value' => 'only-default', 'label' => Craft::t('studio', 'Use only default values')],
            ['value' => 'default-if-not-metadata', 'label' => Craft::t('studio', 'Use default genres only if metadata is not available')],
            ['value' => 'metadata-and-default', 'label' => Craft::t('studio', 'Merge default values and metadata')],
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

        $variables['sources'] = [];
        if (isset($imageField)) {
            if (get_class($imageField) == 'craft\fields\Assets') {
                $sources = $imageField->sources;
                $user = Craft::$app->getUser()->getIdentity();
                $volumeSources = [];
                if ($sources == '*') {
                    $volumes = Craft::$app->volumes->getAllVolumes();
                    foreach ($volumes as $volume) {
                        if ($user->can('viewAssets:' . $volume->uid)) {
                            $volumeSources[] = 'volume:' . $volume->uid;
                        }
                    }
                } else {
                    foreach ($sources as $source) {
                        $source = explode(':', $source);
                        if (isset($source[1])) {
                            if ($user->can('viewAssets:' . $source[1])) {
                                $volumeSources[] = 'volume:' . $source[1];
                            }
                        }
                    }
                }
                $variables['sources'] = $volumeSources;
            }
        }

        $variables['images'] = [];
        if (isset($settings->defaultImage) && $settings->defaultImage) {
            $image = Craft::$app->elements->getElementById($settings->defaultImage[0]);
            $variables['images'] = [$image];
        }

        $variables['imageOptions'] = [
            ['value' => '', 'label' => Craft::t('studio', "Don't import")],
            ['value' => 'only-metadata', 'label' => Craft::t('studio', 'Use only image available in metadata')],
            ['value' => 'only-default', 'label' => Craft::t('studio', 'Use only default image')],
            ['value' => 'default-if-not-metadata', 'label' => Craft::t('studio', 'Use default image only if metadata is not available')],
        ];

        $variables['pubDateOptions'] = [
            ['value' => '', 'label' => Craft::t('studio', "Don't import")],
            ['value' => 'only-metadata', 'label' => Craft::t('studio', 'Use only year available in metadata')],
            ['value' => 'only-default', 'label' => Craft::t('studio', 'Use only default pubDate')],
            ['value' => 'default-if-not-metadata', 'label' => Craft::t('studio', 'Use default pubDate only if metadata is not available')],
        ];

        $site = Craft::$app->sites->getSiteById($siteId);
        $variables['podcast'] = $podcast;
        $variables['site'] = $site;

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
        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');
        if ($podcastId) {
            $settings = Studio::$plugin->podcasts->getPodcastGeneralSettings($podcastId, $siteId);
        } else {
            throw new NotFoundHttpException(Craft::t('studio', 'Podcasts id is not provided.'));
        }
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId, $siteId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user->can('studio-managePodcasts') && !$user->can('studio-editPodcastGeneralSettings-' . $podcast->uid)) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        $settings->podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        $settings->publishRSS = Craft::$app->getRequest()->getBodyParam('publishRSS', $settings->publishRSS);
        $settings->allowAllToSeeRSS = Craft::$app->getRequest()->getBodyParam('allowAllToSeeRSS', $settings->allowAllToSeeRSS);
        $settings->siteId = Craft::$app->getRequest()->getBodyParam('siteId');

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
            'siteId' => $siteId,
            'publishRSS' => $settings->publishRSS,
            'allowAllToSeeRSS' => $settings->allowAllToSeeRSS,
        ], [
            'publishRSS' => $settings->publishRSS,
            'allowAllToSeeRSS' => $settings->allowAllToSeeRSS,
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
        $siteId = Craft::$app->getRequest()->getBodyParam('siteId');
        $podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        if ($podcastId) {
            $settings = Studio::$plugin->podcasts->getPodcastEpisodeSettings($podcastId, $siteId);
        } else {
            throw new NotFoundHttpException(Craft::t('studio', 'Podcasts id is not provided.'));
        }
        $podcast = Studio::$plugin->podcasts->getPodcastById($podcastId, $siteId);
        if (!$podcast) {
            throw new NotFoundHttpException('invalid podcast id');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user->can('studio-managePodcasts') && !$user->can('studio-editPodcastEpisodeSettings-' . $podcast->uid)) {
            throw new ForbiddenHttpException('User is not authorized to perform this action.');
        }

        $settings->podcastId = Craft::$app->getRequest()->getBodyParam('podcastId');
        $settings->defaultGenres = Craft::$app->getRequest()->getBodyParam('defaultGenres', $settings->defaultGenres);
        $settings->genreImportOption = Craft::$app->getRequest()->getBodyParam('genreImportOption', $settings->genreImportOption);
        $settings->genreImportCheck = Craft::$app->getRequest()->getBodyParam('genreImportCheck', $settings->genreImportCheck);
        $settings->defaultImage = Craft::$app->getRequest()->getBodyParam('defaultImage', $settings->defaultImage);
        $settings->imageOption = Craft::$app->getRequest()->getBodyParam('imageOption', $settings->imageOption);
        $settings->defaultPubDate = Craft::$app->getRequest()->getBodyParam('defaultPubDate', $settings->defaultPubDate);
        $settings->pubDateOption = Craft::$app->getRequest()->getBodyParam('pubDateOption', $settings->pubDateOption);
        $settings->siteId = $siteId;
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
            'siteId' => $settings->siteId,
            'settings' => json_encode($settings),
        ], [
            'settings' => json_encode($settings),
        ]);

        Craft::$app->getSession()->setNotice(Craft::t('studio', 'Podcast episode settings saved'));

        return $this->redirectToPostedUrl();
    }
}
