<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;
use vnali\studio\elements\Podcast;

class PodcastEpisodeSettings extends Model
{
    public $categoryOnImport = [];
    public ?bool $enable = null;
    public ?bool $forceImage = null;
    public ?bool $forcePubDate = null;
    public ?bool $genreCheck = null;
    public ?bool $genreImportCheck = null;
    public ?string $genreImportOption = null;
    public $genreOnImport = [];
    public $imageOnImport = [];
    public int $podcastId;
    public $pubDateOnImport;
    public $tagOnImport = [];
    public $volumesImport = [];

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
        return $rules;
    }
}
