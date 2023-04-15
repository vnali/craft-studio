<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\conditions\episodes;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

use vnali\studio\elements\db\EpisodeQuery;
use vnali\studio\elements\Episode;

class EpisodeTypeConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('studio', 'Episode Type');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['episodeType'];
    }

    /**
     * @inheritdoc
     */
    protected function options(): array
    {
        $options = [
            ['label' => 'full', 'value' => 'full'],
            ['label' => 'trailer', 'value' => 'trailer'],
            ['label' => 'bonus', 'value' => 'bonus'],
        ];
        return $options;
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var EpisodeQuery $query */
        $query->episodeType($this->paramValue(fn($episodeType) => $episodeType));
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        /** @var Episode $element */
        return $this->matchValue($element->episodeType);
    }
}
