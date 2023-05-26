<?php

/**
 * @copyright Copyright (c) vnali
 */

 namespace vnali\studio\elements\conditions\episodes;

use Craft;
use craft\base\conditions\BaseNumberConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

use vnali\studio\elements\db\EpisodeQuery;
use vnali\studio\elements\Episode;

class EpisodeNumberConditionRule extends BaseNumberConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('studio', 'Episode number');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['episodeNumber'];
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var EpisodeQuery $query */
        $query->episodeNumber($this->paramValue());
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        /** @var Episode $element */
        return $this->matchValue($element->episodeNumber);
    }
}
