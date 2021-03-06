<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace Roave\SecurityAdvisories;

use DateTime;
use DateTimeZone;
use ErrorException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use UnexpectedValueException;

require_once __DIR__ . '/vendor/autoload.php';

set_error_handler(
    function ($errorCode, $message = '', $file = '', $line = 0) {
        throw new ErrorException($message, 0, $errorCode, $file, $line);
    },
    E_STRICT | E_NOTICE | E_WARNING
);

$advisoriesRepository = 'https://github.com/FriendsOfPHP/security-advisories.git';
$advisoriesExtension  = 'yaml';
$rootDir              = realpath(__DIR__ . '/..');
$buildDir             = $rootDir . '/build';
$baseComposerJson     = [
    'name' => 'roave/security-advisories',
    'type' => 'metapackage',
    'description' => 'Prevents installation of composer packages with known security vulnerabilities: '
        . 'no API, simply require it',
    'license' => 'MIT',
    'authors' => [[
        'name'  => 'Marco Pivetta',
        'role'  => 'maintainer',
        'email' => 'ocramius@gmail.com',
    ]],
];

$cleanBuildDir = function () use ($buildDir) {
    system('rm -rf ' . escapeshellarg($buildDir));
    system('mkdir ' . escapeshellarg($buildDir));
};

$cloneAdvisories = function () use ($advisoriesRepository, $buildDir) {
    system(
        'git clone '
        . escapeshellarg($advisoriesRepository)
        . ' ' . escapeshellarg($buildDir . '/security-advisories')
    );
};

/**
 * @param string $path
 *
 * @return Advisory[]
 */
$findAdvisories = function ($path) use ($advisoriesExtension) {
    $yaml = new Yaml();

    return array_map(
        function (SplFileInfo $advisoryFile) use ($yaml) {
            return Advisory::fromArrayData(
                $yaml->parse(file_get_contents($advisoryFile->getRealPath()), true)
            );
        },
        iterator_to_array(new \CallbackFilterIterator(
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            ),
            function (SplFileInfo $advisoryFile) use ($advisoriesExtension) {
                // @todo skip `vendor` dir
                return $advisoryFile->isFile() && $advisoryFile->getExtension() === $advisoriesExtension;
            }
        ))
    );
};

/**
 * @param Advisory[] $advisories
 *
 * @return Component[]
 */
$buildComponents = function (array $advisories) {
    // @todo need a functional way to do this, somehow
    $indexedAdvisories = [];
    $components        = [];

    foreach ($advisories as $advisory) {
        if (! isset($indexedAdvisories[$advisory->getComponentName()])) {
            $indexedAdvisories[$advisory->getComponentName()] = [];
        }

        $indexedAdvisories[$advisory->getComponentName()][] = $advisory;
    }

    foreach ($indexedAdvisories as $componentName => $advisories) {
        $components[$componentName] = new Component($componentName, $advisories);
    }

    return $components;
};

/**
 * @param Component[] $components
 *
 * @return string[]
 */
$buildConflicts = function (array $components) {
    $conflicts = [];

    foreach ($components as $component) {
        $conflicts[$component->getName()] = $component->getConflictConstraint();
    }

    ksort($conflicts);

    return array_filter($conflicts);
};

$buildConflictsJson = function (array $baseConfig, array $conflicts) {
    return json_encode(
        array_merge(
            $baseConfig,
            ['conflict' => $conflicts]
        ),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
};

$writeJson = function ($jsonString, $path) {
    file_put_contents($path, $jsonString . "\n");
};

$runInPath = function (callable $function, $path) {
    $originalPath = getcwd();

    chdir($path);

    try {
        $returnValue = $function();
    } finally {
        chdir($originalPath);
    }

    return $returnValue;
};

$getComposerPhar = function ($targetDir) use ($runInPath) {
    return $runInPath(
        function () {
            return system(
                'curl -sS https://getcomposer.org/installer -o composer-installer.php && php composer-installer.php'
            );
        },
        $targetDir
    );
};

$execute = function ($commandString) {
    exec($commandString, $ignored, $result);

    return $result === 0;
};

$validateComposerJson = function ($composerJsonPath) use ($runInPath, $execute) {
    $runInPath(
        function () use ($execute) {
            if (! $execute('php composer.phar validate')) {
                throw new UnexpectedValueException('Composer file validation failed');
            }
        },
        dirname($composerJsonPath)
    );
};

$commitComposerJson = function ($composerJsonPath) use ($runInPath, $execute) {
    $runInPath(
        function () use ($composerJsonPath, $execute) {
            if (! $execute('git add ' . escapeshellarg(realpath($composerJsonPath)))) {
                throw new UnexpectedValueException(sprintf(
                    'Could not add file "%s" to staged commit',
                    $composerJsonPath
                ));
            }

            $message = sprintf(
                'Committing generated "composer.json" file as per "%s"',
                (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::W3C)
            );

            $execute('git diff-index --quiet HEAD || git commit -m ' . escapeshellarg($message));
        },
        dirname($composerJsonPath)
    );
};

// cleanup:
$cleanBuildDir();
$cloneAdvisories();

// actual work:
$writeJson(
    $buildConflictsJson(
        $baseComposerJson,
        $buildConflicts(
            $buildComponents(
                $findAdvisories($buildDir . '/security-advisories')
            )
        )
    ),
    $rootDir . '/composer.json'
);

$getComposerPhar($rootDir);
$validateComposerJson($rootDir . '/composer.json');

$commitComposerJson($rootDir . '/composer.json');

echo 'Completed!' . PHP_EOL;
