<?php

namespace Mmoollllee\Cms\Enums;

enum ContentStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Expired = 'expired';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Draft->value => 'Entwurf',
            self::Published->value => 'Veröffentlicht',
            self::Scheduled->value => 'Geplant',
            self::Expired->value => 'Abgelaufen',
        ];
    }
}
