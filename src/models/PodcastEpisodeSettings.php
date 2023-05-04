<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use Craft;
use craft\base\Model;
use craft\db\Table;
use craft\helpers\Db;
use craft\validators\SiteIdValidator;
use vnali\studio\elements\Podcast;

class PodcastEpisodeSettings extends Model
{
    /**
     * @var bool|null If genre should be checked with current values
     */
    public ?bool $genreImportCheck = null;

    /**
     * @var string|null if we should use default or meta genres
     */
    public ?string $genreImportOption = null;

    /**
     * @var string|null If we should use default or meta images
     */
    public ?string $imageOption = null;

    /**
     * @var int Podcast id to set episode settings for
     */
    public int $podcastId;

    /**
     * @var string|null How to use pub date option
     */
    public ?string $pubDateOption = null;

    /**
     * @var int[]|string Default genres to be used
     */
    public array|string $defaultGenres = [];

    /**
     * @var int[]|string Default images to be used
     */
    public array|string $defaultImage = [];

    /**
     * @var mixed Default pubDate to be used
     */
    public mixed $defaultPubDate = null;

    /**
     * @var int Site to set episode settings for
     */
    public int $siteId;

    public mixed $dateUpdated = null;

    public ?int $userId = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['podcastId'], 'required'];
        $rules[] = [['podcastId'], function($attribute, $params, $validator) {
            $podcast = Podcast::find()->siteId($this->siteId)->status(null)->where(['studio_podcast.id' => $this->podcastId])->one();
            if (!$podcast) {
                $this->addError($attribute, 'The podcast is not valid');
            }
        }, 'skipOnEmpty' => true];
        $rules[] = [['defaultGenres', 'defaultImage'], 'default', 'value' => []];
        $rules[] = [['siteId'], SiteIdValidator::class];
        $rules[] = [['siteId'], function($attribute, $params, $validator) {
            if (Craft::$app->getIsMultiSite()) {
                $currentUser = Craft::$app->getUser()->getIdentity();
                // Allow only sites that user has access
                if ($this->$attribute) {
                    $siteUid = Db::uidById(Table::SITES, $this->$attribute);
                    if (!$currentUser->can('editSite:' . $siteUid)) {
                        $this->addError($attribute, 'The user can not access site');
                    }
                } else {
                    $this->addError($attribute, 'The site should be specified');
                }
            }
        }, 'skipOnEmpty' => false];
        return $rules;
    }
}
