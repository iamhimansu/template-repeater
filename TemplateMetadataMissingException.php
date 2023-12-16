<?php

namespace app\PageParser;

use Exception;

class TemplateMetadataMissingException extends Exception
{
    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}