<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Date;
use craft\fields\Entries;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Tags;
use craft\fields\Url;

class Mapping extends Model
{
    /**
     * @var string Type
     */
    public string $type;

    /**
     * @var string Container
     */
    public string $container;

    /**
     * @var string Field
     */
    public string $field;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['container', 'field'], 'safe'];
        $rules[] = ['type', 'in', 'range' => [
            Assets::class,
            Categories::class,
            Entries::class,
            'craft\redactor\Field',
            'craft\ckeditor\Field',
            Date::class,
            PlainText::class,
            Url::class,
            Tags::class,
            Number::class,
        ]];
        return $rules;
    }
}
