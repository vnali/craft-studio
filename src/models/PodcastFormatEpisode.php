<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use Craft;
use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\helpers\UrlHelper;
use vnali\studio\elements\Episode;

/**
 * @mixin FieldLayoutBehavior
 */
class PodcastFormatEpisode extends Model
{
    /**
     * @var int|null ID
     */
    public ?int $id = null;

    /**
     * @var int|null Field layout ID
     */
    public ?int $fieldLayoutId = null;

    /**
     * @var int|null Podcast format Id
     */
    public ?int $podcastFormatId = null;

    /**
     * @var bool|null Enable versioning
     */
    public ?bool $enableVersioning = false;

    /**
     * @var string|null Mapping
     */
    public ?string $mapping = null;

    /**
     * @var string|null Native field's settings
     */
    public ?string $nativeSettings = null;

    /**
     * @var string|null UID
     */
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    protected function defineBehaviors(): array
    {
        return [
            'fieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Episode::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['id', 'fieldLayoutId', 'podcastFormatId'], 'integer'],
            [['mapping', 'nativeSettings'], 'safe'],
            [['enableVersioning'], 'in', 'range' => [0, 1]],
        ];
    }

    /**
     * Returns the CP edit URL.
     */
    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('studio/settings/podcast-types/' . $this->id);
    }

    /**
     * Mapping attributes
     *
     * @return array
     */
    public function mappingAttributes(): array
    {
        return [
            'mainAsset' => [
                'label' => 'Episode asset field',
                'handle' => 'mainAsset',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\Assets' => 'asset',
                ],
            ],
            'episodeImage' => [
                'label' => 'Image field',
                'handle' => 'episodeImage',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\Assets' => 'asset',
                ],
            ],
            'episodeSubtitle' => [
                'label' => 'Subtitle field',
                'handle' => 'episodeSubtitle',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\redactor\Field' => 'redactor',
                ],
            ],
            'episodeSummary' => [
                'label' => 'Summary field',
                'handle' => 'episodeSummary',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\redactor\Field' => 'redactor',
                ],
            ],
            'episodeDescription' => [
                'label' => 'Description field',
                'handle' => 'episodeDescription',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\redactor\Field' => 'redactor',
                ],
            ],
            'episodeContentEncoded' => [
                'label' => 'Content encoded field',
                'handle' => 'episodeContentEncoded',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\PlainText' => 'plain text',
                    'craft\ckeditor\Field' => 'ckeditor',
                    'craft\redactor\Field' => 'redactor',
                ],
            ],
            'episodePubDate' => [
                'label' => 'Publish Date field',
                'handle' => 'episodePubDate',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\Date' => 'date',
                ],
            ],
            'episodeGenre' => [
                'label' => 'Map genre meta data to ',
                'handle' => 'episodeGenre',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\Tags' => 'tag',
                    'craft\fields\Categories' => 'category',
                    'craft\fields\Entries' => 'entry',
                ],
            ],
            'episodeKeywords' => [
                'label' => 'Episode keyword field',
                'handle' => 'episodeKeywords',
                'convertTo' => [
                    '' => 'select one',
                    'craft\fields\Tags' => 'tag',
                    'craft\fields\Categories' => 'category',
                    'craft\fields\Entries' => 'entry',
                    'craft\fields\PlainText' => 'plain text',
                ],
            ],
        ];
    }

    /**
     * Episode native fields
     *
     * @return array
     */
    public function episodeNativeFields(): array
    {
        $episodeAttributes = [
            'duration' => Craft::t('studio', 'Duration'),
            'episodeBlock' => Craft::t('studio', 'Episode Block'),
            'episodeExplicit' => Craft::t('studio', 'Episode Explicit'),
            'publishOnRSS' => Craft::t('studio', 'Publish on RSS'),
            'episodeSeason' => Craft::t('studio', 'Episode Season'),
            'episodeSeasonName' => Craft::t('studio', 'Episode Season Name'),
            'episodeNumber' => Craft::t('studio', 'Episode Number'),
            'episodeType' => Craft::t('studio', 'Episode Type'),
            'episodeGUID' => Craft::t('studio', 'Episode GUID'),
        ];
        return $episodeAttributes;
    }
}
