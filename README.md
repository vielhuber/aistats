[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/aistats)](https://github.com/vielhuber/aistats/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/aistats)](https://github.com/vielhuber/aistats/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/aistats)](https://github.com/vielhuber/aistats/commits)

# 🤖 aistats 🤖

A small self-hosted dashboard for your CLI AI usage: recent requests, token spend, served models, per-account rate limits, charts, prompt groups, and a pace estimator that warns before a limit is hit.

## requirements

aistats reads the per-request logs of a running [cliproxyapi](https://github.com/router-for-me/CLIProxyAPI) instance, so that is the only requirement: cliproxyapi installed with request logging enabled. point a vhost at the project so that `/admin` is served locally by php. If you also want the proxied `/v1` api reachable from outside, expose cliproxyapi through the same host; otherwise leave everything but `/admin` closed.

## setup

```bash
git clone https://github.com/vielhuber/aistats.git .
composer install
npm install
cp .env.example .env && vim .env
```
