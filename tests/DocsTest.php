<?php

declare(strict_types=1);

use RealRashid\Beam\BeamManager;
use RealRashid\Beam\Exceptions\ErrorCode;
use RealRashid\Beam\Testing\BeamFake;

/**
 * The docs cannot drift from the code without this test noticing: every PHP
 * sample parses, every static the docs call on Beam:: exists, every JS static
 * the docs call on Beam. exists in the client, and the errors page names every
 * error code and every browser-side reason.
 */
$docs = fn (): array => array_merge(...array_map(fn (string $dir) => glob(dirname(__DIR__)."/docs/{$dir}/*.md") ?: [], ['guide', 'usage', 'frontend', 'turn', 'example', 'demo']));

$fences = function (string $lang) use ($docs): array {
    $out = [];
    foreach ($docs() as $file) {
        preg_match_all('/```'.$lang.'\n(.*?)```/s', (string) file_get_contents($file), $m);
        foreach ($m[1] as $i => $code) {
            $out[basename(dirname($file)).'/'.basename($file).' #'.($i + 1)] = $code;
        }
    }

    return $out;
};

it('has PHP samples that parse', function () use ($fences) {
    $samples = $fences('php');
    expect($samples)->not->toBeEmpty();

    foreach ($samples as $where => $code) {
        $trimmed = ltrim((string) preg_replace('~^\s*//[^\n]*\n~m', '', $code));   // leading comment lines do not decide the shape
        // A config excerpt is an array's inside; give it a home so it parses.
        $code = str_starts_with($trimmed, '<?php') ? $code : (str_starts_with($trimmed, "'") ? "<?php\n\$config = [\n{$code}\n];" : "<?php\n".$code);
        $file = tempnam(sys_get_temp_dir(), 'beam-doc');
        file_put_contents($file, $code);
        $output = [];
        exec('php -l '.escapeshellarg($file).' 2>&1', $output, $status);
        unlink($file);
        expect($status)->toBe(0, "{$where} does not parse: ".implode(' ', $output));
    }
});

it('only calls Beam:: statics that exist on the manager or the fake', function () use ($fences) {
    $known = array_merge(
        array_map(fn (ReflectionMethod $m) => $m->getName(), (new ReflectionClass(BeamManager::class))->getMethods(ReflectionMethod::IS_PUBLIC)),
        array_map(fn (ReflectionMethod $m) => $m->getName(), (new ReflectionClass(BeamFake::class))->getMethods(ReflectionMethod::IS_PUBLIC)),
    );

    foreach ($fences('php') as $where => $code) {
        preg_match_all('/Beam::(\w+)\(/', $code, $m);
        foreach ($m[1] as $method) {
            expect(in_array($method, $known, true))->toBeTrue("{$where} calls Beam::{$method}(), which does not exist");
        }
    }
});

it('only calls Beam. statics that exist in the client', function () use ($fences) {
    $client = (string) file_get_contents(dirname(__DIR__).'/resources/js/beam.ts');
    preg_match_all('/^\s+static (?:async )?(?:get )?(\w+)\s*[(<]/m', $client, $m);
    $known = array_merge($m[1], ['PROTOCOL_VERSION']);

    foreach (array_merge($fences('js'), $fences('blade'), $fences('vue'), $fences('jsx')) as $where => $code) {
        preg_match_all('/\bBeam\.(\w+)\b/', $code, $calls);
        foreach (array_unique($calls[1]) as $member) {
            expect(in_array($member, $known, true))->toBeTrue("{$where} uses Beam.{$member}, which the client does not have");
        }
    }
});

/**
 * A sample can name a method that exists and still pass it something that does
 * not: the docs told four pages to call answer({ video: true }) for a year, and
 * JoinOptions has no `video` — it was accepted by the compiler, ignored at run
 * time, and would have opened the camera on a call the reader meant to keep
 * audio-only. Option names are part of the API, so they are checked too.
 */
it('passes only options that exist to join() and answer()', function () use ($fences) {
    $client = (string) file_get_contents(dirname(__DIR__).'/resources/js/beam.ts');

    preg_match('/export interface JoinOptions \{(.*?)\n\}/s', $client, $block);
    preg_match_all('/^\s*(\w+)\??:/m', $block[1] ?? '', $found);
    $known = $found[1];

    expect($known)->toContain('media');

    /** The keys of the first object literal in a call's arguments, at depth one. */
    $topLevelKeys = function (string $code, int $from): array {
        $open = strpos($code, '{', $from);
        if ($open === false) {
            return [];
        }

        $keys = [];
        $depth = 0;
        $length = strlen($code);

        for ($i = $open; $i < $length; $i++) {
            $char = $code[$i];

            if ($char === '{' || $char === '[' || $char === '(') {
                $depth++;

                continue;
            }

            if ($char === '}' || $char === ']' || $char === ')') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            if ($depth === 1 && preg_match('/\G\s*([A-Za-z_$][\w$]*)\s*:/', $code, $key, 0, $i)) {
                $keys[] = $key[1];
                $i += strlen($key[0]) - 1;
            }
        }

        return $keys;
    };

    foreach (array_merge($fences('js'), $fences('blade'), $fences('vue'), $fences('jsx'), $fences('ts')) as $where => $code) {
        preg_match_all('/\b(?:Beam\.join|\.join|\.answer)\(/', $code, $calls, PREG_OFFSET_CAPTURE);

        foreach ($calls[0] as [$call, $offset]) {
            $args = substr($code, $offset + strlen($call), 400);
            // Only a literal passed as an option object; a token argument has no braces.
            if (! str_contains(explode("\n", $args)[0], '{')) {
                continue;
            }

            foreach ($topLevelKeys($args, 0) as $key) {
                expect(in_array($key, $known, true))->toBeTrue("{$where} passes [{$key}] to {$call}), which is not a JoinOptions key (".implode(', ', $known).')');
            }
        }
    }
});

it('lists every error code and every browser reason on the errors page', function () {
    $page = (string) file_get_contents(dirname(__DIR__).'/docs/usage/errors.md');

    foreach (ErrorCode::cases() as $case) {
        expect(str_contains($page, '`'.$case->value.'`'))->toBeTrue("errors.md does not mention {$case->value}");
    }

    preg_match_all("/\| '([a-z-]+)'/", (string) file_get_contents(dirname(__DIR__).'/resources/js/errors.ts'), $m);
    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $reason) {
        expect(str_contains($page, '`'.$reason.'`'))->toBeTrue("errors.md does not mention the browser reason {$reason}");
    }
});
