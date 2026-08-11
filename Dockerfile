# Development image for the Wajaycha API.
#
# One image, three roles: the HTTP server, the Horizon worker and the scheduler.
# They differ only in the command compose gives them, so a dependency installed
# here is available to all three and they can never drift apart.
#
# This is a development target. It carries git, Composer and the full dev
# dependency tree on purpose; do not deploy it.

FROM php:8.3-cli-bookworm AS dev

# UID/GID are build args so files this container writes into the bind-mounted
# source tree — logs, caches, generated files — belong to the host user instead
# of root. Override them when your host account is not 1000:1000.
ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates \
      git \
      unzip \
      libpq-dev \
      libicu-dev \
      libzip-dev \
      libonig-dev \
      libpng-dev \
      libjpeg62-turbo-dev \
      libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# This list is not guesswork: it is every ext-* requirement in composer.lock
# that the php:8.3-cli base image does not already ship. Regenerate the check
# after adding a dependency — a missing extension fails at `composer install`
# with a lockfile error that names the package, not the extension.
#
# pdo_pgsql  — PostgreSQL driver. Non-negotiable: the domain lives there.
# intl       — Laravel's validation and formatting helpers expect it.
# bcmath     — money arithmetic; floats do not belong in a finance tracker.
# zip, mbstring — Composer and string handling.
# pcntl      — Horizon needs it to fork and to trap signals for graceful restarts.
# gd         — required by phpoffice/phpspreadsheet and setasign/fpdf, which
#              maatwebsite/excel pulls in for the export features.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
      pdo_pgsql \
      pgsql \
      intl \
      bcmath \
      zip \
      mbstring \
      pcntl \
      gd

# REDIS_CLIENT defaults to phpredis in config/database.php, so the extension is
# required rather than optional — Horizon will not start without a Redis client.
RUN pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Long-running workers should not die on a memory limit tuned for web requests.
RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/zz-wajaycha.ini

RUN groupadd --gid "${GID}" app 2>/dev/null || true \
    && useradd --uid "${UID}" --gid "${GID}" --create-home --shell /bin/bash app 2>/dev/null || true

# Both of these paths receive a named volume at run time, and Docker seeds an
# empty volume from the image directory — ownership included. If the directory
# does not exist in the image, the volume materialises owned by root and the
# unprivileged user cannot write to it.
#
#   /home/app/.composer  Composer degrades to "proceeding without cache"
#   /app/vendor          composer install dies with "does not exist and could
#                        not be created", naming a random package
RUN mkdir -p /home/app/.composer /app/vendor \
    && chown -R "${UID}:${GID}" /home/app /app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app
USER app

# Composer's cache lives in the container, not in the bind mount, so it survives
# rebuilds without polluting the host tree.
ENV COMPOSER_HOME=/home/app/.composer \
    COMPOSER_MEMORY_LIMIT=-1

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
