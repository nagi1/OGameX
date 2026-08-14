<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Extensions\ExtensionRegistry;
use OGame\Extensions\ModuleExtension;
use OGame\Services\SettingsService;
use Tests\TestCase;

class ModuleSettingsIntegrationTest extends TestCase
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

    public function test_registered_module_setting_is_namespaced_and_read_through_scoped_settings(): void
    {
        $this->extensions->module('mymodule', function (ModuleExtension $module): void {
            $module->setting('tick_seconds')
                ->integer()
                ->default(60)
                ->min(5)
                ->label('Tick interval');
        });

        $settings = resolve(SettingsService::class);
        $settings->module('mymodule')->set('tick_seconds', 30);

        $definition = $this->extensions->settings()['mymodule.tick_seconds'];

        $this->assertSame('integer', $definition->type);
        $this->assertSame(['integer', 'min:5'], $definition->validationRules());
        $this->assertSame(30, $settings->module('mymodule')->integer('tick_seconds'));
        $this->assertDatabaseHas('settings', ['key' => 'mymodule.tick_seconds', 'value' => '30']);
    }
}
