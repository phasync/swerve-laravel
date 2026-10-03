<?php

/*
 * What stock Laravel Octane does with the probes of tests/Fixtures/routes/audit-request.php when requests
 * come one after another: Octane's own Worker, ApplicationFactory and listeners (config/octane.php of
 * the package), no swerve. "Octane parity" in the audit means the probe leaks here too.
 *
 *     php tests/Audit/octane-sequential.php probe [probe...]       (/audr/ probes)
 *     php tests/Audit/octane-sequential.php seq:/path,/path    (any routes, one after another)
 *     php tests/Audit/octane-sequential.php auds:probe [...]       (/auds/ probes: tags 1, 2, 3 in a row)
 *
 * Prints one JSON line per probe: the 'before' each request read, Z first (the baseline), then A and B
 * (which set the state), then C, D and E (which only read it).
 */

use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Worker;
use Symfony\Component\HttpFoundation\Response;

const APP = __DIR__ . '/../Fixtures/app';

\chdir(APP);
require APP . '/vendor/autoload.php';
$_SERVER['LARAVEL_OCTANE'] = 1;

$client = new class implements Client {
    public ?Response $response = null;

    public function marshalRequest(RequestContext $context): array
    {
        throw new LogicException('unused');
    }

    public function respond(RequestContext $context, OctaneResponse $response): void
    {
        $this->response = $response->response;
    }

    public function error(Throwable $e, Illuminate\Foundation\Application $app, Request $request, RequestContext $context): void
    {
        throw $e;
    }
};
$worker = new Worker(new ApplicationFactory(APP), $client);
$worker->boot();

/** One request through Octane's worker; cookies in and out as a browser's jar does */
$send = function (string $path, array &$jar) use ($worker, $client): array {
    $client->response = null;
    $request          = Request::create($path, 'GET', [], $jar, [], ['HTTP_ACCEPT' => 'application/json']);
    $worker->handle($request, new RequestContext(['request' => $request]));
    foreach ($client->response->headers->getCookies() as $cookie) {
        if ('' === $cookie->getValue() || ($cookie->getExpiresTime() > 0 && $cookie->getExpiresTime() < \time())) {
            unset($jar[$cookie->getName()]);
        } else {
            $jar[$cookie->getName()] = $cookie->getValue();
        }
    }

    return \json_decode($client->response->getContent(), true) ?? ['error' => $client->response->getStatusCode()];
};

$login = ['auth-user', 'auth-setuser', 'auth-guard'];
// The fixture's own probes' routes are registered by routes/web.php; pass probes that need a login as such
foreach (\array_slice($argv, 1) as $probe) {
    $out = [];
    if (\str_starts_with($probe, 'seq:')) {
        // Paths one after another through one worker, comma separated; the bodies printed
        $jar = [];
        $bodies = [];
        foreach (\explode(',', \substr($probe, 4)) as $path) {
            $bodies[$path] = $send($path, $jar);
        }
        echo \json_encode(['probe' => $probe, 'bodies' => $bodies], \JSON_UNESCAPED_SLASHES), "\n";
        continue;
    }
    if (\str_starts_with($probe, 'auds:')) {
        // A statics probe (tests/Fixtures/routes/audit-statics.php): each request sets its tag; what request 2 and 3 see before is what request 1 left
        foreach (['1', '2', '3'] as $tag) {
            $jar = [];
            $r   = $send('/auds/' . \substr($probe, 5) . "/$tag?page=$tag", $jar);
            $out[$tag] = isset($r['error']) ? $r : ['before' => $r['before'], 'after' => $r['after']];
        }
        echo \json_encode(['probe' => $probe] + $out, \JSON_UNESCAPED_SLASHES), "\n";
        continue;
    }
    foreach (['Z', 'A', 'B', 'C', 'D', 'E'] as $tag) {
        $jar = [];
        \in_array($probe, $login, true) && $send("/login-as/$tag", $jar);
        $mode = \in_array($tag, ['A', 'B'], true) ? 'set' : 'read';
        $r    = $send("/audr/$probe/$tag?mode=$mode&wait=0", $jar);
        $out[$tag] = isset($r['error']) ? $r : ['before' => $r['before'], 'after' => $r['after']];
    }
    echo \json_encode(['probe' => $probe] + $out, \JSON_UNESCAPED_SLASHES), "\n";
}
