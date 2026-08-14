<?php

namespace Tests\Unit;

use OGame\Extensions\ExtensionRegistry;
use OGame\Extensions\ModuleExtension;
use OGame\GameObjects\Models\Abstracts\GameObject;
use OGame\GameObjects\Models\ShipObject;
use OGame\Services\ObjectService;
use Tests\TestCase;

/**
 * Guards the core object catalog. A module-system regression once caused core
 * ships to be returned twice by the per-category accessors; these assertions
 * pin the catalog to a single instance per object.
 */
class CoreObjectIntegrityTest extends TestCase
{
    private ExtensionRegistry $extensions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extensions = app(ExtensionRegistry::class);
        $this->extensions->flush();
    }

    protected function tearDown(): void
    {
        $this->extensions->flush();

        parent::tearDown();
    }

    public function test_core_ship_objects_are_not_duplicated(): void
    {
        $this->assertUnique(ObjectService::getShipObjects());
        $this->assertUnique(ObjectService::getMilitaryShipObjects());
        $this->assertUnique(ObjectService::getCivilShipObjects());
    }

    public function test_core_building_station_research_and_defense_objects_are_not_duplicated(): void
    {
        $this->assertUnique(ObjectService::getBuildingObjects());
        $this->assertUnique(ObjectService::getStationObjects());
        $this->assertUnique(ObjectService::getResearchObjects());
        $this->assertUnique(ObjectService::getDefenseObjects());
    }

    public function test_module_ships_are_added_without_duplicating_core_ships(): void
    {
        $coreMilitaryCount = count(ObjectService::getMilitaryShipObjects());
        $coreCivilCount = count(ObjectService::getCivilShipObjects());

        $militaryShip = new ShipObject();
        $militaryShip->id = 10001;
        $militaryShip->machine_name = 'module_attack_ship';
        $militaryShip->isMilitary = true;

        $civilShip = new ShipObject();
        $civilShip->id = 10002;
        $civilShip->machine_name = 'module_cargo_ship';
        $civilShip->isMilitary = false;

        $this->extensions->module('mymodule', function (ModuleExtension $module) use ($militaryShip, $civilShip): void {
            $module->objects([$militaryShip, $civilShip]);
        });

        $militaryShips = ObjectService::getMilitaryShipObjects();
        $civilShips = ObjectService::getCivilShipObjects();

        // Module ships are appended exactly once; core ships are not re-added.
        $this->assertCount($coreMilitaryCount + 1, $militaryShips);
        $this->assertCount($coreCivilCount + 1, $civilShips);
        $this->assertUnique($militaryShips);
        $this->assertUnique($civilShips);
        $this->assertSame($militaryShip, end($militaryShips));
        $this->assertSame($civilShip, end($civilShips));
    }

    /**
     * @param  array<int, GameObject>  $objects
     */
    private function assertUnique(array $objects): void
    {
        $ids = array_map(static fn (GameObject $object): int => $object->id, $objects);
        $names = array_map(static fn (GameObject $object): string => $object->machine_name, $objects);

        $this->assertCount(count($objects), array_unique($ids), 'Duplicate object IDs detected.');
        $this->assertCount(count($objects), array_unique($names), 'Duplicate object machine names detected.');
    }
}
