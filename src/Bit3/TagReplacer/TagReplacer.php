<?php

namespace Bit3\TagReplacer;

use Bit3\TagReplacer\Internals\TokenTag;
use Doctrine\Common\Cache\Cache;
use Symfony\Component\PropertyAccess\Exception\NoSuchPropertyException;
use Symfony\Component\PropertyAccess\PropertyAccess;

class TagReplacer
{
	/**
	 * Throw an exception on unknown tokens.
	 */
	const MODE_EXCEPTION = 1;

	/**
	 * Trigger an error on unknown tokens.
	 */
	const MODE_ERROR = 2;

	/**
	 * Trigger a warning on unknown tokens.
	 */
	const MODE_WARNING = 4;

	/**
	 * Trigger a notice on unknown tokens.
	 */
	const MODE_NOTICE = 8;

	/**
	 * Replace unknown tokens with an empty string.
	 */
	const MODE_EMPTY = 16;

	/**
	 * Leave the token as it it, it can be parsed later.
	 */
	const MODE_SKIP = 32;

	/**
	 * Enable internal logic
	 */
	const FLAG_ENABLE_ALL_INTERNALS = self::FLAG_ENABLE_TAG_TOKEN;

	/**
	 * Enable internal token tag {{token::<name>}}
	 */
	const FLAG_ENABLE_TAG_TOKEN = 1024;

	/**
	 * @var array
	 */
	protected static $defaultBufferTypes = array(
		\Twig_Token::TEXT_TYPE,
		\Twig_Token::BLOCK_START_TYPE,
		\Twig_Token::VAR_START_TYPE,
		\Twig_Token::NUMBER_TYPE,
		\Twig_Token::STRING_TYPE,
		\Twig_Token::OPERATOR_TYPE,
		\Twig_Token::PUNCTUATION_TYPE,
		\Twig_Token::INTERPOLATION_START_TYPE,
		\Twig_Token::INTERPOLATION_END_TYPE,
	);

	/**
	 * @var array
	 */
	protected static $allTypes = array(
		\Twig_Token::EOF_TYPE,
		\Twig_Token::TEXT_TYPE,
		\Twig_Token::BLOCK_START_TYPE,
		\Twig_Token::VAR_START_TYPE,
		\Twig_Token::BLOCK_END_TYPE,
		\Twig_Token::VAR_END_TYPE,
		\Twig_Token::NAME_TYPE,
		\Twig_Token::NUMBER_TYPE,
		\Twig_Token::STRING_TYPE,
		\Twig_Token::OPERATOR_TYPE,
		\Twig_Token::PUNCTUATION_TYPE,
		\Twig_Token::INTERPOLATION_START_TYPE,
		\Twig_Token::INTERPOLATION_END_TYPE,
	);

	/**
	 * @var int
	 */
	protected $flags;

	/**
	 * @var Cache
	 */
	protected $cache;

	/**
	 * @var array
	 */
	protected $blocks;

	/**
	 * @var array
	 */
	protected $tags;

	/**
	 * @var array
	 */
	protected $callbacks;

	/**
	 * @var array
	 */
	protected $filters;

	/**
	 * @var array
	 */
	protected $tokens;

	/**
	 * @var string
	 */
	protected $unknownTagMode = self::MODE_EXCEPTION;

	/**
	 * @var string
	 */
	protected $unknownTokenMode = self::MODE_EXCEPTION;

	/**
	 * @var PropertyAccess
	 */
	protected $accessor;

	function __construct($flags = 0)
	{
		$this->flags = $flags;
		$this->cache       = new NoOpCache();
		$this->blocks      = array();
		$this->tags        = array();
		$this->callbacks   = array();
		$this->filters     = array();
		$this->tokens      = array();
		$this->environment = null;
		$this->accessor    = PropertyAccess::createPropertyAccessor();
	}

	/**
	 * @param int $flags
	 */
	public function setFlags($flags)
	{
		$this->flags = (int) $flags;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getFlags()
	{
		return $this->flags;
	}

	/**
	 * Set current caching mechanism.
	 *
	 * @param Cache|null $cache
	 *
	 * @return void
	 */
	public function setCache(Cache $cache)
	{
		$this->cache = $cache ? : new NoOpCache();
	}

	/**
	 * Get current caching mechanism
	 *
	 * @return Cache|null
	 */
	public function getCache()
	{
		return $this->cache;
	}

	/**
	 * Register a block tag.
	 *
	 * @param string   $name
	 * @param callable $callback
	 *
	 * @return void
	 */
	public function registerBlock($name, $callback)
	{
		$this->blocks[$name] = $callback;
	}

	/**
	 * Unregister a block tag.
	 *
	 * @param string $name
	 *
	 * @return void
	 */
	public function unregisterBlock($name)
	{
		unset($this->blocks[$name]);
	}

	/**
	 * Return all registered blocks.
	 *
	 * @return array Array with names as keys and callbacks as values.
	 */
	public function getRegisteredBlocks()
	{
		return $this->blocks;
	}

	/**
	 * Register an insert tag.
	 *
	 * @param string   $name
	 * @param callable $callback
	 *
	 * @return void
	 */
	public function registerTag($name, $callback)
	{
		$this->tags[$name] = $callback;
	}

	/**
	 * Unregister an insert tag.
	 *
	 * @param string $name
	 *
	 * @return void
	 */
	public function unregisterTag($name)
	{
		unset($this->tags[$name]);
	}

	/**
	 * Return all registered tags.
	 *
	 * @return array Array with names as keys and callbacks as values.
	 */
	public function getRegisteredTags()
	{
		return $this->tags;
	}

	/**
	 * Register a callback for unknown insert tags.
	 *
	 * @param callable $callback
	 *
	 * @return void
	 */
	public function registerCallback($callback)
	{
		$this->callbacks[] = $callback;
	}

	/**
	 * Unregister a callback for unknown insert tags.
	 *
	 * @param callable $callback
	 *
	 * @return void
	 */
	public function unregisterCallback($callback)
	{
		foreach ($this->callbacks as $index => $registeredCallback) {
			if ($registeredCallback == $callback) {
				unset($this->callbacks[$index]);
			}
		}
	}

	/**
	 * Return all registered callbacks.
	 *
	 * @return array Array with callbacks as values.
	 */
	public function getRegisteredCallbacks()
	{
		return $this->callbacks;
	}

	/**
	 * Register a filter function.
	 *
	 * @param string   $name
	 * @param callable $callback
	 *
	 * @return void
	 */
	public function registerFilter($name, $callback)
	{
		$this->filters[$name] = $callback;
	}

	/**
	 * Unregister a filter function.
	 *
	 * @param string $name
	 *
	 * @return void
	 */
	public function unregisterFilter($name)
	{
		unset($this->filters[$name]);
	}

	/**
	 * Return all registered filter functions.
	 *
	 * @return array Array with names as keys and callbacks as values.
	 */
	public function getRegisteredFilters()
	{
		return $this->filters;
	}

	/**
	 * Set a token value.
	 *
	 * @param string   $name
	 * @param callable $callback
	 *
	 * @return void
	 */
	public function setToken($name, $value)
	{
		$this->tokens[$name] = $value;
	}

	/**
	 * Unset token.
	 *
	 * @param string $name
	 *
	 * @return void
	 */
	public function unsetToken($name)
	{
		unset($this->tokens[$name]);
	}

	/**
	 * Return a registered token value.
	 *
	 * @param string $name
	 *
	 * @return mixed
	 */
	public function getToken($name)
	{
		return $this->tokens[$name];
	}

	/**
	 * Set a set of tokens.
	 *
	 * @param array $tokens Array of tokens
	 *
	 * @return void
	 */
	public function setTokens(array $tokens)
	{
		foreach ($tokens as $name => $value) {
			$this->setToken($name, $value);
		}
	}

	/**
	 * Unset token.
	 *
	 * @param array $names Array of token names to unset
	 *
	 * @return void
	 */
	public function unsetTokens(array $names)
	{
		foreach ($names as $name) {
			$this->unsetToken($name);
		}
	}

	/**
	 * Return all registered tokens.
	 *
	 * @return array Array with names as keys and values.
	 */
	public function getTokens()
	{
		return $this->tokens;
	}

	protected function extendMode($mode)
	{
		if (
			($mode & self::MODE_ERROR || $mode & self::MODE_WARNING || $mode & self::MODE_NOTICE) &&
			!($mode & self::MODE_EMPTY || $mode & self::MODE_SKIP)
		) {
			$mode |= self::MODE_EMPTY;
		}
		return $mode;
	}

	/**
	 * Set handling mode for unknown blocks.
	 *
	 * @param string $mode On of the MODE_* constants.
	 */
	public function setUnknownDefaultMode($mode)
	{
		$this->setUnknownTagMode($mode);
		$this->setUnknownTokenMode($mode);
	}

	/**
	 * Set handling mode for unknown insert tags.
	 *
	 * @param string $mode On of the MODE_* constants.
	 */
	public function setUnknownTagMode($mode)
	{
		$this->unknownTagMode = $this->extendMode((int) $mode);
	}

	/**
	 * Get handling mode for unknown insert tags.
	 *
	 * @return string On of the MODE_* constants.
	 */
	public function getUnknownTagMode()
	{
		return $this->unknownTagMode;
	}

	/**
	 * Set handling mode for unknown tokens.
	 *
	 * @param string $mode On of the MODE_* constants.
	 */
	public function setUnknownTokenMode($mode)
	{
		$this->unknownTokenMode = $this->extendMode((int) $mode);
	}

	/**
	 * Get handling mode for unknown tokens.
	 *
	 * @return string On of the MODE_* constants.
	 */
	public function getUnknownTokenMode()
	{
		return $this->unknownTokenMode;
	}

	protected function applyFilters($string, $filters)
	{
		foreach ($filters as $filter) {
			if (isset($this->filters[$filter])) {
				$string = call_user_func($this->filters[$filter], $string);
			}
			else {
				throw new \Twig_Error_Syntax('Unknown filter ' . $filter);
			}
		}
		return $string;
	}

	protected function parseUntil(
		\Twig_TokenStream $stream,
		array $bufferTypes,
		array $allowedTypes,
		array $expectedTypes,
		array $expectedNames,
		$callback = false
	) {
		$buffer = '';

		while (!$stream->isEOF()) {
			$token = $stream->next();

			if (!in_array($token->getType(), $allowedTypes)) {
				throw new \Twig_Error_Syntax('Unexpected token type ' . \Twig_Token::typeToEnglish($token->getType()));
			}

			if (in_array($token->getType(), $expectedTypes)) {
				return $buffer;
			}

			switch ($token->getType()) {
				case \Twig_Token::EOF_TYPE:
					return $buffer;

				case \Twig_Token::TEXT_TYPE:
				case \Twig_Token::NAME_TYPE:
				case \Twig_Token::NUMBER_TYPE:
				case \Twig_Token::STRING_TYPE:
				case \Twig_Token::OPERATOR_TYPE:
				case \Twig_Token::PUNCTUATION_TYPE:
				case \Twig_Token::INTERPOLATION_START_TYPE:
				case \Twig_Token::INTERPOLATION_END_TYPE:
					if (in_array($token->getType(), $bufferTypes)) {
						$buffer .= $token->getValue();
					}
					break;

				case \Twig_Token::BLOCK_START_TYPE:
					$fullName = $this->parseUntil(
						$stream,
						static::$allTypes,
						static::$allTypes,
						array(\Twig_Token::BLOCK_END_TYPE),
						array(),
						$callback
					);

					$filters = explode('|', $fullName);
					$name    = array_shift($filters);
					try {
						$value = $this->accessor->getValue((object) $this->tokens, $name);
					}
					catch(NoSuchPropertyException $e) {
						$value = null;
					}

					if (!empty($value)) {
						$buffer .= $this->applyFilters($value, $filters);
					}
					else {
						if ($this->unknownTagMode & self::MODE_ERROR) {
							trigger_error('Unknown token ##' . $fullName . '##', E_USER_ERROR);
						}

						if ($this->unknownTagMode & self::MODE_WARNING) {
							trigger_error('Unknown token ##' . $fullName . '##', E_USER_WARNING);
						}

						if ($this->unknownTagMode & self::MODE_NOTICE) {
							trigger_error('Unknown token ##' . $fullName . '##', E_USER_NOTICE);
						}

						if ($this->unknownTagMode & self::MODE_EMPTY) {
							// do nothing
							break;
						}

						if ($this->unknownTagMode & self::MODE_SKIP) {
							$buffer .= '##' . $fullName . '##';
							break;
						}

						throw new \Twig_Error_Syntax('Unknown token ##' . $fullName . '##');
					}
					break;

				case \Twig_Token::VAR_START_TYPE:
					$fullName = $this->parseUntil(
						$stream,
						static::$allTypes,
						static::$allTypes,
						array(\Twig_Token::VAR_END_TYPE),
						array(),
						$callback
					);

					$fullName = $this->replace($fullName, $callback);

					$filters     = explode('|', $fullName);
					$partialTag  = array_shift($filters);
					$tagParts    = explode('?', $partialTag, 2);
					$queryString = count($tagParts) > 1 ? $tagParts[1] : '';
					$parameters  = array();
					parse_str($queryString, $parameters);
					$args = explode('::', $tagParts[0]);
					$name = array_shift($args);

					if (in_array($name, $expectedNames)) {
						return $this->applyFilters($buffer, $filters);
					}
					else if (isset($this->blocks[$name])) {
						$body = $this->parseUntil(
							$stream,
							static::$defaultBufferTypes,
							static::$allTypes,
							array(),
							array('end' . $name),
							$callback
						);

						$buffer .= $this->applyFilters(
							call_user_func($this->blocks[$name], $name, $args, $parameters, $body),
							$filters
						);
					}
					else if (isset($this->tags[$name])) {
						if ($this->cache->contains($fullName)) {
							$value = $this->cache->fetch($fullName);
						}
						else {
							$value = call_user_func($this->tags[$name], $name, $args, $parameters);
							$value = $this->applyFilters($value, $filters);
							$this->cache->save($fullName, $value);
						}

						$buffer .= $value;
					}
					else {
						$temp = is_callable($callback) ? call_user_func($callback, $fullName) : false;

						if ($temp !== null && $temp !== false) {
							break;
						}

						if ($this->unknownTagMode & self::MODE_ERROR) {
							trigger_error('Unknown tag {{' . $fullName . '}}', E_USER_ERROR);
						}

						if ($this->unknownTagMode & self::MODE_WARNING) {
							trigger_error('Unknown tag {{' . $fullName . '}}', E_USER_WARNING);
						}

						if ($this->unknownTagMode & self::MODE_NOTICE) {
							trigger_error('Unknown tag {{' . $fullName . '}}', E_USER_NOTICE);
						}

						if ($this->unknownTagMode & self::MODE_EMPTY) {
							// do nothing
							break;
						}

						if ($this->unknownTagMode & self::MODE_SKIP) {
							$buffer .= '{{' . $fullName . '}}';
							break;
						}

						throw new \Twig_Error_Syntax('Unknown tag {{' . $fullName . '}}');
					}
					break;

				default:
					throw new \Twig_Error_Syntax('Unknown element type ' . \Twig_Token::typeToEnglish(
						$token->getType()
					));
			}
		}

		return $buffer;
	}

	/**
	 * Replace insert tags.
	 *
	 * @param string   $string   The string to replace insert tags in.
	 * @param callable $callback Fallback callback for unknown insert tags.
	 *
	 * @return string
	 */
	public function replace($string, $callback = null)
	{
		$environment = new \Twig_Environment();
		$lexer       = new \Twig_Lexer(
			$environment,
			array(
				 'tag_block' => array('##', '##')
			)
		);
		$stream      = $lexer->tokenize($string);

		if ($this->flags & static::FLAG_ENABLE_TAG_TOKEN) {
			$this->registerTag('token', array(new TokenTag($this), 'replace'));
		}

		return $this->parseUntil(
			$stream,
			static::$defaultBufferTypes,
			static::$allTypes,
			array(),
			array(),
			$callback
		);
	}
}