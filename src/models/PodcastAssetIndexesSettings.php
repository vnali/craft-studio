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

class PodcastAssetIndexesSettings extends Model
{
    /**
     * @var bool|null If this setting for the podcast is checked when asset indexes happen
     */
    public ?bool $enable = null;

    /**
     * @var integer Podcast Id to add indexed assets
     */
    public int $podcastId;

    /**
     * @var int[]|string Volumes to index assets for importing episodes
     */
    public array|string $volumes = [];

    /**
     * @var int[]|string|null Site Ids to save/propagate to
     */
    public array|string|null $siteIds = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['siteIds', 'volumes'], 'required'];
        $rules[] = [['enable'], 'in', 'range' => [0, 1]];
        $rules[] = [['siteIds'], 'each', 'rule' => [SiteIdValidator::class]];
        $rules[] = [['siteIds'], function($attribute, $params, $validator) {
            if (Craft::$app->getIsMultiSite()) {
                $currentUser = Craft::$app->getUser()->getIdentity();
                // Allow only sites that user has access
                if (is_array($this->$attribute)) {
                    foreach ($this->$attribute as $key => $siteId) {
                        $siteUid = Db::uidById(Table::SITES, $siteId);
                        if (!$currentUser->can('editSite:' . $siteUid)) {
                            $this->addError($attribute, 'The user can not access site');
                            break;
                        }
                    }
                }
            }
        }, 'skipOnEmpty' => false];
        $rules[] = [['volumes'], function($attribute, $params, $validator) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            // Allow only sites that user has access
            if (is_array($this->$attribute)) {
                foreach ($this->$attribute as $key => $volumeId) {
                    $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);
                    if (!$currentUser->can('viewAssets:' . $volume->uid)) {
                        $this->addError($attribute, 'The user can not access the volume');
                        break;
                    }
                }
            }
        }, 'skipOnEmpty' => false];
        return $rules;
    }
}
