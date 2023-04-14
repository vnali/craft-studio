<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use Craft;
use craft\base\Model;
use craft\validators\SiteIdValidator;
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
    /**
     * @var int[]|string|null SiteIds
     */
    public array|string|null $siteIds = null;

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
        $rules[] = [['defaultGenres', 'defaultImage'], 'default', 'value' => []];
        $rules[] = [['siteIds'], 'each', 'rule' => [SiteIdValidator::class]];
        $rules[] = [['siteIds'], function($attribute, $params, $validator) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            // Allow only sites that user has access
            if (is_array($this->$attribute)) {
                foreach ($this->$attribute as $key => $siteId) {
                    if (!$currentUser->can('editSite:' . $siteId)) {
                        $this->addError($attribute, 'The user can not access site');
                        break;
                    }
                }
            } elseif (!$this->$attribute) {
                $this->addError($attribute, 'The sites should be specified');
            }
        }, 'skipOnEmpty' => false, 'on' => 'import'];
        $rules[] = [['volumes'], function($attribute, $params, $validator) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            // Allow only sites that user has access
            if (is_array($this->$attribute)) {
                foreach ($this->$attribute as $key => $volumeId) {
                    if (!$currentUser->can('saveAssets:' . $volumeId)) {
                        $this->addError($attribute, 'The user can not access the volume');
                        break;
                    }
                }
            } elseif (!$this->$attribute) {
                $this->addError($attribute, 'The volumes should be specified');
            }
        }, 'skipOnEmpty' => false, 'on' => 'import'];
        return $rules;
    }
}
