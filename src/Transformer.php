<?php

declare(strict_types=1);

namespace TypistTech\Imposter\Plugin;

use Composer\IO\IOInterface;
use TypistTech\Imposter\ConfigFactory;
use TypistTech\Imposter\Filesystem;
use TypistTech\Imposter\ImposterFactory;

class Transformer
{
    public static function run(IOInterface $io): void
    {
        // Print an empty line to separate imposter outputs.
        $io->write('', true);
        $io->write('', true);
        $io->write('<info>Running Imposter...</info>', true);
        $io->write('<info>======================</info>', true);
        $io->write('Loading package information from <comment>' . getcwd() . '/composer.json</comment>', true);

        $imposter = ImposterFactory::forProject(getcwd(), ['typisttech/imposter-plugin']);

        $autoloads = $imposter->getAutoloads();
        $count = count($autoloads);
        $index = 1;
        foreach ($autoloads as $autoload) {
            $io->write(" - <comment>$index/$count</comment>: Transforming $autoload", true);
            $imposter->transform($autoload);
            $index++;
        }

        $io->write('<info>Success: Imposter transformed vendor files.</info>', true);

        self::updateInstalledJson($io);

        $invalidAutoloads = $imposter->getInvalidAutoloads();
        if (! empty($invalidAutoloads)) {
            $invalidAutoloadsCount = count($invalidAutoloads);
            $io->writeError('', true);
            $io->writeError(
                // phpcs:ignore Generic.Files.LineLength.TooLong
                "<warning>Warning: Imposter failed to transformed $invalidAutoloadsCount of the autoload path(s).</warning>",
                true
            );

            foreach ($invalidAutoloads as $invalidAutoload) {
                $io->writeError(" - $invalidAutoload", true);
            }
        }

        // Print empty lines to separate imposter outputs.
        $io->write('', true);
        $io->write('', true);
    }

    private static function updateInstalledJson($io)
    {
        $path = getcwd() . '/vendor/composer/installed.json';

        if (!file_exists($path)) {
            $io->writeError(
                "<warning>$path does not exist yet. Please run `composer update` after. Skipping ...</warning>",
                true
            );
            return;
        }

        $content = file_get_contents($path);

        $arrayContent = json_decode($content, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $io->writeError(
                "<warning>$path content is not json. Skipping ...</warning>",
                true
            );
            return;
        }

        if (empty($arrayContent['packages'])) {
            $io->writeError(
                "<warning>$path has no `packages` entry, or is empty. Skipping ...</warning>",
                true
            );
            return;
        }

        $filesystem = new Filesystem();
        $projectConfig = ConfigFactory::buildProjectConfig(getcwd() . '/composer.json', $filesystem);
        $projectNameSpace = $projectConfig->getImposterNamespace();

        foreach ($arrayContent['packages'] as &$package) {
            if (!isset($package['autoload']) || !isset($package['autoload']['psr-4'])) {
                continue;
            }

            $newPackageAutoload = [];

            foreach ($package['autoload']['psr-4'] as $namespace => $dir) {
                if (strpos($namespace, $projectNameSpace) !== false || strpos($namespace, 'Imposter') !== false) {
                    $newPackageAutoload[$namespace] = $dir;
                } else {
                    $newPackageAutoload[$projectNameSpace . '\\' . $namespace] = $dir;
                }
            }

            $package['autoload']['psr-4'] = $newPackageAutoload;
        }
        unset($package);

        $newContent = json_encode(
            $arrayContent,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT
        );

        file_put_contents($path, $newContent);

        $io->write('<info>' . $path . ' namespaces has been updated</info>', true);
    }
}
