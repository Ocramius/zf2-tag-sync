#!/usr/bin/env php
<?php

chdir(__DIR__);
system('rm -rf ' . escapeshellarg(__DIR__ . '/composer.json'));
system('rm -rf ' . escapeshellarg(__DIR__ . '/composer.lock'));
system('rm -rf ' . escapeshellarg(__DIR__ . '/composer.phar'));
system('rm -rf ' . escapeshellarg(__DIR__ . '/vendor'));
system('rm -rf ' . escapeshellarg(__DIR__ . '/zf2'));
system('git clone git@github.com:zendframework/zf2.git ./zf2');

$replaceComponents = json_decode(file_get_contents(__DIR__ . '/zf2/composer.json'), true)['replace'];

unset($replaceComponents['zendframework/zend-resources']); // excluded, weird meta-package.

// read "replace" components and put them into the `composer.json`
file_put_contents(
    __DIR__ . '/composer.json',
    json_encode(
        [
            'require' => array_merge(
                ['php' => '~5.5'],
                array_map(
                    function () {
                        return 'dev-master@DEV';
                    },
                    $replaceComponents
                )
            ),
        ],
        \JSON_PRETTY_PRINT
    )
);

system('curl -sS https://getcomposer.org/installer | php --');
system('php composer.phar install --prefer-dist');
