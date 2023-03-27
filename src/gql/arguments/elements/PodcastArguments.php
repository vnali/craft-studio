<?php

namespace vnali\studio\gql\arguments\elements;

use Craft;
use craft\gql\base\ElementArguments;
use GraphQL\Type\Definition\Type;
use vnali\studio\elements\Podcast;
use vnali\studio\Studio;

class PodcastArguments extends ElementArguments
{
    // Public Methods
    // =========================================================================
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), self::getContentArguments(), [
            'podcastFormat' => [
                'name' => 'podcastFormat',
                'type' => Type::string(),
                'description' => 'Narrows the query results based on the podcast format handles the podcasts belong to.',
            ],
            'podcastFormatId' => [
                'name' => 'podcastFormatId',
                'type' => Type::int(),
                'description' => 'Narrows the query results based on the podcast formats the podcasts belong to, per the podcast format’ IDs.',
            ],
        ]);
    }

    /**
     * @inheritdoc
     */
    public static function getContentArguments(): array
    {
        $podcastFormats = Studio::$plugin->podcastFormats->getAllPodcastFormats();
        $podcastFormatFieldArguments = Craft::$app->getGql()->getContentArguments($podcastFormats, Podcast::class);
        return array_merge(parent::getContentArguments(), $podcastFormatFieldArguments);
    }
}
