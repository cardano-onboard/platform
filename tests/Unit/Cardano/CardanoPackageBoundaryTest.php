<?php

namespace Tests\Unit\Cardano;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * The application's half of the package boundary.
 *
 * The Cardano code is two dependencies, declared in composer.json and reached through their autoload roots. Both are
 * published now, so both arrive from a registry like any other dependency and neither has a directory in this
 * repository for a controller to reach into.
 *
 * The in-repo half of this test is kept rather than deleted, with nothing listed under it. Vendoring a package back
 * into the tree is a thing somebody will reasonably do again, to iterate on a package and an application together,
 * and the shape that arrangement has to hold is worth keeping written down. Listing a package there is then the
 * whole of what declaring it costs.
 *
 * Two things are asserted of every package, whichever of the two it is. The application only names classes the
 * package publishes, so a class the package calls its own machinery cannot quietly become part of what the
 * application depends on. And the application reaches the package through composer rather than through a path, so
 * no file is opened out of a package directory and no autoload rule points into its source.
 *
 * What differs is how each package is supplied, and that is the whole of what publishing changes. A package still
 * in this repository is supplied by a path repository and its suite is run from here, because here is the only
 * place it can run. A published package is supplied by the registry, carries no path repository, and its suite
 * runs in its own repository rather than this one. Asserting both shapes means the day a package moves from one to
 * the other, the leftovers of the old arrangement cannot survive unnoticed.
 */
class CardanoPackageBoundaryTest extends TestCase
{
    /** Where application code lives. Everything here is subject to the boundary. */
    private const APPLICATION_TREES = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests'];

    /**
     * Packages published to a registry and installed from it.
     *
     * @return array<string, array{string, string, string}> name => [package, namespace, former path]
     */
    public static function publishedPackages(): array
    {
        return [
            'the chain-data package' => ['cardano-php/data-client', 'CardanoPhp\\DataClient\\', 'packages/cardano-data-client'],
            'the transaction package' => ['cardano-php/transaction', 'Cardano\\Transaction\\', 'packages/cardano-transaction'],
        ];
    }

    /**
     * Packages this repository still carries and supplies through a path repository.
     *
     * @return array<string, array{string, string, string}> name => [package, namespace, path]
     */
    public static function inRepoPackages(): array
    {
        return [];
    }

    /**
     * Every package, however it is supplied. The boundary itself does not care which.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function packages(): array
    {
        return array_merge(self::publishedPackages(), self::inRepoPackages());
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function applicationFiles(): array
    {
        $files = [];

        foreach (self::APPLICATION_TREES as $tree) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($tree), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if ($file->getPathname() === __FILE__) {
                    continue;
                }

                $files[substr($file->getPathname(), strlen(base_path()) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Class names as they appear in source, whether written bare or escaped inside a string literal.
     *
     * @return list<string>
     */
    private static function packageSymbolsIn(string $source, string $namespace): array
    {
        $normalized = str_replace('\\\\', '\\', $source);

        // preg_quote turns the namespace's single backslashes into escaped ones, which is what the pattern needs.
        $root = preg_quote(rtrim($namespace, '\\'), '/');

        preg_match_all('/'.$root.'(?:\\\\[A-Za-z0-9_]+)+/', $normalized, $matches);

        return array_values(array_unique($matches[0]));
    }

    private static function isInternal(string $class): bool
    {
        $doc = (new ReflectionClass($class))->getDocComment();

        return is_string($doc) && str_contains($doc, '@internal');
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        return json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private static function pathRepositoryUrls(): array
    {
        return array_column(array_filter(
            self::manifest()['repositories'] ?? [],
            static fn (array $repository): bool => ($repository['type'] ?? null) === 'path',
        ), 'url');
    }

    /**
     * Anything the application names has to be a class the package publishes: it has to exist, and it must not be
     * marked internal. A name that does not resolve is the more likely failure of the two, because it is what a
     * stale import or a hand-written string left behind by a rename looks like.
     */
    #[DataProvider('packages')]
    public function test_the_application_only_names_classes_the_package_publishes(string $package, string $namespace): void
    {
        $violations = [];

        foreach (self::applicationFiles() as $path => $source) {
            foreach (self::packageSymbolsIn($source, $namespace) as $symbol) {
                if (str_starts_with($symbol, $namespace.'Tests\\')) {
                    $violations[] = $path.' names '.$symbol.', which is one of the package\'s own tests';

                    continue;
                }

                if (! class_exists($symbol) && ! interface_exists($symbol) && ! enum_exists($symbol)) {
                    $violations[] = $path.' names '.$symbol.', which the package does not publish';

                    continue;
                }

                if (self::isInternal($symbol)) {
                    $violations[] = $path.' names '.$symbol.', which the package marks @internal';
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['The application has reached past the public surface of '.$package.':'],
            $violations,
            [
                '',
                'The public surface is every class under '.$namespace.' without an @internal tag on it.',
                'If one of these belongs in the application, publish it from the package first.',
            ],
        )));
    }

    /**
     * No file is opened out of a package directory, and no autoload rule points into its source. For a package
     * still in the repository either one would work today and stop working the moment the directory moved. For a
     * published one the directory is already gone, so either one is a reference to something that is not there.
     */
    #[DataProvider('packages')]
    public function test_the_application_reaches_the_package_through_composer_and_not_through_a_path(string $package, string $namespace, string $packagePath): void
    {
        $violations = [];

        foreach (self::applicationFiles() as $path => $source) {
            if (str_contains($source, $packagePath)) {
                $violations[] = $path.' names the path '.$packagePath;
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['The application reads '.$package.' out of its directory rather than through composer:'],
            $violations,
        )));

        foreach (self::manifest()['autoload']['psr-4'] as $prefix => $target) {
            $this->assertStringStartsNotWith(
                'packages/',
                $target,
                'The application autoloads '.$prefix.' out of a package tree instead of requiring the package.'
            );
            $this->assertNotSame(
                $namespace,
                $prefix,
                'The application still owns the '.$package.' namespace.'
            );
        }
    }

    /**
     * Every package is required by a caret constraint, whoever supplies it. A branch or dev constraint would have
     * to change at release; a caret constraint does not.
     */
    #[DataProvider('packages')]
    public function test_the_package_is_required_by_a_version_constraint(string $package): void
    {
        $manifest = self::manifest();

        $this->assertArrayHasKey(
            $package,
            $manifest['require'],
            'The application does not require '.$package.' at all.'
        );

        $constraint = $manifest['require'][$package];

        $this->assertMatchesRegularExpression(
            '/^\^\d+\.\d+/',
            $constraint,
            'The requirement is '.$constraint.'. A branch or dev constraint would have to change at release; a caret constraint does not.'
        );

        $this->assertTrue(
            is_link(base_path('vendor/'.$package)) || is_dir(base_path('vendor/'.$package)),
            $package.' is required but not installed, so nothing proves the constraint resolves.'
        );
    }

    /**
     * What supplies a package still in this repository is the path repository, and that is the only line that has
     * to go when the package is published.
     */
    public function test_a_path_repository_exists_for_every_in_repo_package_and_for_nothing_else(): void
    {
        $paths = self::pathRepositoryUrls();

        $expected = array_column(self::inRepoPackages(), 2);
        sort($expected);
        sort($paths);

        foreach (self::inRepoPackages() as [$package, , $packagePath]) {
            $this->assertContains($packagePath, $paths, 'Nothing supplies '.$package.' until it is published.');
        }

        $this->assertSame(
            $expected,
            $paths,
            'There should be one path repository per package still in this repository and no others. '
            .'With every package published there should be none at all, because a path repository always '
            .'resolves to whatever is on disk, which makes the version constraint beside it decorative.'
        );
    }

    /**
     * A package's own suite is run from here while it is in this repository, which is the only reason its test
     * namespace is autoloadable at all. Its tests must run somewhere, or moving a test into the package is a way
     * of switching it off.
     */
    public function test_an_in_repo_packages_own_tests_are_run_by_this_repository(): void
    {
        $manifest = self::manifest();

        // Every configuration in the tree, rather than a list written down here. The published edition renames
        // one of them and a list would go stale on the day that happened, in a test the published edition runs.
        $configurations = glob(base_path('phpunit*.xml')) ?: [];

        $this->assertNotSame([], $configurations, 'There is no phpunit configuration in the repository at all.');

        foreach (self::inRepoPackages() as [$package, $namespace, $packagePath]) {
            $this->assertSame(
                $packagePath.'/tests/',
                $manifest['autoload-dev']['psr-4'][$namespace.'Tests\\'] ?? null,
                'The test namespace of '.$package.' is not autoloadable, so its suite cannot run from here.'
            );

            foreach ($configurations as $configuration) {
                $this->assertStringContainsString(
                    $packagePath.'/tests',
                    (string) file_get_contents($configuration),
                    basename($configuration).' does not run the suite of '.$package.'.'
                );
            }
        }
    }

    /**
     * Publishing a package leaves three things behind that all still work, and each of them would keep the
     * application on the copy in this repository rather than the one the registry serves. The directory itself,
     * the path repository that pointed at it, and the autoload and phpunit entries that ran its suite from here.
     */
    #[DataProvider('publishedPackages')]
    public function test_a_published_package_keeps_nothing_of_its_time_in_this_repository(string $package, string $namespace, string $formerPath): void
    {
        $this->assertDirectoryDoesNotExist(
            base_path($formerPath),
            $package.' is published, but its directory is still here. Whichever copy composer resolves, one of the two is not the one being read.'
        );

        $this->assertNotContains(
            $formerPath,
            self::pathRepositoryUrls(),
            $package.' is published, but a path repository still supplies it, so the registry version is never installed.'
        );

        $this->assertArrayNotHasKey(
            $namespace.'Tests\\',
            self::manifest()['autoload-dev']['psr-4'] ?? [],
            'The test namespace of '.$package.' is still autoloadable here, pointing at a directory that is gone.'
        );

        foreach (glob(base_path('phpunit*.xml')) ?: [] as $configuration) {
            $this->assertStringNotContainsString(
                $formerPath,
                (string) file_get_contents($configuration),
                basename($configuration).' still runs the suite of '.$package.' from a directory that is gone.'
            );
        }
    }
}
