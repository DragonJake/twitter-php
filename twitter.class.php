<?php

require_once(dirname(__FILE__) . '/lib/twitteroauth.php');


/**
 * Twitter for PHP - library for sending messages to Twitter and receiving status updates.
 *
 * @author     David Grudl
 * @copyright  Copyright (c) 2008 David Grudl
 * @license    New BSD License
 * @link       http://phpfashion.com/
 * @see        http://apiwiki.twitter.com/Twitter-API-Documentation
 * @version    1.3
 */
class Twitter
{
	/**#@+ Timeline {@link Twitter::load()} */
	const ME = 1;
	const ME_AND_FRIENDS = 2;
	const REPLIES = 3;
	const ALL = 4;
	/**#@-*/

	/**#@+ Output format {@link Twitter::load()} */
	const XML = 0;
	const JSON = 16;
	const RSS = 32;
	const ATOM = 48;
	/**#@-*/

	/**#@+ Authorization states {@link Twitter::__construct()} */
	const AUTH_ACCESS = 200;
	const AUTH_REDIRECT = 301;
	/**#@-*/

	/** @var int */
	public static $cacheExpire = 1800; // 30 min

	/** @var string */
	public static $cacheDir;

	/** @var TwitterOAuth */
	private $oauth;



	/**
	 * Creates object using application and request/access keys.
	 * @param  string  app key
	 * @param  string  app secret
	 * @param  string  access key
	 * @param  string  access secret
	 * @throws TwitterException when CURL extension is not loaded
	 * @throws TwitterAuthException to signalize individual authorization steps
	 */
	public function __construct($appKey, $appSecret, $accessKey = NULL, $accessSecret = NULL)
	{
		if (!$accessKey || !$accessSecret) {
			$sess = &$_SESSION['__TWT'];
			if (!$sess['request_key'] || !$sess['request_secret']) {
				$oauth = new TwitterOAuth($appKey, $appSecret);
				$token = $oauth->getRequestToken();
				$sess['request_key'] = $token['oauth_token'];
				$sess['request_secret'] = $token['oauth_token_secret'];

				$url = $oauth->getAuthorizeURL($sess['request_key']);
				throw new TwitterAuthException($url, self::AUTH_REDIRECT);
			} else {
				$oauth = new TwitterOAuth($appKey, $appSecret, $sess['request_key'], $sess['request_secret']);
				unset($_SESSION['__TWT']);

				$info = $oauth->getAccessToken();
				$accessKey = $info['oauth_token'];
				$accessSecret = $info['oauth_token_secret'];

				throw new TwitterAuthException(NULL, self::AUTH_ACCESS, $accessKey, $accessSecret);
			}
		} else {
			$this->oauth = new TwitterOAuth($appKey, $appSecret, $accessKey, $accessSecret);
		}
	}



	/**
	 * Checks authorization.
	 * @return boolean
	 */
	public function isAuthorized()
	{
		return ($this->oauth instanceof TwitterOAuth);
	}



	/**
	 * Sends message to the Twitter.
	 * @param string   message encoded in UTF-8
	 * @return mixed   ID on success or FALSE on failure
	 * @throws TwitterException
	 */
	public function send($message)
	{
		if (iconv_strlen($message, 'UTF-8') > 140) {
			$message = preg_replace_callback('#https?://\S+[^:);,.!?\s]#', array($this, 'shortenUrl'), $message);
		}

		$xml = $this->httpRequest(
			'https://api.twitter.com/1/statuses/update.xml',
			array('status' => $message)
		);
		return $xml->id ? (string) $xml->id : FALSE;
	}



	/**
	 * Returns the most recent statuses.
	 * @param  int    timeline (ME | ME_AND_FRIENDS | REPLIES | ALL) and optional format (XML | JSON | RSS | ATOM)
	 * @param  int    number of statuses to retrieve
	 * @param  int    page of results to retrieve
	 * @param  bool   include retweets?
	 * @return mixed
	 * @throws TwitterException
	 */
	public function load($flags = self::ME, $count = 20, $page = 1, $retweets = FALSE)
	{
		static $timelines = array(self::ME => 'user_timeline', self::ME_AND_FRIENDS => 'friends_timeline', self::REPLIES => 'mentions', self::ALL => 'public_timeline');
		static $formats = array(self::XML => 'xml', self::JSON => 'json', self::RSS => 'rss', self::ATOM => 'atom');

		if (!is_int($flags)) { // back compatibility
			$flags = $flags ? self::ME_AND_FRIENDS : self::ME;

		} elseif (!isset($timelines[$flags & 0x0F], $formats[$flags & 0x30])) {
			throw new InvalidArgumentException;
		}

		return $this->cachedHttpRequest("http://api.twitter.com/1/statuses/" . $timelines[$flags & 0x0F] . '.' . $formats[$flags & 0x30] . "?count=$count&page=$page&include_rts=$retweets");
	}



	/**
	 * Destroys status.
	 * @param  int    id of status to be destroyed
	 * @return mixed
	 * @throws TwitterException
	 */
	public function destroy($id)
	{
		$xml = $this->httpRequest("http://api.twitter.com/1/statuses/destroy/:$id.xml", array('id' => $id));
		return $xml->id ? (string) $xml->id : FALSE;
	}



	/**
	 * Returns tweets that match a specified query.
	 * @param  string   query
	 * @param  int      format (JSON | ATOM)
	 * @return mixed
	 * @throws TwitterException
	 */
	public function search($query, $flags = self::JSON)
	{
		static $formats = array(self::JSON => 'json', self::ATOM => 'atom');
		if (!isset($formats[$flags & 0x30])) {
			throw new InvalidArgumentException;
		}

		return $this->httpRequest(
			'http://search.twitter.com/search.' . $formats[$flags & 0x30],
			array('q' => $query)
		)->results;
	}



	/**
	 * Process HTTP request.
	 * @param  string  URL
	 * @param  array   POST data
	 * @return mixed
	 * @throws TwitterException
	 */
	private function httpRequest($url, $postData = NULL)
	{
		if (!($this->oauth instanceof TwitterOAuth)) {
			throw new TwitterException('Not authorized.');
		}

		$result = $this->oauth->oAuthRequest($url, ($postData ? 'POST' : 'GET'), $postData);
		if (strpos($url, 'json')) {
			$payload = @json_decode($result); // intentionally @

		} else {
			$payload = @simplexml_load_string($result); // intentionally @
		}

		if (empty($payload)) {
			throw new TwitterException('Invalid server response');
		}

		return $payload;
	}



	/**
	 * Cached HTTP request.
	 * @param  string  URL
	 * @return mixed
	 */
	private function cachedHttpRequest($url)
	{
		if (!self::$cacheDir) {
			return $this->httpRequest($url);
		}

		$cacheFile = self::$cacheDir . '/twitter.' . md5($url);
		$cache = @file_get_contents($cacheFile); // intentionally @
		$cache = strncmp($cache, '<', 1) ? @json_decode($cache) : @simplexml_load_string($cache); // intentionally @
		if ($cache && @filemtime($cacheFile) + self::$cacheExpire > time()) { // intentionally @
			return $cache;
		}

		try {
			$payload = $this->httpRequest($url);
			file_put_contents($cacheFile, $payload instanceof SimpleXMLElement ? $payload->asXml() : json_encode($payload));
			return $payload;

		} catch (TwitterException $e) {
			if ($cache) {
				return $cache;
			}
			throw $e;
		}
	}



	/**
	 * Shortens URL using http://is.gd API.
	 * @param  array
	 * @return string
	 * @throws TwitterException
	 */
	private function shortenUrl($m)
	{
		if (!extension_loaded('curl')) {
			throw new TwitterException('PHP extension CURL is not loaded.');
		}
		
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, 'http://is.gd/api.php?longurl=' . urlencode($m[0]));
		curl_setopt($curl, CURLOPT_HEADER, FALSE);
		curl_setopt($curl, CURLOPT_TIMEOUT, 20);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		$result = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		return curl_errno($curl) || $code >= 400 ? $m[0] : $result;
	}

}



/**
 * An exception generated by Twitter.
 */
class TwitterException extends Exception
{
}



/**
 * An exception generated by authorization process.
 */
class TwitterAuthException extends TwitterException
{
	private $uri;

	private $accessKey;

	private $accessSecret;

	public function __construct($uri, $code, $accessKey = NULL, $accessSecret = NULL)
	{
		parent::__construct(NULL, $code);
		$this->uri = $uri;
		$this->accessKey = $accessKey;
		$this->accessSecret = $accessSecret;
	}

	public function getUri()
	{
		return $this->uri;
	}

	public function getAccessKey()
	{
		return $this->accessKey;
	}

	public function getAccessToken()
	{
		return $this->accessSecret;
	}
}
