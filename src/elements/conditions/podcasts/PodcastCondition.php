<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\conditions\podcasts;

use craft\elements\conditions\ElementCondition;

class PodcastCondition extends ElementCondition
{
    /**
     * @inheritdoc
     */
    protected function conditionRuleTypes(): array
    {
        return array_merge(parent::conditionRuleTypes(), [
            PodcastIsBlockConditionRule::class,
            PodcastIsExplicitConditionRule::class,
            PodcastIsCompleteConditionRule::class,
            PodcastIsNewFeedUrlRule::class,
            podcastOwnerEmailConditionRule::class,
            podcastOwnerNameConditionRule::class,
            podcastAuthorNameConditionRule::class,
            podcastCopyrightConditionRule::class,
        ]);
    }
}
