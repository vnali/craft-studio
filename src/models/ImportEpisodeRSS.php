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
     * @var string RSS URL
     */
    public string $rssURL;

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
     * @var int[]|string|null SiteIds
     */
    public array|string|null $siteIds = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['rssURL'], 'required', 'on' => 'import'];
        $rules[] = [['rssURL'], 'url', 'on' => 'import'];
        $rules[] = [['limit'], 'integer', 'min' => 1, 'on' => 'import'];
        $rules[] = [['ignoreMainAsset', 'ignoreImageAsset'], 'in', 'range' => [0, 1], 'on' => 'import'];
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
        }, 'skipOnEmpty' => false, 'on' => 'import'];
        return $rules;
    }
}
