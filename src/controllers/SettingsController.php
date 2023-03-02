<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\controllers;

use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;

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
}
