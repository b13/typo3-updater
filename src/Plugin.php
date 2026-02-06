<?php

declare(strict_types=1);

namespace B13\Typo3Updater;

/*
 * This file is part of the b13/typo3-updater Composer plugin by b13.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use B13\Typo3Updater\Command\Typo3CommandProvider;
use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable as CapableInterface;
use Composer\Plugin\PluginInterface;

final class Plugin implements PluginInterface, CapableInterface
{
    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => Typo3CommandProvider::class,
        ];
    }
}
