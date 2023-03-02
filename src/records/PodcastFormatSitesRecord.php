<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\records;

use craft\db\ActiveRecord;

/**
 * Podcast format site record.
 *
 * @property string $attributes
 * @property bool $episodeEnabledByDefault
 * @property bool $podcastEnabledByDefault
 * @property string $episodeUriFormat
 * @property string $podcastUriFormat
 * @property string $episodeTemplate
 * @property string $podcastTemplate
 * @property int $fieldLayoutId
 * @property int $id
 * @property int $podcastFormatId
 * @property int $siteId
 * @property string $uid
 * @property int $userId
 */
class PodcastFormatSitesRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%studio_podcastFormat_sites}}';
    }
}
