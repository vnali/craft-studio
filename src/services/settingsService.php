<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\services;

use Craft;
use vnali\studio\models\Settings;
use vnali\studio\Studio;

use yii\base\Component;

/**
 * Settings Class
 */
class settingsService extends Component
{
    /**
     * Save plugin settings
     *
     * @param Settings $settings
     * @return boolean
     */
    public function saveSettings($settings): bool
    {
        if (!$settings->validate()) {
            return false;
        }

        return Craft::$app->plugins->savePluginSettings(Studio::$plugin, $settings->getAttributes());
    }
}
