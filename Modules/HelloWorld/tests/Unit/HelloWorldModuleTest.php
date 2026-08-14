<?php

namespace Modules\HelloWorld\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Nwidart\Modules\Facades\Module;
use OGame\Events\PlanetColonized;
use OGame\Extensions\ExtensionRegistry;
use OGame\Services\ModuleSlotService;
use Tests\TestCase;

/** Module-local reference tests for contributors to copy. */
class HelloWorldModuleTest extends TestCase
{
    private string $statusesFile;

    private string $originalStatuses;

    protected function setUp(): void
    {
        parent::setUp();

        ModuleSlotService::resetSlots();

        $this->statusesFile = base_path('modules_statuses.json');
        $this->originalStatuses = (string) file_get_contents($this->statusesFile);

        // The reference module is disabled by default. Enable it and re-boot the
        // application so its provider actually registers its contributions.
        Module::findOrFail('HelloWorld')->enable();
        $this->refreshApplication();
    }

    protected function tearDown(): void
    {
        ModuleSlotService::resetSlots();
        file_put_contents($this->statusesFile, $this->originalStatuses);

        parent::tearDown();
    }

    public function test_enabled_module_registers_its_setting_slot_and_listener(): void
    {
        $extensions = app(ExtensionRegistry::class);

        $this->assertArrayHasKey('helloworld.greeting', $extensions->settings());
        $this->assertStringContainsString('/admin/hello-world', ModuleSlotService::render('admin.nav'));
        $this->assertTrue(Event::hasListeners(PlanetColonized::class));
    }
}
