<?php

class Commit
{
    /**
     * @var string
     */
    private $hash;

    /**
     * @var int
     */
    private $time;

    public function __construct($hash, $time)
    {
        if (! is_int($time)) {
            throw new \InvalidArgumentException(sprintf('Given time is not an integer, "%s" given', gettype($time)));
        }

        if (! is_string($hash) || 40 !== strlen($hash)) {
            throw new \InvalidArgumentException(sprintf('Invalid hash "%s" provided', $hash));
        }

        $this->hash = $hash;
        $this->time = $time;
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * @return int
     */
    public function getTime()
    {
        return $this->time;
    }
}
