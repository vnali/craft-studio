<?php

namespace vnali\studio\gql\resolvers\elements;

use craft\gql\base\ElementResolver;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use Illuminate\Support\Collection;
use vnali\studio\elements\Episode as EpisodeElement;
use vnali\studio\elements\Podcast;
use vnali\studio\gql\helpers\GqlHelper;

class EpisodeResolver extends ElementResolver
{
    // Public Methods
    // =========================================================================
    public static function prepareQuery(mixed $source, array $arguments, $fieldName = null): mixed
    {
        if ($source === null) {
            $query = EpisodeElement::find();
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
            $podcastFormatIds = array_values(Db::idsByUids('{{%studio_podcastFormat}}', $pairs['podcastFormats']));
            $podcasts = Podcast::find()->podcastFormatId($podcastFormatIds)->all();
            $podcastIds = ArrayHelper::getColumn($podcasts, 'id');
            $query->andWhere(['in', 'podcastId', $podcastIds]);
        }

        return $query;
    }
}
