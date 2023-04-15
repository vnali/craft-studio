<?php

namespace vnali\studio\gql\helpers;

use craft\helpers\Gql;

class GqlHelper extends Gql
{
    // Public Methods
    // =========================================================================

    public static function canQueryPodcastFormats(): bool
    {
        $allowedEntities = self::extractAllowedEntitiesFromSchema();

        return isset($allowedEntities['podcastFormats']);
    }
}
