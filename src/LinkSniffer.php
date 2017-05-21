<?php

/*
	WildPHP - a modular and easily extendable IRC bot written in PHP
	Copyright (C) 2016 WildPHP

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

namespace WildPHP\Modules\LinkSniffer;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use WildPHP\Core\Channels\Channel;

use WildPHP\Core\Commands\CommandHandler;
use WildPHP\Core\Commands\CommandHelp;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\Configuration\ConfigurationItemNotFoundException;

use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Logger\Logger;
use WildPHP\Core\Users\User;

class LinkSniffer
{
	use ContainerTrait;
	protected $lastLinks = [];

	public function __construct(ComponentContainer $container)
	{
		EventEmitter::fromContainer($container)->on('irc.line.in.privmsg', [$this, 'sniffLinks']);

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Shortens a given link.');
		$commandHelp->addPage('Usage: shorten [URL]');
		CommandHandler::fromContainer($container)->registerCommand('shorten', [$this, 'shortenCommand'], $commandHelp, 1, 1);

		$commandHelp = new CommandHelp();
		$commandHelp->addPage('Shortens the last recognized link given in the channel.');
		CommandHandler::fromContainer($container)->registerCommand('shortenlast', [$this, 'shortenlastCommand'], $commandHelp, 0, 0);

		$this->setContainer($container);
	}

	public function shortenlastCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$channel = $source->getName();
		$lastLink = $this->lastLinks[$channel] ?? '';

		if (empty($lastLink))
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', I do not have a stored link for this channel.');

			return;
		}

		$shortenedLink = self::getShortenedLink($lastLink);

		if (!$shortenedLink)
		{
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', I was unable to create a shortened link for this URL: ' . $lastLink);
		}
		else
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', shortened URL: ' . $shortenedLink . ' (shortened from ' . $lastLink . ')');
	}

	public function shortenCommand(Channel $source, User $user, $args, ComponentContainer $container)
	{
		$uri = $args[0];
		$shortenedUri = self::getShortenedLink($uri);

		if ($shortenedUri)
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', here is your shortened link: ' . $shortenedUri);
		else
			Queue::fromContainer($container)->privmsg($source->getName(), $user->getNickname() . ', I was unable to create a shortened link for this URL.');
	}

	/**
	 * @param PRIVMSG $incomingIrcMessage
	 * @param Queue $queue
	 */
	public function sniffLinks(PRIVMSG $incomingIrcMessage, Queue $queue)
	{
		$channel = $incomingIrcMessage->getChannel();

		try
		{
			$blockedChannels = Configuration::fromContainer($this->getContainer())->get('disablelinksniffer')->getValue();

			if (is_array($blockedChannels) &&  in_array($channel, $blockedChannels))
				return;
		}
		catch (ConfigurationItemNotFoundException $e) {}

		$message = $incomingIrcMessage->getMessage();

		$uri = self::extractUriFromString($message);

		if ($uri == false)
			return;

		$this->lastLinks[$channel] = $uri;

		$guzzleClient = new Client([
			'connect_timeout' => 3.0,
			'timeout' => 3.0
		]);
		$goutteClient = new \Goutte\Client();
		$goutteClient->setClient($guzzleClient);

		try
		{
			$response = $guzzleClient->head($uri, [
				'connect_timeout' => 3.0,
				'allow_redirects' => false,
				'timeout' => 3.0,
			]);

			$contentType = $response->getHeader('Content-Type')[0] ?? 'unknown';
			$contentType = explode(';', $contentType)[0];

			if ($contentType == 'text/html')
			{
				$crawler = $goutteClient->request('GET', $uri);
				$title = $crawler->filterXPath('//title')->text();
			}
			else
				$title = 'Content type: ' . $contentType;

			if (!$title)
				return;

			$title = trim(str_replace("\n", '', str_replace("\r", "\n", $title)));

			if (strlen($title) > 150)
				$title = substr($title, 0, 150) . '...';

			$msg = '[' . $incomingIrcMessage->getNickname() . '] ' . $title;

			$queue->privmsg($channel, $msg);
		}
		catch (\InvalidArgumentException | RequestException $e)
		{
			Logger::fromContainer($this->getContainer())->warning('Exception encountered', [
				'message' => $e->getMessage()
			]);
		}
	}

	/**
	 * @param string $uri
	 * @param Client|null $client
	 * @return bool|string
	 */
	public static function getShortenedLink(string $uri, Client $client = null)
	{
		if (is_null($client))
			$client = new Client([
				'connect_timeout' => 3.0,
				'timeout' => 3.0
			]);

		try
		{
			$encodedUri = urlencode($uri);
			$shortenedLinkResponse = $client->get('https://is.gd/create.php?format=json&url=' . $encodedUri);
			$jsonBody = $shortenedLinkResponse->getBody();

			$json = json_decode($jsonBody, true);

			if (!$json || !array_key_exists('shorturl', $json))
				return false;

			return $json['shorturl'];
		}
		catch (RequestException $e)
		{
			return false;
		}
	}

	public static function extractUriFromString($string)
	{
		if (empty($string))
			return false;

		elseif (strpos($string, '!nosniff') !== false)
			return false;

		$hasMatches = preg_match('/https?\:\/\/[A-Za-z0-9\-\/._~:?#@!$&\'()*+,;=%]+/i', $string, $matches);

		if (!$hasMatches || empty($matches))
			return false;

		$possibleUri = $matches[0];

		if (filter_var($possibleUri, FILTER_VALIDATE_URL) === false)
			return false;

		return $possibleUri;
	}
}