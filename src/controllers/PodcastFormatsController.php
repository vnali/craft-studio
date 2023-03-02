<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UrlManager;

use vnali\studio\assets\EditSiteSettingsAsset;
use vnali\studio\elements\Episode;
use vnali\studio\elements\Podcast;
use vnali\studio\helpers\GeneralHelper;
use vnali\studio\models\Mapping;
use vnali\studio\models\PodcastFormat;
use vnali\studio\models\PodcastFormatEpisode;
use vnali\studio\models\PodcastFormatSite;
use vnali\studio\Studio;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PodcastFormatsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('studio', 'Administrative changes are disallowed in this environment.'));
        }
        // Require permission
        $this->requirePermission('studio-manageSettings');

        return parent::beforeAction($action);
    }

    /**
     * Podcast format index page
     * @return Response
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('studio/podcast-formats/_index.twig');
    }

    /**
     * Podcast format edit page.
     *
     * @param int|null $podcastFormatId
     * @param PodcastFormat|null $podcastFormat
     * @param PodcastFormatEpisode|null $podcastFormatEpisode
     * @param PodcastFormatSite[] $podcastFormatSites
     * @return Response
     */
    public function actionEdit(?int $podcastFormatId = null, PodcastFormat $podcastFormat = null, PodcastFormatEpisode $podcastFormatEpisode = null, array $podcastFormatSites = []): Response
    {
        $episodeMappingModel = null;
        $podcastMappings = null;
        $episodeMappings = null;
        $episodeNativeSettingsValues = null;
        $podcastNativeSettingsValues = null;
        if ($podcastFormat === null) {
            if ($podcastFormatId !== null) {
                $podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatById($podcastFormatId);
                $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastFormatId);
                $podcastFormatSites = Studio::$plugin->podcastFormats->getPodcastFormatSitesById($podcastFormatId);

                if ($podcastFormat === null) {
                    throw new NotFoundHttpException(Craft::t('studio', 'Podcast format not found.'));
                }
            }
        }

        if ($podcastFormat === null) {
            $podcastFormat = new PodcastFormat();
        }

        if ($podcastFormatEpisode === null) {
            $podcastFormatEpisode = new PodcastFormatEpisode();
        }

        // Podcast Mapping
        $podcastFieldMappings = json_decode($podcastFormat->mapping, true) ?? [];
        foreach ($podcastFieldMappings as $mappingKey => $podcastMapping) {
            $podcastMappingModel = new Mapping();
            foreach ($podcastMapping as $key => $value) {
                $podcastMappingModel->$key = $value;
            }
            $podcastMappings[$mappingKey] = $podcastMappingModel;
        }

        // Episode Mapping
        $episodeFieldMappings = json_decode($podcastFormatEpisode->mapping, true) ?? [];
        foreach ($episodeFieldMappings as $mappingKey => $episodeMapping) {
            $episodeMappingModel = new Mapping();
            foreach ($episodeMapping as $key => $value) {
                $episodeMappingModel->$key = $value;
            }
            $episodeMappings[$mappingKey] = $episodeMappingModel;
        }

        // Native field settings
        $podcastNativeSettingsValues = json_decode($podcastFormat->nativeSettings, true);
        $episodeNativeSettingsValues = json_decode($podcastFormatEpisode->nativeSettings, true);

        $variables = [
            'podcastFormatId' => $podcastFormatId,
            'podcastFormat' => $podcastFormat,
            'podcastFormatEpisode' => $podcastFormatEpisode,
            'podcastFormatSites' => $podcastFormatSites,
            'podcastMappings' => $podcastMappings,
            'episodeMappings' => $episodeMappings,
        ];

        $tabs = [
            'podcastGeneralSettings' => [
                'label' => Craft::t('studio', 'General'),
                'url' => '#podcast-general-settings',
                'class' => ($podcastFormat->hasErrors('name') ||
                    $podcastFormat->hasErrors('handle') || $podcastFormat->getErrors('siteSettings')
                ) ? 'error' : '',
            ],
            'podcastFieldSettings' => [
                'label' => Craft::t('studio', 'Podcast field layout'),
                'url' => '#podcast-field-settings',
            ],
            'episodeFieldSettings' => [
                'label' => Craft::t('studio', 'Episode field layout'),
                'url' => '#episode-field-settings',
            ],
            'podcastMappingSettings' => [
                'label' => Craft::t('studio', 'Podcast mapping'),
                'url' => '#podcast-mapping-settings',
            ],
            'episodeMappingSettings' => [
                'label' => Craft::t('studio', 'Episode mapping'),
                'url' => '#episode-mapping-settings',
            ],
            'podcastExtraSettings' => [
                'label' => Craft::t('studio', 'Extra Settings'),
                'url' => '#extra-settings',
            ],
        ];

        $variables['tabs'] = $tabs;
        $variables['selectedTab'] = 'podcastGeneralSettings';


        $variables['podcastItemSettings'] = $podcastFormat->mappingAttributes();
        $variables['episodeItemSettings'] = $podcastFormatEpisode->mappingAttributes();

        $variables['podcastNativeFields'] = $podcastFormat->podcastNativeFields();
        $variables['podcastNativeSettings'] = $podcastNativeSettingsValues;
        $variables['episodeNativeFields'] = $podcastFormatEpisode->episodeNativeFields();
        $variables['episodeNativeSettings'] = $episodeNativeSettingsValues;

        if ($podcastFormatId === null) {
            $variables['title'] = Craft::t('studio', 'Create a new podcast template');
        } else {
            $variables['title'] = $podcastFormat->name;
        }

        $variables['fields'] = [
            ['value' => '', 'label' => Craft::t('studio', 'Select/Create field')],
        ];

        $podcastFieldLayout = $podcastFormat->getFieldLayout();
        $episodeFieldLayout = $podcastFormatEpisode->getFieldLayout();
        $variables['podcastContainers'] = GeneralHelper::containers($podcastFieldLayout, 'all', 'podcast', false);
        $variables['episodeContainers'] = GeneralHelper::containers($episodeFieldLayout, 'all', 'episode', false);

        $this->getView()->registerAssetBundle(EditSiteSettingsAsset::class);

        return $this->renderTemplate('studio/podcast-formats/_edit', $variables);
    }

    /**
     * Saves the podcast format.
     *
     * @return Response|null
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $validate = true;

        $podcastFormatId = $this->request->getBodyParam('podcastFormatId');
        if ($podcastFormatId) {
            $podcastFormat = Studio::$plugin->podcastFormats->getPodcastFormatById($podcastFormatId);
            $podcastFormatEpisode = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($podcastFormatId);
            if (!$podcastFormat || !$podcastFormatEpisode) {
                throw new NotFoundHttpException(Craft::t('studio', 'Podcast type not found.'));
            }
        } else {
            $podcastFormat = new PodcastFormat();
            $podcastFormatEpisode = new PodcastFormatEpisode();
        }

        $podcastFormat->name = $this->request->getBodyParam('name', $podcastFormat->name);
        $podcastFormat->handle = $this->request->getBodyParam('handle', $podcastFormat->handle);
        $podcastFormat->enableVersioning = (bool) $this->request->getBodyParam('podcastVersioning', $podcastFormat->enableVersioning);

        $podcastFormatEpisode->enableVersioning = (bool) $this->request->getBodyParam('episodeVersioning', $podcastFormatEpisode->enableVersioning);

        // Podcast native field settings
        $podcastNativeFieldsSettings = [];
        $podcastAttributes = $podcastFormat->podcastNativeFields();
        foreach ($podcastAttributes as $podcastNativeFieldKey => $podcastAttribute) {
            $postedSettings = $this->request->getBodyParam('podcastNativeFields.' . $podcastNativeFieldKey);
            $tempVar = 'translatable' . $podcastNativeFieldKey;
            $$tempVar = $postedSettings['translatable'];

            $podcastNativeFieldSettings = [];
            $podcastNativeFieldSettings['translatable'] = $postedSettings['translatable'] ?? null;
            $podcastNativeFieldsSettings[$podcastNativeFieldKey] = $podcastNativeFieldSettings;
        }
        $podcastFormat->nativeSettings = json_encode($podcastNativeFieldsSettings);

        // Podcast Mapping
        $podcastMappings = [];
        $podcastPostedFields = $this->request->getBodyParam('podcastFields');
        $nativeFields = $podcastFormat->mappingAttributes();
        foreach ($nativeFields as $nativeFieldKey => $nativeField) {
            $mapping = new Mapping();
            if (isset($podcastPostedFields[$nativeFieldKey])) {
                $mapping->container = $podcastPostedFields[$nativeFieldKey]['containerField'];
                $mapping->field = $podcastPostedFields[$nativeFieldKey]['craftField'];
                $mapping->type = $podcastPostedFields[$nativeFieldKey]['convertTo'];
                $validate = $validate && $mapping->validate();
            }
            $podcastMappings[$nativeFieldKey] = $mapping;
        }
        $podcastFormat->mapping = json_encode($podcastMappings);

        // Episode native field settings
        $episodeNativeFieldsSettings = [];
        $episodeNativeFields = $podcastFormatEpisode->episodeNativeFields();
        foreach ($episodeNativeFields as $episodeNativeFieldKey => $episodeAttribute) {
            $postedSettings = $this->request->getBodyParam('episodeNativeFields.' . $episodeNativeFieldKey);
            $tempVar = 'translatable' . $episodeNativeFieldKey;
            $$tempVar = $postedSettings['translatable'];

            $episodeNativeFieldSettings = [];
            $episodeNativeFieldSettings['translatable'] = $postedSettings['translatable'] ?? null;
            $episodeNativeFieldsSettings[$episodeNativeFieldKey] = $episodeNativeFieldSettings;
        }
        $podcastFormatEpisode->nativeSettings = json_encode($episodeNativeFieldsSettings);

        // Episode mapping fields
        $episodeMappings = [];
        $episodePostedFields = $this->request->getBodyParam('episodeFields');
        $nativeFields = $podcastFormatEpisode->mappingAttributes();
        foreach ($nativeFields as $nativeFieldKey => $nativeField) {
            $mapping = new Mapping();
            if (isset($episodePostedFields[$nativeFieldKey])) {
                $mapping->container = $episodePostedFields[$nativeFieldKey]['containerField'];
                $mapping->field = $episodePostedFields[$nativeFieldKey]['craftField'];
                $mapping->type = $episodePostedFields[$nativeFieldKey]['convertTo'];
                $validate = $validate && $mapping->validate();
            }
            $episodeMappings[$nativeFieldKey] = $mapping;
        }
        $podcastFormatEpisode->mapping = json_encode($episodeMappings);

        // Site settings
        $sitesSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $podcastPostedSettings = $this->request->getBodyParam('podcastSites.' . $site->handle);
            $episodePostedSettings = $this->request->getBodyParam('episodeSites.' . $site->handle);
            // Skip disabled sites
            if (!$podcastPostedSettings['enabled']) {
                continue;
            }

            $siteSettings = new PodcastFormatSite();
            $siteSettings->siteId = $site->id;
            $siteSettings->podcastUriFormat = $podcastPostedSettings['podcastUriFormat'] ?? null;
            $siteSettings->podcastTemplate = $podcastPostedSettings['podcastTemplate'] ?? null;
            $siteSettings->podcastEnabledByDefault = (bool) $podcastPostedSettings['podcastEnabledByDefault'];
            $siteSettings->episodeUriFormat = $episodePostedSettings['episodeUriFormat'] ?? null;
            $siteSettings->episodeTemplate = $episodePostedSettings['episodeTemplate'] ?? null;
            $siteSettings->episodeEnabledByDefault = (bool) $episodePostedSettings['episodeEnabledByDefault'];
            $validate = $validate && $siteSettings->validate();
            $sitesSettings[$site->id] = $siteSettings;
        }

        $podcastFormat->setSiteSettings($sitesSettings);

        // Set the podcast field layout
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost('podcast');
        $fieldLayout->type = Podcast::class;
        $podcastFormat->setFieldLayout($fieldLayout);

        // Set the episode field layout
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost('episode');
        $fieldLayout->type = Episode::class;
        $podcastFormatEpisode->setFieldLayout($fieldLayout);

        $validate = $validate && $podcastFormat->validate() && $podcastFormatEpisode->validate();
        if (!$validate) {
            $this->setFailFlash(Craft::t('studio', 'Couldn’t save podcast format because of validation error'));
            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();

            // Send the podcast format back
            $urlManager->setRouteParams([
                'podcastFormat' => $podcastFormat,
                'podcastFormatEpisode' => $podcastFormatEpisode,
                'podcastFormatSites' => $sitesSettings,
            ]);

            return null;
        }

        // Save podcast format
        if (!Studio::$plugin->podcastFormats->savePodcastFormat($podcastFormat, $podcastFormatEpisode, $sitesSettings, false)) {
            $this->setFailFlash(Craft::t('studio', 'Couldn’t save podcast format.'));

            /** @var UrlManager $urlManager */
            $urlManager = Craft::$app->getUrlManager();

            // Send the podcast format back
            $urlManager->setRouteParams([
                'podcastFormat' => $podcastFormat,
                'podcastFormatEpisode' => $podcastFormatEpisode,
                'podcastFormatSites' => $sitesSettings,
            ]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('studio', 'Podcast format saved.'));
        return $this->redirectToPostedUrl();
    }

    /**
     * Deletes a podcast format.
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $podcastFormatId = $this->request->getRequiredBodyParam('id');
        Studio::$plugin->podcastFormats->deletePodcastFormatById($podcastFormatId);

        return $this->asSuccess();
    }
}
