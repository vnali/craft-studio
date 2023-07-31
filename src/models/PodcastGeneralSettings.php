<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;
use craft\validators\SiteIdValidator;
use vnali\studio\elements\Podcast;

class PodcastGeneralSettings extends Model
{
    /**
     * @var bool|null If podcast RSS should be available for all without checking permission
     */
    public ?bool $allowAllToSeeRSS = null;

    /**
     * @var bool|null If OP3 should be enabled for podcast
     */
    public ?bool $enableOP3 = null;

    /**
     * @var bool|null If podcast RSS should be published
     */
    public ?bool $publishRSS = null;

    /**
     * @var int Podcast Id to set general settings for
     */
    public int $podcastId;

    /**
     * @var int Site id to set general settings for
     */
    public int $siteId;

    public mixed $dateUpdated = null;

    public ?int $userId = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['podcastId'], 'required'];
        $rules[] = [['podcastId'], function ($attribute, $params, $validator) {
            $podcast = Podcast::find()->siteId($this->siteId)->status(null)->where(['studio_podcast.id' => $this->podcastId])->one();
            if (!$podcast) {
                $this->addError($attribute, 'The podcast is not valid');
            }
        }, 'skipOnEmpty' => true];
        $rules[] = [['publishRSS', 'allowAllToSeeRSS', 'enableOP3'], 'in', 'range' => [0, 1]];
        $rules[] = [['siteId'], SiteIdValidator::class];
        return $rules;
    }
}
