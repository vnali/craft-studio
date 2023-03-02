<?php

namespace vnali\studio\gql\resolvers\elements;

use craft\gql\base\ElementResolver;
use craft\helpers\Db;
use Illuminate\Support\Collection;
use vnali\studio\elements\Podcast as PodcastElement;
use vnali\studio\gql\helpers\GqlHelper;

class PodcastResolver extends ElementResolver
{
    // Public Methods
    // =========================================================================
    public static function prepareQuery(mixed $source, array $arguments, $fieldName = null): mixed
    {
        if ($source === null) {
            $query = PodcastElement::find();
        } else {
            $query = $source->$fieldName;
        }

        if (is_array($query)) {
            return $query;
        }

        foreach ($arguments as $key => $value) {
            if (method_exists($query, $key)) {
                $query->$key($value);
            } elseif (property_exists($query, $key)) {
                $query->$key = $value;
            } else {
                // Catch custom field queries
                $query->$key($value);
            }
        }

        $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read');

        if (!GqlHelper::canQueryPodcastFormats()) {
            return Collection::empty();
        }

        if (!GqlHelper::canSchema('podcastFormats.all')) {
            $query->andWhere(['in', 'podcastFormatId', array_values(Db::idsByUids('{{%studio_podcastFormat}}', $pairs['podcastFormats']))]);
        }

        return $query;
    }
}
