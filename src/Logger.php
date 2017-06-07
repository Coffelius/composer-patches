<?php
namespace Vaimo\ComposerPatches;

class Logger
{
    /**
     * @var \Composer\IO\IOInterface $io
     */
    protected $io;

    /**
     * @param \Composer\IO\IOInterface $io
     */
    public function __construct(
        \Composer\IO\IOInterface $io
    ) {
        $this->io = $io;
    }

    public function getOutputInstance()
    {
        return $this->io;
    }

    public function writeRaw($message, $args = array())
    {
        $this->io->write(
            vsprintf($message, $args)
        );
    }

    public function write($message, $type, $args = array())
    {
        $this->io->write(
            vsprintf('<' .$type . '>' . $message . '</' . $type . '>', $args)
        );
    }

    public function writeVerbose($message, $type, $args = array())
    {
        if (!$this->io->isVerbose()) {
            return;
        }

        $this->io->write(
            vsprintf('<' .$type . '>' . $message . '</' . $type . '>', $args)
        );
    }

    public function writeException(\Exception $exception)
    {
        if (!$this->io->isVerbose()) {
            return;
        }

        $this->io->write(
            sprintf('<warning>%s</warning>', trim($exception->getMessage(), "\n "))
        );
    }

    public function writeNewLine()
    {
        $this->io->write('');
    }
}
