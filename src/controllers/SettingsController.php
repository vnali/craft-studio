<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\web\Controller;

use vnali\studio\Studio;
use vnali\studio\models\Settings;

use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Set settings for the plugin
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
    }

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
     * Return podcasts general settings template
     *
     * @return Response
     */
    public function actionGeneral($settings = null): Response
    {
        if ($settings === null) {
            $settings = Studio::$plugin->getSettings();
        }
        $variables['settings'] = $settings;
        return $this->renderTemplate(
            'studio/settings/_general',
            $variables
        );
    }

    /**
     * Save podcasts general settings
     *
     * @return Response|null
     */
    public function actionGeneralSave($settings = null): ?Response
    {
        $this->requirePostRequest();

        /** @var Settings $settings */
        $settings = Studio::$plugin->getSettings();
        $settings->checkAccessToVolumes = $this->request->getBodyParam('checkAccessToVolumes', $settings->checkAccessToVolumes);

        // Save it
        if (!Craft::$app->getPlugins()->savePluginSettings(Studio::$plugin, $settings->getAttributes())) {
            return $this->asModelFailure($settings, Craft::t('studio', 'Couldn’t save general settings.'), 'settings');
        }

        return $this->asSuccess(Craft::t('studio', 'General settings saved.'));
    }
}
