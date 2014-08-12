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

$doGitFetch = function ($directory) use ($runInDir) {
    $runInDir(
        function () {
            exec('git fetch --all');
        },
        $directory
    );
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

$doGitCommit = function ($directory, $message, $force = false, $timestamp = null) use ($runInDir) {
    $runInDir(
        function () use ($message, $force, $timestamp) {
            exec('git add -A :/');
            exec(sprintf(
                '%s git commit -a %s-m %s',
                $timestamp
                    ? sprintf(
                        'GIT_AUTHOR_DATE=%s GIT_COMMITTER_DATE=%s ',
                        escapeshellarg(
                            (new \DateTime('@' . $timestamp, new \DateTimeZone('UTC')))->format(\DateTime::ISO8601)
                        ),
                        escapeshellarg(
                            (new \DateTime('@' . $timestamp, new \DateTimeZone('UTC')))->format(\DateTime::ISO8601)
                        )
                    )
                    : '',
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
                'git tag -s %s -m %s',
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
                'git push %s %s',
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

$checkDiff = function ($directory1, $directory2) {
    exec(sprintf('diff -r --exclude=".git" %s %s', escapeshellarg($directory1), escapeshellarg($directory2)), $diff);

    return $diff;
};

$doGitCheckoutNewBranch = function ($directory, $branchName) use ($runInDir) {
    $runInDir(
        function () use ($branchName) {
            exec('git checkout -b %s');
            exec(sprintf(
                'git checkout -b %s',
                escapeshellarg($branchName)
            ));
        },
        $directory
    );
};


$hasTag = function ($directory, $tag) use ($runInDir) {
    return $runInDir(
        function () use ($tag) {
            exec('git tag', $foundTags);

            return in_array($tag, $foundTags, true);
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
 * @param string $oldCommit
 * @param string $newCommit
 *
 * @return Commit[]
 */
$getCommitsBetween = function ($directory, $oldCommit, $newCommit) use ($runInDir) {
    return $runInDir(
        function () use ($oldCommit, $newCommit) {
            exec(
                sprintf(
                    'git log --format=format:"%%ct:%%H" %s',
                    escapeshellarg($oldCommit . '..' . $newCommit)
                ),
                $output
            );

            return array_reverse(array_map(
                function ($commitString) {
                    $commitData = explode(':', $commitString);

                    return new Commit($commitData[1], (int) $commitData[0]);
                },
                $output
            ));
        },
        $directory
    );
};

/**
 * Import state of a particular commit from a path into a new path, committing it with a message
 *
 * @param string $pathFrom
 * @param Commit $commitFrom
 * @param string $pathTo
 * @param string $message
 */
$importCommit = function ($pathFrom, Commit $commitFrom, $pathTo, $message) use ($doGitReset, $doGitCheckout, $doRsync, $doGitCommit) {
    $doGitReset($pathFrom);
    $doGitReset($pathTo);
    $doGitCheckout($pathFrom, $commitFrom->getHash());
    $doRsync($pathFrom, $pathTo);

    $doGitCommit($pathTo, $message, true, $commitFrom->getTime());
};

$runInSequence = function ($functions, $data) {
    array_map(
        function (callable $function) use ($data) {
            return array_map($function, $data);
        },
        $functions
    );
};

$runInSequence(
    [
        function (FrameworkComponent $component) use ($doGitReset) {
            echo sprintf('Git reset of framework path "%s"' . PHP_EOL, $component->getFrameworkPath());
            $doGitReset($component->getFrameworkPath());
            echo sprintf('Git reset of vendor path "%s"' . PHP_EOL, $component->getVendorPath());
            $doGitReset($component->getVendorPath());
        },
        function (FrameworkComponent $component) use ($getCommitsBetween, $doGitCheckoutNewBranch, $importCommit, $doGitCheckout, $oldTag, $newTag, $zfPath) {
            echo 'Checking "' . $component->getName() . ' - [' . $component->getNamespace() . ']"' . "\n";

            echo sprintf('Checkout "%s" in framework path "%s"' . PHP_EOL, $oldTag, $component->getFrameworkPath());
            $doGitCheckout($component->getFrameworkPath(), $oldTag); // start importing from the old tag first
            //$doGitCheckoutNewBranch($component->getVendorPath(), 'import-commits-from-' . $oldTag . '-to-' . $newTag);
            echo sprintf('Checkout "%s" in vendor path "%s"' . PHP_EOL, 'master', $component->getVendorPath());
            $doGitCheckout($component->getVendorPath(), 'master');

            array_map(
                function (Commit $commit) use ($importCommit, $component) {
                    echo sprintf(
                        'Importing commit "%s" for component "%s"' . \PHP_EOL,
                        $commit->getHash(),
                        $component->getName()
                    );

                    $importCommit(
                        $component->getFrameworkPath(),
                        $commit,
                        $component->getVendorPath(),
                        sprintf(
                            "Importing state as of zendframework/zf2@%s (%s)\n\nAutomatic import via rsync",
                            $commit->getHash(),
                            $commit->getTime()
                        )
                    );
                },
                $getCommitsBetween($zfPath, $oldTag, $newTag)
            );
        },
        function (FrameworkComponent $component) use ($doGitTag, $zfPath, $getCommitHash, $getCommitTime, $newTag) {
            echo sprintf('Tagging "%s" in vendor path "%s"' . PHP_EOL, $newTag, $component->getVendorPath());

            $doGitTag(
                $component->getVendorPath(),
                sprintf(
                    'zendframework/zf2@%s (%s)',
                    $getCommitHash($zfPath, $component->getFrameworkPath()),
                    $getCommitTime($zfPath, $component->getFrameworkPath())
                ),
                $newTag
            );
        },
        function (FrameworkComponent $component) use ($checkDiff, $doGitCheckout, $newTag) {
            echo sprintf(
                'Verifying synchronization of vendor path "%s" with framework path "%s" at tag "%s"' . PHP_EOL,
                $component->getVendorPath(),
                $component->getFrameworkPath(),
                $newTag
            );

            echo sprintf(
                'Checkout "%s" in framework path "%s"' . PHP_EOL,
                $newTag,
                $component->getFrameworkPath()
            );

            $doGitCheckout($component->getFrameworkPath(), $newTag);

            echo sprintf(
                'Checkout "%s" in vendor path "%s"' . PHP_EOL,
                $newTag,
                $component->getVendorPath()
            );

            $doGitCheckout($component->getVendorPath(), $newTag);

            echo sprintf(
                'Checking that diff between framework path "%s" and vendor path "%s" is empty' . PHP_EOL,
                $component->getFrameworkPath(),
                $component->getVendorPath()
            );

            if ($diff = $checkDiff($component->getFrameworkPath(), $component->getVendorPath())) {
                throw new \Exception(sprintf(
                    'Component "%s" differs from framework component path for tag "%s": ' . PHP_EOL . '%s',
                    $component->getName(),
                    $newTag,
                    implode(PHP_EOL, $diff)
                ));
            }
        },
        function (FrameworkComponent $component) use ($remote, $doGitPush, $newTag) {
            echo sprintf(
                'Pushing branch "%s" from vendor path "%s"' . PHP_EOL,
                'master',
                $component->getVendorPath()
            );

            $doGitPush($component->getVendorPath(), $remote, 'master');
        },
        function (FrameworkComponent $component) use ($remote, $doGitPush, $newTag) {
            echo sprintf(
                'Pushing tag "%s" from vendor path "%s"' . PHP_EOL,
                $newTag,
                $component->getVendorPath()
            );

            $doGitPush($component->getVendorPath(), $remote, $newTag);
        },
    ],
    array_filter(
        $buildComponents($componentsPath, $zfPath),
        function (FrameworkComponent $component) use ($doGitFetch, $hasTag, $newTag) {
            $doGitFetch($component->getVendorPath());

            return ! $hasTag($component->getVendorPath(), $newTag);
        }
    )
);
