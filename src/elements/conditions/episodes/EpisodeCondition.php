<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\conditions\episodes;

use craft\elements\conditions\ElementCondition;

class EpisodeCondition extends ElementCondition
{
    /**
     * @inheritdoc
     */
    protected function selectableConditionRules(): array
    {
        return array_merge(parent::selectableConditionRules(), [
            EpisodeBlockConditionRule::class,
            EpisodeExplicitConditionRule::class,
            EpisodeTypeConditionRule::class,
            EpisodeDurationConditionRule::class,
            EpisodeSeasonConditionRule::class,
            EpisodeNumberConditionRule::class,
            EpisodePublishConditionRule::class,
            EpisodeSeasonNameConditionRule::class,
        ]);
    }
}
