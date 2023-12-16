<?php

namespace app\parse;

use app\PageParser\PageParser;
use app\PageParser\PropertyNotFoundException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;

/**
 * @property-read array $_stackPages
 * @property-read array $_stack
 * @\app\parse\Page
 * @Page
 */
class Page
{
    /**
     * Keeps number of item in stack
     * @var array $_stackCount
     */
    private static array $_stackCount = [];
    private static array $_stack = [];
    private static int $_repeatX = 0;
    /**
     * No. of times page is repeated in vertically
     * @var int $_repeatY
     */
    private static int $_repeatY = 0;
    /**
     * @var string|array[] $html
     */
    public $html;
    /**
     * Keeps the rendered templates
     * @var null|string|array|object $_stack
     */
    private $_stackPages = [];
    /**
     * @var object|PageParser $_owner
     */
    private $_owner;
    /**
     * @var DOMDocument|object $_dom
     */
    private $_dom;
    /**
     * @var DOMXPath|object $_xpath
     */
    private $_xpath;
    /**
     * @var DOMElement|object $_page
     */
    private $_page;
    /**
     * @var string|array[] $_name
     */
    private $_name;
    /**
     * @var null|string|array|object $_context
     */
    private $_context;
    /**
     * @var object|Environment $_parserTemplate
     */
    private $_parserTemplate;
    /**
     * Current left from page left
     * @var int|float $_left
     */
    private $_left = 0;
    /**
     * Current top from page top
     * @var int|float $_top
     */
    private $_top = 0;
    /**
     * @var array
     */
    private $_height;
    /**
     * @var array
     */
    private $_width;
    /**
     * No. of times page is repeated in horizontally
     * @var int $_repeatX
     */
    private int $_repeatXPage = 0;
    private int $_repeatYPage = 0;
    private int $_repeatXLimit = 1;
    private int $_repeatYLimit = 1;

    private bool $_isUsingPages = false;

    private array $_dataInitialLeft;

    /**
     * @throws Exception
     */
    public function __construct(array $params = [])
    {
        $this->propertyModifier($this, $params);
    }

    /**
     * @param object $source
     * @param array $properties
     * @return void
     * @throws Exception
     */
    private function propertyModifier(object $source, array $properties)
    {
        $className = get_class($source);
        if (!empty($properties)) {
            foreach ($properties as $property => $value) {
                if (!property_exists($this, $property)) {
                    throw new PropertyNotFoundException("$className has no property named $property \n $className::$property does not exists");
                }
                $source->$property = $value;
            }
        }
    }

    public function useContext($context): self
    {
        $this->_context = $context;
        return $this;
    }

    /**
     * @return string
     */
    public function getHtml(): string
    {
        return $this->html;
    }

    /**
     * @param string $html
     */
    public function setHtml(string $html): void
    {
        $this->html = $html;
    }

    /**
     * @return DOMElement
     */
    public function getPage(): DOMElement
    {
        return $this->_page;
    }

    /**
     * @param DOMElement $page
     */
    public function setPage(DOMElement $page): void
    {
        $this->_page = $page;
    }

    /**
     * @return array
     */
    public function getStack(): array
    {
        if ($this->_isUsingPages) {
            return $this->_stackPages;
        }
        return self::$_stack;
    }

    /**
     * @param array $stack
     */
    public function setStack(array $stack): void
    {
        if ($this->_isUsingPages) {
            $this->_stackPages = $stack;
            return;
        }
        self::$_stack = $stack;
    }

    /**
     * @return array
     */
    public function getStackCount()
    {
        return self::$_stackCount;
    }

    /**
     * Cleans stack
     * @return array
     */
    public function cleanStack(): array
    {
        if ($this->_isUsingPages) {
            return $this->_stackPages = [];
        }
        return self::$_stack = [];
    }

    /**
     * Returns the coordinates of the page
     * @throws SyntaxError
     * @throws LoaderError
     */
    public function prepareLayout($index): array
    {
        foreach ($this->getXpath()->query('./*[contains(@style, "absolute")]') as $element) {

            $top = $left = 0;
            /* @var DOMElement $element */
            $style = $element->getAttribute('style');

            $style = preg_replace_callback('/top:\s*(\d+)(\D+)?;/', function () use (&$element, &$top) {
                /*$newTop = $element->getAttribute('data-template-initial-top') + (($this->_repeatY * $this->_top) * $this->_height['value']);*/
                $newTop = $element->getAttribute('data-template-initial-top') + ($this->getRepeatY() * $this->_height['value']);
                return $top = "top: ${newTop}px;";
            }, $style);

            // Replace the left value of the style attribute
            $style = preg_replace_callback('/left:\s*(\d+)(\D+)?;/', function () use (&$element, &$left) {
                $newLeft = $element->getAttribute('data-template-initial-left');
                if ($this->_isUsingPages) {
                    /**
                     * Subtract initial left distance from the page
                     * So that there is no page gap between pages
                     * Example:
                     *
                     *     |---------------------------------------------------------------|
                     *     |        |-------------|                 |--------------|       |
                     *     |<-10px--| width:120px |<-(10px+120px)   | left:130px   |       |
                     *     |        |             |                 |              |       |
                     *     |        |             |                 |              |       |
                     *     |        |             |                 |              |       |
                     *     |        |-------------|                 |--------------|       |
                     *     |                                                               |
                     *     |---------------------------------------------------------------|
                     *  If use-pages is set we subtract initial-left of each from each page
                     */

                    $newLeft -= $this->_dataInitialLeft['value'] ?? 0;
                    $newLeft += $this->getRepeatX() * $this->_width['value'];
                } else {
                    $newLeft = $this->getRepeatX() * $this->_width['value'];
                    $newLeft -= $this->_dataInitialLeft['value'] ?? 0;
                }
                return $left = "left: ${newLeft}px;";
            }, $style);

            /*var_dump("$top, $left");*/
            // Set the new value of the style attribute
            $element->setAttribute('style', $style);
        }

//        $cal = $this->getRepeatX() * $this->_width['value'];
//        var_dump($left);
//        var_dump("page: {$this->getName()}, repeat - y: {$this->getRepeatY()}, repeat - x: {$this->getRepeatX()} new-left: {$this->_left}, cal: {$cal}, left: {$left}");
//
        $this->increaseRepeatX();

        /**
         * manage column count and row count
         */
        // If column exceeds increase row count
        if ($this->getRepeatX() > $this->_repeatXLimit - 1) {
            $this->setRepeatX(0);
            $this->increaseRepeatY();
        }
        // if row exceeds reset column and rows count
        if ($this->getRepeatY() > $this->_repeatYLimit - 1) {
            $this->setRepeatY(0);
            $this->setRepeatX(0);
            $this->_top = 0;
            $this->_owner->setCanWrite(true);
        }
        $this->render($index, $this->getDom()->saveHTML());
        return [$this->getName(), $this->getRepeatX(), $this->getRepeatY()];
    }

    /**
     * @return DOMXPath
     */
    public function getXpath(): DOMXPath
    {
        return $this->_xpath;
    }

    /**
     * @param DOMXPath $xpath
     */
    public function setXpath(DOMXPath $xpath): void
    {
        $this->_xpath = $xpath;
    }

    public function getRepeatY()
    {
        if ($this->_isUsingPages) {
            return $this->_repeatYPage;
        }
        return self::$_repeatY;
    }

    public function getRepeatX()
    {
        if ($this->_isUsingPages) {
            return $this->_repeatXPage;
        }
        return self::$_repeatX;
    }

    private function increaseRepeatX(): int
    {
        if ($this->_isUsingPages) {
            return ++$this->_repeatXPage;
        }
        return ++self::$_repeatX;
    }

    private function setRepeatX(int $value)
    {
        if ($this->_isUsingPages) {
            return $this->_repeatXPage = $value;
        }
        return self::$_repeatX = $value;
    }

    private function increaseRepeatY()
    {
        if ($this->_isUsingPages) {
            return ++$this->_repeatYPage;
        }
        return ++self::$_repeatY;
    }

    private function setRepeatY(int $value)
    {
        if ($this->_isUsingPages) {
            return $this->_repeatYPage = $value;
        }
        return self::$_repeatY = $value;
    }

    /**
     * @param $key
     * @param $html
     * @return int
     * @throws LoaderError
     * @throws SyntaxError
     */
    public function render($key, $html): int
    {
        try {
            return
                $this->pushInStack(
                    $key,
                    $this->
                    getOwner()->
                    getTemplateParser()->
                    createTemplate($html)->
                    render($this->_context));
        } catch (LoaderError|SyntaxError $e) {
            print_r($e);
            throw $e;
        }
    }

    /**
     * Pushes context data
     * @param $key
     * @param $value
     * @return int
     */
    public function pushInStack($key, $value): int
    {
        if ($this->_isUsingPages) {
            $this->_stackPages[] = $value;
        } else {
            self::$_stack[] = $value;
        }

        return $key;
    }

    /**
     * @return PageParser
     */
    public function getOwner(): PageParser
    {
        return $this->_owner;
    }

    /**
     * @param PageParser $owner
     * @return Page
     */
    public function setOwner(PageParser $owner): self
    {
        $this->_owner = $owner;
        return $this;
    }

    /**
     * @return DOMDocument
     */
    public function getDom(): DOMDocument
    {
        return $this->_dom;
    }

    /**
     * @param DOMDocument $dom
     */
    public function setDom(DOMDocument $dom): void
    {
        $this->_dom = $dom;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->_name;
    }

    /**
     * @param string $name
     * @return Page
     */
    public function setName(string $name): self
    {
        $this->_name = $name;
        return $this;
    }

    private function getParserTemplate()
    {
        return $this->_parserTemplate;
    }
}































