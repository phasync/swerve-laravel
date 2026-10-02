# Changelog

## Unreleased

- `new Handler($root, '/app')`: an application served in a folder that a proxy strips, with
  `url()`, redirects and signed URLs as under PHP-FPM.

## 0.1.0-alpha2

- WebSockets tested both ways from a route: echo, server push with `Swerve::subscribe()`
  across workers, clients leaving, identity, 250 open sockets per worker, drain, refusal.
- `Handler::run()`: Laravel code from a WebSocket callback, in a fresh application copy and the
  worker's turn. Outside a request, Laravel's services refer to a flushed copy and throw.
- A `ServerRequestInterface` route parameter is swerve's request for every request, not only
  upgrades: an ordinary `GET` to a WebSocket route is answered 426 (was 500).
- Requires phasync/swerve ^0.1.0-alpha15; CI uses phasync-ext 0.5.0-alpha10.

## 0.1.0-alpha1

- First release: Laravel 12 and 13 on swerve.
