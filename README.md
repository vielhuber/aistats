[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/aistats)](https://github.com/vielhuber/aistats/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/aistats)](https://github.com/vielhuber/aistats/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/aistats)](https://github.com/vielhuber/aistats/commits)

# 📊 aistats 📊

a small self-hosted dashboard for your cli ai usage: recent requests, token spend, served models, per-account rate limits, charts, prompt groups, and a pace estimator that warns before a limit is hit.

## requirements

aistats reads the per-request logs of a running [cliproxyapi](https://github.com/router-for-me/CLIProxyAPI) instance with request logging enabled. point a vhost at the project so that `/admin` is served locally by php. if you also want the proxied `/v1` api reachable from outside, expose cliproxyapi through the same host; otherwise leave everything but `/admin` closed.

## setup

```bash
git clone https://github.com/vielhuber/aistats.git .
composer install
npm install
npm run prod
cp .env.example .env
vim .env
```

## logins

```bash
cli-proxy-api --config /var/lib/lamp/cliproxyapi/config.yaml --codex-login --no-browser
cli-proxy-api --config /var/lib/lamp/cliproxyapi/config.yaml --claude-login --no-browser
cli-proxy-api --config /var/lib/lamp/cliproxyapi/config.yaml --antigravity-login --no-browser
```

opencode go has no cliproxyapi login: sign in at https://opencode.ai in your browser, copy the value of the `auth` cookie and set it as `OPENCODE_GO_AUTH_COOKIE` in `.env`. aistats reads the account limits with that cookie; renew it when the browser session expires.
