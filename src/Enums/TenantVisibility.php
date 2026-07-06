<?php

namespace Mmoollllee\Cms\Enums;

enum TenantVisibility: string
{
    case Public = 'public';
    case Members = 'members';
    case Archived = 'archived';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Public->value => 'Öffentlich',
            self::Members->value => 'Nur Mitglieder',
            self::Archived->value => 'Archiviert',
        ];
    }
}
