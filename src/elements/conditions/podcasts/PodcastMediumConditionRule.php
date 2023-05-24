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
            ['label' => 'Podcast', 'value' => 'podcast'],
            ['label' => 'Music', 'value' => 'music'],
            ['label' => 'Video', 'value' => 'video'],
            ['label' => 'Film', 'value' => 'film'],
            ['label' => 'Audiobook', 'value' => 'audiobook'],
            ['label' => 'Newsletter', 'value' => 'newsletter'],
            ['label' => 'Blog', 'value' => 'blog'],
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
