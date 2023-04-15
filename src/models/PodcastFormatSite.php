<?php
/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\models;

use craft\base\Model;
use craft\validators\SiteIdValidator;
use craft\validators\UriFormatValidator;

class PodcastFormatSite extends Model
{
    // Properties
    // =========================================================================

    /**
     * @var int|null ID
     */
    public $id;

    /**
     * @var int Podcast Format Id
     */
    public int $podcastFormatId;

    /**
     * @var int Site Id
     */
    public int $siteId;

    /**
     * @var bool Episode enabled status by default
     */
    public bool $episodeEnabledByDefault = true;

    /**
     * @var bool Podcast enabled status by default
     */
    public bool $podcastEnabledByDefault = true;

    /**
     * @var string|null Episode Uri Format
     */
    public string|null $episodeUriFormat;

    /**
     * @var string|null Podcast Uri Format
     */
    public string|null $podcastUriFormat;

    /**
     * @var string|null Podcast template URI
     */
    public string|null $podcastTemplate;

    /**
     * @var string|null Episode template URI
     */
    public string|null $episodeTemplate;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['id', 'siteId'], 'number', 'integerOnly' => true];
        $rules[] = [['siteId'], SiteIdValidator::class];
        $rules[] = [['episodeUriFormat', 'podcastUriFormat', 'episodeTemplate', 'podcastTemplate'], 'trim'];
        $rules[] = [['episodeTemplate', 'podcastTemplate'], 'string', 'max' => 500];
        $rules[] = [['episodeUriFormat', 'podcastUriFormat'], UriFormatValidator::class];
        return $rules;
    }
}
