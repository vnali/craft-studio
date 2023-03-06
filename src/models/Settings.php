<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;

class Settings extends Model
{
    public ?bool $checkAccessToVolumes = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['checkAccessToVolumes'], 'in', 'range' => [0, 1]];
        return $rules;
    }
}
