#!/usr/bin/env php
<?php

chdir(__DIR__);
system('rm -rf ' . escapeshellarg(__DIR__ . '/composer.json'));
system('rm -rf ' . escapeshellarg(__DIR__ . '/composer.lock'));
system('rm -rf ' . escapeshellarg(__DIR__ . '/composer.phar'));
system('rm -rf ' . escapeshellarg(__DIR__ . '/vendor'));
system('rm -rf ' . escapeshellarg(__DIR__ . '/zf2'));
system('git clone git@github.com:zendframework/zf2.git ./zf2');

$zfComponents = array_keys(json_decode(file_get_contents(__DIR__ . '/zf2/composer.json'))['replace']);

system('curl -sS https://getcomposer.org/installer | php --');
system('php composer.phar install --prefer-dist');
