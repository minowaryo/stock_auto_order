<?php

namespace Tests\Unit\Livewire;

use App\Livewire\TradeHistory\Import;
use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Livewire component member names — Red phase Unit Test
|--------------------------------------------------------------------------
|
| Livewire 4 evaluates an action expression such as wire:submit="preview"
| as $wire.preview, and $wire returns a public property before falling
| back to a method call. When a component has both a public property and
| a public method with the same name, the button reads the property instead
| of calling the method: no request is sent, and the form stays disabled.
| Livewire::test()->call() calls the method directly, so screen tests
| cannot catch this. See docs/ai-context/known-pitfalls.md.
|
*/

uses(TestCase::class);

/**
 * @return array<int, class-string<Component>>
 */
function livewireComponentClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path('Livewire'))->name('*.php') as $file) {
        $class = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class) && is_subclass_of($class, Component::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('Livewireコンポーネントの一覧を取得できる（空振り防止）', function () {
    expect(livewireComponentClasses())->toContain(Import::class);
});

test('Livewireコンポーネントは、公開プロパティと同じ名前の公開メソッドを持たない', function () {
    $collisions = [];

    foreach (livewireComponentClasses() as $class) {
        $reflection = new ReflectionClass($class);

        $properties = array_map(
            fn (ReflectionProperty $p) => $p->getName(),
            array_filter($reflection->getProperties(ReflectionProperty::IS_PUBLIC), fn (ReflectionProperty $p) => ! $p->isStatic()),
        );
        $methods = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach (array_intersect($properties, $methods) as $name) {
            $collisions[] = "{$class}::{$name}";
        }
    }

    expect($collisions)->toBe([]);
});
