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
    public ?bool $genreImportCheck = null;
    public ?string $genreImportOption = null;
    public ?string $imageOption = null;
    public int $podcastId;
    public ?string $pubDateOption = null;
    public $defaultGenres = [];
    public $defaultImage = [];
    public $defaultPubDate;
    public $siteId = null;

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
        $rules[] = [['siteId'], SiteIdValidator::class];
        $rules[] = [['siteId'], function($attribute, $params, $validator) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            // Allow only sites that user has access
            if ($this->$attribute) {
                $siteUid = Db::uidById(Table::SITES, $this->$attribute);
                if (Craft::$app->getIsMultiSite() && !$currentUser->can('editSite:' . $siteUid)) {
                    $this->addError($attribute, 'The user can not access site');
                }
            } else {
                $this->addError($attribute, 'The site should be specified');
            }
        }, 'skipOnEmpty' => false];
        return $rules;
    }
}
