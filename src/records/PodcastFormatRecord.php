<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\records;

use craft\db\ActiveRecord;
use craft\db\SoftDeleteTrait;
use yii2tech\ar\softdelete\SoftDeleteBehavior;

/**
 * Podcast format record.
 *
 * @property bool $enableVersioning
 * @property int|null $fieldLayoutId
 * @property string $handle
 * @property int $id
 * @property string $mapping
 * @property string $name
 * @property string $nativeSettings
 * @property string $uid
 * @property int $userId
 * @mixin SoftDeleteBehavior
 */
class PodcastFormatRecord extends ActiveRecord
{
    use SoftDeleteTrait;
    
    public static function tableName(): string
    {
        return '{{%studio_podcastFormat}}';
    }
}
