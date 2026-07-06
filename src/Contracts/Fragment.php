<?php

namespace Mmoollllee\Cms\Contracts;

use Mmoollllee\Cms\Cms;
use Mmoollllee\Cms\Concerns\Fragment\ResolvesFragmentWithCascade;

/**
 * A reusable, tenant-scoped content fragment — a named bundle of builder blocks shown
 * across pages (e.g. a CTA box or shared footer copy). The concrete model is resolved
 * via {@see Cms::fragmentModel()}; the resolution + cache logic is
 * provided by {@see ResolvesFragmentWithCascade}.
 */
interface Fragment
{
    /** Whether the fragment has renderable blocks. */
    public function hasContent(): bool;
}
