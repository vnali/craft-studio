<?php

namespace vnali\studio\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;

use vnali\studio\gql\arguments\elements\EpisodeArguments;
use vnali\studio\gql\helpers\GqlHelper;
use vnali\studio\gql\interfaces\elements\EpisodeInterface;
use vnali\studio\gql\resolvers\elements\EpisodeResolver;

class EpisodeQuery extends Query
{
    // Public Methods
    // =========================================================================
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canQueryPodcastFormats()) {
            return [];
        }

        return [
            'episodes' => [
                'type' => Type::listOf(EpisodeInterface::getType()),
                'args' => EpisodeArguments::getArguments(),
                'resolve' => EpisodeResolver::class . '::resolve',
                'description' => 'This query is used to query for episodes.',
            ],
            'episode' => [
                'type' => EpisodeInterface::getType(),
                'args' => EpisodeArguments::getArguments(),
                'resolve' => EpisodeResolver::class . '::resolveOne',
                'description' => 'This query is used to query for episodes.',
            ],
        ];
    }
}
