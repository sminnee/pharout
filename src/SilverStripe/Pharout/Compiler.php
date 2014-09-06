<?php

/*
 * PharOut, (c) 2014 Sam Minnée, <sam@silverstripe.com>
 *
 * Based on the Compiler script included with Composer.
 * (c) Nils Adermann <naderman@naderman.de> Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SilverStripe\PharOut;

use Symfony\Component\Finder\Finder;

/**
 * Compiler class for creating executable phar archives of PHP CLI projects.
 * Designed to be used with Composer projects.
 */
class Compiler {
    protected $projectPath;

    protected $binFile;

    protected $composerPackages = array();

    protected $sourcePaths = array();

    protected $prefixMessage = "";

    /**
     * Set the path of your project, in a fluent syntax.
     * All other paths are relative to this path
     */
    public function forProjectAt($projectPath) {
        if(substr($projectPath,-1) != '/') $projectPath .= '/';
        $this->projectPath = $projectPath;

        return $this;
    }

    /**
     * Set the relative path of your project's executable, in a fluent syntax
     */
    public function withExecutable($binFile) {
        $this->binFile = $binFile;
    
        return $this;
    }

    /**
     * Set a message that is included as a comment at the start of the phar file.
     * This might be a copyright notice, for example.
     */
    public function withInternalMessage($prefixMessage) {
        $this->prefixMessage = $prefixMessage;

        return $this;
    }

    /**
     * Add an array of composer packages, specified as strings of the form "myvendor/mypackage".
     * Note that this will only work if packages are installed in their default locations.
     */
    public function withComposerPackages($packages) {
        $this->composerPackages = array_merge($this->composerPackages, $packages);
 
         return $this;
   }

    /**
     * Add a single composer package
     */
    public function withComposerPackage($package) {
        $this->composerPackages[] = $package;
 
         return $this;
   }

    /**
     * Add an array of source paths, specified relative to the project path
     */
    public function withSourcePaths($paths, $type = "*.php") {
        foreach($paths as $path) {
            $this->addSourcePath($path, $type);
        }
        
        return $this;
    }

    /**
     * Add a single source path.
     */
    public function withSourcePath($path, $type = "*.php") {
        $this->sourcePaths[] = array($path, $type);

        return $this;
    }

    /**
     * Calls compile, handling errors and output more appropriate for a compile script.
     * Displays errors, catches exceptions, and halts with an exit code of 1 if an exception
     * is thrown.
     */
    public function writePhar($pharFile) {
        if($pharFile[0] != '/') $pharFile = $this->projectPath . $pharFile;

        error_reporting(-1);
        ini_set('display_errors', 1);

        try {
            $this->compile($pharFile);
        } catch (\Exception $e) {
            echo 'Failed to compile phar: ['.get_class($e).'] '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine();
            exit(1);
        }
    }



    /**
     * Compiles composer into a single phar file
     *
     * @throws \RuntimeException
     * @param  string            $pharFile The full path to the file to create
     */
    public function compile($pharFile) {
        if(!$pharFile) {
            throw new \LogicException("Please define output phar file");
        }

        if(!$this->projectPath || !$this->binFile) {
            throw new \LogicException("Please set your binFile and projectPath");
        }

        // Remove previous phar, if it exists
        if (file_exists($pharFile)) {
            unlink($pharFile);
        }

        $phar = new \Phar($pharFile, 0, basename($pharFile));
        $phar->setSignatureAlgorithm(\Phar::SHA1);

        $phar->startBuffering();

        // Add project sources
        foreach($this->sourcePaths as $info) {
            list($path, $type) = $info;
            $finder = new Finder();
            $finder->files()
                ->ignoreVCS(true)
                ->name($type)
                ->in($this->projectPath.$path)
            ;

            foreach ($finder as $file) {
                $this->addFile($phar, $file);
            }
        }

        // Add composer packges
        foreach($this->composerPackages as $package) {
            $finder = new Finder();
            $finder->files()
                ->ignoreVCS(true)
                ->name('*.php')
                ->exclude('Tests')
                ->in($this->projectPath.'vendor/' . $package)
            ;

            foreach ($finder as $file) {
                $this->addFile($phar, $file);
            }
        }

        // Add composer autoloader
        $loaderFiles = array(
            'vendor/autoload.php',
            'vendor/composer/autoload_namespaces.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/ClassLoader.php',
        );
        if (file_exists($this->projectPath.'vendor/composer/include_paths.php')) {
            $loaderFiles[] = 'vendor/composer/include_paths.php';
        }
        foreach($loaderFiles as $loaderFile) {
            $this->addFile($phar, new \SplFileInfo($this->projectPath.$loaderFile));
        }

        // Add the binary, set it as the executable part of the phar
        $this->addBinFile($phar, $this->binFile);
        $phar->setStub($this->getStub(basename($pharFile), $this->binFile));

        // Finish!
        $phar->stopBuffering();

        // Add a license file, if it exists
        if(file_exists($this->projectPath . 'LICENSE')) {
            $this->addFile($phar, new \SplFileInfo($this->projectPath . 'LICENSE'), false);  
        }

        unset($phar);
    }

    private function addFile($phar, $file, $strip = true) {
        $path = strtr(str_replace($this->projectPath, '', $file->getRealPath()), '\\', '/');
        echo "Adding $path...\n";

        $content = file_get_contents($file);
        if ($strip) {
            $content = $this->stripWhitespace($content);
        } elseif ('LICENSE' === basename($file)) {
            $content = "\n".$content."\n";
        }

        $phar->addFromString($path, $content);
    }

    private function addBinFile($phar, $binFile) {
        $content = file_get_contents($this->projectPath.'/'.$binFile);
        $content = preg_replace('{^#!/usr/bin/env php\s*}', '', $content);
        $phar->addFromString($binFile, $content);
    }

    /**
     * Removes whitespace from a PHP source string while preserving line numbers.
     *
     * @param  string $source A PHP string
     * @return string The PHP string with the whitespace removed
     */
    private function stripWhitespace($source) {
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

    private function getStub($pharName) {
        $comment = "";
        if($this->prefixMessage) {
            $comment = "/*\n * " . str_replace("\n", "\n * ", trim($this->prefixMessage)) . "\n */";
        }

        $stub = <<<EOF
#!/usr/bin/env php
<?php
$comment

Phar::mapPhar('$pharName');

EOF;

        return $stub . <<<EOF
require 'phar://$pharName/$this->binFile';

__HALT_COMPILER();
EOF;
    }
}