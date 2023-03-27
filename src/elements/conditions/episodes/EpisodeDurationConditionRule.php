<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\conditions\episodes;

use Craft;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

use vnali\studio\base\conditions\BaseDurationConditionRule;
use vnali\studio\elements\db\EpisodeQuery;
use vnali\studio\elements\Episode;

class EpisodeDurationConditionRule extends BaseDurationConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('studio', 'Episode Duration');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['duration'];
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var EpisodeQuery $query */
        $query->duration($this->paramValue());
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        /** @var Episode $element */
        return $this->matchValue($element->duration);
    }
}
