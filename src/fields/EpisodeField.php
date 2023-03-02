<?php
/**
 * @copyright Copyright © vnali
 */

namespace vnali\studio\fields;

use Craft;

use craft\fields\BaseRelationField;
use vnali\studio\elements\Episode as EpisodeElement;

class EpisodeField extends BaseRelationField
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('studio', 'Episodes');
    }

    /**
     * @inheritdoc
     */
    public static function elementType(): string
    {
        return EpisodeElement::class;
    }

    /**
     * @inheritdoc
     */
    public static function defaultSelectionLabel(): string
    {
        return Craft::t('studio', 'Add an Episode');
    }
}
