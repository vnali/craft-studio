<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;
use vnali\studio\elements\Podcast;

class PodcastEpisodeSettings extends Model
{
    public ?bool $enable = null;
    public ?bool $genreImportCheck = null;
    public ?string $genreImportOption = null;
    public ?string $imageOption = null;
    public int $podcastId;
    public ?string $pubDateOption = null;
    public $defaultGenres = [];
    public $defaultImage = [];
    public $defaultPubDate;
    public $volumes = [];

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['podcastId'], 'required', 'on' => 'import'];
        $rules[] = [['podcastId'], function($attribute, $params, $validator) {
            $podcast = Podcast::find()->status(null)->where(['studio_podcast.id' => $this->podcastId])->one();
            if (!$podcast) {
                $this->addError($attribute, 'The podcast is not valid');
            }
        }, 'skipOnEmpty' => true, 'on' => 'import'];
        $rules[] = [['enable'], 'in', 'range' => [0, 1]];
        $rules[] = [['defaultGenres', 'defaultImage', 'volumes'], 'default', 'value' => []];
        return $rules;
    }
}
