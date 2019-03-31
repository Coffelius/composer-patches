<?php
/**
 * Copyright © Vaimo Group. All rights reserved.
 * See LICENSE_VAIMO.txt for license details.
 */
namespace Vaimo\ComposerPatches\Utils;

class FileSystemUtils
{
    public function collectPathsRecursively($rootPath, $pattern)
    {
        $directoryIterator = new \RecursiveDirectoryIterator($rootPath);
        $recursiveIterator = new \RecursiveIteratorIterator($directoryIterator);

        $filesIterator = new \RegexIterator(
            $recursiveIterator, 
            $pattern, 
            \RecursiveRegexIterator::GET_MATCH
        );

        $files = array();

        foreach ($filesIterator as $info) {
            $files[] = reset($info);
        }

        return $files;
    }
}
