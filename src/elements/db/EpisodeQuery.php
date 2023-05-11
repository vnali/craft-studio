<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class EpisodeQuery extends ElementQuery
{
    public $uploaderId;
    public $podcastId;
    public $episodeSeason;
    public $episodeNumber;
    public $episodeType;
    public $duration;
    public mixed $siteId = null;
    public mixed $id = null;
    public ?bool $blocked = null;
    public ?bool $explicit = null;
    public ?bool $published = null;

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

    public function podcastId($value)
    {
        $this->podcastId = $value;
        return $this;
    }

    public function blocked(?bool $value = true): self
    {
        $this->blocked = $value;
        return $this;
    }

    public function explicit(?bool $value = true): self
    {
        $this->explicit = $value;
        return $this;
    }

    public function published(?bool $value = true): self
    {
        $this->published = $value;
        return $this;
    }

    public function episodeSeason($value)
    {
        $this->episodeSeason = $value;
        return $this;
    }

    public function episodeNumber($value)
    {
        $this->episodeNumber = $value;
        return $this;
    }

    public function episodeType($value)
    {
        $this->episodeType = $value;
        return $this;
    }

    public function duration($value)
    {
        $this->duration = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('studio_episode');

        $this->query->select([
            'studio_episode.id',
            'studio_episode.podcastId',
            'studio_i18n.duration',
            'studio_episode.uploaderId',
            'studio_i18n.episodeBlock',
            'studio_i18n.episodeExplicit',
            'studio_i18n.episodeGUID',
            'studio_i18n.episodeNumber',
            'studio_i18n.episodeSeason',
            'studio_i18n.episodeType',
            'studio_i18n.publishOnRSS',
        ]);

        $this->query->innerJoin(['studio_i18n' => '{{%studio_i18n}}'], '[[studio_i18n.elementId]] = [[studio_episode.id]] and studio_i18n.siteId=subquery.siteId');
        // To order by duration works
        $this->subQuery->innerJoin(['studio_i18n' => '{{%studio_i18n}}'], '[[studio_i18n.elementId]] = [[studio_episode.id]]');
        // Make sure podcast element is available for this site
        $this->query->innerJoin(['elements_sites_podcast' => '{{%elements_sites}}'], '[[studio_episode.podcastId]] = [[elements_sites_podcast.elementId]] and elements_sites_podcast.siteId=subquery.siteId');
        
        if ($this->uploaderId) {
            $this->subQuery->andWhere(Db::parseParam('studio_ad.uploaderId', $this->uploaderId));
        }

        if ($this->podcastId) {
            $this->subQuery->andWhere(Db::parseParam('studio_episode.podcastId', $this->podcastId));
        }

        if ($this->siteId) {
            $this->query->andWhere(Db::parseParam('studio_i18n.siteId', $this->siteId));
            // to prevent duplicate records on siteId(*) we need to check site Id
            $this->subQuery->andWhere('studio_i18n.siteId = elements_sites.siteId');
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.siteId', $this->siteId));
        }

        if ($this->id) {
            $this->subQuery->andWhere(Db::parseParam('studio_episode.id', $this->id));
        }

        if ($this->blocked !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('studio_i18n.episodeBlock', $this->blocked, false));
        }

        if ($this->explicit !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('studio_i18n.episodeExplicit', $this->explicit, false));
        }

        if ($this->published !== null) {
            $this->subQuery->andWhere(Db::parseBooleanParam('studio_i18n.publishOnRSS', $this->published, false));
        }

        if ($this->episodeSeason) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.episodeSeason', $this->episodeSeason));
        }

        if ($this->episodeNumber) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.episodeNumber', $this->episodeNumber));
        }

        if ($this->episodeType) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.episodeType', $this->episodeType));
        }

        if ($this->duration) {
            $this->subQuery->andWhere(Db::parseParam('studio_i18n.duration', $this->duration));
        }

        $this->subQuery->addSelect(['elements_sites.siteId']);

        return parent::beforePrepare();
    }
}
