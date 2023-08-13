<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\base\Element;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\EntryQuery;
use craft\elements\db\MatrixBlockQuery;
use craft\elements\db\UserQuery;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\data\SingleOptionFieldData;
use craft\fields\Dropdown;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\fields\MultiSelect;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Table as TableField;
use craft\fields\Tags;
use craft\fields\Url;
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
use doublesecretagency\googlemaps\fields\AddressField as GoogleMapAddressField;
use doublesecretagency\googlemaps\models\Address as GoogleMapAddressModel;
use studioespresso\easyaddressfield\fields\EasyAddressFieldField;
use studioespresso\easyaddressfield\models\EasyAddressFieldModel;
use verbb\supertable\elements\db\SuperTableBlockQuery;
use verbb\supertable\elements\SuperTableBlockElement;
use verbb\supertable\fields\SuperTableField;
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

        $siteStatuses = ElementHelper::siteStatusesForElement($podcast, true);
        $podcastEnabled = $siteStatuses[$podcast->siteId];

        if (!$podcastEnabled && (!$currentUser || (!$currentUser->can("studio-viewPodcast-" . $podcast->uid) && !$currentUser->can("studio-managePodcasts")))) {
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

        $tz = Craft::$app->getTimeZone();
        $variables = [];
        $rssCacheKey = 'studio-plugin-' . $siteId . '-' . $podcast->id . '-' . $tz;
        $variables = $cache->getOrSet($rssCacheKey, function() use ($podcast, $site, $tz, $variables) {
            $podcastFormat = $podcast->getPodcastFormat();
            $podcastMapping = json_decode($podcastFormat->mapping, true);
            $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
            $episodeMapping = json_decode($podcastFormatEpisode->mapping, true);

            $episodeQuery = EpisodeElement::find()->siteId($site->id);
            /** @var EpisodeQuery $episodeQuery */
            $episodeQuery->podcastId = $podcast->id;
            /** @var EpisodeElement[] $episodes */
            $episodes = $episodeQuery->rss(true)->all();

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
            $rssNode->setAttribute("xmlns:podcast", 'https://podcastindex.org/namespace/1.0');

            $xmlChannel = $xml->createElement("channel");
            $rssNode->appendChild($xmlChannel);
            $podcastTitle = $xml->createElement("title", htmlspecialchars($podcast->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlChannel->appendChild($podcastTitle);
            $podcastTitle = $xml->createElement("itunes:title", htmlspecialchars($podcast->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            $xmlChannel->appendChild($podcastTitle);

            // We use current time for last build, when cache is created
            $lastBuildDate = new \DateTime('now', new \DateTimeZone($tz));
            // Add lastBuildDate and pubDate
            $lastBuildDate = $lastBuildDate->format('D, d M Y H:i:s T');
            $pubDate = $xml->createElement("pubDate", $lastBuildDate);
            $xmlChannel->appendChild($pubDate);
            $lastBuildDate = $xml->createElement("lastBuildDate", $lastBuildDate);
            $xmlChannel->appendChild($lastBuildDate);

            // Podcast GUID
            if ($podcast->podcastGUID) {
                $xmlPodcastGUID = $xml->createElement("podcast:guid", $podcast->podcastGUID);
                $xmlChannel->appendChild($xmlPodcastGUID);
            }

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

            // Podcast locked
            if (isset($podcast->locked)) {
                if ($podcast->locked == '1') {
                    $podcastLocked = 'yes';
                } else {
                    $podcastLocked = 'no';
                }
                $xmlPodcastLocked = $xml->createElement("podcast:locked", $podcastLocked);
                if ($podcast->ownerEmail) {
                    $xmlPodcastLocked->setAttribute("owner", $podcast->ownerEmail);
                }
                $xmlChannel->appendChild($xmlPodcastLocked);
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

            // Podcast Location
            list($locationField, $locationBlockTypeHandle) = GeneralHelper::getFieldDefinition('podcastLocation');
            if ($locationField) {
                $locationFieldHandle = $locationField->handle;
                if (get_class($locationField) == PlainText::class) {
                    if (isset($podcast->$locationFieldHandle) && $podcast->$locationFieldHandle) {
                        $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($podcast->$locationFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlChannel->appendChild($xmlLocation);
                    }
                } elseif (get_class($locationField) == TableField::class) {
                    if (isset($podcast->$locationFieldHandle) && $podcast->$locationFieldHandle) {
                        foreach ($podcast->$locationFieldHandle as $row) {
                            if (isset($row['name']) && $row['name']) {
                                $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($row['name'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                if (isset($row['geo']) && $row['geo']) {
                                    $xmlLocation->setAttribute("geo", htmlspecialchars($row['geo'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                } elseif (isset($row['lat']) && $row['lat'] && isset($row['lon']) && $row['lon']) {
                                    $geo = 'geo:' . $row['lat'] . ',' . $row['lon'];
                                    $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($row['osm']) && $row['osm']) {
                                    $xmlLocation->setAttribute("osm", htmlspecialchars($row['osm'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                $xmlChannel->appendChild($xmlLocation);
                                break;
                            }
                        }
                    }
                } elseif (get_class($locationField) == EasyAddressFieldField::class) {
                    if (isset($podcast->$locationFieldHandle->name) && $podcast->$locationFieldHandle->name) {
                        $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($podcast->$locationFieldHandle->name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if (isset($podcast->$locationFieldHandle->latitude) && $podcast->$locationFieldHandle->latitude && isset($podcast->$locationFieldHandle->longitude) && $podcast->$locationFieldHandle->longitude) {
                            $geo = 'geo:' . $podcast->$locationFieldHandle->latitude . ',' . $podcast->$locationFieldHandle->longitude;
                            $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        }
                        $xmlChannel->appendChild($xmlLocation);
                    }
                } elseif (get_class($locationField) == GoogleMapAddressField::class) {
                    if (isset($podcast->$locationFieldHandle->name) && $podcast->$locationFieldHandle->name) {
                        $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($podcast->$locationFieldHandle->name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if (isset($podcast->$locationFieldHandle->lat) && $podcast->$locationFieldHandle->lat && isset($podcast->$locationFieldHandle->lng) && $podcast->$locationFieldHandle->lng) {
                            $geo = 'geo:' . $podcast->$locationFieldHandle->lat . ',' . $podcast->$locationFieldHandle->lng;
                            $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        }
                        $xmlChannel->appendChild($xmlLocation);
                    }
                } elseif (get_class($locationField) == Matrix::class || get_class($locationField) == SuperTableField::class) {
                    $locationBlocks = [];
                    if (get_class($locationField) == Matrix::class) {
                        $blockQuery = \craft\elements\MatrixBlock::find();
                        $locationBlocks = $blockQuery->fieldId($locationField->id)->owner($podcast)->type($locationBlockTypeHandle)->all();
                    } elseif (get_class($locationField) == SuperTableField::class) {
                        $blockQuery = SuperTableBlockElement::find();
                        $locationBlocks = $blockQuery->fieldId($locationField->id)->owner($podcast)->all();
                    }
                    foreach ($locationBlocks as $locationBlock) {
                        if (isset($locationBlock->location) && $locationBlock->location) {
                            if (is_object($locationBlock->location) && get_class($locationBlock->location) == EasyAddressFieldModel::class) {
                                if (isset($locationBlock->location->name) && $locationBlock->location->name) {
                                    $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($locationBlock->location->name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    if (isset($locationBlock->location->latitude) && $locationBlock->location->latitude && isset($locationBlock->location->longitude) && $locationBlock->location->longitude) {
                                        $geo = 'geo:' . $locationBlock->location->latitude . ',' . $locationBlock->location->longitude;
                                        $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    $xmlChannel->appendChild($xmlLocation);
                                    break;
                                }
                            } elseif (is_object($locationBlock->location) && get_class($locationBlock->location) == GoogleMapAddressModel::class) {
                                if (isset($locationBlock->location->name) && $locationBlock->location->name) {
                                    $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($locationBlock->location->name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    if (isset($locationBlock->location->lat) && $locationBlock->location->lat && isset($locationBlock->location->lng) && $locationBlock->location->lng) {
                                        $geo = 'geo:' . $locationBlock->location->lat . ',' . $locationBlock->location->lng;
                                        $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    $xmlChannel->appendChild($xmlLocation);
                                    break;
                                }
                            } elseif (!is_object($locationBlock->location)) {
                                $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($locationBlock->location, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlChannel->appendChild($xmlLocation);
                                break;
                            }
                        }
                    }
                }
            }

            // Podcast Funding
            list($fundingField, $fundingBlockTypeHandle) = GeneralHelper::getFieldDefinition('funding');
            if ($fundingField) {
                $fundingFieldHandle = $fundingField->handle;
                if (get_class($fundingField) == PlainText::class || get_class($fundingField) == Url::class) {
                    if (isset($podcast->$fundingFieldHandle) && $podcast->$fundingFieldHandle) {
                        $xmlFunding = $xml->createElement("podcast:funding");
                        $xmlFunding->setAttribute("url", htmlspecialchars($podcast->$fundingFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlChannel->appendChild($xmlFunding);
                    }
                } elseif (get_class($fundingField) == TableField::class) {
                    if (isset($podcast->$fundingFieldHandle) && $podcast->$fundingFieldHandle) {
                        foreach ($podcast->$fundingFieldHandle as $row) {
                            if (isset($row['fundingUrl']) && $row['fundingUrl']) {
                                $xmlFunding = $xml->createElement("podcast:funding", (isset($row['fundingTitle']) && $row['fundingTitle']) ? htmlspecialchars($row['fundingTitle'], ENT_QUOTES | ENT_XML1, 'UTF-8') : '');
                                $xmlFunding->setAttribute("url", $row['fundingUrl']);
                                $xmlChannel->appendChild($xmlFunding);
                            }
                        }
                    }
                } elseif (get_class($fundingField) == Matrix::class || get_class($fundingField) == SuperTableField::class) {
                    $fundingBlocks = [];
                    if (get_class($fundingField) == Matrix::class) {
                        $blockQuery = \craft\elements\MatrixBlock::find();
                        $fundingBlocks = $blockQuery->fieldId($fundingField->id)->owner($podcast)->type($fundingBlockTypeHandle)->all();
                    } elseif (get_class($fundingField) == SuperTableField::class) {
                        $blockQuery = SuperTableBlockElement::find();
                        $fundingBlocks = $blockQuery->fieldId($fundingField->id)->owner($podcast)->all();
                    }
                    foreach ($fundingBlocks as $fundingBlock) {
                        if (isset($fundingBlock->fundingUrl) && $fundingBlock->fundingUrl) {
                            $xmlFunding = $xml->createElement("podcast:funding", (isset($fundingBlock->fundingTitle) && $fundingBlock->fundingTitle) ? htmlspecialchars($fundingBlock->fundingTitle, ENT_QUOTES | ENT_XML1, 'UTF-8') : '');
                            $xmlFunding->setAttribute("url", $fundingBlock->fundingUrl);
                            $xmlChannel->appendChild($xmlFunding);
                        }
                    }
                }
            }

            // Podcast License
            list($licenseField, $licenseBlockTypeHandle) = GeneralHelper::getFieldDefinition('podcastLicense');
            if ($licenseField) {
                $licenseFieldHandle = $licenseField->handle;
                if (get_class($licenseField) == PlainText::class) {
                    if (isset($podcast->$licenseFieldHandle) && $podcast->$licenseFieldHandle) {
                        $xmlLicense = $xml->createElement("podcast:license", htmlspecialchars($podcast->$licenseFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlChannel->appendChild($xmlLicense);
                    }
                } elseif (get_class($licenseField) == Assets::class) {
                    if (isset($podcast->$licenseFieldHandle) && $podcast->$licenseFieldHandle) {
                        $license = $podcast->$licenseFieldHandle->one();
                        if ($license) {
                            if (isset($license->licenseTitle) && $license->licenseTitle) {
                                $licenseTitle = $license->licenseTitle;
                            } elseif (isset($license->title) && $license->title) {
                                $licenseTitle = $license->title;
                            }
                            if (isset($licenseTitle)) {
                                $xmlLicense = $xml->createElement("podcast:license", htmlspecialchars($licenseTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlLicense->setAttribute("url", htmlspecialchars($license->getUrl(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlChannel->appendChild($xmlLicense);
                            }
                        }
                    }
                } elseif (get_class($licenseField) == TableField::class) {
                    if (isset($podcast->$licenseFieldHandle) && $podcast->$licenseFieldHandle) {
                        foreach ($podcast->$licenseFieldHandle  as $row) {
                            if (isset($row['licenseTitle']) && $row['licenseTitle']) {
                                $xmlLicense = $xml->createElement("podcast:license", htmlspecialchars($row['licenseTitle'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                if (isset($row['licenseUrl']) && $row['licenseUrl']) {
                                    $xmlLicense->setAttribute("url", htmlspecialchars($row['licenseUrl'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                $xmlChannel->appendChild($xmlLicense);
                            }
                            break;
                        }
                    }
                } elseif (get_class($licenseField) == Matrix::class || get_class($licenseField) == SuperTableField::class) {
                    $licenseBlocks = [];
                    if (get_class($licenseField) == Matrix::class) {
                        $blockQuery = \craft\elements\MatrixBlock::find();
                        $licenseBlocks = $blockQuery->fieldId($licenseField->id)->owner($podcast)->type($licenseBlockTypeHandle)->all();
                    } elseif (get_class($licenseField) == SuperTableField::class) {
                        $blockQuery = SuperTableBlockElement::find();
                        $licenseBlocks = $blockQuery->fieldId($licenseField->id)->owner($podcast)->all();
                    }
                    foreach ($licenseBlocks as $licenseBlock) {
                        if (isset($licenseBlock->licenseTitle) && $licenseBlock->licenseTitle) {
                            $xmlLicense = $xml->createElement("podcast:license", htmlspecialchars($licenseBlock->licenseTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            if (isset($licenseBlock->licenseUrl) && $licenseBlock->licenseUrl) {
                                if (is_object($licenseBlock->licenseUrl) && get_class($licenseBlock->licenseUrl) == AssetQuery::class) {
                                    $licenseUrl = $licenseBlock->licenseUrl->one();
                                    if ($licenseUrl) {
                                        $xmlLicense->setAttribute("url",  htmlspecialchars($licenseUrl->getUrl(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                } elseif (!is_object($licenseBlock->licenseUrl)) {
                                    $xmlLicense->setAttribute("url",  htmlspecialchars($licenseBlock->licenseUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                            }
                            $xmlChannel->appendChild($xmlLicense);
                        }
                        break;
                    }
                }
            }

            // Podcast Trailer
            list($trailerField, $trailerBlockTypeHandle) = GeneralHelper::getFieldDefinition('trailer');
            if ($trailerField) {
                $trailerFieldHandle = $trailerField->handle;
                if (get_class($trailerField) == Assets::class) {
                    if (isset($podcast->$trailerFieldHandle) && $podcast->$trailerFieldHandle) {
                        $trailers = $podcast->$trailerFieldHandle->all();
                        foreach ($trailers as $trailer) {
                            /** @var Asset $trailer */
                            if (isset($trailer->trailerTitle) && $trailer->trailerTitle) {
                                $trailerTitle = $trailer->trailerTitle;
                            } elseif (isset($trailer->title) && $trailer->title) {
                                $trailerTitle = $trailer->title;
                            }
                            if (isset($trailerTitle) && isset($trailer->pubdate) && $trailer->pubdate) {
                                $xmlTrailer = $xml->createElement("podcast:trailer", htmlspecialchars($trailerTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlTrailer->setAttribute("url", htmlspecialchars($trailer->getUrl(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                if (isset($trailer->season) && $trailer->season) {
                                    $xmlTrailer->setAttribute("season",  htmlspecialchars($trailer->season, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                $xmlTrailer->setAttribute("pubdate",  htmlspecialchars($trailer->pubdate->format('D, d M Y H:i:s T'), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlTrailer->setAttribute("length", (string)$trailer->size);
                                $xmlTrailer->setAttribute("type", $trailer->mimeType);
                                $xmlChannel->appendChild($xmlTrailer);
                            }
                        }
                    }
                } elseif (get_class($trailerField) == Matrix::class || get_class($trailerField) == SuperTableField::class) {
                    $trailerBlocks = [];
                    if (get_class($trailerField) == Matrix::class) {
                        $blockQuery = \craft\elements\MatrixBlock::find();
                        $trailerBlocks = $blockQuery->fieldId($trailerField->id)->owner($podcast)->type($trailerBlockTypeHandle)->all();
                    } elseif (get_class($trailerField) == SuperTableField::class) {
                        $blockQuery = SuperTableBlockElement::find();
                        $trailerBlocks = $blockQuery->fieldId($trailerField->id)->owner($podcast)->all();
                    }
                    foreach ($trailerBlocks as $trailerBlock) {
                        if (isset($trailerBlock->trailerTitle) && $trailerBlock->trailerTitle && isset($trailerBlock->trailer) && $trailerBlock->trailer && isset($trailerBlock->pubdate) && $trailerBlock->pubdate) {
                            $xmlTrailer = $xml->createElement("podcast:trailer", htmlspecialchars($trailerBlock->trailerTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            if (is_object($trailerBlock->trailer) && get_class($trailerBlock->trailer) == AssetQuery::class) {
                                $trailer = $trailerBlock->trailer->one();
                                if ($trailer) {
                                    $url = $trailer->getUrl();
                                    $xmlTrailer->setAttribute("url",  htmlspecialchars($url, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    $xmlTrailer->setAttribute("length", (string)$trailer->size);
                                    $xmlTrailer->setAttribute("type", $trailer->mimeType);
                                } else {
                                    // Trailer url is required
                                    continue;
                                }
                            } elseif (!is_object($trailerBlock->trailer)) {
                                $xmlTrailer->setAttribute("url",  htmlspecialchars($trailerBlock->trailer, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                // Length attribute
                                if (isset($trailerBlock->length) && $trailerBlock->length) {
                                    $xmlTrailer->setAttribute("length", $trailerBlock->length);
                                }
                                // Type attribute
                                if (isset($trailerBlock->mimeType)) {
                                    if (is_object($trailerBlock->mimeType) && get_class($trailerBlock->mimeType) == SingleOptionFieldData::class && $trailerBlock->mimeType->value) {
                                        $xmlTrailer->setAttribute("type", htmlspecialchars($trailerBlock->mimeType->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (!is_object($trailerBlock->mimeType) && $trailerBlock->mimeType) {
                                        $xmlTrailer->setAttribute("type", htmlspecialchars($trailerBlock->mimeType, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }
                            }
                            if (isset($trailerBlock->pubdate)) {
                                $xmlTrailer->setAttribute("pubdate",  htmlspecialchars($trailerBlock->pubdate->format('D, d M Y H:i:s T'), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            }
                            if (isset($trailerBlock->season) && $trailerBlock->season) {
                                $xmlTrailer->setAttribute("season",  htmlspecialchars($trailerBlock->season, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            }
                            $xmlChannel->appendChild($xmlTrailer);
                        }
                    }
                }
            }

            // Podcast medium
            if ($podcast->medium) {
                $xmlPodcastMedium = $xml->createElement("podcast:medium", $podcast->medium);
                $xmlChannel->appendChild($xmlPodcastMedium);
            }

            // podcast:person
            list($personField, $personBlockTypeHandle) = GeneralHelper::getFieldDefinition('podcastPerson');
            if ($personField) {
                $personFieldHandle = $personField->handle;
                if (get_class($personField) == PlainText::class) {
                    if (isset($podcast->$personFieldHandle) && $podcast->$personFieldHandle) {
                        $xmlPodcastPerson = $xml->createElement("podcast:person", htmlspecialchars($podcast->$personFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlChannel->appendChild($xmlPodcastPerson);
                    }
                } elseif (get_class($personField) == TableField::class) {
                    if (isset($podcast->$personFieldHandle) && $podcast->$personFieldHandle) {
                        foreach ($podcast->$personFieldHandle as $row) {
                            if (isset($row['person']) && $row['person']) {
                                $xmlPodcastPerson = $xml->createElement("podcast:person", htmlspecialchars($row['person'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                if (isset($row['personRole']) && $row['personRole']) {
                                    $xmlPodcastPerson->setAttribute("role", htmlspecialchars($row['personRole'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($row['personGroup']) && $row['personGroup']) {
                                    $xmlPodcastPerson->setAttribute("group", htmlspecialchars($row['personGroup'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($row['personImg']) && $row['personImg']) {
                                    $xmlPodcastPerson->setAttribute("img", htmlspecialchars($row['personImg'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($row['personHref']) && $row['personHref']) {
                                    $xmlPodcastPerson->setAttribute("href", htmlspecialchars($row['personHref'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                $xmlChannel->appendChild($xmlPodcastPerson);
                            }
                        }
                    }
                } elseif (get_class($personField) == Matrix::class || get_class($personField) == SuperTableField::class) {
                    $personBlocks = [];
                    if (get_class($personField) == Matrix::class) {
                        $blockQuery = \craft\elements\MatrixBlock::find();
                        $personBlocks = $blockQuery->fieldId($personField->id)->owner($podcast)->type($personBlockTypeHandle)->all();
                    } elseif (get_class($personField) == SuperTableField::class) {
                        $blockQuery = SuperTableBlockElement::find();
                        $personBlocks = $blockQuery->fieldId($personField->id)->owner($podcast)->all();
                    }
                    foreach ($personBlocks as $personBlock) {
                        $personsArray = [];
                        if (isset($personBlock->userPerson) && get_class($personBlock->userPerson) == UserQuery::class && $personBlock->userPerson->one()) {
                            $persons = $personBlock->userPerson->all();
                            foreach ($persons as $person) {
                                if ($person->fullName) {
                                    $personArray = [];
                                    $personArray['name'] = $person->fullName;
                                    if (isset($person->personHref) && $person->personHref) {
                                        $personArray['personHref'] = $person->personHref;
                                    }
                                    if ($person->photoId) {
                                        $photo = Craft::$app->getAssets()->getAssetById($person->photoId);
                                        if ($photo) {
                                            $personArray['personImg'] = $photo->getUrl();
                                        }
                                    } elseif (isset($person->personImg) && $person->personImg) {
                                        if (!is_object($person->personImg)) {
                                            $personArray['personImg'] = $person->personImg;
                                        } else {
                                            if (get_class($person->personImg) == AssetQuery::class) {
                                                $personImg = $person->personImg->one();
                                                if ($personImg) {
                                                    $personArray['personImg'] = $personImg->getUrl();
                                                } else {
                                                    $personArray['personImg'] = null;
                                                }
                                            }
                                        }
                                    }
                                    $personsArray[] = $personArray;
                                }
                            }
                        }
                        if (isset($personBlock->entryPerson) && get_class($personBlock->entryPerson) == EntryQuery::class && $personBlock->entryPerson->one()) {
                            $persons = $personBlock->entryPerson->all();
                            foreach ($persons as $person) {
                                if (isset($person->title) && $person->title) {
                                    $personArray = [];
                                    $personArray['name'] = $person->title;
                                    if (isset($person->personHref) && $person->personHref) {
                                        $personArray['personHref'] = $person->personHref;
                                    }
                                    if (isset($person->personImg) && $person->personImg) {
                                        if (!is_object($person->personImg)) {
                                            $personArray['personImg'] = $person->personImg;
                                        } else {
                                            if (get_class($person->personImg) == AssetQuery::class) {
                                                $personImg = $person->personImg->one();
                                                if ($personImg) {
                                                    $personArray['personImg'] = $personImg->getUrl();
                                                } else {
                                                    $personArray['personImg'] = null;
                                                }
                                            }
                                        }
                                    }
                                    $personsArray[] = $personArray;
                                }
                            }
                        }
                        if (isset($personBlock->tablePerson) && is_array($personBlock->tablePerson) && isset($personBlock->tablePerson[0]['person']) && $personBlock->tablePerson[0]['person']) {
                            foreach ($personBlock->tablePerson as $person) {
                                if (isset($person['person']) && $person['person']) {
                                    $personArray = [];
                                    $personArray['name'] = $person['person'];
                                    if (isset($person['personHref']) && $person['personHref']) {
                                        $personArray['personHref'] = $person['personHref'];
                                    }
                                    if (isset($person['personImg']) && $person['personImg']) {
                                        $personArray['personImg'] = $person['personImg'];
                                    }
                                    $personsArray[] = $personArray;
                                }
                            }
                        }
                        if (isset($personBlock->textPerson) && !is_object($personBlock->textPerson)) {
                            $personArray = [];
                            $personArray['name'] = $personBlock->textPerson;
                            $personsArray[] = $personArray;
                        }
                        foreach ($personsArray as $personArray) {
                            if ($personArray['name']) {
                                $xmlPodcastPerson = $xml->createElement("podcast:person", htmlspecialchars($personArray['name'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                if (isset($personArray['personImg'])) {
                                    $xmlPodcastPerson->setAttribute("img", htmlspecialchars($personArray['personImg'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($personArray['personHref'])) {
                                    $xmlPodcastPerson->setAttribute("href", htmlspecialchars($personArray['personHref'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($personBlock->personRole) && $personBlock->personRole) {
                                    if (is_object($personBlock->personRole) && get_class($personBlock->personRole) == SingleOptionFieldData::class && $personBlock->personRole->value) {
                                        $xmlPodcastPerson->setAttribute("role", htmlspecialchars($personBlock->personRole->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (!is_object($personBlock->personRole)) {
                                        $xmlPodcastPerson->setAttribute("role", htmlspecialchars($personBlock->personRole, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }
                                if (isset($personBlock->personGroup) && $personBlock->personGroup) {
                                    if (is_object($personBlock->personGroup) && get_class($personBlock->personGroup) == SingleOptionFieldData::class && $personBlock->personGroup->value) {
                                        $xmlPodcastPerson->setAttribute("group", htmlspecialchars($personBlock->personGroup->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (!is_object($personBlock->personGroup)) {
                                        $xmlPodcastPerson->setAttribute("group", htmlspecialchars($personBlock->personGroup, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }

                                // if Role and Group is defined via entry
                                if (isset($personBlock->podcastTaxonomy) && $personBlock->podcastTaxonomy) {
                                    if (is_object($personBlock->podcastTaxonomy) && get_class($personBlock->podcastTaxonomy) == EntryQuery::class) {
                                        $podcastTaxonomy = $personBlock->podcastTaxonomy->one();
                                        if ($podcastTaxonomy) {
                                            if (isset($podcastTaxonomy->personRole) && $podcastTaxonomy->personRole) {
                                                if (is_object($podcastTaxonomy->personRole) && get_class($podcastTaxonomy->personRole) == SingleOptionFieldData::class && $podcastTaxonomy->personRole->value) {
                                                    $xmlPodcastPerson->setAttribute("role", htmlspecialchars($podcastTaxonomy->personRole->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                } elseif (!is_object($podcastTaxonomy->personRole)) {
                                                    $xmlPodcastPerson->setAttribute("role", htmlspecialchars($podcastTaxonomy->personRole, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                            }
                                            if (isset($podcastTaxonomy->personGroup) && $podcastTaxonomy->personGroup) {
                                                if (is_object($podcastTaxonomy->personGroup) && get_class($podcastTaxonomy->personGroup) == SingleOptionFieldData::class && $podcastTaxonomy->personGroup->value) {
                                                    $xmlPodcastPerson->setAttribute("group", htmlspecialchars($podcastTaxonomy->personGroup->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                } elseif (!is_object($podcastTaxonomy->personGroup)) {
                                                    $xmlPodcastPerson->setAttribute("group", htmlspecialchars($podcastTaxonomy->personGroup, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                            }
                                            $level1 = $personBlock->podcastTaxonomy->level(1)->one();
                                            $level2 = $personBlock->podcastTaxonomy->level(2)->one();
                                            if (isset($level1) && isset($level2)) {
                                                $xmlPodcastPerson->setAttribute("group", htmlspecialchars($level1->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                $xmlPodcastPerson->setAttribute("role", htmlspecialchars($level2->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                        }
                                    }
                                }
                                $xmlChannel->appendChild($xmlPodcastPerson);
                            }
                        }
                    }
                }
            }

            // Podcast Live Item
            list($liveItemField, $liveItemBlockTypeHandle) = GeneralHelper::getFieldDefinition('liveItem');
            if ($liveItemField) {
                if (get_class($liveItemField) == Matrix::class) {
                    $blockQuery = \craft\elements\MatrixBlock::find();
                    $liveItemBlocks = $blockQuery->fieldId($liveItemField->id)->owner($podcast)->type($liveItemBlockTypeHandle)->all();
                    foreach ($liveItemBlocks as $liveItemBlock) {
                        if (isset($liveItemBlock->liveStart) && $liveItemBlock->liveStart && isset($liveItemBlock->liveStatus) && $liveItemBlock->liveStatus) {
                            $xmlPodcastLiveItem = $xml->createElement("podcast:liveItem");
                            $xmlPodcastLiveItem->setAttribute("status", $liveItemBlock->liveStatus);
                            $xmlPodcastLiveItem->setAttribute("start", $liveItemBlock->liveStart->format('Y-m-d\TH:i:s.vP'));
                            if (isset($liveItemBlock->liveEnd) && $liveItemBlock->liveEnd) {
                                $xmlPodcastLiveItem->setAttribute("end", $liveItemBlock->liveEnd->format('Y-m-d\TH:i:s.vP'));
                            }
                            if (isset($liveItemBlock->liveTitle) && $liveItemBlock->liveTitle) {
                                $xmlLiveItemTitle = $xml->createElement("title", htmlspecialchars($liveItemBlock->liveTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemTitle);
                            }
                            if (isset($liveItemBlock->liveAuthor) && $liveItemBlock->liveAuthor) {
                                $xmlLiveItemAuthor = $xml->createElement("author", htmlspecialchars($liveItemBlock->liveAuthor, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemAuthor);
                            }
                            if (isset($liveItemBlock->description) && $liveItemBlock->description) {
                                $xmlLiveItemDescription = $xml->createElement("description");
                                $xmlLiveItemDescription->appendChild($xml->createCDATASection($liveItemBlock->description));
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemDescription);
                            }
                            if (isset($liveItemBlock->guid) && $liveItemBlock->guid) {
                                $xmlLiveItemGuid = $xml->createElement("guid", htmlspecialchars($liveItemBlock->guid, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemGuid);
                            }
                            if (isset($liveItemBlock->explicit) && $liveItemBlock->explicit) {
                                $xmlLiveItemExplicit = $xml->createElement("itunes:explicit", "yes");
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemExplicit);
                            }
                            if (isset($liveItemBlock->contentLink) && is_array($liveItemBlock->contentLink)) {
                                foreach ($liveItemBlock->contentLink as $row) {
                                    if (isset($row['title']) && $row['title'] && isset($row['href']) && $row['href']) {
                                        $xmlLiveItemContentLink = $xml->createElement("contentLink", htmlspecialchars($row['title'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlLiveItemContentLink->setAttribute("href", htmlspecialchars($row['href'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlPodcastLiveItem->appendChild($xmlLiveItemContentLink);
                                    }
                                }
                            }
                            if (isset($liveItemBlock->liveEnclosure) && is_array($liveItemBlock->liveEnclosure)) {
                                foreach ($liveItemBlock->liveEnclosure as $row) {
                                    if (isset($row['url']) && $row['url'] && isset($row['type']) && $row['type']) {
                                        $xmlLiveItemEnclosure = $xml->createElement("enclosure");
                                        $prefixUrl = GeneralHelper::prefixUrl($row['url'], $podcast, $site->id);
                                        $xmlLiveItemEnclosure->setAttribute("url", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlLiveItemEnclosure->setAttribute("type", htmlspecialchars($row['type'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlPodcastLiveItem->appendChild($xmlLiveItemEnclosure);
                                        break;
                                    }
                                }
                            }
                            if (isset($liveItemBlock->liveAlternateEnclosure) && is_array($liveItemBlock->liveAlternateEnclosure)) {
                                foreach ($liveItemBlock->liveAlternateEnclosure as $row) {
                                    if (isset($row['uri']) && $row['uri'] && isset($row['type']) && $row['type']) {
                                        $xmlLiveItemAlternateEnclosure = $xml->createElement("podcast:alternateEnclosure");
                                        if (isset($row['enclosureTitle']) && $row['enclosureTitle']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("title", htmlspecialchars($row['enclosureTitle'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureBitrate']) && $row['enclosureBitrate']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("bitrate", htmlspecialchars($row['enclosureBitrate'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureHeight']) && $row['enclosureHeight']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("height", htmlspecialchars($row['enclosureHeight'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureLang']) && $row['enclosureLang']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("lang", htmlspecialchars($row['enclosureLang'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureCodecs']) && $row['enclosureCodecs']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("codecs", htmlspecialchars($row['enclosureCodecs'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureRel']) && $row['enclosureRel']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("rel", htmlspecialchars($row['enclosureRel'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureDefault']) && $row['enclosureDefault']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("default", "true");
                                        }
                                        $xmlLiveItemAlternateEnclosureSource = $xml->createElement("source");
                                        $prefixUrl = GeneralHelper::prefixUrl($row['uri'], $podcast, $site->id);
                                        $xmlLiveItemAlternateEnclosureSource->setAttribute("uri", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlLiveItemAlternateEnclosureSource->setAttribute("type", htmlspecialchars($row['type'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlLiveItemAlternateEnclosure->appendChild($xmlLiveItemAlternateEnclosureSource);
                                        $xmlPodcastLiveItem->appendChild($xmlLiveItemAlternateEnclosure);
                                    }
                                }
                            } elseif (isset($liveItemBlock->liveAlternateEnclosure) && is_object($liveItemBlock->liveAlternateEnclosure) && get_class($liveItemBlock->liveAlternateEnclosure) == SuperTableBlockQuery::class) {
                                foreach ($liveItemBlock->liveAlternateEnclosure->all() as $block) {
                                    $type = false;
                                    $xmlLiveItemAlternateEnclosure = $xml->createElement("podcast:alternateEnclosure");
                                    if (isset($block->enclosureType) && $block->enclosureType) {
                                        if (is_object($block->enclosureType) && get_class($block->enclosureType) == SingleOptionFieldData::class && $block->enclosureType->value) {
                                            $type = true;
                                            $xmlLiveItemAlternateEnclosure->setAttribute("type",  htmlspecialchars($block->enclosureType->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureType)) {
                                            $type = true;
                                            $xmlLiveItemAlternateEnclosure->setAttribute("type",  htmlspecialchars($block->enclosureType, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (!$type) {
                                        continue;
                                    }
                                    if (isset($block->enclosureTitle) && $block->enclosureTitle) {
                                        $xmlLiveItemAlternateEnclosure->setAttribute("title", htmlspecialchars($block->enclosureTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    if (isset($block->enclosureBitrate) && $block->enclosureBitrate) {
                                        if (is_object($block->enclosureBitrate) && get_class($block->enclosureBitrate) == SingleOptionFieldData::class && $block->enclosureBitrate->value) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("bitrate", htmlspecialchars($block->enclosureBitrate->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureBitrate)) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("bitrate", htmlspecialchars($block->enclosureBitrate, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($block->enclosureHeight) && $block->enclosureHeight) {
                                        $xmlLiveItemAlternateEnclosure->setAttribute("height", $block->enclosureHeight);
                                    }
                                    if (isset($block->enclosureLang) && $block->enclosureLang) {
                                        if (is_object($block->enclosureLang) && get_class($block->enclosureLang) == SingleOptionFieldData::class && $block->enclosureLang->value) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("lang", htmlspecialchars($block->enclosureLang->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureLang)) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("lang", htmlspecialchars($block->enclosureLang, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($block->enclosureCodecs) && $block->enclosureCodecs) {
                                        if (is_object($block->enclosureCodecs) && get_class($block->enclosureCodecs) == SingleOptionFieldData::class && $block->enclosureCodecs->value) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("codecs", htmlspecialchars($block->enclosureCodecs->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureCodecs)) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("codecs", htmlspecialchars($block->enclosureCodecs, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($block->enclosureRel) && $block->enclosureRel) {
                                        if (is_object($block->enclosureRel) && get_class($block->enclosureRel) == SingleOptionFieldData::class && $block->enclosureRel->value) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("rel", htmlspecialchars($block->enclosureRel->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureRel)) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("rel", htmlspecialchars($block->enclosureRel, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($block->enclosureDefault) && $block->enclosureDefault) {
                                        $xmlLiveItemAlternateEnclosure->setAttribute("default", "true");
                                    }
                                    if (isset($block->otherSources) && is_array($block->otherSources)) {
                                        foreach ($block->otherSources as $key => $row) {
                                            if (isset($row['uri']) && $row['uri']) {
                                                $xmlLiveItemAlternateEnclosureSource = $xml->createElement("source");
                                                $prefixUrl = GeneralHelper::prefixUrl($row['uri'], $podcast, $site->id);
                                                $xmlLiveItemAlternateEnclosureSource->setAttribute("uri", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                if (isset($row['contentType']) && $row['contentType']) {
                                                    $xmlLiveItemAlternateEnclosureSource->setAttribute("type", htmlspecialchars($row['contentType'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                $xmlLiveItemAlternateEnclosure->appendChild($xmlLiveItemAlternateEnclosureSource);
                                            }
                                        }
                                    }
                                    $xmlPodcastLiveItem->appendChild($xmlLiveItemAlternateEnclosure);
                                }
                            }
                            if (isset($liveItemBlock->person) && $liveItemBlock->person) {
                                if (!is_object($liveItemBlock->person)) {
                                    if (is_array($liveItemBlock->person)) {
                                        foreach ($liveItemBlock->person as $row) {
                                            if (isset($row['person']) && $row['person']) {
                                                $xmlLiveItemPerson = $xml->createElement("podcast:person", htmlspecialchars($row['person'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                if (isset($row['personRole']) && $row['personRole']) {
                                                    $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($row['personRole'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                if (isset($row['personGroup']) && $row['personGroup']) {
                                                    $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($row['personGroup'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                if (isset($row['personImg']) && $row['personImg']) {
                                                    $xmlLiveItemPerson->setAttribute("img", htmlspecialchars($row['personImg'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                if (isset($row['personHref']) && $row['personHref']) {
                                                    $xmlLiveItemPerson->setAttribute("href", htmlspecialchars($row['personHref'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                $xmlPodcastLiveItem->appendChild($xmlLiveItemPerson);
                                            }
                                        }
                                    } else {
                                        $xmlLiveItemPerson = $xml->createElement("podcast:person", htmlspecialchars($liveItemBlock->person, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlPodcastLiveItem->appendChild($xmlLiveItemPerson);
                                    }
                                } elseif (get_class($liveItemBlock->person) == SuperTableBlockQuery::class) {
                                    foreach ($liveItemBlock->person->all() as $personBlock) {
                                        $personsArray = [];
                                        if (isset($personBlock->userPerson) && get_class($personBlock->userPerson) == UserQuery::class && $personBlock->userPerson->one()) {
                                            $persons = $personBlock->userPerson->all();
                                            foreach ($persons as $person) {
                                                if ($person->fullName) {
                                                    $personArray = [];
                                                    $personArray['name'] = $person->fullName;
                                                    if (isset($person->personHref) && $person->personHref) {
                                                        $personArray['personHref'] = $person->personHref;
                                                    }
                                                    if ($person->photoId) {
                                                        $photo = Craft::$app->getAssets()->getAssetById($person->photoId);
                                                        if ($photo) {
                                                            $personArray['personImg'] = $photo->getUrl();
                                                        }
                                                    } elseif (isset($person->personImg) && $person->personImg) {
                                                        if (!is_object($person->personImg)) {
                                                            $personArray['personImg'] = $person->personImg;
                                                        } else {
                                                            if (get_class($person->personImg) == AssetQuery::class) {
                                                                $personImg = $person->personImg->one();
                                                                if ($personImg) {
                                                                    $personArray['personImg'] = $personImg->getUrl();
                                                                } else {
                                                                    $personArray['personImg'] = null;
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $personsArray[] = $personArray;
                                                }
                                            }
                                        }
                                        if (isset($personBlock->entryPerson) && get_class($personBlock->entryPerson) == EntryQuery::class && $personBlock->entryPerson->one()) {
                                            $persons = $personBlock->entryPerson->all();
                                            foreach ($persons as $person) {
                                                if (isset($person->title) && $person->title) {
                                                    $personArray = [];
                                                    $personArray['name'] = $person->title;
                                                    if (isset($person->personHref) && $person->personHref) {
                                                        $personArray['personHref'] = $person->personHref;
                                                    }
                                                    if (isset($person->personImg) && $person->personImg) {
                                                        if (!is_object($person->personImg)) {
                                                            $personArray['personImg'] = $person->personImg;
                                                        } else {
                                                            if (get_class($person->personImg) == AssetQuery::class) {
                                                                $personImg = $person->personImg->one();
                                                                if ($personImg) {
                                                                    $personArray['personImg'] = $personImg->getUrl();
                                                                } else {
                                                                    $personArray['personImg'] = null;
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $personsArray[] = $personArray;
                                                }
                                            }
                                        }
                                        if (isset($personBlock->tablePerson) && is_array($personBlock->tablePerson) && isset($personBlock->tablePerson[0]['person']) && $personBlock->tablePerson[0]['person']) {
                                            foreach ($personBlock->tablePerson as $person) {
                                                if (isset($person['person']) && $person['person']) {
                                                    $personArray = [];
                                                    $personArray['name'] = $person['person'];
                                                    if (isset($person['personHref']) && $person['personHref']) {
                                                        $personArray['personHref'] = $person['personHref'];
                                                    }
                                                    if (isset($person['personImg']) && $person['personImg']) {
                                                        $personArray['personImg'] = $person['personImg'];
                                                    }
                                                    $personsArray[] = $personArray;
                                                }
                                            }
                                        }
                                        if (isset($personBlock->textPerson) && !is_object($personBlock->textPerson)) {
                                            $personArray = [];
                                            $personArray['name'] = $personBlock->textPerson;
                                            $personsArray[] = $personArray;
                                        }
                                        foreach ($personsArray as $personArray) {
                                            if ($personArray['name']) {
                                                $xmlLiveItemPerson = $xml->createElement("podcast:person", htmlspecialchars($personArray['name'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                if (isset($personArray['personImg'])) {
                                                    $xmlLiveItemPerson->setAttribute("img", htmlspecialchars($personArray['personImg'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                if (isset($personArray['personHref'])) {
                                                    $xmlLiveItemPerson->setAttribute("href", htmlspecialchars($personArray['personHref'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                if (isset($personBlock->personRole) && $personBlock->personRole) {
                                                    if (is_object($personBlock->personRole) && get_class($personBlock->personRole) == SingleOptionFieldData::class && $personBlock->personRole->value) {
                                                        $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($personBlock->personRole->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    } elseif (!is_object($personBlock->personRole)) {
                                                        $$xmlLiveItemPerson->setAttribute("role", htmlspecialchars($personBlock->personRole, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    }
                                                }
                                                if (isset($personBlock->personGroup) && $personBlock->personGroup) {
                                                    if (is_object($personBlock->personGroup) && get_class($personBlock->personGroup) == SingleOptionFieldData::class && $personBlock->personGroup->value) {
                                                        $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($personBlock->personGroup->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    } elseif (!is_object($personBlock->personGroup)) {
                                                        $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($personBlock->personGroup, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    }
                                                }

                                                // if Role and Group is defined via entry
                                                if (isset($personBlock->podcastTaxonomy) && $personBlock->podcastTaxonomy) {
                                                    if (is_object($personBlock->podcastTaxonomy) && get_class($personBlock->podcastTaxonomy) == EntryQuery::class) {
                                                        $podcastTaxonomy = $personBlock->podcastTaxonomy->one();
                                                        if ($podcastTaxonomy) {
                                                            if (isset($podcastTaxonomy->personRole) && $podcastTaxonomy->personRole) {
                                                                if (is_object($podcastTaxonomy->personRole) && get_class($podcastTaxonomy->personRole) == SingleOptionFieldData::class && $podcastTaxonomy->personRole->value) {
                                                                    $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($podcastTaxonomy->personRole->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                } elseif (!is_object($podcastTaxonomy->personRole)) {
                                                                    $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($podcastTaxonomy->personRole, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                }
                                                            }
                                                            if (isset($podcastTaxonomy->personGroup) && $podcastTaxonomy->personGroup) {
                                                                if (is_object($podcastTaxonomy->personGroup) && get_class($podcastTaxonomy->personGroup) == SingleOptionFieldData::class && $podcastTaxonomy->personGroup->value) {
                                                                    $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($podcastTaxonomy->personGroup->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                } elseif (!is_object($podcastTaxonomy->personGroup)) {
                                                                    $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($podcastTaxonomy->personGroup, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                }
                                                            }
                                                            $level1 = $personBlock->podcastTaxonomy->level(1)->one();
                                                            $level2 = $personBlock->podcastTaxonomy->level(2)->one();
                                                            if (isset($level1) && isset($level2)) {
                                                                $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($level1->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($level2->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                            }
                                                        }
                                                    }
                                                }
                                                $xmlPodcastLiveItem->appendChild($xmlLiveItemPerson);
                                            }
                                        }
                                    }
                                }
                            }
                            $xmlChannel->appendChild($xmlPodcastLiveItem);
                        }
                    }
                } elseif (get_class($liveItemField) == Entries::class) {
                    $liveItems = $podcast->{$liveItemField->handle}->all();
                    foreach ($liveItems as $liveItemBlock) {
                        if (isset($liveItemBlock->liveStart) && $liveItemBlock->liveStart && isset($liveItemBlock->liveStatus) && $liveItemBlock->liveStatus) {
                            $xmlPodcastLiveItem = $xml->createElement("podcast:liveItem");
                            $xmlPodcastLiveItem->setAttribute("status", $liveItemBlock->liveStatus);
                            $xmlPodcastLiveItem->setAttribute("start", $liveItemBlock->liveStart->format('Y-m-d\TH:i:s.vP'));
                            if (isset($liveItemBlock->liveEnd) && $liveItemBlock->liveEnd) {
                                $xmlPodcastLiveItem->setAttribute("end", $liveItemBlock->liveEnd->format('Y-m-d\TH:i:s.vP'));
                            }
                            if (isset($liveItemBlock->title) && $liveItemBlock->title) {
                                $xmlLiveItemTitle = $xml->createElement("title", htmlspecialchars($liveItemBlock->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemTitle);
                            }
                            if (isset($liveItemBlock->liveAuthor) && $liveItemBlock->liveAuthor) {
                                $xmlLiveItemAuthor = $xml->createElement("author", htmlspecialchars($liveItemBlock->liveAuthor, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemAuthor);
                            }
                            if (isset($liveItemBlock->description) && $liveItemBlock->description) {
                                $xmlLiveItemDescription = $xml->createElement("description");
                                $xmlLiveItemDescription->appendChild($xml->createCDATASection($liveItemBlock->description));
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemDescription);
                            }
                            if (isset($liveItemBlock->guid) && $liveItemBlock->guid) {
                                $xmlLiveItemGuid = $xml->createElement("guid", htmlspecialchars($liveItemBlock->guid, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemGuid);
                            }
                            if (isset($liveItemBlock->explicit) && $liveItemBlock->explicit) {
                                $xmlLiveItemExplicit = $xml->createElement("itunes:explicit", "yes");
                                $xmlPodcastLiveItem->appendChild($xmlLiveItemExplicit);
                            }
                            if (isset($liveItemBlock->contentLink) && is_array($liveItemBlock->contentLink)) {
                                foreach ($liveItemBlock->contentLink as $row) {
                                    if (isset($row['title']) && $row['title'] && isset($row['href']) && $row['href']) {
                                        $xmlLiveItemContentLink = $xml->createElement("contentLink", htmlspecialchars($row['title'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlLiveItemContentLink->setAttribute("href", htmlspecialchars($row['href'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlPodcastLiveItem->appendChild($xmlLiveItemContentLink);
                                    }
                                }
                            }
                            if (isset($liveItemBlock->liveEnclosure) && is_array($liveItemBlock->liveEnclosure)) {
                                foreach ($liveItemBlock->liveEnclosure as $row) {
                                    if (isset($row['url']) && $row['url'] && isset($row['type']) && $row['type']) {
                                        $xmlLiveItemEnclosure = $xml->createElement("enclosure");
                                        $prefixUrl = GeneralHelper::prefixUrl($row['url'], $podcast, $site->id);
                                        $xmlLiveItemEnclosure->setAttribute("url", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlLiveItemEnclosure->setAttribute("type", htmlspecialchars($row['type'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlPodcastLiveItem->appendChild($xmlLiveItemEnclosure);
                                        break;
                                    }
                                }
                            }
                            if (isset($liveItemBlock->liveAlternateEnclosure) && is_array($liveItemBlock->liveAlternateEnclosure)) {
                                foreach ($liveItemBlock->liveAlternateEnclosure as $row) {
                                    if (isset($row['uri']) && $row['uri'] && isset($row['type']) && $row['type']) {
                                        $xmlLiveItemAlternateEnclosure = $xml->createElement("podcast:alternateEnclosure");
                                        if (isset($row['enclosureTitle']) && $row['enclosureTitle']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("title", htmlspecialchars($row['enclosureTitle'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureBitrate']) && $row['enclosureBitrate']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("bitrate", htmlspecialchars($row['enclosureBitrate'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureHeight']) && $row['enclosureHeight']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("height", htmlspecialchars($row['enclosureHeight'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureLang']) && $row['enclosureLang']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("lang", htmlspecialchars($row['enclosureLang'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureCodecs']) && $row['enclosureCodecs']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("codecs", htmlspecialchars($row['enclosureCodecs'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureRel']) && $row['enclosureRel']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("rel", htmlspecialchars($row['enclosureRel'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($row['enclosureDefault']) && $row['enclosureDefault']) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("default", "true");
                                        }
                                        $xmlLiveItemAlternateEnclosureSource = $xml->createElement("source");
                                        $prefixUrl = GeneralHelper::prefixUrl($row['uri'], $podcast, $site->id);
                                        $xmlLiveItemAlternateEnclosureSource->setAttribute("uri", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlLiveItemAlternateEnclosureSource->setAttribute("type", htmlspecialchars($row['type'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlLiveItemAlternateEnclosure->appendChild($xmlLiveItemAlternateEnclosureSource);
                                        $xmlPodcastLiveItem->appendChild($xmlLiveItemAlternateEnclosure);
                                    }
                                }
                            } elseif (isset($liveItemBlock->liveAlternateEnclosure) && is_object($liveItemBlock->liveAlternateEnclosure) && (get_class($liveItemBlock->liveAlternateEnclosure) == SuperTableBlockQuery::class || get_class($liveItemBlock->liveAlternateEnclosure) == MatrixBlockQuery::class)) {
                                foreach ($liveItemBlock->liveAlternateEnclosure->all() as $block) {
                                    $type = false;
                                    $xmlLiveItemAlternateEnclosure = $xml->createElement("podcast:alternateEnclosure");
                                    if (isset($block->enclosureType) && $block->enclosureType) {
                                        if (is_object($block->enclosureType) && get_class($block->enclosureType) == SingleOptionFieldData::class && $block->enclosureType->value) {
                                            $type = true;
                                            $xmlLiveItemAlternateEnclosure->setAttribute("type",  htmlspecialchars($block->enclosureType->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureType)) {
                                            $type = true;
                                            $xmlLiveItemAlternateEnclosure->setAttribute("type",  htmlspecialchars($block->enclosureType, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (!$type) {
                                        continue;
                                    }
                                    if (isset($block->enclosureTitle) && $block->enclosureTitle) {
                                        $xmlLiveItemAlternateEnclosure->setAttribute("title", htmlspecialchars($block->enclosureTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    if (isset($block->enclosureBitrate) && $block->enclosureBitrate) {
                                        if (is_object($block->enclosureBitrate) && get_class($block->enclosureBitrate) == SingleOptionFieldData::class && $block->enclosureBitrate->value) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("bitrate", htmlspecialchars($block->enclosureBitrate->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureBitrate)) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("bitrate", htmlspecialchars($block->enclosureBitrate, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($block->enclosureHeight) && $block->enclosureHeight) {
                                        $xmlLiveItemAlternateEnclosure->setAttribute("height", $block->enclosureHeight);
                                    }
                                    if (isset($block->enclosureLang) && $block->enclosureLang) {
                                        if (is_object($block->enclosureLang) && get_class($block->enclosureLang) == SingleOptionFieldData::class && $block->enclosureLang->value) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("lang", htmlspecialchars($block->enclosureLang->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureLang)) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("lang", htmlspecialchars($block->enclosureLang, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($block->enclosureCodecs) && $block->enclosureCodecs) {
                                        if (is_object($block->enclosureCodecs) && get_class($block->enclosureCodecs) == SingleOptionFieldData::class && $block->enclosureCodecs->value) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("codecs", htmlspecialchars($block->enclosureCodecs->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureCodecs)) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("codecs", htmlspecialchars($block->enclosureCodecs, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($block->enclosureRel) && $block->enclosureRel) {
                                        if (is_object($block->enclosureRel) && get_class($block->enclosureRel) == SingleOptionFieldData::class && $block->enclosureRel->value) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("rel", htmlspecialchars($block->enclosureRel->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($block->enclosureRel)) {
                                            $xmlLiveItemAlternateEnclosure->setAttribute("rel", htmlspecialchars($block->enclosureRel, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($block->enclosureDefault) && $block->enclosureDefault) {
                                        $xmlLiveItemAlternateEnclosure->setAttribute("default", "true");
                                    }
                                    if (isset($block->otherSources) && is_array($block->otherSources)) {
                                        foreach ($block->otherSources as $key => $row) {
                                            if (isset($row['uri']) && $row['uri']) {
                                                $xmlLiveItemAlternateEnclosureSource = $xml->createElement("source");
                                                $prefixUrl = GeneralHelper::prefixUrl($row['uri'], $podcast, $site->id);
                                                $xmlLiveItemAlternateEnclosureSource->setAttribute("uri", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                if (isset($row['contentType']) && $row['contentType']) {
                                                    $xmlLiveItemAlternateEnclosureSource->setAttribute("type", htmlspecialchars($row['contentType'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                $xmlLiveItemAlternateEnclosure->appendChild($xmlLiveItemAlternateEnclosureSource);
                                            }
                                        }
                                    }
                                    $xmlPodcastLiveItem->appendChild($xmlLiveItemAlternateEnclosure);
                                }
                            }

                            if (isset($liveItemBlock->person)) {
                                if (!is_object($liveItemBlock->person) && !is_array($liveItemBlock->person)) {
                                    $xmlLiveItemPerson = $xml->createElement("podcast:person", htmlspecialchars($liveItemBlock->person, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    $xmlChannel->appendChild($xmlLiveItemPerson);
                                } elseif (is_array($liveItemBlock->person)) {
                                    foreach ($liveItemBlock->person as $row) {
                                        if (isset($row['person']) && $row['person']) {
                                            $xmlLiveItemPerson = $xml->createElement("podcast:person", htmlspecialchars($row['person'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            if (isset($row['personRole']) && $row['personRole']) {
                                                $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($row['personRole'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            if (isset($row['personGroup']) && $row['personGroup']) {
                                                $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($row['personGroup'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            if (isset($row['personImg']) && $row['personImg']) {
                                                $xmlLiveItemPerson->setAttribute("img", htmlspecialchars($row['personImg'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            if (isset($row['personHref']) && $row['personHref']) {
                                                $xmlLiveItemPerson->setAttribute("href", htmlspecialchars($row['personHref'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            $xmlChannel->appendChild($xmlLiveItemPerson);
                                        }
                                    }
                                } elseif (get_class($liveItemBlock->person) == MatrixBlockQuery::class || get_class($liveItemBlock->person) == SuperTableBlockQuery::class) {
                                    foreach ($liveItemBlock->person->all() as $personBlock) {
                                        $personsArray = [];
                                        if (isset($personBlock->userPerson) && get_class($personBlock->userPerson) == UserQuery::class && $personBlock->userPerson->one()) {
                                            $persons = $personBlock->userPerson->all();
                                            foreach ($persons as $person) {
                                                if ($person->fullName) {
                                                    $personArray = [];
                                                    $personArray['name'] = $person->fullName;
                                                    if (isset($person->personHref) && $person->personHref) {
                                                        $personArray['personHref'] = $person->personHref;
                                                    }
                                                    if ($person->photoId) {
                                                        $photo = Craft::$app->getAssets()->getAssetById($person->photoId);
                                                        if ($photo) {
                                                            $personArray['personImg'] = $photo->getUrl();
                                                        }
                                                    } elseif (isset($person->personImg) && $person->personImg) {
                                                        if (!is_object($person->personImg)) {
                                                            $personArray['personImg'] = $person->personImg;
                                                        } else {
                                                            if (get_class($person->personImg) == AssetQuery::class) {
                                                                $personImg = $person->personImg->one();
                                                                if ($personImg) {
                                                                    $personArray['personImg'] = $personImg->getUrl();
                                                                } else {
                                                                    $personArray['personImg'] = null;
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $personsArray[] = $personArray;
                                                }
                                            }
                                        }
                                        if (isset($personBlock->entryPerson) && get_class($personBlock->entryPerson) == EntryQuery::class && $personBlock->entryPerson->one()) {
                                            $persons = $personBlock->entryPerson->all();
                                            foreach ($persons as $person) {
                                                if (isset($person->title) && $person->title) {
                                                    $personArray = [];
                                                    $personArray['name'] = $person->title;
                                                    if (isset($person->personHref) && $person->personHref) {
                                                        $personArray['personHref'] = $person->personHref;
                                                    }
                                                    if (isset($person->personImg) && $person->personImg) {
                                                        if (!is_object($person->personImg)) {
                                                            $personArray['personImg'] = $person->personImg;
                                                        } else {
                                                            if (get_class($person->personImg) == AssetQuery::class) {
                                                                $personImg = $person->personImg->one();
                                                                if ($personImg) {
                                                                    $personArray['personImg'] = $personImg->getUrl();
                                                                } else {
                                                                    $personArray['personImg'] = null;
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $personsArray[] = $personArray;
                                                }
                                            }
                                        }
                                        if (isset($personBlock->tablePerson) && is_array($personBlock->tablePerson) && isset($personBlock->tablePerson[0]['person']) && $personBlock->tablePerson[0]['person']) {
                                            foreach ($personBlock->tablePerson as $person) {
                                                if (isset($person['person']) && $person['person']) {
                                                    $personArray = [];
                                                    $personArray['name'] = $person['person'];
                                                    if (isset($person['personHref']) && $person['personHref']) {
                                                        $personArray['personHref'] = $person['personHref'];
                                                    }
                                                    if (isset($person['personImg']) && $person['personImg']) {
                                                        $personArray['personImg'] = $person['personImg'];
                                                    }
                                                    $personsArray[] = $personArray;
                                                }
                                            }
                                        }
                                        if (isset($personBlock->textPerson) && !is_object($personBlock->textPerson)) {
                                            $personArray = [];
                                            $personArray['name'] = $personBlock->textPerson;
                                            $personsArray[] = $personArray;
                                        }
                                        foreach ($personsArray as $personArray) {
                                            if ($personArray['name']) {
                                                $xmlLiveItemPerson = $xml->createElement("podcast:person", htmlspecialchars($personArray['name'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                if (isset($personArray['personImg'])) {
                                                    $xmlLiveItemPerson->setAttribute("img", htmlspecialchars($personArray['personImg'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                if (isset($personArray['personHref'])) {
                                                    $xmlLiveItemPerson->setAttribute("href", htmlspecialchars($personArray['personHref'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                                if (isset($personBlock->personRole) && $personBlock->personRole) {
                                                    if (is_object($personBlock->personRole) && get_class($personBlock->personRole) == SingleOptionFieldData::class && $personBlock->personRole->value) {
                                                        $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($personBlock->personRole->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    } elseif (!is_object($personBlock->personRole)) {
                                                        $$xmlLiveItemPerson->setAttribute("role", htmlspecialchars($personBlock->personRole, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    }
                                                }
                                                if (isset($personBlock->personGroup) && $personBlock->personGroup) {
                                                    if (is_object($personBlock->personGroup) && get_class($personBlock->personGroup) == SingleOptionFieldData::class && $personBlock->personGroup->value) {
                                                        $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($personBlock->personGroup->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    } elseif (!is_object($personBlock->personGroup)) {
                                                        $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($personBlock->personGroup, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    }
                                                }

                                                // if Role and Group is defined via entry
                                                if (isset($personBlock->podcastTaxonomy) && $personBlock->podcastTaxonomy) {
                                                    if (is_object($personBlock->podcastTaxonomy) && get_class($personBlock->podcastTaxonomy) == EntryQuery::class) {
                                                        $podcastTaxonomy = $personBlock->podcastTaxonomy->one();
                                                        if ($podcastTaxonomy) {
                                                            if (isset($podcastTaxonomy->personRole) && $podcastTaxonomy->personRole) {
                                                                if (is_object($podcastTaxonomy->personRole) && get_class($podcastTaxonomy->personRole) == SingleOptionFieldData::class && $podcastTaxonomy->personRole->value) {
                                                                    $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($podcastTaxonomy->personRole->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                } elseif (!is_object($podcastTaxonomy->personRole)) {
                                                                    $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($podcastTaxonomy->personRole, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                }
                                                            }
                                                            if (isset($podcastTaxonomy->personGroup) && $podcastTaxonomy->personGroup) {
                                                                if (is_object($podcastTaxonomy->personGroup) && get_class($podcastTaxonomy->personGroup) == SingleOptionFieldData::class && $podcastTaxonomy->personGroup->value) {
                                                                    $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($podcastTaxonomy->personGroup->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                } elseif (!is_object($podcastTaxonomy->personGroup)) {
                                                                    $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($podcastTaxonomy->personGroup, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                }
                                                            }
                                                            $level1 = $personBlock->podcastTaxonomy->level(1)->one();
                                                            $level2 = $personBlock->podcastTaxonomy->level(2)->one();
                                                            if (isset($level1) && isset($level2)) {
                                                                $xmlLiveItemPerson->setAttribute("group", htmlspecialchars($level1->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                                $xmlLiveItemPerson->setAttribute("role", htmlspecialchars($level2->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                            }
                                                        }
                                                    }
                                                }
                                                $xmlPodcastLiveItem->appendChild($xmlLiveItemPerson);
                                            }
                                        }
                                    }
                                }
                            }
                            $xmlChannel->appendChild($xmlPodcastLiveItem);
                        }
                    }
                }
            }

            // Podcast txt
            list($txtField) = GeneralHelper::getFieldDefinition('podcastTxt');
            if ($txtField) {
                $txtFieldHandle = $txtField->handle;
                if (isset($podcast->$txtFieldHandle) && $podcast->$txtFieldHandle) {
                    if (is_array($podcast->$txtFieldHandle)) {
                        foreach ($podcast->$txtFieldHandle as $row) {
                            if (isset($row['txt']) && $row['txt']) {
                                $xmlTxt = $xml->createElement("podcast:txt", htmlspecialchars($row['txt'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                if (isset($row['purpose']) && $row['purpose']) {
                                    $xmlTxt->setAttribute("purpose", htmlspecialchars($row['purpose'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                $xmlChannel->appendChild($xmlTxt);
                            }
                        }
                    } elseif (!is_object($podcast->$txtFieldHandle)) {
                        $xmlTxt = $xml->createElement("podcast:txt", htmlspecialchars($podcast->$txtFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlChannel->appendChild($xmlTxt);
                    }
                }
            }

            // Podcast value
            list($valueField, $valueBlockTypeHandle) = GeneralHelper::getFieldDefinition('podcastValue');
            if ($valueField) {
                $valueFieldHandle = $valueField->handle;
                if (isset($podcast->$valueFieldHandle) && get_class($podcast->$valueFieldHandle) == EntryQuery::class) {
                    $value4value = $podcast->$valueFieldHandle->one();
                    if (isset($value4value->valueType) && $value4value->valueType && isset($value4value->valueMethod) && $value4value->valueMethod) {
                        $xmlPodcastValue = $xml->createElement("podcast:value");
                        $xmlPodcastValue->setAttribute("type", htmlspecialchars($value4value->valueType, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlPodcastValue->setAttribute("method", htmlspecialchars($value4value->valueMethod, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        if (isset($value4value->valueSuggested) && $value4value->valueSuggested) {
                            $xmlPodcastValue->setAttribute("suggested", htmlspecialchars($value4value->valueSuggested, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        }
                        if (isset($value4value->recipient) && get_class($value4value->recipient) == MatrixBlockQuery::class) {
                            $valueBlocks = $value4value->recipient->all();
                            foreach ($valueBlocks as $valueBlock) {
                                $recipientsArray = [];
                                if (isset($valueBlock->userRecipient) && is_object($valueBlock->userRecipient) && get_class($valueBlock->userRecipient) == UserQuery::class && $valueBlock->userRecipient->one()) {
                                    $recipients = $valueBlock->userRecipient->all();
                                    foreach ($recipients as $recipient) {
                                        $recipientArray = [];
                                        if ($recipient->fullName) {
                                            $recipientArray['name'] = $recipient->fullName;
                                        }
                                        if (isset($recipient->recipientType) && $recipient->recipientType) {
                                            $recipientArray['recipientType'] = $recipient->recipientType;
                                        }
                                        if (isset($recipient->recipientCustomKey) && $recipient->recipientCustomKey) {
                                            $recipientArray['recipientCustomKey'] = $recipient->recipientCustomKey;
                                        }
                                        if (isset($recipient->recipientCustomValue) && $recipient->recipientCustomValue) {
                                            $recipientArray['recipientCustomValue'] = $recipient->recipientCustomValue;
                                        }
                                        if (isset($recipient->recipientAddress) && $recipient->recipientAddress) {
                                            $recipientArray['recipientAddress'] = $recipient->recipientAddress;
                                        }
                                        $recipientsArray[] = $recipientArray;
                                    }
                                }
                                if (isset($valueBlock->entryRecipient) && is_object($valueBlock->entryRecipient) && get_class($valueBlock->entryRecipient) == EntryQuery::class && $valueBlock->entryRecipient->one()) {
                                    $recipients = $valueBlock->entryRecipient->all();
                                    foreach ($recipients as $recipient) {
                                        $recipientArray = [];
                                        if (isset($recipient->title) && $recipient->title) {
                                            $recipientArray['name'] = $recipient->title;
                                        }
                                        if (isset($recipient->recipientType) && $recipient->recipientType) {
                                            $recipientArray['recipientType'] = $recipient->recipientType;
                                        }
                                        if (isset($recipient->recipientCustomKey) && $recipient->recipientCustomKey) {
                                            $recipientArray['recipientCustomKey'] = $recipient->recipientCustomKey;
                                        }
                                        if (isset($recipient->recipientCustomValue) && $recipient->recipientCustomValue) {
                                            $recipientArray['recipientCustomValue'] = $recipient->recipientCustomValue;
                                        }
                                        if (isset($recipient->recipientAddress) && $recipient->recipientAddress) {
                                            $recipientArray['recipientAddress'] = $recipient->recipientAddress;
                                        }
                                        $recipientsArray[] = $recipientArray;
                                    }
                                }
                                if (isset($valueBlock->otherRecipients) && is_array($valueBlock->otherRecipients)) {
                                    foreach ($valueBlock->otherRecipients as $recipient) {
                                        $recipientArray = [];
                                        if (isset($recipient['recipientName']) && $recipient['recipientName']) {
                                            $recipientArray['name'] = $recipient['recipientName'];
                                        }
                                        if (isset($recipient['recipientType']) && $recipient['recipientType']) {
                                            $recipientArray['recipientType'] = $recipient['recipientType'];
                                        }
                                        if (isset($recipient['recipientCustomKey']) && $recipient['recipientCustomKey']) {
                                            $recipientArray['recipientCustomKey'] = $recipient['recipientCustomKey'];
                                        }
                                        if (isset($recipient['recipientCustomValue']) && $recipient['recipientCustomValue']) {
                                            $recipientArray['recipientCustomValue'] = $recipient['recipientCustomValue'];
                                        }
                                        if (isset($recipient['recipientAddress']) && $recipient['recipientAddress']) {
                                            $recipientArray['recipientAddress'] = $recipient['recipientAddress'];
                                        }
                                        $recipientsArray[] = $recipientArray;
                                    }
                                }

                                foreach ($recipientsArray as $recipientArray) {
                                    if (isset($recipientArray['recipientType']) && isset($recipientArray['recipientAddress']) && isset($valueBlock->split) && $valueBlock->split) {
                                        $xmlPodcastRecipient = $xml->createElement("podcast:valueRecipient");
                                        $xmlPodcastRecipient->setAttribute("type", htmlspecialchars($recipientArray['recipientType'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        $xmlPodcastRecipient->setAttribute("address", htmlspecialchars($recipientArray['recipientAddress'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        if (isset($recipientArray['name'])) {
                                            $xmlPodcastRecipient->setAttribute("name", htmlspecialchars($recipientArray['name'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($recipientArray['recipientCustomKey'])) {
                                            $xmlPodcastRecipient->setAttribute("customKey", htmlspecialchars($recipientArray['recipientCustomKey'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        if (isset($recipientArray['recipientCustomValue'])) {
                                            $xmlPodcastRecipient->setAttribute("customValue", htmlspecialchars($recipientArray['recipientCustomValue'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        $xmlPodcastRecipient->setAttribute("split", htmlspecialchars($valueBlock->split, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        if (isset($valueBlock->fee) && $valueBlock->fee) {
                                            $xmlPodcastRecipient->setAttribute("fee", htmlspecialchars("true", ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        $xmlPodcastValue->appendChild($xmlPodcastRecipient);
                                    }
                                }
                            }
                        }
                        // If there is at least one Recipient
                        if (isset($xmlPodcastRecipient)) {
                            $xmlChannel->appendChild($xmlPodcastValue);
                        }
                    }
                }
            }

            // Podroll
            list($podrollField,) = GeneralHelper::getFieldDefinition('podroll');
            if ($podrollField) {
                $podrollFieldHandle = $podrollField->handle;
                if (isset($podcast->$podrollFieldHandle) && get_class($podcast->$podrollFieldHandle) == EntryQuery::class) {
                    $remoteItems = $podcast->$podrollFieldHandle->all();
                    $xmlPodcastPodroll = $xml->createElement("podcast:podroll");
                    foreach ($remoteItems as $remoteItem) {
                        if (isset($remoteItem->feedGuid) && $remoteItem->feedGuid) {
                            $xmlPodcastRemoteItem = $xml->createElement("podcast:remoteItem");
                            $xmlPodcastRemoteItem->setAttribute("feedGuid", htmlspecialchars($remoteItem->feedGuid, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            if (isset($remoteItem->feedUrl) && $remoteItem->feedUrl) {
                                $xmlPodcastRemoteItem->setAttribute("feedUrl", htmlspecialchars($remoteItem->feedUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            }
                            if (isset($remoteItem->itemGuid) && $remoteItem->itemGuid) {
                                $xmlPodcastRemoteItem->setAttribute("itemGuid", htmlspecialchars($remoteItem->itemGuid, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            }
                            if (isset($remoteItem->medium) && $remoteItem->medium) {
                                if (is_object($remoteItem->medium) && get_class($remoteItem->medium) == SingleOptionFieldData::class && $remoteItem->medium->value) {
                                    $xmlPodcastRemoteItem->setAttribute("medium", htmlspecialchars($remoteItem->medium->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                } elseif (!is_object($remoteItem->medium)) {
                                    $xmlPodcastRemoteItem->setAttribute("medium", htmlspecialchars($remoteItem->medium, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                            }
                            $xmlPodcastPodroll->appendChild($xmlPodcastRemoteItem);
                        }
                    }
                    // If there is at least one Remote item
                    if (isset($xmlPodcastRemoteItem)) {
                        $xmlChannel->appendChild($xmlPodcastPodroll);
                    }
                }
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
                    $prefixUrl = GeneralHelper::prefixUrl($assetFileUrl, $podcast, $site->id);
                    $xmlEnclosure->setAttribute("url", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    $xmlItem->appendChild($xmlEnclosure);

                    $xmlEnclosure->setAttribute("length", $asset->size);
                    $xmlEnclosure->setAttribute("type", FileHelper::getMimeTypeByExtension($assetFileUrl));
                }

                // Episode Enclosure
                list($enclosureField, $enclosureBlockTypeHandle) = GeneralHelper::getFieldDefinition('enclosure');
                if ($enclosureField) {
                    $enclosureFieldHandle = $enclosureField->handle;
                    $enclosureBlocks = [];
                    if (get_class($enclosureField) == Assets::class) {
                        if (isset($episode->$enclosureFieldHandle) && $episode->$enclosureFieldHandle) {
                            $enclosures = $episode->$enclosureFieldHandle->all();
                            foreach ($enclosures as $enclosure) {
                                $xmlEnclosure = $xml->createElement("podcast:alternateEnclosure");
                                $xmlEnclosure->setAttribute("type", $enclosure->mimeType);
                                $xmlEnclosure->setAttribute("length", $enclosure->size);
                                if (isset($enclosure->enclosureTitle) && $enclosure->enclosureTitle) {
                                    $xmlEnclosure->setAttribute("title", htmlspecialchars($enclosure->enclosureTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                } elseif (isset($enclosure->title) && $enclosure->title) {
                                    $xmlEnclosure->setAttribute("title", htmlspecialchars($enclosure->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($enclosure->enclosureBitrate) && $enclosure->enclosureBitrate) {
                                    if (is_object($enclosure->enclosureBitrate) && get_class($enclosure->enclosureBitrate) == SingleOptionFieldData::class && $enclosure->enclosureBitrate->value) {
                                        $xmlEnclosure->setAttribute("bitrate", htmlspecialchars($enclosure->enclosureBitrate->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (!is_object($enclosure->enclosureBitrate)) {
                                        $xmlEnclosure->setAttribute("bitrate", htmlspecialchars($enclosure->enclosureBitrate, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }
                                if (isset($enclosure->enclosureHeight) && $enclosure->enclosureHeight) {
                                    $xmlEnclosure->setAttribute("height", $enclosure->enclosureHeight);
                                }
                                if (isset($enclosure->enclosureLang) && $enclosure->enclosureLang) {
                                    if (is_object($enclosure->enclosureLang) && get_class($enclosure->enclosureLang) == SingleOptionFieldData::class && $enclosure->enclosureLang->value) {
                                        $xmlEnclosure->setAttribute("lang", htmlspecialchars($enclosure->enclosureLang->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (!is_object($enclosure->enclosureLang)) {
                                        $xmlEnclosure->setAttribute("lang", htmlspecialchars($enclosure->enclosureLang, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }
                                if (isset($enclosure->enclosureCodecs) && $enclosure->enclosureCodecs) {
                                    if (is_object($enclosure->enclosureCodecs) && get_class($enclosure->enclosureCodecs) == SingleOptionFieldData::class && $enclosure->enclosureCodecs->value) {
                                        $xmlEnclosure->setAttribute("codecs", htmlspecialchars($enclosure->enclosureCodecs->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (!is_object($enclosure->enclosureCodecs)) {
                                        $xmlEnclosure->setAttribute("codecs", htmlspecialchars($enclosure->enclosureCodecs, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }
                                if (isset($enclosure->enclosureRel) && $enclosure->enclosureRel) {
                                    if (is_object($enclosure->enclosureRel) && get_class($enclosure->enclosureRel) == SingleOptionFieldData::class && $enclosure->enclosureRel->value) {
                                        $xmlEnclosure->setAttribute("rel", htmlspecialchars($enclosure->enclosureRel->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (!is_object($enclosure->enclosureRel)) {
                                        $xmlEnclosure->setAttribute("rel", htmlspecialchars($enclosure->enclosureRel, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }
                                if (isset($enclosure->enclosureDefault) && $enclosure->enclosureDefault) {
                                    $xmlEnclosure->setAttribute("default", "true");
                                }
                                $xmlSource = $xml->createElement("podcast:source");
                                $prefixUrl = GeneralHelper::prefixUrl($enclosure->getUrl(), $podcast, $site->id);
                                $xmlSource->setAttribute("uri", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlEnclosure->appendChild($xmlSource);
                                $xmlItem->appendChild($xmlEnclosure);
                            }
                        }
                    } elseif (get_class($enclosureField) == Matrix::class) {
                        $blockQuery = \craft\elements\MatrixBlock::find();
                        $enclosureBlocks = $blockQuery->fieldId($enclosureField->id)->owner($episode)->type($enclosureBlockTypeHandle)->all();
                    } elseif (get_class($enclosureField) == SuperTableField::class) {
                        $blockQuery = SuperTableBlockElement::find();
                        $enclosureBlocks = $blockQuery->fieldId($enclosureField->id)->owner($episode)->all();
                    }
                    foreach ($enclosureBlocks as $enclosureBlock) {
                        $type = false;
                        $length = false;
                        if ((isset($enclosureBlock->sources) && is_object($enclosureBlock->sources) && get_class($enclosureBlock->sources) == AssetQuery::class &&
                                $enclosureBlock->sources->all()) ||
                            (isset($enclosureBlock->otherSources) && $enclosureBlock->otherSources)
                        ) {
                            $xmlEnclosure = $xml->createElement("podcast:alternateEnclosure");
                            if (isset($enclosureBlock->enclosureTitle) && $enclosureBlock->enclosureTitle) {
                                $xmlEnclosure->setAttribute("title", htmlspecialchars($enclosureBlock->enclosureTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            }
                            if (isset($enclosureBlock->enclosureBitrate) && $enclosureBlock->enclosureBitrate) {
                                if (is_object($enclosureBlock->enclosureBitrate) && get_class($enclosureBlock->enclosureBitrate) == SingleOptionFieldData::class && $enclosureBlock->enclosureBitrate->value) {
                                    $xmlEnclosure->setAttribute("bitrate", htmlspecialchars($enclosureBlock->enclosureBitrate->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                } elseif (!is_object($enclosureBlock->enclosureBitrate)) {
                                    $xmlEnclosure->setAttribute("bitrate", htmlspecialchars($enclosureBlock->enclosureBitrate, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                            }
                            if (isset($enclosureBlock->enclosureHeight) && $enclosureBlock->enclosureHeight) {
                                $xmlEnclosure->setAttribute("height", $enclosureBlock->enclosureHeight);
                            }
                            if (isset($enclosureBlock->enclosureLang) && $enclosureBlock->enclosureLang) {
                                if (is_object($enclosureBlock->enclosureLang) && get_class($enclosureBlock->enclosureLang) == SingleOptionFieldData::class && $enclosureBlock->enclosureLang->value) {
                                    $xmlEnclosure->setAttribute("lang", htmlspecialchars($enclosureBlock->enclosureLang->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                } elseif (!is_object($enclosureBlock->enclosureLang)) {
                                    $xmlEnclosure->setAttribute("lang", htmlspecialchars($enclosureBlock->enclosureLang, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                            }
                            if (isset($enclosureBlock->enclosureCodecs) && $enclosureBlock->enclosureCodecs) {
                                if (is_object($enclosureBlock->enclosureCodecs) && get_class($enclosureBlock->enclosureCodecs) == SingleOptionFieldData::class && $enclosureBlock->enclosureCodecs->value) {
                                    $xmlEnclosure->setAttribute("codecs", htmlspecialchars($enclosureBlock->enclosureCodecs->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                } elseif (!is_object($enclosureBlock->enclosureCodecs)) {
                                    $xmlEnclosure->setAttribute("codecs", htmlspecialchars($enclosureBlock->enclosureCodecs, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                            }
                            if (isset($enclosureBlock->enclosureRel) && $enclosureBlock->enclosureRel) {
                                if (is_object($enclosureBlock->enclosureRel) && get_class($enclosureBlock->enclosureRel) == SingleOptionFieldData::class && $enclosureBlock->enclosureRel->value) {
                                    $xmlEnclosure->setAttribute("rel", htmlspecialchars($enclosureBlock->enclosureRel->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                } elseif (!is_object($enclosureBlock->enclosureRel)) {
                                    $xmlEnclosure->setAttribute("rel", htmlspecialchars($enclosureBlock->enclosureRel, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                            }
                            if (isset($enclosureBlock->enclosureDefault) && $enclosureBlock->enclosureDefault) {
                                $xmlEnclosure->setAttribute("default", "true");
                            }
                            $sources = $enclosureBlock->sources->all();
                            foreach ($sources as $key => $source) {
                                if ($key == 0) {
                                    if (isset($source->mimeType)) {
                                        $type = true;
                                        $xmlEnclosure->setAttribute("type", $source->mimeType);
                                    }
                                    if (isset($source->size)) {
                                        $length = true;
                                        $xmlEnclosure->setAttribute("length", $source->size);
                                    }
                                }
                                $xmlSource = $xml->createElement("podcast:source");
                                $prefixUrl = GeneralHelper::prefixUrl($source->getUrl(), $podcast, $site->id);
                                $xmlSource->setAttribute("uri", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlEnclosure->appendChild($xmlSource);
                            }
                            if (isset($enclosureBlock->otherSources) && $enclosureBlock->otherSources) {
                                if (!$type && isset($enclosureBlock->enclosureType) && $enclosureBlock->enclosureType) {
                                    if (is_object($enclosureBlock->enclosureType) && get_class($enclosureBlock->enclosureType) == SingleOptionFieldData::class && $enclosureBlock->enclosureType->value) {
                                        $type = true;
                                        $xmlEnclosure->setAttribute("type",  htmlspecialchars($enclosureBlock->enclosureType->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (!is_object($enclosureBlock->enclosureType)) {
                                        $type = true;
                                        $xmlEnclosure->setAttribute("type",  htmlspecialchars($enclosureBlock->enclosureType, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }
                                if (!$type) {
                                    continue;
                                }
                                if (!$length && isset($enclosureBlock->enclosureLength) && $enclosureBlock->enclosureLength) {
                                    $xmlEnclosure->setAttribute("length", $enclosureBlock->enclosureLength);
                                }
                                if (is_array($enclosureBlock->otherSources)) {
                                    foreach ($enclosureBlock->otherSources as $source) {
                                        if (isset($source['uri']) && $source['uri']) {
                                            $xmlSource = $xml->createElement("podcast:source");
                                            $prefixUrl = GeneralHelper::prefixUrl($source['uri'], $podcast, $site->id);
                                            $xmlSource->setAttribute("uri", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            if (isset($source['contentType']) && $source['contentType']) {
                                                $xmlSource->setAttribute("contentType", htmlspecialchars($source['contentType'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            $xmlEnclosure->appendChild($xmlSource);
                                        }
                                    }
                                } else {
                                    $xmlSource = $xml->createElement("podcast:source");
                                    $prefixUrl = GeneralHelper::prefixUrl($enclosureBlock->otherSources, $podcast, $site->id);
                                    $xmlSource->setAttribute("uri", htmlspecialchars($prefixUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    $xmlEnclosure->appendChild($xmlSource);
                                }
                            }
                            $xmlItem->appendChild($xmlEnclosure);
                        }
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

                    // Podcast season
                    $podcastSeason = $xml->createElement("podcast:season", (string)$episode->episodeSeason);
                    if ($episode->seasonName) {
                        $podcastSeason->setAttribute("name",  htmlspecialchars($episode->seasonName, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    }
                    $xmlItem->appendChild($podcastSeason);
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
                    if (isset($keywords) && $keywords) {
                        $xmlEpisodeKeywords = $xml->createElement("itunes:keywords", htmlspecialchars($keywords, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlItem->appendChild($xmlEpisodeKeywords);
                    }
                }

                // Episode GUID
                if ($episode->episodeGUID) {
                    $xmlEpisodeGUID = $xml->createElement("guid", $episode->episodeGUID);
                    $xmlItem->appendChild($xmlEpisodeGUID);
                }

                // Episode Location
                list($locationField, $locationBlockTypeHandle) = GeneralHelper::getFieldDefinition('episodeLocation');
                if ($locationField) {
                    $locationFieldHandle = $locationField->handle;
                    if (get_class($locationField) == PlainText::class) {
                        if (isset($episode->$locationFieldHandle) && $episode->$locationFieldHandle) {
                            $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($episode->$locationFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            $xmlItem->appendChild($xmlLocation);
                        }
                    } elseif (get_class($locationField) == TableField::class) {
                        if (isset($episode->$locationFieldHandle) && $episode->$locationFieldHandle) {
                            foreach ($episode->$locationFieldHandle as $row) {
                                if (isset($row['name']) && $row['name']) {
                                    $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($row['name'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    if (isset($row['geo']) && $row['geo']) {
                                        $xmlLocation->setAttribute("geo", htmlspecialchars($row['geo'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    } elseif (isset($row['lat']) && $row['lat'] && isset($row['lon']) && $row['lon']) {
                                        $geo = 'geo:' . $row['lat'] . ',' . $row['lon'];
                                        $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    if (isset($row['osm']) && $row['osm']) {
                                        $xmlLocation->setAttribute("osm", htmlspecialchars($row['osm'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    $xmlItem->appendChild($xmlLocation);
                                    break;
                                }
                            }
                        }
                    } elseif (get_class($locationField) == EasyAddressFieldField::class) {
                        if (isset($episode->$locationFieldHandle->name) && $episode->$locationFieldHandle->name) {
                            $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($episode->$locationFieldHandle->name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            if (isset($episode->$locationFieldHandle->latitude) && $episode->$locationFieldHandle->latitude && isset($episode->$locationFieldHandle->longitude) && $episode->$locationFieldHandle->longitude) {
                                $geo = 'geo:' . $episode->$locationFieldHandle->latitude . ',' . $episode->$locationFieldHandle->longitude;
                                $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            }
                            $xmlItem->appendChild($xmlLocation);
                        }
                    } elseif (get_class($locationField) == GoogleMapAddressField::class) {
                        if (isset($episode->$locationFieldHandle->name) && $episode->$locationFieldHandle->name) {
                            $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($episode->$locationFieldHandle->name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            if (isset($episode->$locationFieldHandle->lat) && $episode->$locationFieldHandle->lat && isset($episode->$locationFieldHandle->lng) && $episode->$locationFieldHandle->lng) {
                                $geo = 'geo:' . $episode->$locationFieldHandle->lat . ',' . $episode->$locationFieldHandle->lng;
                                $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            }
                            $xmlItem->appendChild($xmlLocation);
                        }
                    } elseif (get_class($locationField) == Matrix::class || get_class($locationField) == SuperTableField::class) {
                        $locationBlocks = [];
                        if (get_class($locationField) == Matrix::class) {
                            $blockQuery = \craft\elements\MatrixBlock::find();
                            $locationBlocks = $blockQuery->fieldId($locationField->id)->owner($episode)->type($locationBlockTypeHandle)->all();
                        } elseif (get_class($locationField) == SuperTableField::class) {
                            $blockQuery = SuperTableBlockElement::find();
                            $locationBlocks = $blockQuery->fieldId($locationField->id)->owner($episode)->all();
                        }
                        foreach ($locationBlocks as $locationBlock) {
                            if (isset($locationBlock->location) && $locationBlock->location) {
                                if (is_object($locationBlock->location) && get_class($locationBlock->location) == EasyAddressFieldModel::class) {
                                    if (isset($locationBlock->location->name) && $locationBlock->location->name) {
                                        $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($locationBlock->location->name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        if (isset($locationBlock->location->latitude) && $locationBlock->location->latitude && isset($locationBlock->location->longitude) && $locationBlock->location->longitude) {
                                            $geo = 'geo:' . $locationBlock->location->latitude . ',' . $locationBlock->location->longitude;
                                            $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        $xmlItem->appendChild($xmlLocation);
                                        break;
                                    }
                                } elseif (is_object($locationBlock->location) && get_class($locationBlock->location) == GoogleMapAddressModel::class) {
                                    if (isset($locationBlock->location->name) && $locationBlock->location->name) {
                                        $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($locationBlock->location->name, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        if (isset($locationBlock->location->lat) && $locationBlock->location->lat && isset($locationBlock->location->lng) && $locationBlock->location->lng) {
                                            $geo = 'geo:' . $locationBlock->location->lat . ',' . $locationBlock->location->lng;
                                            $xmlLocation->setAttribute("geo", htmlspecialchars($geo, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                        $xmlItem->appendChild($xmlLocation);
                                        break;
                                    }
                                } elseif (!is_object($locationBlock->location)) {
                                    $xmlLocation = $xml->createElement("podcast:location", htmlspecialchars($locationBlock->location, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    $xmlItem->appendChild($xmlLocation);
                                    break;
                                }
                            }
                        }
                    }
                }

                // Episode soundbite
                list($soundbiteField, $soundbiteBlockTypeHandle) = GeneralHelper::getFieldDefinition('soundbite');
                if ($soundbiteField) {
                    $soundbiteBlocks = [];
                    if (get_class($soundbiteField) == Matrix::class) {
                        $blockQuery = \craft\elements\MatrixBlock::find();
                        $soundbiteBlocks = $blockQuery->fieldId($soundbiteField->id)->owner($episode)->type($soundbiteBlockTypeHandle)->all();
                    } elseif (get_class($soundbiteField) == SuperTableField::class) {
                        $blockQuery = SuperTableBlockElement::find();
                        $soundbiteBlocks = $blockQuery->fieldId($soundbiteField->id)->owner($episode)->all();
                    }
                    foreach ($soundbiteBlocks as $soundbiteBlock) {
                        if (isset($soundbiteBlock->startTime) && isset($soundbiteBlock->duration) && $soundbiteBlock->duration) {
                            $xmlSoundbite = $xml->createElement("podcast:soundbite", (isset($soundbiteBlock->soundbiteTitle) && $soundbiteBlock->soundbiteTitle) ? htmlspecialchars($soundbiteBlock->soundbiteTitle, ENT_QUOTES | ENT_XML1, 'UTF-8') : '');
                            $xmlSoundbite->setAttribute("startTime", $soundbiteBlock->startTime);
                            $xmlSoundbite->setAttribute("duration", $soundbiteBlock->duration);
                            $xmlItem->appendChild($xmlSoundbite);
                        }
                    }
                }

                // Episode chapter
                list($chapterField, $chapterBlockTypeHandle) = GeneralHelper::getFieldDefinition('chapter');
                if ($chapterField) {
                    if (get_class($chapterField) == Matrix::class) {
                        $blockQuery = \craft\elements\MatrixBlock::find();
                        $chapterBlock = $blockQuery->fieldId($chapterField->id)->owner($episode)->type($chapterBlockTypeHandle)->one();
                    } elseif (get_class($chapterField) == SuperTableField::class) {
                        $blockQuery = SuperTableBlockElement::find();
                        $chapterBlock = $blockQuery->fieldId($chapterField->id)->owner($episode)->one();
                    }
                    if (isset($chapterBlock)) {
                        $chapterUrl = $site->getBaseUrl() . 'episodes/chapter?episodeId=' . $episode->id . '&site=' . $site->handle;
                        $xmlChapter = $xml->createElement("podcast:chapters");
                        $xmlChapter->setAttribute("url", htmlspecialchars($chapterUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                        $xmlChapter->setAttribute("type", "application/json+chapters");
                        $xmlItem->appendChild($xmlChapter);
                    }
                }

                // Episode License
                list($licenseField, $licenseBlockTypeHandle) = GeneralHelper::getFieldDefinition('episodeLicense');
                if ($licenseField) {
                    $licenseFieldHandle = $licenseField->handle;
                    if (get_class($licenseField) == PlainText::class) {
                        if (isset($episode->$licenseFieldHandle) && $episode->$licenseFieldHandle) {
                            $xmlLicense = $xml->createElement("podcast:license", htmlspecialchars($episode->$licenseFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            $xmlItem->appendChild($xmlLicense);
                        }
                    } elseif (get_class($licenseField) == Assets::class) {
                        if (isset($episode->$licenseFieldHandle) && $episode->$licenseFieldHandle) {
                            $license = $episode->$licenseFieldHandle->one();
                            if ($license) {
                                if (isset($license->licenseTitle) && $license->licenseTitle) {
                                    $licenseTitle = $license->licenseTitle;
                                } elseif (isset($license->title) && $license->title) {
                                    $licenseTitle = $license->title;
                                }
                                if (isset($licenseTitle)) {
                                    $xmlLicense = $xml->createElement("podcast:license", htmlspecialchars($licenseTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    $xmlLicense->setAttribute("url", htmlspecialchars($license->getUrl(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    $xmlItem->appendChild($xmlLicense);
                                }
                            }
                        }
                    } elseif (get_class($licenseField) == TableField::class) {
                        if (isset($episode->$licenseFieldHandle) && $episode->$licenseFieldHandle) {
                            foreach ($episode->$licenseFieldHandle as $row) {
                                if (isset($row['licenseTitle']) && $row['licenseTitle']) {
                                    $xmlLicense = $xml->createElement("podcast:license", htmlspecialchars($row['licenseTitle'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    if (isset($row['licenseUrl']) && $row['licenseUrl']) {
                                        $xmlLicense->setAttribute("url", htmlspecialchars($row['licenseUrl'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    $xmlItem->appendChild($xmlLicense);
                                }
                                break;
                            }
                        }
                    } elseif (get_class($licenseField) == Matrix::class || get_class($licenseField) == SuperTableField::class) {
                        $licenseBlocks = [];
                        if (get_class($licenseField) == Matrix::class) {
                            $blockQuery = \craft\elements\MatrixBlock::find();
                            $licenseBlocks = $blockQuery->fieldId($licenseField->id)->owner($episode)->type($licenseBlockTypeHandle)->all();
                        } elseif (get_class($licenseField) == SuperTableField::class) {
                            $blockQuery = SuperTableBlockElement::find();
                            $licenseBlocks = $blockQuery->fieldId($licenseField->id)->owner($episode)->all();
                        }
                        foreach ($licenseBlocks as $licenseBlock) {
                            if (isset($licenseBlock->licenseTitle) && $licenseBlock->licenseTitle) {
                                $xmlLicense = $xml->createElement("podcast:license", htmlspecialchars($licenseBlock->licenseTitle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                if (isset($licenseBlock->licenseUrl) && $licenseBlock->licenseUrl) {
                                    if (is_object($licenseBlock->licenseUrl) && get_class($licenseBlock->licenseUrl) == AssetQuery::class) {
                                        $licenseUrl = $licenseBlock->licenseUrl->one();
                                        if ($licenseUrl) {
                                            $xmlLicense->setAttribute("url",  htmlspecialchars($licenseUrl->getUrl(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    } elseif (!is_object($licenseBlock->licenseUrl)) {
                                        $xmlLicense->setAttribute("url",  htmlspecialchars($licenseBlock->licenseUrl, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                }
                                $xmlItem->appendChild($xmlLicense);
                            }
                            break;
                        }
                    }
                }

                // podcast:person for episodes
                list($personField, $personBlockTypeHandle) = GeneralHelper::getFieldDefinition('episodePerson');
                if ($personField) {
                    $personFieldHandle = $personField->handle;
                    if (get_class($personField) == PlainText::class) {
                        if (isset($episode->$personFieldHandle) && $episode->$personFieldHandle) {
                            $xmlPodcastPerson = $xml->createElement("podcast:person", htmlspecialchars($episode->$personFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            $xmlItem->appendChild($xmlPodcastPerson);
                        }
                    } elseif (get_class($personField) == TableField::class) {
                        if (isset($episode->$personFieldHandle) && $episode->$personFieldHandle) {
                            foreach ($episode->$personFieldHandle as $row) {
                                if (isset($row['person']) && $row['person']) {
                                    $xmlPodcastPerson = $xml->createElement("podcast:person", htmlspecialchars($row['person'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    if (isset($row['personRole']) && $row['personRole']) {
                                        $xmlPodcastPerson->setAttribute("role", htmlspecialchars($row['personRole'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    if (isset($row['personGroup']) && $row['personGroup']) {
                                        $xmlPodcastPerson->setAttribute("group", htmlspecialchars($row['personGroup'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    if (isset($row['personImg']) && $row['personImg']) {
                                        $xmlPodcastPerson->setAttribute("img", htmlspecialchars($row['personImg'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    if (isset($row['personHref']) && $row['personHref']) {
                                        $xmlPodcastPerson->setAttribute("href", htmlspecialchars($row['personHref'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    $xmlItem->appendChild($xmlPodcastPerson);
                                }
                            }
                        }
                    } elseif (get_class($personField) == Matrix::class || get_class($personField) == SuperTableField::class) {
                        $personBlocks = [];
                        if (get_class($personField) == Matrix::class) {
                            $blockQuery = \craft\elements\MatrixBlock::find();
                            $personBlocks = $blockQuery->fieldId($personField->id)->owner($episode)->type($personBlockTypeHandle)->all();
                        } elseif (get_class($personField) == SuperTableField::class) {
                            $blockQuery = SuperTableBlockElement::find();
                            $personBlocks = $blockQuery->fieldId($personField->id)->owner($episode)->all();
                        }
                        foreach ($personBlocks as $personBlock) {
                            $personsArray = [];
                            if (isset($personBlock->userPerson) && get_class($personBlock->userPerson) == UserQuery::class && $personBlock->userPerson->one()) {
                                $persons = $personBlock->userPerson->all();
                                foreach ($persons as $person) {
                                    if ($person->fullName) {
                                        $personArray = [];
                                        $personArray['name'] = $person->fullName;
                                        if (isset($person->personHref) && $person->personHref) {
                                            $personArray['personHref'] = $person->personHref;
                                        }
                                        if ($person->photoId) {
                                            $photo = Craft::$app->getAssets()->getAssetById($person->photoId);
                                            if ($photo) {
                                                $personArray['personImg'] = $photo->getUrl();
                                            }
                                        } elseif (isset($person->personImg) && $person->personImg) {
                                            if (!is_object($person->personImg)) {
                                                $personArray['personImg'] = $person->personImg;
                                            } else {
                                                if (get_class($person->personImg) == AssetQuery::class) {
                                                    $personImg = $person->personImg->one();
                                                    if ($personImg) {
                                                        $personArray['personImg'] = $personImg->getUrl();
                                                    } else {
                                                        $personArray['personImg'] = null;
                                                    }
                                                }
                                            }
                                        }
                                        $personsArray[] = $personArray;
                                    }
                                }
                            }
                            if (isset($personBlock->entryPerson) && get_class($personBlock->entryPerson) == EntryQuery::class && $personBlock->entryPerson->one()) {
                                $persons = $personBlock->entryPerson->all();
                                foreach ($persons as $person) {
                                    if (isset($person->title) && $person->title) {
                                        $personArray = [];
                                        $personArray['name'] = $person->title;
                                        if (isset($person->personHref) && $person->personHref) {
                                            $personArray['personHref'] = $person->personHref;
                                        }
                                        if (isset($person->personImg) && $person->personImg) {
                                            if (!is_object($person->personImg)) {
                                                $personArray['personImg'] = $person->personImg;
                                            } else {
                                                if (get_class($person->personImg) == AssetQuery::class) {
                                                    $personImg = $person->personImg->one();
                                                    if ($personImg) {
                                                        $personArray['personImg'] = $personImg->getUrl();
                                                    } else {
                                                        $personArray['personImg'] = null;
                                                    }
                                                }
                                            }
                                        }
                                        $personsArray[] = $personArray;
                                    }
                                }
                            }
                            if (isset($personBlock->tablePerson) && is_array($personBlock->tablePerson) && isset($personBlock->tablePerson[0]['person']) && $personBlock->tablePerson[0]['person']) {
                                foreach ($personBlock->tablePerson as $person) {
                                    if (isset($person['person']) && $person['person']) {
                                        $personArray = [];
                                        $personArray['name'] = $person['person'];
                                        if (isset($person['personHref']) && $person['personHref']) {
                                            $personArray['personHref'] = $person['personHref'];
                                        }
                                        if (isset($person['personImg']) && $person['personImg']) {
                                            $personArray['personImg'] = $person['personImg'];
                                        }
                                        $personsArray[] = $personArray;
                                    }
                                }
                            }
                            if (isset($personBlock->textPerson) && !is_object($personBlock->textPerson)) {
                                $personArray = [];
                                $personArray['name'] = $personBlock->textPerson;
                                $personsArray[] = $personArray;
                            }
                            foreach ($personsArray as $personArray) {
                                if ($personArray['name']) {
                                    $xmlPodcastPerson = $xml->createElement("podcast:person", htmlspecialchars($personArray['name'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    if (isset($personArray['personImg'])) {
                                        $xmlPodcastPerson->setAttribute("img", htmlspecialchars($personArray['personImg'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    if (isset($personArray['personHref'])) {
                                        $xmlPodcastPerson->setAttribute("href", htmlspecialchars($personArray['personHref'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    if (isset($personBlock->personRole) && $personBlock->personRole) {
                                        if (is_object($personBlock->personRole) && get_class($personBlock->personRole) == SingleOptionFieldData::class && $personBlock->personRole->value) {
                                            $xmlPodcastPerson->setAttribute("role", htmlspecialchars($personBlock->personRole->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($personBlock->personRole)) {
                                            $xmlPodcastPerson->setAttribute("role", htmlspecialchars($personBlock->personRole, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }
                                    if (isset($personBlock->personGroup) && $personBlock->personGroup) {
                                        if (is_object($personBlock->personGroup) && get_class($personBlock->personGroup) == SingleOptionFieldData::class && $personBlock->personGroup->value) {
                                            $xmlPodcastPerson->setAttribute("group", htmlspecialchars($personBlock->personGroup->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        } elseif (!is_object($personBlock->personGroup)) {
                                            $xmlPodcastPerson->setAttribute("group", htmlspecialchars($personBlock->personGroup, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                        }
                                    }

                                    // if Role and Group is defined via entry
                                    if (isset($personBlock->podcastTaxonomy) && $personBlock->podcastTaxonomy) {
                                        if (is_object($personBlock->podcastTaxonomy) && get_class($personBlock->podcastTaxonomy) == EntryQuery::class) {
                                            $podcastTaxonomy = $personBlock->podcastTaxonomy->one();
                                            if ($podcastTaxonomy) {
                                                if (isset($podcastTaxonomy->personRole) && $podcastTaxonomy->personRole) {
                                                    if (is_object($podcastTaxonomy->personRole) && get_class($podcastTaxonomy->personRole) == SingleOptionFieldData::class && $podcastTaxonomy->personRole->value) {
                                                        $xmlPodcastPerson->setAttribute("role", htmlspecialchars($podcastTaxonomy->personRole->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    } elseif (!is_object($podcastTaxonomy->personRole)) {
                                                        $xmlPodcastPerson->setAttribute("role", htmlspecialchars($podcastTaxonomy->personRole, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    }
                                                }
                                                if (isset($podcastTaxonomy->personGroup) && $podcastTaxonomy->personGroup) {
                                                    if (is_object($podcastTaxonomy->personGroup) && get_class($podcastTaxonomy->personGroup) == SingleOptionFieldData::class && $podcastTaxonomy->personGroup->value) {
                                                        $xmlPodcastPerson->setAttribute("group", htmlspecialchars($podcastTaxonomy->personGroup->value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    } elseif (!is_object($podcastTaxonomy->personGroup)) {
                                                        $xmlPodcastPerson->setAttribute("group", htmlspecialchars($podcastTaxonomy->personGroup, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    }
                                                }
                                                $level1 = $personBlock->podcastTaxonomy->level(1)->one();
                                                $level2 = $personBlock->podcastTaxonomy->level(2)->one();
                                                if (isset($level1) && isset($level2)) {
                                                    $xmlPodcastPerson->setAttribute("group", htmlspecialchars($level1->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                    $xmlPodcastPerson->setAttribute("role", htmlspecialchars($level2->title, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                                }
                                            }
                                        }
                                    }
                                    $xmlItem->appendChild($xmlPodcastPerson);
                                }
                            }
                        }
                    }
                }

                // Episode transcript
                list($transcriptField, $transcriptBlockTypeHandle) = GeneralHelper::getFieldDefinition('transcript');
                if ($transcriptField) {
                    $transcriptFieldHandle = $transcriptField->handle;
                    if (get_class($transcriptField) == Checkboxes::class || get_class($transcriptField) == MultiSelect::class) {
                        foreach ($episode->$transcriptFieldHandle->getOptions() as $option) {
                            if ($option->selected) {
                                $type = $option->value;
                                switch ($type) {
                                    case 'text':
                                        $mimeType = 'text/plain';
                                        break;
                                    case 'html':
                                        $mimeType = 'text/html';
                                        break;
                                    case 'vtt':
                                        $mimeType = 'text/vtt';
                                        break;
                                    case 'json':
                                        $mimeType = 'application/json';
                                        break;
                                    case 'srt':
                                        $mimeType = 'application/x-subrip';
                                        break;
                                    default:
                                        $mimeType = null;
                                        break;
                                }
                                if ($mimeType) {
                                    $xmlTranscript = $xml->createElement("podcast:transcript");
                                    $xmlTranscript->setAttribute("url", Craft::getAlias("@web") . "/episodes/transcript?episodeId=" . $episode->id . '&site=' . $site->handle . "&type=" . $type);
                                    $xmlTranscript->setAttribute("type", $mimeType);
                                    $xmlItem->appendChild($xmlTranscript);
                                }
                            }
                        }
                    } elseif (get_class($transcriptField) == RadioButtons::class || get_class($transcriptField) == Dropdown::class) {
                        $type = $episode->$transcriptFieldHandle->value;
                        switch ($type) {
                            case 'text':
                                $mimeType = 'text/plain';
                                break;
                            case 'html':
                                $mimeType = 'text/html';
                                break;
                            case 'vtt':
                                $mimeType = 'text/vtt';
                                break;
                            case 'json':
                                $mimeType = 'application/json';
                                break;
                            case 'srt':
                                $mimeType = 'application/x-subrip';
                                break;
                            default:
                                $mimeType = null;
                                break;
                        }
                        if ($mimeType) {
                            $xmlTranscript = $xml->createElement("podcast:transcript");
                            $xmlTranscript->setAttribute("url", Craft::getAlias("@web") . "/episodes/transcript?episodeId=" . $episode->id . '&site=' . $site->handle . "&type=" . $type);
                            $xmlTranscript->setAttribute("type", $mimeType);
                            $xmlItem->appendChild($xmlTranscript);
                        }
                    } elseif (get_class($transcriptField) == Assets::class) {
                        if (isset($episode->$transcriptFieldHandle) && $episode->$transcriptFieldHandle) {
                            $transcripts = $episode->$transcriptFieldHandle->all();
                            foreach ($transcripts as $transcript) {
                                $xmlTranscript = $xml->createElement("podcast:transcript");
                                $xmlTranscript->setAttribute("url", htmlspecialchars($transcript->getUrl(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                $xmlTranscript->setAttribute("type", $transcript->mimeType);
                                $xmlItem->appendChild($xmlTranscript);
                            }
                        }
                    } elseif (get_class($transcriptField) == Matrix::class || get_class($transcriptField) == SuperTableField::class) {
                        $transcriptBlocks = [];
                        if (get_class($transcriptField) == Matrix::class) {
                            $blockQuery = \craft\elements\MatrixBlock::find();
                            $transcriptBlocks = $blockQuery->fieldId($transcriptField->id)->owner($episode)->type($transcriptBlockTypeHandle)->all();
                        } elseif (get_class($transcriptField) == SuperTableField::class) {
                            $blockQuery = SuperTableBlockElement::find();
                            $transcriptBlocks = $blockQuery->fieldId($transcriptField->id)->owner($episode)->all();
                        }
                        foreach ($transcriptBlocks as $transcriptBlock) {
                            $xmlTranscript = null;
                            if (isset($transcriptBlock->transcript) && $transcriptBlock->transcript) {
                                if (is_object($transcriptBlock->transcript) && get_class($transcriptBlock->transcript) == AssetQuery::class) {
                                    $transcriptFiles = $transcriptBlock->transcript->all();
                                    if ($transcriptFiles) {
                                        foreach ($transcriptFiles as $transcriptFile) {
                                            $xmlTranscript = $xml->createElement("podcast:transcript");
                                            $xmlTranscript->setAttribute("url",  htmlspecialchars($transcriptFile->getUrl(), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            $xmlTranscript->setAttribute("type", $transcriptFile->mimeType);
                                            $xmlItem->appendChild($xmlTranscript);
                                        }
                                    }
                                } elseif (!is_object($transcriptBlock->transcript)) {
                                    $xmlTranscript = $xml->createElement("podcast:transcript");
                                    $xmlTranscript->setAttribute("url", $transcriptBlock->transcript);
                                    if (isset($transcriptBlock->mimeType)) {
                                        $mimeType = $transcriptBlock->mimeType;
                                        if (is_object($mimeType) && (get_class($mimeType) == SingleOptionFieldData::class)) {
                                            $type = $mimeType->value;
                                            $xmlTranscript->setAttribute("type", $type);
                                        } elseif (!is_object($mimeType)) {
                                            $xmlTranscript->setAttribute("type", $mimeType);
                                        }
                                    }
                                    $xmlItem->appendChild($xmlTranscript);
                                }
                            }
                        }
                    }
                }

                // Episode Social Interact
                list($socialInteractField) = GeneralHelper::getFieldDefinition('socialInteract');
                if ($socialInteractField) {
                    $socialInteractFieldHandle = $socialInteractField->handle;
                    if (isset($episode->$socialInteractFieldHandle) && is_array($episode->$socialInteractFieldHandle)) {
                        $protocolDisable = null;
                        foreach ($episode->$socialInteractFieldHandle as $row) {
                            if ((isset($row['uri']) && $row['uri'] && isset($row['protocol']) && $row['protocol']) ||
                                (isset($row['protocol']) && $row['protocol'] == 'disabled')
                            ) {
                                if ($row['protocol'] == 'disabled') {
                                    if ($protocolDisable !== false) {
                                        $protocolDisable = true;
                                    }
                                    continue;
                                } else {
                                    $protocolDisable = false;
                                    $xmlSocialInteract = $xml->createElement("podcast:socialInteract");
                                    $xmlSocialInteract->setAttribute("protocol", htmlspecialchars($row['protocol'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                $xmlSocialInteract->setAttribute("uri", htmlspecialchars($row['uri'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                if (isset($row['priority']) && $row['priority']) {
                                    $xmlSocialInteract->setAttribute("priority", htmlspecialchars($row['priority'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($row['accountId']) && $row['accountId']) {
                                    $xmlSocialInteract->setAttribute("accountId", htmlspecialchars($row['accountId'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                if (isset($row['accountUrl']) && $row['accountUrl']) {
                                    $xmlSocialInteract->setAttribute("accountUrl", htmlspecialchars($row['accountUrl'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                }
                                $xmlItem->appendChild($xmlSocialInteract);
                            }
                        }
                        if ($protocolDisable) {
                            $xmlSocialInteract = $xml->createElement("podcast:socialInteract");
                            $xmlSocialInteract->setAttribute("protocol", "disabled");
                            $xmlItem->appendChild($xmlSocialInteract);
                        }
                    }
                }

                // Episode txt
                list($txtField) = GeneralHelper::getFieldDefinition('episodeTxt');
                if ($txtField) {
                    $txtFieldHandle = $txtField->handle;
                    if (isset($episode->$txtFieldHandle) && $episode->$txtFieldHandle) {
                        if (is_array($episode->$txtFieldHandle)) {
                            foreach ($episode->$txtFieldHandle as $row) {
                                if (isset($row['txt']) && $row['txt']) {
                                    $xmlTxt = $xml->createElement("podcast:txt", htmlspecialchars($row['txt'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    if (isset($row['purpose']) && $row['purpose']) {
                                        $xmlTxt->setAttribute("purpose", htmlspecialchars($row['purpose'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                    }
                                    $xmlItem->appendChild($xmlTxt);
                                }
                            }
                        } elseif (!is_object($episode->$txtFieldHandle)) {
                            $xmlTxt = $xml->createElement("podcast:txt", htmlspecialchars($episode->$txtFieldHandle, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            $xmlItem->appendChild($xmlTxt);
                        }
                    }
                }

                // podcast:value for episode
                list($valueField, $valueBlockTypeHandle) = GeneralHelper::getFieldDefinition('episodeValue');
                if ($valueField) {
                    $xmlPodcastRecipient = null;
                    $valueFieldHandle = $valueField->handle;
                    if (isset($episode->$valueFieldHandle) && get_class($episode->$valueFieldHandle) == EntryQuery::class) {
                        $value4value = $episode->$valueFieldHandle->one();
                        if (isset($value4value->valueType) && $value4value->valueType && isset($value4value->valueMethod) && $value4value->valueMethod) {
                            $xmlPodcastValue = $xml->createElement("podcast:value");
                            $xmlPodcastValue->setAttribute("type", htmlspecialchars($value4value->valueType, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            $xmlPodcastValue->setAttribute("method", htmlspecialchars($value4value->valueMethod, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            if (isset($value4value->valueSuggested) && $value4value->valueSuggested) {
                                $xmlPodcastValue->setAttribute("suggested", htmlspecialchars($value4value->valueSuggested, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            }
                            if (isset($value4value->recipient) && get_class($value4value->recipient) == MatrixBlockQuery::class) {
                                $valueBlocks = $value4value->recipient->all();
                                foreach ($valueBlocks as $valueBlock) {
                                    $recipientsArray = [];
                                    if (isset($valueBlock->userRecipient) && is_object($valueBlock->userRecipient) && get_class($valueBlock->userRecipient) == UserQuery::class && $valueBlock->userRecipient->one()) {
                                        $recipients = $valueBlock->userRecipient->all();
                                        foreach ($recipients as $recipient) {
                                            $recipientArray = [];
                                            if ($recipient->fullName) {
                                                $recipientArray['name'] = $recipient->fullName;
                                            }
                                            if (isset($recipient->recipientType) && $recipient->recipientType) {
                                                $recipientArray['recipientType'] = $recipient->recipientType;
                                            }
                                            if (isset($recipient->recipientCustomKey) && $recipient->recipientCustomKey) {
                                                $recipientArray['recipientCustomKey'] = $recipient->recipientCustomKey;
                                            }
                                            if (isset($recipient->recipientCustomValue) && $recipient->recipientCustomValue) {
                                                $recipientArray['recipientCustomValue'] = $recipient->recipientCustomValue;
                                            }
                                            if (isset($recipient->recipientAddress) && $recipient->recipientAddress) {
                                                $recipientArray['recipientAddress'] = $recipient->recipientAddress;
                                            }
                                            $recipientsArray[] = $recipientArray;
                                        }
                                    }
                                    if (isset($valueBlock->entryRecipient) && is_object($valueBlock->entryRecipient) && get_class($valueBlock->entryRecipient) == EntryQuery::class && $valueBlock->entryRecipient->one()) {
                                        $recipients = $valueBlock->entryRecipient->all();
                                        foreach ($recipients as $recipient) {
                                            $recipientArray = [];
                                            if (isset($recipient->title) && $recipient->title) {
                                                $recipientArray['name'] = $recipient->title;
                                            }
                                            if (isset($recipient->recipientType) && $recipient->recipientType) {
                                                $recipientArray['recipientType'] = $recipient->recipientType;
                                            }
                                            if (isset($recipient->recipientCustomKey) && $recipient->recipientCustomKey) {
                                                $recipientArray['recipientCustomKey'] = $recipient->recipientCustomKey;
                                            }
                                            if (isset($recipient->recipientCustomValue) && $recipient->recipientCustomValue) {
                                                $recipientArray['recipientCustomValue'] = $recipient->recipientCustomValue;
                                            }
                                            if (isset($recipient->recipientAddress) && $recipient->recipientAddress) {
                                                $recipientArray['recipientAddress'] = $recipient->recipientAddress;
                                            }
                                            $recipientsArray[] = $recipientArray;
                                        }
                                    }
                                    if (isset($valueBlock->otherRecipients) && is_array($valueBlock->otherRecipients)) {
                                        foreach ($valueBlock->otherRecipients as $recipient) {
                                            $recipientArray = [];
                                            if (isset($recipient['recipientName']) && $recipient['recipientName']) {
                                                $recipientArray['name'] = $recipient['recipientName'];
                                            }
                                            if (isset($recipient['recipientType']) && $recipient['recipientType']) {
                                                $recipientArray['recipientType'] = $recipient['recipientType'];
                                            }
                                            if (isset($recipient['recipientCustomKey']) && $recipient['recipientCustomKey']) {
                                                $recipientArray['recipientCustomKey'] = $recipient['recipientCustomKey'];
                                            }
                                            if (isset($recipient['recipientCustomValue']) && $recipient['recipientCustomValue']) {
                                                $recipientArray['recipientCustomValue'] = $recipient['recipientCustomValue'];
                                            }
                                            if (isset($recipient['recipientAddress']) && $recipient['recipientAddress']) {
                                                $recipientArray['recipientAddress'] = $recipient['recipientAddress'];
                                            }
                                            $recipientsArray[] = $recipientArray;
                                        }
                                    }

                                    foreach ($recipientsArray as $recipientArray) {
                                        if (isset($recipientArray['recipientType']) && isset($recipientArray['recipientAddress']) && isset($valueBlock->split) && $valueBlock->split) {
                                            $xmlPodcastRecipient = $xml->createElement("podcast:valueRecipient");
                                            $xmlPodcastRecipient->setAttribute("type", htmlspecialchars($recipientArray['recipientType'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            $xmlPodcastRecipient->setAttribute("address", htmlspecialchars($recipientArray['recipientAddress'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            if (isset($recipientArray['name'])) {
                                                $xmlPodcastRecipient->setAttribute("name", htmlspecialchars($recipientArray['name'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            if (isset($recipientArray['recipientCustomKey'])) {
                                                $xmlPodcastRecipient->setAttribute("customKey", htmlspecialchars($recipientArray['recipientCustomKey'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            if (isset($recipientArray['recipientCustomValue'])) {
                                                $xmlPodcastRecipient->setAttribute("customValue", htmlspecialchars($recipientArray['recipientCustomValue'], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            $xmlPodcastRecipient->setAttribute("split", htmlspecialchars($valueBlock->split, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            if (isset($valueBlock->fee) && $valueBlock->fee) {
                                                $xmlPodcastRecipient->setAttribute("fee", htmlspecialchars("true", ENT_QUOTES | ENT_XML1, 'UTF-8'));
                                            }
                                            $xmlPodcastValue->appendChild($xmlPodcastRecipient);
                                        }
                                    }
                                }
                            }
                            // If there is at least one Recipient
                            if (isset($xmlPodcastRecipient)) {
                                $xmlItem->appendChild($xmlPodcastValue);
                            }
                        }
                    }
                }

                $xmlChannel->appendChild($xmlItem);
            }

            $variables['xml'] = $xml->saveXML();
            return $variables;
        }, 0, new TagDependency(['tags' => ['studio-plugin']]));

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
        $variables['imageField'] = false;
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
                $variables['imageField'] = true;
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
        $op3 = Craft::$app->getRequest()->getBodyParam('enableOP3', $settings->enableOP3);
        if ($op3 !== $settings->enableOP3) {
            TagDependency::invalidate(Craft::$app->getCache(), 'studio-plugin');
        }
        $settings->enableOP3 = $op3;
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
            'enableOP3' => $settings->enableOP3,
        ], [
            'publishRSS' => $settings->publishRSS,
            'allowAllToSeeRSS' => $settings->allowAllToSeeRSS,
            'enableOP3' => $settings->enableOP3,
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
