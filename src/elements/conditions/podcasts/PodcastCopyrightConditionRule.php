<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\conditions\podcasts;

use Craft;
use craft\base\conditions\BaseTextConditionRule;

use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use vnali\studio\elements\db\PodcastQuery;
use vnali\studio\elements\Podcast;

class PodcastCopyrightConditionRule extends BaseTextConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('studio', 'Podcast Copyright');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['copyright'];
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var PodcastQuery $query */
        $query->copyright($this->paramValue());
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        /** @var Podcast $element */
        return $this->matchValue($element->copyright);
    }
}
