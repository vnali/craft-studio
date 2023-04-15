<?php

namespace vnali\studio\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;

use vnali\studio\elements\Episode;
use vnali\studio\gql\interfaces\elements\EpisodeInterface;
use vnali\studio\gql\types\EpisodeType;
use vnali\studio\Studio;

class EpisodeGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    // Public Methods
    // =========================================================================
    public static function generateTypes(mixed $context = null): array
    {
        $podcastFormats = Studio::$plugin->podcastFormats->getAllPodcastFormats();
        $gqlTypes = [];

        foreach ($podcastFormats as $podcastFormat) {
            $requiredContexts = Episode::gqlScopesByContext($podcastFormat);

            if (!GqlHelper::isSchemaAwareOf($requiredContexts)) {
                if (!GqlHelper::canSchema('podcastFormats.all')) {
                    continue;
                }
            }

            $type = static::generateType($podcastFormat);
            $gqlTypes[$type->name] = $type;
        }

        return $gqlTypes;
    }

    public static function generateType(mixed $context): mixed
    {
        $typeName = Episode::gqlTypeNameByContext($context);

        if ($createdType = GqlEntityRegistry::getEntity($typeName)) {
            return $createdType;
        }
        // Get podcast Format episode context by podcast Format
        $context = Studio::$plugin->podcastFormats->getPodcastFormatEpisodeById($context->id);

        $contentFieldGqlTypes = self::getContentFields($context);
        $podcastFormatFields = Craft::$app->getGql()->prepareFieldDefinitions(array_merge(EpisodeInterface::getFieldDefinitions(), $contentFieldGqlTypes), $typeName);

        return GqlEntityRegistry::createEntity($typeName, new EpisodeType([
            'name' => $typeName,
            'fields' => function() use ($podcastFormatFields) {
                return $podcastFormatFields;
            },
        ]));
    }
}
