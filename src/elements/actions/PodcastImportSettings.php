<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\elements\actions;

use Craft;
use craft\base\ElementAction;

class PodcastImportSettings extends ElementAction
{
    /**
     * @var string|null The trigger label
     */
    public ?string $label = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        if (!isset($this->label)) {
            $this->label = Craft::t('studio', 'Import settings');
        }
    }

    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritdoc
     */
    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        type: $type,
        bulk: false,
        validateSelection: \$selectedItems => {
            if (!Garnish.hasAttr(\$selectedItems.find('.extra-element-data'), 'data-editPodcastImportSettings')) {
                return false;
            }
            return true;
        },
        activate: \$selectedItems => {
            window.open('podcasts/podcast-import-settings?podcastId=' + \$selectedItems.find('.element').data('id'), '_self');
        },
    });
})();
JS, [static::class]);

        return null;
    }
}
