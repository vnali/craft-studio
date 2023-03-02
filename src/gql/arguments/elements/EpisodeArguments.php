<?php

namespace vnali\studio\gql\arguments\elements;

use Craft;
use craft\gql\base\ElementArguments;
use vnali\studio\elements\Episode;
use vnali\studio\Studio;

class EpisodeArguments extends ElementArguments
{
    // Public Methods
    // =========================================================================
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), self::getContentArguments(), []);
    }

    /**
     * @inheritdoc
     */
    public static function getContentArguments(): array
    {
        $podcastFormatEpisodes = Studio::$plugin->podcastFormats->getAllPodcastFormatEpisodes();
        $podcastFormatEpisodeFieldArguments = Craft::$app->getGql()->getContentArguments($podcastFormatEpisodes, Episode::class);
        return array_merge(parent::getContentArguments(), $podcastFormatEpisodeFieldArguments);
    }
}
