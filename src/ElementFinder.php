<?php

  declare(strict_types=1);

  namespace Xparse\ElementFinder;

  use Xparse\ElementFinder\Collection\ElementCollection;
  use Xparse\ElementFinder\Collection\StringCollection;
  use Xparse\ElementFinder\ElementFinder\Element;
  use Xparse\ElementFinder\Helper\NodeHelper;
  use Xparse\ElementFinder\Helper\RegexHelper;
  use Xparse\ElementFinder\Helper\StringHelper;
  use Xparse\ExpressionTranslator\ExpressionTranslatorInterface;
  use Xparse\ExpressionTranslator\XpathExpression;

  /**
   * @author Ivan Scherbak <dev@funivan.com>
   */
  class ElementFinder {

    /**
     * Html document type
     *
     * @var int
     */
    const DOCUMENT_HTML = 0;

    /**
     * Xml document type
     *
     * @var int
     */
    const DOCUMENT_XML = 1;

    /**
     * Hide errors
     *
     * @var int
     */
    private $options;

    /**
     * Current document type
     *
     * @var int
     */
    private $type;

    /**
     * @var \DOMDocument
     */
    private $dom;

    /**
     * @var \DomXPath
     */
    private $xpath;

    /**
     * @var ExpressionTranslatorInterface
     */
    private $expressionTranslator;

    /**
     * @var array
     */
    private $loadErrors;


    /**
     *
     *
     * Example:
     * new ElementFinder("<html><div>test </div></html>", ElementFinder::HTML);
     *
     * @param string $data
     * @param null|int $documentType
     * @param ExpressionTranslatorInterface|null $translator
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct(string $data, int $documentType = null, ExpressionTranslatorInterface $translator = null) {
      if ('' === $data) {
        throw new \InvalidArgumentException('Expect not empty string');
      }
      $this->dom = new \DomDocument();
      $this->expressionTranslator = $translator ?? new XpathExpression();
      $this->dom->registerNodeClass(\DOMElement::class, Element::class);

      $documentType = $documentType ?? static::DOCUMENT_HTML;
      $this->options = (LIBXML_NOCDATA & LIBXML_NOERROR);
      $this->setDocumentType($documentType);
      $this->setData($data);
    }


    /**
     *
     * @return string
     */
    public function __toString() {
      try {
        $result = $this->content('.')->getFirst();
      } catch (\Exception $e) {
        $result = '';
      }
      return (string) $result;
    }


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
      $disableEntities = libxml_disable_entity_loader();

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
     * @throws \Exception
     */
    public function content(string $expression, bool $outerContent = false): StringCollection {

      $items = $this->query($expression);

      $result = [];
      foreach ($items as $node) {
        if ($outerContent) {
          $result[] = NodeHelper::getOuterContent($node);
        } else {
          $result[] = NodeHelper::getInnerContent($node);
        }
      }

      return new StringCollection($result);
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
      $items = $this->query($expression);
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
     * @throws \Exception
     */
    public function value($expression): Collection\StringCollection {
      $items = $this->query($expression);
      $result = [];
      foreach ($items as $node) {
        $result[] = $node->nodeValue;
      }
      return new StringCollection($result);
    }


    /**
     * Return array of keys and values
     *
     * @param string $keyExpression
     * @param string $valueExpression
     * @throws \Exception
     * @return array
     */
    public function keyValue(string $keyExpression, string $valueExpression): array {

      $keyNodes = $this->query($keyExpression);
      $valueNodes = $this->query($valueExpression);
      if ($keyNodes->length !== $valueNodes->length) {
        throw new \RuntimeException('Keys and values must have equal numbers of elements');
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
    public function object($expression, $outerHtml = false): Collection\ObjectCollection {
      $type = $this->type;

      $items = $this->query($expression);

      $result = [];
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
        if ($this->type === static::DOCUMENT_XML and strpos($html, '<?xml') === false) {
          $html = '<root>' . $html . '</root>';
        }
        $result[] = new ElementFinder($html, $type, $this->expressionTranslator);
      }

      return new Collection\ObjectCollection($result);
    }


    /**
     * Fetch nodes from document
     *
     * @param string $expression
     * @return \DOMNodeList
     */
    public function query($expression): \DOMNodeList {
      return $this->xpath->query(
        $this->expressionTranslator->convertToXpath($expression)
      );
    }


    /**
     * @param string $expression
     * @return ElementCollection
     * @throws \InvalidArgumentException
     */
    public function element($expression): Collection\ElementCollection {
      $nodeList = $this->query($expression);

      $items = [];
      foreach ($nodeList as $item) {
        $items[] = $item;
      }

      return new ElementCollection($items);
    }


    /**
     * Match regex in document
     * ```php
     *  $tels = $html->match('!([0-9]{4,6})!');
     * ```
     *
     * @param string $regex
     * @param int|callable $i
     * @return StringCollection
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function match($regex, $i = 1): Collection\StringCollection {

      $documentHtml = $this->content('.')->getFirst();

      if (is_int($i)) {
        $collection = RegexHelper::match($regex, $i, [(string) $documentHtml]);
      } elseif (is_callable($i)) {
        $collection = RegexHelper::matchCallback($regex, $i, [(string) $documentHtml]);
      } else {
        throw new \InvalidArgumentException('Invalid argument. Expect int or callable');
      }

      return $collection;
    }


    /**
     * @return string
     */
    protected function getEmptyDocumentHtml(): string {
      return '<html data-document-is-empty></html>';
    }


    /**
     * @param int $documentType
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
     * @return array
     */
    public function getLoadErrors(): array {
      return $this->loadErrors;
    }

  }