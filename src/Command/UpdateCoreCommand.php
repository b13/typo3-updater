<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

/*
 * This file is part of the b13/typo3-updater Composer plugin by b13.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Composer\Command\BaseCommand;
use Composer\Console\Application;
use Composer\Console\Input\InputArgument;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\CompositeRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

final class UpdateCoreCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('typo3:core:update')
            ->setDescription('Update TYPO3 core and incompatible extensions in composer.json')
            ->addArgument('version', InputArgument::REQUIRED, 'TYPO3 target version constraint, e.g. ^13.4')
            ->addOption('--dry-run', null, InputOption::VALUE_NONE, 'Only show what would be changed, do not modify anything')
            ->setHelp(
                <<<EOT
Updates all typo3/cms-* package constraints in the root composer.json to the given
target version. Also detects installed extensions that are incompatible with the
target version and includes their updates.

Example:
  composer typo3:core:update ^13.4
  composer typo3:core:update ^13.4 --dry-run

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = (bool)$input->getOption('dry-run');
        $targetVersion = $input->getArgument('version');

        $composerFile = Factory::getComposerFile();
        $jsonFile = new JsonFile($composerFile);

        if (!$jsonFile->exists()) {
            $io->error('Could not find composer.json at ' . $composerFile);
            return Command::FAILURE;
        }

        $originalData = $jsonFile->read();
        $require = $originalData['require'] ?? [];
        $requireDev = $originalData['require-dev'] ?? [];

        // 1. Collect typo3/cms-* constraint changes
        $coreChanges = [];
        $packagesToUpdate = [];

        foreach ($require as $packageName => $constraint) {
            if ($this->isTypo3Package($packageName)) {
                if ($constraint !== $targetVersion) {
                    $coreChanges[] = [$packageName, $constraint, $targetVersion];
                    $packagesToUpdate[] = $packageName;
                }
            }
        }

        if (empty($coreChanges)) {
            $io->success('All typo3/cms-* packages already use constraint ' . $targetVersion);
            return Command::SUCCESS;
        }

        $io->section('TYPO3 core packages');
        $io->table(['Package', 'Current constraint', 'New constraint'], $coreChanges);

        // 2. Find extensions that need updating for the target version
        $extensionUpgrades = $this->findExtensionUpgrades($targetVersion);

        $updatedData = $originalData;
        foreach ($packagesToUpdate as $packageName) {
            $updatedData['require'][$packageName] = $targetVersion;
        }

        if (!empty($extensionUpgrades)) {
            $extensionRows = [];
            $unresolvedRows = [];

            foreach ($extensionUpgrades as $upgrade) {
                if ($upgrade['newConstraint']) {
                    $stabilityNote = $upgrade['stable'] ? '' : ' <fg=yellow>(pre-release)</>';
                    $extensionRows[] = [
                        $upgrade['name'],
                        $upgrade['currentVersion'],
                        $upgrade['coreConstraint'],
                        $upgrade['newDisplayVersion'] . $stabilityNote,
                        $upgrade['newConstraint'],
                    ];
                    $packagesToUpdate[] = $upgrade['name'];

                    // Update constraint in the correct section
                    if (isset($requireDev[$upgrade['name']])) {
                        $updatedData['require-dev'][$upgrade['name']] = $upgrade['newConstraint'];
                    } elseif (isset($require[$upgrade['name']])) {
                        $updatedData['require'][$upgrade['name']] = $upgrade['newConstraint'];
                    }
                } else {
                    $unresolvedRows[] = [
                        $upgrade['name'],
                        $upgrade['currentVersion'],
                        $upgrade['coreConstraint'],
                    ];
                }
            }

            if (!empty($extensionRows)) {
                $io->section('Extensions to upgrade');
                $io->table(['Extension', 'Installed version', 'Requires typo3/cms-core', 'New version', 'New constraint'], $extensionRows);
            }

            if (!empty($unresolvedRows)) {
                $io->section('Extensions without a compatible version');
                $io->table(['Extension', 'Installed version', 'Requires typo3/cms-core'], $unresolvedRows);
                $io->warning('The extensions above have no published version compatible with ' . $targetVersion . '. The update may fail.');
            }
        }

        if ($isDryRun) {
            $io->note('Dry-run mode: no changes were made.');
            return Command::SUCCESS;
        }

        $io->writeln('');
        $io->writeln('This will run: <info>composer update ' . implode(' ', $packagesToUpdate) . ' -W</info>');
        $io->writeln('');

        $question = new ConfirmationQuestion('Apply these changes and run composer update? [Y/n] ', true);
        if (!$io->askQuestion($question)) {
            return Command::SUCCESS;
        }

        $jsonFile->write($updatedData);

        $io->section('Running composer update...');

        $application = new Application();
        $application->setAutoExit(false);

        $bufferedOutput = new BufferedOutput($output->getVerbosity(), $output->isDecorated());
        $arrayInput = new ArrayInput([
            'command' => 'update',
            'packages' => $packagesToUpdate,
            '-W' => true,
        ]);
        $exitCode = $application->run($arrayInput, $bufferedOutput);

        if ($exitCode) {
            $jsonFile->write($originalData);
            $io->error('Failed to update TYPO3 packages. composer.json has been reverted.');

            if ($output->isVerbose()) {
                $io->section('Composer output');
                $output->write($bufferedOutput->fetch());
            } else {
                $io->note('Run with -v to see the full composer output.');
            }
            return Command::FAILURE;
        }

        $output->write($bufferedOutput->fetch());
        $io->success('TYPO3 core and extensions updated successfully.');
        return Command::SUCCESS;
    }

    private function isTypo3Package(string $name): bool
    {
        return str_starts_with($name, 'typo3/cms-');
    }

    private const STABLE_ONLY = [
        'stable' => BasePackage::STABILITY_STABLE,
    ];

    private const ALL_STABILITIES = [
        'stable' => BasePackage::STABILITY_STABLE,
        'RC' => BasePackage::STABILITY_RC,
        'beta' => BasePackage::STABILITY_BETA,
        'alpha' => BasePackage::STABILITY_ALPHA,
        'dev' => BasePackage::STABILITY_DEV,
    ];

    /**
     * Find installed extensions incompatible with the target TYPO3 version
     * and look up their latest compatible version from remote repositories.
     *
     * @return array<int, array{name: string, currentVersion: string, coreConstraint: string, newConstraint: ?string, newDisplayVersion: ?string, stable: bool}>
     */
    private function findExtensionUpgrades(string $targetVersion): array
    {
        $composer = $this->requireComposer(true, true);
        $installedRepository = $composer->getRepositoryManager()->getLocalRepository();
        $remoteRepositories = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
        $versionParser = new VersionParser();
        $targetConstraint = $versionParser->parseConstraints($targetVersion);
        $upgrades = [];

        foreach ($installedRepository->getPackages() as $package) {
            if ($package->getType() !== 'typo3-cms-extension') {
                continue;
            }

            /** @var Link $link */
            foreach ($package->getRequires() as $link) {
                if ($link->getTarget() === 'typo3/cms-core' && !$targetConstraint->matches($link->getConstraint())) {
                    // Try stable first, fall back to pre-release versions
                    $compatibleVersion = $this->findLatestCompatibleVersion($package, $remoteRepositories, $targetVersion, self::STABLE_ONLY);
                    $stable = true;

                    if (!$compatibleVersion) {
                        $compatibleVersion = $this->findLatestCompatibleVersion($package, $remoteRepositories, $targetVersion, self::ALL_STABILITIES);
                        $stable = false;
                    }

                    [$newConstraint, $newDisplayVersion] = $this->buildVersionStrings($compatibleVersion, $stable);

                    $upgrades[] = [
                        'name' => $package->getName(),
                        'currentVersion' => $package->getPrettyVersion(),
                        'coreConstraint' => $link->getPrettyConstraint(),
                        'newConstraint' => $newConstraint,
                        'newDisplayVersion' => $newDisplayVersion,
                        'stable' => $stable,
                    ];
                }
            }
        }

        return $upgrades;
    }

    /**
     * Build a composer constraint and a human-readable display string for a package version.
     *
     * @return array{?string, ?string} [constraint, displayVersion]
     */
    private function buildVersionStrings(?PackageInterface $package, bool $stable): array
    {
        if (!$package) {
            return [null, null];
        }

        $prettyVersion = $package->getPrettyVersion();

        if ($stable) {
            return ['^' . $prettyVersion, $prettyVersion];
        }

        // Dev packages: use the branch alias (dev-main, dev-master, etc.)
        if ($package->isDev()) {
            $sourceRef = $package->getSourceReference();
            $displayVersion = $prettyVersion;
            if ($sourceRef) {
                $displayVersion .= ' (' . substr($sourceRef, 0, 7) . ')';
            }
            return [$prettyVersion, $displayVersion];
        }

        // Pre-release (RC, beta, alpha): use exact version
        return [$prettyVersion, $prettyVersion];
    }

    /**
     * Find the latest version of a package that is compatible with the given TYPO3 core version.
     *
     * @param array<string, int> $acceptableStabilities
     */
    private function findLatestCompatibleVersion(PackageInterface $package, CompositeRepository $remoteRepositories, string $targetVersion, array $acceptableStabilities): ?PackageInterface
    {
        $versionParser = new VersionParser();
        $targetConstraint = $versionParser->parseConstraints($targetVersion);
        $searchConstraint = $versionParser->parseConstraints('>=' . $package->getVersion());

        $results = $remoteRepositories->loadPackages(
            [$package->getName() => $searchConstraint],
            $acceptableStabilities,
            []
        );

        foreach ($results['packages'] as $candidate) {
            /** @var Link $link */
            foreach ($candidate->getRequires() as $link) {
                if ($link->getTarget() === 'typo3/cms-core' && $targetConstraint->matches($link->getConstraint())) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}
