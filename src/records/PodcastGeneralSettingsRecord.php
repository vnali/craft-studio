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
 * @property int $siteId
 * @property bool $publishRSS
 * @property bool $allowAllToSeeRSS
 * @property bool $enableOP3
 * @property int $userId
 */
class PodcastGeneralSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%studio_podcast_general_settings}}';
    }
}
