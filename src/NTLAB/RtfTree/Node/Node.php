<?php

/*
 * The MIT License
 *
 * Copyright (c) 2014 Toha <tohenk@yahoo.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace NTLAB\RtfTree\Node;

use NTLAB\RtfTree\Common\Base;
use NTLAB\RtfTree\Lexer\Token;
use NTLAB\RtfTree\Lexer\Char;
use NTLAB\RtfTree\Stream\Stream;
use NTLAB\RtfTree\Encoding\Encoding;

class Node extends Base
{
    const NONE = 0;
    const ROOT = 1;
    const KEYWORD = 2;
    const CONTROL = 3;
    const TEXT = 4;
    const GROUP = 5;
    const WHITESPACE = 6;

    /**
     * Node children.
     *
     * @var \NTLAB\RtfTree\Node\Nodes
     */
    protected $children;

    /**
     * Node parent.
     *
     * @var \NTLAB\RtfTree\Node\Node
     */
    protected $parent;

    /**
     * Node root.
     *
     * @var \NTLAB\RtfTree\Node\Node
     */
    protected $root;

    /**
     * Node tree.
     *
     * @var \NTLAB\RtfTree\Node\Tree
     */
    protected $tree;

    /**
     * @var array
     */
    protected static $nodeTypes = array(
        self::NONE        => '',
        self::ROOT        => 'Root',
        self::KEYWORD     => 'Keyword',
        self::CONTROL     => 'Control',
        self::TEXT        => 'Text',
        self::GROUP       => 'Group',
        self::WHITESPACE  => 'Whitespace',
    );

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->type = static::NONE;
        $this->children = new Nodes();
    }

    /**
     * Create node by token referece.
     *
     * @param \NTLAB\RtfTree\Lexer\Token $token  Reference token
     * @return \NTLAB\RtfTree\Node\Node
     */
    public static function createFromToken(Token $token)
    {
        $node = new self();
        switch ($token->getType()) {
            case Token::NONE:
                $node->type = static::NONE;
                break;

            case Token::KEYWORD:
                $node->type = static::KEYWORD;
                break;

            case Token::CONTROL:
                $node->type = static::CONTROL;
                break;

            case Token::TEXT:
                $node->type = static::TEXT;
                break;

            case Token::WHITESPACE:
                $node->type = static::WHITESPACE;
                break;
        }
        $node->key = $token->getKey();
        $node->hasParameter = $token->hasParameter();
        $node->parameter = $token->getParameter();

        return $node;
    }

    /**
     * Create node with specific node type.
     *
     * @param int $type  Node type
     * @return \NTLAB\RtfTree\Node\Node
     */
    public static function createTyped($type = self::NONE)
    {
        $node = new self();
        $node->type = $type;
        if (static::ROOT === $type) {
            $node->root = $node;
        }

        return $node;
    }

    /**
     * Create a node.
     *
     * @param int $type  Node type
     * @param string $key  Node key
     * @param boolean $hasParameter  Is node has parameter
     * @param int $parameter  Node parameter
     * @return \NTLAB\RtfTree\Node\Node
     */
    public static function create($type, $key, $hasParameter = false, $parameter = 0)
    {
        $node = self::createTyped($type);
        $node->key = $key;
        $node->hasParameter = $hasParameter;
        $node->parameter = $parameter;

        return $node;
    }

    /**
     * Decode a character code to string.
     *
     * @param int $code  The string code number
     * @param \NTLAB\RtfTree\Encoding\Encoding $encoding  The encoder
     * @return string
     */
    public static function decode($code, Encoding $encoding)
    {
        return $encoding->decode($code);
    }

    /**
     * Escape text into RTF.
     *
     * @param string $text  The plain text to escape
     * @return string
     */
    public static function escape($text)
    {
        $i = 0;
        while (true) {
            if ($i >= strlen($text)) {
                break;
            }
            $char = substr($text, $i, 1);
            if (Char::isEscapable($char)) {
                $text = substr($text, 0, $i).Char::KEYWORD_MARKER.substr($text, $i);
                $i++;
            }
            $i++;
        }

        return $text;
    }

    /**
     * Create RTF nodes from string.
     *
     * @param string $text  The text
     * @param boolean $separateEscaped  Separate an escapable test as new node
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public static function createText($text, $separateEscaped = false)
    {
        if (strlen($text)) {
            $nodes = new Nodes();
            $stream = new Stream($text);
            while ($stream->read()) {
                // ignore empty char
                if ('' == $stream->getChar()) {
                    continue;
                }
                // is a unicode character
                if (!mb_check_encoding($stream->getChar(), 'ASCII')) {
                    $code = Encoding::getCode($stream->getChar());
                    // code as hex
                    if ($code <= 0xff) {
                        $nodes[] = self::create(static::CONTROL, '\'', true, $code);
                    } else {
                        $nodes[] = self::create(static::KEYWORD, 'u', true, $code);
                        $nodes[] = self::create(static::TEXT, '?');
                    }
                    continue;
                }
                // is a tab character?
                if ($stream->is("\t")) {
                    $nodes[] = self::create(static::KEYWORD, 'tab');
                    continue;
                }
                // is a cr-lf character?
                if ($stream->is("\r")) {
                    if ($stream->read(false) && $stream->is("\n")) {
                        $nodes[] = self::create(static::KEYWORD, 'par');
                        $stream->next();
                    }
                    continue;
                }
                // is character need escaping
                if ($separateEscaped && Char::isEscapable($stream->getChar())) {
                    $nodes[] = self::create(Node::TEXT, $stream->getChar());
                    continue;
                }
                // is character must be hex encoded?
                if (Char::isInHex($stream->getChar())) {
                    $code = Encoding::getCode($stream->getChar());
                    $nodes[] = self::create(static::CONTROL, '\'', true, $code);
                    continue;
                }
                // is a plain character?
                if (Char::isPlain($plain = $stream->getChar())) {
                    while ($stream->read(false)) {
                        if (($separateEscaped && Char::isEscapable($stream->getChar())) || !Char::isPlain($stream->getChar())) {
                            break;
                        }
                        $plain .= $stream->getChar();
                        $stream->next();
                    }
                    $nodes[] = self::create(static::TEXT, $plain);
                    continue;
                }
                throw new \Exception(sprintf('Unhandled char: "%s" at %d', $stream->getChar(), $stream->getPos()));
            }

            return $nodes;
        }
    }

    /**
     * Get node children.
     *
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * Get parent node.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Set parent node.
     *
     * @param \NTLAB\RtfTree\Node\Node $value  Parent node
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function setParent(Node $value)
    {
        $this->parent = $value;

        return $this;
    }

    /**
     * Get root node.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getRoot()
    {
        return $this->root;
    }

    /**
     * Set root node.
     *
     * @param \NTLAB\RtfTree\Node\Node $value  The root node
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function setRoot(Node $value)
    {
        $this->root = $value;

        return $this;
    }

    /**
     * Get node tree.
     *
     * @return \NTLAB\RtfTree\Node\Tree
     */
    public function getTree()
    {
        return $this->tree;
    }

    /**
     * Set node tree.
     *
     * @param \NTLAB\RtfTree\Node\Tree $value  Node tree
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function setTree(Tree $value)
    {
        $this->tree = $value;

        return $this;
    }

    /**
     * Get node index in its parent.
     *
     * @return int
     */
    public function getNodeIndex()
    {
        if ($this->parent) {
            return $this->parent->children->indexOf($this);
        }
    }

    /**
     * Get the first child.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getFirstChild()
    {
        if ($this->children->count()) {
            return $this->children[0];
        }
    }

    /**
     * Get the last child.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getLastChild()
    {
        if ($count = $this->children->count()) {
            return $this->children[$count - 1];
        }
    }

    /**
     * Get the next sibling.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getNextSibling()
    {
        if ($this->parent) {
            return $this->parent->getChildAt($this->getNodeIndex() + 1);
        }
    }

    /**
     * Get the previous sibling.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getPreviousSibling()
    {
        if ($this->parent) {
            return $this->parent->getChildAt($this->getNodeIndex() - 1);
        }
    }

    /**
     * Get the next node.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getNextNode()
    {
        if ($this->is(static::ROOT)) {
            return $this->getFirstChild();
        } else if ($this->parent) {
            // if group, return first group child
            if ($this->is(static::GROUP) && count($this->children)) {
                return $this->getFirstChild();
            } else if ($this->getNodeIndex() < count($this->parent->children) - 1) {
                return $this->getNextSibling();
            } else {
                return $this->parent->getNextSibling();
            }
        }
    }

    /**
     * Get the previous node.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getPreviousNode()
    {
        if (!$this->is(static::ROOT) && $this->parent) {
            if (0 === $this->getNodeIndex()) {
                return $this->parent;
            } else if ($prev = $this->getPreviousSibling()) {
                if ($prev->is(static::GROUP)) {
                    return $prev->getLastChild();
                } else {
                    return $prev;
                }
            }
        }
    }

    /**
     * Get node text.
     *
     * @param boolean $raw  Return raw value
     * @param int $ignoreNChars  Ignore number of chars
     * @return string
     */
    protected function getText($raw, $ignoreNChars = 1)
    {
        $result = null;
        switch ($this->type) {
            case static::GROUP:
                $node = $this->getFirstChild();
                if ($node->isEquals('*')) {
                    $node = $node->getNextSibling();
                }
                if ($raw || !$node->isEquals(array(
                    'fonttbl', 'colortbl', 'stylesheet', 'generator', 'info', 'pict',
                    'object', 'fldinst',
                ))) {
                    $uc = $ignoreNChars;
                    foreach ($this->children as $child) {
                        $result .= $child->getText($raw, $uc);
                        if ($child->is(static::KEYWORD) && $child->isEquals('uc')) {
                            $uc = $child->parameter;
                        }
                    }
                }
                break;

            case static::CONTROL:
                if ($this->key === Char::HEX_MARKER) {
                    $result .= $this->decode($this->parameter, $this->tree->getEncoding());
                }
                break;

            case static::TEXT:
                if (($prev = $this->getPreviousNode()) &&  $prev->is(static::KEYWORD) && $prev->isEquals('u')) {
                    if (strlen($this->key) - $ignoreNChars) {
                        $result .= substr($this->key, $ignoreNChars);
                    }
                } else {
                    $result .= $this->key;
                }
                break;

            case static::KEYWORD:
                if ($this->isEquals('par')) {
                    $result .= "\r\n";
                } else if ($this->isEquals('tab')) {
                    $result .= "\t";
                } else if ($this->isEquals('line')) {
                    $result .= "\r\n";
                } else if ($this->isEquals('lquote')) {
                    $result .= /*'�'*/ $this->tree->getEncoding()->getChar(0x2018);
                } else if ($this->isEquals('rquote')) {
                    $result .= /*'�'*/ $this->tree->getEncoding()->getChar(0x2019);
                } else if ($this->isEquals('ldblquote')) {
                    $result .= /*'�'*/ $this->tree->getEncoding()->getChar(0x201C);
                } else if ($this->isEquals('rdblquote')) {
                    $result .= /*'�'*/ $this->tree->getEncoding()->getChar(0x201D);
                } else if ($this->isEquals('emdash')) {
                    $result .= /*'�'*/ $this->tree->getEncoding()->getChar(0x2014);
                } else if ($this->isEquals('u')) {
                    $result .= $this->decode($this->parameter, $this->tree->getEncoding());
                }
                break;
        }
        //echo sprintf("%s = %s\n", (string) $this, var_export($result, true));

        return $result;
    }

    /**
     * Get the node text.
     *
     * @return string
     */
    public function getNodeText()
    {
        return $this->getText(false);
    }

    /**
     * Get the node raw text.
     *
     * @return string
     */
    public function getRawText()
    {
        return $this->getText(true);
    }

    /**
     * Check if node must use a keyword stop.
     *
     * @return boolean
     */
    protected function useKeywordStop()
    {
        if ($this->is(static::KEYWORD) || $this->is(static::CONTROL) || $this->is(static::GROUP)) {
            return false;
        }
        if ($this->is(static::TEXT) && $this->hasKey()) {
            if ($this->isEquals(';') || Char::isInHex($this->key) || Char::isEscapable($this->key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Encode text as RTF code representation.
     *
     * @param string $text  Text to encode
     * @return string
     */
    protected function encodeText($text)
    {
        $result = null;
        if ($nodes = $this->createText($text)) {
            for ($i = 0; $i < count($nodes); $i++) {
                if ($i == count($nodes) - 1) {
                    $result .= $nodes[$i]->asRtf(null);
                } else {
                    $result .= $nodes[$i]->asRtf($nodes[$i + 1]);
                }
            }
        }

        return $result;
    }

    /**
     * Get RTF code representation of node.
     *
     * @param \NTLAB\RtfTree\Node\Node $nextNode  Next node
     * @param boolean $encode  Encode node key
     * @return string
     */
    protected function asRtf($nextNode, $encode = false)
    {
        $result = null;
        // add prefix
        if ($this->is(static::KEYWORD) || $this->is(static::CONTROL)) {
            $result .= Char::KEYWORD_MARKER;
        }
        // add key
        if ($encode) {
            $result .= $this->encodeText($this->escape($this->key));
        } else {
            $result .= $this->key;
        }
        // add parameter
        if ($this->hasParameter) {
            if ($this->is(static::KEYWORD)) {
                $result .= (string) $this->parameter;
            } else if ($this->is(static::CONTROL) && $this->key === Char::HEX_MARKER) {
                $result .= dechex($this->parameter);
            }
        }
        // add ending
        if ($this->is(static::KEYWORD) && $nextNode && $nextNode->useKeywordStop()) {
            $result .= Char::KEYWORD_STOP;
        }

        return $result;
    }

    /**
     * Get RTF code representation of a node and its children.
     *
     * @param \NTLAB\RtfTree\Node\Node $node  The node to convert
     * @param \NTLAB\RtfTree\Node\Node $nextNode  The next node
     * @return string
     */
    protected function getRtfInm(Node $node, $nextNode)
    {
        $result = null;
        // process node itself
        switch (true) {
            case $node->is(static::ROOT):
                // skip root node
                break;

            case $node->is(static::GROUP):
                $result .= Char::BLOCK_START;
                break;

            case $node->is(static::WHITESPACE):
                $result .= $node->key;
                break;

            default:
                $result .= $node->asRtf($nextNode, true);
                break;
        }
        // process node children
        foreach ($node->children as $child) {
            $result .= $this->getRtfInm($child, $child->getNextNode());
        }
        // process ending
        if ($node->is(static::GROUP)) {
            $result .= Char::BLOCK_END;
        }

        return $result;
    }

    /**
     * Get RTF code representation of this node and its children.
     *
     * @return string
     */
    public function getRtf()
    {
        return $this->getRtfInm($this, null);
    }

    /**
     * Update root node.
     *
     * @param \NTLAB\RtfTree\Node\Node $node  The node to update
     * @return \NTLAB\RtfTree\Node\Node
     */
    protected function updateNodeRoot(Node $node)
    {
        $node->root = $this->root;
        $node->tree = $this->tree;
        foreach ($node->children as $child) {
            $this->updateNodeRoot($child);
        }

        return $this;
    }

    /**
     * Check if current node is a group and matched the key.
     *
     * @param string $key  The key to match
     * @param boolean $ignoreSpecial  Ignore special node
     * @return boolean
     */
    protected function isGroupWithKey($key, $ignoreSpecial)
    {
        if ($this->is(static::GROUP) && count($this->children)) {
            $firstChild = $this->getFirstChild();
            if ($firstChild->isEquals($key) ||
                ($ignoreSpecial && $firstChild->isEquals('*') && count($this->children) > 1 && $this->children[1]->isEquals($key))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current node is a plain text.
     *
     * @return boolean
     */
    protected function isPlainText()
    {
        $result = $this->is(static::TEXT);
        if ($result && $this->parent) {
            $firstChild = $this->parent->getFirstChild();
            if ($firstChild->is(static::KEYWORD) && $firstChild->isEquals('*')) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Select all child nodes which contain text.
     *
     * @param string $text  The text to search
     * @param int $startPos  Start position of text
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    protected function selectChildNodesForText($text, $startPos)
    {
        $sIdx = null;
        $eIdx = null;
        // find start and end position in the children list
        $ctext = null;
        for ($i = 0; $i < count($this->children); $i++) {
            $ctext .= $this->children[$i]->getNodeText();
            if (strlen($ctext) >= $startPos + 1) {
                if (null === $sIdx) {
                    $sIdx = $i;
                }
                if ($startPos === strpos($ctext, $text)) {
                    $eIdx = $i;
                    break;
                }
            }
        }
        if (null !== $sIdx && null !== $eIdx) {
            // only single node matched and it is a group
            if ($sIdx === $eIdx && $this->children[$sIdx]->is(static::GROUP)) {
                return $this->children[$sIdx]->selectChildNodesForText($text, $startPos);
            }
            $nodes = new Nodes();
            for ($i = $sIdx; $i <= $eIdx; $i++) {
                $nodes[] = $this->children[$i];
            }

            return $nodes;
        }
    }

    /**
     * Combine text nodes into single text node.
     *
     * @param \NTLAB\RtfTree\Node\Nodes $nodes  The text nodes
     * @return \NTLAB\RtfTree\Node\Node
     */
    protected function combineNodesText(Nodes $nodes)
    {
        if ($nodes && count($nodes)) {
            $node = $nodes[0];
            if ($node->is(static::GROUP)) {
                $node = $node->getLastChild();
            }
            while (count($nodes) > 1) {
                $nextNode = $nodes[1];
                $node->key .= $nextNode->getNodeText();
                if ($nextNode->parent) {
                    $nextNode->parent->removeChild($nextNode);
                }
                unset($nodes[1]);
            }

            return $node;
        }
    }

    /**
     * Append a child node.
     *
     * @param \NTLAB\RtfTree\Node\Node $node  The child node
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function appendChild(Node $node)
    {
        if ($node) {
            $node->parent = $this;
            $this->updateNodeRoot($node);
            $this->children[] = $node;
        }

        return $this;
    }

    /**
     * Append a child nodes.
     *
     * @param \NTLAB\RtfTree\Node\Nodes $nodes  The child nodes
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function appendChilds(Nodes $nodes)
    {
        if ($nodes) {
            foreach ($nodes as $node) {
                $this->appendChild($node);
            }
        }

        return $this;
    }

    /**
     * Insert a child node at specified index.
     *
     * @param int $index  Insert position
     * @param \NTLAB\RtfTree\Node\Node $node  The child node
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function insertChild($index, Node $node)
    {
        $node->parent = $this;
        $this->updateNodeRoot($node);
        $this->children->insert($index, $node);

        return $this;
    }

    /**
     * Remove child node.
     *
     * @param \NTLAB\RtfTree\Node\Node $node  Node to remove
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function removeChild(Node $node)
    {
        $this->children->remove($node);

        return $this;
    }

    /**
     * Remove child node at specified index.
     *
     * @param int $index  The position to remove
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function removeChildAt($index)
    {
        unset($this->children[$index]);

        return $this;
    }

    /**
     * Get child node at specified index.
     *
     * @param int $index  Node index
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function getChildAt($index)
    {
        if (isset($this->children[$index])) {
            return $this->children[$index];
        }
    }

    /**
     * Clone this node.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function cloneNode()
    {
        $node = self::create($this->type, $this->key, $this->hasParameter, $this->parameter);
        foreach ($this->children as $child) {
            $node->appendChild($child->cloneNode());
        }

        return $node;
    }

    /**
     * Is node has children.
     *
     * @return boolean
     */
    public function hasChildNodes()
    {
        return count($this->children) ? true : false;
    }

    /**
     * Select single child node matched node type.
     *
     * @param int $type  The node type
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectSingleNodeTyped($type = self::NONE)
    {
        foreach ($this->children as $child) {
            if ($child->is($type)) {
                return $child;
            }
            if ($node = $child->selectSingleNodeTyped($type)) {
                return $node;
            }
        }
    }

    /**
     * Select single child node matched node key and parameter.
     *
     * @param string $key  The node key
     * @param int $parameter  The node parameter
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectSingleNode($key, $parameter = null)
    {
        foreach ($this->children as $child) {
            if ($child->isEquals($key) && (null === $parameter || $child->parameter == $parameter)) {
                return $child;
            }
            if ($node = $child->selectSingleNode($key, $parameter)) {
                return $node;
            }
        }
    }

    /**
     * Select single child node of type group matched node key.
     *
     * @param string $key  The node key
     * @param boolean $ignoreSpecial  Ignore special node
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectSingleGroup($key, $ignoreSpecial = false)
    {
        foreach ($this->children as $child) {
            if ($child->isGroupWithKey($key, $ignoreSpecial)) {
                return $child;
            }
            if ($node = $child->selectSingleGroup($key, $ignoreSpecial)) {
                return $node;
            }
        }
    }

    /**
     * Select child nodes with specific type.
     *
     * @param int $type  Node type
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public function selectNodesTyped($type = self::NONE)
    {
        $nodes = new Nodes();
        foreach ($this->children as $child) {
            if ($child->is($type)) {
                $nodes[] = $child;
            }
            $nodes->append($child->selectNodesTyped($type));
        }

        return $nodes;
    }

    /**
     * Select child nodes matched key.
     *
     * @param string $key  Node key
     * @param int $parameter  Node parameter
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public function selectNodes($key, $parameter = null)
    {
        $nodes = new Nodes();
        foreach ($this->children as $child) {
            if ($child->isEquals($key) && (null === $parameter || $child->parameter == $parameter)) {
                $nodes[] = $child;
            }
            $nodes->append($child->selectNodes($key, $parameter));
        }

        return $nodes;
    }

    /**
     * Select child nodes of type group matched key.
     *
     * @param string $key  Node key
     * @param boolean $ignoreSpecial  Ignore special node
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public function selectGroup($key, $ignoreSpecial = false)
    {
        $nodes = new Nodes();
        foreach ($this->children as $child) {
            if ($child->isGroupWithKey($key, $ignoreSpecial)) {
                $nodes[] = $child;
            }
            $nodes->append($child->selectGroup($key, $ignoreSpecial));
        }

        return $nodes;
    }

    /**
     * Select single child node matched node type (non recursive).
     *
     * @param int $type  The node type
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectSingleChildNodeTyped($type = self::NONE)
    {
        foreach ($this->children as $child) {
            if ($child->is($type)) {
                return $child;
            }
        }
    }

    /**
     * Select single child node matched node key and parameter (non recursive).
     *
     * @param string $key  The node key
     * @param int $parameter  The node parameter
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectSingleChildNode($key, $parameter = null)
    {
        foreach ($this->children as $child) {
            if ($child->isEquals($key) && (null === $parameter || $child->parameter == $parameter)) {
                return $child;
            }
        }
    }

    /**
     * Select single child node of type group matched node key (non recursive).
     *
     * @param string $key  The node key
     * @param boolean $ignoreSpecial  Ignore special node
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectSingleChildGroup($key, $ignoreSpecial = false)
    {
        foreach ($this->children as $child) {
            if ($child->isGroupWithKey($key, $ignoreSpecial)) {
                return $child;
            }
        }
    }

    /**
     * Select child nodes with specific type (non recursive).
     *
     * @param int $type  Node type
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public function selectChildNodesTyped($type = self::NONE)
    {
        $nodes = new Nodes();
        foreach ($this->children as $child) {
            if ($child->is($type)) {
                $nodes[] = $child;
            }
        }

        return $nodes;
    }

    /**
     * Select child nodes matched key (non recursive).
     *
     * @param string $key  Node key
     * @param int $parameter  Node parameter
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public function selectChildNodes($key, $parameter = null)
    {
        $nodes = new Nodes();
        foreach ($this->children as $child) {
            if ($child->isEquals($key) && (null === $parameter || $child->parameter == $parameter)) {
                $nodes[] = $child;
            }
        }

        return $nodes;
    }

    /**
     * Select child nodes of type group matched key (non recursive).
     *
     * @param string $key  Node key
     * @param boolean $ignoreSpecial  Ignore special node
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public function selectChildGroup($key, $ignoreSpecial = false)
    {
        $nodes = new Nodes();
        foreach ($this->children as $child) {
            if ($child->isGroupWithKey($key, $ignoreSpecial)) {
                $nodes[] = $child;
            }
        }

        return $nodes;
    }

    /**
     * Select single next sibling node matched node type.
     *
     * @param int $type  The node type
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectSiblingTyped($type = self::NONE)
    {
        if ($this->parent && (null !== ($index = $this->getNodeIndex()))) {
            for ($i = $index + 1; $i < count($this->parent->children); $i++) {
                $node = $this->parent->children[$i];
                if ($node->is($type)) {
                    return $node;
                }
            }
        }
    }

    /**
     * Select single next sibling node matched node key and parameter.
     *
     * @param string $key  The node key
     * @param int $parameter  The node parameter
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectSibling($key, $parameter = null)
    {
        if ($this->parent && (null !== ($index = $this->getNodeIndex()))) {
            for ($i = $index + 1; $i < count($this->parent->children); $i++) {
                $node = $this->parent->children[$i];
                if ($node->isEquals($key) && (null === $parameter || $node->parameter == $parameter)) {
                    return $node;
                }
            }
        }
    }

    /**
     * Select parent node matched key.
     *
     * @param string $key  The node key
     * @param boolean $ignoreSpecial  Ignore special node
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function selectParent($key, $ignoreSpecial = false)
    {
        if ($this->parent) {
            if ($this->parent->isGroupWithKey($key, $ignoreSpecial)) {
                return $this->parent;
            }

            return $this->parent->selectParent($key, $ignoreSpecial);
        }
    }

    /**
     * Find all text nodes containing specified text.
     *
     * @param string $text  The text to search
     * @return \NTLAB\RtfTree\Node\Nodes
     */
    public function findText($text)
    {
        $nodes = new Nodes();
        foreach ($this->children as $child) {
            if ($child->is(static::TEXT) && false !== strpos($child->key, $text)) {
                $nodes[] = $child;
            }
            $nodes->append($child->findText($text));
        }

        return $nodes;
    }

    /**
     * Replace node text value.
     * 
     * @param string $oldValue  Text to replace
     * @param string $newValue  Replacement text
     * @return int
     */
    public function replaceText($oldValue, $newValue)
    {
        $count = 0;
        foreach ($this->children as $child) {
            if ($child->is(static::TEXT) && false !== strpos($child->key, $oldValue)) {
                $child->key = str_replace($oldValue, $newValue, $child->key);
                $count++;
            }
            $count += $child->replaceText($oldValue, $newValue);
        }

        return $count;
    }

    /**
     * Replace node text value.
     *
     * @param string $from  The text to replace
     * @param string $to  The replacement text
     * @return boolean
     */
    public function replaceTextEx($from, $to)
    {
        // found exact match on text node
        if ($this->is(static::TEXT)) {
            if ((null === $this->parent || $this->isPlainText()) && false !== strpos($this->key, $from))
            {
                $replaced = str_replace($from, $to, $this->key);
                if ($this->parent) {
                    $nodes = $this->createText($replaced, true);
                    $this->key = $nodes[0]->getKey();
                    if (count($nodes) > 1) {
                        $node = $this;
                        for ($i = 1; $i < count($nodes); $i++) {
                            $index = $node->getNodeIndex() + 1;
                            $node = $nodes[$i];
                            $this->parent->insertChild($index, $node);
                        }
                    }
                } else {
                    $this->key = $replaced;
                }

                return true;
            }
        }
        // found exact match on child
        foreach ($this->children as $child) {
            if ($child->replaceTextEx($from, $to)) {
                return true;
            }
        }
        // found match on across child node
        if (false !== ($pos = strpos($this->getNodeText(), $from))) {
            if ($nodes = $this->selectChildNodesForText($from, $pos)) {
                if ($node = $this->combineNodesText($nodes)) {
                    $node->replaceTextEx($from, $to);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Clear all children.
     *
     * @return \NTLAB\RtfTree\Node\Node
     */
    public function clear()
    {
        $this->children->clear();

        return $this;
    }

    /**
     * (non-PHPdoc)
     * @see \NTLAB\RtfTree\Common\Base::getTypeText()
     */
    public function getTypeText()
    {
        return self::$nodeTypes[$this->type];
    }

    /**
     * Get tree representation of a node and its child.
     *
     * @param int $level  Start level
     * @param boolean $showNodeType  Show node type
     * @param boolean $showNodeIndex  Show node index
     * @return string
     */
    public function asTree($level = 0, $showNodeType = true, $showNodeIndex = true)
    {
        $result = null;
        // indentation
        for ($i = 1; $i <= $level; $i++) {
            $result .= str_repeat(' ', 4);
        }
        if ($showNodeIndex && (null !== ($index = $this->getNodeIndex()))) {
            $count = strlen((string) count($this->parent->getChildren()));
            $no = (string) $index;
            if (strlen($no) < $count) {
                $no = str_repeat('0', $count - strlen($no)).$no;
            }
            $result .= $no.'>';
        }
        // node type
        switch ($this->type) {
            case static::ROOT:
                $result .= 'ROOT';
                break;

            case static::GROUP:
                $result .= 'GROUP';
                break;

            case static::WHITESPACE:
                $result .= 'WHITESPACE';
                break;

            default:
                if ($showNodeType) {
                    $result .= $this->getTypeText().': ';
                }
                $result .= $this->key;
                if ($this->hasParameter) {
                    $result .= ' '.(string) $this->parameter;
                }
                break;
        }
        if ($result) {
            $result .= "\r\n";
        }
        // childs
        foreach ($this->children as $child) {
            $result .= $child->asTree($level + 1, $showNodeType, $showNodeIndex);
        }

        return $result;
    }
}