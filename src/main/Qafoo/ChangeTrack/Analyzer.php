<?php

namespace Qafoo\ChangeTrack;

use pdepend\reflection\ReflectionSession;
use Arbit\VCSWrapper;

class Analyzer
{
    private $checkout;

    private $checkoutPath;

    public function __construct($repositoryUrl)
    {
        VCSWrapper\Cache\Manager::initialize(__DIR__ . '/../../../var/tmp/cache');

        $this->checkoutPath = __DIR__ . '/../../../var/tmp/checkout';

        $this->checkout = new VCSWrapper\GitCli\Checkout($this->checkoutPath);
        $this->checkout->initialize($repositoryUrl);
    }

    public function analyze()
    {
        $versions = $this->checkout->getVersions();

        $initialVersion = array_shift($versions);

        $recursiveIterator = new \RecursiveIteratorIterator(
            $this->checkout,
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $session = new ReflectionSession();
        $query = $session->createFileQuery();

        $changes = array();

        $this->checkout->update($initialVersion);

        $changeRecorder = new ChangeRecorder($initialVersion, $this->checkout->getLogEntry($initialVersion)->message);

        foreach ($recursiveIterator as $leaveNode) {
            if ($leaveNode instanceof VCSWrapper\File && substr($leaveNode->getLocalPath(), -3) == 'php') {
                foreach ($query->find($leaveNode->getLocalPath()) as $class) {
                    foreach ($class->getMethods() as $method) {
                        $changeRecorder->recordChange($localChanges, $class, $method);
                    }
                }
            }
        }
        $changes[] = $changeRecorder;

        $previousVersion = $initialVersion;
        foreach ($versions as $currentVersion) {
            $localChanges = array();

            $this->checkout->update($currentVersion);

            $changeRecorder = new ChangeRecorder($currentVersion, $this->checkout->getLogEntry($currentVersion)->message);

            $diff = $this->checkout->getDiff($previousVersion, $currentVersion);
            foreach ($diff as $diffCollection) {
                $affectedFilePath = $this->checkoutPath . substr($diffCollection->to, 1);

                if (substr($affectedFilePath, -3) !== 'php') {
                    continue;
                }

                $classes = $query->find($affectedFilePath);

                foreach ($diffCollection->chunks as $chunk) {
                    $hunkStart = $chunk->end;
                    $hunkLength = $chunk->endRange;

                    $lineIndex = $hunkStart;

                    for ($lineOffset = 0; $lineOffset < $hunkLength; $lineOffset++) {
                        $line = $chunk->lines[$lineOffset];

                        switch ($line->type) {
                            case VCSWrapper\Diff\Line::ADDED:
                            case VCSWrapper\Diff\Line::UNCHANGED:
                                $lineIndex++;
                                break;
                            case VCSWrapper\Diff\Line::REMOVED:
                                // No forward
                                break;
                        }

                        foreach ($classes as $class) {
                            foreach ($class->getMethods() as $method) {
                                if ($lineIndex >= $method->getStartLine() && $lineIndex <= $method->getEndLine()) {
                                    $changeRecorder->recordChange($class, $method);
                                }
                            }
                        }
                    }
                }
            }

            $changes[] = $changeRecorder;
            $previousVersion = $currentVersion;
        }
        return $this->mergeChanges($changes);
    }

    protected function mergeChanges(array $changeRecorders)
    {
        $mergedChanges = array();

        foreach ($changeRecorders as $changeRecorder) {
            foreach ($changeRecorder->getChanges() as $className => $methodChanges)
                foreach ($methodChanges as $methodName => $changeCount) {
                    if (!isset($mergedChanges[$className])) {
                        $mergedChanges[$className] = array();
                    }
                    if (!isset($mergedChanges[$className][$methodName])) {
                        $mergedChanges[$className][$methodName] = 0;
                    }
                    $mergedChanges[$className][$methodName] += $changeCount;
                }
        }
        return $mergedChanges;
    }
}