<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SilverStripe\Deploynaut\Console;

use Symfony\Component\Finder\Finder;
//use Symfony\Component\Process\Process;

/**
 * The Compiler class compiles composer into a phar
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Compiler
{
    private $version;
    private $versionDate;

    /**
     * Compiles composer into a single phar file
     *
     * @throws \RuntimeException
     * @param  string            $pharFile The full path to the file to create
     */
    public function compile($pharFile, $baseDir, $binFile) {
        if(!$pharFile) throw new \LogicException("Please define output phar file");

        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $phar = new \Phar($pharFile, 0, basename($pharFile));
        $phar->setSignatureAlgorithm(\Phar::SHA1);

        $phar->startBuffering();

        // Add source 
        $finder = new Finder();
        $finder->files()
            ->ignoreVCS(true)
            ->name('*.php')
            ->notName('Compiler.php')
            ->in($baseDir.'src')
        ;

        foreach ($finder as $file) {
            $this->addFile($phar, $file, $baseDir);
        }
//        $this->addFile($phar, new \SplFileInfo(__DIR__ . '/Autoload/ClassLoader.php'), false);
/*
        $finder = new Finder();
        $finder->files()
            ->name('*.json')
            ->in(__DIR__ . '/../../res')
        ;
*/
        foreach ($finder as $file) {
            $this->addFile($phar, $file, $baseDir, false);
        }

        $composerPackages = array(
            'symfony/console',
            'curl/curl',
        );

        // Add composer packges
        foreach($composerPackages as $package) {
            $finder = new Finder();
            $finder->files()
                ->ignoreVCS(true)
                ->name('*.php')
                ->exclude('Tests')
                ->in($baseDir.'/vendor/' . $package)
            ;
        }

        foreach ($finder as $file) {
            $this->addFile($phar, $file, $baseDir);
        }

        $this->addFile($phar, new \SplFileInfo($baseDir.'/vendor/autoload.php'), $baseDir);
        $this->addFile($phar, new \SplFileInfo($baseDir.'/vendor/composer/autoload_namespaces.php'), $baseDir);
        $this->addFile($phar, new \SplFileInfo($baseDir.'/vendor/composer/autoload_psr4.php'), $baseDir);
        $this->addFile($phar, new \SplFileInfo($baseDir.'/vendor/composer/autoload_classmap.php'), $baseDir);
        $this->addFile($phar, new \SplFileInfo($baseDir.'/vendor/composer/autoload_real.php'), $baseDir);
        if (file_exists($baseDir.'/vendor/composer/include_paths.php')) {
            $this->addFile($phar, new \SplFileInfo($baseDir.'/vendor/composer/include_paths.php'), $baseDir);
        }
        $this->addFile($phar, new \SplFileInfo($baseDir.'/vendor/composer/ClassLoader.php'), $baseDir);

        $this->addComposerBin($phar, $binFile, $baseDir);

        // Stubs
        $phar->setStub($this->getStub(basename($pharFile), $binFile));

        $phar->stopBuffering();

        // disabled for interoperability with systems without gzip ext
        // $phar->compressFiles(\Phar::GZ);

        //$this->addFile($phar, new \SplFileInfo(__DIR__.'/../../LICENSE'), false);

        unset($phar);
    }

    private function addFile($phar, $file, $baseDir, $strip = true)
    {
        $path = strtr(str_replace($baseDir, '', $file->getRealPath()), '\\', '/');
        echo "Adding $path...\n";

        $content = file_get_contents($file);
        if ($strip) {
            $content = $this->stripWhitespace($content);
        } elseif ('LICENSE' === basename($file)) {
            $content = "\n".$content."\n";
        }

/*
        if ($path === 'src/Composer/Composer.php') {
            $content = str_replace('@package_version@', $this->version, $content);
            $content = str_replace('@release_date@', $this->versionDate, $content);
        }
*/
        $phar->addFromString($path, $content);
    }

    private function addComposerBin($phar, $binFile, $baseDir)
    {
        $content = file_get_contents($baseDir.'/'.$binFile);
        $content = preg_replace('{^#!/usr/bin/env php\s*}', '', $content);
        $phar->addFromString($binFile, $content);
    }

    /**
     * Removes whitespace from a PHP source string while preserving line numbers.
     *
     * @param  string $source A PHP string
     * @return string The PHP string with the whitespace removed
     */
    private function stripWhitespace($source)
    {
        if (!function_exists('token_get_all')) {
            return $source;
        }

        $output = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $output .= $token;
            } elseif (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT))) {
                $output .= str_repeat("\n", substr_count($token[1], "\n"));
            } elseif (T_WHITESPACE === $token[0]) {
                // reduce wide spaces
                $whitespace = preg_replace('{[ \t]+}', ' ', $token[1]);
                // normalize newlines to \n
                $whitespace = preg_replace('{(?:\r\n|\r|\n)}', "\n", $whitespace);
                // trim leading spaces
                $whitespace = preg_replace('{\n +}', "\n", $whitespace);
                $output .= $whitespace;
            } else {
                $output .= $token[1];
            }
        }

        return $output;
    }

    private function getStub($pharName, $binFile)
    {
        $stub = <<<EOF
#!/usr/bin/env php
<?php
/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view
 * the license that is located at the bottom of this file.
 */

Phar::mapPhar('$pharName');

EOF;

        return $stub . <<<EOF
require 'phar://$pharName/$binFile';

__HALT_COMPILER();
EOF;
    }
}