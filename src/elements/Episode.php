<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\CopyReferenceTag;
use craft\elements\actions\Delete;
use craft\elements\actions\Edit;
use craft\elements\actions\Restore;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\errors\UnsupportedSiteException;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Assets;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\CpScreenResponseBehavior;

use vnali\studio\elements\conditions\episodes\EpisodeCondition;
use vnali\studio\elements\db\EpisodeQuery;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\helpers\Time;
use vnali\studio\models\PodcastFormat;
use vnali\studio\models\PodcastFormatEpisode;
use vnali\studio\records\I18nRecord;
use vnali\studio\Studio;

use yii\base\InvalidConfigException;
use yii\web\Response;

class Episode extends Element
{
    /**
     * @var bool Deleted with podcast
     */
    public ?bool $deletedWithPodcast = null;

    /**
     * @var string|int|null Duration
     */
    public string|int|null $duration = null;

    /**
     * @var bool|null Episode block
     */
    public ?bool $episodeBlock = null;

    /**
     * @var string|null Episode GUID
     */
    public ?string $episodeGUID = null;

    /**
     * @var bool|null Episode explicit
     */
    public ?bool $episodeExplicit = null;

    /**
     * @var int|null Episode number
     */
    public ?int $episodeNumber = null;

    /**
     * @var int|null Episode season
     */
    public ?int $episodeSeason = null;

    /**
     * @var string|null Episode type
     */
    public ?string $episodeType = null;

    /**
     * @var int|null Podcast Id
     */
    public ?int $podcastId = null;

    /**
     * @var int|null Uploader ID
     * @see getUploaderId()
     * @see setUploaderId()
     */
    public ?int $_uploaderId = null;

    /**
     * @var User|null|false
     * @see getUploader()
     * @see setUploader()
     */
    private User|false|null $_uploader = null;


    /**
     * Returns the episode uploader ID.
     *
     * @return int|null
     */
    public function getUploaderId(): ?int
    {
        return $this->_uploaderId;
    }

    /**
     * Sets the episode uploader ID.
     *
     * @param int|int[]|string|null $uploaderId
     */
    public function setUploaderId(array|int|string|null $uploaderId): void
    {
        if ($uploaderId === '') {
            $uploaderId = null;
        }

        if (is_array($uploaderId)) {
            $this->_uploaderId = reset($uploaderId) ?: null;
        } else {
            $this->_uploaderId = $uploaderId;
        }

        $this->_uploader = null;
    }

    /**
     * Returns the episode’s uploader.
     *
     * @return User|null
     * @throws InvalidConfigException if [[uploaderId]] is set but invalid
     */
    public function getUploader(): ?User
    {
        if (!isset($this->_uploader)) {
            if (!$this->getUploaderId()) {
                return null;
            }

            if (($this->_uploader = Craft::$app->getUsers()->getUserById($this->getUploaderId())) === null) {
                // The uploader is probably soft-deleted. Just no uploader is set
                $this->_uploader = false;
            }
        }

        return $this->_uploader ?: null;
    }

    /**
     * Sets the episode’s uploader.
     *
     * @param User|null $uploader
     */
    public function setUploader(?User $uploader = null): void
    {
        $this->_uploader = $uploader;
        $this->setUploaderId($uploader?->id);
    }

    /**
     * @inheritdoc
     */
    public function setEagerLoadedElements(string $handle, array $elements): void
    {
        if ($handle === 'uploader') {
            $this->_uploader = $elements[0] ?? false;
        } else {
            parent::setEagerLoadedElements($handle, $elements);
        }
    }

    /**
     * @inheritdoc
     */
    public function attributes(): array
    {
        $names = parent::attributes();
        $names[] = 'uploaderId';
        return $names;
    }

    /**
     * @inheritdoc
     */
    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        if ($handle === 'uploader') {
            /** @phpstan-ignore-next-line */
            $sourceElementsWithMakers = array_filter($sourceElements, function(self $episode) {
                return $episode->getUploaderId() !== null;
            });

            /** @phpstan-ignore-next-line */
            $map = array_map(function(self $episode) {
                return [
                    'source' => $episode->id,
                    'target' => $episode->getUploaderId(),
                ];
            }, $sourceElementsWithMakers);

            return [
                'elementType' => User::class,
                'map' => $map,
                'criteria' => [
                    'status' => null,
                ],
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }


    /**
     * @inheritdoc
     */
    protected static function prepElementQueryForTableAttribute(ElementQueryInterface $elementQuery, string $attribute): void
    {
        if ($attribute === 'uploader') {
            $elementQuery->andWith(['uploader', ['status' => null]]);
        } else {
            parent::prepElementQueryForTableAttribute($elementQuery, $attribute);
        }
    }

    /**
     * @inheritdoc
     */
    protected function tableAttributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'uploader':
                $uploader = $this->getUploader();
                return $uploader ? Cp::elementHtml($uploader) : '';
            case 'episodeBlock':
            case 'episodeExplicit':
                if (!$this->$attribute) {
                    return '';
                }
                $label = Craft::t('app', 'Enabled');
                return Html::tag('div', '', [
                    'class' => 'checkbox-icon',
                    'role' => 'img',
                    'title' => $label,
                    'aria' => [
                        'label' => $label,
                    ],
                ]);
            case 'duration':
                $time = Time::sec_to_time($this->$attribute);
                return $time ?? '';
            case 'dateCreated':
                $date = $this->dateCreated;
                return $date->format('Y-m-d H:i:s');
            case 'dateUpdated':
                $date = $this->dateUpdated;
                return $date->format('Y-m-d H:i:s');
            default:
                break;
        }
        return parent::tableAttributeHtml($attribute);
    }

    /**
     * @inheritdoc
     */
    public function extraFields(): array
    {
        $names = parent::extraFields();
        $names[] = 'uploader';
        $names[] = 'podcast';
        return $names;
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Episode';
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return 'Episodes';
    }

    /**
     * @inheritdoc
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasContent(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function hasUris(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl("studio/episodes");
    }

    /**
     * @inheritdoc
     */
    public function canView(User $user): bool
    {
        if (parent::canView($user)) {
            return true;
        }

        if ($user->can('studio-manageEpisodes')) {
            return true;
        }

        $podcast = $this->getPodcast();

        if ($podcast) {
            return $user->can('studio-viewPodcasts-' . $podcast->uid);
        } else {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        if (parent::canDelete($user)) {
            return true;
        }

        if ($user->can('studio-manageEpisodes')) {
            return true;
        }

        $podcast = $this->getPodcast();
        return $user->can('studio-deleteEpisodes-' . $podcast->uid);
    }

    /**
     * @inheritdoc
     */
    public function canDeleteForSite(User $user): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function canCreateDrafts(User $user): bool
    {
        if (parent::canCreateDrafts($user)) {
            return true;
        }

        if ($user->can('studio-manageEpisodes')) {
            return true;
        }

        $podcast = $this->getPodcast();
        if ($podcast) {
            return $user->can('studio-createDraftEpisodes-' . $podcast->uid);
        } else {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function canSave(User $user): bool
    {
        if (parent::canSave($user)) {
            return true;
        }

        if ($user->can('studio-manageEpisodes')) {
            return true;
        }

        $podcast = $this->getPodcast();

        if ($podcast) {
            if (!$this->id || $this->getIsDraft()) {
                return ($user->can('studio-createDraftEpisodes-' . $podcast->uid));
            }

            return $user->can('studio-createEpisodes-' . $podcast->uid);
        } else {
            return false;
        }
    }

    public function getPodcast(): Podcast|null
    {
        $podcast = Studio::$plugin->podcasts->getPodcastById($this->podcastId);
        if ($podcast) {
            return $podcast;
        } else {
            return null;
        }
    }

    /**
     * @inheritdoc
     */
    public function hasRevisions(): bool
    {
        return $this->getPodcast()->getPodcastFormat()->enableVersioning;
    }

    /**
     * Returns whether the episode should be saving revisions on save.
     *
     * @return bool
     */
    private function _shouldSaveRevision(): bool
    {
        return ($this->id &&
            !$this->propagating &&
            !$this->resaving &&
            !$this->getIsDraft() &&
            !$this->getIsRevision() &&
            $this->hasRevisions()
        );
    }

    /**
     * @inheritdoc
     */
    public function afterPropagate(bool $isNew): void
    {
        parent::afterPropagate($isNew);

        // Save a new revision?
        if ($this->_shouldSaveRevision()) {
            Craft::$app->getRevisions()->createRevision($this, $this->revisionCreatorId, $this->revisionNotes);
        }
    }

    /**
     * @inheritdoc
     */
    protected function metaFieldsHtml(bool $static): string
    {
        return implode('', [
            $this->slugFieldHtml($static),
            parent::metaFieldsHtml($static),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        if (($fieldLayout = parent::getFieldLayout()) !== null) {
            return $fieldLayout;
        }
        try {
            $podcastFormatEpisode = $this->getPodcast()->getPodcastFormatEpisode();
        } catch (InvalidConfigException) {
            // The podcast format was probably deleted
            return null;
        }
        return $podcastFormatEpisode->getFieldLayout();
    }

    /**
     * @inheritdoc
     */
    public function getUriFormat(): ?string
    {
        $url = null;
        $podcastFormat = $this->getPodcast()?->getPodcastFormat();
        if ($podcastFormat) {
            $siteSettings = $podcastFormat->getSiteSettings();
            if (!isset($siteSettings[$this->siteId])) {
                throw new InvalidConfigException('episode’  (' . $this->id . ') is not enabled for site ' . $this->siteId);
            }
            $url = $siteSettings[$this->siteId]->episodeUriFormat;
        }
        return $url;
    }

    /**
     * @inheritdoc
     */
    public static function isLocalized(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function cpEditUrl(): ?string
    {
        $path = sprintf('studio/episodes/edit/%s?', $this->getCanonicalId());
        return UrlHelper::cpUrl($path);
    }

    /**
     * @inheritdoc
     */
    protected function uiLabel(): ?string
    {
        if (!isset($this->title) || trim($this->title) === '') {
            return Craft::t('studio', 'Untitled episode');
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function getSupportedSites(): array
    {
        $sites = [];

        if (
            ($this->duplicateOf->id ?? $this->id)
        ) {
            if ($this->id) {
                $currentSites = self::find()
                    ->id($this->id)
                    ->status(null)
                    ->drafts(null)
                    ->provisionalDrafts(null)
                    ->revisions($this->getIsRevision())
                    ->siteId('*')
                    ->select('elements_sites.siteId')
                    ->indexBy('elements_sites.siteId')
                    ->column();
            } else {
                $currentSites = [];
            }

            // If this is being duplicated from another element (e.g. a draft), include any sites the source element is saved to as well
            if (!empty($this->duplicateOf->id)) {
                array_push(
                    $currentSites,
                    ...self::find()
                        ->status(null)
                        ->id($this->duplicateOf->id)
                        ->site('*')
                        ->select('elements_sites.siteId')
                        ->drafts(null)
                        ->provisionalDrafts(null)
                        ->revisions($this->duplicateOf->getIsRevision())
                        ->column()
                );
            }

            $currentSites = array_flip($currentSites);
        }
        $podcastFormat = $this->getPodcast()->getPodcastFormat();
        $sitesSettings = $podcastFormat->getSiteSettings();
        foreach ($sitesSettings as $key => $siteSettings) {
            $int = (int) $siteSettings->siteId;
            $propagate = ($siteSettings->siteId == $this->siteId ||
                $this->getEnabledForSite($siteSettings->siteId) !== null ||
                isset($currentSites[$int]));
            $sites[] = [
                // Craft need siteId as int
                'siteId' => (int) $siteSettings->siteId,
                'propagate' => $propagate,
                'enabledByDefault' => (bool)$siteSettings->podcastEnabledByDefault,
            ];
        }
        return $sites;
    }

    /**
     * @inheritdoc
     */
    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        $data = [
            'deletedWithPodcast' => $this->deletedWithPodcast,
        ];

        Db::update("{{%studio_episode}}", $data, [
            'id' => $this->id,
        ], [], false);

        return true;
    }

    public function beforeRestore(): bool
    {
        if (!$this->getPodcast()) {
            return false;
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool
    {
        $podcastFormat = $this->getPodcast()->getPodcastFormat();
        $podcastFormatSiteSettings = $podcastFormat->getSiteSettings();
        if (!isset($podcastFormatSiteSettings[$this->siteId])) {
            throw new UnsupportedSiteException($this, $this->siteId, "The episode is not enabled for the site '$this->siteId'");
        }

        // Make sure the episode has at least one revision if the podcast format has versioning enabled
        if ($this->_shouldSaveRevision()) {
            $hasRevisions = self::find()
                ->revisionOf($this)
                ->site('*')
                ->status(null)
                ->exists();
            if (!$hasRevisions) {
                /** @var self|null $currentEpisode */
                $currentEpisode = self::find()
                    ->id($this->id)
                    ->site('*')
                    ->status(null)
                    ->one();

                // May be null if the episode is currently stored as an unpublished draft
                if ($currentEpisode) {
                    $revisionNotes = 'Revision from ' . Craft::$app->getFormatter()->asDatetime($currentEpisode->dateUpdated);
                    Craft::$app->getRevisions()->createRevision($currentEpisode, $currentEpisode->getUploaderId(), $revisionNotes);
                }
            }
        }

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function getIsEditable(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function prepareEditScreen(Response $response, string $containerId): void
    {
        /** @var Response|CpScreenResponseBehavior $response */
        $response->selectedSubnavItem = 'episodes';

        $response->crumbs([
            [
                'label' => Craft::t('studio', 'Episodes'),
                'url' => UrlHelper::url('studio/episodes'),
            ],
        ]);
    }


    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $podcastFormatEpisode = new PodcastFormatEpisode();
        $requirableNativeFields = $podcastFormatEpisode->episodeNativeFields();

        // Add required rule for native fields based on episode field layout
        $fieldLayout = $this->getFieldLayout();
        foreach ($requirableNativeFields as $nativeField => $fieldDesc) {
            if ($fieldLayout->isFieldIncluded($nativeField)) {
                $field = $fieldLayout->getField($nativeField);
                if ($field->required) {
                    $rules[] = [$nativeField, 'required', 'on' => self::SCENARIO_LIVE];
                }
            }
        }

        $rules[] = [['duration'], function($attribute, $params, $validator) {
            $durationParts = explode(':', $this->$attribute);
            $error = false;
            $errorMessage = null;
            if (count($durationParts) > 3 || count($durationParts) == 2) {
                $error = true;
                $errorMessage = 'Duration should have 3 parts or in seconds format';
            } elseif (count($durationParts) === 3) {
                foreach ($durationParts as $key => $durationPart) {
                    if (!ctype_digit($durationPart)) {
                        $error = true;
                        $errorMessage = 'Unaccepted character in duration parts';
                        break;
                    } elseif ($key != 0) {
                        if ($durationPart > '59') {
                            $error = true;
                            $errorMessage = $durationPart . ' is not acceptable';
                            break;
                        } elseif ((strlen($durationPart) > 2)) {
                            $error = true;
                            $errorMessage = $durationPart . ' length wrong';
                            break;
                        }
                    }
                }
            } elseif (count($durationParts) === 1) {
                if (!ctype_digit((string)$durationParts[0])) {
                    $error = true;
                    $errorMessage = 'Duration format is not supported';
                }
            }
            if ($error) {
                $this->addError($attribute, $errorMessage);
            }
        }, 'skipOnEmpty' => true, 'on' => [self::SCENARIO_DEFAULT, self::SCENARIO_LIVE]];
        $rules[] = [['episodeBlock', 'episodeExplicit'], 'safe'];
        $rules[] = [['episodeType'], 'in', 'range' => ['full', 'trailer', 'bonus']];
        $rules[] = [['episodeSeason', 'episodeNumber'], 'number', 'integerOnly' => true];
        $rules[] = [['podcastId'], 'number', 'integerOnly' => true];
        $rules[] = [['episodeGUID'], 'string', 'max' => 1000];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('studio', 'All Episodes'),
                'criteria' => [],
                'defaultSort' => ['dateCreated', 'desc'],
                'hasThumbs' => true,
            ],
            [
                'heading' => Craft::t('studio', 'Podcasts'),
            ],
        ];


        $podcasts = Studio::$plugin->podcasts->getAllPodcastsAllSites();
        $index = 1;

        foreach ($podcasts as $index => $podcast) {
            $sources[] = [
                'key' => 'podcast:' . $podcast->uid,
                'label' => $podcast->title,
                'data' => [
                    'handle' => $podcast->slug,
                ],
                'sites' => [$podcast->siteId],
                'criteria' => [
                    'podcastId' => $podcast->id,
                ],
            ];

            $index++;
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        $podcastFormat = $this->getPodcast()->getPodcastFormat();
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $user = Craft::$app->getUser()->getIdentity();
            $userId = $user->id;
        } else {
            $userId = null;
        }

        $lightSwitchFields = ['episodeBlock', 'episodeExplicit'];
        $episodeFieldLayout = $this->getPodcast()->getPodcastFormatEpisode()->getFieldLayout();
        $tabs = $episodeFieldLayout->getTabs();
        foreach ($tabs as $key => $tab) {
            foreach ($tab->elements as $key => $element) {
                if (get_class($element) != CustomField::class && (in_array($element->attribute, $lightSwitchFields) && (!$this->{$element->attribute}))) {
                    $this->{$element->attribute} = false;
                }
            }
        }

        // Convert duration in second format
        if (!ctype_digit((string)$this->duration)) {
            $this->duration = Time::time_to_sec($this->duration);
        }

        $is_propagating = $this->propagating;
        $episodeService = Studio::$plugin->episodes;
        if ($isNew) {
            if (!$is_propagating) {
                \Craft::$app->db->createCommand()
                    ->insert('{{%studio_episode}}', [
                        'id' => $this->id,
                        'uploaderId' => $userId,
                        'podcastId' => (int)$this->podcastId,
                    ])
                    ->execute();
            }
            \Craft::$app->db->createCommand()
                ->insert('{{%studio_i18n}}', [
                    'elementId' => $this->id,
                    'siteId' => $this->siteId,
                    'duration' => $this->duration,
                    'episodeBlock' => $this->episodeBlock,
                    'episodeExplicit' => $this->episodeExplicit,
                    'episodeGUID' => $this->episodeGUID,
                    'episodeNumber' => $this->episodeNumber,
                    'episodeSeason' => $this->episodeSeason,
                    'episodeType' => $this->episodeType,
                ])
                ->execute();
        } else {
            $episodeSite = I18nRecord::find()->where(['elementId' => $this->id, 'siteId' => $this->siteId])->one();
            if (!$this->episodeGUID) {
                $this->episodeGUID = $this->uid;
            }
            if (!$is_propagating && $episodeSite) {

                // Update not translatable fields for all sites
                $notTranslatableFields = $episodeService->notTranslatableFields($podcastFormat->id);
                $columns = [];
                foreach ($notTranslatableFields as $key => $notTranslatableField) {
                    $columns[$key] = $this->$key;
                }
                \Craft::$app->db->createCommand()
                    ->update('{{%studio_i18n}}', $columns, ['elementId' => $this->id])
                    ->execute();

                // Update translatable fields for specified site
                $translatableFields = $episodeService->translatableFields($podcastFormat->id);
                $columns = [];
                foreach ($translatableFields as $key => $translatableField) {
                    $columns[$key] = $this->$key;
                }
                \Craft::$app->db->createCommand()
                    ->update('{{%studio_i18n}}', $columns, ['elementId' => $this->id, 'siteId' => $this->siteId])
                    ->execute();
            }
            if (!$episodeSite) {
                \Craft::$app->db->createCommand()
                    ->insert('{{%studio_i18n}}', [
                        'elementId' => $this->id,
                        'siteId' => $this->siteId,
                        'duration' => $this->duration,
                        'episodeBlock' => $this->episodeBlock,
                        'episodeExplicit' => $this->episodeExplicit,
                        'episodeGUID' => $this->episodeGUID ?: $this->uid,
                        'episodeNumber' => $this->episodeNumber,
                        'episodeSeason' => $this->episodeSeason,
                        'episodeType' => $this->episodeType,
                    ])
                    ->execute();
            }
        }
        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('studio', 'title'),
                'orderBy' => 'title',
                'attribute' => 'title',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'dateCreated',
                'attribute' => 'dateCreated',
            ],
            [
                'label' => Craft::t('app', 'Date Updated'),
                'orderBy' => 'dateUpdated',
                'attribute' => 'dateUpdated',
            ],
            [
                'label' => Craft::t('studio', 'Podcast'),
                'orderBy' => 'podcastId',
                'attribute' => 'podcastId',
            ],
            [
                'label' => Craft::t('studio', 'Duration'),
                'orderBy' => 'duration',
                'attribute' => 'duration',
            ],
            [
                'label' => Craft::t('studio', 'Episode season'),
                'orderBy' => 'episodeSeason',
                'attribute' => 'episodeSeason',
            ],
            [
                'label' => Craft::t('studio', 'Episode number'),
                'orderBy' => 'episodeNumber',
                'attribute' => 'episodeNumber',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function refHandle(): ?string
    {
        return 'episode';
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        $attributes = [
            'uploader' => ['label' => Craft::t('app', 'Uploader')],
            'link' => ['label' => Craft::t('app', 'link')],
            'podcast' => ['label' => Craft::t('studio', 'Podcast')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
            'duration' => ['label' => Craft::t('studio', 'Duration')],
            'episodeBlock' => ['label' => Craft::t('studio', 'Episode Block')],
            'episodeExplicit' => ['label' => Craft::t('studio', 'Episode Explicit')],
            'episodeSeason' => ['label' => Craft::t('studio', 'Episode Season')],
            'episodeNumber' => ['label' => Craft::t('studio', 'Episode Number')],
            'episodeType' => ['label' => Craft::t('studio', 'Episode Type')],
            'episodeGUID' => ['label' => Craft::t('studio', 'GUID')],
        ];

        return $attributes;
    }

    protected static function defineFieldLayouts(string $source): array
    {
        $podcasts = [];
        if ($source === '*') {
            $podcasts = Studio::$plugin->podcasts->getAllPodcasts();
        } elseif (
            preg_match('/^podcast:(.+)$/', $source, $matches) &&
            $podcast = Studio::$plugin->podcasts->getPodcastByUid($matches[1])
        ) {
            $podcasts = [$podcast];
        }

        $fieldLayouts = [];
        foreach ($podcasts as $podcast) {
            $fieldLayouts[] = $podcast->getPodcastFormatEpisode()->getFieldLayout();
        }
        return $fieldLayouts;
    }

    /**
     * @inheritdoc
     */
    public static function find(): EpisodeQuery
    {
        return new EpisodeQuery(static::class);
    }

    /**
     * @inheritdoc
     */
    protected static function defineActions(string $source = null): array
    {
        $actions = [];
        $elementsService = Craft::$app->getElements();

        // Edit
        $actions[] = Craft::$app->getElements()->createAction([
            'type' => Edit::class,
            'label' => Craft::t('studio', 'Edit episode'),
        ]);
        $actions[] = $elementsService->createAction([
            'type' => Delete::class,
            'successMessage' => Craft::t('studio', 'Episodes deleted.'),
            'confirmationMessage' => Craft::t('studio', 'Delete selected podcasts?'),
        ]);
        // Restore
        $actions[] = Craft::$app->getElements()->createAction([
            'type' => Restore::class,
            'successMessage' => Craft::t('studio', 'Episodes restored.'),
            'partialSuccessMessage' => Craft::t('studio', 'Some episodes restored.'),
            'failMessage' => Craft::t('studio', 'Episodes not restored.'),
        ]);
        // Copy Reference Tag
        $actions[] = CopyReferenceTag::class;
        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected function route(): array
    {
        return [
            'templates/render', [
                'template' => 'episodes/view',
                'variables' => [
                    'episode' => $this,
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getThumbUrl(int $size): ?string
    {
        $assetFileUrl = null;
        $podcastFormatEpisode = $this->getPodcast()?->getPodcastFormatEpisode();

        if ($podcastFormatEpisode) {
            $mapping = json_decode($podcastFormatEpisode->mapping, true);
            $fieldContainer = null;
            $fieldHandle = null;
            if (isset($mapping['episodeImage']['container'])) {
                $fieldContainer = $mapping['episodeImage']['container'];
            }
            // Get specified field for episode image
            if (isset($mapping['episodeImage']['field'])) {
                $fieldUid = $mapping['episodeImage']['field'];
                if ($fieldUid) {
                    $field = Craft::$app->fields->getFieldByUid($fieldUid);
                    if ($field) {
                        $fieldHandle = $field->handle;
                        if (get_class($field) == Assets::class) {
                            list($assetFilename, $assetFilePath, $assetFileUrl, $asset) = GeneralHelper::getElementAsset($this, $fieldContainer, $fieldHandle);
                        } else {
                            $assetFileUrl = $this->{$fieldHandle};
                        }
                    }
                }
            }
        }
        return $assetFileUrl;
    }

    /**
     * @inheritdoc
     */
    protected function statusFieldHtml(): string
    {
        $id3Metadata = Cp::lightswitchHtml([
            'label' => 'Id3 metadata',
            'id' => 'id3Metadata',
            'value' => 1,
        ]);

        $id3ImageMetadata = Cp::lightswitchHtml([
            'label' => 'Id3 image metadata',
            'id' => 'id3ImageMetadata',
            'value' => 1,
        ]);

        $metaButton = Cp::fieldHtml(
            Html::button(Craft::t('studio', 'Fetch'), [
                'id' => 'meta-btn',
                'class' => ['btn', 'secondary'],
            ])
        );

        $getId3 = Html::beginTag('fieldset') .
            Html::tag('legend', Craft::t('studio', 'ID3'), ['class' => 'h6']) .
            Html::tag('div', $id3Metadata . $id3ImageMetadata . $metaButton, ['class' => 'meta']) .
            Html::endTag('fieldset');

        $view = Craft::$app->getView();
        $js = <<<JS
            $('#meta-btn').on('click', () => {
                var id3Metadata = $("#id3Metadata").hasClass("on");
                if (id3Metadata == false) {
                    id3Metadata = ''; 
                }
                var id3ImageMetadata = $("#id3ImageMetadata").hasClass("on");
                if (id3ImageMetadata == false) {
                    id3ImageMetadata = ''; 
                }             
                const \$form = Craft.createForm().appendTo(Garnish.\$bod);
                \$form.append(Craft.getCsrfInput());
                $('<input/>', {type: 'hidden', name: 'action', value: 'studio/default/meta'}).appendTo(\$form);
                $('<input/>', {type: 'hidden', name: 'elementId', value: $this->id }).appendTo(\$form);
                $('<input/>', {type: 'hidden', name: 'item', value: 'episode' }).appendTo(\$form);
                $('<input/>', {type: 'hidden', name: 'fetchId3Metadata', value: id3Metadata}).appendTo(\$form);
                $('<input/>', {type: 'hidden', name: 'fetchId3ImageMetadata', value: id3ImageMetadata}).appendTo(\$form);
                $('<input/>', {type: 'submit', value: 'Submit'}).appendTo(\$form);
                \$form.submit();
                \$form.remove();
            });
JS;
        $view->registerJs($js);

        return parent::statusFieldHtml() . $getId3;
    }

    /**
     * @inheritdoc
     */
    public static function trackChanges(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function createCondition(): ElementConditionInterface
    {
        return Craft::createObject(EpisodeCondition::class, [static::class]);
    }

    public static function gqlTypeNameByContext(mixed $context): string
    {
        return $context->handle . '_Episode';
    }

    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext($this->getPodcast()->getPodcastFormat());
    }

    /**
     * @inheritdoc
     */
    public static function gqlScopesByContext(mixed $context): array
    {
        /** @var PodcastFormat $context */
        return [
            'podcastFormats.' . $context->uid,
        ];
    }
}
