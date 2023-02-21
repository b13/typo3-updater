<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

use Composer\Command\BaseCommand;
use Composer\Console\Input\InputArgument;
use Composer\DependencyResolver\Decisions;
use Composer\InstalledVersions;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Comparer\Comparer;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\MatchAllWithOffsetsResult;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\CompilingMatcher;
use Composer\Semver\Constraint\Bound;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

final class UpdateExtensionsCommand extends BaseCommand
{
    protected OutputInterface $output;

    protected function configure()
    {
        $this
            ->setName('typo3:update-extensions')
            ->setDescription('Update all TYPO3 extensions')
            ->addOption('--dry-run', null, InputOption::VALUE_NONE, 'Show available updates for packages')
            ->setHelp(
                <<<EOT
Load installed TYPO3 extensions (type: typo3-cms-extension) and check their compatability
with the currently installed TYPO3 version.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $localRepo = $this->requireComposer(false, true)->getRepositoryManager()->getLocalRepository();
        $core = $localRepo->findPackage('typo3/cms-core', '*');

        if (!$core) {
            throw new \RuntimeException('Package typo3/cms-core not installed. Please run "composer install"');
        }

        $coreVersion = $localRepo->findPackage('typo3/cms-core', '*')->getVersion() ?? '';
        $io->writeln('Core Version ' . $coreVersion);
        $packages = $localRepo->getPackages();
        $progressBar = $this->getProgressBar($packages, $io);
        foreach ($packages as $package) {
            if($package->getType() === 'typo3-cms-extension') {
                $progressBar->setMessage($package->getName(), 'name');
                $progressBar->advance();

                $version = $this->getLatestCompatibleVersion($package->getName());

                if($version) {
                    $flag = $package->getVersion() !== $version->getVersion() ? '<fg=green>' . $version->getPrettyVersion() . '</>' : '-';
                    $rows[] = [$version->getName(), $package->getPrettyVersion(), $flag, '✅'];
                } else {
                    $rows[] = [$package->getName(), '❌', $package->getName(), '❌'];
                }
            }
        }

        $progressBar->setMessage('Done!');
        $progressBar->setMessage('', 'name');
        $progressBar->finish();
        $io->writeln('');
        $io->table(['Package', 'version', 'new version', $coreVersion], $rows);

        return Command::SUCCESS;
    }

    public function loadPackageVersions(string $packageName): array
    {
        $composer = $this->requireComposer();
        $remoteRepos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());

        $packagesToLoad = [];
        $packagesToLoad[$packageName] = new MatchAllConstraint();

        return $remoteRepos->loadPackages($packagesToLoad, ['stable' => BasePackage::STABILITY_STABLE], []);
    }

    private function getLatestCompatibleVersion(string $packageName): ?Package
    {
        $localRepo= $this->requireComposer(false, true)->getRepositoryManager()->getLocalRepository();
        $versions = $this->loadPackageVersions($packageName);
        /** @var Package $version */
        foreach ($versions['packages'] as $version) {
            $requiredPackages = $version->getRequires();
            $compatibleVersion = true;

            foreach ($requiredPackages as $package) {
                // Load package from local/installed repo
                $requiredPackage = $localRepo->findPackage($package->getTarget(), '*');

                if($requiredPackage && ($requiredPackage->getType() === 'typo3-cms-framework' || $requiredPackage->getType() === 'typo3-cms-extension')) {
                    $versionParser = new VersionParser();
                    $requiredConstraint = $versionParser->parseConstraints($requiredPackage->getVersion());
                    if (!$requiredConstraint->matches($package->getConstraint())) {
                        $compatibleVersion = false;
                    }
                }
            }

            // Return the first (latest) compatible version
            if($compatibleVersion) {
                return $version;
            }
        }

        return null;
    }

    private function getProgressBar(array $units, SymfonyStyle $io): ProgressBar
    {
        ProgressBar::setFormatDefinition('packages', ' %current%/%max% -- %message% %name%');
        $progressBar = $io->createProgressBar(count($units));
        $progressBar->setFormat('packages');
        $progressBar->setMessage('Loading packages ...');
        $progressBar->setMessage('', 'name');
        $progressBar->start();

        return $progressBar;
    }
}
