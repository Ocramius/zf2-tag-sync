#!/usr/bin/env php
<?php

require_once __DIR__ . '/FrameworkComponent.php';
require_once __DIR__ . '/Commit.php';

$settings = require __DIR__ . '/settings.php';

$componentsPath = $settings['componentsPath'];
$zfPath         = $settings['zfPath'];
$oldTag         = $settings['fromTag'];
$newTag         = $settings['toTag'];
$remote         = $settings['componentsRemote'];

/**
 * @param string $path
 * @param string $basePath
 *
 * @return string
 */
$extractComponentNamespace = function ($path, $basePath) {
    $relativePath = str_replace($basePath, '', $path);
    $segments     = explode('/', $relativePath);

    $name = [];

    while ($nameSegment = array_pop($segments)) {
        if ($nameSegment === 'Zend') {
            break;
        }

        array_unshift($name, $nameSegment);
    }

    return implode('\\', $name);
};

/**
 * @param string $componentNamespace
 * @param string $zfPath
 *
 * @return string
 */
$getComponentFrameworkPath = function ($componentNamespace, $zfPath) {
    return $zfPath . '/library/Zend/' . str_replace('\\', '/', $componentNamespace);
};

/**
 * Retrieve the paths of all zendframework components within the vendor dir
 *
 * @param string $path
 *
 * @return string[]
 */
$findVendorComponents = function ($path) {
    return array_map(
        function (\SplFileInfo $dir) {
            return $dir->getRealPath();
        },
        iterator_to_array(new \CallbackFilterIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            ),
            function (\SplFileInfo $dir) {
                return $dir->isDir() && is_dir($dir->getRealPath() . '/.git');
            }
        ))
    );
};

$buildComponents = function ($vendorComponentsPath, $frameworkPath) use ($findVendorComponents, $extractComponentNamespace, $getComponentFrameworkPath) {
    return array_map(
        function ($vendorComponentPath) use ($vendorComponentsPath, $frameworkPath, $extractComponentNamespace, $getComponentFrameworkPath) {
            return new FrameworkComponent(
                $extractComponentNamespace($vendorComponentPath, $vendorComponentsPath),
                json_decode(file_get_contents($vendorComponentPath . '/composer.json'), true)['name'],
                $getComponentFrameworkPath($extractComponentNamespace($vendorComponentPath, $vendorComponentsPath), $frameworkPath),
                $vendorComponentPath
            );
        },
        $findVendorComponents($vendorComponentsPath)
    );
};

$runInDir = function (callable $callback, $dir) {
    $currentCwd = getcwd();
    chdir($dir);

    try {
        $return = $callback();
    } catch (\Exception $e) {
        chdir($currentCwd);
        throw $e;
    }

    chdir($currentCwd);

    return $return;
};

$doGitCheckout = function ($directory, $commit) use ($runInDir) {
    $runInDir(
        function () use ($commit) {
            exec(sprintf(
                'git checkout %s',
                escapeshellarg($commit)
            ));
        },
        $directory
    );
};

$doRsync = function ($origin, $target) {
    exec(sprintf(
        'rsync --quiet --archive --filter="P .git*" --exclude=".*.sw*" --exclude=".*.un~" --delete %s %s',
        escapeshellarg($origin . '/'),
        escapeshellarg($target . '/')
    ));
};

$doGitReset = function ($directory) use ($runInDir) {
    $runInDir(
        function () {
            exec('git add -A :/');
            exec('git reset --hard HEAD');
        },
        $directory
    );
};

$doGitCommit = function ($directory, $message, $force = false) use ($runInDir) {
    $runInDir(
        function () use ($message, $force) {
            exec('git add -A :/');
            exec(sprintf(
                'git commit -S -a %s-m %s',
                $force ? '--allow-empty ' : '',
                escapeshellarg($message)
            ));
        },
        $directory
    );
};

$doGitTag = function ($directory, $message, $tag) use ($runInDir) {
    $runInDir(
        function () use ($message, $tag) {
            exec(sprintf(
                'git tag -f -s %s -m %s',
                escapeshellarg($tag),
                escapeshellarg($message)
            ));
        },
        $directory
    );
};

$doGitPush = function ($directory, $remote, $ref) use ($runInDir) {
    $runInDir(
        function () use ($remote, $ref) {
            exec(sprintf(
                'git push -f %s %s',
                escapeshellarg($remote),
                escapeshellarg($ref)
            ));
        },
        $directory
    );
};

$checkGitDiff = function ($directory) use ($runInDir) {
    return $runInDir(
        function () {
            return (bool) strlen(exec('git diff'));
        },
        $directory
    );
};

/**
 * @param string $directory
 * @param string $path
 *
 * @return Commit
 */
$getLastCommit = function ($directory, $path) use ($runInDir) {
    return $runInDir(
        function () use ($path) {
            $commitData = explode(
                ':',
                exec(sprintf(
                    'git log -1 --format=format:"%%ct:%%H" %s',
                    escapeshellarg($path)
                ))
            );

            return new Commit($commitData[1], (int) $commitData[0]);
        },
        $directory
    );
};

/**
 * @param string $directory
 * @param string $path
 *
 * @return int
 */
$getCommitTime = function ($directory, $path) use ($getLastCommit) {
    return $getLastCommit($directory, $path)->getTime();
};

/**
 * @param string $directory
 * @param string $path
 *
 * @return string
 */
$getCommitHash = function ($directory, $path) use ($getLastCommit) {
    return $getLastCommit($directory, $path)->getHash();
};

/**
 * @param string $directory
 * @param string $commit1
 * @param string $commit2
 *
 * @return Commit[]
 */
$getCommitsBetween = function ($directory, $commit1, $commit2) use ($runInDir) {
    return $runInDir(
        function () use ($commit1, $commit2) {
            exec(
                sprintf(
                    'git log --format=format:"%%ct:%%H" %s',
                    escapeshellarg($commit1 . '..' . $commit2)
                ),
                $output
            );

            return array_map(
                function ($commitString) {
                    $commitData = explode(':', $commitString);

                    return new Commit($commitData[1], (int) $commitData[0]);
                },
                $output
            );
        },
        $directory
    );
};

/**
 * Import state of a particular commit from a path into a new path, committing it with a message
 *
 * @param string $pathFrom
 * @param string $commitFrom
 * @param string $pathTo
 * @param string $message
 */
$importCommit = function ($pathFrom, $commitFrom, $pathTo, $message) use ($doGitReset, $doGitCheckout, $doRsync, $doGitCommit) {
    $doGitReset($pathFrom);
    $doGitReset($pathTo);
    $doGitCheckout($pathFrom, $commitFrom);
    $doRsync($pathFrom, $pathTo);

    $doGitCommit($pathTo, $message, true);
};

$doGitCheckout($zfPath, $newTag);
$doGitReset($zfPath);

array_map(
    function (FrameworkComponent $component) use ($importCommit, $doGitCheckout, $oldTag, $newTag, $doRsync, $zfPath, $checkGitDiff, $doGitReset, $doGitTag, $getCommitTime, $getCommitHash, $doGitCommit, $doGitPush, $remote) {
        echo 'Checking "' . $component->getName() . ' - [' . $component->getNamespace() . ']"' . "\n";

        $doGitReset($component->getVendorPath());
        $doGitCheckout($component->getVendorPath(), $newTag);

        $doRsync($component->getFrameworkPath(), $component->getVendorPath());

        if ($checkGitDiff($component->getVendorPath())) {
            $doGitCommit(
                $component->getVendorPath(),
                sprintf(
                    "Importing state as of zendframework/zf2@%s (%s)\n\nAutomatic import via rsync\n\nPreparing release for tag '%s'",
                    $getCommitHash($zfPath, $component->getFrameworkPath()),
                    $getCommitTime($zfPath, $component->getFrameworkPath()),
                    $newTag
                )
            );

            $doGitTag(
                $component->getVendorPath(),
                sprintf(
                    'zendframework/zf2@%s (%s)',
                    $getCommitHash($zfPath, $component->getFrameworkPath()),
                    $getCommitTime($zfPath, $component->getFrameworkPath())
                ),
                $newTag
            );

            //$doGitPush($componentPath, $remote, $tag);
        }
    },
    $buildComponents($componentsPath, $zfPath)
);
