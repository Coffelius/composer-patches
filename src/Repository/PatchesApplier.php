<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Repository;

use Symfony\Component\Console\Output\OutputInterface;
use Vaimo\ComposerPatches\Composer\ResetOperation;
use Vaimo\ComposerPatches\Repositories\PatchesRepository;
use Vaimo\ComposerPatches\Composer\OutputUtils;
use Vaimo\ComposerPatches\Patch\Definition as PatchDefinition;

class PatchesApplier
{
    /**
     * @var \Composer\Installer\InstallationManager
     */
    private $installationManager;

    /**
     * @var \Vaimo\ComposerPatches\Logger
     */
    private $logger;

    /**
     * @var \Vaimo\ComposerPatches\Package\PatchApplier
     */
    private $patchApplier;

    /**
     * @var \Vaimo\ComposerPatches\Repository\Analyser
     */
    private $repositoryAnalyser;

    /**
     * @var \Vaimo\ComposerPatches\Managers\PatcherStateManager
     */
    private $patcherStateManager;

    /**
     * @var \Vaimo\ComposerPatches\Utils\PackageUtils
     */
    private $packageUtils;

    /**
     * @var \Vaimo\ComposerPatches\Utils\FilterUtils
     */
    private $filterUtils;

    /**
     * @param \Composer\Installer\InstallationManager $installationManager
     * @param \Vaimo\ComposerPatches\Package\PatchApplier $patchApplier
     * @param \Vaimo\ComposerPatches\Repository\Analyser $repositoryAnalyser
     * @param \Vaimo\ComposerPatches\Managers\PatcherStateManager $patcherStateManager
     * @param \Vaimo\ComposerPatches\Logger $logger
     */
    public function __construct(
        \Composer\Installer\InstallationManager $installationManager,
        \Vaimo\ComposerPatches\Package\PatchApplier $patchApplier,
        \Vaimo\ComposerPatches\Repository\Analyser $repositoryAnalyser,
        \Vaimo\ComposerPatches\Managers\PatcherStateManager $patcherStateManager,
        \Vaimo\ComposerPatches\Logger $logger
    ) {
        $this->installationManager = $installationManager;
        $this->patchApplier = $patchApplier;
        $this->repositoryAnalyser = $repositoryAnalyser;
        $this->patcherStateManager = $patcherStateManager;

        $this->logger = $logger;

        $this->packageUtils = new \Vaimo\ComposerPatches\Utils\PackageUtils();
        $this->filterUtils = new \Vaimo\ComposerPatches\Utils\FilterUtils();
        $this->patchListUtils = new \Vaimo\ComposerPatches\Utils\PatchListUtils();
    }

    public function apply(PatchesRepository $repository, array $targets = array(), array $filters = array())
    {
        $packagesUpdated = false;

        $this->logger->write('info', 'Processing patches configuration');

        $patches = $repository->getPatches();
        
        $resetQueue = $this->repositoryAnalyser->determinePackageResets($repository->getSource(), $patches);
        
        $patchQueue = $this->patchListUtils->createSimplifiedList($patches);

        if ($filters) {
            if ($composedFilter = $this->filterUtils->composeRegex($filters, '/')) {
                $patchQueue = array_filter(
                    $this->filterUtils->filterBySubItemKeys($patchQueue, $composedFilter)
                );

                if (!$targets) {
                    $targets = array_keys($patchQueue);
                }
            }
        }
        
        if ($targets) {
            if ($targetsFilter = $this->filterUtils->composeRegex($targets, '/')) {
                $resetQueue = preg_grep($targetsFilter, $resetQueue);

                $filterResult = $this->filterUtils->filterBySubItemKeys([$patchQueue], $targetsFilter);
                $patchQueue = $filterResult[0];
            }            
        }
        
        if ($filters || $targets) {
            foreach ($patchQueue as $packageName => $packagePatches) {
                if (!isset($patches[$packageName])) {
                    continue;
                }
                
                $patches[$packageName] = array_intersect_key($patches[$packageName], $packagePatches);
            }   
        }
        
        $packages = $repository->getTargets();

        $loggerIndentation = $this->logger->push('-');
        
        foreach ($packages as $packageName => $package) {
            $hasPatches = !empty($patches[$packageName]);
            
            if ($hasPatches) {
                $patchTargets = array();

                foreach ($patches[$packageName] as $patch) {
                    $patchTargets = array_merge($patchTargets, $patch[PatchDefinition::TARGETS]);
                }
                
                $patchTargets = array_unique($patchTargets);
            } else {
                $patchTargets = array($packageName);
            }

            $itemsToReset = array_intersect($resetQueue, $patchTargets);
            
            foreach ($itemsToReset as $targetName) {
                if (!$hasPatches && !isset($patchQueue[$targetName])) {
                    $this->logger->writeRaw('Resetting patched package <info>%s</info>', array($targetName));
                }

                /** @var \Composer\IO\ConsoleIO $output */
                $output = $this->logger->getOutputInstance();

                $verbosityLevel = OutputUtils::resetVerbosity($output, OutputInterface::VERBOSITY_QUIET);

                try {
                    $this->installationManager->install(
                        $repository->getSource(),
                        new ResetOperation($package, 'Package reset due to changes in patches configuration')
                    );
                } finally {
                    OutputUtils::resetVerbosity($output, $verbosityLevel);
                }

                $packagesUpdated = $this->packageUtils->resetAppliedPatches($package);
            }

            $resetQueue = array_diff($resetQueue, $patchTargets);

            if (!$hasPatches) {
                continue;
            }
            
            $hasPatchChanges = false;
            foreach ($patchTargets as $targetName) {
                $targetQueue = isset($patchQueue[$targetName])
                    ? $patchQueue[$targetName]
                    : array();

                if (!isset($packages[$targetName])) {
                    throw new \Vaimo\ComposerPatches\Exceptions\PackageNotFound(
                        sprintf(
                            'Unknown target "%s" encountered when checking patch changes for: %s',
                            $targetName,
                            implode(',', array_keys($targetQueue))
                        )
                    );
                }

                $target = $packages[$targetName];

                if (!$hasPatchChanges = $this->packageUtils->hasPatchChanges($target, $targetQueue)) {
                    continue;
                }
            }

            if (!$hasPatchChanges) {
                continue;
            }

            $packagesUpdated = true;
            
            $this->logger->writeRaw(
                'Applying patches for <info>%s</info> (%s)',
                array($packageName, count($patches[$packageName]))
            );

            $packagePatchesQueue = $patches[$packageName];
            $packageRepository = $repository->getSource();

            $subProcessIndentation = $this->logger->push('~');

            try {
                $appliedPatches = $this->patchApplier->applyPatches($package, $packagePatchesQueue);
                
                $this->patcherStateManager->registerAppliedPatches($packageRepository, $appliedPatches);
                
                $this->logger->reset($subProcessIndentation);
            } catch (\Vaimo\ComposerPatches\Exceptions\PatchFailureException $exception) {
                $failedPath = $exception->getFailedPatchPath();

                $paths = array_keys($packagePatchesQueue);
                $appliedPaths = array_slice($paths, 0, array_search($failedPath, $paths));
                $appliedPatches = array_intersect_key($packagePatchesQueue, array_flip($appliedPaths));
                
                $this->patcherStateManager->registerAppliedPatches($packageRepository, $appliedPatches);

                $this->patchListUtils->sanitizeFileSystem($patches);

                $this->logger->reset($loggerIndentation);

                $repository->write();

                throw $exception;
            } 

            $this->logger->writeNewLine();
        }

        $this->logger->reset($loggerIndentation);

        $this->patchListUtils->sanitizeFileSystem($patches);
        
        if (!$packagesUpdated) {
            $this->logger->writeRaw('Nothing to patch');
        } else {
            $this->logger->write('info', 'Writing patch info to install file');
        }

        $repository->write();
    }
}
