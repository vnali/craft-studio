<?php

namespace vnali\studio\gql\directives;

use craft\gql\base\Directive;
use craft\gql\GqlEntityRegistry;
use GraphQL\Language\DirectiveLocation;
use GraphQL\Type\Definition\Directive as GqlDirective;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use vnali\studio\Studio;

/**
 * Transcript GraphQL directive
 */
class Transcript extends Directive
{
    private const DEFAULT_FORMAT = 'vtt';

    private const FORMAT_HTML = 'html';
    private const FORMAT_JSON = 'json';
    private const FORMAT_SRT = 'srt';
    private const FORMAT_TEXT = 'text';
    private const FORMAT_VTT = 'vtt';

    private const FORMATS = [
        self::FORMAT_HTML,
        self::FORMAT_JSON,
        self::FORMAT_SRT,
        self::FORMAT_TEXT,
        self::FORMAT_VTT,
    ];

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
                new FieldArgument([
                    'name' => 'format',
                    'type' => Type::string(),
                    'defaultValue' => 'vtt',
                    'description' => 'The format to use. Can be `srt`, `vtt`, `json`, `html`, `text`.',
                ]),
            ],
            'description' => 'Convert transcript to other formats',
        ]));
    }

    public static function name(): string
    {
        return 'transcript';
    }

    public static function apply(mixed $source, mixed $value, array $arguments, ResolveInfo $resolveInfo): mixed
    {
        $format = (isset($arguments['format']) && in_array($arguments['format'], self::FORMATS, true)) ? $arguments['format'] : self::DEFAULT_FORMAT;
        $captionContent = Studio::$plugin->episodes->transcript($format, $value);
        return $captionContent;
    }
}
