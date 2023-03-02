<?php

namespace vnali\studio\gql\directives;

use craft\gql\base\Directive;
use craft\gql\GqlEntityRegistry;
use GraphQL\Language\DirectiveLocation;
use GraphQL\Type\Definition\Directive as GqlDirective;
use GraphQL\Type\Definition\ResolveInfo;
use vnali\studio\helpers\Time;

/**
 * SecToTime GraphQL directive
 */
class SecToTime extends Directive
{
    public static function create(): GqlDirective
    {
        if ($type = GqlEntityRegistry::getEntity(self::name())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(static::name(), new self([
            'name' => static::name(),
            'locations' => [
                DirectiveLocation::FIELD,
            ],
            'args' => [
            ],
            'description' => 'Convert duration in seconds format to HH::MM:SS',
        ]));
    }

    public static function name(): string
    {
        return 'SecToTime';
    }

    public static function apply(mixed $source, mixed $value, array $arguments, ResolveInfo $resolveInfo): mixed
    {
        return Time::sec_to_time($value);
    }
}
