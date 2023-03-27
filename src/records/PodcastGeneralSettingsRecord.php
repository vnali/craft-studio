<?php
/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\records;

use craft\db\ActiveRecord;

/**
 * Podcast general setting record.
 *
 * @property int $podcastId
 * @property bool $publishRSS
 * @property bool $allowAllToSeeRSS
 */
class PodcastGeneralSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%studio_podcast_general_settings}}';
    }
}
