<?php

$componentsPath = __DIR__ . '/vendor/zendframework'; // assuming vendor packages were installed here via composer + --prefer-source
$zfPath         = __DIR__ . '/zf2';                  // assuming the framework was installed here
$tag            = 'release-2.3.0';                   // tag to be used
$remote         = 'origin';

$findVendorComponents = function ($path) {
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path),
        RecursiveIteratorIterator::SELF_FIRST
    );

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

$extractComponentName = function ($path, $basePath) {
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

$getComponentFrameworkPath = function ($componentName, $zfPath) {
    return $zfPath . '/library/Zend/' . str_replace('\\', '/', $componentName);
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

$doGitCommit = function ($directory, $message) use ($runInDir) {
    $runInDir(
        function () use ($message) {
            exec('git add -A :/');
            exec(sprintf(
                'git commit -S -a -m %s',
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

$getLastCommit = function ($directory, $path) use ($runInDir) {
    return $runInDir(
        function () use ($path) {
            return exec(sprintf(
                'git log -1 --format=format:"%%ct:%%H" %s',
                escapeshellarg($path)
            ));
        },
        $directory
    );
};

$getCommitTime = function ($directory, $path) use ($getLastCommit) {
    return explode(':', $getLastCommit($directory, $path))[0];
};

$getCommitHash = function ($directory, $path) use ($getLastCommit) {
    return explode(':', $getLastCommit($directory, $path))[1];
};

$doGitCheckout($zfPath, $tag);
$doGitReset($zfPath);

array_map(
    function ($componentPath) use ($extractComponentName, $componentsPath, $doGitCheckout, $tag, $doRsync, $getComponentFrameworkPath, $zfPath, $checkGitDiff, $doGitReset, $doGitTag, $getLastCommit, $getCommitTime, $getCommitHash, $doGitCommit, $doGitPush, $remote) {
        echo 'Checking "' . $extractComponentName($componentPath, $componentsPath) . '"' . "\n";
        $doGitReset($componentPath);
        $doGitCheckout($componentPath, $tag);

        $doRsync($getComponentFrameworkPath($extractComponentName($componentPath, $componentsPath), $zfPath), $componentPath);

        if ($checkGitDiff($componentPath)) {
            $componentDir = str_replace('\\', '/', 'library/Zend/' . $extractComponentName($componentPath, $componentsPath));
            $doGitCommit(
                $componentPath,
                sprintf(
                    "Importing state as of zendframework/zf2@%s (%s)\n\nAutomatic import via rsync\n\nPreparing release for tag '%s'",
                    $getCommitHash($zfPath, $componentDir),
                    $getCommitTime($zfPath, $componentDir),
                    $tag
                )
            );

            $doGitTag(
                $componentPath,
                'zendframework/zf2@' . $getCommitHash($zfPath, $componentDir) . ' (' . $getCommitTime($zfPath, $componentDir) . ')',
                $tag
            );

            //$doGitPush($componentPath, $remote, $tag);
        }
    },
    $findVendorComponents($componentsPath)
);
