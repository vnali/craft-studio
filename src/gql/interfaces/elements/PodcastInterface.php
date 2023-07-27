<?php

namespace vnali\studio\gql\interfaces\elements;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use vnali\studio\elements\Podcast;
use vnali\studio\gql\types\generators\PodcastGenerator;

class PodcastInterface extends Element
{
    /**
     * @inheritdoc
     */
    public static function getTypeGenerator(): string
    {
        return PodcastGenerator::class;
    }

    /**
     * @inheritdoc
     */
    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all podcasts.',
            'resolveType' => function(Podcast $value) {
                return $value->getGqlTypeName();
            },
        ]));

        PodcastGenerator::generateTypes();

        return $type;
    }

    /**
     * @inheritdoc
     */
    public static function getName(): string
    {
        return 'PodcastInterface';
    }

    /**
     * @inheritdoc
     */
    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'copyright' => [
                'name' => 'copyright',
                'type' => Type::string(),
                'description' => 'Podcast copyright',
            ],
            'ownerEmail' => [
                'name' => 'ownerEmail',
                'type' => Type::string(),
                'description' => 'Podcast Owner Email',
            ],
            'ownerName' => [
                'name' => 'ownerName',
                'type' => Type::string(),
                'description' => 'Podcast Owner Name',
            ],
            'authorName' => [
                'name' => 'authorName',
                'type' => Type::string(),
                'description' => 'Podcast Author Name',
            ],
            'podcastType' => [
                'name' => 'podcastType',
                'type' => Type::string(),
                'description' => 'Podcast type',
            ],
            'podcastBlock' => [
                'name' => 'podcastBlock',
                'type' => Type::boolean(),
                'description' => 'Podcast block',
            ],
            'locked' => [
                'name' => 'locked',
                'type' => Type::boolean(),
                'description' => 'Podcast Locked',
            ],
            'podcastExplicit' => [
                'name' => 'podcastExplicit',
                'type' => Type::boolean(),
                'description' => 'podcast Explicit',
            ],
            'podcastComplete' => [
                'name' => 'podcastComplete',
                'type' => Type::boolean(),
                'description' => 'Podcast complete',
            ],
            'podcastIsNewFeedUrl' => [
                'name' => 'podcastIsNewFeedUrl',
                'type' => Type::boolean(),
                'description' => 'Is New Feed URL',
            ],
            'podcastLink' => [
                'name' => 'podcastLink',
                'type' => Type::string(),
                'description' => 'Podcast Link',
            ],
            'podcastRedirectTo' => [
                'name' => 'podcastRedirectTo',
                'type' => Type::string(),
                'description' => 'Redirect to',
            ],
            'medium' => [
                'name' => 'medium',
                'type' => Type::string(),
                'description' => 'Podcast Medium',
            ],
            'podcastGUID' => [
                'name' => 'podcastGUID',
                'type' => Type::STRING(),
                'description' => 'GUID',
            ],
        ]), self::getName());
    }
}
