<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\conditions\podcasts;

use Craft;
use craft\base\conditions\BaseLightswitchConditionRule;

use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use vnali\studio\elements\db\PodcastQuery;
use vnali\studio\elements\Podcast;

class PodcastIsBlockConditionRule extends BaseLightswitchConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('studio', 'Podcast Is Blocked');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['blocked'];
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var PodcastQuery $query */
        $query->blocked($this->value);
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        /** @var Podcast $element */
        return $this->matchValue($element->podcastBlock);
    }
}
