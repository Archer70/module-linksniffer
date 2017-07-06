<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\LinkSniffer\Backends;


use React\HttpClient\Client;
use React\HttpClient\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\ContainerTrait;
use WildPHP\Modules\LinkSniffer\BackendException;
use WildPHP\Modules\LinkSniffer\BackendInterface;
use WildPHP\Modules\LinkSniffer\BackendResult;

class LinkTitle implements BackendInterface
{
	use ContainerTrait;

	/**
	 * @var string
	 */
	public static $validationRegex = '/^\S+$/i';

	/**
	 * @var Client
	 */
	protected $httpClient;

	/**
	 * LinkTitle constructor.
	 *
	 * @param ComponentContainer $container
	 */
	public function __construct(ComponentContainer $container)
	{
		$this->setContainer($container);

		$this->httpClient = new Client($this->getContainer()->getLoop());
	}

	/**
	 * @inheritdoc
	 */
	public function request(string $url): PromiseInterface
	{
		$deferred = new Deferred();

		$request = $this->httpClient->request('GET', $url);
		$request->on('response', function (Response $response) use ($deferred, $url, $request)
		{
			if ($response->getCode() == 320)
			{
				$location = $response->getHeaders()['Location'] ?? '';
				$request->end();

				$deferred->resolve(new BackendResult($location, 'Redirect (new location: ' . $location . ')'));
				return;
			}

			if ($response->getCode() != 200)
			{
				$deferred->reject(new BackendException('Response was not successful (status code != 200 or too many redirects)'));
				$request->end();
				return;
			}

			$contentType = $response->getHeaders()['Content-Type'] ?? '';
			if (empty($contentType) || explode(';', $contentType)[0] != 'text/html')
			{
				$deferred->reject(new BackendException('Response is not an HTML file; cannot parse'));
				$request->end();
				return;
			}

			$buffer = '';
			$response->on('data', function ($chunk) use (&$buffer, $deferred, $response, $url, $request)
			{
				$buffer .= $chunk;
				$title = $this->tryParseTitleFromBuffer($buffer);

				if ($title)
				{
					$deferred->resolve(new BackendResult($url, $title));
					$request->end();
				}
			});

			$response->on('end', function () use ($deferred)
			{
				$deferred->reject(new BackendException('No link parsed before end of page; no link found'));
			});
		});
		$request->on('error', function (\Exception $e) use ($deferred)
		{
			$deferred->reject($e);
		});
		$request->end();

		return $deferred->promise();
	}

	/**
	 * @param string $buffer
	 *
	 * @return false|string
	 */
	public function tryParseTitleFromBuffer(string $buffer)
	{
		$buffer = trim(preg_replace('/\s+/', ' ', $buffer));

		if (preg_match("/\<title\>(.*)\<\/title\>/i", $buffer, $matches) == false)
			return false;

		return $matches[1];
	}

	/**
	 * @return string
	 */
	public static function getValidationRegex(): string
	{
		return static::$validationRegex;
	}
}