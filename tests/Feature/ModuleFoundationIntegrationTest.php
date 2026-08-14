<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Extensions\ExtensionRegistry;
use OGame\Extensions\ModuleExtension;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameObjects\Models\BuildingObject;
use OGame\GameObjects\Models\ResearchObject;
use OGame\GameObjects\Models\ShipObject;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\ModuleQueueService;
use OGame\Services\ModuleStateService;
use Tests\TestCase;

/** Exercises the core-owned persistence primitives that enabled modules use. */
class ModuleFoundationIntegrationTest extends TestCase
{
    private ExtensionRegistry $extensions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beginTransaction();
        $this->extensions = app(ExtensionRegistry::class);
        $this->extensions->flush();
    }

    protected function tearDown(): void
    {
        $this->extensions->flush();
        DB::rollBack();

        parent::tearDown();
    }

    public function test_module_objects_use_normal_game_services_without_core_columns(): void
    {
        [$user, $planet] = $this->createPlayerAndPlanet();

        $building = $this->building('custom_building', 10000);
        $ship = $this->ship('custom_ship', 10001);
        $research = $this->research('custom_research', 10002);

        $this->extensions->module('mymodule', function (ModuleExtension $module) use ($building, $ship, $research): void {
            $module->objects([$building, $ship, $research]);
        });

        $player = resolve(PlayerServiceFactory::class)->make($user->id, true);
        $planetService = resolve(PlanetServiceFactory::class)->makeForPlayer($player, $planet->id, false);
        $planetService->setObjectLevel($building->id, 3);
        $planetService->setBuildingPercent($building->id, 7);
        $planetService->addUnit($ship->machine_name, 5);
        $planetService->removeUnit($ship->machine_name, 2);
        $player->setResearchLevel($research->machine_name, 4);

        $this->assertSame(3, $planetService->getObjectLevel($building->machine_name));
        $this->assertSame(7, $planetService->getBuildingPercent($building->machine_name));
        $this->assertSame(3, $planetService->getObjectAmount($ship->machine_name));
        $this->assertSame(4, $player->getResearchLevel($research->machine_name));

        $this->assertDatabaseHas('module_object_states', [
            'module_alias' => 'mymodule',
            'scope' => 'planet',
            'owner_id' => $planet->id,
            'machine_name' => $building->machine_name,
            'amount' => 3,
        ]);
        $this->assertDatabaseHas('module_object_states', [
            'module_alias' => 'mymodule',
            'scope' => 'player',
            'owner_id' => $user->id,
            'machine_name' => $research->machine_name,
            'amount' => 4,
        ]);
        $this->assertSame('7', DB::table('module_states')->where([
            'module_alias' => 'mymodule',
            'scope' => 'planet',
            'owner_id' => $planet->id,
            'key' => 'objects.custom_building.production_percent',
        ])->value('value'));

        $planet->refresh();
        $this->assertFalse(array_key_exists($building->machine_name, $planet->getAttributes()));
        $this->assertFalse(array_key_exists($ship->machine_name, $planet->getAttributes()));
    }

    public function test_module_units_can_be_atomically_removed_through_the_standard_unit_collection_api(): void
    {
        [$user, $planet] = $this->createPlayerAndPlanet();
        $ship = $this->ship('custom_ship', 10001);

        $this->extensions->module('mymodule', function (ModuleExtension $module) use ($ship): void {
            $module->objects([$ship]);
        });

        $player = resolve(PlayerServiceFactory::class)->make($user->id, true);
        $planetService = resolve(PlanetServiceFactory::class)->makeForPlayer($player, $planet->id, false);
        $planetService->addUnit($ship->machine_name, 5);

        $units = new UnitCollection();
        $units->addUnit($ship, 3);

        $this->assertTrue($planetService->removeUnitsAtomic($units));
        $this->assertSame(2, $planetService->getObjectAmount($ship->machine_name));
    }

    public function test_module_state_is_namespaced_by_module_and_owner(): void
    {
        [$user, $planet] = $this->createPlayerAndPlanet();
        $state = resolve(ModuleStateService::class);

        $planetState = $state->module('mymodule')->forPlanet($planet);
        $planetState->put('snapshot', ['value' => 12, 'updated_at' => 100]);
        $state->module('other-module')->forPlanet($planet)->put('snapshot', ['value' => 99]);
        $state->module('mymodule')->forPlayer($user)->put('snapshot', ['value' => 1]);

        $this->assertSame(['value' => 12, 'updated_at' => 100], $planetState->get('snapshot'));
        $this->assertSame(['value' => 99], $state->module('other-module')->forPlanet($planet)->get('snapshot'));
        $this->assertSame(['value' => 1], $state->module('mymodule')->forPlayer($user)->get('snapshot'));
        $this->assertSame(['snapshot' => ['value' => 12, 'updated_at' => 100]], $planetState->all());

        $planetState->forget('snapshot');

        $this->assertSame([], $planetState->all());
        $this->assertSame(['value' => 99], $state->module('other-module')->forPlanet($planet)->get('snapshot'));
    }

    public function test_module_queue_returns_only_due_work_and_can_be_completed(): void
    {
        [, $planet] = $this->createPlayerAndPlanet();
        $queue = resolve(ModuleQueueService::class);

        $due = $queue->enqueueForPlanet('mymodule', 'example', $planet, ['kind' => 'example'], now()->subSecond());
        $queue->enqueueForPlanet('mymodule', 'example', $planet, ['kind' => 'other'], now()->addMinute());
        $queue->enqueueForPlanet('mymodule', 'maintenance', $planet, ['kind' => 'maintenance'], now()->subSecond());

        $dueItems = DB::transaction(fn () => $queue->dueForPlanet('mymodule', 'example', $planet));

        $this->assertCount(1, $dueItems);
        $dueItem = $dueItems->firstOrFail();
        $this->assertSame($due->id, $dueItem->id);
        $this->assertSame(['kind' => 'example'], $dueItem->payload);

        $queue->complete($due);

        $this->assertCount(0, DB::transaction(fn () => $queue->dueForPlanet('mymodule', 'example', $planet)));
        $this->assertDatabaseHas('module_queue_items', ['id' => $due->id]);
    }

    /** @return array{User, Planet} */
    private function createPlayerAndPlanet(): array
    {
        $user = User::factory()->create();
        $coordinates = $this->getSafeEmptyCoordinate(new Coordinate(1, 1, 1));
        $planet = Planet::factory()->create([
            'user_id' => $user->id,
            'galaxy' => $coordinates->galaxy,
            'system' => $coordinates->system,
            'planet' => $coordinates->position,
        ]);

        return [$user, $planet];
    }

    private function building(string $machineName, int $id): BuildingObject
    {
        $object = new BuildingObject();
        $object->id = $id;
        $object->machine_name = $machineName;

        return $object;
    }

    private function ship(string $machineName, int $id): ShipObject
    {
        $object = new ShipObject();
        $object->id = $id;
        $object->machine_name = $machineName;

        return $object;
    }

    private function research(string $machineName, int $id): ResearchObject
    {
        $object = new ResearchObject();
        $object->id = $id;
        $object->machine_name = $machineName;

        return $object;
    }
}
