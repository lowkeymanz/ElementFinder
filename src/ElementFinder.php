<?php

  namespace Xparse\ElementFinder;

  use Xparse\ElementFinder\Collection\ElementCollection;
  use Xparse\ElementFinder\Collection\StringCollection;
  use Xparse\ElementFinder\ElementFinder\Element;
  use Xparse\ElementFinder\Helper\NodeHelper;
  use Xparse\ElementFinder\Helper\RegexHelper;
  use Xparse\ElementFinder\Helper\StringHelper;
  use Xparse\ExpressionTranslator\ExpressionTranslatorInterface;

  /**
   * @author Ivan Scherbak <dev@funivan.com>
   */
  class ElementFinder {

    /**
     * Html document type
     *
     * @var integer
     */
    const DOCUMENT_HTML = 0;

    /**
     * Xml document type
     *
     * @var integer
     */
    const DOCUMENT_XML = 1;

    /**
     * Hide errors
     *
     * @var int
     */
    protected $options;

    /**
     * Current document type
     *
     * @var integer
     */
    protected $type;

    /**
     * @var \DOMDocument
     */
    protected $dom;

    /**
     * @var \DomXPath
     */
    protected $xpath;

    /**
     * @var ExpressionTranslatorInterface
     */
    protected $expressionTranslator;

    /**
     * @var array
     */
    protected $loadErrors;


    /**
     *
     *
     * Example:
     * new ElementFinder("<html><div>test </div></html>", ElementFinder::HTML);
     *
     * @param string $data
     * @param null|integer $documentType
     * @param int $options
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($data, $documentType = null, $options = null) {

      if (!is_string($data) or empty($data)) {
        throw new \InvalidArgumentException('Expect not empty string');
      }

      $this->dom = new \DomDocument();

      $this->dom->registerNodeClass(\DOMElement::class, Element::class);

      $documentType = $documentType ?? static::DOCUMENT_HTML;
      $this->setDocumentType($documentType);

      $options = $options ?? (LIBXML_NOCDATA & LIBXML_NOERROR);
      $this->setDocumentOption($options);

      $this->setData($data);

    }


    /**
     *
     * @return string
     */
    public function __toString() {
      $result = $this->content('.')->item(0);
      return (string) $result;
    }


    /**
     *
     */
    public function __destruct() {
      unset($this->dom, $this->xpath);
    }


    /**
     * @param string $data
     * @return $this
     * @throws \Exception
     */
    protected function setData($data) {

      $internalErrors = libxml_use_internal_errors(true);
      $disableEntities = libxml_disable_entity_loader(true);

      if (static::DOCUMENT_HTML === $this->type) {
        $data = StringHelper::safeEncodeStr($data);
        $data = mb_convert_encoding($data, 'HTML-ENTITIES', 'UTF-8');
        $this->dom->loadHTML($data, $this->options);
      } else {
        $this->dom->loadXML($data, $this->options);
      }

      $this->loadErrors = libxml_get_errors();
      libxml_clear_errors();

      libxml_use_internal_errors($internalErrors);
      libxml_disable_entity_loader($disableEntities);

      unset($this->xpath);
      $this->xpath = new \DomXPath($this->dom);

      return $this;
    }


    /**
     * @param string $expression
     * @param bool $outerContent
     * @return StringCollection
     */
    public function content($expression, $outerContent = false) {

      $items = $this->executeQuery($expression);

      $collection = new StringCollection();

      foreach ($items as $node) {
        if ($outerContent) {
          $content = NodeHelper::getOuterContent($node);
        } else {
          $content = NodeHelper::getInnerContent($node);
        }

        $collection->append($content);

      }

      return $collection;
    }


    /**
     * You can remove elements and attributes
     *
     * ```php
     * $html->remove("//span/@class");
     *
     * $html->remove("//input");
     * ```
     *
     * @param string $expression
     * @return $this
     */
    public function remove($expression) {

      $items = $this->executeQuery($expression);

      if ($items === false) {
        return $this;
      }

      foreach ($items as $key => $node) {
        if ($node instanceof \DOMAttr) {
          $node->ownerElement->removeAttribute($node->name);
        } else {
          $node->parentNode->removeChild($node);
        }

      }

      return $this;
    }


    /**
     * Get nodeValue of node
     *
     * @param string $expression
     * @return StringCollection
     */
    public function value($expression) {
      $items = $this->executeQuery($expression);
      $collection = new StringCollection();
      foreach ($items as $node) {
        $collection->append($node->nodeValue);
      }
      return $collection;
    }


    /**
     * Return array of keys and values
     *
     * @param string $keyExpression
     * @param string $valueExpression
     * @throws \Exception
     * @return array
     */
    public function keyValue($keyExpression, $valueExpression) {

      $keyNodes = $this->executeQuery($keyExpression);
      $valueNodes = $this->executeQuery($valueExpression);
      if ($keyNodes->length !== $valueNodes->length) {
        throw new \Exception('Keys and values must have equal numbers of elements');
      }

      $result = [];
      foreach ($keyNodes as $index => $node) {
        $result[$node->nodeValue] = $valueNodes->item($index)->nodeValue;
      }

      return $result;
    }


    /**
     * @param string $expression
     * @param bool $outerHtml
     * @throws \Exception
     * @return \Xparse\ElementFinder\Collection\ObjectCollection
     * @throws \InvalidArgumentException
     */
    public function object($expression, $outerHtml = false) {
      $options = $this->getOptions();
      $type = $this->getType();

      $items = $this->executeQuery($expression);

      $collection = new Collection\ObjectCollection();

      foreach ($items as $node) {
        /** @var \DOMElement $node */
        if ($outerHtml) {
          $html = NodeHelper::getOuterContent($node);
        } else {
          $html = NodeHelper::getInnerContent($node);
        }

        if (trim($html) === '') {
          $html = $this->getEmptyDocumentHtml();
        }
        if ($this->getType() === static::DOCUMENT_XML and strpos($html, '<?xml') === false) {
          $html = '<root>' . $html . '</root>';
        }
        $elementFinder = new ElementFinder($html, $type, $options);
        if ($this->expressionTranslator !== null) {
          $elementFinder->setExpressionTranslator($this->expressionTranslator);
        }
        $collection->append($elementFinder);
      }

      return $collection;
    }


    /**
     * Alias of ElementFinder::query
     *
     * @param string $expression
     * @return \DOMNodeList
     */
    public function node($expression) {
      return $this->query($expression);
    }


    /**
     * Fetch nodes from document
     *
     * @param string $expression
     * @return \DOMNodeList
     */
    public function query($expression) {
      return $this->executeQuery($expression);
    }


    /**
     * @param string $expression
     * @return ElementCollection
     */
    public function element($expression) {
      $nodeList = $this->executeQuery($expression);

      $collection = new ElementCollection();
      foreach ($nodeList as $item) {
        $collection->append($item);
      }

      return $collection;
    }


    /**
     * Match regex in document
     * ```php
     *  $tels = $html->match('!([0-9]{4,6})!');
     * ```
     *
     * @param string $regex
     * @param integer|callable $i
     * @return StringCollection
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function match($regex, $i = 1) {

      $documentHtml = $this->content('.')->getFirst();

      if (is_int($i)) {
        $collection = RegexHelper::match($regex, $i, [(string) $documentHtml]);
      } elseif (is_callable($i)) {
        $collection = RegexHelper::matchCallback($regex, $i, [(string) $documentHtml]);
      } else {
        throw new \InvalidArgumentException('Invalid argument. Expect integer or callable');
      }

      return $collection;
    }


    /**
     * Replace in document and refresh it
     *
     * ```php
     *  $html->replace('!00!', '11');
     * ```
     *
     * @param string $regex
     * @param string $to
     * @return $this
     * @throws \Exception
     */
    public function replace($regex, $to = '') {
      $newDoc = $this->content('.', true)->getFirst();
      $newDoc = preg_replace($regex, $to, $newDoc);

      if (trim($newDoc) === '') {
        $newDoc = $this->getEmptyDocumentHtml();
      }

      $this->setData($newDoc);
      return $this;
    }


    /**
     * @return string
     */
    protected function getEmptyDocumentHtml() {
      return '<html data-document-is-empty></html>';
    }


    /**
     * Return type of document
     *
     * @return int
     */
    public function getType() {
      return $this->type;
    }


    /**
     * @param integer $documentType
     * @return $this
     * @throws \InvalidArgumentException
     */
    protected function setDocumentType($documentType) {

      if ($documentType !== static::DOCUMENT_HTML and $documentType !== static::DOCUMENT_XML) {
        throw new \InvalidArgumentException('Doc type not valid. use xml or html');
      }

      $this->type = $documentType;

      return $this;
    }


    /**
     * @param $options
     * @return $this
     * @throws \InvalidArgumentException
     */
    protected function setDocumentOption($options) {

      if (!is_int($options)) {
        throw new \InvalidArgumentException('Expect int options');
      }

      $this->options = $options;

      return $this;
    }


    /**
     * Get current options
     *
     * @return int
     */
    public function getOptions() {
      return $this->options;
    }


    /**
     * @param string $expression
     * @return \DOMNodeList
     */
    private function executeQuery($expression) {

      if ($this->expressionTranslator !== null) {
        $expression = $this->expressionTranslator->convertToXpath($expression);
      }

      return $this->xpath->query($expression);
    }


    /**
     * @return ExpressionTranslatorInterface
     */
    public function getExpressionTranslator() {
      return $this->expressionTranslator;
    }


    /**
     * @param ExpressionTranslatorInterface $expressionTranslator
     * @return $this
     */
    public function setExpressionTranslator(ExpressionTranslatorInterface $expressionTranslator) {
      $this->expressionTranslator = $expressionTranslator;
      return $this;
    }


    /**
     * @return array
     */
    public function getLoadErrors() {
      return $this->loadErrors;
    }

  }