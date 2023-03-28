<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;

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

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['rssURL'], 'required', 'on' => 'import'];
        $rules[] = [['rssURL'], 'url', 'on' => 'import'];
        $rules[] = [['limit'], 'integer', 'min' => 1, 'on' => 'import'];
        $rules[] = [['ignoreMainAsset', 'ignoreImageAsset'], 'in', 'range' => [0, 1], 'on' => 'import'];
        return $rules;
    }
}
