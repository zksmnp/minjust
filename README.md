Example

```$xslt
<?php

require __DIR__ . '/vendor/autoload.php';

$parser = new \SomeWork\Minjust\Parser\DomParser();
$client = \SomeWork\Minjust\Client(
    new \DivineOmega\Psr18GuzzleAdapter\Client(),
    new \Http\Factory\Guzzle\RequestFactory(),
    new \Http\Factory\Guzzle\StreamFactory()
);

$service = new \SomeWork\Minjust\Service($client, $parser);

$findRequest = new \SomeWork\Minjust\FindRequest();
$findRequest->setMax(100);
$response = $service->findAll($findRequest);
```

Источник данных: http://lawyers.minjust.ru/Lawyers