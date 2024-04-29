<?php

namespace vnali\studio\gql\interfaces\elements;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use craft\gql\interfaces\elements\User;
use craft\helpers\Gql;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use vnali\studio\elements\Episode;
use vnali\studio\gql\types\generators\EpisodeGenerator;

class EpisodeInterface extends Element
{
    /**
     * @inheritdoc
     */
    public static function getTypeGenerator(): string
    {
        return EpisodeGenerator::class;
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
            'description' => 'This is the interface implemented by all episodes.',
            'resolveType' => function(Episode $value) {
                return $value->getGqlTypeName();
            },
        ]));

        EpisodeGenerator::generateTypes();

        return $type;
    }

    /**
     * @inheritdoc
     */
    public static function getName(): string
    {
        return 'EpisodeInterface';
    }

    /**
     * @inheritdoc
     */
    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), self::getConditionalFields(),[
            'duration' => [
                'name' => 'duration',
                'type' => Type::STRING(),
                'description' => 'Episode Duration',
            ],
            'episodeSeason' => [
                'name' => 'episodeSeason',
                'type' => Type::INT(),
                'description' => 'Episode Season',
            ],
            'seasonName' => [
                'name' => 'seasonName',
                'type' => Type::STRING(),
                'description' => 'Season Name',
            ],
            'episodeNumber' => [
                'name' => 'episodeNumber',
                'type' => Type::INT(),
                'description' => 'Episode Number',
            ],
            'episodeType' => [
                'name' => 'episodeType',
                'type' => Type::STRING(),
                'description' => 'Episode type',
            ],
            'episodeBlock' => [
                'name' => 'episodeBlock',
                'type' => Type::boolean(),
                'description' => 'Episode block',
            ],
            'episodeExplicit' => [
                'name' => 'episodeExplicit',
                'type' => Type::boolean(),
                'description' => 'Episode Explicit',
            ],
            'publishOnRSS' => [
                'name' => 'publishOnRSS',
                'type' => Type::boolean(),
                'description' => 'Publish on RSS',
            ],
            'episodeGUID' => [
                'name' => 'episodeGUID',
                'type' => Type::STRING(),
                'description' => 'GUID',
            ],
            'podcastId' => [
                'name' => 'podcastId',
                'type' => Type::INT(),
                'description' => 'Podcast ID',
            ],
        ]), self::getName());
    }

    /**
     * @inheritdoc
     */
    protected static function getConditionalFields(): array
    {
        $fields = [];
        if (Gql::canQueryUsers()) {
            $fields = array_merge($fields, [
                'uploaderId' => [
                    'name' => 'uploaderId',
                    'type' => Type::int(),
                    'description' => 'The ID of the uploader of this episode.',
                ],
                'uploader' => [
                    'name' => 'uploader',
                    'type' => User::getType(),
                    'description' => 'The episode’s uploader.',
                    'complexity' => Gql::eagerLoadComplexity(),
                ],
            ]);
        }
        return $fields;
    }
}
