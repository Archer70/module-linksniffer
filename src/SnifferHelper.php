<?php

/*
WildPHP - a modular and easily extendable IRC bot written in PHP
Copyright (C) 2015 WildPHP

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

use WildPHP\API\Remote;
use WildPHP\API\Validation;

class SnifferHelper
{
	/**
	 * @param string $string
	 * @return string
	 */
	public static function extractUriFromString($string)
	{
		if (empty($string))
			throw new NoUriFoundException();

		$hasMatches = preg_match('/https?\:\/\/[A-Za-z0-9\-\/._~:?#@!$&\'()*+,;=%]+/i', $string, $matches);

		if (!$hasMatches || empty($matches))
			throw new NoUriFoundException();

		$possibleUri = $matches[0];

		if (!Validation::isValidLink($possibleUri))
			throw new NoUriFoundException();

		return $possibleUri;
	}

	/**
	 * @param string $uri
	 * @return string
	 */
	public static function getTitleFromUri($uri)
	{
		$contents = '';
		$title = '';
		Remote::getUriBodySplit($uri, function ($partial) use (&$title, &$contents)
		{
			$contents .= $partial;

			if (preg_match('/<title(?:[^>]+)?>(.*)<\\/title>/is', $contents, $matches) && !empty($matches[1]))
			{
				$title = trim($matches[1]);
				$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML401);
				
				// Abort the operation.
				return false;
			}

			return true;
		});

		if (empty($title))
			throw new PageTitleDoesNotExistException();

		return $title;
	}

	/**
	 * @param string $uri
	 * @return string
	 */
	public static function getContentTypeFromUri($uri)
	{
		$headerResource = Remote::getUriHeaders($uri);

		if (!$headerResource->hasHeader('Content-Type'))
			throw new ContentTypeNotFoundException();

		$content_type = strtolower(explode(';', $headerResource->getHeaderLine('Content-Type'))[0]);

		if (empty($content_type))
			throw new ContentTypeNotFoundException();

		return $content_type;
	}
}
