<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

use Composer\Command\BaseCommand;
use Composer\Console\Input\InputArgument;
use Composer\DependencyResolver\Decisions;
use Composer\InstalledVersions;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\MatchAllWithOffsetsResult;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\RepositorySet;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\Bound;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Symfony\Component\Console\Command\Command;
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
            ->setDescription('Update the TYPO3 extensions')
            ->addOption('--dry-run', null, InputOption::VALUE_NONE, 'Show available updates for packages')
            ->setHelp(
                <<<EOT
Deletes all files and folders created/downloaded by "composer tdk:*" commands. 
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
//        //VersionParser::isUpgrade();
//        // Get all local/installed packages (at least this is the idea)
//        $localRepo = $this->requireComposer(false, true)->getRepositoryManager()->getLocalRepository();
//        $packages = $localRepo->getPackages();
//
//        // Get all local/installed packages (at least this is the idea)
//
//
//        $io = new SymfonyStyle($input, $output);
//        $rows = [];
//        foreach ($packages as $package) {
//            if ($package->getType() === 'typo3-cms-extension') {
//                $versions = $this->loadPackageVersions($package->getName());
//
//                $availableVersions = [];
//                /** @var Package $version */
//                foreach ($versions['packages'] as $version) {
//                    $availableVersions[] = $version->getVersion();
//                }
//
//                $this->findMaxCompatibleVersion($package, $availableVersions);
//
//                $rows[] = [
//                    $package->getName(),
//                    $package->getVersion(),
//                    'implode(PHP_EOL, $availableVersions)'
//                ];
//            }
//        }
//
//        $io->table(['name', 'version', 'available version'], $rows);
//
//        return Command::SUCCESS;
//
//
//
//
//
//        $packages = [];
//        $io = new SymfonyStyle($input, $output);
//
//        $rows = [];
//        foreach ($result['packages'] as $package) {
//            if($package->getName() !== 'georgringer/news') {
//                continue;
//            }
//
//            $requires = [];
//            /** @var Link $require */
//            foreach ($package->getRequires() as $require) {
//                $requires[] = $require->getTarget() . ' Pretty -- ' . $require->getConstraint()->getPrettyString() . ' Bound up -- ' . $require->getPrettyConstraint();
//            }
//
//            // var_dump($requires);
//
//            $rows[] = [$package->getName(), $package->getVersion(), implode(PHP_EOL, $requires)];
//
//
//            $packages[$package->getType()][$package->getName()][$package->getVersion()] = $package->getRequires();
//        }
//
        $io = new SymfonyStyle($input, $output);

        $localRepo = $this->requireComposer(false, true)->getRepositoryManager()->getLocalRepository();
        $core = $localRepo->findPackage('typo3/cms-core', '*');

        $coreVersion = $core->getVersion();
        $io->writeln('Core Version ' . $coreVersion);


        $rows = [];
        // $extension = $this->requireComposer(false, true)->getRepositoryManager()->findPackages('georgringer/news', '*');
        $extension = $localRepo->findPackages('georgringer/news', '*');


        $repositories = $this->requireComposer(false, true)->getRepositoryManager()->getRepositories();
        $repoSet = new RepositorySet();
        foreach ($repositories as $repository) {
            $repoSet->addRepository($repository);
        }

        $versionSelector = new VersionSelector($repoSet);

        foreach ($extension as $repo) {
            $recommended = $versionSelector->findRecommendedRequireVersion($repo) . ' vs ' . $versionSelector->findBestCandidate($repo->getName())->getVersion();
            $rows[] = [$repo->getName(), $repo->getVersion(), $recommended];
        }

        $io->table(['Package', 'version', 'Recommended'], $rows);


        //var_dump($packages);

//        $installedCoreVersion = InstalledVersions::get;
//        $this->getIO()->write('<fg=green>TYPO3 Core ' . $installedCoreVersion . ' installed.</>');
//
//        $extensions = InstalledVersions::getInstalledPackagesByType('typo3-cms-extension');
//        $composer = $this->requireComposer(false, true);
//
//        $packages = $composer->getPackage()->getRequires();
//        foreach ($packages as $package) {
//            $this->getIO()->write($package->getConstraint() . ' --- ' . $package->getTarget());
//        }



//        foreach ($extensions as $extension) {
//
//
//
//            var_dump($extension);
//            //$this->getIO()->($extension);
//        }

//        $requires = $this->requireComposer(false, true)->getPackage()->getRequires();
//
//
//        var_dump($composer);


//        $composer = $this->requireComposer(false, true);
//
//        $required = $composer->getPackage()->getRequires();
//
//        foreach ($required as $package) {
//            $this->getIO()->write($package->getPrettyConstraint() . ' --- ' . $package->getTarget());
//        }

//        foreach ($installedPackages as $packageName) {
//            // var_dump($packageName);
//            $version = InstalledVersions::getVersion($packageName);
//            $this->getIO()->write($packageName . ' --- ' . $version);
//        }

//        $composer = $this->requireComposer();
//        $package = clone $this->requireComposer()->getPackage();
//        $installedRepo = new InstalledRepository([new RootPackageRepository($package)]);
//        $installedRepo->addRepository($composer->getRepositoryManager()->getLocalRepository());
//
//        foreach ($installedRepo->search('typo3') as $package) {
//            var_dump($package);
//            // $this->getIO()->write($package->getName() . ' --- ' . $package->getVersion());
//        }


        //        $composer = $this->requireComposer(false, true);
//        $repositories = $composer->getRepositoryManager()->getRepositories();
//        $installedRepo = new InstalledRepository([$platformRepo]);
//        $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
//        $installedRepo->addRepository($composer->getRepositoryManager()->getLocalRepository());
//
//        $installed = new InstalledRepository();

        return Command::SUCCESS;
    }

    public function loadPackageVersions(string $packageName): array
    {
        $composer = $this->requireComposer();
        $remoteRepos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());

        $packagesToLoad = [];
        $packagesToLoad[$packageName] = new MatchAllWithOffsetsResult();

        return $remoteRepos->loadPackages($packagesToLoad, ['stable' => BasePackage::STABILITY_STABLE], []);
    }

    public function findMaxCompatibleVersion(BasePackage $package, array $versions): string
    {
//        $this->getIO()->write('Package ' . $package->getName());
//        $raging = InstalledVersions::getVersionRanges($package->getName());
//        $this->getIO()->write('      ' . $raging);


//        foreach ($versions as $version) {
//            $satisfied = \Composer\InstalledVersions::satisfies(new VersionParser, $package->getName(), $version);
//            $this->getIO()->write('    compatible? ' . (int)$satisfied . '  ' . $version);
//        }

        $requires = $package->getRequires();
        $type = $package->getSourceType();
        $this->getIO()->write('Package requirements ' . $type . ' -- ' . $package->getName());

        foreach ($requires as $require) {
            $this->getIO()->write('   Required ' . $require->getTarget());
            $this->getIO()->write('   constraint ' . $require->getPrettyConstraint());
            $this->getIO()->write('   upper bound ' . $require->getPrettyString($package));

//            foreach ($versions as $version) {
//                $matches = $require->getConstraint()->matches(new Constraint('=', $version));
//
//                if ($matches) {
//                    $this->getIO()->write('      found compatible version ' . $version);
//                    return $version;
//                }
//            }
        }


//        // ->getConstraint()->matches(new Constraint('=', $package->getVersion())
//        foreach ($requires as $require) {
//            $this->getIO()->write('   Required ' . $require->getTarget());
//            foreach ($versions as $version) {
//                $matches = $require->getConstraint()->matches(new Constraint('=', $version));
//
//                if ($matches) {
//                    $this->getIO()->write('      found compatible version ' . $version);
//                    return $version;
//                }
//            }
//        }

        return '';
    }
}
