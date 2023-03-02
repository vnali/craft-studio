<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\fields;

use Craft;

use craft\elements\conditions\ElementConditionInterface;
use craft\fields\BaseRelationField;
use vnali\studio\elements\Podcast as PodcastElement;

/**
 * Podcast field
 */
class PodcastField extends BaseRelationField
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('studio', 'Podcasts');
    }

    /**
     * @inheritdoc
     */
    public static function elementType(): string
    {
        return PodcastElement::class;
    }

    /**
     * @inheritdoc
     */
    public static function defaultSelectionLabel(): string
    {
        return Craft::t('studio', 'Add a Podcast');
    }

    
    protected function createSelectionCondition(): ?ElementConditionInterface
    {
        return PodcastElement::createCondition();
    }
}
