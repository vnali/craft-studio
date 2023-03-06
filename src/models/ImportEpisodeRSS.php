<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;

class ImportEpisodeRSS extends Model
{
    /**
     * @var string import From RSS
     */
    public string $importFromRSS;

    /**
     * @var int|null limit
    */
    public ?int $limit = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['importFromRSS'], 'required', 'on' => 'import'];
        $rules[] = [['importFromRSS'], 'url', 'on' => 'import'];
        $rules[] = [['limit'], 'integer', 'min' => 1, 'on' => 'import'];
        return $rules;
    }
}
