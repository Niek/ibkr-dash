FROM alpine:latest

ARG PHP_PKG=php83

RUN apk add --no-cache \
        ${PHP_PKG} \
        ${PHP_PKG}-cli \
        ${PHP_PKG}-opcache \
        ${PHP_PKG}-pecl-apcu \
    && ln -sf /usr/bin/${PHP_PKG} /usr/bin/php \
    && echo "apc.enable_cli=1" > /etc/${PHP_PKG}/conf.d/50_apcu.ini

WORKDIR /app
COPY . /app

EXPOSE 5080

CMD ["php", "-S", "0.0.0.0:5080", "-t", "/app", "/app/index.php"]
