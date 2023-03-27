<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\jobs;

use Craft;
use craft\fields\PlainText;
use craft\helpers\Assets;
use craft\queue\BaseJob;

use Symfony\Component\DomCrawler\Crawler;
use vnali\studio\elements\Episode;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\Studio;

/**
 * Episode import job
 */
class importEpisodeJob extends BaseJob
{
    public bool $ignoreImageAsset;

    public bool $ignoreMainAsset;

    public array $items;

    public ?int $limit;

    public int $podcastId;

    public int $total;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $podcast = Studio::$plugin->podcasts->getPodcastById($this->podcastId);
        $podcastFormat = $podcast->getPodcastFormat();
        $sitesSettings = $podcastFormat->getSiteSettings();
        $podcastFormatEpisode = $podcast->getPodcastFormatEpisode();
        $mapping = json_decode($podcastFormatEpisode->mapping, true);
        $step = 0;

        foreach ($this->items as $key => $item) {
            $crawler = new Crawler($item);

            if ($crawler->filter('html body item')->count() == 1) {
                if ($step == $this->limit) {
                    break;
                }

                $step++;

                // Set progress
                $this->setProgress(
                    $queue,
                    $step / ($this->limit ?? $this->total),
                    \Craft::t('app', 'Import {step, number} of {total, number}', [
                        'step' => $step,
                        'total' => ($this->limit ?? $this->total),
                    ])
                );

                $itemElement = new Episode();

                // Set podcast if podcast field is specified
                $itemElement->podcastId = $this->podcastId;
                foreach ($crawler->filter('html body item')->children() as $domElement) {
                    /** @var \DOMElement $domElement */
                    $nodeName = $domElement->nodeName;
                    switch ($nodeName) {
                        case 'title':
                            $title = $domElement->textContent;
                            $itemElement->title = $title;
                            break;
                        case 'explicit':
                            $explicit = $domElement->textContent;
                            if ($explicit == '1' || $explicit == 'yes' || $explicit == 'true') {
                                $itemElement->episodeExplicit = 1;
                            }
                            break;
                        case 'episode':
                            $number = $domElement->textContent;
                            $itemElement->episodeNumber = $number;
                            break;
                        case 'guid':
                            $guid = $domElement->textContent;
                            $itemElement->episodeGUID = $guid;
                            break;
                        case 'pubdate':
                            $pubdate = $domElement->textContent;
                            if (isset($mapping['episodePubDate']['field']) && $mapping['episodePubDate']['field']) {
                                $pubDateFieldId = $mapping['episodePubDate']['field'];
                                $pubDateField = Craft::$app->fields->getFieldByUid($pubDateFieldId);
                                $pubDateFieldHandle = $pubDateField->handle;
                                $itemElement->{$pubDateFieldHandle} = strtotime($pubdate);
                            }
                            break;
                        case 'duration':
                            $duration = $domElement->textContent;
                            $itemElement->duration = $duration;
                            break;
                        case 'summary':
                            $crawler = new Crawler($domElement);
                            $summary = $crawler->filter('summary')->html();
                            $summaryField = GeneralHelper::getElementSummaryField('episode', $mapping);
                            if ($summaryField) {
                                $summaryFieldHandle = $summaryField->handle;
                                $itemElement->{$summaryFieldHandle} = $summary;
                            }
                            break;
                        case 'description':
                            $crawler = new Crawler($domElement);
                            $description = $crawler->html();
                            $descriptionField = GeneralHelper::getElementDescriptionField('episode', $mapping);
                            if ($descriptionField) {
                                $descriptionFieldHandle = $descriptionField->handle;
                                $itemElement->{$descriptionFieldHandle} = $description;
                            }
                            break;
                        case 'encoded':
                            $crawler = new Crawler($domElement);
                            $contentEncoded = $crawler->filter('encoded')->html();
                            $contentEncodedField = GeneralHelper::getElementContentEncodedField('episode', $mapping);
                            if ($contentEncodedField) {
                                $contentEncodedFieldHandle = $contentEncodedField->handle;
                                $itemElement->{$contentEncodedFieldHandle} = $contentEncoded;
                            }
                            break;
                        case 'keywords':
                            $keywords = $domElement->textContent;
                            // Episode keyword
                            list($keywordField, $keywordFieldType, $keywordFieldHandle, $keywordFieldGroup) = GeneralHelper::getElementKeywordsField('episode', $mapping);
                            if ($keywordFieldHandle) {
                                if ($keywordFieldType == PlainText::class) {
                                    $itemElement->{$keywordFieldHandle} = $keywords;
                                } else {
                                    list($keywordIds) = GeneralHelper::saveKeywords($keywords, $keywordFieldType, $keywordFieldGroup->id);
                                    $itemElement->$keywordFieldHandle = $keywordIds;
                                }
                            }
                            break;
                        case 'image':
                            if (!$this->ignoreImageAsset) {
                                $href = $domElement->getAttribute('href');
                                $path = parse_url($href, PHP_URL_PATH);
                                $extension = pathinfo($path, PATHINFO_EXTENSION);
                                $basename = basename($path);
                                list($imageField, $imageContainer) = GeneralHelper::getElementImageField('episode', $mapping);
                                if ($imageField) {
                                    if (get_class($imageField) == 'craft\fields\PlainText') {
                                        $imageFieldHandle = $imageField->handle;
                                        $itemElement->{$imageFieldHandle} = $href;
                                    } elseif (get_class($imageField) == 'craft\fields\Assets') {
                                        if ($href) {
                                            // Set progress
                                            $this->setProgress(
                                                $queue,
                                                $step / ($this->limit ?? $this->total),
                                                \Craft::t('app', 'Import {step, number} of {total, number} - Upload image', [
                                                    'step' => $step,
                                                    'total' => ($this->limit ?? $this->total),
                                                ])
                                            );
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_POST, 0);
                                            curl_setopt($ch, CURLOPT_URL, $href);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
                                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                                            curl_setopt($ch, CURLOPT_HTTPGET, 1);
                                            $content = trim(curl_exec($ch));
                                            $itemElement = GeneralHelper::uploadFile($content, null, $imageField, $imageContainer, $itemElement, $basename, $extension);
                                        }
                                    }
                                }
                            }
                            break;
                        case 'enclosure':
                            if (!$this->ignoreMainAsset) {
                                $url = $domElement->getAttribute('url');
                                $path = parse_url($url, PHP_URL_PATH);
                                $extension = pathinfo($path, PATHINFO_EXTENSION);
                                $basename = basename($path);
                                $fieldContainer = null;
                                if (isset($mapping['mainAsset']['container'])) {
                                    $fieldContainer = $mapping['mainAsset']['container'];
                                }
                                if (isset($mapping['mainAsset']['field'])) {
                                    $fieldId = $mapping['mainAsset']['field'];
                                    if ($fieldId) {
                                        $field = Craft::$app->fields->getFieldByUid($fieldId);
                                        if ($field) {
                                            if (get_class($field) == 'craft\fields\Assets') {
                                                if ($url) {
                                                    // Set progress
                                                    $this->setProgress(
                                                        $queue,
                                                        $step / ($this->limit ?? $this->total),
                                                        \Craft::t('app', 'Import {step, number} of {total, number} - Upload file', [
                                                            'step' => $step,
                                                            'total' => $this->limit ?? $this->total,
                                                        ])
                                                    );
                                                    $tempFile = Assets::tempFilePath();
                                                    $fp = fopen($tempFile, 'w+');
                                                    $ch = curl_init(str_replace(" ", "%20", $url));
                                                    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
                                                    curl_setopt($ch, CURLOPT_FILE, $fp);
                                                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                                                    curl_exec($ch);
                                                    curl_close($ch);
                                                    fclose($fp);
                                                    if ($tempFile) {
                                                        craft::info("Content fetched from RSS $url");
                                                        $itemElement = GeneralHelper::uploadFile(null, $tempFile, $field, $fieldContainer, $itemElement, $basename, $extension);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        default:
                            break;
                    }
                }

                $siteId = null;
                $siteStatus = [];
                foreach ($sitesSettings as $key => $siteSettings) {
                    if (!$siteId) {
                        $siteId = $key;
                    }
                    $siteStatus[$key] = $siteSettings['episodeEnabledByDefault'];
                }

                if (!$siteId) {
                    Craft::warning("there is no site enabled for $item");
                }

                $itemElement->siteId = $siteId;
                $itemElement->setEnabledForSite($siteStatus);
                if (!Craft::$app->getElements()->saveElement($itemElement)) {
                    craft::warning("Creation error" . json_encode($itemElement->getErrors()));
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('studio', 'Importing episodes');
    }
}
