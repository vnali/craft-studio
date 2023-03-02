<?php

namespace vnali\studio\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;

use vnali\studio\gql\arguments\elements\PodcastArguments;
use vnali\studio\gql\helpers\GqlHelper;
use vnali\studio\gql\interfaces\elements\PodcastInterface;
use vnali\studio\gql\resolvers\elements\PodcastResolver;

class PodcastQuery extends Query
{
    // Public Methods
    // =========================================================================
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canQueryPodcastFormats()) {
            return [];
        }

        return [
            'podcasts' => [
                'type' => Type::listOf(PodcastInterface::getType()),
                'args' => PodcastArguments::getArguments(),
                'resolve' => PodcastResolver::class . '::resolve',
                'description' => 'This query is used to query for podcasts.',
            ],
            'podcast' => [
                'type' => PodcastInterface::getType(),
                'args' => PodcastArguments::getArguments(),
                'resolve' => PodcastResolver::class . '::resolveOne',
                'description' => 'This query is used to query for podcasts.',
            ],
        ];
    }
}
