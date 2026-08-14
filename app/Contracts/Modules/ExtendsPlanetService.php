<?php

namespace OGame\Contracts\Modules;

use OGame\Services\PlanetService;

/**
 * Contract for modules that inject planet-level calculations,
 * such as additional resource production, storage, or combat modifiers.
 *
 * Register implementations through the Extensions facade.
 */
interface ExtendsPlanetService
{
    /**
     * Called during planet resource production calculation.
     * Modify the planet state or accumulate production values as needed.
     */
    public function extendResourceProduction(PlanetService $planet): void;
}
