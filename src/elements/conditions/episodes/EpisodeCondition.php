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
    protected function conditionRuleTypes(): array
    {
        return array_merge(parent::conditionRuleTypes(), [
            EpisodeBlockConditionRule::class,
            EpisodeExplicitConditionRule::class,
            EpisodeTypeConditionRule::class,
            EpisodeDurationConditionRule::class,
            EpisodeSeasonConditionRule::class,
            EpisodeNumberConditionRule::class,
        ]);
    }
}
