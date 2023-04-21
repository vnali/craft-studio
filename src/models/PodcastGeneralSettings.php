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
    public ?bool $allowAllToSeeRSS = null;
    public ?bool $publishRSS = null;
    public int $podcastId;
    public int $siteId;

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
        $rules[] = [['publishRSS', 'allowAllToSeeRSS'], 'in', 'range' => [0, 1]];
        $rules[] = [['siteId'], SiteIdValidator::class];
        return $rules;
    }
}
