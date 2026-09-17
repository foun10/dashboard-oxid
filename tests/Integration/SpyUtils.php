<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Integration;

use OxidEsales\Eshop\Core\Utils;

/**
 * Utils without the exit: redirects and "message and exit" responses are recorded instead,
 * so controller code that ends the request can run inside a test.
 */
class SpyUtils extends Utils
{
    /** @var array<int, array{url: string, code: int}> */
    public $redirects = [];

    /** @var array<int, string> */
    public $messages = [];

    /** @var array<int, string> */
    public $headers = [];

    public function redirect($url, $addRedirectParam = true, $headerCode = 302)
    {
        $this->redirects[] = ['url' => (string) $url, 'code' => (int) $headerCode];
    }

    public function showMessageAndExit($message)
    {
        $this->messages[] = (string) $message;
    }

    public function setHeader($header)
    {
        $this->headers[] = (string) $header;
    }
}
