[![GitHub Tag](https://img.shields.io/github/v/tag/vielhuber/aistats)](https://github.com/vielhuber/aistats/tags)
[![Code Style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![License](https://img.shields.io/github/license/vielhuber/aistats)](https://github.com/vielhuber/aistats/blob/main/LICENSE.md)
[![Last Commit](https://img.shields.io/github/last-commit/vielhuber/aistats)](https://github.com/vielhuber/aistats/commits)

# 📊 aistats 📊

a small self-hosted dashboard for your cli ai usage: recent requests, token spend, served models, per-account rate limits, charts, prompt groups, and a pace estimator that warns before a limit is hit.

## requirements

aistats reads the per-request logs of a [cliproxyapi](https://github.com/router-for-me/CLIProxyAPI) instance that lives inside the project directory under `.cliproxyapi/` (gitignored): the binary, `config.yaml`, the oauth files in `auth/` and the request logs in `logs/`. point a vhost at the project so that `/admin` is served locally by php. if you also want the proxied `/v1` api reachable from outside, expose cliproxyapi through the same host; otherwise leave everything but `/admin` closed.

## setup

```bash
git clone https://github.com/vielhuber/aistats.git .
composer install
npm install
npm run prod
cp .env.example .env
vim .env
```

## cliproxyapi

```bash
mkdir -p .cliproxyapi/auth .cliproxyapi/logs
curl -fsSL https://github.com/router-for-me/CLIProxyAPI/releases/download/v7.2.159/CLIProxyAPI_7.2.159_linux_amd64.tar.gz | tar -xz -C .cliproxyapi cli-proxy-api config.example.yaml
sed "s/^request-log: .*/request-log: true/; s|^auth-dir: .*|auth-dir: \"$PWD/.cliproxyapi/auth\"|" .cliproxyapi/config.example.yaml > .cliproxyapi/config.yaml
(cd .cliproxyapi && ./cli-proxy-api --config config.yaml)
```

run it with `.cliproxyapi` as working directory so the request logs land in `.cliproxyapi/logs`; use a process supervisor to keep it running.

## logins

```bash
cd .cliproxyapi
./cli-proxy-api --config config.yaml --codex-login --no-browser
./cli-proxy-api --config config.yaml --claude-login --no-browser
./cli-proxy-api --config config.yaml --antigravity-login --no-browser
```

antigravity's oauth callback expects port `51121` on the machine that opens the browser; forward it when the login runs on a remote host.

opencode go has no cliproxyapi login: sign in at https://opencode.ai in your browser, copy the value of the `auth` cookie and set it as `OPENCODE_GO_AUTH_COOKIE` in `.env`. aistats reads the account limits with that cookie; renew it when the browser session expires.
