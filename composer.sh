#!/bin/bash

set -e

docker build -q -t growthbook-php-dev - < Dockerfile.dev

mkdir -p $HOME/.composer
docker run --rm --interactive --tty --publish 8000 \
  --volume $PWD:/app \
  --volume ${COMPOSER_HOME:-$HOME/.composer}:/tmp \
  growthbook-php-dev composer $@