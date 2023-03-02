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
use craft\helpers\ArrayHelper;

use vnali\studio\elements\db\EpisodeQuery;
use vnali\studio\elements\Episode;
use vnali\studio\Studio;

class PodcastConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('studio', 'Podcast');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return ['podcastId'];
    }

    /**
     * @inheritdoc
     */
    protected function options(): array
    {
        $podcasts = Studio::$plugin->podcasts->getAllPodcasts();
        return ArrayHelper::map($podcasts, 'uid', 'title');
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        $podcasts = Studio::$plugin->podcasts;
        /** @var EpisodeQuery $query */
        $query->podcastId($this->paramValue(fn($uid) => $podcasts->getPodcastByUid($uid)->id ?? null));
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        /** @var Episode $element */
        return $this->matchValue($element->getPodcast()->uid);
    }
}
