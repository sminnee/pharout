PharOut
=======

PharOut is a tool for generating Phar distributions fo PHP CLI tools. It was derived from the Compiler.php script included in Composer's source, and has been adapted to be a more general purpose tool.

Why PharOut?
------------

Distributing a PHP-based CLI tool as a phar archive is a great way of making it, but the mechanisms for creating executable phar files can be a little arcane if you haven't done it before. I wanted to make it easy for developers to make use of libraries such as symfony/console to produce polished CLI tools, and then distribute them to users.

How to use
----------

PharOut doesn't provide much direction over how you create your project, but I lay out my CLI projects in a structure something like this:

    bin/
        mytool
    src/
        MyNamespace/
            MyTool.php
    vendor/
    composer.json
    composer.lock
    LICENSE
    README.md

PharOut is designed to work nicely with Composer, so I suggest that you use this to manage dependencies.

To set it up, first include composer as a dev dependency in your project, and create an empty file "bin/compile". This will be the command you call to generate the phar.

    compososer require --dev sminnee/pharout
    touch bin/compile
    chmod +x bin/compile

Use the following code as a starting point for creating your bin/compile file. In short, you create a `SilverStripe\PharOut\Compiler` object, configure it, and then call writePhar() to generate the phar.

    #!/usr/bin/env php
    <?php

    $projectPath = dirname(__DIR__);
    require($projectPath . '/vendor/autoload.php');

    // Compiler configuration
    $compiler = new SilverStripe\PharOut\Compiler();
    $compiler
        ->forProjectAt($projectPath)
        ->withExecutable("bin/mytool")
        ->withSourcePath("src")
        ->withComposerPackages(array(
            "symfony/console",
        ))
        ->withInternalMessage("My Tool (c) 2014 Yours Truly")
    ;

    $compiler->writePhar("mytool.phar");

Now, you can run `./bin/compile` from your project root, and `mytool.phar` will be created or replaced.

Happy packaging!