<?php
/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\records;

use craft\db\ActiveRecord;

/**
 * Podcast asset indexes setting record.
 *
 * @property int $id
 * @property int $podcastId
 * @property string $settings
 * @property bool $enable
 * @property string $uid
 * @property int $userId
 */
class PodcastAssetIndexesSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%studio_podcast_assetIndexes_settings}}';
    }
}
