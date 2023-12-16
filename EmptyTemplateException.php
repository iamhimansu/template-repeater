<?php

namespace app\PageParser;

use Exception;

class EmptyTemplateException extends Exception
{
    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}