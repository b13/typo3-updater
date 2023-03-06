<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

use Composer\Command\BaseCommand;
use Composer\Console\Application;
use Composer\Console\Input\InputArgument;
use Composer\InstalledVersions;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\Constraint;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use function Deployer\writeln;

final class UpdateCommand extends BaseCommand
{
    protected OutputInterface $output;

    protected function configure()
    {
        $this
            ->setName('typo3:update')
            ->setDescription('Update the TYPO3 installation')
            ->addArgument('version', InputArgument::OPTIONAL, 'TYPO3 target version')
            ->addOption('--dry-run', null, InputOption::VALUE_NONE, 'Show available updates for packages')
            ->setHelp(
                <<<EOT
Deletes all files and folders created/downloaded by "composer tdk:*" commands.
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            $this->getIO()->warning('Starting dry-run of TYPO3 update! This does not modify the composer.json ("composer update typo3/cms-* -W")');
        }

        $application = new Application();
        $application->setAutoExit(false);

        // TYPO3 core packages update. Command: "composer update typo3/cms-* -W"
        // See docs: https://getcomposer.org/doc/03-cli.md#update-u-upgrade
        if ($input->getArgument('version')) {
            $composer = $this->requireComposer(true, true);
            $rootPackage = $composer->getPackage();
            $io = new SymfonyStyle($input, $output);

            //$rootPackage->

            //            // @todo: Disable plugins and scripts any good?!
//            $installedRepository = $this->requireComposer(true, true)->getRepositoryManager()->getLocalRepository();
        } else {
            $arrayInput = new ArrayInput(array('command' => 'update', 'packages' => ['typo3/cms-*'], '-W' => true, '--dry-run' => $input->getOption('dry-run')));
            $exitCode = $application->run($arrayInput, $output);
            $dryRunText = $input->getOption('dry-run') ? ' <options=bold,underscore>"dry-run"</> ' : ' ';
            $dryRunHint = $input->getOption('dry-run') ? ' To actually update run the command without --dry-run' : '';

            if ($exitCode) {
                $this->getIO()->error('Failed to' . $dryRunText . 'update TYPO3 with all dependencies. See errors above');
                return Command::FAILURE;
            }

            $this->getIO()->write('<fg=green>✓</> TYPO3 update' . $dryRunText . 'successful.' . $dryRunHint);
            return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }
}
