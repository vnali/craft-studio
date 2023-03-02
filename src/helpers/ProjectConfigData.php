<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\helpers;

use craft\db\Table;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use vnali\studio\models\PodcastFormat;
use vnali\studio\models\PodcastFormatEpisode;
use vnali\studio\Studio;

class ProjectConfigData
{
    /**
     * Rebuilds the project config.
     *
     * @return array
     */
    public static function rebuildProjectConfig(): array
    {
        $configData = [
            'podcastFormats' => [],
        ];

        $podcastFormats = Studio::$plugin->podcastFormats->getAllPodcastFormats();
        foreach ($podcastFormats as $podcastFormat) {
            $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastFormat->id);
            $podcastFormatSites = Studio::$plugin->podcastFormats->getPodcastFormatSitesById($podcastFormat->id);
            $configData['podcastFormats'][$podcastFormat->uid] = self::getPodcastFormatData($podcastFormat, $podcastFormatEpisode, $podcastFormatSites);
        }

        return $configData;
    }

    /**
     * Returns the podcast format data.
     *
     * @param PodcastFormat $podcastFormat
     * @param PodcastFormatEpisode $podcastFormatEpisode
     * @param array $podcastFormatSites
     * @return array
     */
    public static function getPodcastFormatData(PodcastFormat $podcastFormat, PodcastFormatEpisode $podcastFormatEpisode, $podcastFormatSites): array
    {
        $configData['name'] = $podcastFormat->name;
        $configData['handle'] = $podcastFormat->handle;
        $configData['podcastVersioning'] = $podcastFormat->enableVersioning;
        $configData['podcastMapping'] = $podcastFormat->mapping;
        $configData['podcastAttributes'] = $podcastFormat->nativeSettings;
        // Set the podcast field layout
        $podcastFieldLayout = $podcastFormat->getFieldLayout();

        $podcastAttributes = json_decode($podcastFormat->nativeSettings, true);
        $tabsArray = [];
        $tabs = $podcastFieldLayout->getTabs();
        foreach ($tabs as $key => $tab) {
            $elements = [];
            foreach ($tab->elements as $key => $element) {
                if (isset($element->attribute) && (in_array($element->attribute, array_keys($podcastFormat->podcastNativeFields())))) {
                    /** @var BaseNativeField $element; */
                    $element->translatable = $podcastAttributes[$element->attribute]['translatable'];
                }
                $elements[] = $element;
            }
            $tab->elements = $elements;
            $tabsArray[] = $tab;
        }
        $podcastFieldLayout->setTabs($tabsArray);

        $podcastFieldLayoutConfig = $podcastFieldLayout->getConfig();

        if ($podcastFieldLayoutConfig) {
            if (empty($podcastFieldLayout->id)) {
                $podcastLayoutUid = StringHelper::UUID();
                $podcastFieldLayout->uid = $podcastLayoutUid;
            } else {
                $podcastLayoutUid = Db::uidById(Table::FIELDLAYOUTS, $podcastFieldLayout->id);
            }

            $configData['podcastFieldLayout'] = [$podcastLayoutUid => $podcastFieldLayoutConfig];
        }
        // Get config data from attributes
        $configData['episodeVersioning'] = $podcastFormatEpisode->enableVersioning;
        $configData['episodeMapping'] = $podcastFormatEpisode->mapping;
        $configData['episodeAttributes'] = $podcastFormatEpisode->nativeSettings;
        // Set the episode field layout
        $episodeFieldLayout = $podcastFormatEpisode->getFieldLayout();
        
        $episodeAttributes = json_decode($podcastFormatEpisode->nativeSettings, true);
        $tabsArray = [];
        $tabs = $episodeFieldLayout->getTabs();
        foreach ($tabs as $key => $tab) {
            $elements = [];
            foreach ($tab->elements as $key => $element) {
                if (isset($element->attribute) && (in_array($element->attribute, array_keys($podcastFormatEpisode->episodeNativeFields())))) {
                    /** @var BaseNativeField $element; */
                    $element->translatable = $episodeAttributes[$element->attribute]['translatable'];
                }
                $elements[] = $element;
            }
            $tab->elements = $elements;
            $tabsArray[] = $tab;
        }
        $episodeFieldLayout->setTabs($tabsArray);

        $episodeFieldLayoutConfig = $episodeFieldLayout->getConfig();

        if ($episodeFieldLayoutConfig) {
            if (empty($episodeFieldLayout->id)) {
                $episodeLayoutUid = StringHelper::UUID();
                $episodeFieldLayout->uid = $episodeLayoutUid;
            } else {
                $episodeLayoutUid = Db::uidById(Table::FIELDLAYOUTS, $episodeFieldLayout->id);
            }

            $configData['episodeFieldLayout'] = [$episodeLayoutUid => $episodeFieldLayoutConfig];
        }

        $configData['sitesSettings'] = [];
        foreach ($podcastFormatSites as $siteId => $podcastFormatSite) {
            $siteUid = Db::uidById(Table::SITES, $siteId);
            $configData['sitesSettings'][$siteUid] = [
                'podcastEnabledByDefault' => (bool)$podcastFormatSite['podcastEnabledByDefault'],
                'podcastUriFormat' => $podcastFormatSite['podcastUriFormat'] ?: null,
                'podcastTemplate' => $podcastFormatSite['podcastTemplate'] ?: null,
                'episodeEnabledByDefault' => (bool)$podcastFormatSite['episodeEnabledByDefault'],
                'episodeUriFormat' => $podcastFormatSite['episodeUriFormat'] ?: null,
                'episodeTemplate' => $podcastFormatSite['episodeTemplate'] ?: null,
            ];
        }
        return $configData;
    }
}
