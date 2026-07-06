<?php

namespace Mmoollllee\Cms\Enums;

enum ContentVisibility: string
{
    case Public = 'public';
    case Members = 'members';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Public->value => 'Öffentlich',
            self::Members->value => 'Nur Eingeloggt',
        ];
    }
}
