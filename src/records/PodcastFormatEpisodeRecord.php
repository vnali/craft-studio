<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\records;

use craft\db\ActiveRecord;

/**
 * Podcast format episode record.
 *
 * @property bool $enableVersioning
 * @property int $fieldLayoutId
 * @property int $id
 * @property string $mapping
 * @property string $nativeSettings
 * @property int $podcastFormatId
 * @property string $uid
 * @property int $userId
 */
class PodcastFormatEpisodeRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%studio_podcastFormat_episode}}';
    }
}
