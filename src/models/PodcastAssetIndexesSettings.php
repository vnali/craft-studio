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
    public ?bool $enable = null;
    public int $podcastId;
    public $volumes = [];
    /**
     * @var int[]|string|null SiteIds
     */
    public array|string|null $siteIds = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['enable'], 'in', 'range' => [0, 1]];
        $rules[] = [['siteIds'], 'each', 'rule' => [SiteIdValidator::class]];
        $rules[] = [['siteIds'], function($attribute, $params, $validator) {
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
            } elseif (!$this->$attribute) {
                $this->addError($attribute, 'The sites should be specified');
            }
        }, 'skipOnEmpty' => false];
        $rules[] = [['volumes'], function($attribute, $params, $validator) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            // Allow only sites that user has access
            if (is_array($this->$attribute)) {
                foreach ($this->$attribute as $key => $volumeId) {
                    $volume = Craft::$app->volumes->getVolumeById($volumeId);
                    // TODO: view volume should be enough in this step
                    if (!$currentUser->can('saveAssets:' . $volume->uid)) {
                        $this->addError($attribute, 'The user can not access the volume');
                        break;
                    }
                }
            } elseif (!$this->$attribute) {
                $this->addError($attribute, 'The volumes should be specified');
            }
        }, 'skipOnEmpty' => false];
        return $rules;
    }
}
