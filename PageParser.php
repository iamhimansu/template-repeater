<?php

namespace app\PageParser;

use app\parse\Page;
use DOMAttr;
use DOMDocument as Dom;
use DOMElement;
use DOMXPath as Xpath;
use Exception;
use Iterator;
use Twig\Environment;

/**
 * @author Himanshu Raj Aman
 * @version 0.0.1
 */
class PageParser implements Iterator
{
    /**
     * @var string $regexUnitValue
     */
    protected const REGEX_UNIT_VALUE = '/(\bhtml\b|\bfalse\b|\btrue\b|\ba(4|3)\b|\bl\b|\bp\b)|(\d+)\s?(px|in)?|$/mi';
    /**
     * @property-read Dom $_dom
     * Stores the Dom for the given template
     */
    private static Dom $_dom;
    /**
     * @property-read Xpath $_xpath
     * Stores the xpath
     */
    private static Xpath $_xpath;
    /**
     * @var array $_pages
     * Stores the pages
     */
    private static array $_pages;
    /**
     * @property array $_templateMetaData
     * Stores all the metadata information of the template
     */
    private static array $_templateMetaData;
    /**
     * @var string
     */
    public string $paper = 'A4';
    /**
     * Default page break
     * This will be used when adding page breaks
     * @var string
     */
    public string $pagebreak = '<pagebreak></pagebreak>';
    public bool $canWrite = false;
    /**
     * @property-read string $_template
     * The template to be set as source that will be used for repeating
     */
    private string $_template;
    /**
     * @property-read object|Environment $_templateParser
     * Parser used for parsing template
     */
    private $_templateParser;
    /**
     * @property-read bool $_isInitialized
     * Used for extracting params
     */
    private bool $_isInitialized = false;
    /**
     * @var string $_templateId
     * ID of the template element from where the metadata will be extracted
     */
    private string $_templateId = 'h-template';
    /**
     * The context to be passed in parsing engine
     * @var null|array|object
     */
    private $_context = null;
    /**
     * No. of rows printed
     * @var int $_rowCount
     */
    private int $_rowCount = 1;
    /**
     * No. of cols printed
     * @var int $_colCount
     */
    private int $_colCount = 1;
    /**
     * Number of rows to be repeated
     * @var int $_rowLimit
     */
    private int $_rowLimit = 1;
    /**
     * @var int Number of columns to be repeated
     */
    private int $_colLimit = 1;
    private bool $_isUsingPages = false;

    private int $_totalPages = 0;

    /**
     * Initializes template and extract metadata information
     * @throws Exception
     */
    public function init()
    {
        /**
         * Create dom and domXPath
         */
        [self::$_dom, self::$_xpath] = self::createDom($this->getTemplate());
        /**
         * Extract meta data from template
         */
        $this->extractTemplateMetaData();

        /**
         * Initializes row and columns limit
         * Prevent comparison in each iteration
         */
        $this->initRowColLimit();

        /**
         * Construct pages like <page1>, <page2>, .... <pageN>
         * Saves it to self::$_pages array
         * @return void
         * @throws Exception
         * @see constructPages()
         */
        $this->constructPages();

        /**
         * Set total Number of pages
         */
        $this->_totalPages = count(self::getPages());

        /**
         * Locks the initial position of the pages
         * @see lockInitialPositions()
         */
        $this->lockInitialPositions();
        /**
         * This will be used as a flag to prevent reparsing of the metadata and template
         */
        $this->setIsInitialized();
    }

    /**
     * @param $html
     * Creates a html dom for the given html
     * @return array $html
     * @throws Exception
     */
    private static function createDom($html): array
    {
        try {
            $dom = new Dom();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = false;
            $dom->strictErrorChecking = false;
            // Access $dom as an instance of Dom
            $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOBLANKS | LIBXML_HTML_NOIMPLIED);
            $dom->normalizeDocument(); // removes extra spaces

            //Remove doctype as this can cause some errors in PDFs
            if ($dom->doctype) {
                $dom->removeChild($dom->doctype);
            }
            //xPath
            $xpath = new Xpath($dom);
            $xpath->registerNamespace('style', 'http://www.w3.org/1999/xhtml');

            return [$dom, $xpath];
        } catch (Exception $e) {
            self::Log($e);
            throw $e;
        }
    }

    /**
     * Logs the whole process while generating the templates
     * @param $data
     * @return void
     * @throws Exception
     */
    private static function Log($data)
    {
        //Implement log
        /*throw new Exception((string)$data);*/
    }

    /**
     * @return string
     * Return the template
     */
    public function getTemplate(): string
    {
        return $this->_template;
    }

    /**
     * Sets the template
     * @param string|null $template
     * @return self
     * @throws EmptyTemplateException
     */
    public function setTemplate(?string $template): self
    {
        if (empty($template)) {
            throw new EmptyTemplateException('Template cannot be empty');
        }
        $this->_template = $template;
        return $this;
    }

    /**
     * @return void
     * Extracts the metadata from the template
     * Like: width, height, top, left, gap
     * @throws Exception
     */
    private function extractTemplateMetaData(): void
    {
        $templateId = self::getTemplateId();
        /**
         * Find template by tagName and ID
         */
        $templateTag = self::$_xpath->query('//*[local-name()="template" and @id="' . $templateId . '"]')->item(0);
        //Template tag should be present
        if (empty($templateTag)) {
            throw new TemplateMetadataNotFoundException("No <template> element found with id='$templateId'");
        }
        //Some configurations should always be there
        if (!$templateTag->hasAttributes()) {
            throw new TemplateMetadataMissingException('<template> has no metadata.');
        }

        // './' means the current level of active dom
        $templateDataAttributes = self::$_xpath->query('.//@*[starts-with(local-name(), "data-")]');

        if (empty($templateDataAttributes->length)) {
            throw new TemplateMetadataMissingException('<template> has no data-**(-**)? configurations, please add configurations.');
        }

        foreach ($templateDataAttributes as $dataAttribute) {
            /**
             * @var DOMAttr $dataAttribute
             */
            $this->pushTemplateMetaData(
                $dataAttribute->localName,
                $this->parseMetaConfigurationValues($dataAttribute->value));
        }

        //remove <template> from html
        $templateTag->parentNode->removeChild($templateTag);

        /**
         * Re - Generate Template
         */
        $template = self::$_dom->saveHTML($templateTag->parentNode);
        /**
         * Check if page compression is enabled
         */
        if (filter_var(self::getTemplateMetaDataValue('@compress'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
            $this->minify($template);
        }
        $this->setTemplate($template);
    }

    /**
     * @return string
     */
    public function getTemplateId(): string
    {
        return $this->_templateId;
    }

    /**
     * Used for selecting template for extracting templateMetaData
     * @param string $templateId
     * @return PageParser
     * @throws Exception
     */
    public function setTemplateId(string $templateId): self
    {
        if (empty($templateId)) {
            throw new Exception('Template Id cannot be blank.');
        }
        $this->_templateId = $templateId;
        return $this;
    }

    /**
     * Pushes data into templateMetaData
     * @param $key
     * @param $value
     * @return void
     */
    private function pushTemplateMetaData($key, $value): void
    {
        self::$_templateMetaData[$key] = $value;
    }

    /**
     * Converts the template parameter/configurations into parsable value
     * @param $value
     * @return array
     */
    private function parseMetaConfigurationValues($value): array
    {
        /**
         * Parse attribute and value
         */
        preg_match(self::REGEX_UNIT_VALUE, $value, $matches);

        return ['value' => $matches[3] ?? $matches[0], 'unit' => $matches[4] ?? null];
    }

    /**
     * Gets the value of metadata by key
     * @param $key
     * @param bool $onlyValue
     * @return mixed|void
     */
    public static function getTemplateMetaDataValue($key, bool $onlyValue = true)
    {
        $data = self::$_templateMetaData;
        switch ($key) {
            case $key[0] === '@':
                $resolvedKey = 'data-' . substr($key, 1);
                if (isset($data[$resolvedKey])) {
                    if ($onlyValue) {
                        return $data[$resolvedKey]['value'];
                    }
                    return $data[$resolvedKey];
                }
                return null;
            default:
                if (isset($data[$key])) {
                    if ($onlyValue) {
                        return $data[$key]['value'];
                    }
                    return $data[$key];
                }
                return null;
        }
    }

    /**
     * Removes extra spaces
     * @param $buffer
     * @return array|string|string[]|null
     */
    private function minify(&$buffer)
    {
        $search = array(
            /*'/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\')\/\/.*))/',*/
            '/(?:\/\*(?>[^*]*\*+)*?\/)|(?:\/\/.*)/',
            '/\/\*[\s\S]*?\*\/|([^:]|^)\/\/.*$/m',
            '/>\s+</',           // strip whitespaces before and after tags, except space
            '/(\s)+/s',         // shorten multiple whitespace sequences
            '/<!--(.|\s)*?-->/' // Remove HTML comments
        );

        $replace = array(
            '',
            "$1",
            '><',
            '\\1',
            ''
        );

        return $buffer = preg_replace($search, $replace, $buffer);
    }

    /**
     * Sets default rows and columns limit
     * @return void
     */
    private function initRowColLimit()
    {
        $cols = self::getTemplateMetaDataValue('@cols');
        $rows = self::getTemplateMetaDataValue('@rows');
        if (null !== $cols) {
            $this->_colLimit = $cols;
        }
        if (null !== $rows) {
            $this->_rowLimit = $rows;
        }
    }

    /**
     * Constructs the pages for creating rendered pages
     * @throws Exception
     */
    protected function constructPages()
    {
        $htmlPages = self::$_xpath->query('//*[starts-with(local-name(), "page")]');
        /** If use-pages is true and pages exists in template */
        $this->_isUsingPages = filter_var(self::getTemplateMetaDataValue('@use-pages'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($this->_isUsingPages && $htmlPages->length == 0) {
            throw new Exception('Use of [use-pages] without <page> element, please wrap sections into pages.<br>Example: <page1><p>Front</p></page1><page2><p>Back</p></page2>');
        }
        if ($htmlPages->length > 0) {
            foreach ($htmlPages as $index => $page) {
                self::$_pages[$index] = self::createPage($page, $this);
            }
        } /** else create whole template as page */
        else {
            self::$_pages[] = self::createPage(self::getDom()->documentElement, $this);
        }
    }

    /**
     * Creates pages for parsing
     * @param DOMElement $pageElement
     * @param self $owner
     * @return Page
     * @throws Exception
     */
    private static function createPage(DOMElement $pageElement, self $owner): Page
    {
        /**
         * Page data-initial-left initialization
         */
        $pageInitialLeft = '0px';

        if ($owner->_isUsingPages) {
            /**
             * Get page data-initial-left
             */
            $pageInitialLeft = $pageElement->getAttribute('data-initial-left');

            if (empty($pageInitialLeft)) {
                throw new EmptyAttributeException("[data-initial-left] attribute not set on <$pageElement->localName>");
            }
        }
        $pageParent = $pageElement->ownerDocument;

        /* @var Dom $pageParent */
        $pageHtml = $pageParent->saveHtml($pageElement);

        /**
         * @var Dom $pageDom
         * @var Xpath $pageXPath
         */
        [$pageDom, $pageXPath] = self::createDom($pageHtml);

        return new Page([
            '_owner' => $owner,
            '_dataInitialLeft' => $owner->parseMetaConfigurationValues($pageInitialLeft),
            '_height' => self::getTemplateMetaDataValue('@height', false),
            '_width' => self::getTemplateMetaDataValue('@width', false),
            '_repeatXLimit' => $owner->_colLimit,
            '_repeatYLimit' => $owner->_rowLimit,
            '_isUsingPages' => $owner->_isUsingPages,
            '_dom' => $pageDom,
            '_xpath' => $pageXPath,
            '_page' => $pageElement,
            '_name' => $pageElement->localName,
        ]);
    }

    /**
     * @return Dom
     */
    public static function getDom(): Dom
    {
        return self::$_dom;
    }

    /**
     * @return array
     */
    public static function getPages(): array
    {
        return self::$_pages;
    }

    /**
     * Adds extra attributes for initial positions
     * Attributes:
     *  data-template-initial-top
     *  data-template-initial-left
     *  data-template-initial-right
     *  data-template-initial-left
     * @return void
     */
    private function lockInitialPositions(): void
    {
        $width = filter_var(self::getTemplateMetaDataValue('@width'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        $isUsingPages = filter_var(self::getTemplateMetaDataValue('@use-pages'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $i = 0;
        foreach (self::getPages() as $page) {
            /* @var Page $page */
            foreach ($page->getXpath()->query('./*[contains(@style, "absolute")]') as $element) {
                /* @var DOMElement $element */
                $style = $element->getAttribute('style');
                /* set original top */
                preg_replace_callback('/top:\s*(\d+)(\D+)?;/', function ($match) use ($element) {
                    $element->setAttribute('data-template-initial-top', $match[1]);
                }, $style, 1);
                /* set original left */
                preg_replace_callback('/left:\s*(\d+)(\D+)?;/', function ($match) use ($element, $i, $isUsingPages) {
                    $element->setAttribute('data-template-initial-left', $match[1]);
                }, $style, 1);
                /* set original right */
                preg_replace_callback('/right:\s*(\d+)(\D+)?;/', function ($match) use ($element) {
                    $element->setAttribute('data-template-initial-right', $match[1]);
                }, $style, 1);
                /* set original bottom */
                preg_replace_callback('/bottom:\s*(\d+)(\D+)?;/', function ($match) use ($element) {
                    $element->setAttribute('data-template-initial-bottom', $match[1]);
                }, $style, 1);
            }
            $i++;
        }
    }

    /**
     * @return Xpath
     */
    public static function getXpath(): Xpath
    {
        return self::$_xpath;
    }

    /**
     * @param bool $isInitialized
     * @return self
     */
    private function setIsInitialized(bool $isInitialized = true): self
    {
        $this->_isInitialized = $isInitialized;
        return $this;
    }

    /**
     * @return object|Environment
     */
    public function getTemplateParser(): object
    {
        return $this->_templateParser;
    }

    /**
     * @param object $templateParser
     * @return self
     */
    public function setTemplateParser(object $templateParser): self
    {
        $this->_templateParser = $templateParser;
        return $this;
    }

    /**
     * @return bool
     */
    public function isInitialized(): bool
    {
        return $this->_isInitialized;
    }

    /**
     * @return array
     */
    public function getTemplateMetaData(): array
    {
        return self::$_templateMetaData;
    }

    /**
     * Uses this data for rendering/parsing template with given template parser
     * @param array $data
     * @return void
     */
    public function setContext(array $data): self
    {
        $this->_context = $data;
        return $this;
    }

    /**
     * Pushes context into pages
     */
    public function push(): void
    {
        /*$this->canWrite = false;*/
        foreach (self::getPages() as $index => $page) {
            /* @var Page $page */
//            try {
                $page->useContext($this->_context)->prepareLayout($index);
//            } catch (Exception $e) {
//                echo '';
//                throw $e;
//            }
        }

        $this->_colCount++;

        /**
         * manage column count and row count
         */
        // If column exceeds increase row count
        if ($this->_colCount > $this->_colLimit) {
            $this->_rowCount++;
            $this->_colCount = 1;
        }
        // if row exceeds reset column and rows count
        if ($this->_rowCount > $this->_rowLimit) {
            $this->_rowCount = 1;
            $this->_colCount = 1;
            $this->canWrite = true;
            /*yield $this->getContent();*/
        }
    }

    /**
     * Can parser write the html
     * @return bool
     */
    public function canWrite()
    {
        return $this->canWrite;
    }

    /**
     * @param bool $canWrite
     */
    public function setCanWrite(bool $canWrite): void
    {
        $this->canWrite = $canWrite;
    }
    /**
     * Return the html into pages
     * @return string|null
     */
    public function content()
    {
        $this->canWrite = false;
        return $this->getContent();
    }

    /**
     * Creates template
     * @return string
     */
    private function getContent(): string
    {
        $output = [];
        foreach (self::getPages() as $pageIndex => $page) {
            /** @var Page $page */
            $output[$pageIndex] = implode($page->getStack());
            $page->cleanStack();
        }
        //for additional page-break
        if ($this->_isUsingPages) {
            $output[] = null;
        }
        return implode($this->pagebreak, $output);
    }

    /**
     * @return mixed
     */
    public function current()
    {
        // TODO: Implement current() method.
    }

    /**
     * @return void
     */
    public function next()
    {
        // TODO: Implement next() method.
    }

    /**
     * @return mixed|null
     */
    public function key()
    {
        // TODO: Implement key() method.
    }

    /**
     * @return bool
     */
    public function valid()
    {
        // TODO: Implement valid() method.
    }

    /**
     * @return void
     */
    public function rewind()
    {
        // TODO: Implement rewind() method.
    }

    /**
     * Return the number of total pages
     * @return int
     */
    public function getTotalPages(): int
    {
        return $this->_totalPages;
    }

    /**
     * Clean pages stack
     * @return void
     */
    private function cleanPages()
    {
        foreach (self::getPages() as $page) {
            /** @var Page $page */
            $page->cleanStack();
        }
    }

}






















