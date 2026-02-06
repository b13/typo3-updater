<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

/*
 * This file is part of the b13/typo3-updater Composer plugin by b13.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Composer\Plugin\Capability\CommandProvider;

final class Typo3CommandProvider implements CommandProvider
{
    public function getCommands(): array
    {
        return [
            new UpdateCoreCommand(),
            new UpdateExtensionsCommand(),
        ];
    }
}
