<?php

/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class PodcastQuery extends ElementQuery
{
    public mixed $uploaderId = null;
    public ?string $copyright = null;
    public mixed $podcastFormatId = null;
    public ?string $podcastFormat = null;
    public ?string $ownerEmail = null;
    public ?string $ownerName = null;
    public ?string $authorName = null;
    public mixed $siteId = null;
    public mixed $id = null;
    public ?bool $blocked = null;
    public ?bool $locked = null;
    public ?bool $completed = null;
    public ?bool $explicit = null;
    public ?bool $podcastIsNewFeedUrl = null;
    public string|array|null $medium = null;

    public function blocked(?bool $value = true): self
    {
        $this->blocked = $value;
        return $this;
    }

    public function locked(?bool $value = true): self
    {
        $this->locked = $value;
        return $this;
    }

    public function completed(?bool $value = true): self
    {
        $this->completed = $value;
        return $this;
    }

    public function explicit(?bool $value = true): self
    {
        $this->explicit = $value;
        return $this;
    }

    public function podcastIsNewFeedUrl(?bool $value = true): self
    {
        $this->podcastIsNewFeedUrl = $value;
        return $this;
    }

    public function id(mixed $value): \craft\elements\db\ElementQuery
    {
        $this->id = $value;
        return $this;
    }

    public function uploaderId($value)
    {
        $this->uploaderId = $value;
        return $this;
    }

    public function podcastFormatId($value)
    {
        $this->podcastFormatId = $value;
        return $this;
    }

    public function podcastFormat(string $value): self
    {
        $podcastFormatId = (new Query())
            ->select(['id'])
            ->from('{{%studio_podcastFormat}}')
            ->where(Db::parseParam('handle', $value))
            ->andWhere(['dateDeleted' => null])
            ->scalar();

        if ($podcastFormatId) {
            $this->podcastFormatId = $podcastFormatId;
        } else {
            $this->podcastFormatId = $value;
        }

        return $this;
    }

    public function ownerEmail($value)
    {
        $this->ownerEmail = $value;
        return $this;
    }

    public function ownerName($value)
    {
        $this->ownerName = $value;
        return $this;
    }

    public function authorName($value)
    {
        $this->authorName = $value;
        return $this;
    }

    public function copyright($value)
    {
        $this->copyright = $value;
        return $this;
    }

    public function medium($value)
    {
        $this->medium = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('studio_podcast');

        $this->query->select([
            'studio_podcast.id',
            'studio_podcast.uid',
            'studio_podcast.podcastFormatId',
            'studio_i18n.podcastBlock',
            'studio_i18n.podcastLink',
            'studio_i18n.podcastComplete',
            'studio_i18n.podcastExplicit',
            'studio_i18n.podcastRedirectTo',
            'studio_i18n.podcastIsNewFeedUrl',
            'studio_i18n.authorName',
            'studio_i18n.ownerName',
            'studio_i18n.ownerEmail',
            'studio_i18n.podcastType',
            'studio_i18n.copyright',
            'studio_podcast.uploaderId',
            'studio_i18n.medium',
            'studio_i18n.locked',
            'studio_i18n.podcastGUID',
        ]);

        $this->query->innerJoin(['studio_i18n' => '{{%studio_i18n}}'], '[[studio_i18n.elementId]] = [[studio_podcast.id]] and [[studio_i18n.siteId]]=subquery.siteId');

        $this->subQuery->innerJoin(['studio_i18n' => '{{%studio_i18n}}'], '[[studio_i18n.elementId]] = [[studio_podcast.id]]');

        $this->subQuery->innerJoin(['studio_podcastFormat' => '{{%studio_podcastFormat}}'], '[[studio_podcastFormat.id]] = [[studio_podcast.podcastFormatId]]');

        $this->subQuery->andWhere(['studio_podcastFormat.dateDeleted' => null]);

        if ($this->podcastFormatId) {
            $this->subQuery->andWhere(Db::parseParam('studio_podcast.podcastFormatId', $this->podcastFormatId));
        }

        if ($this->siteId) {
            $this->query->andWhere(Db::parseParam('[[studio_i18n.siteId]]', $this->siteId));
            // to prevent duplicate records on siteId(*) we need to check site Id
            $this->subQuery->andWhere('[[studio_i18n.siteId]] = elements_sites.siteId');
            $this->subQuery->andWhere(Db::parseParam('[[studio_i18n.siteId]]', $this->siteId));
        }

        if ($this->id) {
            $this->subQuery->andWhere(Db::parseParam('studio_podcast.id', $this->id));
        }

        if ($this->blocked !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('studio_i18n.podcastBlock', $this->blocked, false));
        }

        if ($this->locked !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('studio_i18n.locked', $this->locked, false));
        }

        if ($this->completed !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('studio_i18n.podcastComplete', $this->completed, false));
        }
        
        if ($this->explicit !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('studio_i18n.podcastExplicit', $this->explicit, false));
        }

        if ($this->podcastIsNewFeedUrl !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('studio_i18n.podcastIsNewFeedUrl', $this->podcastIsNewFeedUrl, false));
        }

        if ($this->ownerEmail) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.ownerEmail', $this->ownerEmail));
        }

        if ($this->ownerName) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.ownerName', $this->ownerName));
        }

        if ($this->authorName) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.authorName', $this->authorName));
        }

        if ($this->copyright) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.copyright', $this->copyright));
        }

        if ($this->medium) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.medium', $this->medium));
        }

        $this->subQuery->addSelect(['elements_sites.siteId']);

        return parent::beforePrepare();
    }
}
