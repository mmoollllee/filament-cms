<?php

namespace Mmoollllee\Cms\Enums;

enum TenantUserRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Admin->value => 'Admin',
            self::Editor->value => 'Editor',
        ];
    }
}
