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

class ImportEpisodeRSS extends Model
{
    /**
     * @var string|null RSS URL
     */
    public ?string $rssURL = null;

    /**
     * @var int|null limit
     */
    public ?int $limit = null;

    /**
     * @var bool Don't import main asset
     */
    public bool $ignoreMainAsset = false;

    /**
     * @var bool Don't import asset image
     */
    public bool $ignoreImageAsset = false;

    /**
     * @var int[]|string|null siteIds
     */
    public array|string|null $siteIds = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['rssURL', 'siteIds'], 'required'];
        $rules[] = [['rssURL'], 'url'];
        $rules[] = [['limit'], 'integer', 'min' => 1];
        $rules[] = [['ignoreMainAsset', 'ignoreImageAsset'], 'in', 'range' => [0, 1]];
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
        return $rules;
    }
}
