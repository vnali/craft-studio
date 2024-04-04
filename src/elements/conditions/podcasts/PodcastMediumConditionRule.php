<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\conditions\podcasts;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;

use vnali\studio\elements\db\PodcastQuery;
use vnali\studio\elements\Podcast;

class PodcastMediumConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('studio', 'Podcast Medium');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['medium'];
    }

    /**
     * @inheritdoc
     */
    protected function options(): array
    {
        $options = [
            ['label' => Craft::t('studio', 'Podcast'), 'value' => 'podcast'],
            ['label' => Craft::t('studio', 'Music'), 'value' => 'music'],
            ['label' => Craft::t('studio', 'Video'), 'value' => 'video'],
            ['label' => Craft::t('studio', 'Film'), 'value' => 'film'],
            ['label' => Craft::t('studio', 'Audiobook'), 'value' => 'audiobook'],
            ['label' => Craft::t('studio', 'Newsletter'), 'value' => 'newsletter'],
            ['label' => Craft::t('studio', 'Blog'), 'value' => 'blog'],
        ];
        return $options;
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        /** @var PodcastQuery $query */
        $query->medium($this->paramValue(fn($medium) => $medium));
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        /** @var Podcast $element */
        return $this->matchValue($element->medium);
    }
}
