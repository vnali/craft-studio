<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;
use vnali\studio\elements\Podcast;

class PodcastEpisodeSettings extends Model
{
    public ?bool $genreImportCheck = null;
    public ?string $genreImportOption = null;
    public ?string $imageOption = null;
    public int $podcastId;
    public ?string $pubDateOption = null;
    public $defaultGenres = [];
    public $defaultImage = [];
    public $defaultPubDate;

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
        $rules[] = [['defaultGenres', 'defaultImage'], 'default', 'value' => []];
        return $rules;
    }
}
