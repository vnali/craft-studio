<?php
/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\records;

use craft\db\ActiveRecord;

class I18nRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%studio_i18n}}';
    }
}
