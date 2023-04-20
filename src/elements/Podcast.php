<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements;

use Craft;
use craft\base\Element;
use craft\behaviors\DraftBehavior;
use craft\elements\actions\CopyReferenceTag;
use craft\elements\actions\Delete;
use craft\elements\actions\Edit;
use craft\elements\actions\Restore;
use craft\elements\conditions\ElementConditionInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\errors\UnsupportedSiteException;
use craft\events\DefineElementInnerHtmlEvent;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\CpScreenResponseBehavior;

use Throwable;

use vnali\studio\elements\actions\ImportEpisodeFromAssetIndex;
use vnali\studio\elements\actions\ImportEpisodeFromRSS;
use vnali\studio\elements\actions\PodcastEpisodeSettings;
use vnali\studio\elements\actions\PodcastGeneralSettings;
use vnali\studio\elements\conditions\podcasts\PodcastCondition;
use vnali\studio\elements\db\PodcastQuery;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\models\PodcastFormat;
use vnali\studio\models\PodcastFormatEpisode;
use vnali\studio\records\I18nRecord;
use vnali\studio\records\PodcastGeneralSettingsRecord;
use vnali\studio\Studio;

use yii\base\InvalidConfigException;
use yii\web\Response;

class Podcast extends Element
{
    /**
     * @var int Podcast Format Id
     */
    public int $podcastFormatId;

    /**
     * @var string|null Author name
     */
    public ?string $authorName = null;

    /**
     * @var string|null Copyright
     */
    public ?string $copyright = null;

    /**
     * @var string|null Owner Names
     */
    public ?string $ownerName = null;

    /**
     * @var string|null Owner Email
     */
    public ?string $ownerEmail = null;

    /**
     * @var bool|null Podcast is Blocked
     */
    public ?bool $podcastBlock = null;

    /**
     * @var bool|null Podcast is complete
     */
    public ?bool $podcastComplete = null;

    /**
     * @var bool|null Podcast is explicit
     */
    public ?bool $podcastExplicit = null;

    /**
     * @var string|null Podcast link
     */
    public ?string $podcastLink = null;

    /**
     * @var string|null Podcast type
     */
    public ?string $podcastType = null;

    /**
     * @var string|null Podcast is redirected to
     */
    public ?string $podcastRedirectTo = null;

    /**
     * @var bool|null Podcast new feed URL
     */
    public ?bool $podcastIsNewFeedUrl = null;

    /**
     * @inheritdoc
     */
    public function __toString(): string
    {
        return (string)$this->title;
    }

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
     * Returns the podcast uploader ID.
     *
     * @return int|null
     */
    public function getUploaderId(): ?int
    {
        return $this->_uploaderId;
    }

    /**
     * Sets the podcast uploader ID.
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
     * Returns the podcast’s uploader.
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
     * Sets the podcast’s uploader.
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
            $sourceElementsWithMakers = array_filter($sourceElements, function(self $podcast) {
                return $podcast->getUploaderId() !== null;
            });

            /** @phpstan-ignore-next-line */
            $map = array_map(function(self $podcast) {
                return [
                    'source' => $podcast->id,
                    'target' => $podcast->getUploaderId(),
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
            case 'podcastBlock':
            case 'podcastComplete':
            case 'podcastExplicit':
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
            case 'RSS':
                //TODO: let user choose if wants slug instead of id in URL
                $RSSLabel = '';
                $elementId = $this->id;
                if (!$this->getIsDraft()) {
                    $record = PodcastGeneralSettingsRecord::find()->where(['podcastId' => $this->id, 'siteId' => $this->siteId])->one();
                    if (Craft::$app->getIsMultiSite() && count($this->getSupportedSites()) > 1) {
                        $enabled = $this->getEnabledForSite();
                    } else {
                        $enabled = $this->enabled;
                    }
                    /** @var PodcastGeneralSettingsRecord|null $record */
                    if ($enabled && $record && $record->publishRSS) {
                        $RSSLabel = Craft::t('studio', 'View');
                    } else {
                        $RSSLabel = Craft::t('studio', 'Preview');
                    }
                }
                return "<a href='/podcasts/rss?podcastId=" . $elementId . "&site=" . $this->getSite()->handle . "'>" . $RSSLabel . "</a>";
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
        return $names;
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('studio', 'Podcast');
    }

    /**
     * @inheritdoc
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('studio', 'Podcasts');
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
    public function getFieldLayout(): ?\craft\models\FieldLayout
    {
        if (($fieldLayout = parent::getFieldLayout()) !== null) {
            return $fieldLayout;
        }
        try {
            $podcastFormat = $this->getPodcastFormat();
        } catch (InvalidConfigException) {
            // The podcast format was probably deleted
            return null;
        }
        return $podcastFormat->getFieldLayout();
    }

    /**
     * @inheritdoc
     */
    public function getUriFormat(): ?string
    {
        $podcastFormat = $this->getPodcastFormat();
        $siteSettings = $podcastFormat->getSiteSettings();
        if (!isset($siteSettings[$this->siteId])) {
            throw new InvalidConfigException('podcast’  (' . $this->id . ') is not enabled for site ' . $this->siteId);
        }
        return $siteSettings[$this->siteId]->podcastUriFormat;
    }

    /**
     * @inheritdoc
     */
    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl("studio/podcasts");
    }

    /**
     * @inheritdoc
     */
    public function prepareEditScreen(Response $response, string $containerId): void
    {
        /** @var Response|CpScreenResponseBehavior $response */
        $response->selectedSubnavItem = 'podcasts';

        $response->crumbs([
            [
                'label' => Craft::t('studio', 'Podcasts'),
                'url' => UrlHelper::url('studio/podcasts'),
            ],
        ]);
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
    public function canCreateDrafts(User $user): bool
    {
        if (parent::canCreateDrafts($user)) {
            return true;
        }

        if ($user->can('studio-managePodcasts')) {
            return true;
        }

        if (!$this->id) {
            return $user->can('studio-createDraftNewPodcasts');
        }

        $uid = $this->getCanonical()->uid;

        if ($this->getIsDraft()) {
            /** @var static|DraftBehavior $this */
            if ($this->creatorId !== $user->id) {
                return $user->can('studio-saveOtherUserDraftPodcast-' . $uid);
            }
        }

        // If it is not draft, or it is a draft created by current user
        return ($user->can('studio-createDraftPodcast-' . $uid));
    }

    /**
     * @inheritdoc
     */
    public function canView(User $user): bool
    {
        if (parent::canView($user)) {
            return true;
        }

        if ($user->can('studio-managePodcasts')) {
            return true;
        }

        $uid = $this->getCanonical()->uid;

        if ($this->getIsDraft()) {
            /** @var static|DraftBehavior $this */
            if ($this->creatorId !== $user->id) {
                return $user->can('studio-viewOtherUserDraftPodcast-' . $uid);
            } elseif (!$this->getIsDerivative()) {
                return $user->can('studio-createDraftNewPodcasts');
            }
        }
        // If it is not draft, or it is a derivative draft created by current user
        return $user->can('studio-viewPodcast-' . $uid);
    }

    /**
     * @inheritdoc
     */
    public function canDelete(User $user): bool
    {
        if (parent::canDelete($user)) {
            return true;
        }

        $uid = $this->getCanonical()->uid;

        if ($user->can('studio-managePodcasts') || $user->can('studio-deletePodcast-' . $uid)) {
            return true;
        }

        if ($this->getIsDraft()) {
            /** @var static|DraftBehavior $this */
            if ($this->creatorId !== $user->id) {
                return $user->can('studio-deleteOtherUserDraftPodcast-' . $uid);
            } elseif (!$this->getIsDerivative()) {
                return $user->can('studio-createDraftNewPodcasts');
            } else {
                return ($user->can('studio-deleteDraftPodcast-' . $uid));
            }
        }
        return false;
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
    public function canSave(User $user): bool
    {
        if (parent::canSave($user)) {
            return true;
        }

        if ($user->can('studio-managePodcasts')) {
            return true;
        }

        if (!$this->id) {
            return $user->can('studio-createDraftNewPodcasts');
        }

        $uid = $this->getCanonical()->uid;

        if ($this->getIsDraft()) {
            /** @var static|DraftBehavior $this */
            if ($this->creatorId !== $user->id) {
                return $user->can('studio-saveOtherUserDraftPodcast-' . $uid);
            } elseif (!$this->getIsDerivative()) {
                return $user->can('studio-createDraftNewPodcasts');
            } else {
                return ($user->can('studio-createDraftPodcast-' . $uid));
            }
        }
        // If it is not draft
        return ($user->can('studio-editPodcast-' . $uid));
    }

    /**
     * @inheritdoc
     */
    public function hasRevisions(): bool
    {
        return $this->getPodcastFormat()->enableVersioning;
    }

    /**
     * Returns whether the podcast should be saving revisions on save.
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
    public function cpEditUrl(): ?string
    {
        $path = sprintf('studio/podcasts/edit/%s?', $this->getCanonicalId());
        return UrlHelper::cpUrl($path);
    }

    /**
     * @inheritdoc
     */
    protected function uiLabel(): ?string
    {
        if (!isset($this->title) || trim($this->title) === '') {
            return Craft::t('studio', 'Untitled podcast');
        }

        return null;
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
    public function getSupportedSites(): array
    {
        $sites = [];
        $podcastFormat = $this->getPodcastFormat();
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
                'enabledByDefault' => $siteSettings->podcastEnabledByDefault,
            ];
        }
        return $sites;
    }

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool
    {
        $podcastFormat = $this->getPodcastFormat();
        $podcastSiteSettings = $podcastFormat->getSiteSettings();
        if (!isset($podcastSiteSettings[$this->siteId])) {
            throw new UnsupportedSiteException($this, $this->siteId, "The podcast is not enabled for the site '$this->siteId'");
        }

        // Make sure the podcast has at least one revision if the podcast format has versioning enabled
        if ($this->_shouldSaveRevision()) {
            $hasRevisions = self::find()
                ->revisionOf($this)
                ->site('*')
                ->status(null)
                ->exists();
            if (!$hasRevisions) {
                /** @var self|null $currentPodcast */
                $currentPodcast = self::find()
                    ->id($this->id)
                    ->site('*')
                    ->status(null)
                    ->one();

                // May be null if the podcast is currently stored as an unpublished draft
                if ($currentPodcast) {
                    $revisionNotes = 'Revision from ' . Craft::$app->getFormatter()->asDatetime($currentPodcast->dateUpdated);
                    Craft::$app->getRevisions()->createRevision($currentPodcast, $currentPodcast->getUploaderId(), $revisionNotes);
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
    public static function refHandle(): ?string
    {
        return 'podcast';
    }

    /**
     * @inheritdoc
     */
    protected static function defineSources(string $context = null): array
    {
        $criteria = [];
        // If user has manage podcasts permission, we don't need criteria
        if (!Craft::$app->user->checkPermission('studio-managePodcasts')) {
            // When we create criteria, we try to fetch all possible podcast elements for all sites - ->siteId(*) -based on permission not sites
            // The podcast query takes care of returning podcast id only for switched site
            $podcasts = Podcast::find()->siteId('*')->status(null)->trashed(null)->all();
            $podcastIds = [];
            foreach ($podcasts as $podcast) {
                // If user can view podcast, show that podcast on element index
                if (
                    Craft::$app->user->checkPermission('studio-viewPodcast-' . $podcast->uid)
                ) {
                    // First step, show only podcast
                    $podcastIds[] = $podcast->id;
                    // Now if the user can view other user's drafts, show all drafts for that podcast too
                    if (Craft::$app->user->checkPermission('studio-viewOtherUserDraftPodcast-' . $podcast->uid)) {
                        $drafts = Podcast::find()->siteId('*')->status(null)->draftOf($podcast->id)->trashed(null)->all();
                        foreach ($drafts as $draft) {
                            $podcastIds[] = $draft->id;
                        }
                    } else {
                        // Otherwise only show created drafts by this user on podcast index page
                        $drafts = Podcast::find()->siteId('*')->status(null)->draftOf($podcast->id)->draftCreator(Craft::$app->user->identity)->trashed(null)->all();
                        foreach ($drafts as $draft) {
                            $podcastIds[] = $draft->id;
                        }
                    }
                }
            }
            // If there is a draft for new podcast, show this draft to creator too
            if (Craft::$app->user->checkPermission('studio-createDraftNewPodcasts')) {
                $drafts = Podcast::find()->siteId('*')->status(null)->draftOf(false)->draftCreator(Craft::$app->user->identity)->trashed(null)->all();
                foreach ($drafts as $draft) {
                    $podcastIds[] = $draft->id;
                }
            }
            $criteria = [
                'id' => $podcastIds,
            ];
        }
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('studio', 'All Podcasts'),
                'criteria' => $criteria,
                'defaultSort' => ['dateCreated', 'desc'],
                'hasThumbs' => true,
            ],
        ];
        return $sources;
    }

    protected static function defineFieldLayouts(string $source): array
    {
        $podcasts = [];
        if ($source === '*') {
            $podcasts = Studio::$plugin->podcasts->getAllPodcasts();
        }

        $fieldLayouts = [];
        foreach ($podcasts as $podcast) {
            $fieldLayouts[] = $podcast->getPodcastFormat()->getFieldLayout();
        }
        return $fieldLayouts;
    }

    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew): void
    {
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $user = Craft::$app->getUser()->getIdentity();
            $userId = $user->id;
        } else {
            $userId = null;
        }

        $lightSwitchFields = ['podcastBlock', 'podcastComplete', 'podcastExplicit'];
        $podcastFieldLayout = $this->getPodcastFormat()->getFieldLayout();
        $tabs = $podcastFieldLayout->getTabs();
        foreach ($tabs as $key => $tab) {
            foreach ($tab->elements as $key => $element) {
                if (get_class($element) != CustomField::class && (in_array($element->attribute, $lightSwitchFields) && (!$this->{$element->attribute}))) {
                    $this->{$element->attribute} = false;
                }
            }
        }

        $is_propagating = $this->propagating;
        $podcastService = Studio::$plugin->podcasts;
        if ($isNew) {
            if (!$is_propagating) {
                \Craft::$app->db->createCommand()
                    ->insert('{{%studio_podcast}}', [
                        'id' => $this->id,
                        'uploaderId' => $userId,
                        'podcastFormatId' => (int)$this->podcastFormatId,
                    ])
                    ->execute();
            }
            \Craft::$app->db->createCommand()
                ->insert('{{%studio_i18n}}', [
                    'elementId' => $this->id,
                    'siteId' => $this->siteId,
                    'copyright' => $this->copyright,
                    'podcastBlock' => $this->podcastBlock,
                    'podcastComplete' => $this->podcastComplete,
                    'podcastExplicit' => $this->podcastExplicit,
                    'podcastLink' => $this->podcastLink,
                    'podcastType' => $this->podcastType,
                    'authorName' => $this->authorName,
                    'ownerName' => $this->ownerName,
                    'ownerEmail' => $this->ownerEmail,
                    'podcastRedirectTo' => $this->podcastRedirectTo,
                    'podcastIsNewFeedUrl' => $this->podcastIsNewFeedUrl,
                ])
                ->execute();
        } else {
            $podcastSite = I18nRecord::find()->where(['elementId' => $this->id, 'siteId' => $this->siteId])->one();
            if (!$is_propagating && $podcastSite) {

                // Update not translatable fields for all sites
                $notTranslatableFields = $podcastService->notTranslatableFields($this->podcastFormatId);
                $columns = [];
                foreach ($notTranslatableFields as $key => $notTranslatableField) {
                    $columns[$key] = $this->$key;
                }
                \Craft::$app->db->createCommand()
                    ->update('{{%studio_i18n}}', $columns, ['elementId' => $this->id])
                    ->execute();
                // Update translatable fields for specified site
                $translatableFields = $podcastService->translatableFields($this->podcastFormatId);
                $columns = [];
                foreach ($translatableFields as $key => $translatableField) {
                    $columns[$key] = $this->$key;
                }
                \Craft::$app->db->createCommand()
                    ->update('{{%studio_i18n}}', $columns, ['elementId' => $this->id, 'siteId' => $this->siteId])
                    ->execute();
            }
            if (!$podcastSite) {
                \Craft::$app->db->createCommand()
                    ->insert('{{%studio_i18n}}', [
                        'elementId' => $this->id,
                        'siteId' => $this->siteId,
                        'copyright' => $this->copyright,
                        'podcastBlock' => $this->podcastBlock,
                        'podcastLink' => $this->podcastLink,
                        'podcastComplete' => $this->podcastComplete,
                        'podcastExplicit' => $this->podcastExplicit,
                        'podcastType' => $this->podcastType,
                        'authorName' => $this->authorName,
                        'ownerName' => $this->ownerName,
                        'ownerEmail' => $this->ownerEmail,
                        'podcastRedirectTo' => $this->podcastRedirectTo,
                        'podcastIsNewFeedUrl' => $this->podcastIsNewFeedUrl,
                    ])
                    ->execute();
            }
        }
        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $episodeQuery = Episode::find()
                ->podcastId($this->id)
                ->status(null)
                ->drafts(null)
                ->draftOf(false);
            $elementsService = Craft::$app->getElements();
            foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                foreach (Db::each($episodeQuery->siteId($siteId)) as $episode) {
                    /** @var Episode $episode */
                    $episode->deletedWithPodcast = true;
                    $elementsService->deleteElement($episode);
                }
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
        parent::afterDelete();
    }

    /**
     * @inheritdoc
     */
    protected static function defineTableAttributes(): array
    {
        $attributes = [
            'uploader' => ['label' => Craft::t('studio', 'Creator')],
            'link' => ['label' => Craft::t('studio', 'link')],
            'RSS' => ['label' => Craft::t('studio', 'RSS')],
            'slug' => ['label' => Craft::t('studio', 'Slug')],
            'uri' => ['label' => Craft::t('app', 'URI')],
            'id' => ['label' => Craft::t('app', 'ID')],
            'uid' => ['label' => Craft::t('app', 'UID')],
            'revisionNotes' => ['label' => Craft::t('app', 'Revision Notes')],
            'revisionCreator' => ['label' => Craft::t('app', 'Last Edited By')],
            'drafts' => ['label' => Craft::t('app', 'Drafts')],
            'podcastFormat' => ['label' => Craft::t('studio', 'Podcast format')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
            'ownerName' => ['label' => Craft::t('studio', 'Owner Name')],
            'ownerEmail' => ['label' => Craft::t('studio', 'Owner Email')],
            'authorName' => ['label' => Craft::t('studio', 'Author name')],
            'podcastBlock' => ['label' => Craft::t('studio', 'Podcast Block')],
            'podcastLink' => ['label' => Craft::t('studio', 'Podcast Link')],
            'podcastComplete' => ['label' => Craft::t('studio', 'Podcast Complete')],
            'podcastExplicit' => ['label' => Craft::t('studio', 'Podcast Explicit')],
            'podcastType' => ['label' => Craft::t('studio', 'Podcast Type')],
            'podcastIsNewFeedUrl' => ['label' => Craft::t('studio', 'New Feed URL')],
            'copyright' => ['label' => Craft::t('studio', 'Copyright')],
        ];
        return $attributes;
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
        ];
    }

    /**
     * @inheritdoc
     */
    public static function find(): PodcastQuery
    {
        return new PodcastQuery(static::class);
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
            'label' => Craft::t('studio', 'Edit podcast'),
        ]);
        // TODO: CHECK PERMISSION?
        // Delete
        $actions[] = $elementsService->createAction([
            'type' => Delete::class,
            'successMessage' => Craft::t('studio', 'Podcasts deleted.'),
            'confirmationMessage' => Craft::t('studio', 'Delete selected podcasts?'),
        ]);
        // Podcast general settings
        $actions[] = $elementsService->createAction([
            'type' => PodcastGeneralSettings::class,
        ]);
        // Podcast import settings
        $actions[] = $elementsService->createAction([
            'type' => PodcastEpisodeSettings::class,
        ]);
        // Import from URL
        $actions[] = $elementsService->createAction([
            'type' => ImportEpisodeFromRSS::class,
        ]);
        // Import from asset index
        $actions[] = $elementsService->createAction([
            'type' => ImportEpisodeFromAssetIndex::class,
        ]);
        // Restore
        $actions[] = Craft::$app->getElements()->createAction([
            'type' => Restore::class,
            'successMessage' => Craft::t('studio', 'Podcasts restored.'),
            'partialSuccessMessage' => Craft::t('studio', 'Some podcasts restored.'),
            'failMessage' => Craft::t('studio', 'Podcasts not restored.'),
        ]);
        // Copy Reference Tag
        $actions[] = CopyReferenceTag::class;
        return $actions;
    }

    /**
     * @inheritdoc
     */
    protected function route(): array|string|null
    {
        // Make sure that the podcast is actually live
        if (!$this->previewing && $this->getStatus() != 'enabled') {
            return null;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $podcastFormat = $this->getPodcastFormat();
        $siteSettings = $podcastFormat->getSiteSettings();

        // Todo: check if hasUrls
        if (!isset($siteSettings[$siteId])) {
            return null;
        }

        return [
            'templates/render', [
                'template' => (string)$siteSettings[$siteId]->podcastTemplate,
                'variables' => [
                    'podcast' => $this,
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $podcastFormat = new PodcastFormat();
        $requirableNativeFields = $podcastFormat->podcastNativeFields();

        // Add required rule for native fields based on podacst field layout
        $fieldLayout = $this->getFieldLayout();
        foreach ($requirableNativeFields as $nativeField => $fieldDesc) {
            if ($fieldLayout->isFieldIncluded($nativeField)) {
                $field = $fieldLayout->getField($nativeField);
                if ($field->required) {
                    $rules[] = [$nativeField, 'required', 'on' => self::SCENARIO_LIVE];
                }
            }
        }

        $rules[] = [['podcastFormatId'], 'number', 'integerOnly' => true];
        $rules[] = [['podcastBlock', 'podcastComplete', 'podcastExplicit', 'podcastIsNewFeedUrl'], 'safe'];
        $rules[] = [['podcastType'], 'in', 'range' => ['Episodic', 'Serial']];
        $rules[] = [['authorName', 'ownerName', 'copyright'], 'string', 'max' => ['255']];
        $rules[] = [['ownerEmail'], 'email'];
        $rules[] = [['podcastRedirectTo', 'podcastLink'], 'url'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getThumbUrl(int $size): ?string
    {
        $fieldHandle = null;
        $fieldContainer = null;
        $podcastFormat = $this->getPodcastFormat();
        $mapping = json_decode($podcastFormat->mapping, true);

        // Get specified field for podcast image
        if (isset($mapping['podcastImage']['container'])) {
            $fieldContainer = $mapping['podcastImage']['container'];
        }
        if (isset($mapping['podcastImage']['field'])) {
            $fieldUid = $mapping['podcastImage']['field'];
            if ($fieldUid) {
                $field = Craft::$app->fields->getFieldByUid($fieldUid);
                if ($field) {
                    $fieldHandle = $field->handle;
                }
            }
        }
        // get podcast image URL
        list($assetFilename, $assetFilePath, $assetFileUrl) = GeneralHelper::getElementAsset($this, $fieldContainer, $fieldHandle);

        return $assetFileUrl;
    }

    /**
     * @inheritdoc
     */
    public static function trackChanges(): bool
    {
        return true;
    }

    public static function gqlTypeNameByContext(mixed $context): string
    {
        return $context->handle . '_Podcast';
    }

    public function getGqlTypeName(): string
    {
        return static::gqlTypeNameByContext($this->getPodcastFormat());
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

    /**
     * @inheritdoc
     */
    public static function createCondition(): ElementConditionInterface
    {
        return Craft::createObject(PodcastCondition::class, [static::class]);
    }

    /**
     * Get podcast format
     *
     * @return PodcastFormat
     */
    public function getPodcastFormat(): PodcastFormat
    {
        if (!isset($this->podcastFormatId)) {
            throw new InvalidConfigException('podcast is missing its podcast format ID');
        }

        if (($podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatById($this->podcastFormatId)) === null) {
            throw new InvalidConfigException('Invalid podcast format ID: ' . $this->podcastFormatId);
        }

        return $podcastFormat;
    }

    /**
     * Get podcast format episode
     *
     * @return PodcastFormatEpisode
     */
    public function getPodcastFormatEpisode(): PodcastFormatEpisode
    {
        if (!isset($this->podcastFormatId)) {
            throw new InvalidConfigException('podcast is missing its podcast format ID');
        }

        if (($podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($this->podcastFormatId)) === null) {
            throw new InvalidConfigException('Invalid podcast format ID: ' . $this->podcastFormatId);
        }

        return $podcastFormatEpisode;
    }

    /**
     * Get podcast format sites
     *
     * @return array
     */
    public function getPodcastFormatSites(): array
    {
        if (!isset($this->podcastFormatId)) {
            throw new InvalidConfigException('podcast is missing its podcast format ID');
        }

        $podcastFormatSites = Studio::$plugin->podcastFormats->getPodcastFormatSitesById($this->podcastFormatId);

        return $podcastFormatSites;
    }

    /**
     * Update podcast element's inner html
     *
     * @param DefineElementInnerHtmlEvent $event
     * @return void
     */
    public static function updatePodcastElementHtml(DefineElementInnerHtmlEvent $event)
    {
        $element = $event->element;
        $context = $event->context;
        $elementHtml = $event->innerHtml;

        if (($context !== 'index') || !($element instanceof self)) {
            return;
        }

        $user = Craft::$app->getUser()->getIdentity();

        $extraData = '';

        if ($user) {
            if ($context === 'index') {
                if (
                    $user->can('studio-managePodcasts') ||
                    $user->can('studio-editPodcastEpisodeSettings-' . $element->uid)
                ) {
                    $extraData .= 'data-editPodcastEpisodeSettings ';
                }

                if (
                    $user->can('studio-managePodcasts') ||
                    $user->can('studio-editPodcastGeneralSettings-' . $element->uid)
                ) {
                    $extraData .= 'data-editPodcastGeneralSettings ';
                }

                if (
                    $user->can('studio-manageEpisodes') ||
                    $user->can('studio-importEpisodeByRSS-' . $element->uid)
                ) {
                    $extraData .= 'data-importByUrl ';
                }

                if (
                    $user->can('studio-manageEpisodes') ||
                    $user->can('studio-importEpisodeByAssetIndex-' . $element->uid)
                ) {
                    $extraData .= 'data-importByAssetIndex ';
                }
            }
        }

        $html = "<span class='extra-element-data' $extraData></span>";
        $event->innerHtml = $elementHtml . $html;
    }
}
