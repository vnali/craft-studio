<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\twig;

use Craft;
use vnali\studio\elements\db\EpisodeQuery;
use vnali\studio\elements\db\PodcastQuery;
use vnali\studio\elements\Episode;
use vnali\studio\elements\Podcast;
use vnali\studio\Studio;

use yii\base\Behavior;

class CraftVariableBehavior extends Behavior
{
    /**
     * @var Studio
     */
    public Studio $studio;

    public function init(): void
    {
        parent::init();

        $this->studio = Studio::getInstance();
    }

    /**
     * Returns a new EpisodeQuery instance.
     *
     * @param array $criteria
     * @return EpisodeQuery
     */
    public function episodes(array $criteria = null): EpisodeQuery
    {
        $query = Episode::find();
        if ($criteria) {
            Craft::configure($query, $criteria);
        }
        return $query;
    }

    /**
     * Returns a new PodcastQuery instance.
     *
     * @param array $criteria
     * @return PodcastQuery
     */
    public function podcasts(array $criteria = null): PodcastQuery
    {
        $query = Podcast::find();
        if ($criteria) {
            Craft::configure($query, $criteria);
        }
        return $query;
    }
}
