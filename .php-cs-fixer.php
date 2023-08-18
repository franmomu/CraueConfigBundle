<?php

$header = <<<'HEADER'
(c) Christian Raue <christian.raue@gmail.com>

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
HEADER;

$finder = PhpCsFixer\Finder::create()
    ->exclude('vendor')
    ->in(__DIR__)
;

$config = new PhpCsFixer\Config();
return $config->setRules([
        '@Symfony' => true,
        '@PSR12' => true,
        'header_comment' => ['header' => $header],
    ])
    ->setFinder($finder)
;
