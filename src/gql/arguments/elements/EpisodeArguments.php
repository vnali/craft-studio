<?php

namespace vnali\studio\gql\arguments\elements;

use Craft;
use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;
use vnali\studio\elements\Episode;
use vnali\studio\Studio;

class EpisodeArguments extends ElementArguments
{
    // Public Methods
    // =========================================================================
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), self::getContentArguments(), [
            'episodeSeason' => [
                'name' => 'episodeSeason',
                'type' => Type::INT(),
                'description' => 'Episode Season',
            ],
            'episodeType' => [
                'name' => 'episodeType',
                'type' => Type::STRING(),
                'description' => 'Episode type',
            ],
            'blocked' => [
                'name' => 'blocked',
                'type' => Type::boolean(),
                'description' => 'Episode block',
            ],
            'explicit' => [
                'name' => 'explicit',
                'type' => Type::boolean(),
                'description' => 'Episode Explicit',
            ],
            'rss' => [
                'name' => 'rss',
                'type' => Type::boolean(),
                'description' => 'Publish on RSS',
            ],
            'podcastId' => [
                'name' => 'podcastId',
                'type' => Type::INT(),
                'description' => 'Podcast ID',
            ],
            'uploaderId' => [
                'name' => 'uploaderId',
                'type' => Type::INT(),
                'description' => 'Uploader ID',
            ],
        ]);
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
