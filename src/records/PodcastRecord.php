<?php
/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\records;

use craft\db\ActiveRecord;

/**
 * Podcast record.
 *
 * @property int $podcastFormatId
 * @property int $id
 */
class PodcastRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%studio_podcast}}';
    }
}
