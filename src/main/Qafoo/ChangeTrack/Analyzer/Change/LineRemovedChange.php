<?php

namespace Qafoo\ChangeTrack\Analyzer\Change;

class LineRemovedChange extends LineChange
{
    /**
     * Returns the absolute path of the affected file.
     *
     * @param string $beforePath
     * @param string $afterPath
     * @param \Qafoo\ChangeTrack\Analyzer\Change\FileChange $fileChange
     */
    public function determineAffectedFile($beforePath, $afterPath, FileChange $fileChange)
    {
        return $beforePath . '/' . $fileChange->getFromFile();
    }
}
