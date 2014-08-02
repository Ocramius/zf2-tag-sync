<?php

class FrameworkComponent
{
    /**
     * @var string
     */
    private $namespace;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $frameworkPath;

    /**
     * @var string
     */
    private $vendorPath;

    /**
     * @param string $namespace
     * @param string $name
     * @param string $frameworkPath
     * @param string $vendorPath
     */
    public function __construct($namespace, $name, $frameworkPath, $vendorPath)
    {
        if (json_decode(file_get_contents($frameworkPath . '/composer.json'), true)['name'] !== $name) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" doesn\'t seem to contain component "%s"',
                $frameworkPath,
                $name
            ));
        }

        if (json_decode(file_get_contents($vendorPath . '/composer.json'), true)['name'] !== $name) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" doesn\'t seem to contain component "%s"',
                $vendorPath,
                $name
            ));
        }

        $this->namespace     = (string) $namespace;
        $this->name          = (string) $name;
        $this->frameworkPath = (string) $frameworkPath;
        $this->vendorPath    = (string) $vendorPath;
    }

    /**
     * @return string
     */
    public function getNamespace()
    {
        return $this->namespace;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getFrameworkPath()
    {
        return $this->frameworkPath;
    }

    /**
     * @return string
     */
    public function getVendorPath()
    {
        return $this->vendorPath;
    }
}
