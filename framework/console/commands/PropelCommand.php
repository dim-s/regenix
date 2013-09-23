<?php
namespace regenix\console\commands;


use regenix\Regenix;
use regenix\console\ConsoleCommand;
use regenix\lang\CoreException;
use regenix\lang\File;

class PropelCommand extends ConsoleCommand {

    const GROUP = 'propel';

    public function __default(){
        if (!$this->app)
            throw new CoreException("To work with the command, load some application via `regenix load <app_name>`");

        $propelBin = ROOT . 'propel-gen';
        $path = $this->app->getPath() . 'conf/orm/';

        $schemaFile = new File($path . 'schema.xml');
        $propertiesFile = new File($path . 'build.properties');
        $runtimeConfig = new File($path . 'runtime-conf.xml');
        $buildtimeConfig = new File($path . 'buildtime-conf.xml');

        if (!$schemaFile->isFile()){
            $this->writeln(
                '[error] Cannot find a schema file `/apps/%s/conf/orm/schema.xml`',
                $this->app->getName()
            );
            return;
        }

        if (!$propertiesFile->isFile()){
            $this->writeln(
                '[error] Cannot find a build file `/apps/%s/conf/orm/build.properties`',
                $this->app->getName()
            );
            return;
        }

        if (!$runtimeConfig->exists()){
            $this->writeln(
                '[error] Cannot find a runtime-conf file `/apps/%s/conf/orm/runtime-conf.xml`'
            );
            return;
        }

        if (!$buildtimeConfig->exists()){
            @copy($runtimeConfig->getPath(), $buildtimeConfig->getPath());
        }

        $output = '';
        $command = $propelBin . ' "' . $path . '" ' . $this->args->get(0) .
            ' "-Dpropel.name=" "-Dpropel.php.dir=' . $this->app->getModelPath() . '"' .
            ' "-Dpropel.schema.autoNamespace=true" 2>&1';

        $this->writeln('>> ' . $command);
        $this->writeln();
        exec($command, $output);

        foreach((array)$output as $line)
            $this->writeln($line);
    }

    public function getInlineHelp()
    {
        return 'propel-gen command within a current loaded application';
    }
}