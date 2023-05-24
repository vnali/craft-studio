<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use Craft;
use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\db\Query;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;

use vnali\studio\elements\Podcast;
use vnali\studio\records\PodcastFormatRecord;
use vnali\studio\Studio;

/**
 * @mixin FieldLayoutBehavior
 *
 */
class PodcastFormat extends Model
{
    /**
     * @var int|null Id
     */
    public ?int $id = null;

    /**
     * @var int|null Field layout Id
     */
    public ?int $fieldLayoutId = null;

    /**
     * @var string|null Handle
     */
    public ?string $handle = null;

    /**
     * @var string|null Name
     */
    public ?string $name = null;

    /**
     * @var bool|null Enable versioning
     */
    public ?bool $enableVersioning = false;

    /**
     * @var string|null Mapping
     */
    public ?string $mapping = null;

    /**
     * @var string|null Native Field's Settings
     */
    public ?string $nativeSettings = null;

    /**
     * @var string|null Uid
     */
    public ?string $uid = null;

    private $_siteSettings = null;

    public function __toString(): string
    {
        return Craft::t('studio', $this->name) ?: static::class;
    }

    /**
     * @inheritdoc
     */
    protected function defineBehaviors(): array
    {
        return [
            'fieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Podcast::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'siteSettings'], 'required'],
            [['id', 'fieldLayoutId'], 'integer'],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']],
            [['name'], 'string', 'max' => 255],
            [['mapping', 'nativeSettings'], 'safe'],
            [['name', 'handle'], UniqueValidator::class, 'targetClass' => PodcastFormatRecord::class],
            [['enableVersioning'], 'in', 'range' => [0, 1]],
            [['siteSettings'], 'validateSiteSettings'],
        ];
    }

    /**
     * Returns the CP edit URL.
     */
    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('studio/settings/podcast-formats/' . $this->id);
    }

    public function getSiteSettings(): array
    {
        if (isset($this->_siteSettings)) {
            return $this->_siteSettings;
        }

        if (!$this->id) {
            return [];
        }

        $this->_siteSettings = Studio::$plugin->podcastFormats->getPodcastFormatSitesById($this->id);

        return $this->_siteSettings;
    }

    public function setSiteSettings(array $siteSettings): void
    {
        $this->_siteSettings = $siteSettings;
    }

    public function validateSiteSettings(): void
    {
        if ($this->id) {
            $currentSiteIds = (new Query())
                ->select(['siteId'])
                ->from(['{{%studio_podcastFormat_sites}}'])
                ->where(['podcastFormatId' => $this->id])
                ->column();

            if (empty(array_intersect($currentSiteIds, array_keys($this->getSiteSettings())))) {
                $this->addError('siteSettings', Craft::t('app', 'At least one currently-enabled site must remain enabled.'));
            }
        }
    }

    /**
     * Podcast mapping attributes
     *
     * @return array
     */
    public function mappingAttributes(): array
    {
        return [
            'podcastImage' => [
                'label' => 'Podcast Image',
                'handle' => 'podcastImage',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\Assets' => 'asset',
                ],
            ],
            'podcastDescription' => [
                'label' => 'Podcast Description',
                'handle' => 'podcastDescription',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\redactor\Field' => 'redactor',
                ],
            ],
            'podcastCategory' => [
                'label' => 'Podcast Category',
                'handle' => 'podcastCategory',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\Categories' => 'category',
                    'craft\fields\Entries' => 'entry',
                ],
            ],
        ];
    }

    /**
     * Podcast native fields
     *
     * @return array
     */
    public function podcastNativeFields(): array
    {
        $podcastAttributes = [
            'ownerName' => Craft::t('studio', 'Owner Name'),
            'ownerEmail' => Craft::t('studio', 'Owner Email'),
            'authorName' => Craft::t('studio', 'Author Name'),
            'podcastBlock' => Craft::t('studio', 'Podcast Block'),
            'podcastLink' => Craft::t('studio', 'Podcast Link'),
            'podcastComplete' => Craft::t('studio', 'Podcast Complete'),
            'podcastExplicit' => Craft::t('studio', 'Podcast Explicit'),
            'podcastType' => Craft::t('studio', 'Podcast Type'),
            'copyright' => Craft::t('studio', 'Copyright'),
            'medium' => Craft::t('studio', 'Podcast Medium'),
            'podcastRedirectTo' => Craft::t('studio', 'Redirect to'),
            'podcastIsNewFeedUrl' => Craft::t('studio', 'Is New Feed URL'),
        ];
        return $podcastAttributes;
    }
}
