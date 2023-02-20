<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

use Composer\Plugin\Capability\CommandProvider;

final class Typo3CommandProvider implements CommandProvider
{
    public function getCommands(): array
    {
        return [
            new UpdateCommand(),
            new UpdateExtensionsCommand(),
        ];
    }
}
