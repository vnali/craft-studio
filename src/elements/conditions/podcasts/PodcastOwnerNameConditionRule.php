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

class PodcastOwnerNameConditionRule extends BaseTextConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('studio', 'Podcast Owner Name');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['ownerName'];
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var PodcastQuery $query */
        $query->ownerName($this->paramValue());
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        /** @var Podcast $element */
        return $this->matchValue($element->ownerName);
    }
}
