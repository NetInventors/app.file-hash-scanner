<?php

/**
 * Class: FileHashScanner
 * Version: 1.0.1
 */

class FileHashScanner
{

    private $_newFiles = array();
    private $_changedFiles = array();
    private $_deletedFiles = array();
    private $_excludeFiles = array();
    private $_filetypes = array(
        'htm', 'html', 'phtml', 'xml', 'js', 'swf', 'php', 'php3', 'php4', 'php5'
    );
    private $_regex = '';
    private $_negateRegex = false;

    private $_logfile = '';
    private $_procCounter = 0;

    public function __construct() {
        $this->_logfile = strftime('%Y-%m-%d_%H%M%S') . '.log';
    }

    private function init(array $exclude = array(), array $types = array(), $negate = false) {
        $this->_newFiles = array();
        $this->_changedFiles = array();
        $this->_deletedFiles = array();
        $this->_excludeFiles = $exclude;
        if (0 < count($types)) {
            $this->_filetypes = $types;
        }
        $this->_filetypes = array_map('preg_quote', $this->_filetypes);
        $this->_regex = '/^(' . implode('|', $this->_filetypes) . ')$/';
        $this->_negateRegex = (bool) $negate;
        unset($negate);
        return $this;
    }

    public function doScan($originDirectory, array $exclude = array(), array $types = array(), $negate = false, $cacheDirectory = './cache', $logsDirectory = './logs') {
        ++ $this->_procCounter;
        $originDirectory = rtrim($originDirectory, '/') . '/';
        $cacheDirectory = rtrim($cacheDirectory, '/') . '/';
        $logsDirectory = rtrim($logsDirectory, '/') . '/';
        foreach ($exclude as $key => &$entry) {
            if ('/' !== $entry{0}) {
                if (false === $entry = realpath($originDirectory . $entry)) {
                    unset($exclude[$key]);
                }
            }
        }
        return $this->init($exclude, $types, $negate)
            ->scanNewAndChangedFiles($originDirectory, $cacheDirectory)
            ->scanDeletedFiles($originDirectory, $cacheDirectory)
            ->generateStatistics($originDirectory, $logsDirectory);
    }

    private function scanNewAndChangedFiles($originDirectory, $cacheDirectory) {
        $initCacheDirectory = $cacheDirectory . $originDirectory;
        if (! is_dir($initCacheDirectory)) {
            mkdir($initCacheDirectory, 0755, true);
        }
        unset($initCacheDirectory);

        $CurrentWorkingDirectory = dir($originDirectory);
        while ($entry = $CurrentWorkingDirectory->read()) {
            if ($entry != "." && $entry != "..") {
                $pathToEntry = rtrim($originDirectory, '/') . '/' . $entry;
                if (! $this->isInExcludes($pathToEntry)) {
                    $cacheEntry = $cacheDirectory . $pathToEntry;
                    if (is_dir($pathToEntry)) {
                        if (! is_dir($cacheEntry)) {
                            mkdir($cacheEntry, 0755, true);
                            $this->_newFiles[] = $pathToEntry;
                        }
                        $this->scanNewAndChangedFiles($pathToEntry, $cacheDirectory);
                    }
                    else {
                        if ($this->validFiletype($pathToEntry)) {
                            $cacheEntry .= '.md5';
                            $newMd5Hash = md5_file($pathToEntry);
                            $updateMd5HashFile = false;
                            if (file_exists($cacheEntry)) {
                                $oldMd5Hash = trim(file_get_contents($cacheEntry));
                                if ($updateMd5HashFile = ($newMd5Hash !== $oldMd5Hash)) {
                                    $this->_changedFiles[] = $pathToEntry;
                                }
                            }
                            else {
                                $this->_newFiles[] = $pathToEntry;
                                $updateMd5HashFile = true;
                            }
                            if ($updateMd5HashFile) {
                                file_put_contents($cacheEntry, $newMd5Hash);
                            }
                            $this->_scannedFiles[] = $pathToEntry;
                        }
                    }
                }
            }
        }

        $CurrentWorkingDirectory->close();
        unset($CurrentWorkingDirectory);

        return $this;
    }

    private function scanDeletedFiles($originDirectory, $cacheDirectory) {
        $workingDirectory = $cacheDirectory . $originDirectory;
        $CurrentWorkingDirectory = dir($workingDirectory);
        while ($entry = $CurrentWorkingDirectory->read()) {
            if ($entry != "." && $entry != "..") {
                $pathToEntry = rtrim($originDirectory, '/') . '/' . $entry;
                $pathToCacheEntry = $cacheDirectory . $pathToEntry;
                if (is_dir($pathToCacheEntry)) {
                    if (! is_dir($pathToEntry)) {
                        $this->_deletedFiles[$pathToCacheEntry] = $pathToEntry;
                    }
                    $this->scanDeletedFiles($pathToEntry, $cacheDirectory);
                }
                else {
                    $originEntry = substr($pathToEntry, 0, -4);
                    if (! file_exists($originEntry)) {
                        $this->_deletedFiles[$pathToCacheEntry] = $originEntry;
                        //unlink($pathToCacheEntry);
                    }
                }
            }
        }

        $CurrentWorkingDirectory->close();
        unset($CurrentWorkingDirectory);

        if (0 < count($this->_deletedFiles)) {
            foreach ($this->_deletedFiles as $cacheEntry => $originEntry) {
                switch (true) {
                    case is_file($cacheEntry):
                        unlink($cacheEntry);
                        break;

                    case is_dir($cacheEntry):
                        $this->rmdir($cacheEntry);
                        break;
                }
            }
        }

        return $this;
    }

    private function isInExcludes($entry) {
        foreach ($this->_excludeFiles as $exclude) {
            $length = strlen($exclude);
            if ($length <= strlen($entry) && substr($entry, 0, $length) === $exclude) {
                return true;
            }
        }
        return false;
    }

    private function validFiletype($entry) {
        if (is_file($entry)) {
            $ext = strtolower(pathinfo($entry,  PATHINFO_EXTENSION));
            if (1 < strlen($ext)) {
                $status = preg_match($this->_regex, $ext);
                if ($this->_negateRegex) { $status = ! $status; }
                return $status;
            }
        }
        return false;
    }

    private function rmdir($directory, $recursive = true) {
        $CurrentWorkingDirectory = dir($directory);

        while ($entry = $CurrentWorkingDirectory->read()) {
            if ($entry != "." && $entry != "..") {
                $pathToEntry = rtrim($directory, '/') . '/' . $entry;
                if (is_dir($pathToEntry)) {
                    $this->rmdir($pathToEntry);
                }
                else {
                    unlink($pathToEntry);
                }
            }
        }

        rmdir($directory);
        $CurrentWorkingDirectory->close();
        unset($CurrentWorkingDirectory);
    }

    private function resolveType($entry, $wrap = '|') {
        $attr = false;
        switch (true) {
            case is_dir($entry):
                $attr = 'D';
                break;

            case is_link($entry):
                $attr = 'L';
                break;

            case is_file($entry):
                $attr = 'F';
                break;
        }
        return str_replace('|', $attr, $wrap);
    }

    private function generateStatistics($originDirectory, $logsDirectory) {
        // $initLogsDirectory = $logsDirectory . $originDirectory;
        $initLogsDirectory = $logsDirectory;
        if (! is_dir($initLogsDirectory)) {
            mkdir($initLogsDirectory, 0755, true);
        }
        $logfile = $this->_logfile;
        $pathToLogfile = rtrim($initLogsDirectory, '/') . '/' . $logfile;
        unset($initLogsDirectory);

        if (0 === count($this->_newFiles) + count($this->_changedFiles) + count($this->_deletedFiles)) {
            return false;
        }

        if (1 < $this->_procCounter) {
            file_put_contents($pathToLogfile, chr(10) . '--------' . chr(10) . chr(10), FILE_APPEND);
        }
        file_put_contents($pathToLogfile, 'Scanning directory "' . $originDirectory . '"' . chr(10), FILE_APPEND);

        file_put_contents($pathToLogfile, chr(10) . 'New entries (' . count($this->_newFiles) . '):' . chr(10), FILE_APPEND);
        if (0 < count($this->_newFiles)) {
            foreach ($this->_newFiles as $i => $filename) {
                file_put_contents($pathToLogfile, $this->resolveType($filename, "| {$filename}") . chr(10), FILE_APPEND);
                unset($this->_newFiles[$i]);
            }
        }

        file_put_contents($pathToLogfile, chr(10) . 'Changed entries (' . count($this->_changedFiles) . '):' . chr(10), FILE_APPEND);
        if (0 < count($this->_changedFiles)) {
            foreach ($this->_changedFiles as $i => $filename) {
                file_put_contents($pathToLogfile, $this->resolveType($filename, "| {$filename}") . chr(10), FILE_APPEND);
                unset($this->_changedFiles[$i]);
            }
        }

        file_put_contents($pathToLogfile, chr(10) . 'Deleted entries (' . count($this->_deletedFiles) . '):' . chr(10), FILE_APPEND);
        if (0 < count($this->_deletedFiles)) {
            foreach ($this->_deletedFiles as $i => $filename) {
                file_put_contents($pathToLogfile, $this->resolveType($filename, "| {$filename}") . chr(10), FILE_APPEND);
                unset($this->_deletedFiles[$i]);
            }
        }

        return $pathToLogfile;
    }

    public function convertMemory($size) {
        $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
        return @round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . ' ' . $unit[$i];
     }

}

?>
