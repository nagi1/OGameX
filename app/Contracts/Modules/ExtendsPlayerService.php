<?php

namespace OGame\Contracts\Modules;

use OGame\Services\PlayerService;

/**
 * Contract for modules that inject player-level data or state,
 * such as per-player counters, flags, or custom progression data.
 *
 * Register implementations through the Extensions facade.
 */
interface ExtendsPlayerService
{
    /**
     * Called when the PlayerService is booted for a player.
     * Attach module-specific data to the player as needed.
     */
    public function extendPlayer(PlayerService $player): void;
}
