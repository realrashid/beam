<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use RealRashid\Beam\BeamManager;
use RealRashid\Beam\Call;
use RealRashid\Beam\PendingCall;
use RealRashid\Beam\PendingRoom;
use RealRashid\Beam\Room;
use RealRashid\Beam\Testing\BeamFake;
use RealRashid\Beam\Testing\PendingRingAssertion;

/**
 * Boost guidelines are Blade files, and Boost renders them before handing them
 * to an assistant. Every unescaped `{{ }}` and every `@directive` in an
 * example is therefore executed, not documented.
 *
 * laravel-driverjs v1.0.0 shipped a guideline that fatalled on compile and,
 * once that was fixed, turned out to *execute* `@driverjsTour` into a live
 * tour bootstrap inside the guideline text. Nothing caught either, because
 * nothing had ever rendered the file. This test renders it the way Boost does.
 */
function guidelineFiles(): array
{
    return glob(__DIR__.'/../resources/boost/guidelines/*.blade.php') ?: [];
}

it('ships at least one Boost guideline and a skill', function () {
    expect(guidelineFiles())->not->toBeEmpty();
    expect(__DIR__.'/../resources/boost/skills/beam-development/SKILL.md')->toBeFile();
});

it('renders every guideline through Blade without throwing', function () {
    foreach (guidelineFiles() as $file) {
        expect(Blade::render(file_get_contents($file)))->toBeString()->not->toBeEmpty();
    }
});

it('renders every guideline as prose, not as executed Blade', function () {
    foreach (guidelineFiles() as $file) {
        $rendered = Blade::render(file_get_contents($file));

        expect($rendered)->not->toContain('name="_token"');   // an executed @csrf
        expect($rendered)->not->toContain('<script');          // an executed directive
        expect($rendered)->not->toContain('<?php');            // leaked raw PHP
    }
});

it('keeps every code snippet intact through Blade', function () {
    foreach (guidelineFiles() as $file) {
        $source = file_get_contents($file);
        $rendered = Blade::render($source);

        // Every <code-snippet> in the source must survive verbatim.
        preg_match_all('/<code-snippet[^>]*>(.*?)<\/code-snippet>/s', $source, $m);

        foreach ($m[1] as $snippet) {
            expect($rendered)->toContain(trim($snippet));
        }
    }
});

it('only names PHP methods that exist', function () {
    $files = [...guidelineFiles(), __DIR__.'/../resources/boost/skills/beam-development/SKILL.md'];
    $text = implode("\n", array_map('file_get_contents', $files));

    // Beam::x( on the facade; ->x( chained on builders and results.
    preg_match_all('/Beam::([a-zA-Z]+)\(/', $text, $facade);
    preg_match_all('/->([a-z][a-zA-Z]+)\(/', $text, $chained);

    $manager = get_class_methods(BeamManager::class);
    $facadeExtras = ['macro', 'fake', 'assertRang', 'assertNotRang', 'assertRoomCreated', 'assertNothingRang'];
    $chainable = array_unique(array_merge(
        get_class_methods(PendingCall::class),
        get_class_methods(PendingRoom::class),
        get_class_methods(Call::class),
        get_class_methods(Room::class),
        get_class_methods(BeamFake::class),
        get_class_methods(PendingRingAssertion::class),
        // Laravel and application-side calls that appear in the examples.
        // Laravel, Livewire, test-suite and example-app calls that appear in the examples.
        ['notify', 'update', 'create', 'listen', 'define', 'routeNotificationForBeam', 'from', 'to', 'toBe', 'dispatch', 'actingAs', 'post', 'isAgent', 'hasOpenTicketWith'],
    ));

    $missingFacade = array_values(array_diff(array_unique($facade[1]), $manager, $facadeExtras));
    $missingChained = array_values(array_diff(array_unique($chained[1]), $chainable));

    expect($missingFacade)->toBe([], 'Beam:: methods named in the Boost files but missing on the manager: '.implode(', ', $missingFacade));
    expect($missingChained)->toBe([], '->methods named in the Boost files but missing on every builder and result: '.implode(', ', $missingChained));
});

it('only names JavaScript methods that exist on the client', function () {
    $text = implode("\n", array_map('file_get_contents', [...guidelineFiles(), __DIR__.'/../resources/boost/skills/beam-development/SKILL.md']));
    preg_match_all('/\bBeam\.([a-zA-Z]+)\(/', $text, $m);
    $client = file_get_contents(__DIR__.'/../resources/js/beam.ts');

    $missing = array_values(array_filter(array_unique($m[1]), fn (string $method) => ! preg_match('/static (async )?'.$method.'\(/', $client)));

    expect($missing)->toBe([], 'Beam.x() named in the Boost files but missing on the client: '.implode(', ', $missing));
});
