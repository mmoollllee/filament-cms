<?php

namespace Mmoollllee\Cms\Contracts;

/**
 * Implemented by models whose records have a public frontend page — the topbar
 * "Öffnen" button targets it while the record is open in the panel
 * ({@see \Mmoollllee\Cms\Filament\Support\FrontendLinkResolver}).
 *
 * App models beyond content (site-extension resources) may implement it too;
 * the resolver only asks for the interface, never for a concrete class.
 */
interface HasFrontendUrl
{
    /**
     * Absolute public frontend URL for this record, or null when it has no
     * frontend page of its own (the button then falls back to the homepage).
     */
    public function getFrontendUrl(): ?string;
}
