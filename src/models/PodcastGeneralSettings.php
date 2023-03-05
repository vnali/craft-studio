<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;
use vnali\studio\elements\Podcast;

class PodcastGeneralSettings extends Model
{
    public ?bool $publishRSS = null;
    public int $podcastId;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['podcastId'], 'required'];
        $rules[] = [['podcastId'], function($attribute, $params, $validator) {
            $podcast = Podcast::find()->status(null)->where(['studio_podcast.id' => $this->podcastId])->one();
            if (!$podcast) {
                $this->addError($attribute, 'The podcast is not valid');
            }
        }, 'skipOnEmpty' => true];
        $rules[] = [['publishRSS'], 'in', 'range' => [0, 1]];
        return $rules;
    }
}
