<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\services;

use Craft;
use craft\base\Component;
use craft\db\Table;
use craft\events\ConfigEvent;
use craft\events\DeleteSiteEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\queue\jobs\ResaveElements;

use Throwable;

use vnali\studio\elements\Episode;
use vnali\studio\elements\Podcast;
use vnali\studio\helpers\ProjectConfigData;
use vnali\studio\models\PodcastFormat;
use vnali\studio\models\PodcastFormatEpisode;
use vnali\studio\models\PodcastFormatSite;
use vnali\studio\records\PodcastFormatEpisodeRecord;
use vnali\studio\records\PodcastFormatRecord;
use vnali\studio\records\PodcastFormatSitesRecord;

use yii\base\Exception;
use yii\web\NotFoundHttpException;

class podcastFormatsService extends Component
{
    public const CONFIG_PODCAST_FORMATS_KEY = 'studio.podcastFormats';

    /**
     * Return all podcast formats
     *
     * @return array
     */
    public function getAllPodcastFormats(): array
    {
        $podcastFormats = [];
        $podcastFormatRecords = PodcastFormatRecord::find()->all();
        foreach ($podcastFormatRecords as $podcastFormatRecord) {
            $podcastFormat = new PodcastFormat();
            $podcastFormat->setAttributes($podcastFormatRecord->getAttributes(), false);
            $podcastFormats[] = $podcastFormat;
        }
        return $podcastFormats;
    }

    /**
     * Return all podcast format episodes
     *
     * @return array
     */
    public function getAllPodcastFormatEpisodes(): array
    {
        $podcastFormatEpisodes = [];
        $podcastFormatEpisodeRecords = PodcastFormatEpisodeRecord::find()->all();
        foreach ($podcastFormatEpisodeRecords as $podcastFormatEpisodeRecord) {
            $podcastFormatEpisode = new PodcastFormatEpisode();
            $podcastFormatEpisode->setAttributes($podcastFormatEpisodeRecord->getAttributes(), false);
            $podcastFormatEpisodes[] = $podcastFormatEpisode;
        }
        return $podcastFormatEpisodes;
    }

    /**
     * Get podcast format by Id
     *
     * @param int $podcastFormatId
     * @return PodcastFormat|null
     */
    public function getPodcastFormatById(int $podcastFormatId): ?PodcastFormat
    {
        $podcastFormat = null;
        $podcastFormatRecord = PodcastFormatRecord::find()->where(['id' => $podcastFormatId])->one();
        if ($podcastFormatRecord) {
            $podcastFormat = new PodcastFormat();
            $podcastFormat->setAttributes($podcastFormatRecord->getAttributes(), false);
        }
        return $podcastFormat;
    }

    /**
     * Get podcast format episode by podcast format Id
     *
     * @param int $podcastFormatId
     * @return PodcastFormatEpisode|null
     */
    public function getPodcastFormatEpisodeById(int $podcastFormatId): ?PodcastFormatEpisode
    {
        $podcastFormatEpisode = null;
        $podcastFormatEpisodeRecord = PodcastFormatEpisodeRecord::find()->where(['id' => $podcastFormatId])->one();
        if ($podcastFormatEpisodeRecord) {
            $podcastFormatEpisode = new PodcastFormatEpisode();
            $podcastFormatEpisode->setAttributes($podcastFormatEpisodeRecord->getAttributes(), false);
        }
        return $podcastFormatEpisode;
    }

    /**
     * Get podcast format sites by Id
     *
     * @param int $podcastFormatId
     * @return array
     */
    public function getPodcastFormatSitesById(int $podcastFormatId): array
    {
        $podcastFormatSites = [];
        $podcastFormatSitesRecord = PodcastFormatSitesRecord::find()
            ->where(['podcastFormatId' => $podcastFormatId])
            ->innerJoin(['sites' => Table::SITES], '[[sites.id]] = [[siteId]]')
            ->orderBy(['sites.sortOrder' => SORT_ASC])
            ->all();
        /** @var PodcastFormatSitesRecord $podcastFormatSiteRecord */
        foreach ($podcastFormatSitesRecord as $podcastFormatSiteRecord) {
            $podcastFormatSite = new PodcastFormatSite();
            $podcastFormatSite->setAttributes($podcastFormatSiteRecord->getAttributes(), false);
            $podcastFormatSites[$podcastFormatSiteRecord->siteId] = $podcastFormatSite;
        }
        return $podcastFormatSites;
    }

    /**
     * Get podcast format by Uid
     *
     * @param string $uid
     * @return PodcastFormat|null
     */
    public function getPodcastFormatByUid(string $uid): ?PodcastFormat
    {
        $podcastFormat = null;
        $podcastFormatRecord = PodcastFormatRecord::find()->where(['uid' => $uid])->one();
        if ($podcastFormatRecord) {
            $podcastFormat = new PodcastFormat();
            $podcastFormat->setAttributes($podcastFormatRecord->getAttributes(), false);
        }
        return $podcastFormat;
    }

    /**
     * Get podcast format by handle
     *
     * @param string $podcastFormatHandle
     * @return PodcastFormat|null
     */
    public function getPodcastFormatByHandle(string $podcastFormatHandle): ?PodcastFormat
    {
        $podcastFormat = null;
        $podcastFormatRecord = PodcastFormatRecord::find()->where(['handle' => $podcastFormatHandle, 'dateDeleted' => null])->one();
        if ($podcastFormatRecord) {
            $podcastFormat = new PodcastFormat();
            $podcastFormat->setAttributes($podcastFormatRecord->getAttributes(), false);
        }
        return $podcastFormat;
    }

    /**
     * Save podcast format
     *
     * @param PodcastFormat $podcastFormat
     * @param PodcastFormatEpisode $podcastFormatEpisode
     * @param mixed $sitesSettings
     * @param bool $runValidation
     * @return bool
     */
    public function savePodcastFormat(PodcastFormat $podcastFormat, PodcastFormatEpisode $podcastFormatEpisode, $sitesSettings, bool $runValidation = true): bool
    {
        $isNew = $podcastFormat->id === null;

        if ($runValidation && (!$podcastFormat->validate() || !$podcastFormatEpisode->validate())) {
            Craft::info('Podcast format not saved due to validation error.', __METHOD__);
            return false;
        }

        /*
        if (count($sitesSettings) < 1) {
            Craft::info('Podcast format not saved due to validation error.', __METHOD__);
            return false;
        }
        */

        foreach ($sitesSettings as $siteSettings) {
            if (!$siteSettings->validate()) {
                Craft::info('Podcast format not saved due to validation error.', __METHOD__);
                return false;
            }
        }

        // Ensure the podcast format has a UID
        if ($isNew) {
            $podcastFormat->uid = StringHelper::UUID();
        } elseif (!$podcastFormat->uid) {
            /** @var PodcastFormatRecord|null $podcastFormatRecord */
            $podcastFormatRecord = PodcastFormatRecord::find()
                ->andWhere(['id' => $podcastFormat->id])
                ->one();

            if ($podcastFormatRecord === null) {
                throw new NotFoundHttpException('No podcast format exists with the ID ' . $podcastFormat->id);
            }

            $podcastFormat->uid = $podcastFormatRecord->uid;
        }

        // Get config data
        $configData = ProjectConfigData::getPodcastFormatData($podcastFormat, $podcastFormatEpisode, $sitesSettings);
        // Save it to project config
        $path = self::CONFIG_PODCAST_FORMATS_KEY . '.' . $podcastFormat->uid;
        Craft::$app->getProjectConfig()->set($path, $configData);

        // Set the ID on the podcast format
        if ($isNew) {
            $podcastFormat->id = Db::idByUid('{{%studio_podcastFormat}}', $podcastFormat->uid);
        }

        return true;
    }

    /**
     * Handles a changed podcast format
     *
     * @param ConfigEvent $event
     * @return void
     */
    public function handleChangedPodcastFormat(ConfigEvent $event): void
    {
        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $podcastFormatUid = $event->tokenMatches[0];
        $data = $event->newValue;

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $siteSettingData = [];
            if (isset($data['sitesSettings'])) {
                $siteSettingData = $data['sitesSettings'];
            }
            /** @var PodcastFormatRecord|null $podcastFormatRecord */
            $podcastFormatRecord = podcastFormatRecord::findWithTrashed()->where(['uid' => $podcastFormatUid])->one();
            $isNewPodcastFormat = $podcastFormatRecord === null;

            if ($isNewPodcastFormat) {
                $podcastFormatRecord = new PodcastFormatRecord();
                $podcastFormatEpisodeRecord = new PodcastFormatEpisodeRecord();
            } else {
                $podcastFormatEpisodeRecord = PodcastFormatEpisodeRecord::findOne(['podcastFormatId' => $podcastFormatRecord->id]);
                $allOldSiteSettingsRecords = PodcastFormatSitesRecord::find()
                    ->where(['podcastFormatId' => $podcastFormatRecord->id])
                    ->indexBy('siteId')
                    ->all();
            }

            $podcastFormatRecord->setAttributes($data, false);
            $podcastFormatRecord->enableVersioning = (bool)$data['podcastVersioning'];
            $podcastFormatRecord->mapping = $data['podcastMapping'];
            $podcastFormatRecord->nativeSettings = $data['podcastAttributes'];
            $podcastFormatRecord->uid = $podcastFormatUid;
            $resavePodcasts = ($podcastFormatRecord->handle !== $podcastFormatRecord->getOldAttribute('handle')
            );

            $resaveEpisodes = ($podcastFormatRecord->handle !== $podcastFormatRecord->getOldAttribute('handle')
            );

            $fieldsService = Craft::$app->getFields();

            if (!empty($data['podcastFieldLayout'])) {
                // Save the field layout
                $layout = FieldLayout::createFromConfig(reset($data['podcastFieldLayout']));
                $layout->id = $podcastFormatRecord->fieldLayoutId;
                $layout->type = Podcast::class;
                $layout->uid = key($data['podcastFieldLayout']);
                $fieldsService->saveLayout($layout, false);
                $podcastFormatRecord->fieldLayoutId = $layout->id;
            } elseif ($podcastFormatRecord->fieldLayoutId) {
                // Delete the field layout
                $fieldsService->deleteLayoutById($podcastFormatRecord->fieldLayoutId);
                $podcastFormatRecord->fieldLayoutId = null;
            }

            if ($podcastFormatRecord->dateDeleted) {
                $podcastFormatRecord->restore();
                $resavePodcasts = true;
                $resaveEpisodes = true;
            } else {
                // Save the podcast format
                if (!$podcastFormatRecord->save(false)) {
                    throw new Exception('Couldn’t save podcast format.');
                }
            }

            $podcastFormatEpisodeRecord->setAttributes($data, false);
            $podcastFormatEpisodeRecord->enableVersioning = (bool)$data['episodeVersioning'];
            $podcastFormatEpisodeRecord->mapping = $data['episodeMapping'];
            $podcastFormatEpisodeRecord->nativeSettings = $data['episodeAttributes'];
            $podcastFormatEpisodeRecord->podcastFormatId = $podcastFormatRecord->id;

            if (!empty($data['episodeFieldLayout'])) {
                // Save the field layout
                $layout = FieldLayout::createFromConfig(reset($data['episodeFieldLayout']));
                $layout->id = $podcastFormatEpisodeRecord->fieldLayoutId;
                $layout->type = Episode::class;
                $layout->uid = key($data['episodeFieldLayout']);
                $fieldsService->saveLayout($layout, false);
                $podcastFormatEpisodeRecord->fieldLayoutId = $layout->id;
            } elseif ($podcastFormatEpisodeRecord->fieldLayoutId) {
                // Delete the field layout
                $fieldsService->deleteLayoutById($podcastFormatEpisodeRecord->fieldLayoutId);
                $podcastFormatEpisodeRecord->fieldLayoutId = null;
            }
            // Save the podcast format
            if (!$podcastFormatEpisodeRecord->save(false)) {
                throw new Exception('Couldn’t save podcast format.');
            }

            $siteIdMap = Db::idsByUids(Table::SITES, array_keys($siteSettingData));
            $hasNewSite = false;
            foreach ($siteSettingData as $siteUid => $sitesSetting) {
                if (!isset($siteIdMap[$siteUid])) {
                    //continue;
                }
                $siteId = $siteIdMap[$siteUid];

                if (!$isNewPodcastFormat && isset($allOldSiteSettingsRecords[$siteId])) {
                    /** @var PodcastFormatSitesRecord $podcastFormatSiteRecord */
                    $podcastFormatSiteRecord = $allOldSiteSettingsRecords[$siteId];
                } else {
                    $podcastFormatSiteRecord = new PodcastFormatSitesRecord();
                    $podcastFormatSiteRecord->siteId = $siteId;
                    $podcastFormatSiteRecord->podcastFormatId = $podcastFormatRecord->id;
                    $resavePodcasts = true;
                    $resaveEpisodes = true;
                    $hasNewSite = true;
                }
                $podcastFormatSiteRecord->podcastUriFormat = $sitesSetting['podcastUriFormat'];
                $podcastFormatSiteRecord->podcastTemplate = $sitesSetting['podcastTemplate'];
                $podcastFormatSiteRecord->podcastEnabledByDefault = (bool) $sitesSetting['podcastEnabledByDefault'];
                $podcastFormatSiteRecord->episodeUriFormat = $sitesSetting['episodeUriFormat'];
                $podcastFormatSiteRecord->episodeTemplate = $sitesSetting['episodeTemplate'];
                $podcastFormatSiteRecord->episodeEnabledByDefault = (bool) $sitesSetting['episodeEnabledByDefault'];

                $resavePodcasts = ($resavePodcasts ||
                    $podcastFormatSiteRecord->podcastUriFormat !== $podcastFormatSiteRecord->getOldAttribute('podcastUriFormat')
                );

                $resaveEpisodes = ($resaveEpisodes ||
                    $podcastFormatSiteRecord->episodeUriFormat !== $podcastFormatSiteRecord->getOldAttribute('episodeUriFormat')
                );

                // Save the podcast format
                if (!$podcastFormatSiteRecord->save(false)) {
                    throw new Exception('Couldn’t save podcast format site.');
                }
            }

            if (!$isNewPodcastFormat && !empty($siteSettingData)) {
                // Drop any sites that are no longer being used, as well as the associated entry/element site
                // rows
                $affectedSiteUids = array_keys($siteSettingData);

                foreach ($allOldSiteSettingsRecords as $siteId => $siteSettingsRecord) {
                    $siteUid = array_search($siteId, $siteIdMap, false);
                    if (!in_array($siteUid, $affectedSiteUids, false)) {
                        $siteSettingsRecord->delete();
                    }
                }
            }

            if (!$isNewPodcastFormat && $resavePodcasts) {
                Queue::push(new ResaveElements([
                    'description' => Craft::t('studio', 'Resaving podcasts'),
                    'elementType' => Podcast::class,
                    'criteria' => [
                        'siteId' => $siteIdMap,
                        'preferSites' => [Craft::$app->getSites()->getPrimarySite()->id],
                        'unique' => true,
                        'status' => null,
                        'drafts' => null,
                        'provisionalDrafts' => null,
                        'revisions' => null,
                    ],
                    'updateSearchIndex' => $hasNewSite,
                ]));
            }

            if (!$isNewPodcastFormat && $resaveEpisodes) {
                Queue::push(new ResaveElements([
                    'description' => Craft::t('studio', 'Resaving episodes'),
                    'elementType' => Episode::class,
                    'criteria' => [
                        'siteId' => $siteIdMap,
                        'preferSites' => [Craft::$app->getSites()->getPrimarySite()->id],
                        'unique' => true,
                        'status' => null,
                        'drafts' => null,
                        'provisionalDrafts' => null,
                        'revisions' => null,
                    ],
                    'updateSearchIndex' => $hasNewSite,
                ]));
            }

            $transaction->commit();
        } catch (Throwable $exception) {
            $transaction->rollBack();
            throw $exception;
        }

        // Invalidate element caches
        Craft::$app->getElements()->invalidateCachesForElementType(Podcast::class);
        Craft::$app->getElements()->invalidateCachesForElementType(Episode::class);
    }

    /**
     * Handle delete podcast format
     *
     * @param ConfigEvent $event
     * @return void
     */
    public function handleDeletedPodcastFormat(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        /** @var PodcastFormatRecord $podcastFormatRecord */
        $podcastFormatRecord = PodcastFormatRecord::find()->where(['uid' => $uid])->one();

        if (!$podcastFormatRecord->id) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $podcastQuery = Podcast::find()
                ->podcastFormatId($podcastFormatRecord->id)
                ->status(null);
            $elementsService = Craft::$app->getElements();
            foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                foreach (Db::each($podcastQuery->siteId($siteId)) as $podcast) {
                    /** @var Podcast $podcast */
                    $elementsService->deleteElement($podcast);
                }
            }

            // Delete the podcast format
            Craft::$app->getDb()->createCommand()
                ->softDelete('{{%studio_podcastFormat}}', ['id' => $podcastFormatRecord->id])
                ->execute();

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Invalidate element caches
        Craft::$app->getElements()->invalidateCachesForElementType(Podcast::class);
        Craft::$app->getElements()->invalidateCachesForElementType(Episode::class);
    }

    /**
     * Remove deleted site from podcast format sites project config
     *
     * @param DeleteSiteEvent $event
     * @return void
     */
    public function pruneDeletedSite(DeleteSiteEvent $event): void
    {
        $siteUid = $event->site->uid;

        $projectConfig = Craft::$app->getProjectConfig();
        $podcastFormats = $projectConfig->get('studio.podcastFormats');

        if (is_array($podcastFormats)) {
            foreach ($podcastFormats as $podcastUid => $podcastFormat) {
                $projectConfig->remove('studio.podcastFormats' . '.' . $podcastUid . '.sitesSettings.' . $siteUid, 'Remove podcast format settings that belong to a site being deleted');
            }
        }
    }

    /**
     * Deletes a podcast format by its ID.
     *
     * @param int $podcastFormatId
     * @return boolean
     */
    public function deletePodcastFormatById(int $podcastFormatId): bool
    {
        $podcastFormat = $this->getPodcastFormatById($podcastFormatId);

        if ($podcastFormat === null) {
            return false;
        }

        return $this->deletePodcastFormat($podcastFormat);
    }

    /**
     * Delete podcast format from project configs
     *
     * @param PodcastFormat $podcastFormat
     * @return boolean
     */
    public function deletePodcastFormat(PodcastFormat $podcastFormat): bool
    {
        // Remove it from project config
        $path = self::CONFIG_PODCAST_FORMATS_KEY . '.' . $podcastFormat->uid;
        Craft::$app->getProjectConfig()->remove($path);

        return true;
    }
}
