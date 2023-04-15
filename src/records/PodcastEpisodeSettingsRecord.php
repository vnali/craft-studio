<?php
/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\records;

use craft\db\ActiveRecord;

/**
 * Podcast episode setting record.
 *
 * @property int $id
 * @property int $podcastId
 * @property string $settings
 * @property string $uid
 * @property int $userId
 */
class PodcastEpisodeSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%studio_podcast_episode_settings}}';
    }
}
