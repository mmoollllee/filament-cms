<?php

namespace Mmoollllee\Cms\Enums;

enum SocialNetwork: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Linkedin = 'linkedin';
    case Tiktok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Linkedin => 'LinkedIn',
            self::Tiktok => 'TikTok',
        };
    }

    public function icon(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $network): array => [$network->value => $network->label()])
            ->all();
    }
}
