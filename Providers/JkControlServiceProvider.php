<?php

namespace Modules\JkControl\Providers;

use App\Extension\FilterRegistry;
use App\Models\MachineTag;
use App\Models\ProductionDowntime;
use App\Models\ScrapEntry;
use App\Models\Shift;
use App\Services\MenuRegistry;
use App\Services\WidgetRegistry;
use Illuminate\Support\ServiceProvider;
use Modules\JkControl\Import\IdealCycleImporter;
use Modules\JkControl\Models\IdealCycle;
use Modules\JkControl\Services\MachineOeeReport;

/**
 * Entry point of the JkControl module. Only loaded while the module is enabled
 * in Admin → Modules.
 */
class JkControlServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jkcontrol.php', 'jkcontrol');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        // Notes this module writes (on stops, quality checks) and its API messages,
        // in the system language; the frontend merges modules/*/lang on its own.
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        // Machine OEE: a menu entry, a dashboard card linking to it, and the ideal
        // cycles it needs as an entity of Admin → Import.
        $url = url('/jkcontrol/oee');
        app(MenuRegistry::class)->addItem('production', __('Machine OEE'), $url, order: 60);
        app(MenuRegistry::class)->addItem('production', __('Machine OEE report'), url('/jkcontrol/oee/report'), order: 61);
        app(WidgetRegistry::class)->register('kpi', [
            'title' => __('Machine OEE'),
            'body' => __('Live gauges per machine'),
            'href' => $url,
        ], order: 40);
        app(FilterRegistry::class)->addFilter('import.entities', fn (array $entities) => [...$entities, IdealCycleImporter::class]);

        $this->forgetMachineOeeWhenInputsChange();
    }

    /**
     * Kept machine OEE periods (MachineOeeReport) are measured again when what
     * they were measured from changes after the fact.
     */
    private function forgetMachineOeeWhenInputsChange(): void
    {
        $all = fn () => MachineOeeReport::forget();
        foreach ([IdealCycle::class, Shift::class] as $model) {
            $model::saved($all);
            $model::deleted($all);
        }

        $stop = function (ProductionDowntime $stop) {
            if ($stop->workstation_id && $stop->started_at) {
                MachineOeeReport::forget($stop->workstation_id, $stop->started_at, $stop->ended_at ?? now());
            }
        };
        ProductionDowntime::saved($stop);
        ProductionDowntime::deleted($stop);

        $scrap = function (ScrapEntry $entry) {
            if ($entry->reported_at) {
                MachineOeeReport::forget(null, $entry->reported_at->copy()->subSecond(), $entry->reported_at->copy()->addSecond());
            }
        };
        ScrapEntry::saved($scrap);
        ScrapEntry::deleted($scrap);

        $tag = function (MachineTag $tag) {
            if ($tag->workstation_id) {
                MachineOeeReport::forget($tag->workstation_id);
            }
        };
        MachineTag::saved($tag);
        MachineTag::deleted($tag);
    }
}
